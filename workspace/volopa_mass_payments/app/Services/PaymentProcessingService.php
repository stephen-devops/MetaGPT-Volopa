{
  "Development Plan": [
    "app/Services/PaymentProcessingService.php"
  ],
  "Incremental Change": [
    "app/Services/PaymentProcessingService.php: Service class for payment processing business logic with job dispatching, status updates, transaction handling, and external payment API integration"
  ]
}

## Code: app/Services/PaymentProcessingService.php
```php
<?php

namespace App\Services;

use App\Jobs\ProcessPaymentInstructionsJob;
use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Models\TccAccount;
use App\Notifications\PaymentFileApprovalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

class PaymentProcessingService
{
    /**
     * Maximum concurrent processing jobs
     */
    protected int $maxConcurrentJobs;

    /**
     * Batch size for processing payment instructions
     */
    protected int $batchSize;

    /**
     * Maximum retry attempts for failed payments
     */
    protected int $retryAttempts;

    /**
     * Retry delay in seconds
     */
    protected int $retryDelaySeconds;

    /**
     * Processing timeout in minutes
     */
    protected int $timeoutMinutes;

    /**
     * Enable notifications flag
     */
    protected bool $enableNotifications;

    /**
     * Notification channels
     */
    protected array $notificationChannels;

    /**
     * Processing queue name
     */
    protected string $processingQueue;

    /**
     * Processing timeout in seconds for jobs
     */
    protected int $processingTimeout;

    /**
     * Maximum processing attempts per job
     */
    protected int $maxProcessingAttempts;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->maxConcurrentJobs = config('mass-payments.processing.max_concurrent_jobs', 5);
        $this->batchSize = config('mass-payments.processing.batch_size', 100);
        $this->retryAttempts = config('mass-payments.processing.retry_attempts', 3);
        $this->retryDelaySeconds = config('mass-payments.processing.retry_delay_seconds', 300);
        $this->timeoutMinutes = config('mass-payments.processing.timeout_minutes', 30);
        $this->enableNotifications = config('mass-payments.processing.enable_notifications', true);
        $this->notificationChannels = config('mass-payments.processing.notification_channels', ['mail', 'database']);
        $this->processingQueue = config('mass-payments.queue.processing_queue', 'payments');
        $this->processingTimeout = config('mass-payments.queue.processing_timeout', 1800);
        $this->maxProcessingAttempts = config('mass-payments.queue.max_processing_attempts', 5);
    }

    /**
     * Process all payment instructions for a mass payment file
     *
     * @param MassPaymentFile $file
     * @return void
     * @throws Exception
     */
    public function processPaymentInstructions(MassPaymentFile $file): void
    {
        if (!$file) {
            throw new InvalidArgumentException('Mass payment file is required');
        }

        // Validate file is in processable state
        if (!$file->isApproved() && !$file->isProcessing()) {
            throw new Exception('Mass payment file is not in a processable state');
        }

        Log::info('Starting payment instruction processing', [
            'file_id' => $file->id,
            'client_id' => $file->client_id,
            'total_amount' => $file->total_amount,
            'currency' => $file->currency,
        ]);

        DB::beginTransaction();

        try {
            // Mark file as processing
            if (!$file->isProcessing()) {
                $file->markAsProcessing();
            }

            // Check if we have reached concurrent job limits
            $this->enforceJobLimits();

            // Get payment instructions that need processing
            $instructions = $this->getProcessableInstructions($file);

            if ($instructions->isEmpty()) {
                Log::warning('No processable payment instructions found', [
                    'file_id' => $file->id,
                ]);
                
                $this->completeFileProcessing($file);
                DB::commit();
                return;
            }

            // Split instructions into batches and dispatch jobs
            $batches = $instructions->chunk($this->batchSize);
            $jobCount = 0;

            foreach ($batches as $batchIndex => $batch) {
                $this->dispatchBatchProcessingJob($file, $batch->pluck('id')->toArray(), $batchIndex + 1);
                $jobCount++;
            }

            Log::info('Payment processing jobs dispatched', [
                'file_id' => $file->id,
                'job_count' => $jobCount,
                'total_instructions' => $instructions->count(),
            ]);

            // Update file processing metadata
            $this->updateFileProcessingMetadata($file, $jobCount, $instructions->count());

            DB::commit();

            // Send processing started notification
            if ($this->enableNotifications) {
                $this->sendProcessingStartedNotification($file);
            }

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to start payment instruction processing', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark file as failed
            $file->markAsFailed(['processing_error' => $e->getMessage()]);

            // Send failure notification
            if ($this->enableNotifications) {
                $this->sendProcessingFailedNotification($file, $e->getMessage());
            }

            throw new Exception('Failed to process payment instructions: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update payment instruction status
     *
     * @param PaymentInstruction $instruction
     * @param string $status
     * @param array $metadata
     * @return void
     * @throws Exception
     */
    public function updatePaymentStatus(PaymentInstruction $instruction, string $status, array $metadata = []): void
    {
        if (!$instruction) {
            throw new InvalidArgumentException('Payment instruction is required');
        }

        if (!in_array($status, PaymentInstruction::getStatuses())) {
            throw new InvalidArgumentException('Invalid payment status provided');
        }

        Log::debug('Updating payment instruction status', [
            'instruction_id' => $instruction->id,
            'old_status' => $instruction->status,
            'new_status' => $status,
            'metadata' => $metadata,
        ]);

        DB::beginTransaction();

        try {
            // Update instruction status based on the new status
            match ($status) {
                PaymentInstruction::STATUS_PROCESSING => $instruction->markAsProcessing(),
                PaymentInstruction::STATUS_COMPLETED => $this->handleCompletedPayment($instruction, $metadata),
                PaymentInstruction::STATUS_FAILED => $this->handleFailedPayment($instruction, $metadata),
                PaymentInstruction::STATUS_CANCELLED => $instruction->markAsCancelled(),
                default => throw new Exception("Unsupported status transition to: {$status}"),
            };

            // Update file progress and check if processing is complete
            $this->checkFileProcessingCompletion($instruction->massPaymentFile);

            DB::commit();

            Log::info('Payment instruction status updated successfully', [
                'instruction_id' => $instruction->id,
                'new_status' => $status,
                'file_id' => $instruction->mass_payment_file_id,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to update payment instruction status', [
                'instruction_id' => $instruction->id,
                'status' => $status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to update payment status: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Process a single payment instruction
     *
     * @param PaymentInstruction $instruction
     * @return array
     * @throws Exception
     */
    public function processSinglePayment(PaymentInstruction $instruction): array
    {
        if (!$instruction) {
            throw new InvalidArgumentException('Payment instruction is required');
        }

        if (!$instruction->canBeProcessed()) {
            throw new Exception('Payment instruction cannot be processed in its current state');
        }

        Log::debug('Processing single payment instruction', [
            'instruction_id' => $instruction->id,
            'amount' => $instruction->amount,
            'currency' => $instruction->currency,
            'beneficiary_id' => $instruction->beneficiary_id,
        ]);

        try {
            // Mark as processing
            $this->updatePaymentStatus($instruction, PaymentInstruction::STATUS_PROCESSING);

            // Validate payment instruction before processing
            $validationResult = $this->validatePaymentForProcessing($instruction);
            if (!$validationResult['valid']) {
                $this->updatePaymentStatus($instruction, PaymentInstruction::STATUS_FAILED, [
                    'error' => 'Validation failed',
                    'validation_errors' => $validationResult['errors'],
                ]);

                return [
                    'success' => false,
                    'error' => 'Payment validation failed',
                    'details' => $validationResult['errors'],
                ];
            }

            // Check TCC account balance
            $balanceCheck = $this->checkTccAccountBalance($instruction);
            if (!$balanceCheck['sufficient']) {
                $this->updatePaymentStatus($instruction, PaymentInstruction::STATUS_FAILED, [
                    'error' => 'Insufficient funds',
                    'available_balance' => $balanceCheck['available_balance'],
                    'required_amount' => $instruction->amount,
                ]);

                return [
                    'success' => false,
                    'error' => 'Insufficient funds in TCC account',
                    'details' => $balanceCheck,
                ];
            }

            // Process payment through external API
            $paymentResult = $this->executePaymentViaApi($instruction);

            if ($paymentResult['success']) {
                $this->updatePaymentStatus($instruction, PaymentInstruction::STATUS_COMPLETED, [
                    'transaction_id' => $paymentResult['transaction_id'],
                    'processed_at' => now()->toISOString(),
                    'processing_time_ms' => $paymentResult['processing_time_ms'] ?? null,
                ]);

                // Deduct funds from TCC account
                $this->deductFundsFromTccAccount($instruction);

                return [
                    'success' => true,
                    'transaction_id' => $paymentResult['transaction_id'],
                    'processed_at' => now()->toISOString(),
                ];
            } else {
                $this->updatePaymentStatus($instruction, PaymentInstruction::STATUS_FAILED, [
                    'error' => $paymentResult['error'],
                    'error_code' => $paymentResult['error_code'] ?? null,
                    'retry_count' => ($instruction->retry_count ?? 0) + 1,
                ]);

                return [
                    'success' => false,
                    'error' => $paymentResult['error'],
                    'error_code' => $paymentResult['error_code'] ?? null,
                    'retryable' => $paymentResult['retryable'] ?? false,
                ];
            }

        } catch (Exception $e) {
            Log::error('Single payment processing failed', [
                'instruction_id' => $instruction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $this->updatePaymentStatus($instruction, PaymentInstruction::STATUS_FAILED, [
                    'error' => 'Processing exception: ' . $e->getMessage(),
                    'exception_type' => get_class($e),
                ]);
            } catch (Exception $statusUpdateError) {
                Log::error('Failed to update payment status after exception', [
                    'instruction_id' => $instruction->id,
                    'original_error' => $e->getMessage(),
                    'status_update_error' => $statusUpdateError->getMessage(),
                ]);
            }

            return [
                'success' => false,
                'error' => 'Processing exception: ' . $e->getMessage(),
                'exception' => true,
            ];
        }
    }

    /**
     * Retry failed payment instructions
     *
     * @param MassPaymentFile $file
     * @param array $options
     * @return array
     */
    public function retryFailedPayments(MassPaymentFile $file, array $options = []): array
    {
        if (!$file) {
            throw new InvalidArgumentException('Mass payment file is required');
        }

        Log::info('Starting retry of failed payments', [
            'file_id' => $file->id,
            'options' => $options,
        ]);

        // Get failed instructions that can be retried
        $failedInstructions = $file->paymentInstructions()
            ->where('status', PaymentInstruction::STATUS_FAILED)
            ->where(function ($query) {
                $query->whereNull('retry_count')
                      ->orWhere('retry_count', '<', $this->retryAttempts);
            })
            ->get();

        if ($failedInstructions->isEmpty()) {
            return [
                'success' => true,
                'message' => 'No failed payments to retry',
                'retried_count' => 0,
            ];
        }

        $retriedCount = 0;
        $errors = [];

        foreach ($failedInstructions as $instruction) {
            try {
                // Reset instruction to validated status for retry
                $instruction->update([
                    'status' => PaymentInstruction::STATUS_VALIDATED,
                    'validation_errors' => null,
                ]);

                // Dispatch individual processing job with delay
                $delay = $options['delay_seconds'] ?? $this->retryDelaySeconds;
                ProcessPaymentInstructionsJob::dispatch($file, [$instruction->id], 'retry')
                    ->delay(now()->addSeconds($delay))
                    ->onQueue($this->processingQueue);

                $retriedCount++;

            } catch (Exception $e) {
                Log::error('Failed to retry payment instruction', [
                    'instruction_id' => $instruction->id,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = [
                    'instruction_id' => $instruction->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        Log::info('Failed payment retry completed', [
            'file_id' => $file->id,
            'total_failed' => $failedInstructions->count(),
            'retried_count' => $retriedCount,
            'errors_count' => count($errors),
        ]);

        return [
            'success' => true,
            'retried_count' => $retriedCount,
            'total_failed' => $failedInstructions->count(),
            'errors' => $errors,
        ];
    }

    /**
     * Cancel processing of a mass payment file
     *
     * @param MassPaymentFile $file
     * @param array $options
     * @return array
     */
    public function cancelProcessing(MassPaymentFile $file, array $options = []): array
    {
        if (!$file) {
            throw new InvalidArgumentException('Mass payment file is required');
        }

        Log::info('Cancelling payment file processing', [
            'file_id' => $file->id,
            'current_status' => $file->status,
            'options' => $options,