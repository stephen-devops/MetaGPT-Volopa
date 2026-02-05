## Code: app/Http/Resources/PocketExpenseCollection.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * PocketExpenseCollection
 * 
 * API resource collection for pocket expense list responses.
 * Handles paginated collections with summary statistics and metadata.
 * Provides additional collection-level information for dashboard views.
 */
class PocketExpenseCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = PocketExpenseResource::class;

    /**
     * Additional metadata to include with the collection.
     *
     * @var array<string, mixed>
     */
    private array $additionalMeta = [];

    /**
     * Create a new resource collection instance.
     *
     * @param mixed $resource
     * @param array<string, mixed> $additionalMeta
     */
    public function __construct($resource, array $additionalMeta = [])
    {
        parent::__construct($resource);
        $this->additionalMeta = $additionalMeta;
    }

    /**
     * Transform the resource collection into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => array_merge($this->getMeta(), $this->additionalMeta),
            'summary' => $this->getSummaryStatistics(),
            'filters' => $this->getAppliedFilters($request),
        ];
    }

    /**
     * Get the pagination meta information.
     *
     * @return array<string, mixed>
     */
    private function getMeta(): array
    {
        if ($this->resource instanceof LengthAwarePaginator) {
            return [
                'current_page' => $this->resource->currentPage(),
                'from' => $this->resource->firstItem(),
                'last_page' => $this->resource->lastPage(),
                'path' => $this->resource->path(),
                'per_page' => $this->resource->perPage(),
                'to' => $this->resource->lastItem(),
                'total' => $this->resource->total(),
                'has_more_pages' => $this->resource->hasMorePages(),
                'prev_page_url' => $this->resource->previousPageUrl(),
                'next_page_url' => $this->resource->nextPageUrl(),
            ];
        }

        return [
            'total' => $this->collection->count(),
            'per_page' => $this->collection->count(),
            'current_page' => 1,
            'last_page' => 1,
            'has_more_pages' => false,
        ];
    }

    /**
     * Get summary statistics for the expense collection.
     *
     * @return array<string, mixed>
     */
    private function getSummaryStatistics(): array
    {
        $expenses = $this->collection;
        
        if ($expenses->isEmpty()) {
            return [
                'total_count' => 0,
                'total_amount' => 0.0,
                'average_amount' => 0.0,
                'currency_breakdown' => [],
                'status_breakdown' => [],
                'expense_type_breakdown' => [],
                'date_range' => null,
                'recent_count' => 0,
                'pending_approval_count' => 0,
            ];
        }

        // Calculate totals
        $totalAmount = 0.0;
        $currencyBreakdown = [];
        $statusBreakdown = [];
        $expenseTypeBreakdown = [];
        $recentCount = 0;
        $pendingApprovalCount = 0;
        $dates = [];

        foreach ($expenses as $expense) {
            // Amount calculations (use absolute values for totals)
            $amount = abs((float) $expense->amount);
            $totalAmount += $amount;

            // Currency breakdown
            $currency = $expense->currency ?? 'UNKNOWN';
            if (!isset($currencyBreakdown[$currency])) {
                $currencyBreakdown[$currency] = [
                    'count' => 0,
                    'total_amount' => 0.0,
                ];
            }
            $currencyBreakdown[$currency]['count']++;
            $currencyBreakdown[$currency]['total_amount'] += $amount;

            // Status breakdown
            $status = $expense->status ?? 'unknown';
            if (!isset($statusBreakdown[$status])) {
                $statusBreakdown[$status] = [
                    'count' => 0,
                    'total_amount' => 0.0,
                ];
            }
            $statusBreakdown[$status]['count']++;
            $statusBreakdown[$status]['total_amount'] += $amount;

            // Expense type breakdown
            $expenseTypeName = 'Unknown';
            if ($expense->relationLoaded('expenseType') && $expense->expenseType) {
                $expenseTypeName = $expense->expenseType->option ?? 'Unknown';
            }
            
            if (!isset($expenseTypeBreakdown[$expenseTypeName])) {
                $expenseTypeBreakdown[$expenseTypeName] = [
                    'count' => 0,
                    'total_amount' => 0.0,
                    'amount_sign' => $expense->expenseType->amount_sign ?? '-',
                ];
            }
            $expenseTypeBreakdown[$expenseTypeName]['count']++;
            $expenseTypeBreakdown[$expenseTypeName]['total_amount'] += $amount;

            // Recent expenses (within 7 days)
            if ($expense->create_time && $expense->create_time->diffInDays(now()) <= 7) {
                $recentCount++;
            }

            // Pending approval count
            if ($expense->status === 'submitted') {
                $pendingApprovalCount++;
            }

            // Collect dates for range calculation
            if ($expense->date) {
                $dates[] = $expense->date->toDateString();
            }
        }

        // Calculate date range
        $dateRange = null;
        if (!empty($dates)) {
            sort($dates);
            $dateRange = [
                'from' => $dates[0],
                'to' => end($dates),
                'span_days' => \Carbon\Carbon::parse($dates[0])->diffInDays(\Carbon\Carbon::parse(end($dates))),
            ];
        }

        // Format currency breakdown with display values
        $formattedCurrencyBreakdown = [];
        foreach ($currencyBreakdown as $currency => $data) {
            $formattedCurrencyBreakdown[] = [
                'currency' => $currency,
                'count' => $data['count'],
                'total_amount' => $data['total_amount'],
                'formatted_total' => number_format($data['total_amount'], 2, '.', ','),
                'percentage' => $totalAmount > 0 ? round(($data['total_amount'] / $totalAmount) * 100, 2) : 0,
            ];
        }

        // Sort currency breakdown by total amount descending
        usort($formattedCurrencyBreakdown, function ($a, $b) {
            return $b['total_amount'] <=> $a['total_amount'];
        });

        // Format status breakdown
        $formattedStatusBreakdown = [];
        $statusLabels = [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];

        foreach ($statusBreakdown as $status => $data) {
            $formattedStatusBreakdown[] = [
                'status' => $status,
                'label' => $statusLabels[$status] ?? ucfirst($status),
                'count' => $data['count'],
                'total_amount' => $data['total_amount'],
                'formatted_total' => number_format($data['total_amount'], 2, '.', ','),
                'percentage' => $expenses->count() > 0 ? round(($data['count'] / $expenses->count()) * 100, 2) : 0,
                'color' => $this->getStatusColor($status),
            ];
        }

        // Sort status breakdown by count descending
        usort($formattedStatusBreakdown, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        // Format expense type breakdown
        $formattedExpenseTypeBreakdown = [];
        foreach ($expenseTypeBreakdown as $typeName => $data) {
            $formattedExpenseTypeBreakdown[] = [
                'type' => $typeName,
                'count' => $data['count'],
                'total_amount' => $data['total_amount'],
                'formatted_total' => number_format($data['total_amount'], 2, '.', ','),
                'amount_sign' => $data['amount_sign'],
                'percentage' => $expenses->count() > 0 ? round(($data['count'] / $expenses->count()) * 100, 2) : 0,
            ];
        }

        // Sort expense type breakdown by count descending
        usort($formattedExpenseTypeBreakdown, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return [
            'total_count' => $expenses->count(),
            'total_amount' => $totalAmount,
            'formatted_total_amount' => number_format($totalAmount, 2, '.', ','),
            'average_amount' => $expenses->count() > 0 ? $totalAmount / $expenses->count() : 0,
            'formatted_average_amount' => $expenses->count() > 0 ? number_format($totalAmount / $expenses->count(), 2, '.', ',') : '0.00',
            'currency_breakdown' => $formattedCurrencyBreakdown,
            'status_breakdown' => $formattedStatusBreakdown,
            'expense_type_breakdown' => $formattedExpenseTypeBreakdown,
            'date_range' => $dateRange,
            'recent_count' => $recentCount,
            'pending_approval_count' => $pendingApprovalCount,
            'has_receipts_count' => $this->getReceiptCount(),
            'avg_processing_days' => $this->getAverageProcessingDays(),
        ];
    }

    /**
     * Get the applied filters information.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private function getAppliedFilters(Request $request): array
    {
        $filters = [];
        $filterParams = [
            'status' => 'Status',
            'currency' => 'Currency',
            'expense_type' => 'Expense Type',
            'date_from' => 'Date From',
            'date_to' => 'Date To',
            'amount_min' => 'Minimum Amount',
            'amount_max' => 'Maximum Amount',
            'merchant_name' => 'Merchant Name',
            'has_receipts' => 'Has Receipts',
            'user_id' => 'User ID',
        ];

        foreach ($filterParams as $param => $label) {
            $value = $request->query($param);
            if ($value !== null && $value !== '') {
                $filters[] = [
                    'key' => $param,
                    'label' => $label,
                    'value' => $value,
                    'formatted_value' => $this->formatFilterValue($param, $value),
                ];
            }
        }

        return [
            'applied' => $filters,
            'count' => count($filters),
            'has_filters' => count($filters) > 0,
        ];
    }

    /**
     * Format a filter value for display.
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private function formatFilterValue(string $key, $value): string
    {
        switch ($key) {
            case 'status':
                $statusLabels = [
                    'draft' => 'Draft',
                    'submitted' => 'Submitted',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ];
                return $statusLabels[$value] ?? ucfirst($value);

            case 'has_receipts':
                return $value === '1' || $value === 'true' ? 'Yes' : 'No';

            case 'amount_min':
            case 'amount_max':
                return is_numeric($value) ? number_format((float) $value, 2) : (string) $value;

            case 'date_from':
            case 'date_to':
                try {
                    return \Carbon\Carbon::parse($value)->format('M j, Y');
                } catch (\Exception $e) {
                    return (string) $value;
                }

            default:
                return (string) $value;
        }
    }

    /**
     * Get status color for UI.
     *
     * @param string $status
     * @return string
     */
    private function getStatusColor(string $status): string
    {
        $statusColors = [
            'draft' => 'gray',
            'submitted' => 'blue',
            'approved' => 'green',
            'rejected' => 'red',
        ];

        return $statusColors[$status] ?? 'gray';
    }

    /**
     * Get count of expenses with receipts.
     *
     * @return int
     */
    private function getReceiptCount(): int
    {
        $count = 0;
        
        foreach ($this->collection as $expense) {
            if ($expense->relationLoaded('metadata')) {
                $hasReceipt = $expense->metadata->where('metadata_type', 'file')->isNotEmpty();
                if ($hasReceipt) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get average processing days for approved/rejected expenses.
     *
     * @return float|null
     */
    private function getAverageProcessingDays(): ?float
    {
        $processedExpenses = [];
        
        foreach ($this->collection as $expense) {
            if (in_array($expense->status, ['approved', 'rejected']) && 
                $expense->create_time && 
                $expense->approved_at) {
                $processingDays = $expense->create_time->diffInDays($expense->approved_at);
                $processedExpenses[] = $processingDays;
            }
        }

        if (empty($processedExpenses)) {
            return null;
        }

        return round(array_sum($processedExpenses) / count($processedExpenses), 1);
    }

    /**
     * Add additional collection metadata.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Expenses retrieved successfully',
            'timestamp' => now()->toISOString(),
            'version' => '1.0',
            'links' => [
                'self' => $request->fullUrl(),
                'create' => route('api.pocket-expenses.store'),
                'upload_csv' => route('api.pocket-expenses.upload-csv'),
            ],
            'available_actions' => $this->getAvailableActions($request),
            'export_options' => $this->getExportOptions($request),
        ];
    }

    /**
     * Get available actions for the current user.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private function getAvailableActions(Request $request): array
    {
        $user = $request->user();
        $clientId = $request