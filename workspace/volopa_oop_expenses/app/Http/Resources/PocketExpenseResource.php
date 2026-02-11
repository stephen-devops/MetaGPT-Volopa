<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class PocketExpenseResource extends JsonResource
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
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'expense_type_id' => $this->expense_type_id,
            'date' => $this->date,
            'merchant_name' => $this->merchant_name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'description' => $this->description,
            'receipt_url' => $this->receipt_url,
            'converted_amount' => $this->converted_amount,
            'converted_currency' => $this->converted_currency,
            'fx_rate' => $this->fx_rate,
            'fx_commission' => $this->fx_commission,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'is_billable' => $this->is_billable,
            'project_code' => $this->project_code,
            'cost_center' => $this->cost_center,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),

            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? '',
                    'email' => $this->user->email ?? '',
                ];
            }),

            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? '',
                    'is_active' => $this->client->is_active ?? false,
                ];
            }),

            'expense_type' => $this->whenLoaded('expenseType', function () {
                return $this->expenseType ? [
                    'id' => $this->expenseType->id,
                    'name' => $this->expenseType->name,
                    'is_active' => $this->expenseType->is_active,
                ] : null;
            }),

            'approver' => $this->whenLoaded('approver', function () {
                return $this->approver ? [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name ?? '',
                    'email' => $this->approver->email ?? '',
                ] : null;
            }),

            'metadata' => $this->whenLoaded('metadata', function () {
                return $this->metadata->map(function ($metadata) {
                    return [
                        'id' => $metadata->id,
                        'metadata_type' => $metadata->metadata_type,
                        'value' => $metadata->value,
                        'label' => $metadata->label,
                        'details_json' => $metadata->details_json,
                        'is_required' => $metadata->is_required,
                        'is_editable' => $metadata->is_editable,
                        'sort_order' => $metadata->sort_order,
                        'category_id' => $metadata->category_id,
                        'source_id' => $metadata->source_id,
                        'country_id' => $metadata->country_id,
                        'reference_type' => $metadata->reference_type,
                        'reference_id' => $metadata->reference_id,
                        'display_value' => $metadata->getDisplayValue(),
                        'created_at' => $metadata->created_at?->toISOString(),
                        'updated_at' => $metadata->updated_at?->toISOString(),
                    ];
                });
            }),

            // Computed fields
            'status_display' => $this->getStatusDisplayText(),
            'effective_amount' => $this->getEffectiveAmount(),
            'effective_currency' => $this->getEffectiveCurrency(),
            'formatted_amount' => $this->getFormattedAmount(),
            'formatted_converted_amount' => $this->getFormattedConvertedAmount(),
            'has_fx_conversion' => $this->hasFxConversion(),

            // Status flags
            'can_be_edited' => $this->canBeEdited(),
            'can_be_approved' => $this->canBeApproved(),
            'can_be_rejected' => $this->canBeRejected(),
            'can_be_deleted' => $this->canBeDeleted(),

            // Expense metadata
            'expense_meta' => [
                'is_pending' => $this->isPending(),
                'is_approved' => $this->isApproved(),
                'is_rejected' => $this->isRejected(),
                'is_processing' => $this->isProcessing(),
                'is_billable' => $this->isBillable(),
                'has_receipt' => !empty($this->receipt_url),
                'has_project_code' => !empty($this->project_code),
                'has_cost_center' => !empty($this->cost_center),
                'has_description' => !empty($this->description),
                'has_metadata' => $this->whenLoaded('metadata', function () {
                    return $this->metadata->isNotEmpty();
                }, false),
                'metadata_count' => $this->whenLoaded('metadata', function () {
                    return $this->metadata->count();
                }, 0),
                'days_since_created' => $this->getDaysSinceCreated(),
                'days_since_expense_date' => $this->getDaysSinceExpenseDate(),
                'amount_range' => $this->getAmountRange(),
                'is_high_value' => $this->isHighValue(),
                'requires_approval' => $this->requiresApproval(),
                'has_category_metadata' => $this->hasCategoryMetadata(),
                'has_source_metadata' => $this->hasSourceMetadata(),
                'has_location_metadata' => $this->hasLocationMetadata(),
                'has_tax_metadata' => $this->hasTaxMetadata(),
                'has_custom_metadata' => $this->hasCustomMetadata(),
            ],

            // FX conversion details
            'fx_conversion' => $this->when($this->hasFxConversion(), function () {
                return [
                    'original_amount' => $this->amount,
                    'original_currency' => $this->currency,
                    'converted_amount' => $this->converted_amount,
                    'converted_currency' => $this->converted_currency,
                    'fx_rate' => $this->fx_rate,
                    'fx_commission' => $this->fx_commission,
                    'commission_amount' => $this->getCommissionAmount(),
                    'conversion_date' => $this->created_at?->toISOString(),
                ];
            }),

            // Approval details
            'approval' => $this->when($this->isApproved() || $this->isRejected(), function () {
                return [
                    'status' => $this->status,
                    'approved_by' => $this->approved_by,
                    'approved_at' => $this->approved_at?->toISOString(),
                    'rejection_reason' => $this->rejection_reason,
                    'approver_name' => $this->whenLoaded('approver', function () {
                        return $this->approver->name ?? 'Unknown';
                    }),
                    'days_to_approval' => $this->getDaysToApproval(),
                ];
            }),

            // Metadata summary
            'metadata_summary' => $this->whenLoaded('metadata', function () {
                return [
                    'total_count' => $this->metadata->count(),
                    'by_type' => $this->metadata->groupBy('metadata_type')->map->count(),
                    'required_count' => $this->metadata->where('is_required', true)->count(),
                    'editable_count' => $this->metadata->where('is_editable', true)->count(),
                    'completeness' => $this->getMetadataCompleteness(),
                ];
            }),

            // User permissions for current user
            'user_permissions' => [
                'can_edit' => $this->userCanEdit($request),
                'can_delete' => $this->userCanDelete($request),
                'can_approve' => $this->userCanApprove($request),
                'can_reject' => $this->userCanReject($request),
                'can_reprocess' => $this->userCanReprocess($request),
                'can_manage_metadata' => $this->userCanManageMetadata($request),
                'is_owner' => $this->isOwner($request),
            ],
        ];
    }

    /**
     * Get the display text for the expense status.
     *
     * @return string
     */
    private function getStatusDisplayText(): string
    {
        return match ($this->status) {
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'processing' => 'Processing',
            default => ucfirst($this->status ?? 'Unknown'),
        };
    }

    /**
     * Get the number of days since the expense was created.
     *
     * @return int
     */
    private function getDaysSinceCreated(): int
    {
        if (!$this->created_at) {
            return 0;
        }

        return (int) $this->created_at->diffInDays(Carbon::now());
    }

    /**
     * Get the number of days since the expense date.
     *
     * @return int
     */
    private function getDaysSinceExpenseDate(): int
    {
        if (!$this->date) {
            return 0;
        }

        $expenseDate = Carbon::parse($this->date);
        return (int) $expenseDate->diffInDays(Carbon::now());
    }

    /**
     * Get the amount range category.
     *
     * @return string
     */
    private function getAmountRange(): string
    {
        $amount = $this->getEffectiveAmount();

        if ($amount < 50) {
            return 'low';
        } elseif ($amount < 500) {
            return 'medium';
        } elseif ($amount < 2000) {
            return 'high';
        } else {
            return 'very_high';
        }
    }

    /**
     * Check if the expense is high value.
     *
     * @return bool
     */
    private function isHighValue(): bool
    {
        $amount = $this->getEffectiveAmount();
        return $amount >= 1000; // Configurable threshold
    }

    /**
     * Check if the expense requires approval.
     *
     * @return bool
     */
    private function requiresApproval(): bool
    {
        // All expenses require approval unless auto-approved
        return $this->status === 'pending';
    }

    /**
     * Get the commission amount for FX conversion.
     *
     * @return float
     */
    private function getCommissionAmount(): float
    {
        if (!$this->hasFxConversion()) {
            return 0.0;
        }

        return round($this->amount * $this->fx_rate * $this->fx_commission, 2);
    }

    /**
     * Get the number of days to approval.
     *
     * @return int|null
     */
    private function getDaysToApproval(): ?int
    {
        if (!$this->approved_at || !$this->created_at) {
            return null;
        }

        return (int) $this->created_at->diffInDays($this->approved_at);
    }

    /**
     * Get metadata completeness percentage.
     *
     * @return float
     */
    private function getMetadataCompleteness(): float
    {
        if (!$this->relationLoaded('metadata')) {
            return 0.0;
        }

        $requiredMetadata = $this->metadata->where('is_required', true);
        if ($requiredMetadata->isEmpty()) {
            return 100.0;
        }

        $completedMetadata = $requiredMetadata->filter(function ($metadata) {
            return !empty($metadata->value) || 
                   !empty($metadata->details_json) || 
                   !empty($metadata->category_id) || 
                   !empty($metadata->source_id) || 
                   !empty($metadata->country_id);
        });

        return round(($completedMetadata->count() / $requiredMetadata->count()) * 100, 1);
    }

    /**
     * Check if the current user can edit this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanEdit(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can('update', $this->resource) : false;
    }

    /**
     * Check if the current user can delete this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanDelete(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can('delete', $this->resource) : false;
    }

    /**
     * Check if the current user can approve this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanApprove(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can('approve', $this->resource) : false;
    }

    /**
     * Check if the current user can reject this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanReject(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can('reject', $this->resource) : false;
    }

    /**
     * Check if the current user can reprocess this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanReprocess(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can('reprocess', $this->resource) : false;
    }

    /**
     * Check if the current user can manage metadata for this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanManageMetadata(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return method_exists($user, 'can') ? $user->can('manageMetadata', $this->resource) : false;
    }

    /**
     * Check if the current user is the owner of this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function isOwner(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return $this->user_id === $user->id;
    }

    /**
     * Customize the outgoing response for the resource.
     *
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'PocketExpense');
        $response->header('X-Resource-Version', '1.0');
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
                'resource_type' => 'pocket_expense',
                'version' => '1.0',
                'timestamp' => Carbon::now()->toISOString(),
                'currency_info' => [
                    'original_currency' => $this->currency,
                    'converted_currency' => $this->converted_currency,
                    'has_conversion' => $this->hasFxConversion(),
                ],
                'status_info' => [
                    'current_status' => $this->status,
                    'is_editable' => $this->canBeEdited(),
                    'is_approvable' => $this->canBeApproved(),
                ],
                'metadata_info' => [
                    'has_metadata' => $this->relationLoaded('metadata') && $this->metadata->isNotEmpty(),
                    'metadata_count' => $this->relationLoaded('metadata') ? $this->metadata->count() : 0,
                    'completeness_percentage' => $this->relationLoaded('metadata') ? $this->getMetadataCompleteness() : null,
                ],
            ],
        ];
    }

    /**
     * Create a new resource collection.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'resource_type' => 'pocket_expense_collection',
                'version' => '1.0',
                'timestamp' => Carbon::now()->toISOString(),
                'collection_summary' => [
                    'total_items' => $resource instanceof \Illuminate\Pagination\LengthAwarePaginator ? 
                        $resource->total() : $resource->count(),
                    'has_pagination' => $resource instanceof \Illuminate\Pagination\LengthAwarePaginator,
                ],
            ],
        ]);
    }
}