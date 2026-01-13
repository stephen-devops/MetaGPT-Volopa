## Code: app/Jobs/ProcessPaymentInstructionsJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Services\PaymentProcessingService;
use App\Services\MassPaymentFileService;
use App\Notifications\PaymentProcessingNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use Exception;
use Throwable;

class ProcessPaymentInstructionsJob implements ShouldQueue
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
    public int $tries = 5;

    /**
     * The maximum number of seconds the job can run.
     *
     * @var int
     */
    public int $timeout = 600;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public array $backoff = [120, 600, 1800, 3600, 7200];

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
    public int $maxExceptions = 2;

    /**
     * Maximum number of payment instructions to process in a single batch.
     *
     * @var int
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Cache TTL for processing status in seconds.
     *
     * @var int
     */
    private const CACHE_TTL = 300;

    /**
     * Create a new job instance.
     *
     * @param MassPaymentFile $massPaymentFile
     */
    public function __construct(MassPaymentFile $massPaymentFile)
    {
        $this->massPaymentFile = $massPaymentFile;
        
        // Set queue based on file priority and amount
        $this->onQueue($this->determineQueue($massPaymentFile));
        
        // Set connection based on environment
        $this->onConnection(config('queue.default', 'redis'));
        
        // Set job delay if needed
        $this->delay($this->calculateDelay($massPaymentFile));
        
        Log::info('ProcessPaymentInstructionsJob created', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'total_amount' => $massPaymentFile->total_amount,
            'currency' => $massPaymentFile->currency,
            'queue' => $this->queue,
            'connection' => $this->connection
        ]);
    }

    /**
     * Execute the job.
     *
     * @param PaymentProcessingService $paymentProcessingService
     * @return void
     * @throws Exception
     */
    public function handle(PaymentProcessingService $paymentProcessingService): void
    {
        $startTime = microtime(true);
        
        Log::info('Starting payment processing job', [
            'file_id' => $this->massPaymentFile->id,
            'client_id' => $this->massPaymentFile->client_id,
            'total_amount' => $this->massPaymentFile->total_amount,
            'currency' => $this->massPaymentFile->currency,
            'current_status' => $this->massPaymentFile->status,
            'job_attempt' => $this->attempts()
        ]);

        try {
            // Update cache with processing status
            $this->updateProcessingStatus('starting');

            // Refresh the model to ensure we have the latest data
            $this->massPaymentFile->refresh();

            // Validate that the file is in the correct state for processing
            $this->validateFileState();

            // Get payment instructions that need processing
            $instructions = $this->getInstructionsForProcessing();

            if ($instructions->isEmpty()) {
                Log::info('No payment instructions to process', [
                    'file_id' => $this->massPaymentFile->id
                ]);

                $this->completeProcessing(0, 0);
                return;
            }

            // Update file status to processing payments
            $this->massPaymentFile->markProcessingPayments();
            $this->updateProcessingStatus('processing_payments');

            // Process instructions in batches
            $result = $this->processInstructionBatches($instructions, $paymentProcessingService);

            // Finalize processing based on results
            $this->finalizeProcessing($result);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Payment processing job completed successfully', [
                'file_id' => $this->massPaymentFile->id,
                'successful_payments' => $result['successful'],
                'failed_payments' => $result['failed'],
                'processing_time_ms' => $processingTime,
                'final_status' => $this->massPaymentFile->fresh()->status
            ]);

        } catch (Exception $e) {
            $this->handleProcessingFailure($e);
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
        Log::error('Payment processing job failed', [
            'file_id' => $this->massPaymentFile->id,
            'client_id' => $this->massPaymentFile->client_id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        try {
            // Mark file as failed
            $this->massPaymentFile->markAsFailed(
                'Payment processing failed: ' . $exception->getMessage()
            );

            // Update any processing payment instructions to failed
            $this->markProcessingInstructionsAsFailed($exception->getMessage());

            // Clear processing status cache
            $this->clearProcessingStatus();

            // Send failure notification
            $this->sendFailureNotification($exception);

        } catch (Exception $e) {
            Log::error('Failed to handle payment processing job failure', [
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
        return min(7200, pow(2, $attempt) * 120); // Max 2 hours
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'payment_processing',
            'client:' . $this->massPaymentFile->client_id,
            'file:' . $this->massPaymentFile->id,
            'currency:' . $this->massPaymentFile->currency,
            'amount:' . number_format($this->massPaymentFile->total_amount, 2)
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
        $metadata = $massPaymentFile->metadata ?? [];
        $priority = $metadata['priority'] ?? 'normal';
        $amount = $massPaymentFile->total_amount;
        
        // High-value payments get priority queue
        if ($amount > 1000000) {
            return 'mass_payments_high';
        }
        
        // Priority-based queue selection
        switch ($priority) {
            case 'urgent':
            case 'high':
                return 'mass_payments_high';
            case 'low':
                return 'mass_payments_low';
            default:
                return 'payment_processing';
        }
    }

    /**
     * Calculate delay for job execution.
     *
     * @param MassPaymentFile $massPaymentFile
     * @return int
     */
    private function calculateDelay(MassPaymentFile $massPaymentFile): int
    {
        $metadata = $massPaymentFile->metadata ?? [];
        $priority = $metadata['priority'] ?? 'normal';
        
        // No delay for urgent payments
        if ($priority === 'urgent') {
            return 0;
        }
        
        // Small delay for high priority
        if ($priority === 'high') {
            return 30; // 30 seconds
        }
        
        // Longer delay for low priority
        if ($priority === 'low') {
            return 300; // 5 minutes
        }
        
        // Normal priority - small delay to allow for any immediate cancellations
        return 60; // 1 minute
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
            MassPaymentFile::STATUS_APPROVED,
            MassPaymentFile::STATUS_PROCESSING_PAYMENTS
        ];

        if (!in_array($this->massPaymentFile->status, $validStates)) {
            throw new Exception(
                "File cannot be processed in current state: {$this->massPaymentFile->status}"
            );
        }

        // Check if file was soft-deleted
        if ($this->massPaymentFile->trashed()) {
            throw new Exception('Cannot process a deleted file');
        }

        // Validate file has been approved
        if (empty($this->massPaymentFile->approved_by) || empty($this->massPaymentFile->approved_at)) {
            throw new Exception('File has not been properly approved');
        }

        // Check if file has validation errors
        if ($this->massPaymentFile->hasValidationErrors() && 
            $this->massPaymentFile->status !== MassPaymentFile::STATUS_PROCESSING_PAYMENTS) {
            throw new Exception('File has validation errors and cannot be processed');
        }
    }

    /**
     * Get payment instructions that need processing.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getInstructionsForProcessing(): \Illuminate\Support\Collection
    {
        return $this->massPaymentFile->paymentInstructions()
            ->where('status', PaymentInstruction::STATUS_PENDING)
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Process payment instructions in batches.
     *
     * @param \Illuminate\Support\Collection $instructions
     * @param PaymentProcessingService $paymentProcessingService
     * @return array
     * @throws Exception
     */
    private function processInstructionBatches(
        \Illuminate\Support\Collection $instructions,
        PaymentProcessingService $paymentProcessingService
    ): array {
        $batches = $instructions->chunk(self::MAX_BATCH_SIZE);
        $totalBatches = $batches->count();
        $processedBatches = 0;
        $successfulPayments = 0;
        $failedPayments = 0;

        Log::info('Starting batch processing', [
            'file_id' => $this->massPaymentFile->id,
            'total_instructions' => $instructions->count(),
            'total_batches' => $totalBatches,
            'batch_size' => self::MAX_BATCH_SIZE
        ]);

        foreach ($batches as $batchIndex => $batch) {
            try {
                Log::info('Processing payment batch', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'batch_size' => $batch->count()
                ]);

                // Update processing status with progress
                $this->updateProcessingProgress(
                    $processedBatches,
                    $totalBatches,
                    $successfulPayments,
                    $failedPayments
                );

                // Process the batch
                $batchResult = $this->processSingleBatch($batch, $paymentProcessingService);
                
                $successfulPayments += $batchResult['successful'];
                $failedPayments += $batchResult['failed'];
                $processedBatches++;

                Log::info('Batch processing completed', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_index' => $batchIndex + 1,
                    'batch_successful' => $batchResult['successful'],
                    'batch_failed' => $batchResult['failed'],
                    'total_successful' => $successfulPayments,
                    'total_failed' => $failedPayments
                ]);

                // Add small delay between batches to prevent overwhelming external systems
                if ($batchIndex < $totalBatches - 1) {
                    usleep(500000); // 0.5 seconds
                }

            } catch (Exception $e) {
                Log::error('Batch processing failed', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_index' => $batchIndex + 1,
                    'error' => $e->getMessage()
                ]);

                // Mark all instructions in this batch as failed
                $this->markBatchAsFailed($batch, $e->getMessage());
                $failedPayments += $batch->count();
                $processedBatches++;

                // Continue with next batch unless it's a critical error
                if ($this->isCriticalError($e)) {
                    throw $e;
                }
            }
        }

        return [
            'successful' => $successfulPayments,
            'failed' => $failedPayments,
            'total_batches' => $totalBatches,
            'processed_batches' => $processedBatches
        ];
    }

    /**
     * Process a single batch of payment instructions.
     *
     * @param \Illuminate\Support\Collection $batch
     * @param PaymentProcessingService $paymentProcessingService
     * @return array
     */
    private function processSingleBatch(
        \Illuminate\Support\Collection $batch,
        PaymentProcessingService $paymentProcessingService
    ): array {
        $successful = 0;
        $failed = 0;

        foreach ($batch as $instruction) {
            try {
                // Mark instruction as processing
                $instruction->update(['status' => PaymentInstruction::STATUS_PROCESSING]);

                // Process the payment
                $result = $this->processIndividualPayment($instruction, $paymentProcessingService);

                if ($result) {
                    $successful++;
                } else {
                    $failed++;
                }

            } catch (Exception $e) {
                Log::error('Individual payment processing failed', [
                    'instruction_id' => $instruction->id,
                    'file_id' => $this->massPaymentFile->id,
                    'error' => $e->getMessage()
                ]);

                