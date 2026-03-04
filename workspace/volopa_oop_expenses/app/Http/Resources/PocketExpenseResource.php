## Code: app/Http/Resources/PocketExpenseResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * PocketExpenseResource
 * 
 * API Resource for transforming PocketExpense model responses.
 * Shapes output data structure and hides internal model fields for API responses
 * following Laravel best practices with proper data transformation.
 * 
 * Response Structure:
 * - Exposes essential expense data for frontend consumption
 * - Includes related user, client, expense type, and metadata information
 * - Formats timestamps and amounts consistently
 * - Hides sensitive internal fields and database specifics
 * - Provides clear expense status and approval workflow data
 * - Includes FX conversion data and metadata relationships
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
            'date' => $this->date ? $this->date->format('Y-m-d') : null,
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'expense_type' => $this->expense_type,
            'currency' => $this->currency,
            'amount' => $this->when($this->amount !== null, function () {
                return [
                    'value' => (float) $this->amount,
                    'formatted' => number_format($this->amount, 2),
                    'display' => $this->currency . ' ' . number_format($this->amount, 2),
                    'is_positive' => $this->amount >= 0,
                ];
            }),
            'merchant_address' => $this->merchant_address,
            'vat_amount' => $this->when($this->vat_amount !== null, function () {
                return [
                    'value' => (float) $this->vat_amount,
                    'formatted' => number_format($this->vat_amount, 2),
                    'display' => $this->currency . ' ' . number_format($this->vat_amount, 2),
                ];
            }),
            'notes' => $this->notes,
            'status' => $this->status,
            'status_info' => [
                'current' => $this->status,
                'display_name' => $this->getStatusDisplayName(),
                'can_edit' => $this->canBeEdited(),
                'can_delete' => $this->canBeDeleted(),
                'can_approve' => $this->canBeApproved(),
                'next_actions' => $this->getNextActions(),
            ],
            
            // Related user information
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name ?? 'Unknown User',
                'email' => $this->user?->email,
                'role' => $this->user?->role ?? 'Unknown Role',
            ],
            
            // Related client information
            'client' => [
                'id' => $this->client?->id,
                'name' => $this->client?->name ?? 'Unknown Client',
                'code' => $this->client?->code,
            ],
            
            // Related expense type information
            'expense_type_info' => [
                'id' => $this->expenseType?->id,
                'option' => $this->expenseType?->option ?? 'Unknown Type',
                'amount_sign' => $this->expenseType?->amount_sign ?? 'negative',
                'is_refund' => $this->expenseType?->amount_sign === 'positive',
            ],
            
            // Audit trail information
            'audit' => [
                'created_by' => [
                    'id' => $this->createdBy?->id,
                    'name' => $this->createdBy?->name ?? 'Unknown User',
                    'email' => $this->createdBy?->email,
                ],
                'updated_by' => $this->when($this->updatedBy, [
                    'id' => $this->updatedBy?->id,
                    'name' => $this->updatedBy?->name ?? 'Unknown User',
                    'email' => $this->updatedBy?->email,
                ]),
                'approved_by' => $this->when($this->approvedBy, [
                    'id' => $this->approvedBy?->id,
                    'name' => $this->approvedBy?->name ?? 'Unknown Approver',
                    'email' => $this->approvedBy?->email,
                ]),
            ],
            
            // Metadata information
            'metadata' => $this->when($this->relationLoaded('metadata'), function () {
                return $this->metadata->map(function ($meta) {
                    return [
                        'id' => $meta->id,
                        'type' => $meta->metadata_type,
                        'category' => $this->when($meta->transactionCategory, [
                            'id' => $meta->transactionCategory->id,
                            'name' => $meta->transactionCategory->name ?? 'Unknown Category',
                            'code' => $meta->transactionCategory->code,
                        ]),
                        'tracking_code' => $this->when($meta->trackingCode, [
                            'id' => $meta->trackingCode->id,
                            'code' => $meta->trackingCode->code ?? 'Unknown Code',
                            'description' => $meta->trackingCode->description,
                        ]),
                        'project' => $this->when($meta->project, [
                            'id' => $meta->project->id,
                            'name' => $meta->project->name ?? 'Unknown Project',
                            'code' => $meta->project->code,
                        ]),
                        'expense_source' => $this->when($meta->expenseSource, [
                            'id' => $meta->expenseSource->id,
                            'name' => $meta->expenseSource->name ?? 'Unknown Source',
                            'is_default' => $meta->expenseSource->is_default ?? false,
                        ]),
                        'file_store' => $this->when($meta->fileStore, [
                            'id' => $meta->fileStore->id,
                            'filename' => $meta->fileStore->filename ?? 'Unknown File',
                            'url' => $meta->fileStore->url,
                        ]),
                        'additional_field' => $this->when($meta->additionalField, [
                            'id' => $meta->additionalField->id,
                            'name' => $meta->additionalField->name ?? 'Unknown Field',
                            'type' => $meta->additionalField->type,
                        ]),
                        'details' => $meta->details_json,
                        'created_at' => $meta->create_time ? Carbon::parse($meta->create_time)->toISOString() : null,
                    ];
                });
            }, []),
            
            // FX conversion information (from metadata)
            'fx_conversion' => $this->when($this->relationLoaded('metadata'), function () {
                $fxMeta = $this->metadata->where('metadata_type', 'additional_field')
                    ->where('details_json->fx_conversion', '!=', null)
                    ->first();
                
                if ($fxMeta && isset($fxMeta->details_json['fx_conversion'])) {
                    $fx = $fxMeta->details_json['fx_conversion'];
                    return [
                        'original_currency' => $fx['original_currency'] ?? $this->currency,
                        'base_currency' => $fx['base_currency