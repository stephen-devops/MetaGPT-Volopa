## Code: app/Http/Resources/PaymentResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\FileResource;
use Carbon\Carbon;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplayName(),
            
            // Beneficiary information
            'beneficiary' => [
                'name' => $this->beneficiary_name,
                'account' => $this->beneficiary_account,
                'masked_account' => $this->getMaskedBeneficiaryAccount(),
            ],
            
            // Payment details
            'payment' => [
                'amount' => $this->amount,
                'formatted_amount' => $this->formatted_amount,
                'currency' => $this->currency,
                'settlement_method' => $this->settlement_method,
                'settlement_method_display' => $this->settlement_method_display,
                'payment_purpose' => $this->payment_purpose,
                'reference' => $this->reference,
            ],
            
            // Processing information
            'processing' => [
                'is_pending' => $this->isPending(),
                'is_processing' => $this->isProcessing(),
                'is_completed' => $this->isCompleted(),
                'has_failed' => $this->hasFailed(),
                'is_cancelled' => $this->isCancelled(),
                'is_processed' => $this->isProcessed(),
                'processed_at' => $this->processed_at?->toISOString(),
                'processed_at_human' => $this->processed_at?->diffForHumans(),
            ],
            
            // Settlement information
            'settlement' => [
                'method' => $this->settlement_method,
                'method_display' => $this->settlement_method_display,
                'minimum_processing_time' => $this->minimum_processing_time,
                'maximum_processing_time' => $this->maximum_processing_time,
                'estimated_completion' => $this->getEstimatedCompletionTime(),
                'is_instant' => $this->isInstantSettlement(),
                'business_days_required' => $this->getBusinessDaysRequired(),
            ],
            
            // Payment file information (summary)
            'payment_file' => [
                'id' => $this->payment_file_id,
                'filename' => $this->paymentFile->original_name ?? 'Unknown File',
                'status' => $this->paymentFile->status ?? 'unknown',
                'currency' => $this->paymentFile->currency ?? 'USD',
                'uploaded_by' => $this->paymentFile->user->name ?? 'Unknown User',
                'uploaded_at' => $this->paymentFile->created_at?->toISOString(),
            ],
            
            // Validation information
            'validation' => [
                'is_valid' => $this->isValid(),
                'validation_errors' => $this->getValidationSummary(),
                'has_warnings' => $this->hasWarnings(),
                'compliance_score' => $this->getComplianceScore(),
            ],
            
            // Risk assessment
            'risk' => [
                'level' => $this->getRiskLevel(),
                'factors' => $this->getRiskFactors(),
                'requires_manual_review' => $this->requiresManualReview(),
                'aml_check_status' => $this->getAmlCheckStatus(),
                'sanctions_check_status' => $this->getSanctionsCheckStatus(),
            ],
            
            // Timestamps
            'timestamps' => [
                'created_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
                'processed_at' => $this->processed_at?->toISOString(),
                'created_at_human' => $this->created_at?->diffForHumans(),
                'updated_at_human' => $this->updated_at?->diffForHumans(),
                'processed_at_human' => $this->processed_at?->diffForHumans(),
            ],
            
            // Full payment file details (conditionally loaded)
            'full_payment_file' => new FileResource($this->whenLoaded('paymentFile')),
            
            // Transaction tracking
            'tracking' => [
                'transaction_id' => $this->getTransactionId(),
                'external_reference' => $this->getExternalReference(),
                'tracking_number' => $this->getTrackingNumber(),
                'correspondent_bank' => $this->getCorrespondentBank(),
            ],
            
            // Actions available to current user
            'actions' => $this->getAvailableActions($request),
            
            // API links
            'links' => [
                'self' => route('api.v1.payments.show', $this->id),
                'payment_file' => route('api.v1.files.show', $this->payment_file_id),
                'retry' => $this->when(
                    $this->hasFailed() && $this->canBeRetried($request),
                    route('api.v1.payments.retry', $this->id)
                ),
                'cancel' => $this->when(
                    $this->canBeCancelled($request),
                    route('api.v1.payments.cancel', $this->id)
                ),
                'track' => $this->when(
                    $this->isProcessed(),
                    route('api.v1.payments.track', $this->id)
                ),
            ],
            
            // Metadata for frontend
            'meta' => [
                'priority' => $this->getProcessingPriority(),
                'complexity' => $this->getProcessingComplexity(),
                'estimated_fees' => $this->getEstimatedFees(),
                'exchange_rate' => $this->getExchangeRate(),
                'cut_off_time' => $this->getCutOffTime(),
                'next_processing_window' => $this->getNextProcessingWindow(),
                'can_be_expedited' => $this->canBeExpedited(),
                'requires_additional_documentation' => $this->requiresAdditionalDocumentation(),
            ],
        ];
    }

    /**
     * Get display name for the payment status.
     *
     * @return string
     */
    private function getStatusDisplayName(): string
    {
        $statusDisplayNames = [
            'pending' => 'Pending Processing',
            'processing' => 'Processing',
            'completed' => 'Completed Successfully',
            'failed' => 'Processing Failed',
            'cancelled' => 'Cancelled',
        ];

        return $statusDisplayNames[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Get masked beneficiary account number for security.
     *
     * @return string
     */
    private function getMaskedBeneficiaryAccount(): string
    {
        $account = $this->beneficiary_account ?? '';
        
        if (strlen($account) <= 4) {
            return str_repeat('*', strlen($account));
        }

        $visibleChars = 4;
        $maskedLength = strlen($account) - $visibleChars;
        
        return str_repeat('*', $maskedLength) . substr($account, -$visibleChars);
    }

    /**
     * Check if payment is valid (no validation errors).
     *
     * @return bool
     */
    private function isValid(): bool
    {
        // For simplicity, consider valid if status is not failed and we have required fields
        return !empty($this->beneficiary_name) && 
               !empty($this->beneficiary_account) && 
               $this->amount > 0 && 
               !empty($this->currency) && 
               !empty($this->settlement_method);
    }

    /**
     * Get validation summary for the payment.
     *
     * @return array<string, mixed>
     */
    private function getValidationSummary(): array
    {
        $errors = [];
        $warnings = [];

        // Check required fields
        if (empty($this->beneficiary_name)) {
            $errors[] = 'Beneficiary name is required';
        }

        if (empty($this->beneficiary_account)) {
            $errors[] = 'Beneficiary account is required';
        }

        if ($this->amount <= 0) {
            $errors[] = 'Amount must be greater than zero';
        }

        // Check for warnings
        if (empty($this->payment_purpose)) {
            $warnings[] = 'Payment purpose not specified';
        }

        if ($this->amount > 10000) {
            $warnings[] = 'High-value payment may require additional approval';
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            'is_valid' => empty($errors),
        ];
    }

    /**
     * Check if payment has warnings.
     *
     * @return bool
     */
    private function hasWarnings(): bool
    {
        $validation = $this->getValidationSummary();
        return $validation['warning_count'] > 0;
    }

    /**
     * Get compliance score for the payment (0-100).
     *
     * @return int
     */
    private function getComplianceScore(): int
    {
        $score = 100;
        $validation = $this->getValidationSummary();
        
        // Reduce score for each error
        $score -= $validation['error_count'] * 20;
        
        // Reduce score for each warning
        $score -= $validation['warning_count'] * 5;
        
        // Reduce score for missing optional fields
        if (empty($this->payment_purpose)) {
            $score -= 5;
        }
        
        if (empty($this->reference)) {
            $score -= 3;
        }
        
        return max(0, $score);
    }

    /**
     * Get risk level for the payment.
     *
     * @return string
     */
    private function getRiskLevel(): string
    {
        $riskFactors = $this->getRiskFactors();
        $highRiskCount = array_sum(array_filter($riskFactors, function($factor) {
            return $factor === true;
        }));

        if ($highRiskCount >= 3) {
            return 'high';
        } elseif ($highRiskCount >= 1) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get risk factors for the payment.
     *
     * @return array<string, bool>
     */
    private function getRiskFactors(): array
    {
        $amount = $this->amount ?? 0.0;
        $currency = $this->currency ?? 'USD';
        $settlementMethod = $this->settlement_method ?? '';

        return [
            'high_value' => $amount >= 50000.0,
            'foreign_currency' => $currency !== 'USD',
            'cross_border' => in_array($settlementMethod, ['SWIFT', 'WIRE']),
            'cash_intensive_business' => $this->isCashIntensiveBusiness(),
            'high_risk_country' => $this->isHighRiskCountry(),
            'suspicious_pattern' => $this->hasSuspiciousPattern(),
            'frequent_beneficiary' => $this->isFrequentBeneficiary(),
            'round_number' => $this->isRoundNumber(),
        ];
    }

    /**
     * Check if payment requires manual review.
     *
     * @return bool
     */
    private function requiresManualReview(): bool
    {
        return $this->getRiskLevel() === 'high' || 
               $this->amount >= 100000.0 ||
               !$this->isValid();
    }

    /**
     * Get AML check status.
     *
     * @return string
     */
    private function getAmlCheckStatus(): string
    {
        // Simulate AML check status
        if ($this->getRiskLevel() === 'high') {
            return 'manual_review_required';
        } elseif ($this->getRiskLevel() === 'medium') {
            return 'automated_review_passed';
        }

        return 'cleared';
    }

    /**
     * Get sanctions check status.
     *
     * @return string
     */
    private function getSanctionsCheckStatus(): string
    {
        // Simulate sanctions screening
        return 'cleared';
    }

    /**
     * Get estimated completion time.
     *
     * @return string|null
     */
    private function getEstimatedCompletionTime(): ?string
    {
        if (!$this->isPending() && !$this->isProcessing()) {
            return null;
        }

        $now = Carbon::now();
        $processingHours = $this->maximum_processing_time ?? 24;
        
        return $now->addHours($processingHours)->toISOString();
    }

    /**
     * Check if settlement method is instant.
     *
     * @return bool
     */
    private function isInstantSettlement(): bool
    {
        return ($this->minimum_processing_time ?? 24) === 0;
    }

    /**
     * Get business days required for processing.
     *
     * @return int
     */
    private function getBusinessDaysRequired(): int
    {
        $hours = $this->maximum_processing_time ?? 24;
        return max(1, ceil($hours / 24));
    }

    /**
     * Get transaction ID (simulated).
     *
     * @return string|null
     */
    private function getTransactionId(): ?string
    {
        if ($this->isProcessed()) {
            return 'TXN-' . str_pad($this->id, 10, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * Get external reference (simulated).
     *
     * @return string|null
     */
    private function getExternalReference(): ?string
    {
        if ($this->isCompleted()) {
            return 'EXT-' . strtoupper(substr(md5($this->id . $this->beneficiary_account), 0, 8));
        }

        return null;
    }

    /**
     * Get tracking number (simulated).
     *
     * @return string|null
     */
    private function getTrackingNumber(): ?string
    {
        if ($this->isProcessing() || $this->isCompleted()) {
            return 'TRK-' . date('Ymd') . '-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
        }

        return null;
    }

    /**
     * Get correspondent bank information.
     *
     * @return string|null
     */
    private function getCorrespondentBank(): ?string
    {
        $correspondentBanks = [
            'SWIFT' => 'Correspondent Bank International',
            'WIRE' => 'Federal Wire Network',
            'SEPA' => 'European Central Bank',
            'FASTER_PAYMENTS' => 'UK Faster Payments Service',
            'ACH' => 'Federal Reserve ACH Network',
        ];

        return $correspondentBanks[$this->settlement_method] ?? null;
    }

    /**
     * Get available actions for the current user.
     *
     * @param Request $request
     * @return array<string, bool>
     */
    private function getAvailableActions(Request $request): array
    {
        $user = $request->user();
        
        return [