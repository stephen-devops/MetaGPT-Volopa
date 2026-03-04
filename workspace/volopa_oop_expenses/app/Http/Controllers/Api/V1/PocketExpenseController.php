## Code: app/Http/Controllers/Api/V1/PocketExpenseController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Models\PocketExpense;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

/**
 * PocketExpenseController
 * 
 * Thin controller for pocket expense operations.
 * Delegates business logic to PocketExpenseService and FXConversionService,
 * shapes responses using API Resources following Laravel best practices.
 * 
 * Endpoints:
 * - GET    /api/v1/pocket-expenses          (index)
 * - POST   /api/v1/pocket-expenses          (store)
 * - GET    /api/v1/pocket-expenses/{id}     (show)
 * - PUT    /api/v1/pocket-expenses/{id}     (update)
 * - DELETE /api/v1/pocket-expenses/{id}     (destroy)
 * - POST   /api/v1/pocket-expenses/{id}/approve (approve)
 * - POST   /api/v1/pocket-expenses/convert-fx   (convertFX)
 */
class PocketExpenseController extends Controller
{
    /**
     * Pocket expense service instance.
     *
     * @var PocketExpenseService
     */
    private PocketExpenseService $pocketExpenseService;

    /**
     * FX conversion service instance.
     *
     * @var FXConversionService
     */
    private FXConversionService $fxConversionService;

    /**
     * Default pagination size for index requests.
     *
     * @var int
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Maximum pagination size allowed.
     *
     * @var int
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Valid status values for filtering.
     *
     * @var array<int, string>
     */
    private const VALID_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    /**
     * Valid sort fields.
     *
     * @var array<int, string>
     */
    private const VALID_SORT_FIELDS = [
        'id',
        'date',
        'merchant_name',
        'amount',
        'currency',
        'status',
        'create_time',
        'update_time'
    ];

    /**
     * Default sort field.
     *
     * @var string
     */
    private const DEFAULT_SORT_FIELD = 'create_time';

    /**
     * Default sort direction.
     *
     * @var string
     */
    private const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Valid 3-letter ISO currency codes.
     *
     * @var array<int, string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'KRW', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR',
        'PLN', 'DKK', 'CZK', 'HUF', 'ILS', 'AED', 'SAR', 'THB', 'MYR', 'PHP'
    ];

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $pocketExpenseService
     * @param FXConversionService $fxConversionService
     */
    public function __construct(
        PocketExpenseService $pocketExpenseService,
        FXConversionService $fxConversionService
    ) {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->fxConversionService = $fxConversionService;
        
        // Apply OAuth2 middleware for all routes
        $this->middleware('auth:api');
        
        // Apply throttling middleware
        $this->middleware('throttle:60,1');
    }

    /**
     * Display a listing of pocket expenses.
     *
     * GET /api/v1/pocket-expenses
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                return $this->errorResponse('Invalid user context', 401);
            }

            // Authorize the request
            $this->authorize('viewAny', PocketExpense::class);

            // Build query with relationships and scoping
            $query = PocketExpense::with([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.expenseSource',
                'metadata.fileStore',
                'metadata.additionalField'
            ])->where('client_id', $user->client_id);

            // Apply filters
            $this->applyFilters($query, $request);

            // Apply ordering
            $this->applyOrdering($query, $request);

            // Get pagination parameters
            $perPage = min(
                (int) $request->get('per_page', self::DEFAULT_PER_PAGE),
                self::MAX_PER_PAGE
            );

            // Execute paginated query
            $expenses = $query->paginate($perPage);

            Log::info('Pocket expenses retrieved', [
                'user_id' => $user->id,
                'client_id' => $user->client_id,
                'total_count' => $expenses->total(),
                'filters' => $request->only(['status', 'date_from', 'date_to', 'currency', 'merchant_name']),
            ]);

            return $this->successResponse(
                PocketExpenseResource::collection($expenses),
                'Pocket expenses retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expenses', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve pocket expenses',
                500,
                ['error' => 'An unexpected error occurred while retrieving expenses']
            );
        }
    }

    /**
     * Store a newly created pocket expense.
     *
     * POST /api/v1/pocket-expenses
     * 
     * @param StorePocketExpenseRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                return $this->errorResponse('Invalid user context', 401);
            }

            $validatedData = $request->validated();

            // Create the expense using the service
            $expense = $this->pocketExpenseService->createExpense(
                $validatedData,
                $user->id,
                $user->client_id
            );

            // Load relationships for response
            $expense->load([
                