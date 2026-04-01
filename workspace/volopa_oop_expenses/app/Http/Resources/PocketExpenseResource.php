<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseResource
 * 
 * API resource transformer for PocketExpense with relationships.
 * Shapes the response data for expense objects including related metadata,
 * user information, and expense type details while hiding internal fields.
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
            'date' => $this->date,
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'expense_type' => $this->expense_type,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'merchant_address' => $this->merchant_address,
            'vat_amount' => $this->vat_amount,
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'create_time' => $this->create_time?->toISOString(),
            'update_time' => $this->update_time?->toISOString(),
            'deleted' => (bool) $this->deleted,
            'delete_time' => $this->delete_time?->toISOString(),
            
            // Conditional relationships - loaded only when available to prevent N+1
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? null,
                    'username' => $this->user->username ?? null,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? null,
                ];
            }),
            
            'expense_type_details' => $this->whenLoaded('expenseType', function () {
                return [
                    'id' => $this->expenseType->id,
                    'option' => $this->expenseType->option,
                    'amount_sign' => $this->expenseType->amount_sign,
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name ?? null,
                    'username' => $this->createdBy->username ?? null,
                ];
            }),
            
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return $this->updatedBy ? [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->name ?? null,
                    'username' => $this->updatedBy->username ?? null,
                ] : null;
            }),
            
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return $this->approvedBy ? [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name ?? null,
                    'username' => $this->approvedBy->username ?? null,
                ] : null;
            }),
            
            'metadata' => $this->whenLoaded('metadata', function () {
                return $this->metadata->map(function ($meta) {
                    return [
                        'id' => $meta->id,
                        'metadata_type' => $meta->metadata_type,
                        'transaction_category_id' => $meta->transaction_category_id,
                        'tracking_code_id' => $meta->tracking_code_id,
                        'project_id' => $meta->project_id,
                        'file_store_id' => $meta->file_store_id,
                        'expense_source_id' => $meta->expense_source_id,
                        'additional_field_id' => $meta->additional_field_id,
                        'user_id' => $meta->user_id,
                        'details_json' => $meta->details_json,
                        'deleted' => (bool) $meta->deleted,
                        'create_time' => $meta->create_time?->toISOString(),
                        'update_time' => $meta->update_time?->toISOString(),
                    ];
                });
            }),
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
            'links' => [
                'self' => route('api.v1.pocket-expenses.show', $this->id),
                'edit' => route('api.v1.pocket-expenses.update', $this->id),
                'delete' => route('api.v1.pocket-expenses.destroy', $this->id),
            ],
        ];
    }
}