{
  "Development Plan": [
    "app/Jobs/ProcessPaymentInstructionsJob.php"
  ],
  "Incremental Change": [
    "app/Jobs/ProcessPaymentInstructionsJob.php: Async job for payment instruction processing with batch handling, external API integration, status tracking, and comprehensive error handling"
  ]
}

## Code: app/Jobs/ProcessPaymentInstructionsJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Services\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;
use Throwable;

class ProcessPaymentInstructionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Mass payment file to process
     */
    protected MassPaymentFile $massPaymentFile;

    /**
     * Payment instruction IDs to process in this job
     */
    protected array $instructionIds;

    /**
     * Batch identifier for tracking
     */
    protected string $batchId;

    /**
     * Processing type (normal, retry, priority)
     */
    protected string $processingType;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * The maximum number of seconds the job should run.
     */
    public int $timeout = 1800;

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHours(4);
    }

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 300;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param array $instructionIds
     * @param string $batchId
     * @param string $processingType
     */
    public function __construct(
        MassPaymentFile $massPaymentFile,
        array $instructionIds,
        string $batchId = '',
        string $processingType = 'normal'
    ) {
        $this->massPaymentFile = $massPaymentFile->withoutRelations();
        $this->instructionIds = $instructionIds;
        $this->batchId = $batchId ?: 'batch_' . uniqid();
        $this->processingType = $processingType;
        
        // Set queue configuration
        $this->onQueue(config('mass-payments.queue.processing_queue', 'payments'));
        $this->timeout = config('mass-payments.queue.processing_timeout', 1800);
        $this->tries = config('mass-payments.queue.max_processing_attempts', 5);
        
        // Set priority based on processing type
        if ($processingType === 'priority') {
            $this->onQueue('payments-priority');
        } elseif ($processingType === 'retry') {
            $this->onQueue('payments-retry');
        }
    }

    /**
     * Execute the job.
     *
     * @param PaymentProcessingService $processor
     * @return void
     * @throws Exception
     */
    public function handle(PaymentProcessingService $processor): void
    {
        $startTime = microtime(true);

        Log::info('Starting payment instruction processing job', [
            'file_id' => $this->massPaymentFile->id,
            'batch_id' => $this->batchId,
            'instruction_count' => count($this->instructionIds),
            'processing_type' => $this->processingType,
            'job_id' => $this->job->getJobId(),
            'attempt' => $this->attempts(),
        ]);

        // Refresh model to get latest state
        $this->massPaymentFile->refresh();

        // Validate file is in processable state
        if (!$this->canBeProcessed()) {
            Log::warning('Mass payment file is not in a processable state', [
                'file_id' => $this->massPaymentFile->id,
                'current_status' => $this->massPaymentFile->status,
                'batch_id' => $this->batchId,
            ]);
            return;
        }

        // Set processing lock to prevent duplicate processing
        $lockKey = $this->getLockKey();
        $lockTtl = 3600; // 1 hour

        if (!Cache::add($lockKey, $this->batchId, $lockTtl)) {
            Log::warning('Payment processing job is already running for this batch', [
                'file_id' => $this->massPaymentFile->id,
                'batch_id' => $this->batchId,
                'lock_key' => $lockKey,
            ]);
            return;
        }

        try {
            // Get payment instructions to process
            $instructions = $this->getPaymentInstructions();

            if ($instructions->isEmpty()) {
                Log::warning('No payment instructions found for processing', [
                    'file_id' => $this->massPaymentFile->id,
                    'batch_id' => $this->batchId,
                    'instruction_ids' => $this->instructionIds,
                ]);
                return;
            }

            // Track batch processing statistics
            $batchStats = [
                'total_instructions' => $instructions->count(),
                'successful_payments' => 0,
                'failed_payments' => 0,
                'skipped_payments' => 0,
                'total_amount_processed' => 0.0,
                'processing_errors' => [],
                'start_time' => now()->toISOString(),
            ];

            // Process each payment instruction
            foreach ($instructions as $instruction) {
                $this->processIndividualPayment($processor, $instruction, $batchStats);
                
                // Check for job cancellation or timeout
                if ($this->shouldStopProcessing()) {
                    break;
                }
            }

            // Complete batch processing
            $this->completeBatchProcessing($batchStats, $startTime);

            // Check if entire file processing is complete
            $this->checkFileProcessingCompletion();

        } catch (Exception $e) {
            Log::error('Payment processing job failed', [
                'file_id' => $this->massPaymentFile->id,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleProcessingException($e);
            
            throw $e;
            
        } finally {
            // Release processing lock
            Cache::forget($lockKey);
        }
    }

    /**
     * Handle job failure
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Payment processing job failed permanently', [
            'file_id' => $this->massPaymentFile->id,
            'batch_id' => $this->batchId,
            'instruction_ids' => $this->instructionIds,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'processing_type' => $this->processingType,
        ]);

        try {
            // Mark instructions as failed
            $this->markInstructionsAsFailed($exception);

            // Update file status if necessary
            $this->updateFileStatusOnFailure($exception);

            // Send failure notifications
            $this->sendFailureNotifications($exception);

        } catch (Exception $e) {
            Log::error('Failed to handle payment processing job failure', [
                'file_id' => $this->massPaymentFile->id,
                'batch_id' => $this->batchId,
                'original_error' => $exception->getMessage(),
                'handling_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if file can be processed
     *
     * @return bool
     */
    protected function canBeProcessed(): bool
    {
        return in_array($this->massPaymentFile->status, [
            MassPaymentFile::STATUS_APPROVED,
            MassPaymentFile::STATUS_PROCESSING,
        ]);
    }

    /**
     * Get payment instructions for processing
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getPaymentInstructions(): \Illuminate\Database\Eloquent\Collection
    {
        return PaymentInstruction::whereIn('id', $this->instructionIds)
            ->where('mass_payment_file_id', $this->massPaymentFile->id)
            ->with(['beneficiary', 'massPaymentFile.tccAccount'])
            ->get();
    }

    /**
     * Process individual payment instruction
     *
     * @param PaymentProcessingService $processor
     * @param PaymentInstruction $instruction
     * @param array $batchStats
     * @return void
     */
    protected function processIndividualPayment(
        PaymentProcessingService $processor,
        PaymentInstruction $instruction,
        array &$batchStats
    ): void {
        $instructionStartTime = microtime(true);

        Log::debug('Processing individual payment instruction', [
            'instruction_id' => $instruction->id,
            'file_id' => $this->massPaymentFile->id,
            'batch_id' => $this->batchId,
            'amount' => $instruction->amount,
            'currency' => $instruction->currency,
            'current_status' => $instruction->status,
        ]);

        try {
            // Check if instruction is still processable
            if (!$instruction->canBeProcessed()) {
                Log::warning('Payment instruction cannot be processed', [
                    'instruction_id' => $instruction->id,
                    'current_status' => $instruction->status,
                    'batch_id' => $this->batchId,
                ]);
                
                $batchStats['skipped_payments']++;
                return;
            }

            // Process the payment
            $result = $processor->processSinglePayment($instruction);

            // Update batch statistics based on result
            if ($result['success']) {
                $batchStats['successful_payments']++;
                $batchStats['total_amount_processed'] += $instruction->amount;
                
                Log::debug('Payment instruction processed successfully', [
                    'instruction_id' => $instruction->id,
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'processing_time_ms' => round((microtime(true) - $instructionStartTime) * 1000, 2),
                ]);
            } else {
                $batchStats['failed_payments']++;
                $batchStats['processing_errors'][] = [
                    'instruction_id' => $instruction->id,
                    'row_number' => $instruction->row_number,
                    'error' => $result['error'] ?? 'Unknown error',
                    'error_code' => $result['error_code'] ?? null,
                    'retryable' => $result['retryable'] ?? false,
                ];
                
                Log::warning('Payment instruction processing failed', [
                    'instruction_id' => $instruction->id,
                    'error' => $result['error'] ?? 'Unknown error',
                    'error_code' => $result['error_code'] ?? null,
                    'retryable' => $result['retryable'] ?? false,
                ]);
            }

        } catch (Exception $e) {
            $batchStats['failed_payments']++;
            $batchStats['processing_errors'][] = [
                'instruction_id' => $instruction->id,
                'row_number' => $instruction->row_number,
                'error' => 'Processing exception: ' . $e->getMessage(),
                'exception' => true,
            ];

            Log::error('Exception during payment instruction processing', [
                'instruction_id' => $instruction->id,
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark instruction as failed due to exception
            try {
                $processor->updatePaymentStatus($instruction, PaymentInstruction::STATUS_FAILED, [
                    'error' => 'Processing exception: ' . $e->getMessage(),
                    'exception_type' => get_class($e),
                    'processed_in_job' => $this->batchId,
                ]);
            } catch (Exception $statusUpdateError) {
                Log::error('Failed to update payment status after processing exception', [
                    'instruction_id' => $instruction->id,
                    'original_error' => $e->getMessage(),
                    'status_update_error' => $statusUpdateError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Complete batch processing
     *
     * @param array $batchStats
     * @param float $startTime
     * @return void
     */
    protected function completeBatchProcessing(array $batchStats, float $startTime): void
    {
        $processingTime = microtime(true) - $startTime;
        $batchStats['end_time'] = now()->toISOString();
        $batchStats['processing_time_seconds'] = round($processingTime, 2);

        Log::info('Payment instruction batch processing completed', [
            'file_id' => $this->massPaymentFile->id,
            'batch_id' => $this->batchId,
            'statistics' => $batchStats,
            'processing_type' => $this->processingType,
        ]);

        // Store batch processing statistics
        $this->storeBatchStatistics($batchStats);

        // Send batch completion notification if enabled
        if (config('mass-payments.processing.enable_notifications', true)) {
            $this->sendBatchCompletionNotification($batchStats);
        }
    }

    /**
     * Check if file processing is complete
     *
     * @return void
     */
    protected function checkFileProcessingCompletion(): void
    {
        // Refresh file model
        $this->massPaymentFile->refresh();

        // Count remaining processable instructions
        $remainingInstructions = $this->massPaymentFile->paymentInstructions()
            ->whereIn('status', [
                PaymentInstruction::STATUS_VALIDATED,
                PaymentInstruction::STATUS_PROCESSING,
            ])
            ->count();

        Log::debug('Checking file processing completion', [
            'file_id' => $this->massPaymentFile->id,
            'remaining_instructions' => $remainingInstructions,
            'current_file_status' => $this->massPaymentFile->status,
        ]);

        if ($remainingInstructions === 0 && $this->massPaymentFile->isProcessing()) {
            // All instructions are processed, mark file as completed
            DB::transaction(function () {
                $this->massPaymentFile->refresh();
                
                if ($this->massPaymentFile->isProcessing()) {
                    $this->massPaymentFile->markAsCompleted();
                    
                    Log::info('Mass payment file processing completed', [
                        'file_id' => $this->massPaymentFile->id,
                        'total_instructions' => $this->massPaymentFile->payment_instructions_count,
                        'successful_payments' => $this->massPaymentFile->successful_payments_count,
                        'failed_payments' => $this->massPaymentFile->failed_payments_count,
                    ]);

                    // Send completion notification
                    if (config('mass-payments.processing.enable_notifications', true