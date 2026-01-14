## Code: app/Jobs/ValidateMassPaymentFileJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Services\CsvValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;
use Carbon\Carbon;
use Exception;
use Throwable;

class ValidateMassPaymentFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The mass payment file to validate.
     */
    public MassPaymentFile $massPaymentFile;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 600; // 10 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Maximum rows to process in a single batch.
     */
    private const BATCH_SIZE = 1000;

    /**
     * Maximum memory limit in MB for processing.
     */
    private const MEMORY_LIMIT_MB = 512;

    /**
     * File storage disk for mass payment files.
     */
    private const STORAGE_DISK = 'mass_payments';

    /**
     * Create a new job instance.
     */
    public function __construct(MassPaymentFile $massPaymentFile)
    {
        $this->massPaymentFile = $massPaymentFile;
        $this->onQueue(config('queue.mass_payments.validation.queue', 'validation'));
        $this->timeout = config('queue.mass_payments.timeouts.csv_validation', 600);
        $this->tries = config('queue.mass_payments.max_retries.validation', 3);
    }

    /**
     * Execute the job.
     */
    public function handle(CsvValidationService $validationService): void
    {
        $startTime = microtime(true);

        try {
            Log::info('Starting mass payment file validation', [
                'file_id' => $this->massPaymentFile->id,
                'filename' => $this->massPaymentFile->filename,
                'currency' => $this->massPaymentFile->currency,
                'job_id' => $this->job->getJobId(),
            ]);

            // Set memory limit for processing
            $this->setMemoryLimit();

            // Validate file exists and is readable
            $this->validateFileExists();

            // Update status to validating
            $this->updateFileStatus(MassPaymentFile::STATUS_VALIDATING);

            // Read and validate CSV structure
            $csvReader = $this->createCsvReader();
            $headers = $this->validateCsvHeaders($csvReader);

            // Process CSV rows in batches
            $validationResults = $this->processCsvInBatches($csvReader, $validationService);

            // Create payment instruction records
            $this->createPaymentInstructions($validationResults['valid_rows']);

            // Update file with validation results
            $this->updateFileWithResults($validationResults);

            // Determine final status based on validation results
            $this->setFinalFileStatus($validationResults);

            $processingTime = round(microtime(true) - $startTime, 2);

            Log::info('Mass payment file validation completed', [
                'file_id' => $this->massPaymentFile->id,
                'total_rows' => $validationResults['total_rows'],
                'valid_rows' => $validationResults['valid_count'],
                'invalid_rows' => $validationResults['invalid_count'],
                'processing_time' => $processingTime,
            ]);

        } catch (Exception $e) {
            $this->handleValidationFailure($e, $startTime);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Mass payment file validation job failed', [
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempt' => $this->attempts(),
        ]);

        try {
            // Update file status to failed
            $this->massPaymentFile->updateStatus(
                MassPaymentFile::STATUS_VALIDATION_FAILED,
                [
                    'job_failure' => [
                        'error' => $exception->getMessage(),
                        'failed_at' => Carbon::now()->toISOString(),
                        'attempts' => $this->attempts(),
                    ]
                ]
            );

            // Clean up any partial data
            $this->cleanupPartialData();

        } catch (Exception $cleanupException) {
            Log::error('Failed to cleanup after validation job failure', [
                'file_id' => $this->massPaymentFile->id,
                'cleanup_error' => $cleanupException->getMessage(),
            ]);
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [60, 120, 300]; // 1 minute, 2 minutes, 5 minutes
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): Carbon
    {
        return now()->addMinutes(30); // Retry for up to 30 minutes
    }

    /**
     * Set memory limit for CSV processing.
     */
    private function setMemoryLimit(): void
    {
        $memoryLimit = config('queue.mass_payments.memory_limits.csv_parsing', self::MEMORY_LIMIT_MB);
        ini_set('memory_limit', $memoryLimit . 'M');
    }

    /**
     * Validate that the file exists and is readable.
     */
    private function validateFileExists(): void
    {
        $filePath = $this->massPaymentFile->file_path;

        if (empty($filePath)) {
            throw new Exception('File path is empty');
        }

        if (!Storage::disk(self::STORAGE_DISK)->exists($filePath)) {
            throw new Exception("File does not exist: {$filePath}");
        }

        $fullPath = Storage::disk(self::STORAGE_DISK)->path($filePath);
        
        if (!is_readable($fullPath)) {
            throw new Exception("File is not readable: {$filePath}");
        }

        $fileSize = Storage::disk(self::STORAGE_DISK)->size($filePath);
        $maxFileSize = config('queue.mass_payments.file_limits.max_file_size_mb', 50) * 1024 * 1024;

        if ($fileSize > $maxFileSize) {
            throw new Exception("File size exceeds maximum allowed: " . ($fileSize / 1024 / 1024) . "MB");
        }
    }

    /**
     * Create CSV reader from file.
     */
    private function createCsvReader(): Reader
    {
        try {
            $filePath = Storage::disk(self::STORAGE_DISK)->path($this->massPaymentFile->file_path);
            $reader = Reader::createFromPath($filePath, 'r');
            $reader->setHeaderOffset(0);
            
            return $reader;

        } catch (CsvException $e) {
            throw new Exception("Failed to read CSV file: " . $e->getMessage());
        }
    }

    /**
     * Validate CSV headers.
     */
    private function validateCsvHeaders(Reader $reader): array
    {
        try {
            $headers = $reader->getHeader();

            if (empty($headers)) {
                throw new Exception('CSV file has no headers');
            }

            // Normalize headers (trim whitespace, convert to lowercase)
            $normalizedHeaders = array_map(function ($header) {
                return strtolower(trim($header));
            }, $headers);

            // Check for required headers
            $requiredHeaders = $this->getRequiredHeaders();
            $missingHeaders = array_diff($requiredHeaders, $normalizedHeaders);

            if (!empty($missingHeaders)) {
                throw new Exception('Missing required CSV headers: ' . implode(', ', $missingHeaders));
            }

            // Check for duplicate headers
            $duplicateHeaders = array_diff_assoc($normalizedHeaders, array_unique($normalizedHeaders));
            if (!empty($duplicateHeaders)) {
                throw new Exception('Duplicate headers found in CSV: ' . implode(', ', $duplicateHeaders));
            }

            return $normalizedHeaders;

        } catch (CsvException $e) {
            throw new Exception("Failed to validate CSV headers: " . $e->getMessage());
        }
    }

    /**
     * Get required headers based on currency.
     */
    private function getRequiredHeaders(): array
    {
        $baseHeaders = [
            'beneficiary_name',
            'amount',
            'reference',
            'purpose_code',
            'beneficiary_email',
            'beneficiary_country',
            'beneficiary_type',
        ];

        $currency = $this->massPaymentFile->currency;

        // Add currency-specific required headers
        if ($currency === 'INR') {
            $baseHeaders[] = 'invoice_number';
            $baseHeaders[] = 'invoice_date';
        }

        if ($currency === 'TRY') {
            $baseHeaders[] = 'incorporation_number';
        }

        return $baseHeaders;
    }

    /**
     * Process CSV rows in batches.
     */
    private function processCsvInBatches(Reader $reader, CsvValidationService $validationService): array
    {
        $validationResults = [
            'valid_rows' => [],
            'invalid_rows' => [],
            'total_rows' => 0,
            'valid_count' => 0,
            'invalid_count' => 0,
            'total_amount' => 0.0,
            'errors' => [],
        ];

        try {
            $batchNumber = 1;
            $rowNumber = 1; // Start from 1 (header is 0)
            $maxRows = config('queue.mass_payments.file_limits.max_rows', 10000);

            $records = $reader->getRecords();

            foreach ($records as $record) {
                $rowNumber++;

                // Check maximum rows limit
                if ($validationResults['total_rows'] >= $maxRows) {
                    throw new Exception("File exceeds maximum allowed rows: {$maxRows}");
                }

                $validationResults['total_rows']++;

                // Skip empty rows
                if ($this->isEmptyRow($record)) {
                    continue;
                }

                // Validate individual row
                $rowValidation = $validationService->validatePaymentInstruction(
                    $record,
                    $this->massPaymentFile->currency
                );

                if ($rowValidation['is_valid']) {
                    $validationResults['valid_rows'][] = array_merge($rowValidation['normalized_data'], [
                        'row_number' => $rowNumber,
                    ]);
                    $validationResults['valid_count']++;
                    
                    // Add to total amount
                    $amount = (float) ($rowValidation['normalized_data']['amount'] ?? 0);
                    $validationResults['total_amount'] += $amount;
                } else {
                    $validationResults['invalid_rows'][] = [
                        'row_number' => $rowNumber,
                        'data' => $record,
                        'errors' => $rowValidation['errors'],
                    ];
                    $validationResults['invalid_count']++;
                }

                // Process in batches to avoid memory issues
                if ($validationResults['total_rows'] % self::BATCH_SIZE === 0) {
                    Log::debug('Processed CSV batch', [
                        'file_id' => $this->massPaymentFile->id,
                        'batch_number' => $batchNumber,
                        'rows_processed' => $validationResults['total_rows'],
                        'valid_so_far' => $validationResults['valid_count'],
                        'memory_usage' => memory_get_usage(true),
                    ]);

                    $batchNumber++;

                    // Force garbage collection to manage memory
                    if ($batchNumber % 5 === 0) {
                        gc_collect_cycles();
                    }
                }
            }

        } catch (Exception $e) {
            throw new Exception("Failed to process CSV rows: " . $e->getMessage());
        }

        return $validationResults;
    }

    /**
     * Check if a row is empty.
     */
    private function isEmptyRow(array $row): bool
    {
        $nonEmptyValues = array_filter($row, function ($value) {
            return !empty(trim($value));
        });

        return empty($nonEmptyValues);
    }

    /**
     * Create payment instruction records from valid rows.
     */
    private function createPaymentInstructions(array $validRows): void
    {
        if (empty($validRows)) {
            return;
        }

        try {
            DB::beginTransaction();

            $batchSize = config('queue.mass_payments.batch_config.chunk_size', 100);
            $batches = array_chunk($validRows, $batchSize);

            foreach ($batches as $batch) {
                $instructions = [];

                foreach ($batch as $row) {
                    $instructions[] = $this->preparePaymentInstructionData($row);
                }

                // Bulk insert payment instructions
                PaymentInstruction::insert($instructions);

                Log::debug('Created payment instruction batch', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_size' => count($instructions),
                ]);
            }

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to create payment instructions: " . $e->getMessage());
        }
    }

    /**
     * Prepare payment instruction data for database insertion.
     */
    private function preparePaymentInstructionData(array $row): array
    {
        return [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'mass_payment_file_id' => $this->massPaymentFile->id,
            'beneficiary_id' => null, // Will be set during processing
            'amount' => (float) $row['amount'],
            'currency' => $this->massPaymentFile->currency,
            'purpose_code' => $row['purpose_code'],
            'reference' => $row['reference'],
            'status' => PaymentInstruction::STATUS_VALIDATED,
            'validation_errors' => null,
            'row_number' => $row['row_number'],
            
            // Beneficiary information
            'beneficiary_name' => $row['beneficiary_name'],
            'beneficiary_account_number' => $row['beneficiary_account_number'] ?? null,
            'beneficiary_sort_code' => $row['beneficiary_sort_code'] ?? null,
            'beneficiary_iban' => $row['beneficiary_iban'] ?? null,
            'beneficiary_swift_code' => $row['beneficiary_swift_code'] ?? null,
            'beneficiary_bank_name' => $row['beneficiary_bank_name'] ?? null,
            'beneficiary_bank_address' => $row['beneficiary_bank_address'] ?? null,
            'beneficiary_address_line1' => $row['beneficiary_address_line1'] ?? null,
            'beneficiary_address_line2'