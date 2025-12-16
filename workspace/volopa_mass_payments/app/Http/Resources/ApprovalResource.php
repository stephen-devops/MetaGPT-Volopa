## Code: app/Http/Resources/ApprovalResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\FileResource;
use Carbon\Carbon;

class ApprovalResource extends JsonResource
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
            'status' => $this->status,
            'status_display' => $this->getStatusDisplayName(),
            
            // Approval details
            'approval_details' => [
                'approver_id' => $this->approver_id,
                'approver_name' => $this->approver->name ?? 'Unknown Approver',
                'approver_email' => $this->approver->email ?? null,
                'approved_at' => $this->approved_at?->toISOString(),
                'approved_at_human' => $this->approved_at?->diffForHumans(),
                'comments' => $this->comments,
                'has_comments' => !empty(trim($this->comments ?? '')),
            ],
            
            // Payment file information (summary)
            'payment_file' => [
                'id' => $this->payment_file_id,
                'filename' => $this->paymentFile->original_name ?? 'Unknown File',
                'total_amount' => $this->paymentFile->total_amount ?? 0.00,
                'formatted_amount' => $this->getFormattedAmount(),
                'currency' => $this->paymentFile->currency ?? 'USD',
                'valid_records' => $this->paymentFile->valid_records ?? 0,
                'invalid_records' => $this->paymentFile->invalid_records ?? 0,
                'file_status' => $this->paymentFile->status ?? 'unknown',
                'uploaded_by' => $this->paymentFile->user->name ?? 'Unknown User',
                'uploaded_by_id' => $this->paymentFile->user_id ?? null,
                'uploaded_at' => $this->paymentFile->created_at?->toISOString(),
                'uploaded_at_human' => $this->paymentFile->created_at?->diffForHumans(),
            ],
            
            // Approval workflow information
            'workflow' => [
                'is_pending' => $this->isPending(),
                'is_approved' => $this->isApproved(),
                'is_rejected' => $this->isRejected(),
                'is_expired' => $this->isExpired(),
                'can_be_approved' => $this->canBeApproved($request),
                'can_be_rejected' => $this->canBeRejected($request),
                'approval_deadline' => $this->getApprovalDeadline()?->toISOString(),
                'approval_deadline_human' => $this->getApprovalDeadline()?->diffForHumans(),
                'time_remaining' => $this->getTimeRemaining(),
                'priority' => $this->getApprovalPriority(),
            ],
            
            // Risk assessment
            'risk_assessment' => [
                'priority' => $this->getApprovalPriority(),
                'risk_level' => $this->getRiskLevel(),
                'risk_factors' => $this->getRiskFactors(),
                'requires_urgent_attention' => $this->requiresUrgentAttention(),
                'has_validation_errors' => $this->hasValidationErrors(),
                'has_critical_errors' => $this->hasCriticalErrors(),
            ],
            
            // Timestamps
            'timestamps' => [
                'created_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
                'approved_at' => $this->approved_at?->toISOString(),
                'created_at_human' => $this->created_at?->diffForHumans(),
                'updated_at_human' => $this->updated_at?->diffForHumans(),
                'approved_at_human' => $this->approved_at?->diffForHumans(),
            ],
            
            // Full payment file details (conditionally loaded)
            'full_payment_file' => new FileResource($this->whenLoaded('paymentFile')),
            
            // Approver details (conditionally loaded)
            'approver' => $this->when(
                $this->relationLoaded('approver'),
                function () {
                    return [
                        'id' => $this->approver->id,
                        'name' => $this->approver->name,
                        'email' => $this->approver->email,
                        'role' => $this->approver->role ?? 'approver',
                        'department' => $this->approver->department ?? null,
                        'last_login' => $this->approver->last_login_at?->toISOString(),
                        'is_active' => $this->approver->is_active ?? true,
                    ];
                }
            ),
            
            // Actions available to current user
            'actions' => $this->getAvailableActions($request),
            
            // API links
            'links' => [
                'self' => route('api.v1.approvals.show', $this->id),
                'approve' => $this->when(
                    $this->canBeApproved($request),
                    route('api.v1.approvals.approve', $this->id)
                ),
                'reject' => $this->when(
                    $this->canBeRejected($request),
                    route('api.v1.approvals.reject', $this->id)
                ),
                'payment_file' => route('api.v1.files.show', $this->payment_file_id),
            ],
            
            // Metadata for frontend
            'meta' => [
                'approval_age_hours' => $this->getApprovalAgeInHours(),
                'approval_age_days' => $this->getApprovalAgeInDays(),
                'is_overdue' => $this->isOverdue(),
                'escalation_required' => $this->requiresEscalation(),
                'business_impact' => $this->getBusinessImpactLevel(),
                'processing_complexity' => $this->getProcessingComplexity(),
            ],
        ];
    }

    /**
     * Get display name for the approval status.
     *
     * @return string
     */
    private function getStatusDisplayName(): string
    {
        $statusDisplayNames = [
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];

        return $statusDisplayNames[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get formatted amount with currency symbol.
     *
     * @return string
     */
    private function getFormattedAmount(): string
    {
        if (!$this->paymentFile) {
            return '$0.00';
        }

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
        ];

        $currency = $this->paymentFile->currency ?? 'USD';
        $amount = $this->paymentFile->total_amount ?? 0.00;
        $symbol = $symbols[$currency] ?? '';
        
        return $symbol . number_format($amount, 2);
    }

    /**
     * Check if approval is pending.
     *
     * @return bool
     */
    private function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if approval is approved.
     *
     * @return bool
     */
    private function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if approval is rejected.
     *
     * @return bool
     */
    private function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if approval has expired.
     *
     * @return bool
     */
    private function isExpired(): bool
    {
        $deadline = $this->getApprovalDeadline();
        return $deadline && Carbon::now()->isAfter($deadline);
    }

    /**
     * Get approval deadline (72 hours from creation).
     *
     * @return Carbon|null
     */
    private function getApprovalDeadline(): ?Carbon
    {
        return $this->created_at?->addHours(72);
    }

    /**
     * Get time remaining for approval.
     *
     * @return string
     */
    private function getTimeRemaining(): string
    {
        if (!$this->isPending()) {
            return 'N/A';
        }

        $deadline = $this->getApprovalDeadline();
        if (!$deadline) {
            return 'Unknown';
        }

        $now = Carbon::now();
        if ($now->isAfter($deadline)) {
            return 'Expired';
        }

        return $deadline->diffForHumans($now);
    }

    /**
     * Get approval priority based on amount and risk factors.
     *
     * @return string
     */
    private function getApprovalPriority(): string
    {
        if (!$this->paymentFile) {
            return 'low';
        }

        $amount = $this->paymentFile->total_amount ?? 0.00;
        
        // High priority thresholds
        $highPriorityThresholds = [
            'USD' => 50000.00,
            'EUR' => 42500.00,
            'GBP' => 37500.00,
        ];

        // Medium priority thresholds
        $mediumPriorityThresholds = [
            'USD' => 10000.00,
            'EUR' => 8500.00,
            'GBP' => 7500.00,
        ];

        $currency = $this->paymentFile->currency ?? 'USD';
        $highThreshold = $highPriorityThresholds[$currency] ?? 50000.00;
        $mediumThreshold = $mediumPriorityThresholds[$currency] ?? 10000.00;

        if ($amount >= $highThreshold) {
            return 'high';
        } elseif ($amount >= $mediumThreshold) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get risk level for the approval.
     *
     * @return string
     */
    private function getRiskLevel(): string
    {
        $riskFactors = $this->getRiskFactors();
        $riskCount = count(array_filter($riskFactors));

        if ($riskCount >= 3) {
            return 'high';
        } elseif ($riskCount >= 1) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get risk factors for the approval.
     *
     * @return array<string, bool>
     */
    private function getRiskFactors(): array
    {
        if (!$this->paymentFile) {
            return [];
        }

        $factors = [];
        $amount = $this->paymentFile->total_amount ?? 0.00;
        $validRecords = $this->paymentFile->valid_records ?? 0;
        $invalidRecords = $this->paymentFile->invalid_records ?? 0;
        $totalRecords = $validRecords + $invalidRecords;

        // High value transactions
        $factors['high_value'] = $amount >= 100000.00;

        // Large volume of transactions
        $factors['high_volume'] = $validRecords > 1000;

        // High error rate
        $factors['high_error_rate'] = $totalRecords > 0 && ($invalidRecords / $totalRecords) > 0.1;

        // Foreign currency
        $factors['foreign_currency'] = ($this->paymentFile->currency ?? 'USD') !== 'USD';

        // Has validation errors
        $factors['has_validation_errors'] = $this->hasValidationErrors();

        // Multiple settlement methods (would need to check payment instructions)
        $factors['complex_settlement'] = false; // Simplified for now

        return $factors;
    }

    /**
     * Check if approval requires urgent attention.
     *
     * @return bool
     */
    private function requiresUrgentAttention(): bool
    {
        return $this->getApprovalPriority() === 'high' || 
               $this->isOverdue() || 
               $this->getRiskLevel() === 'high';
    }

    /**
     * Check if payment file has validation errors.
     *
     * @return bool
     */
    private function hasValidationErrors(): bool
    {
        if (!$this->paymentFile) {
            return false;
        }

        return $this->paymentFile->invalid_records > 0;
    }

    /**
     * Check if payment file has critical errors.
     *
     * @return bool
     */
    private function hasCriticalErrors(): bool
    {
        // This would require loading validation errors relationship
        // For now, simplified logic based on invalid records
        if (!$this->paymentFile) {
            return false;
        }

        $totalRecords = $this->paymentFile->valid_records + $this->paymentFile->invalid_records;
        return $totalRecords > 0 && ($this->paymentFile->invalid_records / $totalRecords) > 0.5;
    }

    /**
     * Check if approval can be approved by current user.
     *
     * @param Request $request
     * @return bool
     */
    private function canBeApproved(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }

        return $this->isPending() && 
               !$this->isExpired() && 
               $user->can('approve', $this->resource) &&
               !$this->hasCriticalErrors();
    }

    /**
     * Check if approval can be rejected by current user.
     *
     * @param Request $request
     * @return bool
     */
    private function canBeRejected(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }

        return $this->isPending() && 
               !$this->isExpired() && 
               $user->can('approve', $this->resource);
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
            'can_view' => $user ? $user->can('view', $this->resource) : false,
            'can_approve' => $this->canBeApproved($request),
            'can_reject' => $this->canBeRejected($request),
            'can_comment' => $user ? $user->can('approve', $this->resource) : false,
            'can_escalate' => $user ? $user->can('escalate', $this->resource) : false,
            'can_view_file' => $user && $this->paymentFile ? $user->can('view', $this->paymentFile) : false,
        ];
    }

    /**
     * Get approval age in hours.
     *
     * @return int
     */
    private function getApprovalAgeInHours(): int
    {
        return $this->created_at?->diffInHours(Carbon::now()) ?? 0;
    }

    /**
     * Get approval age in days.
     *
     * @return int
     */
    private function getApprovalAgeIn