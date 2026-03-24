<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use App\Services\UserFeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * UserFeaturePermissionController
 * 
 * Handles user feature permission management operations including granting, revoking,
 * and listing permissions. Follows hierarchical RBAC with role-based access control.
 * 
 * Business Rules:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own expenses by default; needs explicit grant for others  
 * - Admin can only grant access to their own managed users (not all users)
 * - Managing access can be given to any user irrespective of role
 */
class UserFeaturePermissionController extends Controller
{
    /**
     * The user feature permission service instance.
     */
    protected UserFeaturePermissionService $userFeaturePermissionService;

    /**
     * Create a new controller instance.
     */
    public function __construct(UserFeaturePermissionService $userFeaturePermissionService)
    {
        $this->userFeaturePermissionService = $userFeaturePermissionService;
        
        // Apply OAuth2 authentication to all routes
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of user feature permissions.
     * 
     * Returns paginated permissions based on user's access level:
     * - Primary Admin: can see all permissions
     * - Admin: can see permissions for users they manage
     * - Other roles: can see only their own permissions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Check authorization
            if (!Gate::allows('viewAny', UserFeaturePermission::class)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to view user permissions.',
                ], 403);
            }

            $user = Auth::user();
            $clientId = $request->integer('client_id');
            $page = $request->integer('page', 1);
            $perPage = min($request->integer('per_page', 15), 100); // Max 100 items per page

            // Validate client_id parameter
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
                'user_id' => 'sometimes|integer|exists:users,id',
                'feature_id' => 'sometimes|integer',
                'is_enabled' => 'sometimes|boolean',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
            ]);

            // Get permissions based on user's access level and filters
            $permissions = $this->userFeaturePermissionService->getUserPermissions(
                $user->id,
                $clientId,
                $request->only(['user_id', 'feature_id', 'is_enabled'])
            );

            // Apply pagination
            $paginatedPermissions = $permissions->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'User permissions retrieved successfully.',
                'data' => UserFeaturePermissionResource::collection($paginatedPermissions->items()),
                'meta' => [
                    'current_page' => $paginatedPermissions->currentPage(),
                    'last_page' => $paginatedPermissions->lastPage(),
                    'per_page' => $paginatedPermissions->perPage(),
                    'total' => $paginatedPermissions->total(),
                    'from' => $paginatedPermissions->firstItem(),
                    'to' => $paginatedPermissions->lastItem(),
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve user permissions: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user permissions. Please try again.',
            ], 500);
        }
    }

    /**
     * Store a newly created permission grant.
     * 
     * Grants permission to a user for a specific feature within a client context.
     * Validates hierarchical access and business rules before granting.
     *
     * @param GrantUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function store(GrantUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            // Check authorization - handled in FormRequest as well, but double-check here
            if (!Gate::allows('create', UserFeaturePermission::class)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to grant permissions.',
                ], 403);
            }

            $user = Auth::user();
            $validatedData = $request->validated();

            // Additional business rule validation
            $canManage = $this->userFeaturePermissionService->canManageUser(
                $user->id,
                $validatedData['user_id'],
                $validatedData['client_id']
            );

            if (!$canManage) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only grant permissions to users you manage.',
                ], 403);
            }

            // Grant the permission
            $permission = $this->userFeaturePermissionService->grantPermission(
                $validatedData['user_id'],
                $validatedData['client_id'],
                $validatedData['feature_id'],
                $user->id, // grantor_id
                $validatedData['manager_user_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'Permission granted successfully.',
                'data' => new UserFeaturePermissionResource($permission),
            ], 201);

        } catch (\Illuminate\Database\QueryException $e) {
            // Handle unique constraint violations (duplicate permissions)
            if (str_contains($e->getMessage(), 'unique_user_client_feature')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission already exists for this user, client, and feature combination.',
                ], 422);
            }

            \Log::error('Database error while granting permission: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'request_data' => $request->validated(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to grant permission due to database error.',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Failed to grant user permission: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'request_data' => $request->validated(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to grant permission. Please try again.',
            ], 500);
        }
    }

    /**
     * Remove the specified permission (revoke access).
     * 
     * Soft-revokes permission by setting is_enabled = false rather than deleting.
     * Maintains audit trail while preventing future access.
     *
     * @param UserFeaturePermission $permission
     * @return JsonResponse
     */
    public function destroy(UserFeaturePermission $permission): JsonResponse
    {
        try {
            // Check authorization
            if (!Gate::allows('delete', $permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to revoke this permission.',
                ], 403);
            }

            $user = Auth::user();

            // Additional business rule validation - ensure user can manage the target user
            $canManage = $this->userFeaturePermissionService->canManageUser(
                $user->id,
                $permission->user_id,
                $permission->client_id
            );

            if (!$canManage) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only revoke permissions for users you manage.',
                ], 403);
            }

            // Check if permission is already revoked
            if (!$permission->is_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission is already revoked.',
                ], 422);
            }

            // Revoke the permission
            $revoked = $this->userFeaturePermissionService->revokePermission($permission);

            if (!$revoked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to revoke permission.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permission revoked successfully.',
            ], 204);

        } catch (\Exception $e) {
            \Log::error('Failed to revoke user permission: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'permission_id' => $permission->id,
                'permission_user_id' => $permission->user_id,
                'permission_client_id' => $permission->client_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke permission. Please try again.',
            ], 500);
        }
    }

    /**
     * Display the specified permission details.
     * 
     * Shows detailed information about a specific permission grant.
     * Includes grant history and current status.
     *
     * @param UserFeaturePermission $permission
     * @return JsonResponse
     */
    public function show(UserFeaturePermission $permission): JsonResponse
    {
        try {
            // Check authorization
            if (!Gate::allows('view', $permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to view this permission.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permission details retrieved successfully.',
                'data' => new UserFeaturePermissionResource($permission->load([
                    'user:id,name,email',
                    'client:id,name',
                    'grantor:id,name,email',
                    'manager:id,name,email'
                ])),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve permission details: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'permission_id' => $permission->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permission details. Please try again.',
            ], 500);
        }
    }

    /**
     * Get permissions summary for a specific user.
     * 
     * Returns a summary of all permissions granted to a specific user
     * across all clients and features. Used for user management dashboards.
     *
     * @param Request $request
     * @param int $userId
     * @return JsonResponse
     */
    public function userPermissionsSummary(Request $request, int $userId): JsonResponse
    {
        try {
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
            ]);

            $user = Auth::user();
            $clientId = $request->integer('client_id');

            // Check if the requesting user can manage the target user
            $canManage = $this->userFeaturePermissionService->canManageUser(
                $user->id,
                $userId,
                $clientId
            );

            if (!$canManage) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to view permissions for this user.',
                ], 403);
            }

            // Get permissions summary
            $permissions = $this->userFeaturePermissionService->getUserPermissions(
                $userId,
                $clientId
            );

            // Group permissions by feature for easier consumption
            $permissionsByFeature = $permissions->get()->groupBy('feature_id')->map(function ($featurePermissions) {
                return [
                    'feature_id' => $featurePermissions->first()->feature_id,
                    'permissions' => UserFeaturePermissionResource::collection($featurePermissions),
                    'is_enabled' => $featurePermissions->where('is_enabled', true)->isNotEmpty(),
                    'granted_count' => $featurePermissions->count(),
                    'active_count' => $featurePermissions->where('is_enabled', true)->count(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'User permissions summary retrieved successfully.',
                'data' => [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'permissions_by_feature' => $permissionsByFeature,
                    'total_permissions' => $permissions->count(),
                    'active_permissions' => $permissions->where('is_enabled', true)->count(),
                ],
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve user permissions summary: ' . $e->getMessage(), [
                'requesting_user_id' => Auth::id(),
                'target_user_id' => $userId,
                'client_id' => $request->get('client_id'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user permissions summary. Please try again.',
            ], 500);
        }
    }

    /**
     * Get manageable users for the authenticated user.
     * 
     * Returns a list of users that the authenticated user can manage
     * within a specific client context. Used for dropdown lists in the UI.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getManageableUsers(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
            ]);

            $user = Auth::user();
            $clientId = $request->integer('client_id');

            // Get users that the authenticated user can manage
            $manageableUsers = $this->userFeaturePermissionService->getManageableUsers(
                $user->id,
                $clientId
            );

            return response()->json([
                'success' => true,
                'message' => 'Manageable users retrieved successfully.',
                'data' => $manageableUsers->map(function ($manageableUser) {
                    return [
                        'id' => $manageableUser->id,
                        'name' => $manageableUser->name,
                        'email' => $manageableUser->email,
                        'role' => $manageableUser->role ?? 'User', // Assuming role field exists
                    ];
                }),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve manageable users: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve manageable users. Please try again.',
            ], 500);
        }
    }

    /**
     * Bulk grant permissions to multiple users.
     * 
     * Allows granting the same permission to multiple users at once.
     * Validates each user individually and reports any failures.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkGrant(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_ids' => 'required|array|min:1|max:50', // Limit bulk operations
                'user_ids.*' => 'integer|exists:users,id',
                'client_id' => 'required|integer|exists:clients,id',
                'feature_id' => 'required|integer',
                'manager_user_id' => 'required|integer|exists:users,id',
            ]);

            $user = Auth::user();
            $validatedData = $request->validated();

            $results = [
                'successful' => [],
                'failed' => [],
                'skipped' => [],
            ];

            foreach ($validatedData['user_ids'] as $targetUserId) {
                try {
                    // Check if user can manage the target user
                    $canManage = $this->userFeaturePermissionService->canManageUser(
                        $user->id,
                        $targetUserId,
                        $validatedData['client_id']
                    );

                    if (!$canManage) {
                        $results['failed'][] = [
                            'user_id' => $targetUserId,
                            'error' => 'You cannot manage this user.',
                        ];
                        continue;
                    }

                    // Check if permission already exists
                    $existingPermission = UserFeaturePermission::where([
                        'user_id' => $targetUserId,
                        'client_id' => $validatedData['client_id'],
                        'feature_id' => $validatedData['feature_id'],
                    ])->first();

                    if ($existingPermission && $existingPermission->is_enabled) {
                        $results['skipped'][] = [
                            'user_id' => $targetUserId,
                            'reason' => 'Permission already exists and is enabled.',
                        ];
                        continue;
                    }

                    // Grant the permission
                    $permission = $this->userFeaturePermissionService->grantPermission(
                        $targetUserId,
                        $validatedData['client_id'],
                        $validatedData['feature_id'],
                        $user->id,
                        $validatedData['manager_user_id']
                    );

                    $results['successful'][] = [
                        'user_id' => $targetUserId,
                        'permission_id' => $permission->id,
                    ];

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'user_id' => $targetUserId,
                        'error' => 'Failed to grant permission: ' . $e->getMessage(),
                    ];
                }
            }

            $httpStatus = empty($results['failed']) ? 200 : 207; // 207 Multi-Status for partial success

            return response()->json([
                'success' => !empty($results['successful']),
                'message' => sprintf(
                    'Bulk grant completed. %d successful, %d failed, %d skipped.',
                    count($results['successful']),
                    count($results['failed']),
                    count($results['skipped'])
                ),
                'data' => $results,
            ], $httpStatus);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to execute bulk permission grant: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to execute bulk permission grant. Please try again.',
            ], 500);
        }
    }

    /**
     * Bulk revoke permissions from multiple users.
     * 
     * Allows revoking the same permission from multiple users at once.
     * Validates each user individually and reports any failures.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkRevoke(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'permission_ids' => 'required|array|min:1|max:50', // Limit bulk operations
                'permission_ids.*' => 'integer|exists:user_feature_permission,id',
            ]);

            $user = Auth::user();
            $permissionIds = $request->validated()['permission_ids'];

            $results = [
                'successful' => [],
                'failed' => [],
                'skipped' => [],
            ];

            $permissions = UserFeaturePermission::whereIn('id', $permissionIds)->get();

            foreach ($permissions as $permission) {
                try {
                    // Check authorization
                    if (!Gate::allows('delete', $permission)) {
                        $results['failed'][] = [
                            'permission_id' => $permission->id,
                            'error' => 'Not authorized to revoke this permission.',
                        ];
                        continue;
                    }

                    // Check if already revoked
                    if (!$permission->is_enabled) {
                        $results['skipped'][] = [
                            'permission_id' => $permission->id,
                            'reason' => 'Permission is already revoked.',
                        ];
                        continue;
                    }

                    // Revoke the permission
                    $revoked = $this->userFeaturePermissionService->revokePermission($permission);

                    if ($revoked) {
                        $results['successful'][] = [
                            'permission_id' => $permission->id,
                            'user_id' => $permission->user_id,
                        ];
                    } else {
                        $results['failed'][] = [
                            'permission_id' => $permission->id,
                            'error' => 'Failed to revoke permission.',
                        ];
                    }

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'permission_id' => $permission->id,
                        'error' => 'Failed to revoke permission: ' . $e->getMessage(),
                    ];
                }
            }

            $httpStatus = empty($results['failed']) ? 200 : 207; // 207 Multi-Status for partial success

            return response()->json([
                'success' => !empty($results['successful']),
                'message' => sprintf(
                    'Bulk revoke completed. %d successful, %d failed, %d skipped.',
                    count($results['successful']),
                    count($results['failed']),
                    count($results['skipped'])
                ),
                'data' => $results,
            ], $httpStatus);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to execute bulk permission revoke: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to execute bulk permission revoke. Please try again.',
            ], 500);
        }
    }
}