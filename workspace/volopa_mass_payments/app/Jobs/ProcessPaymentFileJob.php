## Code: app/Jobs/ProcessPaymentFileJob.php

```php
<?php

namespace App\Jobs;

use App\Models\PaymentFile;
use App\Models\PaymentInstruction;
use App\Models\ValidationError;
use App\Services\ValidationService;
use App\Services\FileProcessingService;
use App\Services\ApprovalWorkflowService;
use App\Jobs\ValidatePaymentInstructionsJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Exception;
use Carbon\Carbon;

class ProcessPaymentFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The payment file to process.
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
    public int $timeout = 600;

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
     * Maximum file processing attempts.
     */
    private const MAX_PROCESSING_ATTEMPTS = 3;

    /**
     * Create a new job instance.
     *
     * @param PaymentFile $paymentFile
     */
    public function __construct(PaymentFile $paymentFile)
    {
        $this->paymentFile = $paymentFile;
        $this->onQueue('payment-processing');
    }

    /**
     * Execute the job.
     *
     * @param ValidationService $validationService
     * @param FileProcessingService $fileProcessingService
     * @param ApprovalWorkflowService $approvalWorkflowService
     * @return void
     * @throws Exception
     */
    public function handle(
        ValidationService $validationService,
        FileProcessingService $fileProcessingService,
        ApprovalWorkflowService $approvalWorkflowService
    ): void {
        Log::info('Starting payment file processing job', [
            'payment_file_id' => $this->paymentFile->id,
            'filename' => $this->paymentFile->original_name,
            'file_size' => $this->paymentFile->file_size,
            'current_status' => $this->paymentFile->status,
        ]);

        try {
            // Validate job preconditions
            $this->validateJobPreconditions();

            // Update status to processing
            $fileProcessingService->updateFileStatus($this->paymentFile, PaymentFile::STATUS_PROCESSING);

            // Step 1: Read and validate CSV structure
            $csvData = $this->readAndValidateCsvStructure($fileProcessingService);

            // Step 2: Process and validate payment instructions
            $validationResults = $this->processPaymentInstructions($csvData, $validationService);

            // Step 3: Save validation results to database
            $this->saveValidationResults($validationResults, $fileProcessingService);

            // Step 4: Update payment file statistics
            $this->updatePaymentFileStatistics($validationResults, $fileProcessingService);

            // Step 5: Update status to validated
            $fileProcessingService->updateFileStatus($this->paymentFile, PaymentFile::STATUS_VALIDATED);

            // Step 6: Check if approval is required
            $this->handleApprovalWorkflow($approvalWorkflowService, $fileProcessingService);

            Log::info('Payment file processing job completed successfully', [
                'payment_file_id' => $this->paymentFile->id,
                'total_processed' => $validationResults['total_processed'],
                'valid_count' => $validationResults['valid_count'],
                'invalid_count' => $validationResults['invalid_count'],
                'total_amount' => $validationResults['total_amount'],
                'final_status' => $this->paymentFile->fresh()->status,
            ]);

        } catch (Exception $e) {
            Log::error('Payment file processing job failed', [
                'payment_file_id' => $this->paymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempts' => $this->attempts(),
            ]);

            $this->handleJobFailure($e, $fileProcessingService);
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
        Log::error('Payment file processing job permanently failed', [
            'payment_file_id' => $this->paymentFile->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        try {
            // Update payment file status to failed
            $this->paymentFile->refresh();
            $this->paymentFile->updateStatus(PaymentFile::STATUS_FAILED);

            // Create a system validation error for the job failure
            ValidationError::create([
                'payment_file_id' => $this->paymentFile->id,
                'row_number' => 0,
                'field_name' => 'system',
                'error_message' => 'File processing failed after ' . $this->tries . ' attempts: ' . $exception->getMessage(),
                'error_code' => ValidationError::ERROR_INVALID_FORMAT,
            ]);

            Log::info('Payment file marked as failed', [
                'payment_file_id' => $this->paymentFile->id,
                'final_status' => PaymentFile::STATUS_FAILED,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to handle job failure properly', [
                'payment_file_id' => $this->paymentFile->id,
                'original_error' => $exception->getMessage(),
                'handling_error' => $e->getMessage(),
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
        if (!$this->paymentFile->exists) {
            throw new Exception('Payment file no longer exists in database');
        }

        // Check if payment file is in correct status for processing
        $validStatuses = [
            PaymentFile::STATUS_UPLOADED,
            PaymentFile::STATUS_PROCESSING
        ];

        if (!in_array($this->paymentFile->status, $validStatuses)) {
            throw new Exception(
                "Payment file is not in correct status for processing. Current status: {$this->paymentFile->status}, expected: " .
                implode(', ', $validStatuses)
            );
        }

        // Check if stored file exists
        $filePath = $this->getStoredFilePath();
        if (!Storage::disk(self::STORAGE_DISK)->exists($filePath)) {
            throw new Exception('Stored CSV file not found at path: ' . $filePath);
        }

        // Check file size constraints
        $fileSize = Storage::disk(self::STORAGE_DISK)->size($filePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new Exception('Stored CSV file is empty or cannot be read');
        }

        Log::info('Job preconditions validated successfully', [
            'payment_file_id' => $this->paymentFile->id,
            'status' => $this->paymentFile->status,
            'file_exists' => true,
            'file_size' => $fileSize,
        ]);
    }

    /**
     * Read and validate CSV structure.
     *
     * @param FileProcessingService $fileProcessingService
     * @return array
     * @throws Exception
     */
    private function readAndValidateCsvStructure(FileProcessingService $fileProcessingService): array
    {
        Log::info('Reading and validating CSV structure', [
            'payment_file_id' => $this->paymentFile->id,
        ]);

        try {
            // Validate CSV structure using FileProcessingService
            $filePath = $this->getStoredFilePath();
            $isValidStructure = $fileProcessingService->validateCsvStructure($filePath);

            if (!$isValidStructure) {
                throw new Exception('CSV file structure validation failed');
            }

            // Read CSV data
            $csvData = $fileProcessingService->readCsvData($this->paymentFile);

            if (empty($csvData)) {
                throw new Exception('CSV file contains no data rows');
            }

            Log::info('CSV structure validated and data loaded', [
                'payment_file_id' => $this->paymentFile->id,
                'total_rows' => count($csvData),
            ]);

            return $csvData;

        } catch (Exception $e) {
            Log::error('CSV structure validation failed', [
                'payment_file_id' => $this->paymentFile->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process payment instructions with validation.
     *
     * @param array $csvData
     * @param ValidationService $validationService
     * @return array
     * @throws Exception
     */
    private function processPaymentInstructions(array $csvData, ValidationService $validationService): array
    {
        Log::info('Processing payment instructions', [
            'payment_file_id' => $this->paymentFile->id,
            'total_rows' => count($csvData),
        ]);

        try {
            // Process data in chunks to avoid memory issues
            $totalProcessed = 0;
            $totalValid = 0;
            $totalInvalid = 0;
            $totalAmount = 0.0;
            $allValidInstructions = [];
            $allValidationErrors = [];
            $detectedCurrency = null;
            $currencyConsistent = true;

            // Split data into chunks
            $chunks = array_chunk($csvData, self::CHUNK_SIZE);
            $chunkNumber = 1;

            Log::info('Processing CSV data in chunks', [
                'payment_file_id' => $this->paymentFile->id,
                'total_chunks' => count($chunks),
                'chunk_size' => self::CHUNK_SIZE,
            ]);

            foreach ($chunks as $chunk) {
                Log::debug('Processing chunk', [
                    'payment_file_id' => $this->paymentFile->id,
                    'chunk_number' => $chunkNumber,
                    'chunk_size' => count($chunk),
                ]);

                try {
                    // Calculate correct row numbers for this chunk (adding 2 for header row)
                    $chunkStartRow = ($chunkNumber - 1) * self::CHUNK_SIZE + 2;
                    
                    // Prepare chunk data with proper row indexing
                    $chunkWithRowNumbers = [];
                    foreach ($chunk as $index => $row) {
                        $chunkWithRowNumbers[] = $row;
                    }

                    // Validate chunk using ValidationService
                    $chunkResults = $validationService->validatePaymentInstructions($chunkWithRowNumbers);

                    // Adjust row numbers for chunk offset and add payment_file_id
                    foreach ($chunkResults['valid_instructions'] as &$instruction) {
                        $instruction['row_number'] = $chunkStartRow + array_search($instruction, $chunkResults['valid_instructions']);
                        $instruction['payment_file_id'] = $this->paymentFile->id;
                        
                        // Check currency consistency
                        if ($detectedCurrency === null) {
                            $detectedCurrency = $instruction['currency'];
                        } elseif ($detectedCurrency !== $instruction['currency'] && $currencyConsistent) {
                            $currencyConsistent = false;
                            Log::warning('Multiple currencies detected in payment file', [
                                'payment_file_id' => $this->paymentFile->id,
                                'first_currency' => $detectedCurrency,
                                'different_currency' => $instruction['currency'],
                                'row_number' => $instruction['row_number'],
                            ]);
                        }
                    }

                    foreach ($chunkResults['validation_errors'] as &$error) {
                        $error['row_number'] = $chunkStartRow + array_search($error, $chunkResults['validation_errors']);
                        $error['payment_file_id'] = $this->paymentFile->id;
                    }

                    // Accumulate results
                    $totalProcessed += $chunkResults['total_processed'];
                    $totalValid += $chunkResults['valid_count'];
                    $totalInvalid += $chunkResults['invalid_count'];
                    
                    $allValidInstructions = array_merge($allValidInstructions, $chunkResults['valid_instructions']);
                    $allValidationErrors = array_merge($allValidationErrors, $chunkResults['validation_errors']);

                    // Calculate total amount from valid instructions only
                    foreach ($chunkResults['valid_instructions'] as $instruction) {
                        $totalAmount += $instruction['amount'];
                    }

                    Log::debug('Chunk processing completed', [
                        'payment_file_id' => $this->paymentFile->id,
                        'chunk_number' => $chunkNumber,
                        'chunk_valid' => $chunkResults['valid_count'],
                        'chunk_invalid' => $chunkResults['invalid_count'],
                    ]);

                } catch (Exception $e) {
                    Log::error('Error processing chunk', [
                        'payment_file_id' => $this->paymentFile->id,
                        'chunk_number' => $chunkNumber,
                        'error' => $e->getMessage(),
                    ]);

                    // Create error for entire chunk
                    $chunkError = [
                        'payment_file_id' => $this->paymentFile->id,
                        'row_number' => $chunkStartRow,
                        'field_name' => 'system',
                        'error_message' => 'Chunk processing failed: ' . $e->getMessage(),
                        'error_code' => ValidationError::ERROR_INVALID_FORMAT,
                    ];

                    $allValidationErrors[] = $chunkError;
                    $totalProcessed += count($chunk);
                    $totalInvalid += count($chunk);
                }

                $chunkNumber++;
            }

            // Add currency consistency validation error if needed
            if (!$currencyConsistent) {
                $allValidationErrors[] = [
                    'payment_file_id' => $this->paymentFile->id,
                    'row_number' => 1,
                    'field_name' => 'currency',
                    'error_message' => 'Multiple currencies detected in the same file. All payment instructions must use the same currency.',
                    'error_code' => ValidationError::ERROR_INVALID_CURRENCY,
                ];
            }

            // Set detected currency or default
            $finalCurrency = $detectedCurrency ?? PaymentFile::CURRENCY_USD;

            $results = [
                'total_processed' => $totalProcessed,
                'valid_count' => $totalValid,