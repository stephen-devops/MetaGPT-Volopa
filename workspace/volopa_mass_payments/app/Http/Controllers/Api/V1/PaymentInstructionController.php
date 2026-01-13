## Code: app/Http/Controllers/Api/V1/PaymentInstructionController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetPaymentInstructionsRequest;
use App\Http\Resources\PaymentInstructionResource;
use App\Models\PaymentInstruction;
use App\Models\MassPaymentFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;
use Exception;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class PaymentInstructionController extends Controller
{
    /**
     * Items per page for pagination
     */
    protected int $perPage;

    /**
     * Maximum items per page allowed
     */
    protected int $maxPerPage;

    /**
     * Cache TTL for statistics in minutes
     */
    protected int $statisticsCacheTtl;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->perPage = config('mass-payments.pagination.per_page', 20);
        $this->maxPerPage = config('mass-payments.pagination.max_per_page', 100);
        $this->statisticsCacheTtl = config('mass-payments.cache.currency_cache_minutes', 60);

        // Apply authentication middleware
        $this->middleware('auth:api');
        
        // Apply Volopa authentication middleware
        $this->middleware('volopa.auth');

        // Apply throttling
        $this->middleware('throttle:' . config('mass-payments.security.rate_limit_per_minute', 60) . ',1');
    }

    /**
     * Display a listing of payment instructions.
     *
     * @param GetPaymentInstructionsRequest $request
     * @return JsonResponse
     */
    public function index(GetPaymentInstructionsRequest $request): JsonResponse
    {
        try {
            Log::info('Payment instructions index request', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'filters' => $request->except(['page', 'per_page']),
            ]);

            // Get validated data
            $validatedData = $request->validated();

            // Validate pagination parameters
            $perPage = min(
                (int) $request->get('per_page', $this->perPage),
                $this->maxPerPage
            );

            if ($perPage < 1) {
                $perPage = $this->perPage;
            }

            // Build base query with global scope (client filtering)
            $query = PaymentInstruction::query();

            // Apply eager loading based on include parameter
            $includes = $this->getEagerLoadRelations($validatedData['include'] ?? []);
            if (!empty($includes)) {
                $query->with($includes);
            }

            // Apply filters
            $this->applyFilters($query, $validatedData);

            // Apply sorting
            $this->applySorting($query, $validatedData);

            // Add counts for statistics if requested
            if ($validatedData['include_statistics'] ?? false) {
                $query->withCount([
                    'massPaymentFile',
                ]);
            }

            // Filter by final states only
            if ($validatedData['final_states_only'] ?? false) {
                $query->whereIn('status', [
                    PaymentInstruction::STATUS_COMPLETED,
                    PaymentInstruction::STATUS_FAILED,
                    PaymentInstruction::STATUS_CANCELLED,
                ]);
            }

            // Filter by processable states only
            if ($validatedData['processable_only'] ?? false) {
                $query->whereNotIn('status', [
                    PaymentInstruction::STATUS_COMPLETED,
                    PaymentInstruction::STATUS_FAILED,
                    PaymentInstruction::STATUS_CANCELLED,
                    PaymentInstruction::STATUS_VALIDATION_FAILED,
                ]);
            }

            // Group by if specified
            if (!empty($validatedData['group_by'])) {
                $this->applyGroupBy($query, $validatedData['group_by']);
            }

            // Check if export is requested
            if (!empty($validatedData['export_format'])) {
                return $this->exportPaymentInstructions($query, $validatedData);
            }

            // Paginate results
            $instructions = $query->paginate($perPage);

            // Get statistics if requested
            $statistics = null;
            if ($validatedData['include_statistics'] ?? false) {
                $statistics = $this->getPaymentInstructionStatistics($validatedData);
            }

            Log::info('Payment instructions retrieved successfully', [
                'user_id' => Auth::id(),
                'total_instructions' => $instructions->total(),
                'current_page' => $instructions->currentPage(),
                'per_page' => $instructions->perPage(),
                'filters_applied' => count($this->getAppliedFilters($validatedData)),
            ]);

            $response = [
                'success' => true,
                'message' => 'Payment instructions retrieved successfully',
                'data' => PaymentInstructionResource::collection($instructions),
                'meta' => [
                    'pagination' => [
                        'current_page' => $instructions->currentPage(),
                        'last_page' => $instructions->lastPage(),
                        'per_page' => $instructions->perPage(),
                        'total' => $instructions->total(),
                        'from' => $instructions->firstItem(),
                        'to' => $instructions->lastItem(),
                        'has_more_pages' => $instructions->hasMorePages(),
                    ],
                    'filters_applied' => $this->getAppliedFilters($validatedData),
                ],
            ];

            if ($statistics !== null) {
                $response['meta']['statistics'] = $statistics;
            }

            return response()->json($response, Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Failed to retrieve payment instructions', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment instructions',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified payment instruction.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            Log::info('Payment instruction show request', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
            ]);

            // Find the payment instruction with relationships
            $instruction = PaymentInstruction::with([
                'massPaymentFile:id,original_filename,status,currency,total_amount,client_id,created_at,approved_at',
                'massPaymentFile.client:id,name,code',
                'massPaymentFile.uploader:id,name,email',
                'massPaymentFile.approver:id,name,email',
                'beneficiary:id,name,account_number,bank_code,country,address,city,created_at'
            ])
            ->findOrFail($id);

            // Check authorization - user must belong to same client as the file
            $user = Auth::user();
            if ($user->client_id !== $instruction->massPaymentFile->client_id) {
                Log::warning('Unauthorized access attempt to payment instruction', [
                    'instruction_id' => $id,
                    'user_id' => $user->id,
                    'user_client_id' => $user->client_id,
                    'instruction_client_id' => $instruction->massPaymentFile->client_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Payment instruction not found or you do not have permission to view it.',
                ], Response::HTTP_NOT_FOUND);
            }

            Log::info('Payment instruction retrieved successfully', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
                'status' => $instruction->status,
                'amount' => $instruction->amount,
                'currency' => $instruction->currency,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment instruction retrieved successfully',
                'data' => new PaymentInstructionResource($instruction),
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Payment instruction not found', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment instruction not found',
            ], Response::HTTP_NOT_FOUND);

        } catch (Exception $e) {
            Log::error('Failed to retrieve payment instruction', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment instruction',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Apply filters to the query
     *
     * @param Builder $query
     * @param array $filters
     * @return void
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        // File ID filter
        if (!empty($filters['file_id'])) {
            $query->where('mass_payment_file_id', $filters['file_id']);
        }

        // Status filter (single)
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Status filter (multiple)
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $query->whereIn('status', $filters['statuses']);
        }

        // Currency filter (single)
        if (!empty($filters['currency'])) {
            $query->where('currency', strtoupper($filters['currency']));
        }

        // Currency filter (multiple)
        if (!empty($filters['currencies']) && is_array($filters['currencies'])) {
            $currencies = array_map('strtoupper', $filters['currencies']);
            $query->whereIn('currency', $currencies);
        }

        // Beneficiary ID filter
        if (!empty($filters['beneficiary_id'])) {
            $query->where('beneficiary_id', (int) $filters['beneficiary_id']);
        }

        // Amount range filters
        if (isset($filters['min_amount']) && is_numeric($filters['min_amount'])) {
            $query->where('amount', '>=', (float) $filters['min_amount']);
        }

        if (isset($filters['max_amount']) && is_numeric($filters['max_amount'])) {
            $query->where('amount', '<=', (float) $filters['max_amount']);
        }

        // Date range filters
        if (!empty($filters['created_from'])) {
            try {
                $createdFrom = \Carbon\Carbon::parse($filters['created_from']);
                $query->whereDate('created_at', '>=', $createdFrom);
            } catch (Exception $e) {
                Log::warning('Invalid created_from date format', [
                    'value' => $filters['created_from'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($filters['created_to'])) {
            try {
                $createdTo = \Carbon\Carbon::parse($filters['created_to']);
                $query->whereDate('created_at', '<=', $createdTo);
            } catch (Exception $e) {
                Log::warning('Invalid created_to date format', [
                    'value' => $filters['created_to'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Purpose code filter
        if (!empty($filters['purpose_code'])) {
            $query->where('purpose_code', $filters['purpose_code']);
        }

        // Reference search
        if (!empty($filters['reference'])) {
            $query->where('reference', 'like', '%' . $filters['reference'] . '%');
        }

        // Row number range
        if (isset($filters['min_row']) && is_numeric($filters['min_row'])) {
            $query->where('row_number', '>=', (int) $filters['min_row']);
        }

        if (isset($filters['max_row']) && is_numeric($filters['max_row'])) {
            $query->where('row_number', '<=', (int) $filters['max_row']);
        }

        // Validation errors filter
        if (isset($filters['has_validation_errors'])) {
            if ($filters['has_validation_errors']) {
                $query->whereNotNull('validation_errors');
            } else {
                $query->whereNull('validation_errors');
            }
        }

        // Search query for full-text search
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('purpose_code', 'like', "%{$search}%")
                  ->orWhereHas('beneficiary', function ($beneficiaryQuery) use ($search) {
                      $beneficiaryQuery->where('name', 'like', "%{$search}%")
                                      ->orWhere('account_number', 'like', "%{$search}%");
                  })
                  ->orWhereHas('massPaymentFile', function ($fileQuery) use ($search) {
                      $fileQuery->where('original_filename', 'like', "%{$search}%");
                  });
            });
        }
    }

    /**
     * Apply sorting to the query
     *
     * @param Builder $query
     * @param array $params
     * @return void
     */
    protected function applySorting(Builder $query, array $params): void
    {
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDirection = $params['sort_direction'] ?? 'desc';

        $allowedSortFields = [
            'created_at',
            'updated_at',
            'amount',
            'currency',
            'status',
            'row_number',
            'reference',
            'purpose_code',
        ];

        if (in_array($sortBy, $allowedSortFields)) {
            $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) 
                ? strtolower($sortDirection) 
                : 'desc';
            $query->orderBy($sortBy, $sortDirection);
        } else {
            // Default sorting
            $query->orderBy('created_at', 'desc');
        }
    }

    /**
     * Apply grouping to the query
     *
     * @param Builder $query
     * @param string $groupBy
     * @return void
     */
    protected function applyGroupBy(Builder $query, string $groupBy): void
    {
        $allowedGroupByFields = ['status', 'currency', 'purpose_code', 'beneficiary_id'];

        if (in_array($groupBy, $allowedGroupByFields)) {
            $query->select([
                $groupBy,
                DB::raw('COUNT(*