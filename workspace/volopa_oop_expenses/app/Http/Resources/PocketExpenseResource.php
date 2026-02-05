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
 * API resource for single pocket expense response.
 * Shapes expense data for API responses with proper formatting and computed attributes.
 * Includes relationships, metadata, and user-friendly display values.
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
            // Core identifiers
            'id' => $this->id,
            'uuid' => $this->uuid,
            
            // User and client relationships
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            
            // Expense details
            'date' => $this->date?->toDateString(),
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'merchant_address' => $this->merchant_address,
            'merchant_country' => $this->merchant_country,
            'merchant_country_name' => $this->getCountryName($this->merchant_country),
            
            // Financial data
            'currency' => $this->currency,
            'amount' => $this->formatAmount($this->amount),
            'amount_raw' => (float) $this->amount,
            'amount_display' => $this->getAmountDisplay(),
            'vat_amount' => $this->vat_amount ? (float) $this->vat_amount : null,
            'vat_percentage' => $this->vat_amount ? $this->formatVatPercentage() : null,
            'user_converted_amount' => $this->user_converted_amount ? $this->formatAmount($this->user_converted_amount) : null,
            'user_converted_amount_raw' => $this->user_converted_amount ? (float) $this->user_converted_amount : null,
            'user_converted_amount_display' => $this->getUserConvertedAmountDisplay(),
            
            // Expense type information
            'expense_type_id' => $this->expense_type,
            'expense_type' => $this->when($this->relationLoaded('expenseType'), function () {
                return [
                    'id' => $this->expenseType->id,
                    'name' => $this->expenseType->option,
                    'amount_sign' => $this->expenseType->amount_sign,
                    'is_positive' => $this->expenseType->isPositive(),
                    'is_negative' => $this->expenseType->isNegative(),
                    'display_name' => $this->expenseType->getDisplayName(),
                    'full_description' => $this->expenseType->getFullDescription(),
                ];
            }),
            
            // Status information
            'status' => $this->status,
            'status_display' => $this->getStatusDisplay(),
            'status_color' => $this->getStatusColor(),
            'is_draft' => $this->isDraft(),
            'is_submitted' => $this->isSubmitted(),
            'is_approved' => $this->isApproved(),
            'is_rejected' => $this->isRejected(),
            'is_editable' => $this->isEditable(),
            'is_deletable' => $this->isDeletable(),
            'is_approvable' => $this->isApprovable(),
            
            // Additional fields
            'notes' => $this->notes,
            
            // User relationships
            'user' => $this->when($this->relationLoaded('user'), function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'creator' => $this->when($this->relationLoaded('creator'), function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            
            'updater' => $this->when($this->relationLoaded('updater') && $this->updater, function () {
                return [
                    'id' => $this->updater->id,
                    'name' => $this->updater->name,
                ];
            }),
            
            'approver' => $this->when($this->relationLoaded('approver') && $this->approver, function () {
                return [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                    'approved_at' => $this->approved_at?->toISOString(),
                ];
            }),
            
            // Client information
            'client' => $this->when($this->relationLoaded('client'), function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                ];
            }),
            
            // Metadata
            'metadata' => $this->when($this->relationLoaded('metadata'), function () {
                return $this->formatMetadata();
            }),
            
            // Computed fields
            'has_receipt' => $this->hasReceipt(),
            'receipt_count' => $this->getReceiptCount(),
            'age_days' => $this->getAgeDays(),
            'is_recent' => $this->isRecent(),
            'requires_approval' => $this->requiresApproval(),
            'can_be_approved' => $this->canBeApproved(),
            
            // Timestamps
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at_human' => $this->create_time?->diffForHumans(),
            'updated_at_human' => $this->update_time?->diffForHumans(),
            
            // Additional computed fields for UI
            'summary' => $this->getSummary(),
            'display_title' => $this->getDisplayTitle(),
        ];
    }
    
    /**
     * Format amount for display.
     *
     * @param float|null $amount
     * @return string|null
     */
    private function formatAmount(?float $amount): ?string
    {
        if ($amount === null) {
            return null;
        }
        
        return number_format(abs($amount), 2, '.', '');
    }
    
    /**
     * Get amount display with currency and sign.
     *
     * @return string
     */
    private function getAmountDisplay(): string
    {
        $amount = (float) $this->amount;
        $sign = $amount >= 0 ? '' : '-';
        $formattedAmount = number_format(abs($amount), 2, '.', ',');
        
        return "{$sign}{$this->currency} {$formattedAmount}";
    }
    
    /**
     * Get user converted amount display with currency and sign.
     *
     * @return string|null
     */
    private function getUserConvertedAmountDisplay(): ?string
    {
        if (!$this->user_converted_amount) {
            return null;
        }
        
        // This would typically use client base currency, defaulting to USD
        $baseCurrency = $this->getClientBaseCurrency();
        $amount = (float) $this->user_converted_amount;
        $sign = $amount >= 0 ? '' : '-';
        $formattedAmount = number_format(abs($amount), 2, '.', ',');
        
        return "{$sign}{$baseCurrency} {$formattedAmount}";
    }
    
    /**
     * Get client base currency.
     *
     * @return string
     */
    private function getClientBaseCurrency(): string
    {
        // This would typically be retrieved from client settings
        // For now, default to USD
        return 'USD';
    }
    
    /**
     * Format VAT percentage for display.
     *
     * @return string|null
     */
    private function formatVatPercentage(): ?string
    {
        if (!$this->vat_amount) {
            return null;
        }
        
        return number_format((float) $this->vat_amount, 2) . '%';
    }
    
    /**
     * Get status display name.
     *
     * @return string
     */
    private function getStatusDisplay(): string
    {
        $statusLabels = [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];
        
        return $statusLabels[$this->status] ?? ucfirst($this->status);
    }
    
    /**
     * Get status color for UI.
     *
     * @return string
     */
    private function getStatusColor(): string
    {
        $statusColors = [
            'draft' => 'gray',
            'submitted' => 'blue',
            'approved' => 'green',
            'rejected' => 'red',
        ];
        
        return $statusColors[$this->status] ?? 'gray';
    }
    
    /**
     * Get country name from country code.
     *
     * @param string|null $countryCode
     * @return string|null
     */
    private function getCountryName(?string $countryCode): ?string
    {
        if (!$countryCode) {
            return null;
        }
        
        $countries = [
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'FR' => 'France',
            'DE' => 'Germany',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'IE' => 'Ireland',
            'PT' => 'Portugal',
            'LU' => 'Luxembourg',
            'JP' => 'Japan',
        ];
        
        return $countries[$countryCode] ?? $countryCode;
    }
    
    /**
     * Format metadata for API response.
     *
     * @return array<string, mixed>
     */
    private function formatMetadata(): array
    {
        if (!$this->relationLoaded('metadata') || $this->metadata->isEmpty()) {
            return [];
        }
        
        $formatted = [];
        
        foreach ($this->metadata as $meta) {
            $type = $meta->metadata_type;
            
            switch ($type) {
                case 'expense_source':
                    $formatted['expense_source'] = [
                        'id' => $meta->expense_source_id,
                        'name' => $meta->expenseSource?->name ?? 'Unknown Source',
                        'is_global' => $meta->expenseSource?->isGlobal() ?? false,
                        'is_other' => $meta->expenseSource?->isOtherSource() ?? false,
                        'details' => $meta->details_json,
                    ];
                    break;
                    
                case 'category':
                    $formatted['category'] = [
                        'id' => $meta->transaction_category_id,
                        'name' => $meta->transactionCategory?->name ?? 'Unknown Category',
                    ];
                    break;
                    
                case 'tracking_code_type_1':
                    $formatted['tracking_code_1'] = [
                        'id' => $meta->tracking_code_id,
                        'name' => $meta->trackingCode?->name ?? 'Unknown Code',
                    ];
                    break;
                    
                case 'tracking_code_type_2':
                    $formatted['tracking_code_2'] = [
                        'id' => $meta->tracking_code_id,
                        'name' => $meta->trackingCode?->name ?? 'Unknown Code',
                    ];
                    break;
                    
                case 'project':
                    $formatted['project'] = [
                        'id' => $meta->project_id,
                        'name' => $meta->project?->name ?? 'Unknown Project',
                    ];
                    break;
                    
                case 'file':
                    if (!isset($formatted['files'])) {
                        $formatted['files'] = [];
                    }
                    $formatted['files'][] = [
                        'id' => $meta->file_store_id,
                        'name' => $meta->fileStore?->original_name ?? 'Unknown File',
                        'type' => $meta->fileStore?->mime_type ?? 'unknown',
                        'size' => $meta->fileStore?->file_size ?? 0,
                        'url' => $this->getFileUrl($meta->fileStore),
                    ];
                    break;
                    
                case 'additional_field':
                    if (!isset($formatted['additional_fields'])) {
                        $formatted['additional_fields'] = [];
                    }
                    $formatted['additional_fields'][] = [
                        'id' => $meta->additional_field_id,
                        'name' => $meta->additionalField?->name ?? 'Unknown Field',
                        'value' => $meta->details_json['value'] ?? '',
                    ];
                    break;
            }
        }
        
        return $formatted;
    }
    
    /**
     * Get file URL for a file store record.
     *
     * @param mixed $fileStore
     * @return string|null
     */
    private function getFileUrl($fileStore): ?string
    {
        if (!$fileStore) {
            return null;
        }
        
        // This would typically generate a signed URL or public URL
        // For now, return a placeholder
        return route('api.files.show', ['file' => $fileStore->id ?? 0]);
    }
    
    /**
     * Check if expense has receipt attachments.
     *
     * @return bool
     */
    private function hasReceipt(): bool
    {
        if (!$this->relationLoaded('metadata')) {
            return false;
        }
        
        return $this->metadata->where('metadata_type', 'file')->isNotEmpty();
    }
    
    /**
     * Get count of receipt attachments.
     *
     * @return int
     */
    private function getReceiptCount(): int
    {
        if (!$this->relationLoaded('metadata')) {
            return 0;
        }
        
        return $this->metadata->where('metadata_type', 'file')->count();
    }
    
    /**
     * Get age of expense in days.
     *
     * @return int
     */
    private function getAgeDays(): int
    {
        if (!$this->create_time) {
            return 0;
        }
        
        return $this->create_time->diffInDays(now());
    }
    
    /**
     * Check if expense is recent (within 7 days).
     *
     * @return bool
     */
    private function isRecent(): bool
    {
        return $this->getAgeDays() <= 7;
    }
    
    /**
     * Check if expense is editable based on status.
     *
     * @return bool
     */
    private function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }
    
    /**