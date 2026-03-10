<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * PocketExpenseResource
 * 
 * API resource for transforming PocketExpense models into JSON responses.
 * This resource shapes the output of pocket expense data for API consumers,
 * hiding internal fields and providing computed attributes. Follows the mental model:
 * Controller -> Service -> Model -> API Resource -> JSON with correct status codes.
 * 
 * Key responsibilities:
 * - Transform PocketExpense model data into API-friendly format
 * - Hide sensitive internal fields and database implementation details
 * - Include computed attributes and relationship data
 * - Provide consistent JSON structure across all expense endpoints
 * - Support conditional field inclusion based on request context
 * - Format dates, amounts, and currency according to API standards
 * - Include expense status transitions and workflow information
 * - Format expense metadata and file attachments
 */
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
            'uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'date' => $this->date?->format('Y-m-d'),
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'expense_type' => $this->expense_type,
            'currency' => $this->currency,
            'amount' => (float) $this->amount,
            'merchant_address' => $this->merchant_address,
            'vat_amount' => $this->vat_amount ? (float) $this->vat_amount : null,
            'notes' => $this->notes,
            'status' => $this->status,

            // Computed amount fields
            'amount_info' => [
                'original_amount' => (float) $this->amount,
                'signed_amount' => $this->getSignedAmount(),
                'absolute_amount' => $this->getAbsoluteAmount(),
                'formatted_amount' => $this->getFormattedAmount(),
                'formatted_vat_amount' => $this->getFormattedVatAmount(),
                'total_amount' => $this->getTotalAmount(),
                'formatted_total_amount' => $this->getFormattedTotalAmount(),
                'currency_symbol' => $this->getCurrencySymbol(),
            ],

            // Status and workflow information
            'status_info' => [
                'current_status' => $this->status,
                'is_draft' => $this->isDraft(),
                'is_submitted' => $this->isSubmitted(),
                'is_approved' => $this->isApproved(),
                'is_rejected' => $this->isRejected(),
                'can_be_edited' => $this->canBeEdited(),
                'can_be_deleted' => $this->canBeDeleted(),
                'can_be_submitted' => $this->canBeSubmitted(),
                'can_be_approved' => $this->canBeApproved(),
                'can_be_rejected' => $this->canBeRejected(),
                'available_status_transitions' => $this->getAvailableStatusTransitions(),
                'status_display_name' => ucfirst(str_replace('_', ' ', $this->status)),
            ],

            // Relationship data
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'role' => $this->user->role,
                ];
            }),

            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'code' => $this->client->code ?? null,
                ];
            }),

            'expense_type_info' => $this->whenLoaded('expenseType', function () {
                return [
                    'id' => $this->expenseType->id,
                    'option' => $this->expenseType->option,
                    'amount_sign' => $this->expenseType->amount_sign,
                    'is_positive' => $this->expenseType->isPositive(),
                    'is_negative' => $this->expenseType->isNegative(),
                    'is_refund' => $this->expenseType->isRefund(),
                    'description' => $this->expenseType->getDescription(),
                    'amount_multiplier' => $this->expenseType->getAmountMultiplier(),
                ];
            }),

            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                    'email' => $this->createdBy->email,
                    'role' => $this->createdBy->role,
                ];
            }),

            'updated_by' => $this->when($this->updated_by_user_id, function () {
                return $this->whenLoaded('updatedBy', function () {
                    return [
                        'id' => $this->updatedBy->id,
                        'name' => $this->updatedBy->name,
                        'email' => $this->updatedBy->email,
                        'role' => $this->updatedBy->role,
                    ];
                });
            }),

            'approved_by' => $this->when($this->approved_by_user_id, function () {
                return $this->whenLoaded('approvedBy', function () {
                    return [
                        'id' => $this->approvedBy->id,
                        'name' => $this->approvedBy->name,
                        'email' => $this->approvedBy->email,
                        'role' => $this->approvedBy->role,
                    ];
                });
            }),

            // Metadata information
            'metadata' => $this->whenLoaded('metadata', function () {
                return $this->metadata->map(function ($metadata) {
                    return [
                        'id' => $metadata->id,
                        'metadata_type' => $metadata->metadata_type,
                        'display_value' => $metadata->getDisplayValue(),
                        'description' => $metadata->getDescription(),
                        'is_category' => $metadata->isCategory(),
                        'is_tracking_code' => $metadata->isTrackingCode(),
                        'is_project' => $metadata->isProject(),
                        'is_file_attachment' => $metadata->isFileAttachment(),
                        'is_expense_source' => $metadata->isExpenseSource(),
                        'is_additional_field' => $metadata->isAdditionalField(),
                        'details_json' => $metadata->details_json,
                        'created_at' => $metadata->create_time?->toISOString(),
                        'updated_at' => $metadata->update_time?->toISOString(),
                    ];
                });
            }),

            // Expense source information
            'expense_source' => $this->getExpenseSourceInfo(),

            // File attachments
            'file_attachments' => $this->getFileAttachmentsInfo(),

            // Expense context for current user
            'expense_context' => [
                'belongs_to_current_user' => $this->belongsToUser(auth()->user()->id ?? 0),
                'belongs_to_current_client' => $this->belongsToClient(auth()->user()->client_id ?? 0),
                'was_created_by_current_user' => $this->wasCreatedBy(auth()->user()->id ?? 0),
                'was_approved_by_current_user' => $this->wasApprovedBy(auth()->user()->id ?? 0),
                'can_current_user_edit' => $this->canCurrentUserEdit(),
                'can_current_user_delete' => $this->canCurrentUserDelete(),
                'can_current_user_approve' => $this->canCurrentUserApprove(),
            ],

            // Audit information
            'audit' => [
                'created_at' => $this->create_time?->toISOString(),
                'updated_at' => $this->update_time?->toISOString(),
                'created_by' => $this->whenLoaded('createdBy', $this->createdBy->name ?? 'Unknown'),
                'updated_by' => $this->when($this->updated_by_user_id, function () {
                    return $this->whenLoaded('updatedBy', $this->updatedBy->name ?? 'Unknown');
                }),
                'approved_by' => $this->when($this->approved_by_user_id, function () {
                    return $this->whenLoaded('approvedBy', $this->approvedBy->name ?? 'Unknown');
                }),
                'expense_age_days' => $this->create_time ? 
                    $this->create_time->diffInDays(now()) : 0,
                'last_update_days_ago' => $this->update_time ? 
                    $this->update_time->diffInDays(now()) : 0,
            ],

            // Additional computed fields
            'computed_fields' => [
                'description' => $this->getDescription(),
                'is_active' => $this->isActive(),
                'has_vat' => $this->vat_amount !== null && $this->vat_amount > 0,
                'has_notes' => !empty($this->notes),
                'has_merchant_address' => !empty($this->merchant_address),
                'has_merchant_description' => !empty($this->merchant_description),
                'expense_category' => $this->getExpenseCategory(),
                'expense_age_category' => $this->getExpenseAgeCategory(),
                'amount_category' => $this->getAmountCategory(),
            ],

            // Timestamps (using custom timestamp fields)
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
        ];
    }

    /**
     * Get expense source information from metadata.
     *
     * @return array<string, mixed>|null
     */
    private function getExpenseSourceInfo(): ?array
    {
        if (!$this->relationLoaded('metadata')) {
            return null;
        }

        $sourceMetadata = $this->getExpenseSource();
        if (!$sourceMetadata || !$sourceMetadata->expenseSource) {
            return null;
        }

        $source = $sourceMetadata->expenseSource;
        
        return [
            'id' => $source->id,
            'uuid' => $source->uuid,
            'name' => $source->name,
            'is_default' => $source->is_default,
            'is_global' => $source->isGlobal(),
            'is_client_specific' => $source->isClientSpecific(),
            'source_note' => $sourceMetadata->details_json['source_note'] ?? null,
        ];
    }

    /**
     * Get file attachments information from metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFileAttachmentsInfo(): array
    {
        if (!$this->relationLoaded('metadata')) {
            return [];
        }

        $fileAttachments = $this->getAttachedFiles();
        
        return $fileAttachments->map(function ($attachment) {
            $fileStore = $attachment->fileStore ?? null;
            
            return [
                'metadata_id' => $attachment->id,
                'file_store_id' => $attachment->file_store_id,
                'file_name' => $fileStore->file_name ?? 'Unknown File',
                'file_size' => $fileStore->file_size ?? null,
                'file_type' => $fileStore->file_type ?? null,
                'uploaded_at' => $attachment->create_time?->toISOString(),
            ];
        })->toArray();
    }

    /**
     * Check if current user can edit this expense.
     *
     * @return bool
     */
    private function canCurrentUserEdit(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        
        // Basic editability check
        if (!$this->canBeEdited()) {
            return false;
        }

        // Primary Admins and Admins can edit any expense within their client
        if (in_array($user->role, ['Primary Administrator', 'Admin'])) {
            return $this->belongsToClient($user->client_id);
        }

        // Users can edit their own expenses
        if ($this->belongsToUser($user->id)) {
            return true;
        }

        // Additional business logic for managers/delegated users would go here
        return false;
    }

    /**
     * Check if current user can delete this expense.
     *
     * @return bool
     */
    private function canCurrentUserDelete(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        
        // Basic deletability check
        if (!$this->canBeDeleted()) {
            return false;
        }

        // Primary Admins can delete any expense within their client
        if ($user->role === 'Primary Administrator') {
            return $this->belongsToClient($user->client_id);
        }

        // Admins can delete non-approved expenses within their client
        if ($user->role === 'Admin') {
            return $this->belongsToClient($user->client_id) && !$this->isApproved();
        }

        // Users can delete their own non-approved expenses
        if ($this->belongsToUser($user->id)) {
            return !$this->isApproved();
        }

        return false;
    }

    /**
     * Check if current user can approve this expense.
     *
     * @return bool
     */
    private function canCurrentUserApprove(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        
        // Basic approvability check
        if (!$this->canBeApproved()) {
            return false;
        }

        // Users cannot approve their own expenses
        if ($this->belongsToUser($user->id)) {
            return false;
        }

        // Primary Admins and Admins can approve expenses within their client
        if (in_array($user->role, ['Primary Administrator', 'Admin'])) {
            return $this->belongsToClient($user->client_id);
        }

        // Additional business logic for delegated approvers would go here
        return false;
    }

    /**
     * Get currency symbol for the expense currency.
     *
     * @return string
     */
    private function getCurrencySymbol(): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'CHF' => 'CHF',
            'CNY' => '¥',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
            'HUF' => 'Ft'
        ];
        
        return $symbols[$this->currency] ?? $this->currency;
    }

    /**
     * Get expense category based on amount and type.
     *
     * @return string
     */
    private function getExpenseCategory(): string
    {
        $amount = abs($this->amount);
        
        if ($amount < 50) {
            return 'small';
        } elseif ($amount < 500) {
            return 'medium';
        } elseif ($amount < 2000) {
            return 'large';
        } else {
            return 'very_large';
        }
    }

    /**
     * Get expense age category.
     *
     * @return string
     */
    private function getExpenseAgeCategory(): string
    {
        if (!$this->create_time) {
            return 'unknown';
        }

        $daysOld = $this->create_time->diffInDays(now());
        
        if ($daysOld === 0) {
            return 'today';
        } elseif ($daysOld <= 7) {
            return 'this_week';
        } elseif ($daysOld <= 30) {
            return 'this_month';
        } elseif ($daysOld <= 90) {
            return 'this_quarter';
        } else {
            return 'older';
        }
    }

    /**
     * Get amount category based on value ranges.
     *
     * @return string
     */
    private function getAmountCategory(): string
    {
        $amount = abs($this->amount);
        
        if ($amount <= 25) {
            return 'minimal';
        } elseif ($amount <= 100) {
            return 'low';
        } elseif ($amount <= 500) {
            return 'moderate';
        } elseif ($amount <= 1000) {
            return 'high';
        } else {
            return 'significant';
        }
    }

    /**
     * Get additional attributes to include in response.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'pocket_expense',
                'api_version' => '1.0',
                'generated_at' => now()->toISOString(),
                'client_timezone' => $request->header('X-Client-Timezone', 'UTC'),
                'includes' => $this->getLoadedRelations(),
            ],
        ];
    }

    /**
     * Get loaded relations for debugging.
     *
     * @return array<string, bool>
     */
    private function getLoadedRelations(): array
    {
        return [
            'user' => $this->relationLoaded('user'),
            'client' => $this->relationLoaded('client'),
            'expense_type' => $this->relationLoaded('expenseType'),
            'metadata' => $this->relationLoaded('metadata'),
            'created_by' => $this->relationLoaded('createdBy'),
            'updated_by' => $this->relationLoaded('updatedBy'),
            'approved_by' => $this->relationLoaded('approvedBy'),
        ];
    }

    /**
     * Customize the response for this resource.
     * 
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'PocketExpense');
        $response->header('X-API-Version', '1.0');
        
        // Add cache headers for GET requests
        if ($request->isMethod('GET')) {
            $response->header('Cache-Control', 'private, max-age=300'); // 5 minutes
            $response->header('ETag', md5($this->updated_at . $this->id));
        }
    }

    /**
     * Get the JSON serialization options that should be applied to the resource response.
     *
     * @return int
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;
    }
}