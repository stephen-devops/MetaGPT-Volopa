<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantUserFeaturePermissionRequest;
use App\Http\Requests\RevokeUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class UserFeaturePermissionController extends Controller
{
    /**
     * The user permission service instance.
     *
     * @var UserPermissionService
     */
    protected UserPermissionService $userPermissionService;

    /**
     * Create a new controller instance.
     *
     * @param UserPermissionService $userPermissionService
     */
    public function __construct(UserPermissionService $userPermissionService)
    {
        $this->userPermissionService = $userPermissionService;
        
        // Apply OAuth2 middleware to all routes
        $this->middleware('oauth2.user_client');
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
            
            // Check if user can view permissions
            $this->authorize('viewAny', UserFeaturePermission::class);

            // Get query parameters
            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
            $userId = $request->query('user_id');
            $clientId = $request->query('client_id');
            $featureId = $request->query('feature_id');
            $status = $request->query('status', 'all'); // all, active, disabled, revoked
            $search = $request->query('search');

            // Build the query
            $query = UserFeaturePermission::with(['user', 'client', 'feature', 'grantor', 'manager']);

            // Filter by user
            if ($userId) {
                $query->where('user_id', $userId);
                
                // Check if current user can view this user's permissions
                if ($userId != $user->id) {
                    $this->authorize('viewUserPermissions', [UserFeaturePermission::class, (int) $userId, (int) $clientId]);
                }
            }

            // Filter by client
            if ($clientId) {
                $query->where('client_id', $clientId);
                
                // Check if current user can view permissions for this client
                $this->authorize('manageForClient', [UserFeaturePermission::class, (int) $clientId]);
            }

            // Filter by feature
            if ($featureId) {
                $query->where('feature_id', $featureId);
            }

            // Filter by status
            switch ($status) {
                case 'active':
                    $query->where('is_enabled', true)->whereNull('deleted_at');
                    break;
                case 'disabled':
                    $query->where('is_enabled', false)->whereNull('deleted_at');
                    break;
                case 'revoked':
                    $query->onlyTrashed();
                    break;
                case 'all':
                default:
                    $query->withTrashed();
                    break;
            }

            // Search functionality
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'LIKE', "%{$search}%")
                                 ->orWhere('email', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('feature', function ($featureQuery) use ($search) {
                        $featureQuery->where('name', 'LIKE', "%{$search}%")
                                    ->orWhere('display_name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('name', 'LIKE', "%{$search}%");
                    });
                });
            }

            // Apply sorting
            $sortBy = $request->query('sort_by', 'created_at');
            $sortOrder = $request->query('sort_order', 'desc');
            
            $allowedSortFields = ['id', 'created_at', 'updated_at', 'user_id', 'feature_id', 'is_enabled'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Get paginated results
            $permissions = $query->paginate($perPage);

            Log::info('User feature permissions retrieved', [
                'user_id' => $user->id,
                'client_id' => $clientId,
                'filters' => [
                    'user_id' => $userId,
                    'feature_id' => $featureId,
                    'status' => $status,
                    'search' => $search,
                ],
                'total' => $permissions->total(),
                'per_page' => $perPage
            ]);

            return response()->json([
                'data' => UserFeaturePermissionResource::collection($permissions->items()),
                'meta' => [
                    'current_page' => $permissions->currentPage(),
                    'from' => $permissions->firstItem(),
                    'last_page' => $permissions->lastPage(),
                    'path' => $permissions->path(),
                    'per_page' => $permissions->perPage(),
                    'to' => $permissions->lastItem(),
                    'total' => $permissions->total(),
                ],
                'links' => [
                    'first' => $permissions->url(1),
                    'last' => $permissions->url($permissions->lastPage()),
                    'prev' => $permissions->previousPageUrl(),
                    'next' => $permissions->nextPageUrl(),
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve user feature permissions', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Grant a feature permission to a user.
     *
     * @param GrantUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function grant(GrantUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validatedWithDefaults();
            $user = Auth::user();

            Log::info('Granting user feature permission', [
                'grantor_id' => $user->id,
                'user_id' => $validatedData['user_id'],
                'client_id' => $validatedData['client_id'],
                'feature_id' => $validatedData['feature_id'],
                'manager_user_id' => $validatedData['manager_user_id']
            ]);

            $permission = $this->userPermissionService->grantPermission(
                userId: $validatedData['user_id'],
                clientId: $validatedData['client_id'],
                featureId: $validatedData['feature_id'],
                grantorId: $validatedData['grantor_id'],
                managerId: $validatedData['manager_user_id']
            );

            // Load relationships for the response
            $permission->load(['user', 'client', 'feature', 'grantor', 'manager']);

            Log::info('User feature permission granted successfully', [
                'permission_id' => $permission->id,
                'grantor_id' => $user->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id
            ]);

            return response()->json([
                'message' => 'Permission granted successfully',
                'data' => new UserFeaturePermissionResource($permission)
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to grant user feature permission', [
                'grantor_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to grant permission',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Revoke a feature permission from a user.
     *
     * @param RevokeUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function revoke(RevokeUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validatedWithDefaults();
            $user = Auth::user();
            $permission = $request->getPermissionInstance();

            if (!$permission) {
                return response()->json([
                    'message' => 'Permission not found'
                ], 404);
            }

            Log::info('Revoking user feature permission', [
                'revoker_id' => $user->id,
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'permanent' => $request->isPermanentDeletion(),
                'reason' => $request->getRevocationReason()
            ]);

            DB::transaction(function () use ($permission, $request, $user) {
                if ($request->isPermanentDeletion()) {
                    // Permanently delete the permission
                    $permission->forceDelete();
                } else {
                    // Soft delete the permission
                    $permission->delete();
                }

                // Log the revocation reason if provided
                $reason = $request->getRevocationReason();
                if ($reason) {
                    Log::info('Permission revocation reason', [
                        'permission_id' => $permission->id,
                        'revoked_by' => $user->id,
                        'reason' => $reason
                    ]);
                }
            });

            Log::info('User feature permission revoked successfully', [
                'permission_id' => $permission->id,
                'revoked_by' => $user->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'permanent' => $request->isPermanentDeletion()
            ]);

            return response()->json([
                'message' => $request->isPermanentDeletion() ? 
                    'Permission permanently deleted' : 
                    'Permission revoked successfully'
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to revoke user feature permission', [
                'revoker_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to revoke permission',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Show a specific user feature permission.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::with(['user', 'client', 'feature', 'grantor', 'manager'])
                ->findOrFail($id);

            // Check authorization
            $this->authorize('view', $permission);

            Log::debug('User feature permission retrieved', [
                'permission_id' => $id,
                'viewed_by' => Auth::id()
            ]);

            return response()->json([
                'data' => new UserFeaturePermissionResource($permission)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('User feature permission not found', [
                'permission_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Permission not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to retrieve user feature permission', [
                'permission_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to retrieve permission',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Enable a user feature permission.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function enable(int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::findOrFail($id);
            
            // Check authorization
            $this->authorize('update', $permission);

            if ($permission->is_enabled) {
                return response()->json([
                    'message' => 'Permission is already enabled'
                ], 400);
            }

            $result = $this->userPermissionService->enablePermission(
                $permission->user_id,
                $permission->client_id,
                $permission->feature_id
            );

            if (!$result) {
                return response()->json([
                    'message' => 'Failed to enable permission'
                ], 500);
            }

            $permission->refresh()->load(['user', 'client', 'feature', 'grantor', 'manager']);

            Log::info('User feature permission enabled', [
                'permission_id' => $id,
                'enabled_by' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Permission enabled successfully',
                'data' => new UserFeaturePermissionResource($permission)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Permission not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to enable user feature permission', [
                'permission_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to enable permission',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Disable a user feature permission.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function disable(int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::findOrFail($id);
            
            // Check authorization
            $this->authorize('update', $permission);

            if (!$permission->is_enabled) {
                return response()->json([
                    'message' => 'Permission is already disabled'
                ], 400);
            }

            $result = $this->userPermissionService->disablePermission(
                $permission->user_id,
                $permission->client_id,
                $permission->feature_id
            );

            if (!$result) {
                return response()->json([
                    'message' => 'Failed to disable permission'
                ], 500);
            }

            $permission->refresh()->load(['user', 'client', 'feature', 'grantor', 'manager']);

            Log::info('User feature permission disabled', [
                'permission_id' => $id,
                'disabled_by' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Permission disabled successfully',
                'data' => new UserFeaturePermissionResource($permission)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Permission not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to disable user feature permission', [
                'permission_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to disable permission',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Get managed users for the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getManagedUsers(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $clientId = (int) $request->query('client_id');

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client ID is required'
                ], 400);
            }

            // Check authorization
            $this->authorize('manageForClient', [UserFeaturePermission::class, $clientId]);

            $managedUsers = $this->userPermissionService->getManagedUsers($user->id, $clientId);

            Log::debug('Retrieved managed users', [
                'manager_id' => $user->id,
                'client_id' => $clientId,
                'managed_users_count' => $managedUsers->count()
            ]);

            return response()->json([
                'data' => $managedUsers->map(function ($managedUser) {
                    return [
                        'id' => $managedUser->id,
                        'name' => $managedUser->name ?? '',
                        'email' => $managedUser->email ?? '',
                        'created_at' => $managedUser->created_at?->toISOString(),
                    ];
                }),
                'meta' => [
                    'total' => $managedUsers->count(),
                    'manager_id' => $user->id,
                    'client_id' => $clientId,
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve managed users', [
                'manager_id' => Auth::id(),
                'client_id' => $request->query('client_id'),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to retrieve managed users',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Bulk grant permissions to multiple users.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkGrant(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_ids' => 'required|array|min:1|max:100',
                'user_ids.*' => 'required|integer|min:1',
                'client_id' => 'required|integer|min:1',
                'feature_ids' => 'required|array|min:1|max:50',
                'feature_ids.*' => 'required|integer|min:1',
                'manager_user_id' => 'required|integer|min:1',
            ]);

            $user = Auth::user();
            $userIds = $request->input('user_ids');
            $clientId = $request->input('client_id');
            $featureIds = $request->input('feature_ids');
            $managerId = $request->input('manager_user_id');

            // Check authorization for bulk operations
            $this->authorize('bulkManage', [UserFeaturePermission::class, $clientId]);

            Log::info('Starting bulk grant permissions', [
                'grantor_id' => $user->id,
                'client_id' => $clientId,
                'user_count' => count($userIds),
                'feature_count' => count($featureIds),
                'manager_id' => $managerId
            ]);

            $results = $this->userPermissionService->bulkGrantPermissions(
                $userIds,
                $clientId,
                $featureIds,
                $user->id,
                $managerId
            );

            Log::info('Bulk grant permissions completed', [
                'grantor_id' => $user->id,
                'results' => $results
            ]);

            return response()->json([
                'message' => 'Bulk grant permissions completed',
                'data' => $results
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to bulk grant permissions', [
                'grantor_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to bulk grant permissions',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Bulk revoke permissions from multiple users.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkRevoke(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'user_ids' => 'required|array|min:1|max:100',
                'user_ids.*' => 'required|integer|min:1',
                'client_id' => 'required|integer|min:1',
                'feature_ids' => 'required|array|min:1|max:50',
                'feature_ids.*' => 'required|integer|min:1',
            ]);

            $user = Auth::user();
            $userIds = $request->input('user_ids');
            $clientId = $request->input('client_id');
            $featureIds = $request->input('feature_ids');

            // Check authorization for bulk operations
            $this->authorize('bulkManage', [UserFeaturePermission::class, $clientId]);

            Log::info('Starting bulk revoke permissions', [
                'revoker_id' => $user->id,
                'client_id' => $clientId,
                'user_count' => count($userIds),
                'feature_count' => count($featureIds)
            ]);

            $results = $this->userPermissionService->bulkRevokePermissions(
                $userIds,
                $clientId,
                $featureIds
            );

            Log::info('Bulk revoke permissions completed', [
                'revoker_id' => $user->id,
                'results' => $results
            ]);

            return response()->json([
                'message' => 'Bulk revoke permissions completed',
                'data' => $results
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to bulk revoke permissions', [
                'revoker_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to bulk revoke permissions',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Get permissions summary for a client.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSummary(Request $request): JsonResponse
    {
        try {
            $clientId = (int) $request->query('client_id');

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client ID is required'
                ], 400);
            }

            // Check authorization
            $this->authorize('manageForClient', [UserFeaturePermission::class, $clientId]);

            $summary = $this->userPermissionService->getClientPermissionsSummary($clientId);

            Log::debug('Retrieved permissions summary', [
                'client_id' => $clientId,
                'user_id' => Auth::id(),
                'total_permissions' => $summary['total_permissions'] ?? 0
            ]);

            return response()->json([
                'data' => $summary
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve permissions summary', [
                'client_id' => $request->query('client_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            $statusCode = $this->getErrorStatusCode($e);
            
            return response()->json([
                'message' => 'Failed to retrieve permissions summary',
                'error' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Get the appropriate HTTP status code for an exception.
     *
     * @param Exception $exception
     * @return int
     */
    private function getErrorStatusCode(Exception $exception): int
    {
        if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return 403;
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 404;
        }

        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return 422;
        }

        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        return 500;
    }
}