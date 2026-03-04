## Code: app/Http/Controllers/Api/V1/UserFeaturePermissionController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantUserFeaturePermissionRequest;
use App\Http\Requests\RevokeUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;
use Exception;
use InvalidArgumentException;

/**
 * UserFeaturePermissionController
 * 
 * Thin controller for user feature permission management operations.
 * Delegates business logic to UserPermissionService and shapes responses using API Resources.
 * Implements delegation-based RBAC system with multi-tenant support and role hierarchy enforcement.
 * 
 * Business Rules:
 * - Primary Administrator has full access to all users' permissions
 * - Administrator requires explicit delegation to manage other users' permissions
 * - Business User and Card User cannot approve expenses even with management rights
 * - Permission delegation can be granted by Primary Admin to any user regardless of role
 * - Admin can only grant access to their own managed users, not all users
 * - Revoked users fall back to Primary Administrator management until reassigned
 */
class UserFeaturePermissionController extends Controller
{
    /**
     * User permission service instance.
     *
     * @var UserPermissionService
     */
    private UserPermissionService $userPermissionService;

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
     * Create a new controller instance.
     *
     * @param UserPermissionService $userPermissionService
     */
    public function __construct(UserPermissionService $userPermissionService)
    {
        $this->userPermissionService = $userPermissionService;
        
        // Apply OAuth2 middleware for all routes
        $this->middleware('auth:api');
        
        // Apply throttling middleware
        $this->middleware('throttle:60,1');
    }

    /**
     * Display a listing of user feature permissions.
     *
     * GET /api/v1/user-feature-permissions
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
            $this->authorize('viewAny', UserFeaturePermission::class);

            // Build query with filters
            $query = UserFeaturePermission::with(['user', 'client', 'feature', 'grantor', 'manager'])
                ->where('client_id', $user->client_id);

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
            $permissions = $query->paginate($perPage);

            Log::info('User feature permissions retrieved', [
                'user_id' => $user->id,
                'client_id' => $user->client_id,
                'total_count' => $permissions->total(),
                'filters' => $request->only(['user_id', 'feature_id', 'is_enabled', 'manager_user_id']),
            ]);

            return $this->successResponse(
                UserFeaturePermissionResource::collection($permissions),
                'User feature permissions retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Failed to retrieve user feature permissions', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve user feature permissions',
                500,
                ['error' => 'An unexpected error occurred while retrieving permissions']
            );
        }
    }

    /**
     * Store a newly created user feature permission.
     *
     * POST /api/v1/user-feature-permissions
     * 
     * @param GrantUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function store(GrantUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                return $this->errorResponse('Invalid user context', 401);
            }

            $validatedData = $request->validated();

            // Grant the permission using the service
            $permission = $this->userPermissionService->grantPermission(
                $validatedData['user_id'],
                $user->client_id,
                $validatedData['feature_id'],
                $user->id,
                $validatedData['manager_user_id']
            );

            // Load relationships for response
            $permission->load(['user', 'client', 'feature', 'grantor', 'manager']);

            Log::info('User feature permission granted', [
                'permission_id' => $permission->id,
                'user_id' => $validatedData['user_id'],
                'feature_id' => $validatedData['feature_id'],
                'grantor_id' => $user->id,
                'manager_id' => $validatedData['manager_user_id'],
                'client_id' => $user->client_id,
            ]);

            return $this->successResponse(
                new UserFeaturePermissionResource($permission),
                'User feature permission granted successfully',
                201
            );

        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid user feature permission grant request', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_data' => $request->validated(),
            ]);

            return $this->errorResponse(
                'Invalid permission grant request',
                422,
                ['error' => $e->getMessage()]
            );

        } catch (Exception $e) {
            Log::error('Failed to grant user feature permission', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->validated(),
            ]);

            return $this->errorResponse(
                'Failed to grant user feature permission',
                500,
                ['error' => 'An unexpected error occurred while granting permission']
            );
        }
    }

    /**
     * Remove the specified user feature permission.
     *
     * DELETE /api/v1/user-feature-permissions/{id}
     * 
     * @param int $id
     * @param RevokeUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function destroy(int $id, RevokeUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                return $this->errorResponse('Invalid user context', 401);
            }

            if ($id <= 0) {
                return $this->errorResponse('Invalid permission ID', 400);
            }

            $validatedData = $request->validated();

            // Find the permission to revoke
            $permission = UserFeaturePermission::where('id', $id)
                ->where('client_id', $user->client_id)
                ->first();

            if (!$permission