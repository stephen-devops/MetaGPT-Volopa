## Code: app/Http/Resources/PaymentInstructionResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentInstructionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mass_payment_file_id' => $this->mass_payment_file_id,
            'beneficiary_id' => $this->beneficiary_id,
            'amount' => number_format((float)$this->amount, 2, '.', ''),
            'currency' => $this->currency,
            'purpose_code' => $this->purpose_code,
            'reference' => $this->reference,
            'status' => $this->status,
            'validation_errors' => $this->when(
                $this->hasValidationErrors(),
                $this->validation_errors
            ),
            'additional_data' => $this->when(
                !empty($this->additional_data),
                $this->additional_data
            ),
            'row_number' => $this->row_number,
            'processed_at' => $this->when(
                $this->processed_at,
                $this->processed_at?->toISOString()
            ),
            'processing_error' => $this->when(
                $this->hasProcessingErrors(),
                $this->processing_error
            ),
            'transaction_id' => $this->transaction_id,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Computed status fields
            'is_pending' => $this->isPending(),
            'is_validated' => $this->isValidated(),
            'has_failed_validation' => $this->hasFailedValidation(),
            'is_processing' => $this->isProcessing(),
            'is_processed' => $this->isProcessed(),
            'is_completed' => $this->isCompleted(),
            'has_failed' => $this->hasFailed(),
            'is_cancelled' => $this->isCancelled(),
            'has_validation_errors' => $this->hasValidationErrors(),
            'has_processing_errors' => $this->hasProcessingErrors(),
            'can_be_processed' => $this->canBeProcessed(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'is_processable' => $this->isProcessable(),
            
            // Formatted fields for display
            'formatted_amount' => $this->getFormattedAmount(),
            'display_status' => $this->getDisplayStatus(),
            'status_color' => $this->getStatusColor(),
            'status_icon' => $this->getStatusIcon(),
            
            // Beneficiary information (when loaded)
            'beneficiary' => $this->when(
                $this->relationLoaded('beneficiary'),
                [
                    'id' => $this->beneficiary?->id,
                    'name' => $this->beneficiary?->name,
                    'account_number' => $this->beneficiary?->account_number,
                    'sort_code' => $this->beneficiary?->sort_code,
                    'iban' => $this->beneficiary?->iban,
                    'swift_code' => $this->beneficiary?->swift_code,
                    'bank_name' => $this->beneficiary?->bank_name,
                    'currency' => $this->beneficiary?->currency,
                    'display_name' => $this->getBeneficiaryName(),
                    'account_details' => $this->getBeneficiaryAccountNumber(),
                ]
            ),
            
            // Mass payment file information (when loaded)
            'mass_payment_file' => $this->when(
                $this->relationLoaded('massPaymentFile'),
                [
                    'id' => $this->massPaymentFile?->id,
                    'filename' => $this->massPaymentFile?->filename,
                    'status' => $this->massPaymentFile?->status,
                    'currency' => $this->massPaymentFile?->currency,
                    'is_approved' => $this->massPaymentFile?->isApproved() ?? false,
                ]
            ),
            
            // Processing information
            'processing_info' => [
                'processing_duration' => $this->getProcessingDuration(),
                'can_retry' => $this->canRetryProcessing(),
                'retry_count' => $this->getRetryCount(),
                'last_attempt_at' => $this->getLastAttemptTime(),
                'next_retry_at' => $this->getNextRetryTime(),
            ],
            
            // Validation information
            'validation_info' => $this->when(
                $this->hasValidationErrors() || $this->hasFailedValidation(),
                [
                    'validation_errors_count' => $this->getValidationErrorsCount(),
                    'validation_error_summary' => $this->getValidationErrorSummary(),
                    'can_override_validation' => $this->canOverrideValidation(),
                    'requires_manual_review' => $this->requiresManualReview(),
                ]
            ),
            
            // Currency-specific information
            'currency_info' => [
                'currency_code' => $this->currency,
                'currency_symbol' => $this->getCurrencySymbol(),
                'decimal_places' => $this->getCurrencyDecimalPlaces(),
                'formatted_amount_with_symbol' => $this->getFormattedAmountWithSymbol(),
            ],
            
            // Business rules validation
            'business_rules' => [
                'exceeds_limits' => $this->exceedsLimits($this->getCurrencyLimits()),
                'requires_purpose_code' => $this->requiresPurposeCode(),
                'is_high_value' => $this->isHighValue(),
                'requires_additional_verification' => $this->requiresAdditionalVerification(),
            ],
            
            // Audit trail information
            'audit_info' => [
                'created_at' => $this->created_at->toISOString(),
                'last_updated' => $this->updated_at->toISOString(),
                'status_history_count' => $this->getStatusHistoryCount(),
                'last_status_change' => $this->getLastStatusChangeTime(),
            ],
            
            // Error details for failed instructions
            'error_details' => $this->when(
                $this->hasFailed() || $this->hasFailedValidation(),
                [
                    'error_type' => $this->getErrorType(),
                    'error_category' => $this->getErrorCategory(),
                    'is_recoverable' => $this->isRecoverableError(),
                    'suggested_action' => $this->getSuggestedAction(),
                    'error_occurred_at' => $this->getErrorOccurredAt(),
                ]
            ),
            
            // Additional data details
            'additional_info' => $this->when(
                !empty($this->additional_data) && is_array($this->additional_data),
                $this->getProcessedAdditionalData()
            ),
        ];
    }

    /**
     * Get formatted amount with currency symbol.
     */
    private function getFormattedAmount(): string
    {
        return number_format((float)$this->amount, 2);
    }

    /**
     * Get formatted amount with currency symbol.
     */
    private function getFormattedAmountWithSymbol(): string
    {
        return $this->getCurrencySymbol() . $this->getFormattedAmount();
    }

    /**
     * Get display-friendly status text.
     */
    private function getDisplayStatus(): string
    {
        $statusMap = [
            'pending' => 'Pending Validation',
            'validated' => 'Validated',
            'failed_validation' => 'Validation Failed',
            'processing' => 'Processing Payment',
            'processed' => 'Payment Processed',
            'completed' => 'Completed',
            'failed' => 'Payment Failed',
            'cancelled' => 'Cancelled',
        ];

        return $statusMap[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Get status color for UI display.
     */
    private function getStatusColor(): string
    {
        $colorMap = [
            'pending' => 'yellow',
            'validated' => 'blue',
            'failed_validation' => 'red',
            'processing' => 'blue',
            'processed' => 'green',
            'completed' => 'green',
            'failed' => 'red',
            'cancelled' => 'gray',
        ];

        return $colorMap[$this->status] ?? 'gray';
    }

    /**
     * Get status icon for UI display.
     */
    private function getStatusIcon(): string
    {
        $iconMap = [
            'pending' => 'clock',
            'validated' => 'check-circle',
            'failed_validation' => 'x-circle',
            'processing' => 'refresh-cw',
            'processed' => 'check',
            'completed' => 'check-circle',
            'failed' => 'alert-circle',
            'cancelled' => 'x',
        ];

        return $iconMap[$this->status] ?? 'help-circle';
    }

    /**
     * Get currency symbol.
     */
    private function getCurrencySymbol(): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'INR' => '₹'
        ];

        return $symbols[$this->currency] ?? $this->currency . ' ';
    }

    /**
     * Get currency decimal places.
     */
    private function getCurrencyDecimalPlaces(): int
    {
        return 2; // All supported currencies use 2 decimal places
    }

    /**
     * Get processing duration in seconds.
     */
    private function getProcessingDuration(): ?int
    {
        return $this->getProcessingDuration();
    }

    /**
     * Check if instruction can be retried.
     */
    private function canRetryProcessing(): bool
    {
        return $this->hasFailed() && $this->isRecoverableError();
    }

    /**
     * Get retry count from additional data.
     */
    private function getRetryCount(): int
    {
        if (!is_array($this->additional_data)) {
            return 0;
        }

        return (int) ($this->additional_data['retry_count'] ?? 0);
    }

    /**
     * Get last attempt time.
     */
    private function getLastAttemptTime(): ?string
    {
        if (!$this->processed_at) {
            return null;
        }

        return $this->processed_at->toISOString();
    }

    /**
     * Get next retry time.
     */
    private function getNextRetryTime(): ?string
    {
        if (!$this->canRetryProcessing()) {
            return null;
        }

        // Simple retry logic - would be more sophisticated in practice
        $retryCount = $this->getRetryCount();
        $delayMinutes = min(60, pow(2, $retryCount)); // Exponential backoff, max 60 minutes
        
        return now()->addMinutes($delayMinutes)->toISOString();
    }

    /**
     * Get validation errors count.
     */
    private function getValidationErrorsCount(): int
    {
        if (empty($this->validation_errors)) {
            return 0;
        }

        if (is_string($this->validation_errors)) {
            return 1;
        }

        if (is_array($this->validation_errors)) {
            return count($this->validation_errors);
        }

        return 0;
    }

    /**
     * Get validation error summary.
     */
    private function getValidationErrorSummary(): array
    {
        if (!$this->hasValidationErrors()) {
            return [];
        }

        $errors = is_string($this->validation_errors) 
            ? [$this->validation_errors] 
            : (array) $this->validation_errors;

        return [
            'total_errors' => count($errors),
            'first_error' => $errors[0] ?? '',
            'error_types' => $this->categorizeValidationErrors($errors),
        ];
    }

    /**
     * Categorize validation errors by type.
     */
    private function categorizeValidationErrors(array $errors): array
    {
        $categories = [
            'amount' => 0,
            'currency' => 0,
            'beneficiary' => 0,
            'purpose_code' => 0,
            'reference' => 0,
            'other' => 0,
        ];

        foreach ($errors as $error) {
            $errorLower = strtolower($error);
            
            if (strpos($errorLower, 'amount') !== false) {
                $categories['amount']++;
            } elseif (strpos($errorLower, 'currency') !== false) {
                $categories['currency']++;
            } elseif (strpos($errorLower, 'beneficiary') !== false) {
                $categories['beneficiary']++;
            } elseif (strpos($errorLower, 'purpose') !== false) {
                $categories['purpose_code']++;
            } elseif (strpos($errorLower, 'reference') !== false) {
                $categories['reference']++;
            } else {
                $categories['other']++;
            }
        }

        return array_filter($categories);
    }

    /**
     * Check if validation can be overridden.
     */
    private function canOverrideValidation(): bool
    {
        return $this->hasFailedValidation() && !$this->isCriticalValidationError();
    }

    /**
     * Check if instruction requires manual review.
     */
    private function requiresManualReview(): bool
    {
        return $this->hasValidationErrors() || 
               $this->isHighValue() || 
               $this->hasFailed();
    }

    /**
     * Check if validation error is critical.
     */
    private function isCriticalValidationError(): bool
    {
        if (!$this->hasValidationErrors()) {
            return false;
        }

        $criticalKeywords = ['currency mismatch', 'invalid beneficiary', 'duplicate'];
        $errors = is_string($this->validation_errors) 
            ? [$this->validation_errors] 
            : (array) $this->validation_errors;

        foreach ($errors as $error) {
            foreach ($criticalKeywords as $keyword) {
                if (stripos($error, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get currency limits for validation.
     */
    private function getCurrencyLimits(): array
    {
        $limits = [
            'GBP' => ['min_amount' => 1.00, 'max_amount' => 50000.00],
            'EUR' => ['min_amount' => 1.00, 'max_amount' => 50000.00],
            'USD' => ['min_amount' => 1.00, 'max_amount' => 50000.00],
            'INR' => ['min_amount' => 100.00, 'max_amount' => 5000000.00],
        ];

        return $limits[$this->currency] ?? ['min_amount' => 1.00, 'max_amount' => 50000.00];
    }

    /**
     * Check if purpose code is required for this currency.
     */
    private function requiresPurposeCode(): bool
    {
        $requiredCurrencies