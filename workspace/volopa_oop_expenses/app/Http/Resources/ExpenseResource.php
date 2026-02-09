<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid ?? null,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'date' => $this->date ? $this->date->format('Y-m-d') : null,
            'merchant_name' => $this->merchant_name,
            'description' => $this->description,
            'transaction_type' => $this->transaction_type,
            'transaction_type_label' => $this->getTransactionTypeLabelAttribute(),
            'currency' => $this->currency,
            'amount' => $this->amount ? (float) $this->amount : 0.0,
            'formatted_amount' => $this->getFormattedAmountAttribute(),
            'absolute_amount' => $this->getAbsoluteAmountAttribute(),
            'is_negative_amount' => $this->isNegativeAmount(),
            'is_positive_amount' => $this->isPositiveAmount(),
            'merchant_address' => $this->merchant_address,
            'country' => $this->country,
            'source' => $this->source,
            'category' => $this->category,
            'custom_fields' => $this->when(
                $this->hasCustomFields(),
                $this->custom_fields ?? []
            ),
            'tracking_code_i' => $this->tracking_code_i,
            'tracking_code_ii' => $this->tracking_code_ii,
            'project_id' => $this->project_id,
            'vat' => $this->vat ? (float) $this->vat : null,
            'has_vat' => $this->hasVat(),
            'vat_amount' => $this->when(
                $this->hasVat(),
                $this->getVatAmountAttribute()
            ),
            'amount_excluding_vat' => $this->when(
                $this->hasVat(),
                $this->getAmountExcludingVatAttribute()
            ),
            'status' => $this->status,
            'status_label' => $this->getStatusLabelAttribute(),
            'status_color' => $this->getStatusColorAttribute(),
            'can_be_updated' => $this->canBeUpdated(),
            'can_be_deleted' => $this->canBeDeleted(),
            'can_be_approved' => $this->canBeApproved(),
            'can_be_rejected' => $this->canBeRejected(),
            'is_pending' => $this->isPending(),
            'is_approved' => $this->isApproved(),
            'is_rejected' => $this->isRejected(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at ? $this->approved_at->toISOString() : null,
            'approved_at_formatted' => $this->approved_at ? $this->approved_at->format('M j, Y g:i A') : null,
            'receipt_path' => $this->receipt_path,
            'has_receipt' => $this->hasReceipt(),
            'notes' => $this->notes,
            'age_in_days' => $this->getAgeInDaysAttribute(),
            'is_older_than_week' => $this->isOlderThan(7),
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'created_at_formatted' => $this->created_at ? $this->created_at->format('M j, Y g:i A') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_at_formatted' => $this->updated_at ? $this->updated_at->format('M j, Y g:i A') : null,
            
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            'approved_by_user' => $this->whenLoaded('approvedBy', function () {
                return $this->approvedBy ? [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                    'email' => $this->approvedBy->email,
                ] : null;
            }),
            
            'project' => $this->whenLoaded('project', function () {
                return $this->project ? [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'description' => $this->project->description ?? null,
                ] : null;
            }),
            
            // Computed fields for UI
            'display_date' => $this->date ? $this->date->format('M j, Y') : null,
            'display_amount' => $this->currency . ' ' . number_format(abs($this->amount ?? 0), 2),
            'display_status' => ucfirst($this->status ?? 'unknown'),
            'display_merchant' => $this->merchant_name ?? 'Unknown Merchant',
            
            // Additional metadata for frontend
            'meta' => [
                'belongs_to_user' => $this->when(
                    $request->user(),
                    function () use ($request) {
                        return $this->belongsToUser($request->user()->id);
                    }
                ),
                'belongs_to_client' => $this->when(
                    $request->has('client_id'),
                    function () use ($request) {
                        return $this->belongsToClient((int) $request->get('client_id'));
                    }
                ),
                'has_custom_fields' => $this->hasCustomFields(),
                'requires_receipt' => $this->amount && abs($this->amount) > 100, // Example business rule
                'is_high_value' => $this->amount && abs($this->amount) > 1000, // Example business rule
                'processing_time' => $this->when(
                    $this->approved_at && $this->created_at,
                    function () {
                        return $this->created_at->diffInDays($this->approved_at);
                    }
                ),
            ],
            
            // Links for HATEOAS
            'links' => [
                'self' => route('api.expenses.show', ['expense' => $this->id]),
                'update' => $this->when(
                    $this->canBeUpdated(),
                    route('api.expenses.update', ['expense' => $this->id])
                ),
                'delete' => $this->when(
                    $this->canBeDeleted(),
                    route('api.expenses.destroy', ['expense' => $this->id])
                ),
                'approve' => $this->when(
                    $this->canBeApproved(),
                    route('api.expenses.approve', ['expense' => $this->id])
                ),
                'reject' => $this->when(
                    $this->canBeRejected(),
                    route('api.expenses.reject', ['expense' => $this->id])
                ),
                'receipt' => $this->when(
                    $this->hasReceipt(),
                    route('api.expenses.receipt', ['expense' => $this->id])
                ),
            ],
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'timestamp' => now()->toISOString(),
                'timezone' => config('app.timezone', 'UTC'),
                'currency_symbol' => $this->getCurrencySymbol($this->currency ?? 'USD'),
                'locale' => app()->getLocale(),
            ],
        ];
    }

    /**
     * Customize the response for a request.
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'expense');
        $response->header('X-Resource-Version', '1.0');
        
        if ($this->resource && $this->resource->updated_at) {
            $response->header('Last-Modified', $this->resource->updated_at->toRfc7231String());
            $response->header('ETag', '"' . md5($this->resource->updated_at->timestamp) . '"');
        }
    }

    /**
     * Get currency symbol for display purposes.
     */
    private function getCurrencySymbol(string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'CHF' => 'Fr',
            'CNY' => '¥',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
            'HUF' => 'Ft',
            'RUB' => '₽',
            'INR' => '₹',
            'BRL' => 'R$',
            'MXN' => '$',
            'ZAR' => 'R',
            'KRW' => '₩',
            'SGD' => 'S$',
            'HKD' => 'HK$',
            'NZD' => 'NZ$',
            'TRY' => '₺',
            'THB' => '฿',
            'MYR' => 'RM',
            'PHP' => '₱',
            'IDR' => 'Rp',
            'VND' => '₫',
            'AED' => 'د.إ',
            'SAR' => '﷼',
            'QAR' => '﷼',
            'KWD' => 'د.ك',
            'BHD' => '.د.ب',
            'OMR' => '﷼',
            'JOD' => 'د.ا',
            'LBP' => '£',
            'EGP' => '£',
        ];

        return $symbols[$currency] ?? $currency;
    }

    /**
     * Create a new resource instance for a collection of models.
     */
    public static function collection($resource)
    {
        return parent::collection($resource);
    }

    /**
     * Create a conditional resource.
     */
    public static function make($resource): static
    {
        return new static($resource);
    }

    /**
     * Determine if the resource should be returned.
     */
    public function shouldReturn(): bool
    {
        return $this->resource !== null;
    }

    /**
     * Get the JSON serialization options.
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;
    }

    /**
     * Customize the pagination information for the resource.
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return array_merge($default, [
            'meta' => array_merge($default['meta'] ?? [], [
                'resource_type' => 'expense',
                'per_page_options' => [10, 15, 25, 50, 100],
                'sortable_fields' => [
                    'date', 'merchant_name', 'amount', 'status', 'created_at', 'updated_at'
                ],
                'filterable_fields' => [
                    'status', 'currency', 'transaction_type', 'date_range', 'amount_range'
                ],
                'searchable_fields' => [
                    'merchant_name', 'description', 'notes'
                ],
            ]),
        ]);
    }

    /**
     * Get the string representation of the resource.
     */
    public function __toString(): string
    {
        if (!$this->resource) {
            return 'Expense Resource (null)';
        }

        return sprintf(
            'Expense #%d (%s - %s %s)',
            $this->resource->id,
            $this->resource->merchant_name ?? 'Unknown',
            $this->resource->currency ?? '',
            number_format(abs($this->resource->amount ?? 0), 2)
        );
    }
}