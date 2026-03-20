<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * PocketExpenseCollection Resource
 * 
 * Transforms paginated collection of PocketExpense models into consistent API format.
 * Provides standardized pagination metadata and expense list structure.
 * Used for expense listing endpoints with proper data shaping and performance optimization.
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
     * Indicates if the resource's collection keys should be preserved.
     *
     * @var bool
     */
    public $preserveKeys = false;

    /**
     * Transform the resource collection into an array.
     * 
     * Provides consistent structure for paginated expense collections with:
     * - Transformed expense data using PocketExpenseResource
     * - Standardized pagination metadata
     * - Additional collection-level statistics
     * - Performance-optimized data loading
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->transform(function ($expense) use ($request) {
                return new PocketExpenseResource($expense);
            }),
            'meta' => $this->getMeta(),
            'links' => $this->getLinks(),
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Get pagination and collection metadata.
     *
     * @return array<string, mixed>
     */
    protected function getMeta(): array
    {
        $paginationInfo = $this->resource->toArray();
        
        return [
            'pagination' => [
                'current_page' => $paginationInfo['current_page'] ?? 1,
                'per_page' => $paginationInfo['per_page'] ?? 15,
                'total' => $paginationInfo['total'] ?? 0,
                'last_page' => $paginationInfo['last_page'] ?? 1,
                'from' => $paginationInfo['from'] ?? null,
                'to' => $paginationInfo['to'] ?? null,
            ],
            'collection' => [
                'count' => $this->collection->count(),
                'has_more' => ($paginationInfo['current_page'] ?? 1) < ($paginationInfo['last_page'] ?? 1),
            ],
        ];
    }

    /**
     * Get pagination navigation links.
     *
     * @return array<string, string|null>
     */
    protected function getLinks(): array
    {
        $paginationInfo = $this->resource->toArray();
        
        return [
            'first' => $paginationInfo['first_page_url'] ?? null,
            'last' => $paginationInfo['last_page_url'] ?? null,
            'prev' => $paginationInfo['prev_page_url'] ?? null,
            'next' => $paginationInfo['next_page_url'] ?? null,
            'self' => $paginationInfo['path'] ?? null,
        ];
    }

    /**
     * Get collection-level summary statistics.
     * 
     * Provides aggregate information about the expenses in the current page/collection:
     * - Status distribution
     * - Currency breakdown
     * - Amount totals
     * - Date range information
     *
     * @return array<string, mixed>
     */
    protected function getSummary(): array
    {
        $expenses = $this->collection;
        
        if ($expenses->isEmpty()) {
            return [
                'status_counts' => [
                    'draft' => 0,
                    'submitted' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                ],
                'currency_totals' => [],
                'total_expenses' => 0,
                'date_range' => null,
            ];
        }

        // Calculate status distribution
        $statusCounts = [
            'draft' => 0,
            'submitted' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        foreach ($expenses as $expense) {
            $status = $expense->status ?? 'draft';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        // Calculate currency totals
        $currencyTotals = [];
        foreach ($expenses as $expense) {
            $currency = $expense->currency ?? 'USD';
            $amount = (float) ($expense->amount ?? 0.0);
            
            if (!isset($currencyTotals[$currency])) {
                $currencyTotals[$currency] = [
                    'currency' => $currency,
                    'total_amount' => 0.0,
                    'count' => 0,
                ];
            }
            
            $currencyTotals[$currency]['total_amount'] += $amount;
            $currencyTotals[$currency]['count']++;
            
            // Round to 2 decimal places for currency precision
            $currencyTotals[$currency]['total_amount'] = round($currencyTotals[$currency]['total_amount'], 2);
        }

        // Get date range information
        $dates = $expenses->pluck('date')->filter()->map(function ($date) {
            return is_string($date) ? \Carbon\Carbon::parse($date) : $date;
        });

        $dateRange = null;
        if ($dates->isNotEmpty()) {
            $minDate = $dates->min();
            $maxDate = $dates->max();
            
            $dateRange = [
                'earliest' => $minDate ? $minDate->format('Y-m-d') : null,
                'latest' => $maxDate ? $maxDate->format('Y-m-d') : null,
                'span_days' => $minDate && $maxDate ? $minDate->diffInDays($maxDate) : 0,
            ];
        }

        return [
            'status_counts' => $statusCounts,
            'currency_totals' => array_values($currencyTotals), // Convert to indexed array
            'total_expenses' => $expenses->count(),
            'date_range' => $dateRange,
        ];
    }

    /**
     * Customize the outgoing response for the resource.
     * 
     * Adds additional HTTP headers and response metadata for API clients:
     * - Content type specification
     * - Cache control headers
     * - API version information
     * - Performance metrics
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Http\Response $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('Content-Type', 'application/json');
        $response->header('X-API-Version', '1.0');
        
        // Add cache control for expense collections (short cache due to frequent updates)
        $response->header('Cache-Control', 'private, max-age=60');
        
        // Add collection metadata to response headers for client optimization
        $collectionSize = $this->collection->count();
        $response->header('X-Collection-Count', (string) $collectionSize);
        
        // Add pagination information to headers for client convenience
        $paginationInfo = $this->resource->toArray();
        if (isset($paginationInfo['total'])) {
            $response->header('X-Total-Count', (string) $paginationInfo['total']);
        }
        if (isset($paginationInfo['current_page'])) {
            $response->header('X-Current-Page', (string) $paginationInfo['current_page']);
        }
        if (isset($paginationInfo['last_page'])) {
            $response->header('X-Last-Page', (string) $paginationInfo['last_page']);
        }
    }

    /**
     * Get additional data that should be returned with the resource array.
     * 
     * Provides context information that doesn't belong in the main data structure:
     * - API documentation links
     * - Client configuration hints
     * - Feature availability flags
     * - Request processing metadata
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'api_info' => [
                'version' => '1.0',
                'endpoint' => 'pocket-expenses',
                'documentation' => url('/docs/api/v1/pocket-expenses'),
            ],
            'client_hints' => [
                'supports_filtering' => true,
                'supports_sorting' => true,
                'supports_search' => true,
                'max_per_page' => 100,
                'default_per_page' => 15,
            ],
            'features' => [
                'bulk_operations' => true,
                'export_csv' => true,
                'status_updates' => true,
                'metadata_support' => true,
            ],
            'request_info' => [
                'processed_at' => now()->toISOString(),
                'request_id' => $request->header('X-Request-ID', \Illuminate\Support\Str::uuid()),
                'client_id' => auth()->user()->client_id ?? null,
            ],
        ];
    }

    /**
     * Create a new resource collection with optimized loading.
     * 
     * Factory method that ensures proper eager loading for performance optimization.
     * Pre-loads related models to prevent N+1 queries in the collection transformation.
     *
     * @param mixed $resource The paginated expense collection
     * @return static
     */
    public static function make($resource): static
    {
        // If the resource is a paginator, ensure we have the necessary relationships loaded
        if (method_exists($resource, 'getCollection')) {
            $collection = $resource->getCollection();
            
            // Eager load relationships that will be used in PocketExpenseResource
            if ($collection->isNotEmpty() && method_exists($collection->first(), 'load')) {
                $collection->load([
                    'user:id,name,email',
                    'client:id,name',
                    'expenseType:id,option,amount_sign',
                    'createdBy:id,name',
                    'updatedBy:id,name',
                    'approvedBy:id,name',
                    'metadata' => function ($query) {
                        $query->where('deleted', false)->with([
                            'expenseSource:id,name',
                            'transactionCategory:id,name',
                            'project:id,name',
                        ]);
                    },
                ]);
            }
        }
        
        return new static($resource);
    }

    /**
     * Create a collection with applied filters for consistent API responses.
     * 
     * Helper method for controllers to create filtered collections with standard parameters.
     * Applies common filtering, sorting, and pagination logic.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Http\Request $request
     * @return static
     */
    public static function filtered($query, Request $request): static
    {
        // Apply filters from request
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('currency')) {
            $query->where('currency', $request->input('currency'));
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->input('expense_type'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($subQuery) use ($search) {
                $subQuery->where('merchant_name', 'LIKE', "%{$search}%")
                         ->orWhere('merchant_description', 'LIKE', "%{$search}%")
                         ->orWhere('notes', 'LIKE', "%{$search}%");
            });
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'create_time');
        $sortDirection = $request->input('sort_direction', 'desc');
        
        $allowedSortFields = ['create_time', 'date', 'amount', 'merchant_name', 'status'];
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('create_time', 'desc');
        }

        // Apply pagination
        $perPage = min((int) $request->input('per_page', 15), 100);
        $paginatedResults = $query->paginate($perPage);

        return static::make($paginatedResults);
    }

    /**
     * Transform the collection for export purposes (CSV, Excel, etc.).
     * 
     * Provides a flattened data structure suitable for export formats.
     * Removes nested structures and API-specific metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toExportArray(): array
    {
        return $this->collection->map(function ($expense) {
            return [
                'id' => $expense->id ?? null,
                'uuid' => $expense->uuid ?? null,
                'date' => $expense->date ? (is_string($expense->date) ? $expense->date : $expense->date->format('Y-m-d')) : null,
                'merchant_name' => $expense->merchant_name ?? '',
                'merchant_description' => $expense->merchant_description ?? '',
                'currency' => $expense->currency ?? 'USD',
                'amount' => $expense->amount ?? 0.0,
                'vat_amount' => $expense->vat_amount ?? null,
                'status' => $expense->status ?? 'draft',
                'expense_type' => $expense->expenseType->option ?? 'Other',
                'user_name' => $expense->user->name ?? 'Unknown',
                'created_at' => $expense->create_time ? (is_string($expense->create_time) ? $expense->create_time : $expense->create_time->format('Y-m-d H:i:s')) : null,
                'approved_by' => $expense->approvedBy->name ?? null,
                'notes' => $expense->notes ?? '',
            ];
        })->toArray();
    }
}