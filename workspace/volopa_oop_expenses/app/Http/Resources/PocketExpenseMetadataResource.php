<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pocket Expense Metadata Resource
 * 
 * API resource for transforming PocketExpenseMetadata model instances.
 * Shapes response data for expense metadata including type-specific fields
 * and related entity information while hiding internal fields.
 * 
 * @property \App\Models\PocketExpenseMetadata $resource
 */
class PocketExpenseMetadataResource extends JsonResource
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
            'pocket_expense_id' => $this->resource->pocket_expense_id,
            'metadata_type' => $this->resource->metadata_type,
            
            // Type-specific fields - only include if not null
            'transaction_category_id' => $this->when($this->resource->transaction_category_id !== null, $this->resource->transaction_category_id),
            'tracking_code_id' => $this->when($this->resource->tracking_code_id !== null, $this->resource->tracking_code_id),
            'project_id' => $this->when($this->resource->project_id !== null, $this->resource->project_id),
            'file_store_id' => $this->when($this->resource->file_store_id !== null, $this->resource->file_store_id),
            'expense_source_id' => $this->when($this->resource->expense_source_id !== null, $this->resource->expense_source_id),
            'additional_field_id' => $this->when($this->resource->additional_field_id !== null, $this->resource->additional_field_id),
            'user_id' => $this->when($this->resource->user_id !== null, $this->resource->user_id),
            
            // JSON details - decode if present
            'details' => $this->when($this->resource->details_json !== null, function () {
                $decoded = json_decode($this->resource->details_json, true);
                return $decoded !== null ? $decoded : [];
            }),
            
            // Related entity information - load relationships conditionally
            'expense_source' => $this->when(
                $this->resource->expense_source_id !== null && $this->resource->relationLoaded('source'),
                function () {
                    return new PocketExpenseSourceResource($this->resource->source);
                }
            ),
            
            // TODO: Add resources for other related entities when their models are available
            // 'transaction_category' => $this->when($this->resource->relationLoaded('category'), new TransactionCategoryResource($this->resource->category)),
            // 'tracking_code' => $this->when($this->resource->relationLoaded('trackingCode'), new TrackingCodeResource($this->resource->trackingCode)),
            // 'project' => $this->when($this->resource->relationLoaded('project'), new ProjectResource($this->resource->project)),
            // 'file' => $this->when($this->resource->relationLoaded('file'), new FileResource($this->resource->file)),
            // 'additional_field' => $this->when($this->resource->relationLoaded('additionalField'), new AdditionalFieldResource($this->resource->additionalField)),
            
            'user' => $this->when(
                $this->resource->user_id !== null && $this->resource->relationLoaded('user'),
                function () {
                    // TODO: Create UserResource when available or use existing User resource
                    return [
                        'id' => $this->resource->user->id,
                        'name' => $this->resource->user->name ?? '',
                    ];
                }
            ),
            
            // Timestamps using Volopa legacy convention
            'create_time' => $this->resource->create_time?->toISOString(),
            'update_time' => $this->resource->update_time?->toISOString(),
            
            // Soft delete information
            'deleted' => $this->resource->deleted,
            'delete_time' => $this->when($this->resource->delete_time !== null, $this->resource->delete_time->toISOString()),
            
            // Computed fields
            'is_active' => !$this->resource->deleted,
            'display_type' => ucwords(str_replace('_', ' ', $this->resource->metadata_type)),
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
                'resource_type' => 'pocket_expense_metadata',
                'version' => '1.0',
            ],
        ];
    }
}