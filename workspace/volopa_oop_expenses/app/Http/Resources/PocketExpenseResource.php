<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * API Resource for PocketExpense model
 * 
 * Shapes PocketExpense model data for API responses, hiding internal fields
 * and providing consistent JSON structure. Includes relationships to expense
 * type, metadata, and user information while maintaining security boundaries.
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
        // Format create_time and update_time using Carbon for consistency
        $createTime = $this->create_time ? Carbon::parse($this->create_time) : null;
        $updateTime = $this->update_time ? Carbon::parse($this->update_time) : null;
        
        return [
            // Primary identifiers
            'id' => $this->id,
            'uuid' => $this->uuid,
            
            // Ownership and context
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            
            // Core expense details
            'date' => $this->date, // Already in YYYY-MM-DD format from DB
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'merchant_address' => $this->merchant_address,
            
            // Financial details
            'currency' => $this->currency,
            'amount' => (float) $this->amount, // Convert decimal to float for JSON
            'vat_amount' => $this->vat_amount ? (float) $this->vat_amount : null,
            
            // Additional details
            'notes' => $this->notes,
            
            // Workflow status
            'status' => $this->status,
            
            // Timestamps formatted for API consistency
            'created_at' => $createTime?->toISOString(),
            'updated_at' => $updateTime?->toISOString(),
            
            // Relationships - loaded only when available to avoid N+1 queries
            'expense_type' => $this->whenLoaded('expenseType', function () {
                return [
                    'id' => $this->expenseType->id,
                    'option' => $this->expenseType->option,
                    'amount_sign' => $this->expenseType->amount_sign,
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
            
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return $this->updatedBy ? [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->name,
                ] : null;
            }),
            
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return $this->approvedBy ? [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ] : null;
            }),
            
            // Metadata collection - process different metadata types
            'metadata' => $this->whenLoaded('metadata', function () {
                return $this->metadata->map(function ($meta) {
                    $baseData = [
                        'id' => $meta->id,
                        'type' => $meta->metadata_type,
                        'details' => $meta->details_json ? json_decode($meta->details_json, true) : null,
                    ];
                    
                    // Add type-specific relationship data
                    switch ($meta->metadata_type) {
                        case 'category':
                            if ($meta->transactionCategory) {
                                $baseData['category'] = [
                                    'id' => $meta->transactionCategory->id,
                                    'name' => $meta->transactionCategory->name,
                                    'code' => $meta->transactionCategory->code,
                                ];
                            }
                            break;
                            
                        case 'tracking_code_type_1':
                        case 'tracking_code_type_2':
                            if ($meta->trackingCode) {
                                $baseData['tracking_code'] = [
                                    'id' => $meta->trackingCode->id,
                                    'unit' => $meta->trackingCode->unit,
                                    'tracking_code_type_id' => $meta->trackingCode->tracking_code_type_id,
                                ];
                            }
                            break;
                            
                        case 'project':
                            if ($meta->project) {
                                $baseData['project'] = [
                                    'id' => $meta->project->id,
                                    'name' => $meta->project->name,
                                    'code' => $meta->project->code,
                                ];
                            }
                            break;
                            
                        case 'file':
                            if ($meta->fileStore) {
                                $baseData['file'] = [
                                    'id' => $meta->fileStore->id,
                                    'uuid' => $meta->fileStore->uuid,
                                    'file_name' => $meta->fileStore->file_name,
                                    'file_extension' => $meta->fileStore->file_extension,
                                ];
                            }
                            break;
                            
                        case 'expense_source':
                            if ($meta->expenseSource) {
                                $baseData['expense_source'] = [
                                    'id' => $meta->expenseSource->id,
                                    'name' => $meta->expenseSource->name,
                                    'is_default' => (bool) $meta->expenseSource->is_default,
                                ];
                            }
                            break;
                            
                        case 'additional_field':
                            if ($meta->additionalField) {
                                $baseData['additional_field'] = [
                                    'id' => $meta->additionalField->id,
                                    'label' => $meta->additionalField->label,
                                    'field_type' => $meta->additionalField->field_type,
                                ];
                            }
                            break;
                    }
                    
                    return $baseData;
                })->toArray();
            }),
            
            // Include user details when loaded (for admin views)
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'username' => $this->user->username,
                ];
            }),
            
            // Include calculated or derived fields when available
            'fx_conversion' => $this->when($this->fx_rate ?? false, function () {
                return [
                    'original_currency' => $this->currency,
                    'base_currency' => $this->base_currency ?? null,
                    'fx_rate' => $this->fx_rate ? (float) $this->fx_rate : null,
                    'base_amount' => $this->base_amount ? (float) $this->base_amount : null,
                    'fx_date' => $this->fx_date ?? null,
                ];
            }),
            
            // Administrative information (only when accessed by authorized users)
            'admin_info' => $this->when(
                $request->user()?->hasRole(['Primary Admin', 'Admin']) ?? false,
                function () use ($createTime, $updateTime) {
                    return [
                        'created_by_user_id' => $this->created_by_user_id,
                        'updated_by_user_id' => $this->updated_by_user_id,
                        'approved_by_user_id' => $this->approved_by_user_id,
                        'deleted' => (bool) $this->deleted,
                        'delete_time' => $this->delete_time ? Carbon::parse($this->delete_time)->toISOString() : null,
                        'create_time' => $createTime?->toISOString(),
                        'update_time' => $updateTime?->toISOString(),
                    ];
                }
            ),
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
                'resource_type' => 'pocket_expense',
                'api_version' => 'v1',
                'generated_at' => Carbon::now()->toISOString(),
            ],
        ];
    }
    
    /**
     * Customize the outgoing response for the resource.
     *
     * @param Request $request
     * @param \Illuminate\Http\Response $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Set consistent response headers for PocketExpense resources
        $response->header('X-Resource-Type', 'PocketExpense');
        $response->header('X-API-Version', 'v1');
    }
    
    /**
     * Get the resource's data array with pagination support.
     * Override to provide consistent collection metadata.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    protected function paginationInformation(Request $request): array
    {
        $paginated = parent::resource;
        
        if (!method_exists($paginated, 'total')) {
            return [];
        }
        
        return [
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
            'filters_applied' => $request->only([
                'status',
                'date_from',
                'date_to',
                'currency',
                'expense_type',
                'merchant_name',
                'amount_min',
                'amount_max',
            ]),
            'sorting' => [
                'sort_by' => $request->input('sort_by', 'date'),
                'sort_direction' => $request->input('sort_direction', 'desc'),
            ],
        ];
    }
}