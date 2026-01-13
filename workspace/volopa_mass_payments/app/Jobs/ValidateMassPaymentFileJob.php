## Code: app/Jobs/ValidateMassPaymentFileJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Services\CsvValidationService;
use App\Services\MassPaymentFileService;
use App\Notifications\ApprovalRequiredNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Exception;
use Throwable;

class ValidateMassPaymentFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The mass payment file instance.
     *
     * @var MassPaymentFile
     */
    protected MassPaymentFile $massPaymentFile;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public array $backoff = [60, 300, 900];

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public bool $failOnTimeout = true;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     *
     * @param MassPaymentFile $massPaymentFile
     */
    public function __construct(MassPaymentFile $massPaymentFile)
    {
        $this->massPaymentFile = $massPaymentFile;
        
        // Set queue based on file priority
        $this->onQueue($this->determineQueue($massPaymentFile));
        
        // Set connection based on environment
        $this->onConnection(config('queue.default', 'redis'));
        
        Log::info('ValidateMassPaymentFileJob created', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'queue' => $this->queue,
            'connection' => $this->connection
        ]);
    }

    /**
     * Execute the job.
     *
     * @param CsvValidationService $csvValidationService
     * @return void
     * @throws Exception
     */
    public function handle(CsvValidationService $csvValidationService): void
    {
        $startTime = microtime(true);
        
        Log::info('Starting mass payment file validation job', [
            'file_id' => $this->massPaymentFile->id,
            'client_id' => $this->massPaymentFile->client_id,
            'filename' => $this->massPaymentFile->original_filename,
            'current_status' => $this->massPaymentFile->status,
            'job_attempt' => $this->attempts()
        ]);

        try {
            // Update cache with processing status
            $this->updateProcessingStatus('validating');

            // Refresh the model to ensure we have the latest data
            $this->massPaymentFile->refresh();

            // Validate that the file is in the correct state for processing
            $this->validateFileState();

            // Update file status to processing
            $this->massPaymentFile->markAsProcessing();

            // Check if file exists on disk
            $this->validateFileExists();

            // Perform CSV validation
            $validationResult = $this->performValidation($csvValidationService);

            // Process validation results
            $this->processValidationResults($validationResult);

            // Update file with validation summary
            $this->updateFileValidationSummary($validationResult);

            // Determine next status and actions
            $this->determineNextActions($validationResult);

            // Update processing status cache
            $this->updateProcessingStatus('completed');

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Mass payment file validation job completed successfully', [
                'file_id' => $this->massPaymentFile->id,
                'validation_status' => $validationResult['valid'] ? 'passed' : 'failed',
                'total_rows' => $validationResult['summary']['total_rows'] ?? 0,
                'valid_rows' => $validationResult['summary']['valid_rows'] ?? 0,
                'invalid_rows' => $validationResult['summary']['invalid_rows'] ?? 0,
                'processing_time_ms' => $processingTime,
                'final_status' => $this->massPaymentFile->fresh()->status
            ]);

        } catch (Exception $e) {
            $this->handleValidationFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Mass payment file validation job failed', [
            'file_id' => $this->massPaymentFile->id,
            'client_id' => $this->massPaymentFile->client_id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        try {
            // Mark file as failed
            $this->massPaymentFile->markAsFailed(
                'Validation processing failed: ' . $exception->getMessage()
            );

            // Clear processing status cache
            $this->clearProcessingStatus();

            // Send failure notification
            $this->sendFailureNotification($exception);

        } catch (Exception $e) {
            Log::error('Failed to handle job failure', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function backoff(): int
    {
        $attempt = $this->attempts();
        
        if ($attempt <= count($this->backoff)) {
            return $this->backoff[$attempt - 1];
        }
        
        // For attempts beyond our backoff array, use exponential backoff
        return min(3600, pow(2, $attempt) * 60); // Max 1 hour
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'mass_payment_validation',
            'client:' . $this->massPaymentFile->client_id,
            'file:' . $this->massPaymentFile->id,
            'currency:' . $this->massPaymentFile->currency
        ];
    }

    /**
     * Determine the appropriate queue for this job.
     *
     * @param MassPaymentFile $massPaymentFile
     * @return string
     */
    private function determineQueue(MassPaymentFile $massPaymentFile): string
    {
        // Get file metadata to determine priority
        $metadata = $massPaymentFile->metadata ?? [];
        $priority = $metadata['priority'] ?? 'normal';
        $fileSize = $metadata['file_size'] ?? 0;
        
        // Large files get dedicated queue
        if ($fileSize > 10485760) { // 10MB
            return 'file_validation';
        }
        
        // Priority-based queue selection
        switch ($priority) {
            case 'urgent':
            case 'high':
                return 'mass_payments_high';
            case 'low':
                return 'mass_payments_low';
            default:
                return 'file_validation';
        }
    }

    /**
     * Validate that the file is in the correct state for processing.
     *
     * @return void
     * @throws Exception
     */
    private function validateFileState(): void
    {
        $validStates = [
            MassPaymentFile::STATUS_UPLOADING,
            MassPaymentFile::STATUS_PROCESSING
        ];

        if (!in_array($this->massPaymentFile->status, $validStates)) {
            throw new Exception(
                "File cannot be validated in current state: {$this->massPaymentFile->status}"
            );
        }

        // Check if file was soft-deleted
        if ($this->massPaymentFile->trashed()) {
            throw new Exception('Cannot validate a deleted file');
        }

        // Validate required fields
        if (empty($this->massPaymentFile->file_path)) {
            throw new Exception('File path is missing');
        }

        if (empty($this->massPaymentFile->currency)) {
            throw new Exception('Currency is missing');
        }
    }

    /**
     * Validate that the file exists on disk.
     *
     * @return void
     * @throws Exception
     */
    private function validateFileExists(): void
    {
        $filePath = $this->massPaymentFile->file_path;
        
        if (!Storage::disk(config('filesystems.default', 'local'))->exists($filePath)) {
            throw new Exception("File not found on disk: {$filePath}");
        }

        // Get full path for direct file operations
        $fullPath = Storage::disk(config('filesystems.default', 'local'))->path($filePath);
        
        if (!is_readable($fullPath)) {
            throw new Exception("File is not readable: {$fullPath}");
        }

        // Validate file size hasn't changed
        $currentSize = filesize($fullPath);
        $expectedSize = $this->massPaymentFile->metadata['file_size'] ?? 0;
        
        if ($expectedSize > 0 && abs($currentSize - $expectedSize) > 100) {
            throw new Exception(
                "File size mismatch. Expected: {$expectedSize}, Actual: {$currentSize}"
            );
        }
    }

    /**
     * Perform the CSV validation.
     *
     * @param CsvValidationService $csvValidationService
     * @return array
     * @throws Exception
     */
    private function performValidation(CsvValidationService $csvValidationService): array
    {
        $filePath = $this->massPaymentFile->file_path;
        $fullPath = Storage::disk(config('filesystems.default', 'local'))->path($filePath);
        
        // Create a mock UploadedFile for the validation service
        $fileInfo = new \SplFileInfo($fullPath);
        $uploadedFile = new \Illuminate\Http\Testing\File(
            $fileInfo->getFilename(),
            fopen($fullPath, 'r')
        );

        // Perform validation
        $validationResult = $csvValidationService->validateFile($uploadedFile);

        // Add additional context
        $validationResult['file_info'] = [
            'path' => $filePath,
            'size' => filesize($fullPath),
            'modified' => filemtime($fullPath),
            'validation_timestamp' => now()->toISOString()
        ];

        return $validationResult;
    }

    /**
     * Process validation results and handle any errors.
     *
     * @param array $validationResult
     * @return void
     * @throws Exception
     */
    private function processValidationResults(array $validationResult): void
    {
        // Log validation summary
        Log::info('Validation results processed', [
            'file_id' => $this->massPaymentFile->id,
            'valid' => $validationResult['valid'],
            'total_rows' => $validationResult['summary']['total_rows'] ?? 0,
            'valid_rows' => $validationResult['summary']['valid_rows'] ?? 0,
            'invalid_rows' => $validationResult['summary']['invalid_rows'] ?? 0,
            'error_count' => count($validationResult['errors'] ?? []),
            'warning_count' => count($validationResult['warnings'] ?? [])
        ]);

        // Check for critical errors that should stop processing
        $criticalErrors = $this->identifyCriticalErrors($validationResult);
        
        if (!empty($criticalErrors)) {
            throw new Exception(
                'Critical validation errors found: ' . implode(', ', $criticalErrors)
            );
        }
    }

    /**
     * Update file with validation summary.
     *
     * @param array $validationResult
     * @return void
     */
    private function updateFileValidationSummary(array $validationResult): void
    {
        $summary = $validationResult['summary'] ?? [];
        
        $updateData = [
            'total_rows' => $summary['total_rows'] ?? 0,
            'valid_rows' => $summary['valid_rows'] ?? 0,
            'invalid_rows' => $summary['invalid_rows'] ?? 0,
            'total_amount' => $summary['total_amount'] ?? 0.00,
            'validation_summary' => [
                'validation_status' => $validationResult['valid'] ? 'passed' : 'failed',
                'processing_time' => $summary['processing_time'] ?? 0,
                'currencies' => $summary['currencies'] ?? [],
                'error_summary' => $this->summarizeErrors($validationResult),
                'warning_summary' => $this->summarizeWarnings($validationResult),
                'validated_at' => now()->toISOString()
            ]
        ];

        // Only add validation errors if there are any
        if (!empty($validationResult['row_errors'])) {
            $updateData['validation_errors'] = $this->formatValidationErrors($validationResult['row_errors']);
        }

        $this->massPaymentFile->update($updateData);
    }

    /**
     * Determine next actions based on validation results.
     *
     * @param array $validationResult
     * @return void
     */
    private function determineNextActions(array $validationResult): void
    {
        if ($validationResult['valid']) {
            // Mark as validation completed and pending approval
            $this->massPaymentFile->markValidationCompleted($validationResult['summary'] ?? []);
            $this->massPaymentFile->markPendingApproval();
            
            // Send approval required notification
            $this->sendApprovalRequiredNotification();
            
        } else {
            // Mark as validation failed
            $errorSummary = $this->createErrorSummary($validationResult);
            $this->massPaymentFile->markValidationFailed($errorSummary);
            
            // Send validation failure notification
            $this->sendValidationFailureNotification($validationResult);
        }
    }

    /**
     * Update processing status in cache.
     *
     * @param string $status
     * @return void
     */
    private function updateProcessingStatus(string $status): void
    {
        $cacheKey = "mass_payment_file_status:{$this->massPaymentFile->id}";
        
        $statusData = [
            'status' => $status,
            'file_id' => $this->massPaymentFile->id,
            'updated_at' => now()->toISOString(),
            'job_attempt' => $this->attempts()
        ];

        Cache::put($cacheKey, $statusData, 300); // 5 minutes
    }

    /**
     * Clear processing status from cache.
     *
     * @return void
     */
    private function clearProcessingStatus(): void
    {
        $cacheKey = "mass_payment_file_status:{$this->massPaymentFile->id}";
        Cache::forget($cacheKey);
    }

    /**
     