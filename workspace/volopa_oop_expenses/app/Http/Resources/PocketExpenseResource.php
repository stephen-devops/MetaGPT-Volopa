## Code: app/Http/Resources/PocketExpenseResource.php

```php
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
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'create_time' => $this->create_time?->toISOString(),
            'update_time' => $this->update_time?->toISOString(),
            'deleted' => $this->deleted ?? false,
            'delete_time' => $this->delete_time?->toISOString(),
            
            // Relationship data (loaded when available)
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? 'Unknown User',
                    'email' => $this->user->email ?? 'no-email@example.com',
                    'role' => $this->user->role ?? 'user',
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                    'code' => $this->client->code ?? 'N/A',
                ];
            }),
            
            'expense_type_details' => $this->whenLoaded('expenseType', function () {
                return [
                    'id' => $this->expenseType->id,
                    'option' => $this->expenseType->option ?? 'Unknown Type',
                    'amount_sign' => $this->expenseType->amount_sign ?? 'negative',
                    'display_name' => $this->expenseType->getDisplayName() ?? 'Unknown Type',
                    'is_positive' => $this->expenseType->isPositive() ?? false,
                    'amount_multiplier' => $this->expenseType->getAmountMultiplier() ?? -1,
                ];
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
                        'create_time' => $meta->create_time?->toISOString(),
                        'update_time' => $meta->update_time?->toISOString(),
                        'deleted' => $meta->deleted ?? false,
                        
                        // Nested relationship data
                        'transaction_category' => $this->when(
                            $meta->relationLoaded('transactionCategory') && $meta->transactionCategory,
                            function () use ($meta) {
                                return [
                                    'id' => $meta->transactionCategory->id,
                                    'name' => $meta->transactionCategory->name ?? 'Unknown Category',
                                ];
                            }
                        ),
                        
                        'tracking_code' => $this->when(
                            $meta->relationLoaded('trackingCode') && $meta->trackingCode,
                            function () use ($meta) {
                                return [
                                    'id' => $meta->trackingCode->id,
                                    'code' => $meta->trackingCode->code ?? 'Unknown Code',
                                    'description' => $meta->trackingCode->description ?? null,
                                ];
                            }
                        ),
                        
                        'project' => $this->when(
                            $meta->relationLoaded('project') && $meta->project,
                            function () use ($meta) {
                                return [
                                    'id' => $meta->project->id,
                                    'name' => $meta->project->name ?? 'Unknown Project',
                                    'code' => $meta->project->code ?? 'N/A',
                                ];
                            }
                        ),
                        
                        'expense_source' => $this->when(
                            $meta->relationLoaded('expenseSource') && $meta->expenseSource,
                            function () use ($meta) {
                                return [
                                    'id' => $meta->expenseSource->id,
                                    'uuid' => $meta->expenseSource->uuid,
                                    'name' => $meta->expenseSource->name ?? 'Unknown Source',
                                    'is_default' => $meta->expenseSource->is_default ?? false,
                                    'is_global_other' => $meta->expenseSource->isGlobalOther() ?? false,
                                ];
                            }
                        ),
                    ];
                });
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name ?? 'Unknown User',
                    'email' => $this->createdBy->email ?? 'no-email@example.com',
                ];
            }),
            
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return $this->updatedBy ? [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->name ?? 'Unknown User',
                    'email' => $this->updatedBy->email ?? 'no-email@example.com',
                ] : null;
            }),
            
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return $this->approvedBy ? [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name ?? 'Unknown User',
                    'email' => $this->approvedBy->email ?? 'no-email@example.com',
                ] : null;
            }),
            
            // Additional computed fields
            'formatted_amount' => $this->getFormattedAmount(),
            'formatted_vat_amount' => $this->getFormattedVatAmount(),
            'status_display' => $this->getStatusDisplay(),
            'days_since_created' =>