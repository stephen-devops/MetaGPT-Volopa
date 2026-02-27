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
use App\Services\PocketExpenseFXService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class PocketExpenseController extends Controller
{
    /**
     * The pocket expense service instance.
     *
     * @var PocketExpenseService
     */
    private PocketExpenseService $pocketExpenseService;

    /**
     * The FX service instance.
     *
     * @var PocketExpenseFXService
     */
    private PocketExpenseFXService $fxService;

    /**
     * Default pagination limit for index queries.
     *
     * @var int
     */
    private const DEFAULT_PAGINATION_LIMIT = 50;

    /**
     * Maximum pagination limit allowed.
     *
     * @var int
     */
    private const MAX_PAGINATION_LIMIT = 200;

    /**
     * Valid status values for pocket expenses.
     *
     * @var array<string>
     */
    private const VALID_STATUSES = [
        'draft',
        'submitted',
        'approved',
        'rejected',
    ];

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $pocketExpenseService
     * @param PocketExpenseFXService $fxService
     */
    public function __construct(PocketExpenseService $pocketExpenseService, PocketExpenseFXService $fxService)
    {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->fxService = $fxService;
        
        // Apply auth middleware to all routes
        $this->middleware('auth:api');
        
        // Apply throttle middleware for API rate limiting
        $this->middleware('throttle:api')->only(['store', 'update', 'destroy']);
    }

    /**
     * Display a listing of pocket expenses.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access',
                    'error_code' => 'AUTH_REQUIRED'
                ], 401);
            }

            // Authorize using policy
            $this->authorize('viewAny', PocketExpense::class);

            // Validate and sanitize input parameters
            $validated = $request->validate([
                'client_id' => 'required|integer|min:1|exists:clients,id',
                'status' => 'sometimes|string|in:' . implode(',', self::VALID_STATUSES),
                'date_from' => 'sometimes|date|date_format:Y-m-d',
                'date_to' => 'sometimes|date|date_format:Y-m-d|after_or_equal:date_from',
                'currency' => 'sometimes|string|size:3|alpha',
                'user_id' => 'sometimes|integer|min:1|exists:users,id',
                'expense_type' => 'sometimes|integer|min:1|exists:opt_pocket_expense_type,id',
                'amount_min' => 'sometimes|numeric|min:0',
                'amount_max' => 'sometimes|numeric|min:0|gte:amount_min',
                'merchant_name' => 'sometimes|string|max:180',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:' . self::MAX_PAGINATION_LIMIT,
                'sort_by' => 'sometimes|string|in:id,date,amount,merchant_name,status,create_time,update_time',
                'sort_direction' => 'sometimes|string|in:asc,desc',
                'include_deleted' => 'sometimes|boolean',
            ]);

            // Build query with relationships
            $query = PocketExpense::with([
                'user:id,name,email,role',
                'client:id,name,code',
                'expenseType:id,option,amount_sign',
                'metadata.transactionCategory:id,name',
                'metadata.trackingCode:id,code,description',
                'metadata.project:id,name,code',
                'metadata.expenseSource:id,uuid,name,is_default',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
                'approvedBy:id,name,email'
            ]);

            // Apply client filter (required)
            $query->forClient($validated['client_id']);

            // Apply role-based filtering
            if (!$user->isPrimaryAdmin()) {
                if ($user->isAdmin()) {
                    // Admin gets access to expenses they can manage
                    $managedUserIds = $this->getManagedUserIds($user, $validated['client_id']);
                    $managedUserIds[] = $user->id; // Include own expenses
                    $query->whereIn('user_id', $managedUserIds);
                } else {
                    // Business Users and Card Users can only see their own expenses
                    $query->forUser($user->id);
                }
            }

            // Apply additional filters
            if (isset($validated['status'])) {
                $query->byStatus($validated['status']);
            }

            if (isset($validated['date_from'])) {
                $query->where('date', '>=', $validated['date_from']);
            }

            if (isset($validated['date_to'])) {
                $query->where('date', '<=', $validated['date_to']);
            }

            if (isset($validated['currency'])) {
                $query->where('currency', strtoupper($validated['currency']));
            }

            if (isset($validated['user_id'])) {
                $query->forUser($validated['user_id']);
            }

            if (isset($validated['expense_type'])) {
                $query->where('expense_type', $validated['expense_type']);
            }

            if (isset($validated['amount_min'])) {
                $query->where('amount', '>=', $validated['amount_min']);
            }

            if (isset($validated['amount_max'])) {
                $query->where('amount', '<=', $validated['amount_max']);
            }

            if (isset($validated['merchant_name'])) {
                $query->where('merchant_name', 'LIKE', '%' . $validated['merchant_name'] . '%');
            }

            // Apply deletion filter
            if (!($validated['include_deleted'] ?? false)) {
                $query->active();
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'create_time';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $query->orderBy($sortBy, $sortDirection);

            // Apply pagination
            $perPage = min($validated['per_page'] ?? self::DEFAULT_PAGINATION_LIMIT, self::MAX_PAGINATION_LIMIT);
            $expenses = $query->paginate($perPage);

            Log::info('Pocket expenses retrieved', [
                'user_id' => $user->id,
                'client_id' => $validated['client_id'],
                'filters' => array_intersect_key($validated, array_flip(['status', 'date_from', 'date_to', 'currency', 'user_id', 'expense_type'])),