<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserFeaturePermissionRequest;
use App\Http\Requests\UpdateUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * UserFeaturePermissionController
 * 
 * Handles RBAC operations for user feature permissions within client context.
 * Manages permission granting, updating, and revocation with proper authorization.
 */
class UserFeaturePermissionController extends Controller
{
    /**
     * The user permission service instance.
     *
     * @var \App\Services\UserPermissionService
     */
    protected UserPermissionService $userPermissionService;

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\UserPermissionService $userPermissionService
     */
    public function __construct(UserPermissionService $userPermissionService)
    {
        $this->userPermissionService = $userPermissionService;
    }

    /**
     * Display a listing of user feature permissions.
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Validate required client_id parameter
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id'
        ]);

        $clientId = (int) $request->get('client_id');

        try {
            // TODO: Get authenticated user from request/middleware
            // For now using placeholder - should be extracted from OAuth2 token
            $authenticatedUserId = 1; // TODO: Extract from auth middleware

            // Check if user has permission to view permissions for this client
            // TODO: Implement authorization check via policy
            // $this->authorize('viewAny', [UserFeaturePermission::class, $clientId]);

            // Get permissions with pagination - default to 15 per page
            $permissions = UserFeaturePermission::with(['user', 'client', 'grantor', 'manager'])
                ->forClient($clientId)
                ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => UserFeaturePermissionResource::collection($permissions),
                'meta' => [
                    'current_page' => $permissions->currentPage(),
                    'last_page' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'total' => $permissions->total(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user feature permissions.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created user feature permission.
     * 
     * @param \App\Http\Requests\StoreUserFeaturePermissionRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreUserFeaturePermissionRequest $request): JsonResponse
    {
        try {
            // Extract validated data
            $validatedData = $request->validated();
            
            // TODO: Get authenticated user from request/middleware
            // For now using placeholder - should be extracted from OAuth2 token
            $grantorId = 1; // TODO: Extract from auth middleware

            // Grant permission using service
            $permission = $this->userPermissionService->grantPermission(
                userId: (int) $validatedData['user_id'],
                clientId: (int) $validatedData['client_id'],
                featureId: (int) $validatedData['feature_id'],
                grantorId: $grantorId,
                managerId: (int) $validatedData['manager_user_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'User feature permission granted successfully.',
                'data' => new UserFeaturePermissionResource($permission)
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid permission request.',
                'error' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to grant user feature permission.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified user feature permission.
     * 
     * @param \App\Models\UserFeaturePermission $permission
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(UserFeaturePermission $permission): JsonResponse
    {
        try {
            // TODO: Implement authorization check via policy
            // $this->authorize('view', $permission);

            // Load relationships for complete resource data
            $permission->load(['user', 'client', 'grantor', 'manager']);

            return response()->json([
                'success' => true,
                'data' => new UserFeaturePermissionResource($permission)
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User feature permission not found.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user feature permission.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified user feature permission.
     * 
     * @param \App\Http\Requests\UpdateUserFeaturePermissionRequest $request
     * @param \App\Models\UserFeaturePermission $permission
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateUserFeaturePermissionRequest $request, UserFeaturePermission $permission): JsonResponse
    {
        try {
            // TODO: Implement authorization check via policy
            // $this->authorize('update', $permission);

            // Extract validated data
            $validatedData = $request->validated();

            // Update the permission
            $permission->update($validatedData);

            // Load relationships for complete resource data
            $permission->load(['user', 'client', 'grantor', 'manager']);

            return response()->json([
                'success' => true,
                'message' => 'User feature permission updated successfully.',
                'data' => new UserFeaturePermissionResource($permission)
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User feature permission not found.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user feature permission.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified user feature permission.
     * 
     * @param \App\Models\UserFeaturePermission $permission
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(UserFeaturePermission $permission): JsonResponse
    {
        try {
            // TODO: Implement authorization check via policy
            // $this->authorize('delete', $permission);

            // TODO: Get authenticated user from request/middleware
            $authenticatedUserId = 1; // TODO: Extract from auth middleware

            // Revoke permission using service
            $revoked = $this->userPermissionService->revokePermission(
                userId: $permission->user_id,
                clientId: $permission->client_id,
                featureId: $permission->feature_id
            );

            if (!$revoked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to revoke user feature permission.'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'User feature permission revoked successfully.'
            ], 204);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User feature permission not found.'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke user feature permission.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}