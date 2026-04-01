<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserFeaturePermissionRequest;
use App\Http\Requests\UpdateUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\UserFeaturePermission;
use App\Services\UserFeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UserFeaturePermissionController
 * 
 * REST API controller for user permission management with role-based access control.
 * Supports delegation where admins can grant permissions to their managed users.
 * All operations are scoped by client_id for multi-tenancy.
 */
class UserFeaturePermissionController extends Controller
{
    protected UserFeaturePermissionService $userFeaturePermissionService;

    /**
     * Constructor - dependency injection for service layer
     * 
     * Note: OAuth2 middleware is applied at route group level, not in constructor
     * as per system constraints.
     */
    public function __construct(UserFeaturePermissionService $userFeaturePermissionService)
    {
        $this->userFeaturePermissionService = $userFeaturePermissionService;
    }

    /**
     * Display a listing of user feature permissions.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Authorize the action
        $this->authorize('viewAny', UserFeaturePermission::class);

        // Validate required client_id parameter
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'user_id' => 'sometimes|integer|exists:users,id',
            'feature_id' => 'sometimes|integer|exists:features,id',
            'is_enabled' => 'sometimes|boolean',
        ]);

        $clientId = (int) $request->input('client_id');
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;
        $featureId = $request->input('feature_id') ? (int) $request->input('feature_id') : null;
        $isEnabled = $request->input('is_enabled');

        // Build query with filters
        $query = UserFeaturePermission::query()
            ->with(['user', 'client', 'feature', 'grantor', 'manager'])
            ->forClient($clientId);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($featureId !== null) {
            $query->forFeature($featureId);
        }

        if ($isEnabled !== null) {
            if ($isEnabled) {
                $query->enabled();
            } else {
                $query->where('is_enabled', false);
            }
        }

        // Apply authorization filters based on user role
        // TODO: Implement role-based filtering based on authenticated user's permissions
        // Primary Admin: see all permissions
        // Admin: see only permissions they granted or for users they manage
        // Business User/Card User: limited access based on specific grants

        $permissions = $query->paginate(50); // Limit to prevent unbounded lists

        return UserFeaturePermissionResource::collection($permissions)
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Store a newly created user feature permission.
     * 
     * @param StoreUserFeaturePermissionRequest $request
     * @return JsonResponse
     */
    public function store(StoreUserFeaturePermissionRequest $request): JsonResponse
    {
        // Authorization is handled in the FormRequest
        
        // Extract validated data
        $validatedData = $request->validated();
        
        // Get authenticated user ID (grantor)
        $grantorId = auth()->id();
        $validatedData['grantor_id'] = $grantorId;

        try {
            // Use service layer for business logic
            $permission = $this->userFeaturePermissionService->createPermission($validatedData);

            return (new UserFeaturePermissionResource($permission))
                ->response()
                ->setStatusCode(201);

        } catch (\Exception $e) {
            // Log error and return appropriate response
            \Log::error('Failed to create user feature permission', [
                'error' => $e->getMessage(),
                'data' => $validatedData,
                'grantor_id' => $grantorId
            ]);

            return response()->json([
                'message' => 'Failed to create permission',
                'error' => 'An error occurred while creating the permission'
            ], 500);
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
        $permission = UserFeaturePermission::with(['user', 'client', 'feature', 'grantor', 'manager'])
            ->findOrFail($id);

        // Authorize the action
        $this->authorize('view', $permission);

        return (new UserFeaturePermissionResource($permission))
            ->response()
            ->setStatusCode(200);
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
        $permission = UserFeaturePermission::findOrFail($id);

        // Authorization is handled in the FormRequest
        
        $validatedData = $request->validated();

        try {
            // Use service layer for business logic
            $updatedPermission = $this->userFeaturePermissionService->updatePermission($permission, $validatedData);

            return (new UserFeaturePermissionResource($updatedPermission))
                ->response()
                ->setStatusCode(200);

        } catch (\Exception $e) {
            // Log error and return appropriate response
            \Log::error('Failed to update user feature permission', [
                'error' => $e->getMessage(),
                'permission_id' => $id,
                'data' => $validatedData,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Failed to update permission',
                'error' => 'An error occurred while updating the permission'
            ], 500);
        }
    }

    /**
     * Remove the specified user feature permission.
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $permission = UserFeaturePermission::findOrFail($id);

        // Authorize the action
        $this->authorize('delete', $permission);

        try {
            // Use service layer for business logic
            $result = $this->userFeaturePermissionService->revokePermission($permission);

            if ($result) {
                return response()->json(null, 204);
            } else {
                return response()->json([
                    'message' => 'Failed to revoke permission',
                    'error' => 'Permission could not be revoked'
                ], 422);
            }

        } catch (\Exception $e) {
            // Log error and return appropriate response
            \Log::error('Failed to revoke user feature permission', [
                'error' => $e->getMessage(),
                'permission_id' => $id,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Failed to revoke permission',
                'error' => 'An error occurred while revoking the permission'
            ], 500);
        }
    }
}