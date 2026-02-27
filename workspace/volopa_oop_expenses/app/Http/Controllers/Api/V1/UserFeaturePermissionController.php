## Code: app/Http/Controllers/Api/V1/UserFeaturePermissionController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserFeaturePermissionRequest;
use App\Http\Requests\UpdateUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class UserFeaturePermissionController extends Controller
{
    /**
     * The user permission service instance.
     *
     * @var UserPermissionService
     */
    private UserPermissionService $userPermissionService;

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
     * Create a new controller instance.
     *
     * @param UserPermissionService $userPermissionService
     */
    public function __construct(UserPermissionService $userPermissionService)
    {
        $this->userPermissionService = $userPermissionService;
        
        // Apply auth middleware to all routes
        $this->middleware('auth:api');
        
        // Apply throttle middleware for API rate limiting
        $this->middleware('throttle:api')->only(['store', 'update', 'destroy']);
    }

    /**
     * Display a listing of user feature permissions.
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
            $this->authorize('viewAny', UserFeaturePermission::class);

            // Validate and sanitize input parameters
            $validated = $request->validate([
                'client_id' => 'required|integer|min:1|exists:clients,id',
                'user_id' => 'sometimes|integer|min:1|exists:users,id',
                'feature_id' => 'sometimes|integer|min:1|exists:features,id',
                'manager_user_id' => 'sometimes|integer|min:1|exists:users,id',
                'is_enabled' => 'sometimes|boolean',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:' . self::MAX_PAGINATION_LIMIT,
                'sort_by' => 'sometimes|string|in:id,created_at,updated_at,user_id,feature_id',
                'sort_direction' => 'sometimes|string|in:asc,desc',
            ]);

            // Build query with filters
            $query = UserFeaturePermission::with([
                'user:id,name,email,role',
                'client:id,name,code',
                'feature:id,name,code,description',
                'grantor:id,name,email,role',
                'manager:id,name,email,role'
            ]);

            // Apply client filter (required)
            $query->forClient($validated['client_id']);

            // Apply additional filters if provided
            if (isset($validated['user_id'])) {
                $query->forUser($validated['user_id']);
            }

            if (isset($validated['feature_id'])) {
                $query->forFeature($validated['feature_id']);
            }

            if (isset($validated['manager_user_id'])) {
                $query->managedBy($validated['manager_user_id']);
            }

            if (isset($validated['is_enabled'])) {
                if ($validated['is_enabled']) {
                    $query->active();
                } else {
                    $query->where('is_enabled', false);
                }
            }

            // Apply role-based filtering
            if (!$user->isPrimaryAdmin()) {
                if ($user->isAdmin()) {
                    // Admin can only see permissions they granted or manage
                    $query->where(function ($q) use ($user) {
                        $q->where('grantor_id', $user->id)
                          ->orWhere('manager_user_id', $user->id)
                          ->orWhere('user_id', $user->id);
                    });
                } else {
                    // Business Users and Card Users can only see their own permissions
                    $query->forUser($user->id);
                }
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'created_at';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $query->orderBy($sortBy, $sortDirection);

            // Apply pagination
            $perPage = min($validated['per_page'] ?? self::DEFAULT_PAGINATION_LIMIT, self::MAX_PAGINATION_LIMIT);
            $permissions = $query->paginate($perPage);

            Log::info('User feature permissions retrieved', [
                'user_id' => $user->id,
                'client_id' => $validated['client_id'],
                'filters' => array_intersect_key($validated, array_flip(['user_id', 'feature_id', 'manager_user_id', 'is_enabled'])),
                'total_results' => $permissions->total()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User feature permissions retrieved successfully',
                'data' => UserFeaturePermissionResource::collection($permissions->items()),
                'pagination' => [
                    'current_page' => $permissions->currentPage(),
                    'total_pages' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'total_items' => $permissions->total(),
                    'has_next_page' => $permissions->hasMorePages(),
                    'has_previous_page' => $permissions->currentPage() > 1,
                ],
                'filters_applied' => array_intersect_key($validated, array_flip(['user_id', 'feature_id', 'manager_user_id', 'is_enabled'])),
            ], 200);

        } catch (ValidationException $e) {
            Log::warning('Validation error in user permissions index', [
                'user_id' => Auth::id(),
                'errors' => $e->errors()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_ERROR'
            ], 422);

        } catch (Exception $e) {
            Log::error('Error retrieving user feature permissions', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user feature permissions',
                'error_code' => 'RETRIEVAL_ERROR'
            ], 500);
        }
    }

    /**
     * Store a newly created user feature permission.
     *
     * @param StoreUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function store(StoreUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    