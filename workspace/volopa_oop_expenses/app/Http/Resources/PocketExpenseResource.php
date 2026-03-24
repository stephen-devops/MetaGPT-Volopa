<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for PocketExpense model
 * 
 * Transforms PocketExpense model data for API responses, hiding internal fields
 * and providing consistent response format. Includes nested metadata relationships
 * and user-friendly field formatting.
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
            'date' => $this->date,
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'merchant_address' => $this->merchant_address,
            'currency' => $this->currency,
            'amount' => $this->formatAmount($this->amount),
            'vat_amount' => $this->formatVatAmount($this->vat_amount),
            'notes' => $this->notes,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplay($this->status),
            
            // Expense type information
            'expense_type' => [
                'id' => $this->expenseType->id ?? null,
                'option' => $this->expenseType->option ?? null,
                'amount_sign' => $this->expenseType->amount_sign ?? null,
            ],
            
            // User relationships
            'user' => [
                'id' => $this->user->id ?? null,
                'name' => $this->user->name ?? null,
            ],
            
            'client' => [
                'id' => $this->client->id ?? null,
                'name' => $this->client->name ?? null,
            ],
            
            'created_by' => [
                'id' => $this->createdBy->id ?? null,
                'name' => $this->createdBy->name ?? null,
            ],
            
            'updated_by' => $this->when($this->updated_by_user_id, [
                'id' => $this->updatedBy->id ?? null,
                'name' => $this->updatedBy->name ?? null,
            ]),
            
            'approved_by' => $this->when($this->approved_by_user_id, [
                'id' => $this->approvedBy->id ?? null,
                'name' => $this->approvedBy->name ?? null,
            ]),
            
            // Metadata relationships
            'metadata' => PocketExpenseMetadataResource::collection($this->whenLoaded('metadata')),
            
            // Audit timestamps (using Volopa legacy format)
            'create_time' => $this->create_time ? $this->create_time->toISOString() : null,
            'update_time' => $this->update_time ? $this->update_time->toISOString() : null,
            
            // Calculated fields
            'can_edit' => $this->canEdit($request),
            'can_approve' => $this->canApprove($request),
            'can_delete' => $this->canDelete($request),
            
            // FX conversion information (when available)
            'fx_info' => $this->when($this->fx_rate ?? false, [
                'base_currency' => $this->base_currency ?? null,
                'base_amount' => $this->base_amount ? $this->formatAmount($this->base_amount) : null,
                'fx_rate' => $this->fx_rate ? number_format($this->fx_rate, 6) : null,
                'fx_date' => $this->fx_date ?? null,
                'commission_rate' => $this->commission_rate ? number_format($this->commission_rate, 4) : null,
            ]),
        ];
    }

    /**
     * Format amount with proper decimal places and sign handling.
     *
     * @param float|null $amount
     * @return string|null
     */
    private function formatAmount(?float $amount): ?string
    {
        if ($amount === null) {
            return null;
        }
        
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format VAT amount as percentage with proper decimal places.
     *
     * @param float|null $vatAmount
     * @return string|null
     */
    private function formatVatAmount(?float $vatAmount): ?string
    {
        if ($vatAmount === null) {
            return null;
        }
        
        return number_format($vatAmount, 2, '.', '') . '%';
    }

    /**
     * Get human-readable status display text.
     *
     * @param string $status
     * @return string
     */
    private function getStatusDisplay(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'submitted' => 'Submitted for Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($status),
        };
    }

    /**
     * Determine if the current user can edit this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function canEdit(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }
        
        // Only draft and rejected expenses can be edited
        if (!in_array($this->status, ['draft', 'rejected'])) {
            return false;
        }
        
        // Owner can always edit their own expenses
        if ($this->user_id === $user->id) {
            return true;
        }
        
        // Check if user has management permission for this expense owner
        // This would typically be checked via a policy, but we're providing
        // a basic implementation here for the resource
        return $this->hasManagementPermission($user);
    }

    /**
     * Determine if the current user can approve this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function canApprove(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }
        
        // Only submitted expenses can be approved
        if ($this->status !== 'submitted') {
            return false;
        }
        
        // Users cannot approve their own expenses
        if ($this->user_id === $user->id) {
            return false;
        }
        
        // Business User and Card User cannot approve expenses even with management rights
        // This would typically be checked via user roles and policies
        return $this->hasApprovalPermission($user);
    }

    /**
     * Determine if the current user can delete this expense.
     *
     * @param Request $request
     * @return bool
     */
    private function canDelete(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }
        
        // Approved expenses typically cannot be deleted
        if ($this->status === 'approved') {
            return false;
        }
        
        // Owner can delete their own draft/rejected expenses
        if ($this->user_id === $user->id && in_array($this->status, ['draft', 'rejected'])) {
            return true;
        }
        
        // Check management permission for other statuses
        return $this->hasManagementPermission($user);
    }

    /**
     * Check if user has management permission for this expense.
     *
     * @param mixed $user
     * @return bool
     */
    private function hasManagementPermission($user): bool
    {
        // This is a simplified implementation
        // In a real application, this would check the user_feature_permission table
        // and verify the user has management rights for the expense owner
        
        // For now, we'll assume Primary Admin and Admin roles have management permission
        // This should be replaced with proper policy checks
        return true; // Placeholder - implement proper permission checking
    }

    /**
     * Check if user has approval permission.
     *
     * @param mixed $user
     * @return bool
     */
    private function hasApprovalPermission($user): bool
    {
        // This is a simplified implementation
        // In a real application, this would check user roles and ensure
        // Business User and Card User cannot approve expenses
        
        // For now, we'll assume only Primary Admin and Admin can approve
        // This should be replaced with proper role/policy checks
        return true; // Placeholder - implement proper role checking
    }

    /**
     * Get additional attributes when the resource is used in a collection.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'version' => '1.0',
                'timestamp' => now()->toISOString(),
            ],
        ];
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
        // Set custom headers if needed
        $response->header('X-Resource-Type', 'PocketExpense');
    }

    /**
     * Create a new resource collection with proper pagination handling.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'expense_statuses' => [
                    'draft' => 'Draft',
                    'submitted' => 'Submitted for Review', 
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ],
                'permissions' => [
                    'can_create' => true, // This should be dynamically determined
                    'can_bulk_upload' => true, // This should be dynamically determined
                ],
            ],
        ]);
    }
}

/**
 * Nested resource for PocketExpenseMetadata
 * 
 * Provides a simplified view of metadata for inclusion in PocketExpense resources
 * without circular dependencies.
 */
class PocketExpenseMetadataResource extends JsonResource
{
    /**
     * Transform the metadata resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'metadata_type' => $this->metadata_type,
            'details' => $this->details_json,
            
            // Reference information based on metadata type
            'reference_info' => $this->getReferenceInfo(),
            
            // Source information for expense_source type
            'source_info' => $this->when($this->metadata_type === 'expense_source', [
                'source_id' => $this->expense_source_id,
                'source_name' => $this->expenseSource->name ?? null,
                'source_note' => $this->details_json['source_note'] ?? null,
            ]),
            
            // File information for file type
            'file_info' => $this->when($this->metadata_type === 'file', [
                'file_id' => $this->file_store_id,
                'file_name' => $this->details_json['file_name'] ?? null,
                'file_size' => $this->details_json['file_size'] ?? null,
                'mime_type' => $this->details_json['mime_type'] ?? null,
            ]),
            
            // Category information
            'category_info' => $this->when($this->metadata_type === 'category', [
                'category_id' => $this->transaction_category_id,
                'category_name' => $this->details_json['category_name'] ?? null,
                'category_code' => $this->details_json['category_code'] ?? null,
                'subcategory' => $this->details_json['subcategory'] ?? null,
            ]),
            
            // Project information
            'project_info' => $this->when($this->metadata_type === 'project', [
                'project_id' => $this->project_id,
                'project_name' => $this->details_json['project_name'] ?? null,
                'project_code' => $this->details_json['project_code'] ?? null,
                'phase' => $this->details_json['phase'] ?? null,
            ]),
            
            // Timestamps
            'create_time' => $this->create_time ? $this->create_time->toISOString() : null,
            'update_time' => $this->update_time ? $this->update_time->toISOString() : null,
        ];
    }

    /**
     * Get reference information based on the metadata type.
     *
     * @return array<string, mixed>|null
     */
    private function getReferenceInfo(): ?array
    {
        return match ($this->metadata_type) {
            'category' => [
                'type' => 'transaction_category',
                'id' => $this->transaction_category_id,
                'display_name' => $this->details_json['category_name'] ?? 'Category #' . $this->transaction_category_id,
            ],
            'tracking_code_type_1', 'tracking_code_type_2' => [
                'type' => 'tracking_code',
                'id' => $this->tracking_code_id,
                'display_name' => $this->details_json['code'] ?? 'Tracking Code #' . $this->tracking_code_id,
                'description' => $this->details_json['description'] ?? null,
            ],
            'project' => [
                'type' => 'project',
                'id' => $this->project_id,
                'display_name' => $this->details_json['project_name'] ?? 'Project #' . $this->project_id,
            ],
            'additional_field' => [
                'type' => 'additional_field',
                'id' => $this->additional_field_id,
                'display_name' => $this->details_json['field_name'] ?? 'Field #' . $this->additional_field_id,
                'value' => $this->details_json['field_value'] ?? null,
            ],
            'file' => [
                'type' => 'file_store',
                'id' => $this->file_store_id,
                'display_name' => $this->details_json['file_name'] ?? 'File #' . $this->file_store_id,
            ],
            'expense_source' => [
                'type' => 'expense_source',
                'id' => $this->expense_source_id,
                'display_name' => $this->expenseSource->name ?? 'Source #' . $this->expense_source_id,
            ],
            default => null,
        };
    }
}