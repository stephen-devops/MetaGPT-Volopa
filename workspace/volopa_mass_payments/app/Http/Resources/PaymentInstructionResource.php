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
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mass_payment_file_id' => $this->mass_payment_file_id,
            'beneficiary_id' => $this->beneficiary_id,
            'client_id' => $this->client_id,
            'amount' => $this->formatAmount($this->amount),
            'currency' => $this->currency,
            'purpose_code' => $this->purpose_code,
            'purpose_code_label' => $this->getPurposeCodeLabel(),
            'remittance_information' => $this->remittance_information,
            'payment_reference' => $this->payment_reference,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'row_number' => $this->row_number,
            'validation_errors' => $this->when(
                $this->shouldIncludeValidationErrors($request),
                $this->validation_errors
            ),
            'processing_reference' => $this->when(
                !empty($this->processing_reference),
                $this->processing_reference
            ),
            'failure_reason' => $this->when(
                !empty($this->failure_reason),
                $this->failure_reason
            ),
            'processed_at' => $this->when(
                $this->processed_at,
                $this->processed_at?->toISOString()
            ),
            'processing_fee' => $this->when(
                $this->processing_fee && $this->processing_fee > 0,
                $this->formatAmount($this->processing_fee)
            ),
            'exchange_rate' => $this->when(
                !empty($this->exchange_rate),
                $this->exchange_rate
            ),
            'local_amount' => $this->when(
                $this->local_amount && $this->local_amount !== $this->amount,
                $this->formatAmount($this->local_amount)
            ),
            'local_currency' => $this->when(
                !empty($this->local_currency) && $this->local_currency !== $this->currency,
                $this->local_currency
            ),
            'bank_response' => $this->when(
                $this->shouldIncludeBankResponse($request),
                $this->bank_response
            ),
            'transaction_id' => $this->when(
                !empty($this->transaction_id),
                $this->transaction_id
            ),
            'metadata' => $this->when(
                $this->shouldIncludeMetadata($request),
                $this->formatMetadata()
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'processing_info' => [
                'retry_count' => $this->getRetryCount(),
                'can_be_retried' => $this->canBeRetried(),
                'estimated_completion' => $this->getEstimatedCompletion(),
                'processing_duration' => $this->getProcessingDuration(),
                'is_high_value' => $this->isHighValue(),
                'requires_manual_review' => $this->requiresManualReview()
            ],
            'relationships' => [
                'mass_payment_file' => $this->when(
                    $this->relationLoaded('massPaymentFile'),
                    function () {
                        return [
                            'id' => $this->massPaymentFile->id,
                            'filename' => $this->massPaymentFile->original_filename,
                            'status' => $this->massPaymentFile->status,
                            'total_amount' => $this->formatAmount($this->massPaymentFile->total_amount),
                            'currency' => $this->massPaymentFile->currency,
                            'created_at' => $this->massPaymentFile->created_at->toISOString()
                        ];
                    }
                ),
                'beneficiary' => $this->when(
                    $this->relationLoaded('beneficiary'),
                    function () {
                        return [
                            'id' => $this->beneficiary->id,
                            'name' => $this->beneficiary->name,
                            'account_number' => $this->maskAccountNumber($this->beneficiary->account_number),
                            'bank_code' => $this->beneficiary->bank_code,
                            'bank_name' => $this->beneficiary->bank_name ?? '',
                            'country' => $this->beneficiary->country ?? '',
                            'is_active' => $this->beneficiary->is_active ?? true,
                            'is_verified' => $this->beneficiary->is_verified ?? false
                        ];
                    }
                )
            ],
            'actions' => $this->getAvailableActions($request),
            'display' => [
                'status_color' => $this->getStatusColor(),
                'status_icon' => $this->getStatusIcon(),
                'priority_level' => $this->getPriorityLevel(),
                'risk_level' => $this->getRiskLevel(),
                'formatted_created_at' => $this->created_at->format('M j, Y \a\t g:i A T'),
                'formatted_updated_at' => $this->updated_at->format('M j, Y \a\t g:i A T'),
                'formatted_processed_at' => $this->when(
                    $this->processed_at,
                    $this->processed_at?->format('M j, Y \a\t g:i A T')
                ),
                'masked_account_number' => $this->maskAccountNumber($this->getBeneficiaryAccountNumber()),
                'formatted_amount' => $this->formatAmountWithCurrency($this->amount, $this->currency)
            ]
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'type' => 'payment_instruction',
                'api_version' => 'v1',
                'timestamp' => now()->toISOString(),
                'resource_permissions' => $this->getResourcePermissions($request)
            ]
        ];
    }

    /**
     * Get human-readable status label.
     *
     * @return string
     */
    private function getStatusLabel(): string
    {
        $statusLabels = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'rejected' => 'Rejected'
        ];

        return $statusLabels[$this->status] ?? 'Unknown';
    }

    /**
     * Get human-readable purpose code label.
     *
     * @return string
     */
    private function getPurposeCodeLabel(): string
    {
        $purposeCodeLabels = [
            'SAL' => 'Salary',
            'DIV' => 'Dividend',
            'INT' => 'Interest',
            'FEE' => 'Fee',
            'RFD' => 'Refund',
            'TRD' => 'Trade',
            'SVC' => 'Service',
            'SUP' => 'Supplier',
            'INV' => 'Investment',
            'OTH' => 'Other'
        ];

        return $purposeCodeLabels[$this->purpose_code] ?? 'Unknown';
    }

    /**
     * Format amount for display.
     *
     * @param float|null $amount
     * @return string
     */
    private function formatAmount(?float $amount): string
    {
        if ($amount === null) {
            return '0.00';
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * Format amount with currency for display.
     *
     * @param float|null $amount
     * @param string $currency
     * @return string
     */
    private function formatAmountWithCurrency(?float $amount, string $currency): string
    {
        $formattedAmount = $this->formatAmount($amount);
        return "{$formattedAmount} {$currency}";
    }

    /**
     * Determine if validation errors should be included.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeValidationErrors(Request $request): bool
    {
        // Include validation errors if specifically requested or if user has permission
        return $request->boolean('include_validation_errors', false) ||
               $request->user()?->can('viewValidationErrors', $this->resource);
    }

    /**
     * Determine if bank response should be included.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeBankResponse(Request $request): bool
    {
        // Include bank response only for completed, failed, or rejected payments
        if (!in_array($this->status, ['completed', 'failed', 'rejected'])) {
            return false;
        }

        // Include if specifically requested or user has permission
        return $request->boolean('include_bank_response', false) ||
               $request->user()?->can('viewBankResponse', $this->resource);
    }

    /**
     * Determine if metadata should be included.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeMetadata(Request $request): bool
    {
        // Include metadata if specifically requested or for detailed view
        return $request->boolean('include_metadata', false) ||
               $request->route()?->getName() === 'api.v1.payment-instructions.show';
    }

    /**
     * Format metadata for response.
     *
     * @return array
     */
    private function formatMetadata(): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'payment_type' => $metadata['payment_type'] ?? 'standard',
            'urgency_level' => $metadata['urgency_level'] ?? 'normal',
            'compliance_flags' => $metadata['compliance_flags'] ?? [],
            'risk_indicators' => $metadata['risk_indicators'] ?? [],
            'processing_notes' => $metadata['processing_notes'] ?? '',
            'retry_history' => $metadata['retry_history'] ?? [],
            'external_references' => $metadata['external_references'] ?? [],
            'beneficiary_verification' => $metadata['beneficiary_verification'] ?? [],
            'regulatory_information' => $metadata['regulatory_information'] ?? [],
            'routing_information' => $metadata['routing_information'] ?? []
        ];
    }

    /**
     * Get retry count from metadata.
     *
     * @return int
     */
    private function getRetryCount(): int
    {
        $metadata = $this->metadata ?? [];
        $retryHistory = $metadata['retry_history'] ?? [];
        
        return count($retryHistory);
    }

    /**
     * Check if payment instruction can be retried.
     *
     * @return bool
     */
    private function canBeRetried(): bool
    {
        // Can only retry failed payments
        if ($this->status !== 'failed') {
            return false;
        }

        // Check retry count limit
        if ($this->getRetryCount() >= 3) {
            return false;
        }

        // Check if failure is retryable
        $nonRetryableReasons = [
            'invalid_beneficiary',
            'account_closed',
            'insufficient_funds',
            'compliance_rejection',
            'sanctions_hit'
        ];

        $metadata = $this->metadata ?? [];
        $failureCategory = $metadata['failure_category'] ?? '';

        return !in_array($failureCategory, $nonRetryableReasons);
    }

    /**
     * Get estimated completion time.
     *
     * @return string|null
     */
    private function getEstimatedCompletion(): ?string
    {
        if ($this->status === 'completed') {
            return null;
        }

        if ($this->status === 'processing') {
            // Estimate based on currency and payment method
            $estimatedMinutes = $this->getEstimatedProcessingTime();
            return now()->addMinutes($estimatedMinutes)->toISOString();
        }

        return null;
    }

    /**
     * Get estimated processing time in minutes based on currency.
     *
     * @return int
     */
    private function getEstimatedProcessingTime(): int
    {
        $processingTimes = [
            'USD' => 30,
            'EUR' => 60,
            'GBP' => 45,
            'SGD' => 15,
            'HKD' => 15,
            'AUD' => 30,
            'CAD' => 30,
            'JPY' => 60,
            'CNY' => 120,
            'THB' => 30,
            'MYR' => 30,
            'IDR' => 45,
            'PHP' => 45,
            'VND' => 60
        ];

        return $processingTimes[$this->currency] ?? 45;
    }

    /**
     * Get processing duration in human readable format.
     *
     * @return string|null
     */
    private function getProcessingDuration(): ?string
    {
        if ($this->status === 'pending') {
            return null;
        }

        $endTime = $this->processed_at ?? now();
        return $this->created_at->diffForHumans($endTime, true);
    }

    /**
     * Check if this is a high-value payment.
     *
     * @return bool
     */
    private function isHighValue(): bool
    {
        $highValueThresholds = [
            'USD' => 100000.00,
            'EUR' => 90000.00,
            'GBP' => 80000.00,
            'SGD' => 130000.00,
            'HKD' => 780000.00,
            'AUD' => 140000.00,
            'CAD' => 130000.00,
            'JPY' => 11000000.00,
            'CNY' => 650000.00,
            'THB' => 3200000.00,
            'MYR' => 420000.00,
            'IDR' => 1500000000.00,
            'PHP' => 5000000.00,
            'VND' => 2300000000.00
        ];

        $threshold = $highValueThresholds[$this->currency] ?? 50000.00;
        return $this->amount >= $threshold;
    }

    /**
     * Check if payment requires manual review.
     *
     * @return bool
     */
    private function requiresManualReview(): bool
    {
        // High value payments require manual review
        if ($this->isHighValue()) {
            return true;
        }

        // Check for compliance flags
        $metadata = $this->metadata ?? [];
        $complianceFlags = $metadata['compliance_flags'] ?? [];
        
        if (!empty($complianceFlags)) {
            return true;
        }

        // Check for risk indicators
        $riskIndicators = $metadata['risk_indicators'] ?? [];
        $highRiskIndicators = ['sanctions_screening', 'pep_match', 'high_risk_country'];
        
        foreach ($highRiskIndicators as $indicator) {
            if (in_array($indicator, $riskIndicators)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask account