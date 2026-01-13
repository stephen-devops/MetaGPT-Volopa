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
     * Mass payment file to validate
     */
    protected MassPaymentFile $massPaymentFile;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job should run.
     */
    public int $timeout = 600;

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): Carbon
    {
        return now()->addMinutes(30);
    }

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(MassPaymentFile $massPaymentFile)
    {
        $this->massPaymentFile = $massPaymentFile->withoutRelations();
        
        // Set queue configuration
        $this->onQueue(config('mass-payments.queue.validation_queue', 'validation'));
        $this->timeout = config('mass-payments.queue.validation_timeout', 600);
        $this->tries = config('mass-payments.queue.max_validation_attempts', 3);
    }

    /**
     * Execute the job.
     */
    public function handle(CsvValidationService $validator): void
    {
        Log::info('Starting mass payment file validation job', [
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->original_filename,
            'client_id' => $this->massPaymentFile->client_id,
            'job_id' => $this->job->getJobId(),
        ]);

        // Refresh model to get latest state
        $this->massPaymentFile->refresh();

        // Check if file is still in a state that can be validated
        if (!$this->canBeValidated()) {
            Log::warning('Mass payment file is not in a validatable state', [
                'file_id' => $this->massPaymentFile->id,
                'current_status' => $this->massPaymentFile->status,
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            // Mark file as validating
            $this->massPaymentFile->markAsValidating();

            // Perform CSV structure validation
            $structureValidation = $this->validateFileStructure($validator);
            
            if (!$structureValidation['valid']) {
                $this->handleValidationFailure($structureValidation['errors']);
                DB::commit();
                return;
            }

            // Parse and validate payment instructions
            $instructionValidation = $this->validatePaymentInstructions($validator, $structureValidation);
            
            if (!$instructionValidation['valid']) {
                $this->handleValidationFailure($instructionValidation['errors'], $instructionValidation['instruction_errors'] ?? []);
                DB::commit();
                return;
            }

            // Store payment instructions in database
            $this->storePaymentInstructions($instructionValidation['instructions'], $structureValidation['headers']);

            // Update file metadata and mark as awaiting approval
            $this->completeValidation($instructionValidation['statistics']);

            DB::commit();

            Log::info('Mass payment file validation completed successfully', [
                'file_id' => $this->massPaymentFile->id,
                'total_instructions' => $instructionValidation['statistics']['total_instructions'] ?? 0,
                'valid_instructions' => $instructionValidation['statistics']['valid_instructions'] ?? 0,
                'total_amount' => $instructionValidation['statistics']['total_amount'] ?? 0.0,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Mass payment file validation job failed', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleValidationException($e);
            
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Mass payment file validation job failed permanently', [
            'file_id' => $this->massPaymentFile->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        try {
            // Refresh model to ensure we have latest state
            $this->massPaymentFile->refresh();

            // Mark file as validation failed with error details
            $this->massPaymentFile->markAsValidationFailed([
                'job_error' => $exception->getMessage(),
                'failed_at' => now()->toISOString(),
                'attempts' => $this->attempts(),
                'error_type' => 'job_failure',
            ]);

        } catch (Exception $e) {
            Log::error('Failed to update mass payment file status after job failure', [
                'file_id' => $this->massPaymentFile->id,
                'original_error' => $exception->getMessage(),
                'status_update_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if file can be validated
     */
    protected function canBeValidated(): bool
    {
        return in_array($this->massPaymentFile->status, [
            MassPaymentFile::STATUS_DRAFT,
            MassPaymentFile::STATUS_VALIDATING,
        ]);
    }

    /**
     * Validate CSV file structure
     */
    protected function validateFileStructure(CsvValidationService $validator): array
    {
        $filePath = $this->getFilePath();
        
        if (!$filePath || !Storage::exists($filePath)) {
            return [
                'valid' => false,
                'errors' => ['CSV file not found on storage'],
            ];
        }

        $fullPath = Storage::path($filePath);
        
        Log::debug('Validating CSV structure', [
            'file_id' => $this->massPaymentFile->id,
            'file_path' => $filePath,
        ]);

        return $validator->validateCsvStructure($fullPath);
    }

    /**
     * Validate payment instructions from CSV
     */
    protected function validatePaymentInstructions(CsvValidationService $validator, array $structureValidation): array
    {
        $filePath = $this->getFilePath();
        $fullPath = Storage::path($filePath);
        $instructions = [];

        try {
            // Create CSV reader
            $csv = Reader::createFromPath($fullPath, 'r');
            $csv->setHeaderOffset(0);
            
            $headers = $csv->getHeader();
            $headerMapping = $this->createHeaderMapping($headers);
            
            Log::debug('Parsing CSV payment instructions', [
                'file_id' => $this->massPaymentFile->id,
                'headers' => $headers,
                'header_mapping' => $headerMapping,
            ]);

            $records = $csv->getRecords();
            $rowNumber = 1;

            foreach ($records as $record) {
                $rowNumber++;
                
                // Map CSV row to instruction array
                $instruction = $this->mapCsvRowToInstruction($record, $headerMapping, $rowNumber);
                $instructions[] = $instruction;

                // Prevent memory issues with very large files
                if ($rowNumber > config('mass-payments.max_rows_per_file', 10000)) {
                    break;
                }
            }

            // Validate all instructions
            $validationResult = $validator->validatePaymentInstructions($instructions);
            $validationResult['instructions'] = $instructions;
            $validationResult['headers'] = $headers;

            return $validationResult;

        } catch (CsvException $e) {
            Log::error('CSV parsing error during instruction validation', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'valid' => false,
                'errors' => ['CSV parsing error: ' . $e->getMessage()],
                'instruction_errors' => [],
                'instructions' => [],
            ];
        }
    }

    /**
     * Store validated payment instructions in database
     */
    protected function storePaymentInstructions(array $instructions, array $headers): void
    {
        Log::debug('Storing payment instructions', [
            'file_id' => $this->massPaymentFile->id,
            'instruction_count' => count($instructions),
        ]);

        $batchSize = 100;
        $batches = array_chunk($instructions, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $instructionData = [];

            foreach ($batch as $instruction) {
                $instructionData[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'mass_payment_file_id' => $this->massPaymentFile->id,
                    'beneficiary_id' => $this->findOrCreateBeneficiary($instruction),
                    'amount' => (float) $instruction['amount'],
                    'currency' => strtoupper($instruction['currency']),
                    'purpose_code' => $instruction['purpose_code'] ?? null,
                    'reference' => $instruction['reference'] ?? null,
                    'status' => PaymentInstruction::STATUS_PENDING,
                    'validation_errors' => null,
                    'row_number' => $instruction['row_number'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            PaymentInstruction::insert($instructionData);

            Log::debug('Stored payment instruction batch', [
                'file_id' => $this->massPaymentFile->id,
                'batch_index' => $batchIndex + 1,
                'batch_size' => count($instructionData),
            ]);
        }
    }

    /**
     * Handle validation failure
     */
    protected function handleValidationFailure(array $errors, array $instructionErrors = []): void
    {
        Log::warning('Mass payment file validation failed', [
            'file_id' => $this->massPaymentFile->id,
            'error_count' => count($errors),
            'instruction_error_count' => count($instructionErrors),
        ]);

        $validationErrors = [
            'validation_failed_at' => now()->toISOString(),
            'global_errors' => $errors,
            'instruction_errors' => $instructionErrors,
            'error_summary' => [
                'total_errors' => count($errors),
                'instruction_errors' => count($instructionErrors),
            ],
        ];

        $this->massPaymentFile->markAsValidationFailed($validationErrors);
    }

    /**
     * Handle validation exception
     */
    protected function handleValidationException(Exception $exception): void
    {
        $validationErrors = [
            'validation_failed_at' => now()->toISOString(),
            'exception_error' => $exception->getMessage(),
            'error_type' => 'validation_exception',
            'error_summary' => [
                'total_errors' => 1,
                'exception' => true,
            ],
        ];

        try {
            $this->massPaymentFile->markAsValidationFailed($validationErrors);
        } catch (Exception $e) {
            Log::error('Failed to mark file as validation failed', [
                'file_id' => $this->massPaymentFile->id,
                'original_error' => $exception->getMessage(),
                'status_update_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Complete validation process
     */
    protected function completeValidation(array $statistics): void
    {
        // Update file total amount and currency if not already set
        $totalAmount = $statistics['total_amount'] ?? 0.0;
        $detectedCurrency = $this->detectPrimaryCurrency($statistics);

        $updateData = [
            'total_amount' => $totalAmount,
        ];

        if ($detectedCurrency && empty($this->massPaymentFile->currency)) {
            $updateData['currency'] = $detectedCurrency;
        }

        $this->massPaymentFile->update($updateData);

        // Mark as awaiting approval
        $this->massPaymentFile->markAsAwaitingApproval();

        Log::info('Mass payment file validation completed', [
            'file_id' => $this->massPaymentFile->id,
            'total_amount' => $totalAmount,
            'currency' => $detectedCurrency,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Get file storage path
     */
    protected function getFilePath(): ?string
    {
        if (empty($this->massPaymentFile->filename)) {
            return null;
        }

        $basePath = config('mass-payments.storage_path', 'mass-payment-files');
        return $basePath . '/' . $this->massPaymentFile->filename;
    }

    /**
     * Create header mapping for CSV columns
     */
    protected function createHeaderMapping(array $headers): array
    {
        $mapping = [];
        
        foreach ($headers as $index => $header) {
            $normalizedHeader = $this->normalizeHeaderName($header);
            $mapping[$normalizedHeader] = $index;
        }

        return $mapping;
    }

    /**
     * Normalize header name for consistent mapping
     */
    protected function normalizeHeaderName(string $header): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $header)));
    }

    /**
     * Map CSV row to payment instruction array
     */
    protected function mapCsvRowToInstruction(array $record, array $headerMapping, int $rowNumber): array
    {
        $instruction = [
            'row_number' => $rowNumber,
        ];

        // Map standard fields
        $fieldMappings = [
            'amount' => ['amount', 'payment_amount'],
            'currency' => ['currency', 'curr'],
            'beneficiary_name' => ['beneficiary_name', 'recipient_name', 'payee_name', 'name'],
            'beneficiary_account' => ['beneficiary_account', 'account_number', 'account'],
            'bank_code' => ['bank_code', 'swift_code', 'swift'],
            'reference' => ['reference', 'payment_reference', 'ref'],
            'purpose_code' => ['purpose_code', 'purpose'],
            'beneficiary_address' => ['beneficiary_address', 'address'],
            'beneficiary_country' => ['beneficiary_country', 'country'],
            'beneficiary_city' => ['beneficiary_city', 'city'],
            'intermediary_bank' => ['intermediary_bank'],
            'special_instructions' => ['special_instructions', 'instructions'],
        ];

        foreach ($fieldMappings as $instructionField => $possibleHeaders) {
            foreach ($possibleHeaders as $headerVariant) {
                if (isset($headerMapping[$headerVariant])) {
                    $columnIndex = $headerMapping[$header