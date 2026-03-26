<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserFeaturePermissionRequest;
use App\Http\Requests\UpdateUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * User Feature Permission Controller
 * 
 * Handles REST API operations for user feature permissions.
 * Implements RBAC with delegation capabilities for OOP Expense management.
 * All operations are scoped by client for multi-tenancy.
 */
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
        
        // Apply OAuth2 authentication middleware per platform requirements
        $this->middleware('auth:api');
    }

    /**
     * Display a paginated listing of user feature permissions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get client_id from authenticated user context or request
            // TODO: Implement proper client context extraction from authenticated user
            $clientId = $request->input('client_id');
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Build query with filters and client scoping
            $query = UserFeaturePermission::forClient($clientId)
                ->with(['user', 'client', 'grantor', 'manager']);

            // Apply optional filters
            if ($request->has('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }

            if ($request->has('feature_id')) {
                $query->forFeature($request->input('feature_id'));
            }

            if ($request->has('manager_user_id')) {
                $query->managedBy($request->input('manager_user_id'));
            }

            if ($request->has('is_enabled')) {
                $isEnabled = filter_var($request->input('is_enabled'), FILTER_VALIDATE_BOOLEAN);
                if ($isEnabled) {
                    $query->enabled();
                } else {
                    $query->where('is_enabled', false);
                }
            }

            // Apply pagination
            $perPage = min($request->input('per_page', 15), 100); // Limit to prevent unbounded lists
            $permissions = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => UserFeaturePermissionResource::collection($permissions),
                'meta' => [
                    'current_page' => $permissions->currentPage(),
                    'last_page' => $permissions->lastPage(),
                    'per_page' => $permissions->perPage(),
                    'total' => $permissions->total(),
                ],
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            // TODO: Add proper observability logging
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permissions',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
            // Extract validated data
            $validatedData = $request->validated();

            // Grant permission through service layer
            $permission = $this->userPermissionService->grantPermission(
                $validatedData['user_id'],
                $validatedData['client_id'],
                $validatedData['feature_id'],
                $validatedData['grantor_id'],
                $validatedData['manager_user_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'Permission granted successfully',
                'data' => new UserFeaturePermissionResource($permission),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            // TODO: Add proper observability logging
            return response()->json([
                'success' => false,
                'message' => 'Failed to grant permission: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified user feature permission.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::with(['user', 'client', 'grantor', 'manager'])
                ->find($id);

            if (!$permission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // TODO: Add authorization check to ensure user can view this permission
            // Policy check should verify client scoping and user permissions

            return response()->json([
                'success' => true,
                'data' => new UserFeaturePermissionResource($permission),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            // TODO: Add proper observability logging
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permission',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified user feature permission.
     *
     * @param UpdateUserFeaturePermissionRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateUserFeaturePermissionRequest $request, int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::find($id);

            if (!$permission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Extract validated data
            $validatedData = $request->validated();

            // Update permission through service layer or direct model update
            // For simple field updates, we can update directly
            if (isset($validatedData['is_enabled'])) {
                $permission->is_enabled = $validatedData['is_enabled'];
            }

            if (isset($validatedData['manager_user_id'])) {
                $permission->manager_user_id = $validatedData['manager_user_id'];
            }

            $permission->save();

            return response()->json([
                'success' => true,
                'message' => 'Permission updated successfully',
                'data' => new UserFeaturePermissionResource($permission->fresh()),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            // TODO: Add proper observability logging
            return response()->json([
                'success' => false,
                'message' => 'Failed to update permission: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified user feature permission (revoke).
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $permission = UserFeaturePermission::find($id);

            if (!$permission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // TODO: Add authorization check to ensure user can revoke this permission
            // Policy check should verify client scoping and user permissions

            // Revoke permission through service layer
            $revoked = $this->userPermissionService->revokePermission(
                $permission->user_id,
                $permission->client_id,
                $permission->feature_id
            );

            if ($revoked) {
                return response()->json([
                    'success' => true,
                    'message' => 'Permission revoked successfully',
                ], Response::HTTP_NO_CONTENT);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to revoke permission',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            // TODO: Add proper observability logging
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke permission: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}