## Code: app/Services/PermissionService.php

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PermissionService
 * 
 * Manages user permissions for pocket expense system using RBAC.
 * Provides methods for checking permissions, managing user roles, and handling hierarchical permissions.
 * Implements caching for performance optimization and handles permission delegation.
 */
class PermissionService
{
    /**
     * Feature ID constants for different features.
     */
    public const FEATURE_POCKET_EXPENSE = 1;
    public const FEATURE_ADMIN = 2;
    public const FEATURE_MANAGER = 3;

    /**
     * Permission levels.
     */
    public const LEVEL_USER = 'user';
    public const LEVEL_MANAGER = 'manager';
    public const LEVEL_ADMIN = 'admin';
    public const LEVEL_PRIMARY_ADMIN = 'primary_admin';

    /**
     * Cache TTL for permissions (15 minutes).
     */
    private const PERMISSION_CACHE_TTL = 900;

    /**
     * Cache key prefix for permissions.
     */
    private const CACHE_PREFIX = 'pocket_expense_permissions';

    /**
     * Check if a user has permission for a specific feature within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function hasPermission(int $userId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): bool
    {
        $cacheKey = $this->getPermissionCacheKey($userId, $clientId, $featureId);
        
        return Cache::remember($cacheKey, self::PERMISSION_CACHE_TTL, function () use ($userId, $clientId, $featureId) {
            return UserFeaturePermission::hasPermission($userId, $clientId, $featureId);
        });
    }

    /**
     * Check if a user is an admin for a specific client.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    public function isAdmin(int $userId, int $clientId): bool
    {
        // Check if user has admin feature permission
        $hasAdminFeature = $this->hasPermission($userId, $clientId, self::FEATURE_ADMIN);
        
        if ($hasAdminFeature) {
            return true;
        }

        // Alternative check: user is a manager for multiple users (indicates admin role)
        $managedUsersCount = $this->getManagedUsersCount($userId, $clientId);
        
        // If managing more than 5 users, likely an admin
        return $managedUsersCount > 5;
    }

    /**
     * Check if a user is a primary admin (highest level).
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    public function isPrimaryAdmin(int $userId, int $clientId): bool
    {
        return $this->hasPermission($userId, $clientId, self::FEATURE_PRIMARY_ADMIN);
    }

    /**
     * Check if a user is a manager within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function isManager(int $userId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): bool
    {
        $cacheKey = $this->getManagerCacheKey($userId, $clientId, $featureId);
        
        return Cache::remember($cacheKey, self::PERMISSION_CACHE_TTL, function () use ($userId, $clientId, $featureId) {
            return UserFeaturePermission::enabled()
                ->forClient($clientId)
                ->forFeature($featureId)
                ->forManager($userId)
                ->exists();
        });
    }

    /**
     * Check if a user can manage another user within a client context.
     *
     * @param int $managerUserId
     * @param int $targetUserId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function canManageUser(int $managerUserId, int $targetUserId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): bool
    {
        // Users can always manage themselves
        if ($managerUserId === $targetUserId) {
            return true;
        }

        // Check if manager user is assigned as manager for target user
        return UserFeaturePermission::enabled()
            ->forUser($targetUserId)
            ->forClient($clientId)
            ->forFeature($featureId)
            ->forManager($managerUserId)
            ->exists();
    }

    /**
     * Get all users that a manager can manage within a client context.
     *
     * @param int $managerUserId
     * @param int $clientId
     * @param int $featureId
     * @return array<int>
     */
    public function getManagedUserIds(int $managerUserId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): array
    {
        $cacheKey = $this->getManagedUsersCacheKey($managerUserId, $clientId, $featureId);
        
        return Cache::remember($cacheKey, self::PERMISSION_CACHE_TTL, function () use ($managerUserId, $clientId, $featureId) {
            $managedUserIds = UserFeaturePermission::enabled()
                ->forClient($clientId)
                ->forFeature($featureId)
                ->forManager($managerUserId)
                ->pluck('user_id')
                ->toArray();

            // Always include the manager themselves
            $managedUserIds[] = $managerUserId;

            return array_unique($managedUserIds);
        });
    }

    /**
     * Get count of users managed by a specific manager.
     *
     * @param int $managerUserId
     * @param int $clientId
     * @param int $featureId
     * @return int
     */
    public function getManagedUsersCount(int $managerUserId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): int
    {
        return UserFeaturePermission::enabled()
            ->forClient($clientId)
            ->forFeature($featureId)
            ->forManager($managerUserId)
            ->count();
    }

    /**
     * Get all permissions for a user within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection<UserFeaturePermission>
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        $cacheKey = $this->getUserPermissionsCacheKey($userId, $clientId);
        
        return Cache::remember($cacheKey, self::PERMISSION_CACHE_TTL, function () use ($userId, $clientId) {
            return UserFeaturePermission::getUserPermissions($userId, $clientId);
        });
    }

    /**
     * Grant permission to a user for a feature within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @param int|null $grantorId
     * @param int|null $managerUserId
     * @param bool $isEnabled
     * @return UserFeaturePermission
     */
    public function grantPermission(
        int $userId,
        int $clientId,
        int $featureId = self::FEATURE_POCKET_EXPENSE,
        ?int $grantorId = null,
        ?int $managerUserId = null,
        bool $isEnabled = true
    ): UserFeaturePermission {
        DB::beginTransaction();
        
        try {
            $permission = UserFeaturePermission::createOrUpdate([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerUserId,
                'is_enabled' => $isEnabled,
            ]);

            // Clear related caches
            $this->clearUserPermissionCaches($userId, $clientId);
            if ($managerUserId) {
                $this->clearManagerPermissionCaches($managerUserId, $clientId);
            }

            DB::commit();

            Log::info('Permission granted', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerUserId,
                'is_enabled' => $isEnabled,
            ]);

            return $permission;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to grant permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Revoke permission from a user for a feature within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function revokePermission(int $userId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): bool
    {
        DB::beginTransaction();
        
        try {
            $permission = UserFeaturePermission::forUserClientFeature($userId, $clientId, $featureId)->first();
            
            if (!$permission) {
                DB::rollBack();
                return false;
            }

            $permission->disable();

            // Clear related caches
            $this->clearUserPermissionCaches($userId, $clientId);
            if ($permission->manager_user_id) {
                $this->clearManagerPermissionCaches($permission->manager_user_id, $clientId);
            }

            DB::commit();

            Log::info('Permission revoked', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to revoke permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Assign a manager to a user for a specific feature within a client context.
     *
     * @param int $userId
     * @param int $managerUserId
     * @param int $clientId
     * @param int $featureId
     * @param int|null $grantorId
     * @return UserFeaturePermission|null
     */
    public function assignManager(
        int $userId,
        int $managerUserId,
        int $clientId,
        int $featureId = self::FEATURE_POCKET_EXPENSE,
        ?int $grantorId = null
    ): ?UserFeaturePermission {
        // Cannot assign self as manager
        if ($userId === $managerUserId) {
            return null;
        }

        // Check if manager has permission in the client
        if (!$this->hasPermission($managerUserId, $clientId, $featureId)) {
            return null;
        }

        DB::beginTransaction();
        
        try {
            $permission = UserFeaturePermission::forUserClientFeature($userId, $clientId, $featureId)->first();
            
            if (!$permission) {
                // Create new permission with manager
                $permission = $this->grantPermission($userId, $clientId, $featureId, $grantorId, $managerUserId);
            } else {
                // Update existing permission
                $permission->manager_user_id = $managerUserId;
                if ($grantorId) {
                    $permission->grantor_id = $grantorId;
                }
                $permission->save();
            }

            // Clear related caches
            $this->clearUserPermissionCaches($userId, $clientId);
            $this->clearManagerPermissionCaches($managerUserId, $clientId);

            DB::commit();

            Log::info('Manager assigned', [
                'user_id' => $userId,
                'manager_user_id' => $managerUserId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
            ]);

            return $permission;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to assign manager', [
                'user_id' => $userId,
                'manager_user_id' => $managerUserId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Remove manager assignment from a user.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function removeManager(int $userId, int $clientId, int $featureId = self::FEATURE_POCKET_EXPENSE): bool
    {
        DB::beginTransaction();
        
        try {
            $permission = UserFeaturePermission::forUserClientFeature($userId, $clientId, $featureId)->first();
            
            if (!$permission) {
                DB::rollBack();
                return false;
            }

            $oldManagerId = $permission->manager_user_id;
            $permission->manager_user_id = null;
            $permission->save();

            // Clear related caches
            $this->clearUserPermissionCaches($userId, $clientId);
            if ($oldManagerId) {
                $this->clearManagerPermissionCaches($oldManagerId, $clientId);
            }

            DB::commit();

            Log::info('Manager removed', [
                'user_id' => $userId,
                'old_manager_user_id' => $oldManagerId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to remove manager', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get the permission level for a user within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @return string
     */
    public function getUserPermissionLevel(int $userId, int $clientId): string
    {
        if ($this->isPrimaryAdmin($userId, $clientId)) {
            return self::LEVEL_PRIMARY_ADMIN;
        }

        if ($this->isAdmin($userId, $clientId)) {
            return self::LEVEL_ADMIN;
        }

        if ($this->isManager($userId, $clientId)) {
            return self::LEVEL_MANAGER;
        }

        return self::LEVEL_USER;
    }

    