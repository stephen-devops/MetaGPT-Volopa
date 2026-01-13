{
  "Development Plan": [
    "app/Services/PaymentProcessingService.php"
  ],
  "Incremental Change": [
    "app/Services/PaymentProcessingService.php: Service for processing approved payments, creating payment instructions, handling payment execution, managing batch processing, and integrating with external payment systems"
  ]
}

## Code: app/Services/PaymentProcessingService.php

```php
<?php

namespace App\Services;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Models\Beneficiary;
use App\Models\TccAccount;
use App\Models\User;
use App\Jobs\ProcessPaymentInstructionsJob;
use App\Notifications\PaymentProcessingNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;
use League\Csv\Reader;
use Exception;

class PaymentProcessingService
{
    /**
     * Maximum number of payment instructions to process in a single batch.
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Maximum number of retry attempts for failed payments.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Payment processing timeout in seconds.
     */
    private const PROCESSING_TIMEOUT = 600;

    /**
     * Cache TTL for processing status in seconds.
     */
    private const CACHE_TTL = 300;

    /**
     * External payment system endpoints.
     */
    private const PAYMENT_ENDPOINTS = [
        'domestic' => '/api/v1/payments/domestic',
        'international' => '/api/v1/payments/international',
        'swift' => '/api/v1/payments/swift',
        'sepa' => '/api/v1/payments/sepa'
    ];

    /**
     * Payment method mapping by currency.
     */
    private const PAYMENT_METHODS = [
        'USD' => 'international',
        'EUR' => 'sepa',
        'GBP' => 'international',
        'SGD' => 'domestic',
        'HKD' => 'domestic',
        'AUD' => 'domestic',
        'CAD' => 'domestic',
        'JPY' => 'swift',
        'CNY' => 'swift',
        'THB' => 'domestic',
        'MYR' => 'domestic',
        'IDR' => 'domestic',
        'PHP' => 'domestic',
        'VND' => 'domestic'
    ];

    /**
     * Processing fee rates by currency (percentage).
     */
    private const PROCESSING_FEES = [
        'USD' => 0.0025,
        'EUR' => 0.0030,
        'GBP' => 0.0035,
        'SGD' => 0.0020,
        'HKD' => 0.0020,
        'AUD' => 0.0025,
        'CAD' => 0.0025,
        'JPY' => 0.0030,
        'CNY' => 0.0040,
        'THB' => 0.0030,
        'MYR' => 0.0030,
        'IDR' => 0.0035,
        'PHP' => 0.0035,
        'VND' => 0.0035
    ];

    /**
     * Exchange rate service instance.
     */
    private ExchangeRateService $exchangeRateService;

    /**
     * Mass payment file service instance.
     */
    private MassPaymentFileService $massPaymentFileService;

    /**
     * Create a new payment processing service instance.
     *
     * @param ExchangeRateService $exchangeRateService
     * @param MassPaymentFileService $massPaymentFileService
     */
    public function __construct(
        ExchangeRateService $exchangeRateService,
        MassPaymentFileService $massPaymentFileService
    ) {
        $this->exchangeRateService = $exchangeRateService;
        $this->massPaymentFileService = $massPaymentFileService;
    }

    /**
     * Create payment instructions from a mass payment file.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param array $validatedData
     * @return Collection
     * @throws Exception
     */
    public function createInstructions(MassPaymentFile $massPaymentFile, array $validatedData): Collection
    {
        Log::info('Creating payment instructions from mass payment file', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'total_rows' => count($validatedData)
        ]);

        try {
            return DB::transaction(function () use ($massPaymentFile, $validatedData) {
                $instructions = collect();
                $totalAmount = 0.00;
                $batchNumber = $this->generateBatchNumber();

                foreach ($validatedData as $rowIndex => $rowData) {
                    $instruction = $this->createSingleInstruction(
                        $massPaymentFile,
                        $rowData,
                        $rowIndex + 1,
                        $batchNumber
                    );

                    if ($instruction) {
                        $instructions->push($instruction);
                        $totalAmount += $instruction->amount;
                    }
                }

                // Update mass payment file with total amount
                $massPaymentFile->update([
                    'total_amount' => $totalAmount,
                    'total_rows' => count($validatedData),
                    'valid_rows' => $instructions->count(),
                    'invalid_rows' => count($validatedData) - $instructions->count()
                ]);

                Log::info('Payment instructions created successfully', [
                    'file_id' => $massPaymentFile->id,
                    'instructions_count' => $instructions->count(),
                    'total_amount' => $totalAmount,
                    'batch_number' => $batchNumber
                ]);

                return $instructions;
            });
        } catch (Exception $e) {
            Log::error('Failed to create payment instructions', [
                'file_id' => $massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Process payments for a mass payment file.
     *
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     * @throws Exception
     */
    public function processPayments(MassPaymentFile $massPaymentFile): bool
    {
        Log::info('Starting payment processing for mass payment file', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'status' => $massPaymentFile->status
        ]);

        try {
            // Update file status
            $massPaymentFile->markProcessingPayments();

            // Cache processing status
            $this->cacheProcessingStatus($massPaymentFile->id, 'processing_payments');

            // Get payment instructions
            $instructions = $massPaymentFile->paymentInstructions()
                ->where('status', PaymentInstruction::STATUS_PENDING)
                ->get();

            if ($instructions->isEmpty()) {
                Log::warning('No pending payment instructions found', [
                    'file_id' => $massPaymentFile->id
                ]);

                $massPaymentFile->markAsCompleted();
                return true;
            }

            // Process instructions in batches
            $batches = $instructions->chunk(self::MAX_BATCH_SIZE);
            $totalBatches = $batches->count();
            $processedBatches = 0;
            $successfulPayments = 0;
            $failedPayments = 0;

            foreach ($batches as $batchIndex => $batch) {
                Log::info('Processing payment batch', [
                    'file_id' => $massPaymentFile->id,
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => $totalBatches,
                    'batch_size' => $batch->count()
                ]);

                $batchResult = $this->processBatch($batch, $massPaymentFile);
                $successfulPayments += $batchResult['successful'];
                $failedPayments += $batchResult['failed'];
                $processedBatches++;

                // Update processing progress
                $this->updateProcessingProgress(
                    $massPaymentFile->id,
                    $processedBatches,
                    $totalBatches,
                    $successfulPayments,
                    $failedPayments
                );
            }

            // Update final status
            $this->finalizePaymentProcessing($massPaymentFile, $successfulPayments, $failedPayments);

            Log::info('Payment processing completed', [
                'file_id' => $massPaymentFile->id,
                'successful_payments' => $successfulPayments,
                'failed_payments' => $failedPayments,
                'total_batches' => $totalBatches
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to process payments', [
                'file_id' => $massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark file as failed
            $massPaymentFile->markAsFailed('Payment processing failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Retry failed payment instruction.
     *
     * @param PaymentInstruction $instruction
     * @return bool
     * @throws Exception
     */
    public function retryFailedPayment(PaymentInstruction $instruction): bool
    {
        Log::info('Retrying failed payment instruction', [
            'instruction_id' => $instruction->id,
            'client_id' => $instruction->client_id,
            'attempt' => $this->getRetryAttemptCount($instruction) + 1
        ]);

        try {
            // Check if instruction can be retried
            if (!$this->canRetryPayment($instruction)) {
                Log::warning('Payment instruction cannot be retried', [
                    'instruction_id' => $instruction->id,
                    'status' => $instruction->status,
                    'retry_attempts' => $this->getRetryAttemptCount($instruction)
                ]);

                return false;
            }

            // Reset instruction status
            $instruction->update([
                'status' => PaymentInstruction::STATUS_PENDING,
                'failure_reason' => null,
                'bank_response' => null
            ]);

            // Process single payment
            $result = $this->processSinglePayment($instruction);

            Log::info('Payment retry completed', [
                'instruction_id' => $instruction->id,
                'result' => $result ? 'success' : 'failed'
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to retry payment', [
                'instruction_id' => $instruction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Get payment processing status.
     *
     * @param string $fileId
     * @return array|null
     */
    public function getProcessingStatus(string $fileId): ?array
    {
        $cacheKey = "payment_processing_status:{$fileId}";
        
        return Cache::get($cacheKey, function () use ($fileId) {
            $file = MassPaymentFile::with(['paymentInstructions'])->find($fileId);
            
            if (!$file) {
                return null;
            }

            $instructions = $file->paymentInstructions;
            
            return [
                'file_status' => $file->status,
                'total_instructions' => $instructions->count(),
                'pending' => $instructions->where('status', PaymentInstruction::STATUS_PENDING)->count(),
                'processing' => $instructions->where('status', PaymentInstruction::STATUS_PROCESSING)->count(),
                'completed' => $instructions->where('status', PaymentInstruction::STATUS_COMPLETED)->count(),
                'failed' => $instructions->where('status', PaymentInstruction::STATUS_FAILED)->count(),
                'cancelled' => $instructions->where('status', PaymentInstruction::STATUS_CANCELLED)->count(),
                'rejected' => $instructions->where('status', PaymentInstruction::STATUS_REJECTED)->count(),
                'total_amount' => $file->total_amount,
                'processed_amount' => $instructions->whereIn('status', [
                    PaymentInstruction::STATUS_COMPLETED,
                    PaymentInstruction::STATUS_PROCESSING
                ])->sum('amount'),
                'completion_percentage' => $this->calculateCompletionPercentage($instructions),
                'estimated_completion' => $this->estimateCompletionTime($instructions),
                'last_updated' => $file->updated_at->toISOString()
            ];
        });
    }

    /**
     * Cancel pending payment instructions.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param string $reason
     * @return int Number of cancelled instructions
     * @throws Exception
     */
    public function cancelPendingPayments(MassPaymentFile $massPaymentFile, string $reason = ''): int
    {
        Log::info('Cancelling pending payments', [
            'file_id' => $massPaymentFile->id,
            'reason' => $reason
        ]);

        try {
            return DB::transaction(function () use ($massPaymentFile, $reason) {
                $pendingInstructions = $massPaymentFile->paymentInstructions()
                    ->whereIn('status', [
                        PaymentInstruction::STATUS_PENDING,
                        PaymentInstruction::STATUS_PROCESSING
                    ])
                    ->get();

                $cancelledCount = 0;

                foreach ($pendingInstructions as $instruction) {
                    // Check if payment is already being processed by external system
                    if ($instruction->status === PaymentInstruction::STATUS_PROCESSING && 
                        !empty($instruction->transaction_id)) {
                        // Attempt to cancel with external system
                        $this->cancelExternalPayment($instruction);
                    }

                    $instruction->update([
                        'status' => PaymentInstruction::STATUS_CANCELLED,
                        'failure_reason' => $reason ?: 'Cancelled by user request',
                        'processed_at' => Carbon::now()
                    ]);

                    $cancelledCount++;
                }

                // Update mass payment file status
                if ($cancelledCount > 0) {
                    $massPaymentFile->markAsCancelled($reason);
                }

                Log::info('Payments cancelled successfully', [
                    'file_id' => $massPaymentFile->id,
                    'cancelled_count' => $cancelledCount
                ]);

                return $cancelledCount;
            });
        } catch (Exception $e) {
            Log::error('Failed to cancel payments', [
                'file_id' => $massPaymentFile->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Create a single payment instruction.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param array $rowData
     * @param int $rowNumber
     * @param string $batchNumber
     * @return PaymentInstruction|null
     * @throws Exception
     */
    private function createSingleInstruction(
        MassPaymentFile $massPaymentFile,
        array $rowData,
        int $rowNumber,
        string $batchNumber
    ): ?PaymentInstruction {
        try {