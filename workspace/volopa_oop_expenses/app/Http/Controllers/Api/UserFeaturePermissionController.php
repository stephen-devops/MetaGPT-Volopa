<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserFeaturePermissionRequest;
use App\Http\Resources\UserFeaturePermissionResource;
use App\Models\User;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * UserFeaturePermissionController
 * 
 * Handles user feature permission management operations including granting,
 * revoking, and listing permissions with delegation-based RBAC system.
 * 
 * Supports multi-tenant client scoping and permission management hierarchy.
 * All operations are scoped by authenticated user's client context.
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
     * @return void
     */
    public function __construct(UserPermissionService $userPermissionService)
    {
        $this->userPermissionService = $userPermissionService;
        
        // Apply OAuth2 middleware for all routes
        $this->middleware('auth:api');
        
        // Apply client scoping middleware
        $this->middleware('client.scope');
    }

    /**
     * Display a listing of user feature permissions.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Validate that user has permission to view user permissions
            if (!$user->can('viewAny', UserFeaturePermission::class)) {
                return response()->json([
                    'message' => 'Forbidden. Insufficient permissions to view user feature permissions.',
                ], Response::HTTP_FORBIDDEN);
            }

            // Get pagination parameters
            $perPage = min((int) $request->get('per_page', 15), 100); // Max 100 per page
            $page = max((int) $request->get('page', 1), 1);
            
            // Get filter parameters
            $filters = [
                'user_id' => $request->get('user_id'),
                'feature_id' => $request->get('feature_id'),
                'is_enabled' => $request->get('is_enabled'),
                'grantor_id' => $request->get('grantor_id'),
                'manager_user_id' => $request->get('manager_user_id'),
            ];
            
            // Remove null/empty filters
            $filters = array_filter($filters, function ($value) {
                return !is_null($value) && $value !== '';
            });

            // Build query with client scoping
            $query = UserFeaturePermission::with(['user', 'grantor', 'manager'])
                                        ->forClient($clientId);

            // Apply filters
            foreach ($filters as $field => $value) {
                if ($field === 'is_enabled') {
                    $query->where($field, filter_var($value, FILTER_VALIDATE_BOOLEAN));
                } else {
                    $query->where($field, $value);
                }
            }

            // Check if user can only see their own managed permissions
            if (!$user->hasRole(['Primary Admin', 'Admin'])) {
                // Non-admin users can only see permissions they granted or manage
                $query->where(function ($q) use ($user) {
                    $q->where('grantor_id', $user->id)
                      ->orWhere('manager_user_id', $user->id);
                });
            }

            // Apply sorting
            $sortField = $request->get('sort', 'created_at');
            $sortDirection = $request->get('direction', 'desc');
            
            // Validate sort fields
            $allowedSortFields = [
                'id', 'user_id', 'feature_id', 'is_enabled', 
                'grantor_id', 'manager_user_id', 'created_at', 'updated_at'
            ];
            
            if (!in_array($sortField, $allowedSortFields)) {
                $sortField = 'created_at';
            }
            
            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }
            
            $query->orderBy($sortField, $sortDirection);

            // Execute paginated query
            $permissions = $query->paginate($perPage, ['*'], 'page', $page);

            // Transform using resource collection
            return response()->json([
                'data' => UserFeaturePermissionResource::collection($permissions->items()),
                'meta' => [
                    'current_page' => $permissions->currentPage(),
                    'per_page' => $permissions->perPage(),
                    'total' => $permissions->total(),
                    'last_page' => $permissions->lastPage(),
                    'from' => $permissions->firstItem(),
                    'to' => $permissions->lastItem(),
                ],
                'links' => [
                    'first' => $permissions->url(1),
                    'last' => $permissions->url($permissions->lastPage()),
                    'prev' => $permissions->previousPageUrl(),
                    'next' => $permissions->nextPageUrl(),
                ],
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user feature permissions', [
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while retrieving user feature permissions.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Get validated data
            $validatedData = $request->validated();
            
            DB::beginTransaction();
            
            try {
                // Grant the permission using the service
                $permission = $this->userPermissionService->grantPermission(
                    userId: $validatedData['user_id'],
                    clientId: $clientId,
                    featureId: $validatedData['feature_id'],
                    grantorId: $user->id,
                    managerUserId: $validatedData['manager_user_id'] ?? null
                );
                
                DB::commit();
                
                // Load relationships for response
                $permission->load(['user', 'grantor', 'manager']);
                
                // Log successful permission grant
                Log::info('User feature permission granted', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'grantor_id' => $permission->grantor_id,
                    'manager_user_id' => $permission->manager_user_id,
                    'granted_by' => $user->id,
                    'granted_at' => now(),
                ]);
                
                // Return created permission
                return response()->json(
                    new UserFeaturePermissionResource($permission),
                    Response::HTTP_CREATED
                );
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            Log::error('Failed to grant user feature permission', [
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'request_data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while granting the permission.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified user feature permission.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Find the permission with client scoping
            $permission = UserFeaturePermission::with(['user', 'grantor', 'manager'])
                                               ->forClient($clientId)
                                               ->findOrFail($id);
            
            // Check authorization
            if (!$user->can('view', $permission)) {
                return response()->json([
                    'message' => 'Forbidden. Insufficient permissions to view this user feature permission.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            return response()->json(
                new UserFeaturePermissionResource($permission),
                Response::HTTP_OK
            );

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'User feature permission not found.',
            ], Response::HTTP_NOT_FOUND);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user feature permission', [
                'permission_id' => $id,
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while retrieving the permission.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified user feature permission.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Find the permission with client scoping
            $permission = UserFeaturePermission::forClient($clientId)->findOrFail($id);
            
            // Check authorization
            if (!$user->can('update', $permission)) {
                return response()->json([
                    'message' => 'Forbidden. Insufficient permissions to update this user feature permission.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            // Validate update request
            $validatedData = $request->validate([
                'is_enabled' => 'boolean',
                'manager_user_id' => 'nullable|integer|exists:users,id',
            ]);
            
            DB::beginTransaction();
            
            try {
                $originalData = $permission->toArray();
                
                // Update permission fields
                if (isset($validatedData['is_enabled'])) {
                    if ($validatedData['is_enabled']) {
                        $permission->enable();
                    } else {
                        $permission->disable();
                    }
                }
                
                if (array_key_exists('manager_user_id', $validatedData)) {
                    $permission->setManager($validatedData['manager_user_id']);
                }
                
                DB::commit();
                
                // Load relationships for response
                $permission->load(['user', 'grantor', 'manager']);
                
                // Log permission update
                Log::info('User feature permission updated', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'original_data' => $originalData,
                    'updated_data' => $permission->toArray(),
                    'updated_by' => $user->id,
                    'updated_at' => now(),
                ]);
                
                return response()->json(
                    new UserFeaturePermissionResource($permission),
                    Response::HTTP_OK
                );
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'User feature permission not found.',
            ], Response::HTTP_NOT_FOUND);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            Log::error('Failed to update user feature permission', [
                'permission_id' => $id,
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while updating the permission.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified user feature permission.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Find the permission with client scoping
            $permission = UserFeaturePermission::forClient($clientId)->findOrFail($id);
            
            // Check authorization
            if (!$user->can('delete', $permission)) {
                return response()->json([
                    'message' => 'Forbidden. Insufficient permissions to revoke this user feature permission.',
                ], Response::HTTP_FORBIDDEN);
            }
            
            DB::beginTransaction();
            
            try {
                // Store permission data for logging before deletion
                $permissionData = $permission->toArray();
                
                // Revoke the permission using the service
                $revoked = $this->userPermissionService->revokePermission($permission);
                
                if (!$revoked) {
                    throw new \RuntimeException('Failed to revoke permission');
                }
                
                DB::commit();
                
                // Log successful permission revocation
                Log::info('User feature permission revoked', [
                    'permission_id' => $permissionData['id'],
                    'user_id' => $permissionData['user_id'],
                    'client_id' => $permissionData['client_id'],
                    'feature_id' => $permissionData['feature_id'],
                    'grantor_id' => $permissionData['grantor_id'],
                    'manager_user_id' => $permissionData['manager_user_id'],
                    'revoked_by' => $user->id,
                    'revoked_at' => now(),
                ]);
                
                return response()->json(null, Response::HTTP_NO_CONTENT);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'User feature permission not found.',
            ], Response::HTTP_NOT_FOUND);

        } catch (\Exception $e) {
            Log::error('Failed to revoke user feature permission', [
                'permission_id' => $id,
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while revoking the permission.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get users that the authenticated user can manage permissions for.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getManagedUsers(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Get users that this user can manage
            $managedUsers = $this->userPermissionService->getUserManagedUsers($user->id, $clientId);
            
            // Transform users for response (minimal data for dropdown/selection)
            $formattedUsers = $managedUsers->map(function (User $managedUser) {
                return [
                    'id' => $managedUser->id,
                    'name' => $managedUser->name,
                    'email' => $managedUser->email,
                    'role' => $managedUser->role ?? null,
                ];
            });
            
            return response()->json([
                'data' => $formattedUsers,
                'total' => $formattedUsers->count(),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve managed users', [
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while retrieving managed users.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check if the authenticated user can manage a specific user.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $targetUserId
     * @return \Illuminate\Http\JsonResponse
     */
    public function canManageUser(Request $request, int $targetUserId): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Check if user can manage the target user
            $canManage = $this->userPermissionService->canManageUser(
                $user->id,
                $targetUserId,
                $clientId
            );
            
            return response()->json([
                'can_manage' => $canManage,
                'user_id' => $user->id,
                'target_user_id' => $targetUserId,
                'client_id' => $clientId,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to check user management capability', [
                'user_id' => Auth::id(),
                'target_user_id' => $targetUserId,
                'client_id' => $request->get('client_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred while checking user management capability.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Bulk enable or disable permissions for multiple users.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $request->get('client_id'); // Set by client.scope middleware
            
            // Validate bulk update request
            $validatedData = $request->validate([
                'permission_ids' => 'required|array|min:1|max:100', // Max 100 for performance
                'permission_ids.*' => 'integer|exists:user_feature_permission,id',
                'is_enabled' => 'required|boolean',
            ]);
            
            DB::beginTransaction();
            
            try {
                $permissionIds = $validatedData['permission_ids'];
                $isEnabled = $validatedData['is_enabled'];
                $updatedCount = 0;
                $errors = [];
                
                // Process each permission
                foreach ($permissionIds as $permissionId) {
                    try {
                        $permission = UserFeaturePermission::forClient($clientId)
                                                           ->findOrFail($permissionId);
                        
                        // Check authorization for this specific permission
                        if (!$user->can('update', $permission)) {
                            $errors[] = "Permission {$permissionId}: Insufficient permissions to update";
                            continue;
                        }
                        
                        // Update status
                        if ($isEnabled) {
                            $permission->enable();
                        } else {
                            $permission->disable();
                        }
                        
                        $updatedCount++;
                        
                    } catch (ModelNotFoundException $e) {
                        $errors[] = "Permission {$permissionId}: Not found";
                    } catch (\Exception $e) {
                        $errors[] = "Permission {$permissionId}: " . $e->getMessage();
                    }
                }
                
                DB::commit();
                
                // Log bulk update
                Log::info('Bulk user permission status update', [
                    'updated_by' => $user->id,
                    'client_id' => $clientId,
                    'total_requested' => count($permissionIds),
                    'successfully_updated' => $updatedCount,
                    'errors_count' => count($errors),
                    'is_enabled' => $isEnabled,
                    'updated_at' => now(),
                ]);
                
                $response = [
                    'message' => "Bulk update completed. {$updatedCount} permissions updated successfully.",
                    'updated_count' => $updatedCount,
                    'total_requested' => count($permissionIds),
                ];
                
                if (!empty($errors)) {
                    $response['errors'] = $errors;
                }
                
                return response()->json($response, Response::HTTP_OK);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            Log::error('Failed to bulk update user feature permissions', [
                'user_id' => Auth::id(),
                'client_id' => $request->get('client_id'),
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred during bulk update.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}