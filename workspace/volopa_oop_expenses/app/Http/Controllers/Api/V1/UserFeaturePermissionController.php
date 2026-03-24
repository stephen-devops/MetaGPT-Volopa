<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserFeaturePermission;
use App\Services\UserFeaturePermissionService;
use App\Http\Requests\StoreUserFeaturePermissionRequest;
use App\Http\Requests\UpdateUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * UserFeaturePermissionController
 * 
 * Handles API endpoints for managing user feature permissions within the OOP Expense system.
 * Provides thin controller layer that delegates business logic to UserFeaturePermissionService
 * and enforces authorization through policies. All operations are scoped by client_id for
 * multi-tenancy and require OAuth2 authentication via Oauth2UserClient middleware.
 * 
 * Endpoints:
 * - GET /api/v1/user-feature-permissions - List user permissions
 * - POST /api/v1/user-feature-permissions - Grant new permission
 * - GET /api/v1/user-feature-permissions/{id} - Show specific permission
 * - PUT /api/v1/user-feature-permissions/{id} - Update permission
 * - DELETE /api/v1/user-feature-permissions/{id} - Revoke permission
 */
class UserFeaturePermissionController extends Controller
{
    /**
     * User feature permission service for business logic
     *
     * @var UserFeaturePermissionService
     */
    protected UserFeaturePermissionService $permissionService;

    /**
     * Create a new controller instance
     *
     * @param UserFeaturePermissionService $permissionService
     */
    public function __construct(UserFeaturePermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
        
        // Apply OAuth2 authentication middleware to all routes
        $this->middleware('oauth2userClient');
        
        // Apply authorization policies to controller actions
        $this->authorizeResource(UserFeaturePermission::class, 'userFeaturePermission');
    }

    /**
     * Display a listing of user feature permissions
     * 
     * Returns paginated list of permissions filtered by client_id and optional query parameters.
     * Only returns permissions that the authenticated user is authorized to view based on
     * role hierarchy and management relationships.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->input('client_id', $user->client_id ?? null);
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                    'errors' => [
                        'client_id' => ['Client ID must be provided']
                    ]
                ], 400);
            }

            // Build query filters from request parameters
            $filters = [
                'user_id' => $request->input('user_id'),
                'feature_id' => $request->input('feature_id', 16), // Default to OOP Expense feature
                'is_enabled' => $request->input('is_enabled'),
                'grantor_id' => $request->input('grantor_id'),
                'manager_user_id' => $request->input('manager_user_id'),
            ];

            // Remove null values from filters
            $filters = array_filter($filters, fn($value) => $value !== null);

            // Get permissions through service with pagination
            $permissions = $this->permissionService->getUserFeaturePermissions(
                $user->id,
                $clientId,
                $filters,
                $request->input('per_page', 15)
            );

            return response()->json([
                'success' => true,
                'message' => 'User feature permissions retrieved successfully',
                'data' => UserFeaturePermissionResource::collection($permissions),
                'meta' => [
                    'current_page' => $permissions->currentPage(),
                    'last_page' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'total' => $permissions->total(),
                    'from' => $permissions->firstItem(),
                    'to' => $permissions->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user feature permissions',
                'errors' => [
                    'server' => ['An unexpected error occurred while retrieving permissions']
                ]
            ], 500);
        }
    }

    /**
     * Store a newly created user feature permission
     * 
     * Grants a new feature permission to a user within the specified client context.
     * Validates request data, enforces authorization policies, and delegates creation
     * to the service layer for business logic processing.
     *
     * @param StoreUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function store(StoreUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            // Get authenticated user (grantor)
            $user = Auth::user();
            $clientId = $request->input('client_id', $user->client_id ?? null);

            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                    'errors' => [
                        'client_id' => ['Client ID must be provided']
                    ]
                ], 400);
            }

            // Grant permission through service
            $permission = $this->permissionService->grantPermission(
                $request->input('user_id'),
                $clientId,
                $request->input('feature_id', 16), // Default to OOP Expense feature
                $user->id, // Grantor is authenticated user
                $request->input('manager_user_id')
            );

            return response()->json([
                'success' => true,
                'message' => 'User feature permission granted successfully',
                'data' => new UserFeaturePermissionResource($permission)
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid permission grant request',
                'errors' => [
                    'permission' => [$e->getMessage()]
                ]
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to grant user feature permission',
                'errors' => [
                    'server' => ['An unexpected error occurred while granting permission']
                ]
            ], 500);
        }
    }

    /**
     * Display the specified user feature permission
     * 
     * Returns detailed information about a specific permission record.
     * Authorization is enforced through the policy to ensure the authenticated
     * user can view the requested permission based on role hierarchy.
     *
     * @param UserFeaturePermission $userFeaturePermission
     * @return JsonResponse
     */
    public function show(UserFeaturePermission $userFeaturePermission): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'User feature permission retrieved successfully',
                'data' => new UserFeaturePermissionResource($userFeaturePermission)
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User feature permission not found',
                'errors' => [
                    'permission' => ['The requested permission does not exist or you do not have access to it']
                ]
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user feature permission',
                'errors' => [
                    'server' => ['An unexpected error occurred while retrieving the permission']
                ]
            ], 500);
        }
    }

    /**
     * Update the specified user feature permission
     * 
     * Updates an existing permission record with new values. Common updates include
     * enabling/disabling permissions and changing manager assignments. Authorization
     * is enforced to ensure only authorized users can modify permissions.
     *
     * @param UpdateUserFeaturePermissionRequest $request
     * @param UserFeaturePermission $userFeaturePermission
     * @return JsonResponse
     */
    public function update(UpdateUserFeaturePermissionRequest $request, UserFeaturePermission $userFeaturePermission): JsonResponse
    {
        try {
            // Get authenticated user
            $user = Auth::user();

            // Update permission through service
            $updatedPermission = $this->permissionService->updatePermission(
                $userFeaturePermission,
                $request->validated(),
                $user->id // Update performed by authenticated user
            );

            return response()->json([
                'success' => true,
                'message' => 'User feature permission updated successfully',
                'data' => new UserFeaturePermissionResource($updatedPermission)
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User feature permission not found',
                'errors' => [
                    'permission' => ['The requested permission does not exist or you do not have access to it']
                ]
            ], 404);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid permission update request',
                'errors' => [
                    'permission' => [$e->getMessage()]
                ]
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user feature permission',
                'errors' => [
                    'server' => ['An unexpected error occurred while updating the permission']
                ]
            ], 500);
        }
    }

    /**
     * Remove the specified user feature permission
     * 
     * Revokes a user's feature permission by removing the permission record.
     * This is a soft operation that disables the permission rather than hard deletion
     * to maintain audit trail. Authorization ensures only authorized users can revoke permissions.
     *
     * @param UserFeaturePermission $userFeaturePermission
     * @return JsonResponse
     */
    public function destroy(UserFeaturePermission $userFeaturePermission): JsonResponse
    {
        try {
            // Get authenticated user
            $user = Auth::user();

            // Revoke permission through service
            $success = $this->permissionService->revokePermission(
                $userFeaturePermission,
                $user->id // Revocation performed by authenticated user
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to revoke user feature permission',
                    'errors' => [
                        'permission' => ['Permission could not be revoked at this time']
                    ]
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'User feature permission revoked successfully',
                'data' => null
            ], 204);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User feature permission not found',
                'errors' => [
                    'permission' => ['The requested permission does not exist or you do not have access to it']
                ]
            ], 404);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid permission revocation request',
                'errors' => [
                    'permission' => [$e->getMessage()]
                ]
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke user feature permission',
                'errors' => [
                    'server' => ['An unexpected error occurred while revoking the permission']
                ]
            ], 500);
        }
    }

    /**
     * Get permissions that the authenticated user can manage
     * 
     * Custom endpoint to retrieve permissions where the authenticated user
     * is either the grantor or has management rights. Useful for admin
     * interfaces showing manageable permissions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function manageable(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->input('client_id', $user->client_id ?? null);
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                    'errors' => [
                        'client_id' => ['Client ID must be provided']
                    ]
                ], 400);
            }

            // Get manageable permissions through service
            $permissions = $this->permissionService->getManageablePermissions(
                $user->id,
                $clientId,
                $request->input('per_page', 15)
            );

            return response()->json([
                'success' => true,
                'message' => 'Manageable permissions retrieved successfully',
                'data' => UserFeaturePermissionResource::collection($permissions),
                'meta' => [
                    'current_page' => $permissions->currentPage(),
                    'last_page' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'total' => $permissions->total(),
                    'from' => $permissions->firstItem(),
                    'to' => $permissions->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve manageable permissions',
                'errors' => [
                    'server' => ['An unexpected error occurred while retrieving manageable permissions']
                ]
            ], 500);
        }
    }

    /**
     * Check if authenticated user can manage specific target user
     * 
     * Utility endpoint to check management permissions before performing
     * permission operations. Returns boolean indicating if the authenticated
     * user can manage the specified target user within the client context.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function canManage(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and parameters
            $user = Auth::user();
            $targetUserId = $request->input('target_user_id');
            $clientId = $request->input('client_id', $user->client_id ?? null);

            if (!$clientId || !$targetUserId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID and target user ID are required',
                    'errors' => [
                        'client_id' => !$clientId ? ['Client ID must be provided'] : [],
                        'target_user_id' => !$targetUserId ? ['Target user ID must be provided'] : []
                    ]
                ], 400);
            }

            // Check management capability through service
            $canManage = $this->permissionService->canManageUser(
                $user->id,
                $targetUserId,
                $clientId
            );

            return response()->json([
                'success' => true,
                'message' => 'Management check completed successfully',
                'data' => [
                    'can_manage' => $canManage,
                    'manager_user_id' => $user->id,
                    'target_user_id' => $targetUserId,
                    'client_id' => $clientId
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check management permissions',
                'errors' => [
                    'server' => ['An unexpected error occurred while checking management permissions']
                ]
            ], 500);
        }
    }
}