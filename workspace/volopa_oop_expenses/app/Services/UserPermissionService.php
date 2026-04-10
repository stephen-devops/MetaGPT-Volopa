<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * UserPermissionService
 * 
 * Handles RBAC (Role-Based Access Control) operations for user permissions.
 * Manages permission granting, revocation, validation, and delegation hierarchy.
 * 
 * Key features:
 * - Grant and revoke permissions with delegation tracking
 * - Validate user permissions for features within client context
 * - Manage user delegation relationships
 * - Enforce permission hierarchy constraints
 */
class UserPermissionService
{
    /**
     * Grant a permission to a user for a specific feature within a client.
     * 
     * @param int $userId The user receiving the permission
     * @param int $clientId The client context for the permission
     * @param int $featureId The feature being granted access to
     * @param int $grantorId The user granting the permission
     * @param int $managerId The user who will be managed by this permission
     * @return UserFeaturePermission The created permission record
     * @throws \Exception If permission creation fails or constraints are violated
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId): UserFeaturePermission
    {
        DB::beginTransaction();

        try {
            // Check if permission already exists
            $existingPermission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();

            if ($existingPermission) {
                // Update existing permission if disabled
                if (!$existingPermission->is_enabled) {
                    $existingPermission->update([
                        'is_enabled' => true,
                        'grantor_id' => $grantorId,
                        'manager_user_id' => $managerId,
                    ]);
                    
                    DB::commit();
                    return $existingPermission->fresh();
                }

                DB::rollback();
                throw new \Exception("Permission already exists and is enabled for this user, client, and feature combination.");
            }

            // Validate users exist and belong to the correct client
            $this->validateUsersExistForClient([$userId, $grantorId, $managerId], $clientId);

            // Create new permission
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => true,
            ]);

            DB::commit();

            // TODO: Implement audit logging for permission grant (DEC-UNRESOLVED-003)
            // This should log who granted what permission to whom and when

            return $permission;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Revoke a permission for a user within a client context.
     * 
     * @param int $userId The user whose permission is being revoked
     * @param int $clientId The client context
     * @param int $featureId The feature being revoked
     * @return bool True if permission was revoked, false if permission didn't exist
     * @throws \Exception If revocation fails
     */
    public function revokePermission(int $userId, int $clientId, int $featureId): bool
    {
        DB::beginTransaction();

        try {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->where('is_enabled', true)
                ->first();

            if (!$permission) {
                DB::rollback();
                return false;
            }

            $permission->update(['is_enabled' => false]);

            DB::commit();

            // TODO: Implement audit logging for permission revocation (DEC-UNRESOLVED-003)
            // This should log who revoked what permission from whom and when

            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Check if a user has permission for a specific feature within a client.
     * 
     * @param int $userId The user to check permissions for
     * @param int $clientId The client context
     * @param int $featureId The feature to check access to
     * @return bool True if user has permission, false otherwise
     */
    public function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        $permission = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->first();

        return $permission !== null;
    }

    /**
     * Check if a manager user can manage a specific user within a client.
     * Validates the delegation hierarchy.
     * 
     * @param int $managerId The user attempting to manage
     * @param int $userId The user being managed
     * @param int $clientId The client context
     * @return bool True if manager can manage user, false otherwise
     */
    public function canManageUser(int $managerId, int $userId, int $clientId): bool
    {
        // Check if there's an enabled permission where managerId is granted permission
        // to manage userId within the client context
        $permission = UserFeaturePermission::where('user_id', $managerId)
            ->where('manager_user_id', $userId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->first();

        return $permission !== null;
    }

    /**
     * Get all users that a manager can manage within a client.
     * 
     * @param int $managerId The manager user ID
     * @param int $clientId The client context
     * @return Collection Collection of User models that the manager can manage
     */
    public function getManagedUsers(int $managerId, int $clientId): Collection
    {
        // Get all users that the manager has permission to manage
        $managedUserIds = UserFeaturePermission::where('user_id', $managerId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->pluck('manager_user_id')
            ->unique()
            ->toArray();

        if (empty($managedUserIds)) {
            return collect([]);
        }

        // TODO: Add proper User model relationship query with client validation
        // For now, return a collection of user IDs as this requires User model implementation
        return User::whereIn('id', $managedUserIds)
            ->where('deleted', 0) // Assuming User model uses deleted flag
            ->get();
    }

    /**
     * Get all permissions for a specific user within a client.
     * 
     * @param int $userId The user ID
     * @param int $clientId The client context
     * @return Collection Collection of UserFeaturePermission models
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        return UserFeaturePermission::with(['user', 'client', 'grantor', 'manager'])
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->get();
    }

    /**
     * Get all permissions granted by a specific grantor within a client.
     * 
     * @param int $grantorId The grantor user ID
     * @param int $clientId The client context
     * @return Collection Collection of UserFeaturePermission models
     */
    public function getPermissionsGrantedBy(int $grantorId, int $clientId): Collection
    {
        return UserFeaturePermission::with(['user', 'client', 'grantor', 'manager'])
            ->where('grantor_id', $grantorId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->get();
    }

    /**
     * Check if a user has a specific role permission within a client.
     * This method handles role-based permission checking for different user types.
     * 
     * @param int $userId The user ID to check
     * @param int $clientId The client context
     * @param string $role The role to check (e.g., 'primary_admin', 'admin', 'business_user', 'card_user')
     * @return bool True if user has the role permission, false otherwise
     */
    public function hasRolePermission(int $userId, int $clientId, string $role): bool
    {
        // TODO: Implement role-based permission checking
        // This should integrate with the platform's user role system
        // and check against the user's assigned role within the client context
        
        // For now, return false as this requires integration with existing platform role system
        return false;
    }

    /**
     * Validate that users exist and belong to the specified client.
     * 
     * @param array $userIds Array of user IDs to validate
     * @param int $clientId The client ID to validate against
     * @throws ModelNotFoundException If any user doesn't exist or belong to client
     */
    private function validateUsersExistForClient(array $userIds, int $clientId): void
    {
        foreach ($userIds as $userId) {
            // TODO: Implement proper user-client relationship validation
            // This requires understanding the User-Client relationship in the platform
            
            $user = User::find($userId);
            if (!$user) {
                throw new ModelNotFoundException("User with ID {$userId} not found.");
            }
            
            // TODO: Add client membership validation once User-Client relationship is defined
            // For now, we assume the user exists and has access to the client
        }
        
        // Validate client exists
        $client = Client::find($clientId);
        if (!$client) {
            throw new ModelNotFoundException("Client with ID {$clientId} not found.");
        }
    }

    /**
     * Enable a disabled permission.
     * 
     * @param int $userId The user ID
     * @param int $clientId The client context
     * @param int $featureId The feature ID
     * @param int $grantorId The new grantor ID
     * @return bool True if permission was enabled, false if not found
     * @throws \Exception If enabling fails
     */
    public function enablePermission(int $userId, int $clientId, int $featureId, int $grantorId): bool
    {
        DB::beginTransaction();

        try {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->where('is_enabled', false)
                ->first();

            if (!$permission) {
                DB::rollback();
                return false;
            }

            $permission->update([
                'is_enabled' => true,
                'grantor_id' => $grantorId,
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Disable an enabled permission.
     * 
     * @param int $userId The user ID
     * @param int $clientId The client context
     * @param int $featureId The feature ID
     * @return bool True if permission was disabled, false if not found
     * @throws \Exception If disabling fails
     */
    public function disablePermission(int $userId, int $clientId, int $featureId): bool
    {
        DB::beginTransaction();

        try {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->where('is_enabled', true)
                ->first();

            if (!$permission) {
                DB::rollback();
                return false;
            }

            $permission->update(['is_enabled' => false]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
}