Looking at the context and design patterns, I need to implement the `ProcessPaymentInstructionsJob.php` file. This job is responsible for processing approved mass payment files by executing the actual payment instructions through the PaymentProcessingService.

## Code: app/Jobs/ProcessPaymentInstructionsJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Services\PaymentProcessingService;
use App\Notifications\PaymentFileApprovalPending;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;
use Throwable;

class ProcessPaymentInstructionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The mass payment file to process.
     */
    public MassPaymentFile $massPaymentFile;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 300; // 5 minutes

    /**
     * Maximum number of instructions to process in a single batch.
     */
    private const BATCH_SIZE = 50;

    /**
     * Maximum memory limit in MB for processing.
     */
    private const MEMORY_LIMIT_MB = 256;

    /**
     * Delay between batches in microseconds (0.5 seconds).
     */
    private const BATCH_DELAY_MICROSECONDS = 500000;

    /**
     * Cache key prefix for processing progress.
     */
    private const PROGRESS_CACHE_PREFIX = 'mass_payment_progress:';

    /**
     * Maximum concurrent processing jobs per client.
     */
    private const MAX_CONCURRENT_JOBS_PER_CLIENT = 3;

    /**
     * Default queue for processing payment instructions.
     */
    private const DEFAULT_QUEUE = 'processing';

    /**
     * Create a new job instance.
     */
    public function __construct(MassPaymentFile $massPaymentFile)
    {
        $this->massPaymentFile = $massPaymentFile;
        
        // Configure queue settings from config
        $this->onQueue(config('queue.mass_payments.processing.queue', self::DEFAULT_QUEUE));
        $this->timeout = config('queue.mass_payments.timeouts.payment_processing', 1800);
        $this->tries = config('queue.mass_payments.max_retries.processing', 5);
        $this->backoff = config('queue.mass_payments.error_handling.max_backoff_seconds', 300);

        // Set priority based on file amount and currency
        $priority = $this->calculateJobPriority($massPaymentFile);
        $this->onQueue($this->queue)->priority($priority);
    }

    /**
     * Execute the job.
     */
    public function handle(PaymentProcessingService $paymentProcessingService): void
    {
        $startTime = microtime(true);
        $progressKey = self::PROGRESS_CACHE_PREFIX . $this->massPaymentFile->id;

        try {
            Log::info('Starting payment instruction processing job', [
                'file_id' => $this->massPaymentFile->id,
                'total_amount' => $this->massPaymentFile->total_amount,
                'currency' => $this->massPaymentFile->currency,
                'total_instructions' => $this->massPaymentFile->valid_instructions,
                'job_id' => $this->job->getJobId(),
            ]);

            // Set memory limit for processing
            $this->setMemoryLimit();

            // Validate file can be processed
            $this->validateFileForProcessing();

            // Check concurrent processing limits
            $this->checkConcurrencyLimits();

            // Initialize progress tracking
            $this->initializeProgressTracking($progressKey);

            // Update file status to processing
            $this->updateFileStatus(MassPaymentFile::STATUS_PROCESSING);

            // Get validated payment instructions
            $instructions = $this->getValidatedInstructions();

            if ($instructions->isEmpty()) {
                throw new Exception('No validated payment instructions found for processing');
            }

            // Process instructions in batches
            $processingResults = $this->processBatchedInstructions($instructions, $paymentProcessingService, $progressKey);

            // Update file with final results
            $this->updateFileWithResults($processingResults);

            // Send completion notifications
            $this->sendCompletionNotifications($processingResults);

            // Cleanup progress tracking
            $this->cleanupProgressTracking($progressKey);

            $processingTime = round(microtime(true) - $startTime, 2);

            Log::info('Payment instruction processing job completed', [
                'file_id' => $this->massPaymentFile->id,
                'total_processed' => $processingResults['total_processed'],
                'successful' => $processingResults['successful_count'],
                'failed' => $processingResults['failed_count'],
                'processing_time' => $processingTime,
                'final_status' => $this->massPaymentFile->fresh()->status,
            ]);

        } catch (Exception $e) {
            $this->handleProcessingFailure($e, $startTime, $progressKey);
            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        $progressKey = self::PROGRESS_CACHE_PREFIX . $this->massPaymentFile->id;

        Log::error('Payment instruction processing job failed', [
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        try {
            DB::beginTransaction();

            // Update file status to failed
            $this->massPaymentFile->updateStatus(
                MassPaymentFile::STATUS_FAILED,
                [
                    'processing_failure' => [
                        'error' => $exception->getMessage(),
                        'failed_at' => Carbon::now()->toISOString(),
                        'attempts' => $this->attempts(),
                        'job_id' => $this->job->getJobId() ?? 'unknown',
                    ]
                ]
            );

            // Mark pending instructions as failed
            $this->markPendingInstructionsAsFailed($exception->getMessage());

            // Send failure notifications
            $this->sendFailureNotifications($exception);

            // Cleanup progress tracking
            $this->cleanupProgressTracking($progressKey);

            DB::commit();

        } catch (Exception $cleanupException) {
            DB::rollBack();
            
            Log::error('Failed to cleanup after processing job failure', [
                'file_id' => $this->massPaymentFile->id,
                'cleanup_error' => $cleanupException->getMessage(),
                'original_error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        // Exponential backoff: 5min, 10min, 20min, 40min, 80min
        $baseDelay = config('queue.mass_payments.error_handling.exponential_backoff', true) ? 300 : 300;
        
        return [
            $baseDelay,                    // 5 minutes
            $baseDelay * 2,               // 10 minutes  
            $baseDelay * 4,               // 20 minutes
            $baseDelay * 8,               // 40 minutes
            $baseDelay * 16,              // 80 minutes
        ];
    }

    /**
     * Determine if the job should be retried.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHours(4); // Retry for up to 4 hours
    }

    /**
     * Set memory limit for payment processing.
     */
    private function setMemoryLimit(): void
    {
        $memoryLimit = config('queue.mass_payments.memory_limits.processing', self::MEMORY_LIMIT_MB);
        ini_set('memory_limit', $memoryLimit . 'M');

        Log::debug('Memory limit set for payment processing', [
            'file_id' => $this->massPaymentFile->id,
            'memory_limit' => $memoryLimit . 'M',
        ]);
    }

    /**
     * Validate file can be processed.
     */
    private function validateFileForProcessing(): void
    {
        // Refresh model to get latest status
        $this->massPaymentFile = $this->massPaymentFile->fresh();

        if (!$this->massPaymentFile) {
            throw new Exception('Mass payment file not found');
        }

        if (!$this->massPaymentFile->isApproved()) {
            throw new Exception('Mass payment file is not in approved status: ' . $this->massPaymentFile->status);
        }

        if ($this->massPaymentFile->valid_instructions === 0) {
            throw new Exception('No valid payment instructions to process');
        }

        // Check if file is already being processed
        $processingKey = 'processing_file:' . $this->massPaymentFile->id;
        if (Cache::has($processingKey)) {
            throw new Exception('Mass payment file is already being processed by another job');
        }

        // Set processing lock
        Cache::put($processingKey, $this->job->getJobId() ?? 'unknown', now()->addMinutes(30));
    }

    /**
     * Check concurrent processing limits for client.
     */
    private function checkConcurrencyLimits(): void
    {
        $clientId = $this->massPaymentFile->client_id;
        $concurrencyKey = 'concurrent_processing:' . $clientId;
        
        $currentJobs = Cache::get($concurrencyKey, 0);
        $maxJobs = config('queue.mass_payments.max_concurrent_jobs_per_client', self::MAX_CONCURRENT_JOBS_PER_CLIENT);

        if ($currentJobs >= $maxJobs) {
            throw new Exception("Maximum concurrent processing jobs reached for client: {$currentJobs}/{$maxJobs}");
        }

        // Increment counter
        Cache::put($concurrencyKey, $currentJobs + 1, now()->addHours(2));
    }

    /**
     * Initialize progress tracking for the job.
     */
    private function initializeProgressTracking(string $progressKey): void
    {
        $progressData = [
            'status' => 'processing',
            'total_instructions' => $this->massPaymentFile->valid_instructions,
            'processed_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'current_batch' => 0,
            'started_at' => Carbon::now()->toISOString(),
            'estimated_completion' => null,
            'last_updated' => Carbon::now()->toISOString(),
        ];

        Cache::put($progressKey, $progressData, now()->addHours(1));

        Log::debug('Initialized progress tracking', [
            'file_id' => $this->massPaymentFile->id,
            'progress_key' => $progressKey,
            'total_instructions' => $progressData['total_instructions'],
        ]);
    }

    /**
     * Update file status.
     */
    private function updateFileStatus(string $status): void
    {
        $updated = $this->massPaymentFile->updateStatus($status);
        
        if (!$updated) {
            throw new Exception("Failed to update file status to: {$status}");
        }

        Log::info('File status updated', [
            'file_id' => $this->massPaymentFile->id,
            'new_status' => $status,
        ]);
    }

    /**
     * Get validated payment instructions for processing.
     */
    private function getValidatedInstructions()
    {
        return $this->massPaymentFile->paymentInstructions()
            ->where('status', PaymentInstruction::STATUS_VALIDATED)
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Process payment instructions in batches.
     */
    private function processBatchedInstructions($instructions, PaymentProcessingService $paymentProcessingService, string $progressKey): array
    {
        $results = [
            'total_processed' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'batches_processed' => 0,
            'processing_errors' => [],
            'total_amount_processed' => 0.0,
            'total_fees' => 0.0,
        ];

        $batches = $instructions->chunk(self::BATCH_SIZE);
        $totalBatches = $batches->count();

        Log::info('Starting batch processing', [
            'file_id' => $this->massPaymentFile->id,
            'total_instructions' => $instructions->count(),
            'batch_size' => self::BATCH_SIZE,
            'total_batches' => $totalBatches,
        ]);

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            
            try {
                $batchResults = $this->processBatch($batch, $batchNumber, $paymentProcessingService);
                
                // Aggregate results
                $results['total_processed'] += $batchResults['processed'];
                $results['successful_count'] += $batchResults['successful'];
                $results['failed_count'] += $batchResults['failed'];
                $results['total_amount_processed'] += $batchResults['amount_processed'];
                $results['total_fees'] += $batchResults['fees'];
                $results['batches_processed']++;
                
                // Merge errors
                $results['processing_errors'] = array_merge(
                    $results['processing_errors'], 
                    $batchResults['errors']
                );

                // Update progress
                $this->updateProgressTracking($progressKey, $results, $batchNumber, $totalBatches);

                Log::info('Batch processed successfully', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_number' => $batchNumber,
                    'batch_size' => $batch->count(),
                    'successful' => $batchResults['successful'],
                    'failed' => $batchResults['failed'],
                    'progress_percentage' => round(($results['total_processed'] / $instructions->count()) * 100, 2),
                ]);

                // Small delay between batches to avoid overwhelming external services
                if ($batchNumber < $totalBatches) {
                    usleep(self::BATCH_DELAY_MICROSECONDS);
                }

                // Force garbage collection every 5 batches
                if ($batchNumber % 5 === 0) {
                    gc_collect_cycles();
                }

            } catch (Exception $e) {
                Log::error('Batch processing failed', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_number' => $batchNumber,
                    'error' => $e->getMessage(),
                ]);

                // Mark all instructions in failed batch as failed
                $this->markBatchAsFailed($batch, $e->getMessage());
                
                $results['failed_count'] += $batch->count();