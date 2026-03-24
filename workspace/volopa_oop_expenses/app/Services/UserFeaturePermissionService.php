<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Exception;

/**
 * Service class for managing user feature permissions
 * 
 * Handles the business logic for granting, revoking, and managing user permissions
 * for features within client contexts. Enforces permission hierarchy and delegation
 * rules based on user roles and management relationships.
 */
class UserFeaturePermissionService
{
    /**
     * Grant permission to a user for a specific feature within a client context.
     * 
     * @param int $userId User receiving the permission
     * @param int $clientId Client context for the permission
     * @param int $featureId Feature being granted (16 = OOP Expense)
     * @param int $grantorId User who is granting this permission
     * @param int|null $managerId Optional manager who can manage the target user
     * @return UserFeaturePermission
     * @throws Exception
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, ?int $managerId = null): UserFeaturePermission
    {
        DB::beginTransaction();
        
        try {
            // Check if permission already exists
            $existingPermission = UserFeaturePermission::where([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId
            ])->first();
            
            if ($existingPermission) {
                if ($existingPermission->is_enabled) {
                    throw new Exception("User already has this permission granted");
                } else {
                    // Re-enable existing permission
                    $existingPermission->update([
                        'is_enabled' => 1,
                        'grantor_id' => $grantorId,
                        'manager_user_id' => $managerId,
                        'update_time' => Carbon::now()
                    ]);
                    
                    DB::commit();
                    return $existingPermission->fresh();
                }
            }
            
            // Create new permission record
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => 1,
                'create_time' => Carbon::now(),
                'update_time' => Carbon::now()
            ]);
            
            DB::commit();
            return $permission;
            
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
    
    /**
     * Revoke permission from a user for a specific feature within a client context.
     * 
     * @param int $userId User whose permission is being revoked
     * @param int $clientId Client context for the permission
     * @param int $featureId Feature being revoked
     * @return bool
     * @throws Exception
     */
    public function revokePermission(int $userId, int $clientId, int $featureId): bool
    {
        DB::beginTransaction();
        
        try {
            $permission = UserFeaturePermission::where([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId
            ])->first();
            
            if (!$permission) {
                throw new Exception("Permission not found for this user, client, and feature combination");
            }
            
            if (!$permission->is_enabled) {
                throw new Exception("Permission is already revoked");
            }
            
            $permission->update([
                'is_enabled' => 0,
                'update_time' => Carbon::now()
            ]);
            
            DB::commit();
            return true;
            
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
    
    /**
     * Get all permissions for a user within a specific client context.
     * 
     * @param int $userId User whose permissions are being retrieved
     * @param int $clientId Client context to filter permissions
     * @return Collection
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        return UserFeaturePermission::where([
            'user_id' => $userId,
            'client_id' => $clientId,
            'is_enabled' => 1
        ])
        ->with(['user', 'client', 'grantor', 'manager'])
        ->orderBy('create_time', 'desc')
        ->get();
    }
    
    /**
     * Check if a manager can manage a target user within a client context.
     * 
     * Based on permission hierarchy and explicit management grants. Primary Admin
     * has full access to all users by default. Admin gets full access only to own
     * expenses by default unless explicitly granted management rights.
     * 
     * @param int $managerId User attempting to manage
     * @param int $targetUserId User being managed
     * @param int $clientId Client context for the management relationship
     * @return bool
     */
    public function canManageUser(int $managerId, int $targetUserId, int $clientId): bool
    {
        // Self-management is always allowed
        if ($managerId === $targetUserId) {
            return true;
        }
        
        // Check if manager has explicit management rights for the target user
        $managementPermission = UserFeaturePermission::where([
            'client_id' => $clientId,
            'manager_user_id' => $managerId,
            'user_id' => $targetUserId,
            'is_enabled' => 1
        ])->exists();
        
        if ($managementPermission) {
            return true;
        }
        
        // Additional role-based checks would go here if user roles were available
        // For now, we rely on explicit permission grants through the manager_user_id field
        
        return false;
    }
    
    /**
     * Get all users that a manager can manage within a client context.
     * 
     * @param int $managerId User whose managed users are being retrieved
     * @param int $clientId Client context to filter managed users
     * @return Collection
     */
    public function getManagedUsers(int $managerId, int $clientId): Collection
    {
        return UserFeaturePermission::where([
            'client_id' => $clientId,
            'manager_user_id' => $managerId,
            'is_enabled' => 1
        ])
        ->with(['user'])
        ->get()
        ->pluck('user')
        ->unique('id')
        ->values();
    }
    
    /**
     * Get all permissions granted by a specific grantor within a client context.
     * 
     * @param int $grantorId User whose granted permissions are being retrieved
     * @param int $clientId Client context to filter permissions
     * @return Collection
     */
    public function getPermissionsGrantedBy(int $grantorId, int $clientId): Collection
    {
        return UserFeaturePermission::where([
            'grantor_id' => $grantorId,
            'client_id' => $clientId
        ])
        ->with(['user', 'manager'])
        ->orderBy('create_time', 'desc')
        ->get();
    }
    
    /**
     * Check if a user has a specific permission within a client context.
     * 
     * @param int $userId User whose permission is being checked
     * @param int $clientId Client context for the permission
     * @param int $featureId Feature being checked
     * @return bool
     */
    public function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        return UserFeaturePermission::where([
            'user_id' => $userId,
            'client_id' => $clientId,
            'feature_id' => $featureId,
            'is_enabled' => 1
        ])->exists();
    }
    
    /**
     * Get all active permissions for a specific feature across all users in a client.
     * 
     * @param int $clientId Client context to filter permissions
     * @param int $featureId Feature to filter permissions
     * @return Collection
     */
    public function getFeaturePermissions(int $clientId, int $featureId): Collection
    {
        return UserFeaturePermission::where([
            'client_id' => $clientId,
            'feature_id' => $featureId,
            'is_enabled' => 1
        ])
        ->with(['user', 'grantor', 'manager'])
        ->orderBy('create_time', 'desc')
        ->get();
    }
    
    /**
     * Update the manager for an existing permission.
     * 
     * @param int $userId User whose permission manager is being updated
     * @param int $clientId Client context for the permission
     * @param int $featureId Feature for the permission
     * @param int|null $newManagerId New manager user ID (null to remove manager)
     * @return UserFeaturePermission
     * @throws Exception
     */
    public function updatePermissionManager(int $userId, int $clientId, int $featureId, ?int $newManagerId = null): UserFeaturePermission
    {
        DB::beginTransaction();
        
        try {
            $permission = UserFeaturePermission::where([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'is_enabled' => 1
            ])->first();
            
            if (!$permission) {
                throw new Exception("Active permission not found for this user, client, and feature combination");
            }
            
            $permission->update([
                'manager_user_id' => $newManagerId,
                'update_time' => Carbon::now()
            ]);
            
            DB::commit();
            return $permission->fresh();
            
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
    
    /**
     * Get permission statistics for a client.
     * 
     * @param int $clientId Client context for statistics
     * @return array
     */
    public function getPermissionStatistics(int $clientId): array
    {
        $totalPermissions = UserFeaturePermission::where('client_id', $clientId)->count();
        $activePermissions = UserFeaturePermission::where([
            'client_id' => $clientId,
            'is_enabled' => 1
        ])->count();
        
        $permissionsByFeature = UserFeaturePermission::where([
            'client_id' => $clientId,
            'is_enabled' => 1
        ])
        ->select('feature_id', DB::raw('count(*) as count'))
        ->groupBy('feature_id')
        ->pluck('count', 'feature_id')
        ->toArray();
        
        $uniqueUsersWithPermissions = UserFeaturePermission::where([
            'client_id' => $clientId,
            'is_enabled' => 1
        ])
        ->distinct('user_id')
        ->count('user_id');
        
        $uniqueManagersGrantingAccess = UserFeaturePermission::where([
            'client_id' => $clientId,
            'is_enabled' => 1
        ])
        ->whereNotNull('manager_user_id')
        ->distinct('manager_user_id')
        ->count('manager_user_id');
        
        return [
            'total_permissions' => $totalPermissions,
            'active_permissions' => $activePermissions,
            'revoked_permissions' => $totalPermissions - $activePermissions,
            'permissions_by_feature' => $permissionsByFeature,
            'unique_users_with_permissions' => $uniqueUsersWithPermissions,
            'unique_managers_granting_access' => $uniqueManagersGrantingAccess
        ];
    }
    
    /**
     * Bulk grant permissions to multiple users for the same feature and client.
     * 
     * @param array $userIds Array of user IDs to grant permissions to
     * @param int $clientId Client context for the permissions
     * @param int $featureId Feature being granted
     * @param int $grantorId User who is granting these permissions
     * @param int|null $managerId Optional manager for all users
     * @return Collection
     * @throws Exception
     */
    public function bulkGrantPermissions(array $userIds, int $clientId, int $featureId, int $grantorId, ?int $managerId = null): Collection
    {
        DB::beginTransaction();
        
        try {
            $grantedPermissions = collect();
            
            foreach ($userIds as $userId) {
                $permission = $this->grantPermission($userId, $clientId, $featureId, $grantorId, $managerId);
                $grantedPermissions->push($permission);
            }
            
            DB::commit();
            return $grantedPermissions;
            
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }
    }
    
    /**
     * Bulk revoke permissions from multiple users for the same feature and client.
     * 
     * @param array $userIds Array of user IDs to revoke permissions from
     * @param int $clientId Client context for the permissions
     * @param int $featureId Feature being revoked
     * @return array Results array with success/failure status for each user
     */
    public function bulkRevokePermissions(array $userIds, int $clientId, int $featureId): array
    {
        $results = [];
        
        foreach ($userIds as $userId) {
            try {
                $success = $this->revokePermission($userId, $clientId, $featureId);
                $results[$userId] = ['success' => $success, 'error' => null];
            } catch (Exception $e) {
                $results[$userId] = ['success' => false, 'error' => $e->getMessage()];
            }
        }
        
        return $results;
    }
    
    /**
     * Get users without permission for a specific feature within a client.
     * This method would require additional user lookup logic based on available user data.
     * 
     * @param int $clientId Client context
     * @param int $featureId Feature to check
     * @return Collection
     */
    public function getUsersWithoutPermission(int $clientId, int $featureId): Collection
    {
        // This method would need access to all users within a client to determine
        // who doesn't have permissions. Implementation depends on available user
        // relationship data that isn't defined in the current context.
        
        $usersWithPermission = UserFeaturePermission::where([
            'client_id' => $clientId,
            'feature_id' => $featureId,
            'is_enabled' => 1
        ])->pluck('user_id')->toArray();
        
        // Would need to query all client users and exclude those with permissions
        // This requires additional client-user relationship data not defined in scope
        
        return collect(); // Placeholder - would implement based on available user data
    }
    
    /**
     * Validate if a user can grant permissions based on their role and existing permissions.
     * 
     * @param int $grantorId User attempting to grant permissions
     * @param int $clientId Client context
     * @param int $featureId Feature being granted
     * @return bool
     */
    public function canGrantPermissions(int $grantorId, int $clientId, int $featureId): bool
    {
        // Basic validation - grantor should have the permission they're trying to grant
        $grantorHasPermission = $this->hasPermission($grantorId, $clientId, $featureId);
        
        if (!$grantorHasPermission) {
            return false;
        }
        
        // Additional role-based checks would be implemented here based on
        // user role data (Primary Admin, Admin, Business User, Card User)
        // that isn't available in the current context
        
        return true;
    }
}