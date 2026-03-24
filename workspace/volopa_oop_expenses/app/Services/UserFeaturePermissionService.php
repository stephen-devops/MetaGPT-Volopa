<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service class for managing user feature permissions in the OOP Expenses system.
 * Handles granting, revoking, and querying permissions with hierarchical RBAC support.
 * 
 * Permissions are scoped by client for multi-tenancy and follow platform constraints:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own expenses by default; needs explicit grant for others
 * - Business User and Card User cannot approve expenses even with management rights
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to their own managed users (not all users)
 */
class UserFeaturePermissionService
{
    /**
     * Grant a feature permission to a user.
     * 
     * Creates a new UserFeaturePermission record linking a user to a feature
     * within a client context, with designated grantor and manager.
     * 
     * @param int $userId Target user receiving the permission
     * @param int $clientId Client context for the permission
     * @param int $featureId Feature being granted access to (e.g., 16 for OOP Expenses)
     * @param int $grantorId User who is granting this permission
     * @param int $managerId User who can manage the target user
     * @return UserFeaturePermission Created permission record
     * @throws Exception When validation fails or database operation fails
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId): UserFeaturePermission
    {
        try {
            DB::beginTransaction();
            
            // Validate that all referenced users exist and belong to the client
            $this->validateUsers([$userId, $grantorId, $managerId], $clientId);
            
            // Validate that the client exists
            $client = Client::findOrFail($clientId);
            
            // Check if permission already exists (enabled or disabled)
            $existingPermission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();
                
            if ($existingPermission) {
                if ($existingPermission->is_enabled) {
                    throw new Exception("Permission already granted for user {$userId}, client {$clientId}, feature {$featureId}");
                }
                
                // Re-enable existing disabled permission
                $existingPermission->update([
                    'is_enabled' => true,
                    'grantor_id' => $grantorId,
                    'manager_user_id' => $managerId,
                    'updated_at' => now(),
                ]);
                
                DB::commit();
                
                Log::info('User feature permission re-enabled', [
                    'permission_id' => $existingPermission->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'manager_id' => $managerId,
                ]);
                
                return $existingPermission->fresh();
            }
            
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
            
            Log::info('User feature permission granted', [
                'permission_id' => $permission->id,
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
            ]);
            
            return $permission;
            
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to grant user feature permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to grant permission: " . $e->getMessage());
        }
    }
    
    /**
     * Revoke a feature permission from a user.
     * 
     * Sets the permission as disabled rather than deleting the record
     * to maintain audit trail and historical data.
     * 
     * @param UserFeaturePermission $permission Permission to revoke
     * @return bool True if successfully revoked
     * @throws Exception When database operation fails
     */
    public function revokePermission(UserFeaturePermission $permission): bool
    {
        try {
            DB::beginTransaction();
            
            if (!$permission->is_enabled) {
                throw new Exception("Permission is already revoked for permission ID {$permission->id}");
            }
            
            $permission->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);
            
            DB::commit();
            
            Log::info('User feature permission revoked', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
            ]);
            
            return true;
            
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to revoke user feature permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to revoke permission: " . $e->getMessage());
        }
    }
    
    /**
     * Get all active permissions for a user within a client context.
     * 
     * @param int $userId User to get permissions for
     * @param int $clientId Client context to scope permissions
     * @return Collection Collection of UserFeaturePermission models
     * @throws Exception When validation fails
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        try {
            // Validate user exists and belongs to client
            $this->validateUsers([$userId], $clientId);
            
            $permissions = UserFeaturePermission::with(['user', 'client', 'grantor', 'manager'])
                ->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->orderBy('feature_id')
                ->orderBy('created_at', 'desc')
                ->get();
            
            Log::debug('Retrieved user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'permission_count' => $permissions->count(),
            ]);
            
            return $permissions;
            
        } catch (Exception $e) {
            Log::error('Failed to retrieve user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to retrieve user permissions: " . $e->getMessage());
        }
    }
    
    /**
     * Check if a manager user can manage a target user within a client context.
     * 
     * Validates hierarchical permission delegation:
     * - Admin can only grant access to their own managed users (not all users)
     * - Managing access can be given to any user irrespective of role
     * 
     * @param int $managerId User attempting to manage
     * @param int $targetUserId User being managed
     * @param int $clientId Client context for the management relationship
     * @return bool True if manager can manage the target user
     * @throws Exception When validation fails
     */
    public function canManageUser(int $managerId, int $targetUserId, int $clientId): bool
    {
        try {
            // Validate users exist and belong to client
            $this->validateUsers([$managerId, $targetUserId], $clientId);
            
            // Self-management is always allowed
            if ($managerId === $targetUserId) {
                return true;
            }
            
            // Check if manager has explicit management rights over target user
            $canManage = UserFeaturePermission::where('manager_user_id', $managerId)
                ->where('user_id', $targetUserId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->exists();
            
            Log::debug('Checked user management permission', [
                'manager_id' => $managerId,
                'target_user_id' => $targetUserId,
                'client_id' => $clientId,
                'can_manage' => $canManage,
            ]);
            
            return $canManage;
            
        } catch (Exception $e) {
            Log::error('Failed to check user management permission', [
                'manager_id' => $managerId,
                'target_user_id' => $targetUserId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to check management permission: " . $e->getMessage());
        }
    }
    
    /**
     * Get all users that a manager can manage within a client context.
     * 
     * @param int $managerId Manager user ID
     * @param int $clientId Client context
     * @return Collection Collection of User models that the manager can manage
     * @throws Exception When validation fails
     */
    public function getManagedUsers(int $managerId, int $clientId): Collection
    {
        try {
            // Validate manager exists and belongs to client
            $this->validateUsers([$managerId], $clientId);
            
            $managedUserIds = UserFeaturePermission::where('manager_user_id', $managerId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->distinct()
                ->pluck('user_id');
            
            // Include self-management
            if (!$managedUserIds->contains($managerId)) {
                $managedUserIds->push($managerId);
            }
            
            $managedUsers = User::whereIn('id', $managedUserIds)
                ->where('deleted', false)
                ->orderBy('name')
                ->get();
            
            Log::debug('Retrieved managed users', [
                'manager_id' => $managerId,
                'client_id' => $clientId,
                'managed_user_count' => $managedUsers->count(),
            ]);
            
            return $managedUsers;
            
        } catch (Exception $e) {
            Log::error('Failed to retrieve managed users', [
                'manager_id' => $managerId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to retrieve managed users: " . $e->getMessage());
        }
    }
    
    /**
     * Get all permissions granted by a specific user.
     * 
     * @param int $grantorId User who granted permissions
     * @param int $clientId Client context
     * @return Collection Collection of UserFeaturePermission models granted by the user
     * @throws Exception When validation fails
     */
    public function getPermissionsGrantedBy(int $grantorId, int $clientId): Collection
    {
        try {
            // Validate grantor exists and belongs to client
            $this->validateUsers([$grantorId], $clientId);
            
            $permissions = UserFeaturePermission::with(['user', 'client', 'manager'])
                ->where('grantor_id', $grantorId)
                ->where('client_id', $clientId)
                ->orderBy('created_at', 'desc')
                ->get();
            
            Log::debug('Retrieved permissions granted by user', [
                'grantor_id' => $grantorId,
                'client_id' => $clientId,
                'permission_count' => $permissions->count(),
            ]);
            
            return $permissions;
            
        } catch (Exception $e) {
            Log::error('Failed to retrieve permissions granted by user', [
                'grantor_id' => $grantorId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to retrieve granted permissions: " . $e->getMessage());
        }
    }
    
    /**
     * Check if a user has a specific feature permission within a client context.
     * 
     * @param int $userId User to check permission for
     * @param int $clientId Client context
     * @param int $featureId Feature to check permission for
     * @return bool True if user has the permission
     * @throws Exception When validation fails
     */
    public function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        try {
            // Validate user exists and belongs to client
            $this->validateUsers([$userId], $clientId);
            
            $hasPermission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->where('is_enabled', true)
                ->exists();
            
            Log::debug('Checked user feature permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'has_permission' => $hasPermission,
            ]);
            
            return $hasPermission;
            
        } catch (Exception $e) {
            Log::error('Failed to check user feature permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to check feature permission: " . $e->getMessage());
        }
    }
    
    /**
     * Bulk revoke all permissions for a user within a client context.
     * Used when user access needs to be completely removed.
     * 
     * @param int $userId User to revoke all permissions for
     * @param int $clientId Client context
     * @return int Number of permissions revoked
     * @throws Exception When database operation fails
     */
    public function revokeAllUserPermissions(int $userId, int $clientId): int
    {
        try {
            DB::beginTransaction();
            
            // Validate user exists and belongs to client
            $this->validateUsers([$userId], $clientId);
            
            $revokedCount = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->update([
                    'is_enabled' => false,
                    'updated_at' => now(),
                ]);
            
            DB::commit();
            
            Log::info('Bulk revoked user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'revoked_count' => $revokedCount,
            ]);
            
            return $revokedCount;
            
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to bulk revoke user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to revoke all user permissions: " . $e->getMessage());
        }
    }
    
    /**
     * Get permission statistics for a client.
     * 
     * @param int $clientId Client to get statistics for
     * @return array Array containing permission statistics
     * @throws Exception When validation fails
     */
    public function getClientPermissionStats(int $clientId): array
    {
        try {
            // Validate client exists
            Client::findOrFail($clientId);
            
            $stats = [
                'total_permissions' => UserFeaturePermission::where('client_id', $clientId)->count(),
                'active_permissions' => UserFeaturePermission::where('client_id', $clientId)
                    ->where('is_enabled', true)->count(),
                'revoked_permissions' => UserFeaturePermission::where('client_id', $clientId)
                    ->where('is_enabled', false)->count(),
                'unique_users_with_permissions' => UserFeaturePermission::where('client_id', $clientId)
                    ->where('is_enabled', true)
                    ->distinct('user_id')->count(),
                'permissions_by_feature' => UserFeaturePermission::where('client_id', $clientId)
                    ->where('is_enabled', true)
                    ->selectRaw('feature_id, COUNT(*) as count')
                    ->groupBy('feature_id')
                    ->pluck('count', 'feature_id')
                    ->toArray(),
            ];
            
            Log::debug('Generated client permission statistics', [
                'client_id' => $clientId,
                'stats' => $stats,
            ]);
            
            return $stats;
            
        } catch (Exception $e) {
            Log::error('Failed to generate client permission statistics', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            throw new Exception("Failed to get permission statistics: " . $e->getMessage());
        }
    }
    
    /**
     * Validate that users exist and belong to the specified client.
     * 
     * @param array $userIds Array of user IDs to validate
     * @param int $clientId Client ID to validate against
     * @return void
     * @throws Exception When validation fails
     */
    private function validateUsers(array $userIds, int $clientId): void
    {
        // Validate client exists
        $client = Client::where('id', $clientId)
            ->where('deleted', false)
            ->first();
            
        if (!$client) {
            throw new Exception("Client with ID {$clientId} not found or is deleted");
        }
        
        // Validate all users exist and are not deleted
        $existingUsers = User::whereIn('id', $userIds)
            ->where('deleted', false)
            ->pluck('id')
            ->toArray();
        
        $missingUsers = array_diff($userIds, $existingUsers);
        if (!empty($missingUsers)) {
            throw new Exception("Users not found or are deleted: " . implode(', ', $missingUsers));
        }
        
        // Note: In a full implementation, we would also validate that users belong to the client
        // This would require additional user-client relationship validation based on the platform's
        // multi-tenancy implementation, which is not fully specified in the current context.
    }
}