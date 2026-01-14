## Code: app/Services/PaymentProcessingService.php

```php
<?php

namespace App\Services;

use App\Models\PaymentInstruction;
use App\Models\MassPaymentFile;
use App\Models\Beneficiary;
use App\Models\TccAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\RequestException;
use Carbon\Carbon;
use Exception;

class PaymentProcessingService
{
    /**
     * Maximum batch size for payment processing.
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Default timeout for external API calls in seconds.
     */
    private const DEFAULT_TIMEOUT = 30;

    /**
     * Maximum retry attempts for failed payments.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * High-value transaction threshold in USD equivalent.
     */
    private const HIGH_VALUE_THRESHOLD = 10000.00;

    /**
     * Cache TTL for exchange rates in minutes.
     */
    private const EXCHANGE_RATE_CACHE_TTL = 15;

    /**
     * Supported payment corridors and their configurations.
     */
    private const PAYMENT_CORRIDORS = [
        'USD' => [
            'provider' => 'volopa_core',
            'max_amount' => 1000000.00,
            'processing_time' => 'instant',
            'requires_swift' => true,
        ],
        'EUR' => [
            'provider' => 'volopa_core',
            'max_amount' => 1000000.00,
            'processing_time' => 'instant',
            'requires_swift' => true,
        ],
        'GBP' => [
            'provider' => 'volopa_core',
            'max_amount' => 1000000.00,
            'processing_time' => 'instant',
            'requires_swift' => false,
        ],
        'INR' => [
            'provider' => 'volopa_india',
            'max_amount' => 100000.00,
            'processing_time' => 'next_day',
            'requires_swift' => true,
            'requires_invoice' => true,
        ],
        'TRY' => [
            'provider' => 'volopa_turkey',
            'max_amount' => 50000.00,
            'processing_time' => 'same_day',
            'requires_swift' => true,
            'requires_incorporation' => true,
        ],
    ];

    /**
     * Process all validated payment instructions for a mass payment file.
     */
    public function processPaymentInstructions(MassPaymentFile $massPaymentFile): bool
    {
        $startTime = microtime(true);
        
        try {
            Log::info('Starting payment instruction processing', [
                'file_id' => $massPaymentFile->id,
                'currency' => $massPaymentFile->currency,
                'total_amount' => $massPaymentFile->total_amount,
            ]);

            // Update file status to processing
            $massPaymentFile->updateStatus(MassPaymentFile::STATUS_PROCESSING);

            // Get validated payment instructions
            $instructions = $this->getValidatedInstructions($massPaymentFile);
            
            if ($instructions->isEmpty()) {
                throw new Exception('No validated payment instructions found');
            }

            // Process instructions in batches
            $this->processBatchedInstructions($instructions, $massPaymentFile);

            // Update file completion status
            $this->updateFileCompletionStatus($massPaymentFile);

            $processingTime = round(microtime(true) - $startTime, 2);

            Log::info('Payment instruction processing completed', [
                'file_id' => $massPaymentFile->id,
                'total_instructions' => $instructions->count(),
                'processing_time' => $processingTime,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Payment instruction processing failed', [
                'file_id' => $massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update file status to failed
            $massPaymentFile->updateStatus(MassPaymentFile::STATUS_FAILED);

            return false;
        }
    }

    /**
     * Process a single payment instruction.
     */
    public function processPaymentInstruction(PaymentInstruction $instruction): bool
    {
        DB::beginTransaction();

        try {
            Log::debug('Processing payment instruction', [
                'instruction_id' => $instruction->id,
                'amount' => $instruction->amount,
                'currency' => $instruction->currency,
                'beneficiary_name' => $instruction->beneficiary_name,
            ]);

            // Validate instruction can be processed
            $this->validateInstructionForProcessing($instruction);

            // Get or create beneficiary
            $beneficiary = $this->getOrCreateBeneficiary($instruction);

            // Get payment corridor configuration
            $corridorConfig = $this->getPaymentCorridorConfig($instruction->currency);

            // Perform pre-processing checks
            $this->performPreProcessingChecks($instruction, $corridorConfig);

            // Calculate fees and exchange rates
            $processingData = $this->calculateProcessingData($instruction, $corridorConfig);

            // Execute payment through provider
            $paymentResult = $this->executePayment($instruction, $beneficiary, $processingData, $corridorConfig);

            // Update instruction with processing results
            $this->updateInstructionWithResults($instruction, $paymentResult, $processingData);

            DB::commit();

            Log::info('Payment instruction processed successfully', [
                'instruction_id' => $instruction->id,
                'external_transaction_id' => $paymentResult['transaction_id'],
                'status' => $instruction->status,
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Payment instruction processing failed', [
                'instruction_id' => $instruction->id,
                'error' => $e->getMessage(),
            ]);

            // Update instruction status to failed
            $instruction->updateStatus(PaymentInstruction::STATUS_FAILED, [
                'processing_error' => $e->getMessage(),
                'failed_at' => now()->toISOString(),
            ]);

            return false;
        }
    }

    /**
     * Retry failed payment instruction.
     */
    public function retryPaymentInstruction(PaymentInstruction $instruction): bool
    {
        try {
            // Check if instruction can be retried
            if (!$this->canRetryInstruction($instruction)) {
                throw new Exception('Payment instruction cannot be retried');
            }

            // Reset instruction status
            $instruction->updateStatus(PaymentInstruction::STATUS_PENDING);

            // Process the instruction again
            return $this->processPaymentInstruction($instruction);

        } catch (Exception $e) {
            Log::error('Payment instruction retry failed', [
                'instruction_id' => $instruction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cancel a pending payment instruction.
     */
    public function cancelPaymentInstruction(PaymentInstruction $instruction): bool
    {
        DB::beginTransaction();

        try {
            // Validate cancellation conditions
            if (!$instruction->canBeCancelled()) {
                throw new Exception('Payment instruction cannot be cancelled in current status: ' . $instruction->status);
            }

            // If payment is already processing, attempt to cancel with provider
            if ($instruction->isProcessing() && $instruction->external_transaction_id) {
                $cancellationResult = $this->cancelPaymentWithProvider($instruction);
                if (!$cancellationResult) {
                    throw new Exception('Failed to cancel payment with provider');
                }
            }

            // Update instruction status
            $instruction->updateStatus(PaymentInstruction::STATUS_CANCELLED, [
                'cancelled_at' => now()->toISOString(),
                'cancelled_by' => auth()->id(),
            ]);

            DB::commit();

            Log::info('Payment instruction cancelled', [
                'instruction_id' => $instruction->id,
                'external_transaction_id' => $instruction->external_transaction_id,
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Payment instruction cancellation failed', [
                'instruction_id' => $instruction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get payment status from external provider.
     */
    public function getPaymentStatus(PaymentInstruction $instruction): ?array
    {
        try {
            if (!$instruction->external_transaction_id) {
                return null;
            }

            $corridorConfig = $this->getPaymentCorridorConfig($instruction->currency);
            
            return $this->queryPaymentStatusFromProvider(
                $instruction->external_transaction_id,
                $corridorConfig
            );

        } catch (Exception $e) {
            Log::error('Failed to get payment status', [
                'instruction_id' => $instruction->id,
                'external_transaction_id' => $instruction->external_transaction_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update payment status from provider webhook.
     */
    public function updatePaymentStatusFromWebhook(string $externalTransactionId, array $statusData): bool
    {
        try {
            // Find instruction by external transaction ID
            $instruction = PaymentInstruction::where('external_transaction_id', $externalTransactionId)->first();
            
            if (!$instruction) {
                Log::warning('Payment instruction not found for webhook', [
                    'external_transaction_id' => $externalTransactionId,
                ]);
                return false;
            }

            // Update instruction status based on webhook data
            $this->updateInstructionFromWebhookData($instruction, $statusData);

            Log::info('Payment status updated from webhook', [
                'instruction_id' => $instruction->id,
                'external_transaction_id' => $externalTransactionId,
                'new_status' => $statusData['status'] ?? 'unknown',
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to update payment status from webhook', [
                'external_transaction_id' => $externalTransactionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get processing statistics for mass payment file.
     */
    public function getProcessingStatistics(MassPaymentFile $massPaymentFile): array
    {
        $instructions = $massPaymentFile->paymentInstructions();

        return [
            'total_count' => $instructions->count(),
            'pending_count' => $instructions->where('status', PaymentInstruction::STATUS_PENDING)->count(),
            'processing_count' => $instructions->where('status', PaymentInstruction::STATUS_PROCESSING)->count(),
            'completed_count' => $instructions->where('status', PaymentInstruction::STATUS_COMPLETED)->count(),
            'failed_count' => $instructions->where('status', PaymentInstruction::STATUS_FAILED)->count(),
            'cancelled_count' => $instructions->where('status', PaymentInstruction::STATUS_CANCELLED)->count(),
            'total_amount_processed' => $instructions->where('status', PaymentInstruction::STATUS_COMPLETED)->sum('amount'),
            'total_fees_charged' => $instructions->whereNotNull('fee_amount')->sum('fee_amount'),
            'average_processing_time' => $this->calculateAverageProcessingTime($massPaymentFile),
            'success_rate' => $this->calculateSuccessRate($massPaymentFile),
        ];
    }

    /**
     * Get validated payment instructions for processing.
     */
    private function getValidatedInstructions(MassPaymentFile $massPaymentFile): Collection
    {
        return $massPaymentFile->paymentInstructions()
            ->where('status', PaymentInstruction::STATUS_VALIDATED)
            ->orderBy('row_number')
            ->get();
    }

    /**
     * Process payment instructions in batches.
     */
    private function processBatchedInstructions(Collection $instructions, MassPaymentFile $massPaymentFile): void
    {
        $batches = $instructions->chunk(self::MAX_BATCH_SIZE);
        $batchNumber = 1;

        foreach ($batches as $batch) {
            Log::info('Processing payment batch', [
                'file_id' => $massPaymentFile->id,
                'batch_number' => $batchNumber,
                'batch_size' => $batch->count(),
            ]);

            $this->processBatch($batch, $batchNumber);
            $batchNumber++;

            // Small delay between batches to avoid overwhelming the provider
            if ($batchNumber <= $batches->count()) {
                usleep(500000); // 0.5 second delay
            }
        }
    }

    /**
     * Process a batch of payment instructions.
     */
    private function processBatch(Collection $batch, int $batchNumber): void
    {
        foreach ($batch as $instruction) {
            try {
                // Mark instruction as pending
                $instruction->updateStatus(PaymentInstruction::STATUS_PENDING);

                // Process the instruction
                $this->processPaymentInstruction($instruction);

            } catch (Exception $e) {
                Log::error('Batch instruction processing failed', [
                    'instruction_id' => $instruction->id,
                    'batch_number' => $batchNumber,
                    'error' => $e->getMessage(),
                ]);

                // Continue processing other instructions in the batch
                continue;
            }
        }
    }

    /**
     * Validate instruction can be processed.
     */
    private function validateInstructionForProcessing(PaymentInstruction $instruction): void
    {
        if (!$instruction->canBeProcessed()) {
            throw new Exception('Payment instruction cannot be processed in current status: ' . $instruction->status);
        }

        if ($instruction->amount < 0.01) {
            throw new Exception('Payment amount must be greater than 0.01');
        }

        $corridorConfig = $this->getPaymentCorridorConfig($instruction->currency);
        
        if ($instruction->amount > $corridorConfig['max_amount']) {
            throw new Exception("Payment amount exceeds maximum allowed for {$instruction->currency}: {$corridorConfig['max_amount']}");
        }

        // Validate currency-specific requirements
        $this->validateCurrencySpecificRequirements($instruction, $corridorConfig);
    }

    /**
     * Validate currency-specific requirements.
     */
    private function validateCurrencySpecificRequirements(PaymentInstruction $instruction, array $corridorConfig): void
    {
        // Check invoice requirements
        if (($corridorConfig['requires_invoice'] ?? false) && empty($instruction->invoice_number)) {
            throw new Exception("Invoice number is required for {$instruction->currency} payments");
        }

        // Check incorporation requirements for business recipients
        if (($corridorConfig['requires_incorporation'] ?? false) && 
            $instruction->beneficiary_type === 'business' && 
            empty($instruction->incorporation_number)) {
            throw new Exception("Incorporation number is required for business recipients in {$instruction->currency}");
        }

        // Check SWIFT requirements
        if (($corridorConfig['requires_swift'] ?? false) && 
            empty($instruction->beneficiary_swift_code) && 
            empty($instruction->beneficiary_iban)) {
            throw new Exception("SWIFT code or IBAN is required for {$instruction->currency} payments");
        }
    }

    /**
     * Get or create beneficiary from payment instruction.
     */
    private function getOrCreateBeneficiary(PaymentInstruction $instruction): Beneficiary
    {
        // Try to find existing beneficiary
        if ($instruction->beneficiary_id) {
            $beneficiary = Beneficiary::