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
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'tcc_account_id' => $this->tcc_account_id,
            'filename' => $this->filename,
            'file_path' => $this->when(
                $request->user() && $request->user()->hasRole('admin'),
                $this->file_path
            ),
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'valid_rows' => $this->valid_rows,
            'error_rows' => $this->error_rows,
            'validation_errors' => $this->when(
                $this->hasValidationErrors(),
                $this->validation_errors
            ),
            'total_amount' => number_format((float)$this->total_amount, 2, '.', ''),
            'currency' => $this->currency,
            'uploaded_by' => $this->uploaded_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->when(
                $this->approved_at,
                $this->approved_at?->toISOString()
            ),
            'rejection_reason' => $this->when(
                $this->isRejected(),
                $this->rejection_reason
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Computed fields
            'success_rate' => round($this->getSuccessRate(), 2),
            'error_rate' => round($this->getErrorRate(), 2),
            'is_pending' => $this->isPending(),
            'is_approved' => $this->isApproved(),
            'is_rejected' => $this->isRejected(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'has_failed' => $this->hasFailed(),
            'has_validation_errors' => $this->hasValidationErrors(),
            'validation_error_count' => $this->getValidationErrorCount(),
            
            // Formatted amounts
            'formatted_amount' => $this->getFormattedAmount(),
            
            // Status indicators for UI
            'can_be_approved' => $this->canBeApproved(),
            'can_be_rejected' => $this->canBeRejected(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_deleted' => $this->canBeDeleted(),
            
            // Relationships (only when loaded)
            'tcc_account' => new TccAccountResource($this->whenLoaded('tccAccount')),
            'uploader' => $this->when(
                $this->relationLoaded('uploader'),
                [
                    'id' => $this->uploader?->id,
                    'name' => $this->uploader?->name,
                    'email' => $this->uploader?->email,
                ]
            ),
            'approver' => $this->when(
                $this->relationLoaded('approver') && $this->approver,
                [
                    'id' => $this->approver?->id,
                    'name' => $this->approver?->name,
                    'email' => $this->approver?->email,
                ]
            ),
            'payment_instructions' => PaymentInstructionResource::collection(
                $this->whenLoaded('paymentInstructions')
            ),
            
            // Payment instructions summary (when loaded)
            'payment_instructions_summary' => $this->when(
                $this->relationLoaded('paymentInstructions'),
                [
                    'total_count' => $this->paymentInstructions->count(),
                    'pending_count' => $this->paymentInstructions->where('status', 'pending')->count(),
                    'validated_count' => $this->paymentInstructions->where('status', 'validated')->count(),
                    'failed_validation_count' => $this->paymentInstructions->where('status', 'failed_validation')->count(),
                    'processing_count' => $this->paymentInstructions->where('status', 'processing')->count(),
                    'processed_count' => $this->paymentInstructions->where('status', 'processed')->count(),
                    'completed_count' => $this->paymentInstructions->where('status', 'completed')->count(),
                    'failed_count' => $this->paymentInstructions->where('status', 'failed')->count(),
                    'cancelled_count' => $this->paymentInstructions->where('status', 'cancelled')->count(),
                ]
            ),
            
            // Processing metadata
            'processing_info' => [
                'estimated_processing_time' => $this->getEstimatedProcessingTime(),
                'processing_started_at' => $this->when(
                    $this->isProcessing() || $this->isCompleted(),
                    $this->getProcessingStartTime()
                ),
                'processing_duration' => $this->when(
                    $this->isCompleted(),
                    $this->getProcessingDuration()
                ),
                'next_possible_actions' => $this->getNextPossibleActions(),
            ],
            
            // Validation summary for failed files
            'validation_summary' => $this->when(
                $this->hasValidationErrors() && !empty($this->validation_errors),
                $this->getValidationSummary()
            ),
            
            // Audit trail information
            'audit_info' => [
                'uploaded_at' => $this->created_at->toISOString(),
                'last_updated' => $this->updated_at->toISOString(),
                'status_changes_count' => $this->getStatusChangesCount(),
                'last_status_change' => $this->getLastStatusChangeTime(),
            ],
            
            // File metadata
            'file_info' => [
                'original_filename' => $this->filename,
                'file_size_readable' => $this->getReadableFileSize(),
                'mime_type' => $this->getFileMimeType(),
                'is_file_accessible' => $this->isFileAccessible(),
            ],
            
            // Currency-specific information
            'currency_info' => [
                'currency_code' => $this->currency,
                'currency_symbol' => $this->getCurrencySymbol(),
                'decimal_places' => $this->getCurrencyDecimalPlaces(),
                'min_amount' => $this->getCurrencyMinAmount(),
                'max_amount' => $this->getCurrencyMaxAmount(),
            ],
            
            // Business rules status
            'business_rules' => [
                'requires_approval' => $this->requiresApproval(),
                'approval_threshold_exceeded' => $this->exceedsApprovalThreshold(),
                'has_duplicate_beneficiaries' => $this->hasDuplicateBeneficiaries(),
                'exceeds_daily_limit' => $this->exceedsDailyLimit(),
                'exceeds_monthly_limit' => $this->exceedsMonthlyLimit(),
            ],
        ];
    }

    /**
     * Get formatted amount with currency symbol.
     */
    private function getFormattedAmount(): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'INR' => '₹'
        ];

        $symbol = $symbols[$this->currency] ?? $this->currency . ' ';
        return $symbol . number_format((float)$this->total_amount, 2);
    }

    /**
     * Check if file can be approved.
     */
    private function canBeApproved(): bool
    {
        return in_array($this->status, ['validated', 'pending_approval', 'validation_failed']) &&
               !$this->isApproved() &&
               !$this->isRejected();
    }

    /**
     * Check if file can be rejected.
     */
    private function canBeRejected(): bool
    {
        return in_array($this->status, [
            'uploaded',
            'validated',
            'validation_failed',
            'pending_approval'
        ]) && !$this->isRejected() && !$this->isProcessing();
    }

    /**
     * Check if file can be cancelled.
     */
    private function canBeCancelled(): bool
    {
        return in_array($this->status, [
            'uploading',
            'uploaded',
            'validating',
            'validated',
            'validation_failed',
            'pending_approval'
        ]);
    }

    /**
     * Check if file can be deleted.
     */
    private function canBeDeleted(): bool
    {
        return in_array($this->status, [
            'validation_failed',
            'rejected',
            'failed',
            'completed'
        ]) || ($this->status === 'uploaded' && $this->created_at->diffInHours(now()) > 24);
    }

    /**
     * Get estimated processing time in minutes.
     */
    private function getEstimatedProcessingTime(): int
    {
        $baseTime = 2; // 2 minutes base
        $rowMultiplier = max(1, ceil($this->total_rows / 1000)); // 1 minute per 1000 rows
        
        return $baseTime + $rowMultiplier;
    }

    /**
     * Get processing start time.
     */
    private function getProcessingStartTime(): ?string
    {
        // This would typically come from an audit log or status change tracking
        if ($this->isProcessing() || $this->isCompleted()) {
            return $this->updated_at->toISOString();
        }
        
        return null;
    }

    /**
     * Get processing duration in minutes.
     */
    private function getProcessingDuration(): ?int
    {
        if (!$this->isCompleted()) {
            return null;
        }
        
        // This is a simplified calculation - in practice you'd track actual processing times
        return $this->updated_at->diffInMinutes($this->created_at);
    }

    /**
     * Get next possible actions for the file.
     */
    private function getNextPossibleActions(): array
    {
        $actions = [];
        
        if ($this->canBeApproved()) {
            $actions[] = 'approve';
        }
        
        if ($this->canBeRejected()) {
            $actions[] = 'reject';
        }
        
        if ($this->canBeCancelled()) {
            $actions[] = 'cancel';
        }
        
        if ($this->canBeDeleted()) {
            $actions[] = 'delete';
        }
        
        if ($this->status === 'validation_failed') {
            $actions[] = 'revalidate';
        }
        
        return $actions;
    }

    /**
     * Get validation summary for failed files.
     */
    private function getValidationSummary(): array
    {
        if (!$this->hasValidationErrors() || empty($this->validation_errors)) {
            return [];
        }
        
        $errors = $this->validation_errors;
        $summary = [
            'total_errors' => $this->getValidationErrorCount(),
            'error_categories' => [],
            'most_common_errors' => [],
            'affected_rows' => 0,
        ];
        
        // Analyze error types (simplified)
        if (isset($errors['row_errors'])) {
            $summary['error_categories']['row_errors'] = count($errors['row_errors']);
        }
        
        if (isset($errors['structure_errors'])) {
            $summary['error_categories']['structure_errors'] = count($errors['structure_errors']);
        }
        
        if (isset($errors['validation_summary'])) {
            $summary['affected_rows'] = $errors['validation_summary']['error_rows'] ?? $this->error_rows;
        }
        
        return $summary;
    }

    /**
     * Get status changes count (simplified - would need audit table in practice).
     */
    private function getStatusChangesCount(): int
    {
        // This is a placeholder - in practice you'd query an audit/status_changes table
        return 1;
    }

    /**
     * Get last status change time.
     */
    private function getLastStatusChangeTime(): string
    {
        return $this->updated_at->toISOString();
    }

    /**
     * Get readable file size.
     */
    private function getReadableFileSize(): string
    {
        // This is a placeholder - file size would be stored or calculated from actual file
        return 'Unknown';
    }

    /**
     * Get file MIME type.
     */
    private function getFileMimeType(): string
    {
        return 'text/csv';
    }

    /**
     * Check if file is still accessible.
     */
    private function isFileAccessible(): bool
    {
        if (empty($this->file_path)) {
            return false;
        }
        
        return \Illuminate\Support\Facades\Storage::exists($this->file_path);
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
        
        return $symbols[$this->currency] ?? $this->currency;
    }

    /**
     * Get currency decimal places.
     */
    private function getCurrencyDecimalPlaces(): int
    {
        return 2; // All supported currencies use 2 decimal places
    }

    /**
     * Get currency minimum amount.
     */
    private function getCurrencyMinAmount(): float
    {
        $limits = [
            'GBP' => 1.00,
            'EUR' => 1.00,
            'USD' => 1.00,
            'INR' => 100.00,
        ];
        
        return $limits[$this->currency] ?? 1.00;
    }

    /**
     * Get currency maximum amount.
     */
    private function getCurrencyMaxAmount(): float
    {
        $limits = [
            'GBP' => 50000.00,
            'EUR' => 50000.00,
            'USD' => 50000.00,
            'INR' => 5000000.00,
        ];
        
        return $limits[$this->currency] ?? 50000.00;
    }

    /**
     * Check if file requires approval.
     */
    private function requiresApproval(): bool
    {
        // All files require approval in this system
        return true;
    }

    /**
     * Check if file exceeds approval threshold.
     */
    private function exceedsApprovalThreshold(): bool
    {
        $thresholds = [
            'GBP' => 10000.00,
            'EUR' => 10000.00,
            'USD' => 10000.00,
            'INR' => 1000000.00,
        ];
        
        $threshold = $thresholds[$this->currency] ?? 10000.00;
        return (float)$this->total_amount >= $threshold;
    }

    /**
     * Check if file has duplicate beneficiaries.
     */
    private function hasDuplicateBeneficiaries(): bool
    {
        if (!$this->relationLoaded('paymentInstructions')) {
            return false;
        }
        
        $benef