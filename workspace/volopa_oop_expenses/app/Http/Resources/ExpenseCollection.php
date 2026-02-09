## Code: app/Http/Resources/ExpenseCollection.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ExpenseCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     */
    public $collects = ExpenseResource::class;

    /**
     * Transform the resource collection into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($expense) use ($request) {
                return new ExpenseResource($expense);
            }),
            'summary' => $this->getSummaryData($request),
            'filters' => $this->getAvailableFilters($request),
            'aggregates' => $this->getAggregateData($request),
        ];
    }

    /**
     * Get summary data for the expense collection.
     */
    private function getSummaryData(Request $request): array
    {
        $collection = $this->collection;
        
        if ($collection->isEmpty()) {
            return [
                'total_expenses' => 0,
                'total_amount_by_currency' => [],
                'status_breakdown' => [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                ],
                'date_range' => null,
                'unique_merchants' => 0,
                'unique_currencies' => 0,
                'average_amount' => null,
                'highest_amount' => null,
                'lowest_amount' => null,
            ];
        }

        // Calculate totals by currency
        $amountsByCurrency = [];
        $statusCounts = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];
        $merchants = [];
        $currencies = [];
        $amounts = [];
        $dates = [];

        foreach ($collection as $expense) {
            // Amount by currency
            $currency = $expense->currency ?? 'USD';
            $amount = $expense->amount ?? 0;
            
            if (!isset($amountsByCurrency[$currency])) {
                $amountsByCurrency[$currency] = [
                    'currency' => $currency,
                    'total' => 0.0,
                    'count' => 0,
                    'symbol' => $this->getCurrencySymbol($currency),
                ];
            }
            
            $amountsByCurrency[$currency]['total'] += $amount;
            $amountsByCurrency[$currency]['count']++;
            
            // Status breakdown
            $status = $expense->status ?? 'pending';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
            
            // Collect unique data
            $merchants[] = $expense->merchant_name ?? '';
            $currencies[] = $currency;
            $amounts[] = abs($amount);
            
            if ($expense->date) {
                $dates[] = $expense->date->format('Y-m-d');
            }
        }

        // Format amounts by currency
        foreach ($amountsByCurrency as &$currencyData) {
            $currencyData['total'] = round($currencyData['total'], 2);
            $currencyData['formatted_total'] = $currencyData['symbol'] . ' ' . number_format(abs($currencyData['total']), 2);
            $currencyData['average'] = round($currencyData['total'] / max($currencyData['count'], 1), 2);
            $currencyData['formatted_average'] = $currencyData['symbol'] . ' ' . number_format(abs($currencyData['average']), 2);
        }

        return [
            'total_expenses' => $collection->count(),
            'total_amount_by_currency' => array_values($amountsByCurrency),
            'status_breakdown' => $statusCounts,
            'date_range' => $this->getDateRange($dates),
            'unique_merchants' => count(array_unique(array_filter($merchants))),
            'unique_currencies' => count(array_unique($currencies)),
            'average_amount' => !empty($amounts) ? round(array_sum($amounts) / count($amounts), 2) : null,
            'highest_amount' => !empty($amounts) ? max($amounts) : null,
            'lowest_amount' => !empty($amounts) ? min($amounts) : null,
            'recent_activity' => $this->getRecentActivitySummary($collection),
        ];
    }

    /**
     * Get available filters based on the collection data.
     */
    private function getAvailableFilters(Request $request): array
    {
        $collection = $this->collection;
        
        if ($collection->isEmpty()) {
            return [
                'statuses' => [],
                'currencies' => [],
                'transaction_types' => [],
                'countries' => [],
                'sources' => [],
                'categories' => [],
                'date_range' => null,
                'amount_range' => null,
            ];
        }

        $statuses = [];
        $currencies = [];
        $transactionTypes = [];
        $countries = [];
        $sources = [];
        $categories = [];
        $amounts = [];

        foreach ($collection as $expense) {
            if ($expense->status) {
                $statuses[] = $expense->status;
            }
            if ($expense->currency) {
                $currencies[] = $expense->currency;
            }
            if ($expense->transaction_type) {
                $transactionTypes[] = $expense->transaction_type;
            }
            if ($expense->country) {
                $countries[] = $expense->country;
            }
            if ($expense->source) {
                $sources[] = $expense->source;
            }
            if ($expense->category) {
                $categories[] = $expense->category;
            }
            if ($expense->amount) {
                $amounts[] = abs($expense->amount);
            }
        }

        return [
            'statuses' => $this->formatFilterOptions(array_unique($statuses)),
            'currencies' => $this->formatCurrencyOptions(array_unique($currencies)),
            'transaction_types' => $this->formatFilterOptions(array_unique($transactionTypes)),
            'countries' => $this->formatFilterOptions(array_unique(array_filter($countries))),
            'sources' => $this->formatFilterOptions(array_unique(array_filter($sources))),
            'categories' => $this->formatFilterOptions(array_unique(array_filter($categories))),
            'date_range' => $this->getCollectionDateRange($collection),
            'amount_range' => !empty($amounts) ? [
                'min' => min($amounts),
                'max' => max($amounts),
                'step' => $this->calculateAmountStep($amounts),
            ] : null,
        ];
    }

    /**
     * Get aggregate data for reporting purposes.
     */
    private function getAggregateData(Request $request): array
    {
        $collection = $this->collection;
        
        if ($collection->isEmpty()) {
            return [
                'monthly_totals' => [],
                'top_merchants' => [],
                'expense_trends' => [],
                'approval_metrics' => [],
            ];
        }

        return [
            'monthly_totals' => $this->getMonthlyTotals($collection),
            'top_merchants' => $this->getTopMerchants($collection, 10),
            'expense_trends' => $this->getExpenseTrends($collection),
            'approval_metrics' => $this->getApprovalMetrics($collection),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        $baseWith = [
            'meta' => [
                'timestamp' => now()->toISOString(),
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => app()->getLocale(),
                'resource_type' => 'expense_collection',
                'version' => '1.0',
            ],
        ];

        // Add pagination meta if this is a paginated collection
        if ($this->resource instanceof LengthAwarePaginator) {
            $baseWith['meta']['pagination'] = [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
                'path' => $this->resource->path(),
            ];

            $baseWith['links'] = [
                'first' => $this->resource->url(1),
                'last' => $this->resource->url($this->resource->lastPage()),
                'prev' => $this->resource->previousPageUrl(),
                'next' => $this->resource->nextPageUrl(),
                'self' => $this->resource->url($this->resource->currentPage()),
            ];
        }

        return $baseWith;
    }

    /**
     * Customize the pagination information for the resource.
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return array_merge($default, [
            'meta' => array_merge($default['meta'] ?? [], [
                'resource_type' => 'expense_collection',
                'collection_summary' => $this->getCollectionMeta(),
                'per_page_options' => [10, 15, 25, 50, 100],
                'sort_options' => [
                    'date_desc' => 'Date (Newest First)',
                    'date_asc' => 'Date (Oldest First)',
                    'amount_desc' => 'Amount (Highest First)',
                    'amount_asc' => 'Amount (Lowest First)',
                    'merchant_asc' => 'Merchant (A-Z)',
                    'merchant_desc' => 'Merchant (Z-A)',
                    'status_asc' => 'Status (A-Z)',
                    'created_desc' => 'Created (Newest First)',
                    'created_asc' => 'Created (Oldest First)',
                ],
                'filter_tips' => [
                    'Use status filter to find pending approvals',
                    'Filter by currency to see regional expenses',
                    'Use date range to analyze specific periods',
                    'Combine filters for detailed analysis',
                ],
            ]),
        ]);
    }

    /**
     * Get date range from array of dates.
     */
    private function getDateRange(array $dates): ?array
    {
        if (empty($dates)) {
            return null;
        }

        $sortedDates = array_unique($dates);
        sort($sortedDates);

        return [
            'start_date' => reset($sortedDates),
            'end_date' => end($sortedDates),
            'span_days' => \Carbon\Carbon::parse(reset($sortedDates))
                ->diffInDays(\Carbon\Carbon::parse(end($sortedDates))),
        ];
    }

    /**
     * Get collection date range.
     */
    private function getCollectionDateRange(Collection $collection): ?array
    {
        $dates = $collection->map(function ($expense) {
            return $expense->date ? $expense->date->format('Y-m-d') : null;
        })->filter()->toArray();

        return $this->getDateRange($dates);
    }

    /**
     * Format filter options with labels.
     */
    private function formatFilterOptions(array $options): array
    {
        return array_map(function ($option) {
            return [
                'value' => $option,
                'label' => ucfirst(str_replace('_', ' ', $option)),
                'count' => $this->collection->where('status', $option)->count(),
            ];
        }, array_values($options));
    }

    /**
     * Format currency options with symbols.
     */
    private function formatCurrencyOptions(array $currencies): array
    {
        return array_map(function ($currency) {
            return [
                'value' => $currency,
                'label' => $currency,
                'symbol' => $this->getCurrencySymbol($currency),
                'count' => $this->collection->where('currency', $currency)->count(),
            ];
        }, array_values($currencies));
    }

    /**
     * Get currency symbol.
     */
    private function getCurrencySymbol(string $currency): string
    {
        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
            'CAD' => 'C$', 'AUD' => 'A$', 'CHF' => 'Fr', 'CNY' => '¥',
            'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł',
            'CZK' => 'Kč', 'HUF' => 'Ft', 'RUB' => '₽', 'INR' => '₹',
            'BRL' => 'R$', 'MXN' => '$', 'ZAR' => 'R', 'KRW' => '₩',
            'SGD' => 'S$', 'HKD' => 'HK$', 'NZD' => 'NZ$', 'TRY' => '₺',
            'THB' => '฿', 'MYR' => 'RM', 'PHP' => '₱', 'AED' => 'د.إ',
            'SAR' => '﷼', 'JOD' => 'د.ا', 'LBP' => '£', 'EGP' => '£',
        ];

        return $symbols[$currency] ?? $currency;
    }

    /**
     * Calculate appropriate step for amount range slider.
     */
    private function calculateAmountStep(array $amounts): float
    {
        if (empty($amounts)) {
            return 1.0;
        }

        $max = max($amounts);
        
        if ($max <= 100) {
            return 1.0;
        } elseif ($max <= 1000) {
            return 10.0;
        } elseif ($max <= 10000) {
            return 50.0;
        } else {
            return 100.0;
        }
    }

    /**
     * Get monthly totals for trend analysis.
     */
    private function getMonthlyTotals(Collection $collection): array
    {
        $monthlyTotals = [];
        
        foreach ($collection as $expense) {
            if (!$expense->date) continue;
            
            $monthKey = $expense->date->format('Y-m');
            $currency = $expense->currency ?? 'USD';
            $amount = abs($expense->amount ?? 0);
            
            if (!isset($monthlyTotals[$monthKey])) {
                $monthlyTotals[$monthKey] = [
                    'month' => $monthKey,
                    'month_name' => $expense->date->format('F Y'),
                    'currencies' => [],
                    'total_count' => 0,
                ];
            }
            
            if (!isset($monthlyTotals[$monthKey]['currencies'][$currency])) {
                $monthlyTotals[$monthKey]['currencies'][$currency] = [
                    'currency' => $currency,
                    'symbol' => $this->getCurrencySymbol($currency),
                    'total' => 0.0,
                    'count' => 0,
                ];
            }
            
            $monthlyTotals[$monthKey]['currencies'][$currency]['total'] += $amount;