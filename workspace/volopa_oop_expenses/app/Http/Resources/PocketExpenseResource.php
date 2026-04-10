<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseResource
 * 
 * API Resource for transforming PocketExpense model data for JSON responses.
 * Shapes the expense data and includes related metadata, user, and type information.
 * Follows Laravel API Resource pattern for consistent response formatting.
 * 
 * @property \App\Models\PocketExpense $resource
 */
class PocketExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'uuid' => $this->resource->uuid,
            'user_id' => $this->resource->user_id,
            'client_id' => $this->resource->client_id,
            'date' => $this->resource->date,
            'merchant_name' => $this->resource->merchant_name,
            'merchant_description' => $this->resource->merchant_description,
            'amount' => $this->resource->amount,
            'currency' => $this->resource->currency,
            'merchant_address' => $this->resource->merchant_address,
            'vat_amount' => $this->resource->vat_amount,
            'notes' => $this->resource->notes,
            'status' => $this->resource->status,
            'create_time' => $this->resource->create_time?->toISOString(),
            'update_time' => $this->resource->update_time?->toISOString(),
            
            // Related data - conditionally loaded to avoid N+1 queries
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->resource->user->id,
                    'name' => $this->resource->user->name,
                    'username' => $this->resource->user->username,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->resource->client->id,
                    'name' => $this->resource->client->name,
                ];
            }),
            
            'expense_type' => $this->whenLoaded('expenseType', function () {
                return [
                    'id' => $this->resource->expenseType->id,
                    'option' => $this->resource->expenseType->option,
                    'amount_sign' => $this->resource->expenseType->amount_sign,
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->resource->createdBy->id,
                    'name' => $this->resource->createdBy->name,
                    'username' => $this->resource->createdBy->username,
                ];
            }),
            
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return $this->resource->updatedBy ? [
                    'id' => $this->resource->updatedBy->id,
                    'name' => $this->resource->updatedBy->name,
                    'username' => $this->resource->updatedBy->username,
                ] : null;
            }),
            
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return $this->resource->approvedBy ? [
                    'id' => $this->resource->approvedBy->id,
                    'name' => $this->resource->approvedBy->name,
                    'username' => $this->resource->approvedBy->username,
                ] : null;
            }),
            
            // Metadata - conditionally loaded
            'metadata' => $this->whenLoaded('metadata', function () {
                return $this->resource->metadata->map(function ($metadata) {
                    return [
                        'id' => $metadata->id,
                        'metadata_type' => $metadata->metadata_type,
                        'details_json' => $metadata->details_json ? json_decode($metadata->details_json, true) : null,
                        'create_time' => $metadata->create_time?->toISOString(),
                        
                        // Related metadata entities - conditionally loaded to avoid N+1
                        'transaction_category' => $metadata->transactionCategory ? [
                            'id' => $metadata->transactionCategory->id,
                            // TODO: Add transaction category fields when TransactionCategory model is available
                        ] : null,
                        
                        'tracking_code' => $metadata->trackingCode ? [
                            'id' => $metadata->trackingCode->id,
                            // TODO: Add tracking code fields when TrackingCode model is available
                        ] : null,
                        
                        'project' => $metadata->project ? [
                            'id' => $metadata->project->id,
                            // TODO: Add project fields when Project model is available
                        ] : null,
                        
                        'file_store' => $metadata->fileStore ? [
                            'id' => $metadata->fileStore->id,
                            // TODO: Add file store fields when FileStore model is available
                        ] : null,
                        
                        'expense_source' => $metadata->expenseSource ? [
                            'id' => $metadata->expenseSource->id,
                            'uuid' => $metadata->expenseSource->uuid,
                            'name' => $metadata->expenseSource->name,
                            'is_default' => $metadata->expenseSource->is_default,
                        ] : null,
                        
                        'additional_field' => $metadata->additionalField ? [
                            'id' => $metadata->additionalField->id,
                            // TODO: Add additional field fields when AdditionalField model is available
                        ] : null,
                    ];
                });
            }),
            
            // Computed fields for API convenience
            'is_draft' => $this->resource->status === 'draft',
            'is_submitted' => $this->resource->status === 'submitted',
            'is_approved' => $this->resource->status === 'approved',
            'is_rejected' => $this->resource->status === 'rejected',
            'is_editable' => in_array($this->resource->status, ['draft', 'rejected']),
            'has_vat' => $this->resource->vat_amount !== null && $this->resource->vat_amount > 0,
            
            // Audit information
            'audit' => [
                'created_by_user_id' => $this->resource->created_by_user_id,
                'updated_by_user_id' => $this->resource->updated_by_user_id,
                'approved_by_user_id' => $this->resource->approved_by_user_id,
                'create_time' => $this->resource->create_time?->toISOString(),
                'update_time' => $this->resource->update_time?->toISOString(),
                'deleted' => $this->resource->deleted,
                'delete_time' => $this->resource->delete_time?->toISOString(),
            ],
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'version' => '1.0',
                'resource_type' => 'pocket_expense',
            ],
        ];
    }
}