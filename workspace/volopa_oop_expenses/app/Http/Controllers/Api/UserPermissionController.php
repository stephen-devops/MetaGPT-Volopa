## Code: app/Http/Controllers/Api/UserPermissionController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantUserPermissionRequest;
use App\Http\Resources\UserPermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use App\Policies\UserPermissionPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserPermissionController extends Controller
{
    /**
     * The user permission service instance.
     */
    private UserPermissionService $userPermissionService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PAGE_SIZE = 15;

    /**
     * Maximum pagination size.
     */
    private const MAX_PAGE_SIZE = 100;

    /**
     * Create a new controller instance.
     */
    public function __construct(UserPermissionService $userPermissionService)
    {
        $this->userPermissionService = $userPermissionService;
        $this->middleware('auth:api');
        $this->middleware('client.context');
    }

    /**
     * Display a listing of permissions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Authorize the request
            Gate::authorize('viewAny', UserFeaturePermission::class);

            // Build filters from request
            $filters = $this->buildFilters($request);

            // Get permissions based on user context
            if ($request->has('client_id')) {
                $clientId = (int) $request->get('client_id');
                
                // Check if user can view client permissions
                if (!Gate::allows('viewClientPermissions', [UserFeaturePermission::class, $clientId])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to view permissions for this client',
                    ], 403);
                }

                $permissions = $this->userPermissionService->getClientPermissions($clientId, $filters);
            } else {
                // Get user's own permissions
                $permissions = $this->userPermissionService->getUserPermissions($request->user());
            }

            // Apply pagination if needed
            $perPage = $this->getValidPageSize($request->get('per_page', self::DEFAULT_PAGE_SIZE));
            $paginatedPermissions = $this->paginateCollection($permissions, $perPage, $request);

            return response()->json([
                'success' => true,
                'data' => UserPermissionResource::collection($paginatedPermissions),
                'pagination' => $this->getPaginationMeta($paginatedPermissions),
                'filters' => $this->getAvailableFilters($permissions),
                'summary' => $this->getPermissionsSummary($permissions),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error retrieving user permissions', [
                'user_id' => $request->user()->id,
                'filters' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permissions',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Grant permission to a user.
     */
    public function grantPermission(GrantUserPermissionRequest $request): JsonResponse
    {
        try {
            // Request is automatically validated and authorized via FormRequest
            $permissionData = $request->getPermissionData();

            // Grant the permission
            $permission = $this->userPermissionService->grantPermission(
                $permissionData['user_id'],
                $permissionData['client_id'],
                $permissionData['feature_id'],
                $permissionData['grantor_id'],
                $permissionData['manager_user_id']
            );

            Log::info('Permission granted successfully', [
                'permission_id' => $permission->id,
                'grantor_id' => $request->user()->id,
                'target_user_id' => $permissionData['user_id'],
                'client_id' => $permissionData['client_id'],
                'feature_id' => $permissionData['feature_id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission granted successfully',
                'data' => new UserPermissionResource($permission->load(['user', 'client', 'feature', 'grantor', 'manager'])),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error granting permission', [
                'grantor_id' => $request->user()->id,
                'data' => $request->getPermissionData(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to grant permission',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified permission.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::with(['user', 'client', 'feature', 'grantor', 'manager'])
                ->findOrFail($id);

            // Authorize the request
            Gate::authorize('view', $permission);

            return response()->json([
                'success' => true,
                'data' => new UserPermissionResource($permission),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this permission record',
            ], 403);

        } catch (\Exception $e) {
            Log::error('Error retrieving permission', [
                'permission_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permission',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified permission.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::findOrFail($id);

            // Authorize the request
            Gate::authorize('update', $permission);

            // Validate request data
            $request->validate([
                'is_enabled' => 'sometimes|boolean',
                'manager_user_id' => 'nullable|integer|exists:users,id|different:user_id',
            ]);

            $updated = false;

            // Update enabled status if provided
            if ($request->has('is_enabled')) {
                $isEnabled = $request->boolean('is_enabled');
                
                if ($isEnabled !== $permission->is_enabled) {
                    if ($isEnabled) {
                        $this->userPermissionService->enablePermission($permission);
                    } else {
                        $this->userPermissionService->disablePermission($permission);
                    }
                    $updated = true;
                }
            }

            // Update manager if provided
            if ($request->has('manager_user_id')) {
                $managerId = $request->get('manager_user_id');
                
                if ($managerId && $managerId !== $permission->manager_user_id) {
                    $this->userPermissionService->setPermissionManager($permission, $managerId);
                    $updated = true;
                } elseif (!$managerId && $permission->manager_user_id) {
                    $this->userPermissionService->removePermissionManager($permission);
                    $updated = true;
                }
            }

            if ($updated) {
                Log::info('Permission updated successfully', [
                    'permission_id' => $id,
                    'updater_id' => $request->user()->id,
                    'updated_fields' => array_keys($request->only(['is_enabled', 'manager_user_id'])),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $updated ? 'Permission updated successfully' : 'No changes made to permission',
                'data' => new UserPermissionResource($permission->fresh(['user', 'client', 'feature', 'grantor', 'manager'])),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update this permission record',
            ], 403);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error updating permission', [
                'permission_id' => $id,
                'updater_id' => $request->user()->id,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update permission',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Revoke the specified permission.
     */
    public function revokePermission(Request $request, int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::findOrFail($id);

            // Authorize the request
            Gate::authorize('revoke', $permission);

            // Revoke the permission
            $revoked = $this->userPermissionService->revokePermission($permission);

            if ($revoked) {
                Log::info('Permission revoked successfully', [
                    'permission_id' => $id,
                    'revoker_id' => $request->user()->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Permission revoked successfully',
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to revoke permission',
                ], 400);
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to revoke this permission record',
            ], 403);

        } catch (\Exception $e) {
            Log::error('Error revoking permission', [
                'permission_id' => $id,
                'revoker_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke permission',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Enable the specified permission.
     */
    public function enablePermission(Request $request, int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::findOrFail($id);

            // Authorize the request
            Gate::authorize('enable', $permission);

            // Enable the permission
            $enabled = $this->userPermissionService->enablePermission($permission);

            if ($enabled) {
                Log::info('Permission enabled successfully', [
                    'permission_id' => $id,
                    'enabler_id' => $request->user()->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Permission enabled successfully',
                    'data' => new UserPermissionResource($permission->fresh(['user', 'client', 'feature', 'grantor', 'manager'])),
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission is already enabled',
                ], 400);
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to enable this permission record',
            ], 403);

        } catch (\Exception $e) {
            Log::error('Error enabling permission', [
                'permission_id' => $id,
                'enabler_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to enable permission',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Disable the specified permission.
     */
    public function disablePermission(Request $request, int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::findOrFail($id);

            // Authorize the request
            Gate::authorize('disable', $permission);

            // Disable the permission
            $disabled = $this->userPermissionService->disablePermission($permission);

            if ($disabled) {
                Log::info('Permission disabled successfully', [
                    'permission_id' => $id,
                    'disabler_id' => $request->user()->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Permission disabled successfully',
                    'data' => new UserPermissionResource($permission->fresh(['user', 'client', 'feature', 'grantor', 'manager'])),
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission is already disabled',
                ], 400);
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not