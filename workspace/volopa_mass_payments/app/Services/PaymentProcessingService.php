## Code: app/Services/PaymentProcessingService.php

```php
<?php

namespace App\Services;

use App\Models\PaymentFile;
use App\Models\PaymentInstruction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Carbon\Carbon;

class PaymentProcessingService
{
    /**
     * Default batch size for processing payment instructions.
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * Maximum retry attempts for failed payments.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Processing timeout in seconds.
     */
    private const PROCESSING_TIMEOUT = 30;

    /**
     * Supported settlement methods and their processing times.
     */
    private const SETTLEMENT_PROCESSING_TIMES = [
        PaymentInstruction::SETTLEMENT_SEPA => [
            'min_hours' => 1,
            'max_hours' => 24,
            'instant' => false
        ],
        PaymentInstruction::SETTLEMENT_FASTER_PAYMENTS => [
            'min_hours' => 0,
            'max_hours' => 1,
            'instant' => true
        ],
        PaymentInstruction::SETTLEMENT_ACH => [
            'min_hours' => 24,
            'max_hours' => 72,
            'instant' => false
        ],
        PaymentInstruction::SETTLEMENT_WIRE => [
            'min_hours' => 4,
            'max_hours' => 24,
            'instant' => false
        ],
        PaymentInstruction::SETTLEMENT_SWIFT => [
            'min_hours' => 24,
            'max_hours' => 120,
            'instant' => false
        ]
    ];

    /**
     * Process payments for an approved payment file.
     *
     * @param PaymentFile $file PaymentFile to process
     * @return array Processing results
     * @throws Exception If processing fails
     */
    public function processPayments(PaymentFile $file): array
    {
        Log::info('Starting payment processing', [
            'payment_file_id' => $file->id,
            'total_instructions' => $file->valid_records,
            'total_amount' => $file->total_amount,
            'currency' => $file->currency
        ]);

        try {
            // Validate file is ready for processing
            $this->validateFileForProcessing($file);

            // Update file status to processing payments
            $file->updateStatus(PaymentFile::STATUS_PROCESSING_PAYMENTS);

            $results = DB::transaction(function () use ($file) {
                return $this->processPaymentInstructions($file);
            });

            // Update final file status based on results
            $this->updateFinalFileStatus($file, $results);

            Log::info('Payment processing completed', [
                'payment_file_id' => $file->id,
                'processed_count' => $results['processed_count'],
                'successful_count' => $results['successful_count'],
                'failed_count' => $results['failed_count'],
                'total_processed_amount' => $results['total_processed_amount']
            ]);

            return $results;

        } catch (Exception $e) {
            Log::error('Payment processing failed', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update file status to failed
            $file->updateStatus(PaymentFile::STATUS_FAILED);
            throw $e;
        }
    }

    /**
     * Process payment instructions in chunks.
     *
     * @param PaymentFile $file PaymentFile to process
     * @return array Processing results
     */
    public function processPaymentInstructions(PaymentFile $file): array
    {
        $totalProcessed = 0;
        $totalSuccessful = 0;
        $totalFailed = 0;
        $totalProcessedAmount = 0.0;
        $processingErrors = [];

        Log::info('Processing payment instructions in batches', [
            'payment_file_id' => $file->id,
            'batch_size' => self::DEFAULT_BATCH_SIZE
        ]);

        // Process instructions in chunks to avoid memory issues
        PaymentInstruction::forPaymentFile($file->id)
            ->pending()
            ->chunk(self::DEFAULT_BATCH_SIZE, function ($instructions) use (
                &$totalProcessed,
                &$totalSuccessful,
                &$totalFailed,
                &$totalProcessedAmount,
                &$processingErrors,
                $file
            ) {
                $batchResults = $this->processPaymentBatch($instructions);
                
                $totalProcessed += $batchResults['processed_count'];
                $totalSuccessful += $batchResults['successful_count'];
                $totalFailed += $batchResults['failed_count'];
                $totalProcessedAmount += $batchResults['total_amount'];
                
                if (!empty($batchResults['errors'])) {
                    $processingErrors = array_merge($processingErrors, $batchResults['errors']);
                }

                Log::info('Batch processing completed', [
                    'payment_file_id' => $file->id,
                    'batch_processed' => $batchResults['processed_count'],
                    'batch_successful' => $batchResults['successful_count'],
                    'batch_failed' => $batchResults['failed_count'],
                    'total_processed_so_far' => $totalProcessed
                ]);
            });

        return [
            'processed_count' => $totalProcessed,
            'successful_count' => $totalSuccessful,
            'failed_count' => $totalFailed,
            'total_processed_amount' => $totalProcessedAmount,
            'success_rate' => $totalProcessed > 0 ? ($totalSuccessful / $totalProcessed) * 100 : 0,
            'errors' => $processingErrors,
            'processing_completed_at' => Carbon::now(),
        ];
    }

    /**
     * Process a batch of payment instructions.
     *
     * @param \Illuminate\Database\Eloquent\Collection $instructions Batch of payment instructions
     * @return array Batch processing results
     */
    public function processPaymentBatch($instructions): array
    {
        $processedCount = 0;
        $successfulCount = 0;
        $failedCount = 0;
        $totalAmount = 0.0;
        $errors = [];

        Log::info('Processing payment batch', [
            'instruction_count' => $instructions->count()
        ]);

        foreach ($instructions as $instruction) {
            try {
                $result = $this->processSinglePayment($instruction);
                
                $processedCount++;
                $totalAmount += $instruction->amount;
                
                if ($result['success']) {
                    $successfulCount++;
                    $instruction->markAsCompleted();
                } else {
                    $failedCount++;
                    $instruction->markAsFailed();
                    
                    if (isset($result['error'])) {
                        $errors[] = [
                            'instruction_id' => $instruction->id,
                            'row_number' => $instruction->row_number,
                            'error' => $result['error'],
                            'amount' => $instruction->amount,
                            'beneficiary_name' => $instruction->beneficiary_name
                        ];
                    }
                }

            } catch (Exception $e) {
                $processedCount++;
                $failedCount++;
                $totalAmount += $instruction->amount;
                
                Log::error('Error processing single payment', [
                    'instruction_id' => $instruction->id,
                    'error' => $e->getMessage()
                ]);

                $instruction->markAsFailed();
                
                $errors[] = [
                    'instruction_id' => $instruction->id,
                    'row_number' => $instruction->row_number,
                    'error' => 'Processing exception: ' . $e->getMessage(),
                    'amount' => $instruction->amount,
                    'beneficiary_name' => $instruction->beneficiary_name
                ];
            }
        }

        return [
            'processed_count' => $processedCount,
            'successful_count' => $successfulCount,
            'failed_count' => $failedCount,
            'total_amount' => $totalAmount,
            'errors' => $errors
        ];
    }

    /**
     * Process a single payment instruction.
     *
     * @param PaymentInstruction $instruction Payment instruction to process
     * @return array Processing result
     */
    public function processSinglePayment(PaymentInstruction $instruction): array
    {
        Log::info('Processing single payment', [
            'instruction_id' => $instruction->id,
            'amount' => $instruction->amount,
            'currency' => $instruction->currency,
            'settlement_method' => $instruction->settlement_method,
            'beneficiary_account' => $instruction->beneficiary_account
        ]);

        try {
            // Mark instruction as processing
            $instruction->markAsProcessing();

            // Validate payment instruction before processing
            $validationResult = $this->validatePaymentInstruction($instruction);
            if (!$validationResult['valid']) {
                return [
                    'success' => false,
                    'error' => $validationResult['error'],
                    'instruction_id' => $instruction->id
                ];
            }

            // Simulate payment processing based on settlement method
            $processingResult = $this->executePaymentProcessing($instruction);

            if ($processingResult['success']) {
                Log::info('Payment processed successfully', [
                    'instruction_id' => $instruction->id,
                    'transaction_id' => $processingResult['transaction_id'] ?? null
                ]);

                return [
                    'success' => true,
                    'transaction_id' => $processingResult['transaction_id'] ?? null,
                    'processing_time' => $processingResult['processing_time'] ?? null,
                    'instruction_id' => $instruction->id
                ];
            } else {
                Log::warning('Payment processing failed', [
                    'instruction_id' => $instruction->id,
                    'error' => $processingResult['error'] ?? 'Unknown error'
                ]);

                return [
                    'success' => false,
                    'error' => $processingResult['error'] ?? 'Payment processing failed',
                    'instruction_id' => $instruction->id
                ];
            }

        } catch (Exception $e) {
            Log::error('Exception during payment processing', [
                'instruction_id' => $instruction->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Processing exception: ' . $e->getMessage(),
                'instruction_id' => $instruction->id
            ];
        }
    }

    /**
     * Get processing statistics for a payment file.
     *
     * @param PaymentFile $file PaymentFile to get statistics for
     * @return array Processing statistics
     */
    public function getProcessingStatistics(PaymentFile $file): array
    {
        $instructions = PaymentInstruction::forPaymentFile($file->id)->get();
        
        $totalInstructions = $instructions->count();
        $completedInstructions = $instructions->where('status', PaymentInstruction::STATUS_COMPLETED)->count();
        $failedInstructions = $instructions->where('status', PaymentInstruction::STATUS_FAILED)->count();
        $processingInstructions = $instructions->where('status', PaymentInstruction::STATUS_PROCESSING)->count();
        $pendingInstructions = $instructions->where('status', PaymentInstruction::STATUS_PENDING)->count();
        
        $completedAmount = $instructions->where('status', PaymentInstruction::STATUS_COMPLETED)->sum('amount');
        $failedAmount = $instructions->where('status', PaymentInstruction::STATUS_FAILED)->sum('amount');

        return [
            'payment_file_id' => $file->id,
            'file_status' => $file->status,
            'total_instructions' => $totalInstructions,
            'completed_instructions' => $completedInstructions,
            'failed_instructions' => $failedInstructions,
            'processing_instructions' => $processingInstructions,
            'pending_instructions' => $pendingInstructions,
            'completion_rate' => $totalInstructions > 0 ? ($completedInstructions / $totalInstructions) * 100 : 0,
            'failure_rate' => $totalInstructions > 0 ? ($failedInstructions / $totalInstructions) * 100 : 0,
            'total_file_amount' => $file->total_amount,
            'completed_amount' => $completedAmount,
            'failed_amount' => $failedAmount,
            'amount_completion_rate' => $file->total_amount > 0 ? ($completedAmount / $file->total_amount) * 100 : 0,
            'currency' => $file->currency,
            'processing_started_at' => $file->updated_at,
            'is_processing_complete' => $pendingInstructions === 0 && $processingInstructions === 0,
        ];
    }

    /**
     * Retry failed payments for a payment file.
     *
     * @param PaymentFile $file PaymentFile to retry payments for
     * @param int $maxRetries Maximum number of retry attempts
     * @return array Retry results
     */
    public function retryFailedPayments(PaymentFile $file, int $maxRetries = self::MAX_RETRY_ATTEMPTS): array
    {
        Log::info('Retrying failed payments', [
            'payment_file_id' => $file->id,
            'max_retries' => $maxRetries
        ]);

        $failedInstructions = PaymentInstruction::forPaymentFile($file->id)
            ->failed()
            ->get();

        if ($failedInstructions->isEmpty()) {
            Log::info('No failed payments to retry', ['payment_file_id' => $file->id]);
            return [
                'retried_count' => 0,
                'successful_retries' => 0,
                'still_failed' => 0
            ];
        }

        $retriedCount = 0;
        $successfulRetries = 0;
        $stillFailed = 0;

        foreach ($failedInstructions as $instruction) {
            try {
                // Reset status to pending for retry
                $instruction->updateStatus(PaymentInstruction::STATUS_PENDING);
                
                $result = $this->processSinglePayment($instruction);
                $retriedCount++;

                if ($result['success']) {
                    $successfulRetries++;
                    $instruction->markAsCompleted();
                    
                    Log::info('Payment retry successful', [
                        'instruction_id' => $instruction->id
                    ]);
                } else {
                    $stillFailed++;
                    $instruction->markAsFailed();
                    
                    Log::warning('Payment retry failed', [
                        'instruction_id' => $instruction->id,
                        'error' => $result['error'] ?? 'Unknown error'
                    ]);
                }

            } catch (Exception $e) {
                $retriedCount++;
                $stillFailed++;
                
                Log::error('Exception during payment retry', [
                    'instruction_id' => $instruction->id,
                    'error' => $e->getMessage()
                ]);
                
                $instruction->markAsFailed();
            }
        }

        Log::info('Payment retry completed', [
            'payment_file_id' => $file->id,
            'retried_count' => $retriedCount,
            'successful_retries' => $successfulRetries,
            'still_failed' => $stillFailed
        ]);

        return [
            'retried_count' => $retriedCount,
            'successful_retries' => $successfulRetries,
            'still_failed' => $stillFailed,
            'retry_success_rate' => $retriedCount > 0 ? ($successfulRetries / $retriedCount) *