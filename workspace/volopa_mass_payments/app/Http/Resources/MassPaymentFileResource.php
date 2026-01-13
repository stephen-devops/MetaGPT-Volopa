## Code: app/Http/Resources/MassPaymentFileResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MassPaymentFileResource extends JsonResource
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
            'client_id' => $this->client_id,
            'tcc_account_id' => $this->tcc_account_id,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'total_amount' => $this->formatAmount($this->total_amount),
            'currency' => $this->currency,
            'total_rows' => $this->total_rows ?? 0,
            'valid_rows' => $this->valid_rows ?? 0,
            'invalid_rows' => $this->invalid_rows ?? 0,
            'validation_summary' => $this->validation_summary,
            'validation_errors' => $this->when(
                $this->shouldIncludeValidationErrors($request),
                $this->validation_errors
            ),
            'created_by' => $this->created_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->when(
                $this->approved_at,
                $this->approved_at?->toISOString()
            ),
            'rejection_reason' => $this->when(
                !empty($this->rejection_reason),
                $this->rejection_reason
            ),
            'metadata' => $this->when(
                $this->shouldIncludeMetadata($request),
                $this->formatMetadata()
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'processing_info' => [
                'validation_success_rate' => $this->getValidationSuccessRate(),
                'formatted_file_size' => $this->getFormattedFileSize(),
                'processing_duration' => $this->getProcessingDuration(),
                'can_be_approved' => $this->canBeApproved(),
                'can_be_cancelled' => $this->canBeCancelled(),
                'has_validation_errors' => $this->hasValidationErrors()
            ],
            'relationships' => [
                'tcc_account' => $this->when(
                    $this->relationLoaded('tccAccount'),
                    function () {
                        return [
                            'id' => $this->tccAccount->id,
                            'account_name' => $this->tccAccount->account_name ?? '',
                            'account_number' => $this->tccAccount->account_number ?? '',
                            'currency' => $this->tccAccount->currency ?? '',
                            'is_active' => $this->tccAccount->is_active ?? false
                        ];
                    }
                ),
                'payment_instructions' => $this->when(
                    $this->relationLoaded('paymentInstructions'),
                    PaymentInstructionResource::collection($this->paymentInstructions)
                ),
                'payment_instructions_summary' => $this->when(
                    $this->relationLoaded('paymentInstructions'),
                    function () {
                        $instructions = $this->paymentInstructions;
                        return [
                            'total_count' => $instructions->count(),
                            'pending_count' => $instructions->where('status', 'pending')->count(),
                            'processing_count' => $instructions->where('status', 'processing')->count(),
                            'completed_count' => $instructions->where('status', 'completed')->count(),
                            'failed_count' => $instructions->where('status', 'failed')->count(),
                            'cancelled_count' => $instructions->where('status', 'cancelled')->count(),
                            'rejected_count' => $instructions->where('status', 'rejected')->count()
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
                'formatted_approved_at' => $this->when(
                    $this->approved_at,
                    $this->approved_at?->format('M j, Y \a\t g:i A T')
                )
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
                'type' => 'mass_payment_file',
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
            'uploading' => 'Uploading',
            'processing' => 'Processing',
            'validation_completed' => 'Validation Completed',
            'validation_failed' => 'Validation Failed',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'processing_payments' => 'Processing Payments',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'failed' => 'Failed'
        ];

        return $statusLabels[$this->status] ?? 'Unknown';
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
     * Determine if metadata should be included.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeMetadata(Request $request): bool
    {
        // Include metadata if specifically requested or for detailed view
        return $request->boolean('include_metadata', false) ||
               $request->route()?->getName() === 'api.v1.mass-payment-files.show';
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
            'file_size' => $metadata['file_size'] ?? 0,
            'mime_type' => $metadata['mime_type'] ?? '',
            'original_extension' => $metadata['original_extension'] ?? '',
            'priority' => $metadata['priority'] ?? 'normal',
            'processing_notes' => $metadata['processing_notes'] ?? '',
            'upload_source' => $metadata['upload_source'] ?? 'web',
            'client_reference' => $metadata['client_reference'] ?? '',
            'batch_reference' => $metadata['batch_reference'] ?? '',
            'compliance_flags' => $metadata['compliance_flags'] ?? [],
            'risk_indicators' => $metadata['risk_indicators'] ?? [],
            'processing_timestamps' => $metadata['processing_timestamps'] ?? []
        ];
    }

    /**
     * Get available actions based on current status and permissions.
     *
     * @param Request $request
     * @return array
     */
    private function getAvailableActions(Request $request): array
    {
        $user = $request->user();
        $actions = [];

        if (!$user) {
            return $actions;
        }

        // View action
        if ($user->can('view', $this->resource)) {
            $actions['view'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.show', $this->id),
                'method' => 'GET'
            ];
        }

        // Approve action
        if ($user->can('approve', $this->resource)) {
            $actions['approve'] = [
                'available' => $this->canBeApproved(),
                'url' => route('api.v1.mass-payment-files.approve', $this->id),
                'method' => 'POST',
                'reason' => $this->canBeApproved() ? null : $this->getApprovalBlockingReason()
            ];
        }

        // Delete action
        if ($user->can('delete', $this->resource)) {
            $actions['delete'] = [
                'available' => $this->canBeCancelled(),
                'url' => route('api.v1.mass-payment-files.destroy', $this->id),
                'method' => 'DELETE',
                'reason' => $this->canBeCancelled() ? null : 'File cannot be deleted in current status'
            ];
        }

        // Download action
        if ($user->can('download', $this->resource)) {
            $actions['download'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.download', $this->id),
                'method' => 'GET'
            ];
        }

        // Resubmit action (for failed validations)
        if ($user->can('resubmit', $this->resource)) {
            $actions['resubmit'] = [
                'available' => $this->isValidationFailed(),
                'url' => route('api.v1.mass-payment-files.resubmit', $this->id),
                'method' => 'POST'
            ];
        }

        // Cancel action
        if ($user->can('cancel', $this->resource)) {
            $actions['cancel'] = [
                'available' => $this->canBeCancelled(),
                'url' => route('api.v1.mass-payment-files.cancel', $this->id),
                'method' => 'POST'
            ];
        }

        // Export action
        if ($user->can('export', $this->resource)) {
            $actions['export'] = [
                'available' => in_array($this->status, ['completed', 'failed', 'cancelled']),
                'url' => route('api.v1.mass-payment-files.export', $this->id),
                'method' => 'GET'
            ];
        }

        return $actions;
    }

    /**
     * Get status color for UI display.
     *
     * @return string
     */
    private function getStatusColor(): string
    {
        $statusColors = [
            'uploading' => 'blue',
            'processing' => 'blue',
            'validation_completed' => 'green',
            'validation_failed' => 'red',
            'pending_approval' => 'orange',
            'approved' => 'green',
            'processing_payments' => 'blue',
            'completed' => 'green',
            'cancelled' => 'gray',
            'failed' => 'red'
        ];

        return $statusColors[$this->status] ?? 'gray';
    }

    /**
     * Get status icon for UI display.
     *
     * @return string
     */
    private function getStatusIcon(): string
    {
        $statusIcons = [
            'uploading' => 'upload',
            'processing' => 'spinner',
            'validation_completed' => 'check-circle',
            'validation_failed' => 'exclamation-circle',
            'pending_approval' => 'clock',
            'approved' => 'check-circle',
            'processing_payments' => 'credit-card',
            'completed' => 'check',
            'cancelled' => 'ban',
            'failed' => 'times-circle'
        ];

        return $statusIcons[$this->status] ?? 'question-circle';
    }

    /**
     * Get priority level based on amount and metadata.
     *
     * @return string
     */
    private function getPriorityLevel(): string
    {
        $metadata = $this->metadata ?? [];
        
        if (isset($metadata['priority'])) {
            return $metadata['priority'];
        }

        $amount = $this->total_amount ?? 0;
        $currency = $this->currency ?? '';

        // High-value thresholds by currency
        $thresholds = [
            'USD' => ['urgent' => 5000000, 'high' => 1000000, 'normal' => 100000],
            'EUR' => ['urgent' => 4500000, 'high' => 900000, 'normal' => 90000],
            'GBP' => ['urgent' => 4000000, 'high' => 800000, 'normal' => 80000],
            'SGD' => ['urgent' => 6500000, 'high' => 1300000, 'normal' => 130000],
            'HKD' => ['urgent' => 39000000, 'high' => 7800000, 'normal' => 780000],
            'default' => ['urgent' => 2500000, 'high' => 500000, 'normal' => 50000]
        ];

        $currencyThresholds = $thresholds[$currency] ?? $thresholds['default'];

        if ($amount >= $currencyThresholds['urgent']) {
            return 'urgent';
        } elseif ($amount >= $currencyThresholds['high']) {
            return 'high';
        } elseif ($amount >= $currencyThresholds['normal']) {
            return 'normal';
        } else {
            return 'low';
        }
    }

    /**
     * Get risk level based on amount, destination, and compliance flags.
     *
     * @return string
     */
    private function getRiskLevel(): string
    {
        $metadata = $this->metadata ?? [];
        $riskIndicators = $metadata['risk_indicators'] ?? [];
        $complianceFlags = $metadata['compliance_flags'] ?? [];
        
        // High risk if compliance flags present
        if (!empty($complianceFlags)) {
            return 'high';
        }

        // Medium risk for high-value transactions
        $priority = $this->getPriorityLevel();
        if (in_array($priority, ['urgent', 'high'])) {
            return 'medium';
        }

        // Check specific risk indicators
        $highRiskIndicators = ['sanctions_screening', 'pep_match', 'high_risk_country'];
        foreach ($highRiskIndicators as $indicator) {
            if (in_array($indicator, $riskIndicators)) {
                return 'high';
            }
        }

        $mediumRiskIndicators = ['new_beneficiary', 'unusual_pattern', 'currency_mismatch'];
        foreach ($mediumRiskIndicators as $indicator) {
            if (in_array($indicator, $riskIndicators