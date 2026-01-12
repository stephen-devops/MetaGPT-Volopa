{
  "Development Plan": [
    "app/Jobs/ValidatePaymentInstructionsJob.php"
  ],
  "Incremental Change": [
    "app/Jobs/ValidatePaymentInstructionsJob.php: Queue job for CSV validation processing with chunked processing and error handling"
  ]
}

## Code: app/Jobs/ValidatePaymentInstructionsJob.php

```php
<?php

namespace App\Jobs;

use App\Models\PaymentFile;
use App\Models\PaymentInstruction;
use App\Models\ValidationError;
use App\Services\ValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;
use Exception;
use Carbon\Carbon;

class ValidatePaymentInstructionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The payment file to validate.
     */
    private PaymentFile $paymentFile;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Number of records to process in each chunk.
     */
    private const CHUNK_SIZE = 100;

    /**
     * File storage disk name.
     */
    private const STORAGE_DISK = 'local';

    /**
     * File storage directory.
     */
    private const STORAGE_DIRECTORY = 'payment-files';

    /**
     * Create a new job instance.
     *
     * @param PaymentFile $paymentFile
     */
    public function __construct(PaymentFile $paymentFile)
    {
        $this->paymentFile = $paymentFile;
        $this->onQueue('payment-validation');
    }

    /**
     * Execute the job.
     *
     * @param ValidationService $validationService
     * @return void
     * @throws Exception
     */
    public function handle(ValidationService $validationService): void
    {
        Log::info('Starting payment instructions validation job', [
            'payment_file_id' => $this->paymentFile->id,
            'filename' => $this->paymentFile->original_name,
            'file_size' => $this->paymentFile->file_size,
        ]);

        try {
            // Validate that the payment file exists and is in the correct status
            $this->validateJobPreconditions();

            // Read CSV data from stored file
            $csvData = $this->readCsvData();

            if (empty($csvData)) {
                throw new Exception('CSV file contains no data rows');
            }

            Log::info('CSV data loaded for validation', [
                'payment_file_id' => $this->paymentFile->id,
                'total_rows' => count($csvData),
            ]);

            // Process CSV data in chunks to avoid memory issues
            $validationResults = $this->processInstructionsInChunks($csvData, $validationService);

            // Save validation results to database
            $this->saveValidationResults($validationResults);

            // Update payment file statistics and status
            $this->updatePaymentFileStatus($validationResults);

            Log::info('Payment instructions validation job completed', [
                'payment_file_id' => $this->paymentFile->id,
                'total_processed' => $validationResults['total_processed'],
                'valid_count' => $validationResults['valid_count'],
                'invalid_count' => $validationResults['invalid_count'],
                'total_amount' => $validationResults['total_amount'],
            ]);

        } catch (Exception $e) {
            Log::error('Payment instructions validation job failed', [
                'payment_file_id' => $this->paymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleJobFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error('Payment instructions validation job permanently failed', [
            'payment_file_id' => $this->paymentFile->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        try {
            // Update payment file status to failed
            $this->paymentFile->updateStatus(PaymentFile::STATUS_FAILED);

            // Create a validation error record for the job failure
            ValidationError::create([
                'payment_file_id' => $this->paymentFile->id,
                'row_number' => 0,
                'field_name' => 'system',
                'error_message' => 'Validation job failed: ' . $exception->getMessage(),
                'error_code' => ValidationError::ERROR_INVALID_FORMAT,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to update payment file status after job failure', [
                'payment_file_id' => $this->paymentFile->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate job preconditions before processing.
     *
     * @return void
     * @throws Exception
     */
    private function validateJobPreconditions(): void
    {
        // Refresh model from database to get latest status
        $this->paymentFile->refresh();

        // Check if payment file still exists
        if (!$this->paymentFile) {
            throw new Exception('Payment file not found');
        }

        // Check if payment file is in correct status for validation
        if (!in_array($this->paymentFile->status, [
            PaymentFile::STATUS_UPLOADED,
            PaymentFile::STATUS_PROCESSING
        ])) {
            throw new Exception("Payment file is not in correct status for validation. Current status: {$this->paymentFile->status}");
        }

        // Check if stored file exists
        $filePath = $this->getStoredFilePath();
        if (!Storage::disk(self::STORAGE_DISK)->exists($filePath)) {
            throw new Exception('Stored CSV file not found');
        }
    }

    /**
     * Read CSV data from stored file.
     *
     * @return array
     * @throws Exception
     */
    private function readCsvData(): array
    {
        try {
            $filePath = $this->getStoredFilePath();
            $fullPath = Storage::disk(self::STORAGE_DISK)->path($filePath);

            // Create CSV reader
            $csv = Reader::createFromPath($fullPath, 'r');
            $csv->setHeaderOffset(0);

            // Convert to array for processing
            $records = [];
            foreach ($csv as $record) {
                $records[] = $record;
            }

            Log::info('CSV data read successfully', [
                'payment_file_id' => $this->paymentFile->id,
                'record_count' => count($records),
            ]);

            return $records;

        } catch (Exception $e) {
            Log::error('Failed to read CSV data', [
                'payment_file_id' => $this->paymentFile->id,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to read CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Process payment instructions in chunks.
     *
     * @param array $csvData
     * @param ValidationService $validationService
     * @return array
     */
    private function processInstructionsInChunks(array $csvData, ValidationService $validationService): array
    {
        $totalProcessed = 0;
        $totalValid = 0;
        $totalInvalid = 0;
        $totalAmount = 0.0;
        $allValidInstructions = [];
        $allValidationErrors = [];
        $detectedCurrency = null;

        // Process data in chunks
        $chunks = array_chunk($csvData, self::CHUNK_SIZE);
        $chunkNumber = 1;

        Log::info('Processing CSV data in chunks', [
            'payment_file_id' => $this->paymentFile->id,
            'total_chunks' => count($chunks),
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        foreach ($chunks as $chunk) {
            Log::info('Processing chunk', [
                'payment_file_id' => $this->paymentFile->id,
                'chunk_number' => $chunkNumber,
                'chunk_size' => count($chunk),
            ]);

            try {
                // Calculate correct row numbers for this chunk
                $chunkStartRow = ($chunkNumber - 1) * self::CHUNK_SIZE;
                $chunkWithRowNumbers = [];
                
                foreach ($chunk as $index => $row) {
                    $chunkWithRowNumbers[] = $row;
                }

                // Validate chunk using ValidationService
                $chunkResults = $validationService->validatePaymentInstructions($chunkWithRowNumbers);

                // Adjust row numbers for chunk offset
                foreach ($chunkResults['valid_instructions'] as &$instruction) {
                    $instruction['row_number'] += $chunkStartRow;
                }

                foreach ($chunkResults['validation_errors'] as &$error) {
                    $error['row_number'] += $chunkStartRow;
                    $error['payment_file_id'] = $this->paymentFile->id;
                }

                // Accumulate results
                $totalProcessed += $chunkResults['total_processed'];
                $totalValid += $chunkResults['valid_count'];
                $totalInvalid += $chunkResults['invalid_count'];
                
                $allValidInstructions = array_merge($allValidInstructions, $chunkResults['valid_instructions']);
                $allValidationErrors = array_merge($allValidationErrors, $chunkResults['validation_errors']);

                // Calculate total amount and detect currency from valid instructions
                foreach ($chunkResults['valid_instructions'] as $instruction) {
                    $totalAmount += $instruction['amount'];
                    
                    // Set detected currency from first valid instruction
                    if (!$detectedCurrency && isset($instruction['currency'])) {
                        $detectedCurrency = $instruction['currency'];
                    }
                }

                Log::info('Chunk processing completed', [
                    'payment_file_id' => $this->paymentFile->id,
                    'chunk_number' => $chunkNumber,
                    'chunk_valid' => $chunkResults['valid_count'],
                    'chunk_invalid' => $chunkResults['invalid_count'],
                ]);

                $chunkNumber++;

            } catch (Exception $e) {
                Log::error('Error processing chunk', [
                    'payment_file_id' => $this->paymentFile->id,
                    'chunk_number' => $chunkNumber,
                    'error' => $e->getMessage(),
                ]);

                // Create error for entire chunk
                $allValidationErrors[] = [
                    'payment_file_id' => $this->paymentFile->id,
                    'row_number' => $chunkStartRow + 1,
                    'field_name' => 'system',
                    'error_message' => 'Chunk processing failed: ' . $e->getMessage(),
                    'error_code' => ValidationError::ERROR_INVALID_FORMAT,
                ];

                $totalProcessed += count($chunk);
                $totalInvalid += count($chunk);
                $chunkNumber++;
            }
        }

        return [
            'total_processed' => $totalProcessed,
            'valid_count' => $totalValid,
            'invalid_count' => $totalInvalid,
            'total_amount' => $totalAmount,
            'detected_currency' => $detectedCurrency ?? PaymentFile::CURRENCY_USD,
            'valid_instructions' => $allValidInstructions,
            'validation_errors' => $allValidationErrors,
        ];
    }

    /**
     * Save validation results to database.
     *
     * @param array $validationResults
     * @return void
     * @throws Exception
     */
    private function saveValidationResults(array $validationResults): void
    {
        Log::info('Saving validation results to database', [
            'payment_file_id' => $this->paymentFile->id,
            'valid_instructions' => count($validationResults['valid_instructions']),
            'validation_errors' => count($validationResults['validation_errors']),
        ]);

        try {
            DB::transaction(function () use ($validationResults) {
                // Clear any existing validation errors and payment instructions
                ValidationError::where('payment_file_id', $this->paymentFile->id)->delete();
                PaymentInstruction::where('payment_file_id', $this->paymentFile->id)->delete();

                // Save valid payment instructions in batches
                if (!empty($validationResults['valid_instructions'])) {
                    $instructionsToInsert = [];
                    $timestamp = Carbon::now();

                    foreach ($validationResults['valid_instructions'] as $instruction) {
                        $instructionsToInsert[] = array_merge($instruction, [
                            'payment_file_id' => $this->paymentFile->id,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    }

                    // Insert in batches to avoid query size limits
                    $instructionChunks = array_chunk($instructionsToInsert, 500);
                    foreach ($instructionChunks as $chunk) {
                        PaymentInstruction::insert($chunk);
                    }

                    Log::info('Payment instructions saved', [
                        'payment_file_id' => $this->paymentFile->id,
                        'count' => count($instructionsToInsert),
                    ]);
                }

                // Save validation errors in batches
                if (!empty($validationResults['validation_errors'])) {
                    $errorsToInsert = [];
                    $timestamp = Carbon::now();

                    foreach ($validationResults['validation_errors'] as $error) {
                        $errorsToInsert[] = array_merge($error, [
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    }

                    // Insert in batches to avoid query size limits
                    $errorChunks = array_chunk($errorsToInsert, 500);
                    foreach ($errorChunks as $chunk) {
                        ValidationError::insert($chunk);
                    }

                    Log::info('Validation errors saved', [
                        'payment_file_id' => $this->paymentFile->id,
                        'count' => count($errorsToInsert),
                    ]);
                }
            });

        } catch (Exception $e) {
            Log::error('Failed to save validation results', [
                'payment_file_id' => $this->paymentFile->id,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to save validation results: ' . $e->getMessage());
        }
    }

    /**
     * Update payment file status and statistics.
     *
     * @param array $validationResults
     * @return void
     * @throws Exception
     */
    private function updatePaymentFileStatus(array $validationResults): void
    {
        Log::info('Updating payment file status and statistics', [
            'payment_file_id' => $this->paymentFile->id,
        ]);

        try {
            // Update payment