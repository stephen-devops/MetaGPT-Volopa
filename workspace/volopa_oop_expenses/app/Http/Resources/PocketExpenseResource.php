<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseResource
 * 
 * API resource for transforming PocketExpense model instances into JSON responses.
 * Shapes the expense data for API consumption while hiding internal fields.
 * Includes related data and computed attributes for frontend display.
 * 
 * @property-read \App\Models\PocketExpense $resource
 */
class PocketExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Core identification fields
            'id' => $this->id,
            'uuid' => $this->uuid,
            
            // Basic expense information
            'date' => $this->date?->format('Y-m-d'),
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'merchant_address' => $this->merchant_address,
            
            // Financial information
            'currency' => $this->currency,
            'amount' => $this->amount,
            'formatted_amount' => $this->getFormattedAmountAttribute(),
            'vat_amount' => $this->vat_amount,
            'net_amount' => $this->getNetAmount(),
            'has_vat' => $this->hasVat(),
            'vat_percentage' => $this->getVatPercentage(),
            
            // Status and workflow
            'status' => $this->status,
            'status_display' => $this->getStatusDisplayAttribute(),
            'can_edit' => $this->canEdit(),
            'can_submit' => $this->canSubmit(),
            'can_approve' => $this->canApprove(),
            'can_reject' => $this->canReject(),
            'can_delete' => $this->canDelete(),
            
            // Additional information
            'notes' => $this->notes,
            
            // Expense type information - only include if relationship is loaded
            'expense_type' => $this->when(
                $this->relationLoaded('expenseType') && $this->expenseType,
                function () {
                    return [
                        'id' => $this->expenseType->id,
                        'option' => $this->expenseType->option,
                        'amount_sign' => $this->expenseType->amount_sign,
                        'amount_sign_display' => $this->expenseType->getAmountSignDisplayAttribute(),
                        'is_refund' => $this->expenseType->isRefund(),
                        'is_expense' => $this->expenseType->isExpense(),
                    ];
                }
            ),
            
            // User associations - only include if relationships are loaded
            'user' => $this->when(
                $this->relationLoaded('user') && $this->user,
                function () {
                    return [
                        'id' => $this->user->id,
                        'name' => $this->user->name,
                        'email' => $this->user->email,
                    ];
                }
            ),
            
            'created_by' => $this->when(
                $this->relationLoaded('createdBy') && $this->createdBy,
                function () {
                    return [
                        'id' => $this->createdBy->id,
                        'name' => $this->createdBy->name,
                        'email' => $this->createdBy->email,
                    ];
                }
            ),
            
            'updated_by' => $this->when(
                $this->relationLoaded('updatedBy') && $this->updatedBy,
                function () {
                    return [
                        'id' => $this->updatedBy->id,
                        'name' => $this->updatedBy->name,
                        'email' => $this->updatedBy->email,
                    ];
                }
            ),
            
            'approved_by' => $this->when(
                $this->relationLoaded('approvedBy') && $this->approvedBy,
                function () {
                    return [
                        'id' => $this->approvedBy->id,
                        'name' => $this->approvedBy->name,
                        'email' => $this->approvedBy->email,
                    ];
                }
            ),
            
            // Client information - only include if relationship is loaded
            'client' => $this->when(
                $this->relationLoaded('client') && $this->client,
                function () {
                    return [
                        'id' => $this->client->id,
                        'name' => $this->client->name ?? 'Unknown Client',
                    ];
                }
            ),
            
            // Metadata information - only include if relationship is loaded
            'metadata' => $this->when(
                $this->relationLoaded('metadata'),
                function () {
                    return $this->metadata->map(function ($metadata) {
                        return [
                            'id' => $metadata->id,
                            'metadata_type' => $metadata->metadata_type,
                            'details' => $this->formatMetadataDetails($metadata),
                            'created_at' => $metadata->create_time?->format('Y-m-d H:i:s'),
                        ];
                    });
                }
            ),
            
            'metadata_count' => $this->when(
                $this->relationLoaded('metadata'),
                $this->metadata->count()
            ),
            
            // Timestamps using Volopa pattern
            'created_at' => $this->create_time?->format('Y-m-d H:i:s'),
            'updated_at' => $this->update_time?->format('Y-m-d H:i:s'),
            
            // Additional computed fields
            'days_since_created' => $this->create_time ? now()->diffInDays($this->create_time) : null,
            'is_recent' => $this->create_time ? now()->diffInDays($this->create_time) <= 7 : false,
            
            // Workflow status flags for frontend use
            'is_draft' => $this->isDraft(),
            'is_submitted' => $this->isSubmitted(),
            'is_approved' => $this->isApproved(),
            'is_rejected' => $this->isRejected(),
            'is_active' => $this->isActive(),
            
            // Validation flags for display
            'has_complete_info' => $this->hasCompleteInformation(),
            'requires_approval' => $this->isSubmitted(),
            
            // Currency and amount formatting helpers
            'amount_display' => [
                'raw' => $this->amount,
                'formatted' => $this->getFormattedAmountAttribute(),
                'currency' => $this->currency,
                'is_positive' => $this->amount >= 0,
            ],
        ];
    }
    
    /**
     * Format metadata details for API response.
     *
     * @param \App\Models\PocketExpenseMetadata $metadata
     * @return array<string, mixed>
     */
    private function formatMetadataDetails($metadata): array
    {
        $details = [
            'type' => $metadata->metadata_type,
        ];
        
        // Add specific reference data based on metadata type
        switch ($metadata->metadata_type) {
            case 'category':
                if ($metadata->relationLoaded('transactionCategory') && $metadata->transactionCategory) {
                    $details['category'] = [
                        'id' => $metadata->transactionCategory->id,
                        'name' => $metadata->transactionCategory->name ?? 'Unknown Category',
                    ];
                }
                break;
                
            case 'tracking_code_type_1':
            case 'tracking_code_type_2':
                if ($metadata->relationLoaded('trackingCode') && $metadata->trackingCode) {
                    $details['tracking_code'] = [
                        'id' => $metadata->trackingCode->id,
                        'code' => $metadata->trackingCode->code ?? 'Unknown Code',
                        'description' => $metadata->trackingCode->description ?? null,
                    ];
                }
                break;
                
            case 'project':
                if ($metadata->relationLoaded('project') && $metadata->project) {
                    $details['project'] = [
                        'id' => $metadata->project->id,
                        'name' => $metadata->project->name ?? 'Unknown Project',
                        'code' => $metadata->project->code ?? null,
                    ];
                }
                break;
                
            case 'file':
                if ($metadata->relationLoaded('fileStore') && $metadata->fileStore) {
                    $details['file'] = [
                        'id' => $metadata->fileStore->id,
                        'filename' => $metadata->fileStore->original_filename ?? 'Unknown File',
                        'size' => $metadata->fileStore->file_size ?? null,
                        'mime_type' => $metadata->fileStore->mime_type ?? null,
                    ];
                }
                break;
                
            case 'expense_source':
                if ($metadata->relationLoaded('expenseSource') && $metadata->expenseSource) {
                    $details['source'] = [
                        'id' => $metadata->expenseSource->id,
                        'uuid' => $metadata->expenseSource->uuid,
                        'name' => $metadata->expenseSource->name,
                        'is_default' => $metadata->expenseSource->is_default,
                        'is_global' => $metadata->expenseSource->isGlobal(),
                    ];
                }
                break;
                
            case 'additional_field':
                if ($metadata->relationLoaded('additionalField') && $metadata->additionalField) {
                    $details['additional_field'] = [
                        'id' => $metadata->additionalField->id,
                        'field_name' => $metadata->additionalField->field_name ?? 'Unknown Field',
                        'field_type' => $metadata->additionalField->field_type ?? 'text',
                        'is_required' => $metadata->additionalField->is_required ?? false,
                    ];
                }
                break;
        }
        
        // Include JSON details if present
        if ($metadata->details_json) {
            $details['additional_data'] = $metadata->details_json;
        }
        
        return $details;
    }
    
    /**
     * Check if the expense has complete information for submission.
     *
     * @return bool
     */
    private function hasCompleteInformation(): bool
    {
        // Required fields check
        $hasRequiredFields = !empty($this->merchant_name) 
                           && !empty($this->currency)
                           && !empty($this->amount)
                           && !empty($this->date)
                           && $this->amount > 0;
        
        // Currency format validation
        $validCurrency = preg_match('/^[A-Z]{3}$/', $this->currency ?? '');
        
        // Date validation (not too old)
        $validDate = $this->date && $this->date >= now()->subYears(3);
        
        // Merchant name length validation
        $validMerchantName = $this->merchant_name && strlen(trim($this->merchant_name)) <= 180;
        
        return $hasRequiredFields && $validCurrency && $validDate && $validMerchantName;
    }
    
    /**
     * Get additional attributes when including related models.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'pocket_expense',
                'version' => '1.0',
                'generated_at' => now()->toISOString(),
            ],
        ];
    }
    
    /**
     * Customize the response for JSON serialization.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Add custom headers for expense resources
        $response->header('X-Resource-Type', 'PocketExpense');
        $response->header('X-API-Version', '1.0');
        
        // Set cache headers for approved/rejected expenses (they don't change often)
        if ($this->resource && in_array($this->resource->status, ['approved', 'rejected'])) {
            $response->header('Cache-Control', 'public, max-age=3600'); // 1 hour cache
        } else {
            $response->header('Cache-Control', 'no-cache, must-revalidate');
        }
    }
}