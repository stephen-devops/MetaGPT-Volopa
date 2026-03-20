<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * UserPermissionService
 * 
 * Service for managing user feature permissions with delegation-based RBAC system.
 * Handles permission grants, revokes, management hierarchy, and authorization checks.
 * Supports multi-tenant client scoping and audit logging.
 */
class UserPermissionService
{
    /**
     * Feature ID constants for pocket expense system.
     */
    const FEATURE_POCKET_EXPENSE_CREATE = 1;
    const FEATURE_POCKET_EXPENSE_VIEW = 2;
    const FEATURE_POCKET_EXPENSE_EDIT = 3;
    const FEATURE_POCKET_EXPENSE_DELETE = 4;
    const FEATURE_POCKET_EXPENSE_APPROVE = 5;
    const FEATURE_POCKET_EXPENSE_UPLOAD = 6;
    const FEATURE_POCKET_EXPENSE_MANAGE_USERS = 7;

    /**
     * Valid feature IDs.
     *
     * @var array<int>
     */
    protected static array $validFeatureIds = [
        self::FEATURE_POCKET_EXPENSE_CREATE,
        self::FEATURE_POCKET_EXPENSE_VIEW,
        self::FEATURE_POCKET_EXPENSE_EDIT,
        self::FEATURE_POCKET_EXPENSE_DELETE,
        self::FEATURE_POCKET_EXPENSE_APPROVE,
        self::FEATURE_POCKET_EXPENSE_UPLOAD,
        self::FEATURE_POCKET_EXPENSE_MANAGE_USERS,
    ];

    /**
     * Grant a feature permission to a user.
     *
     * @param int $userId The user receiving the permission
     * @param int $clientId The client scope for the permission
     * @param int $featureId The feature being granted access to
     * @param int $grantorId The user granting the permission
     * @param int|null $managerId Optional user who can manage this permission
     * @return UserFeaturePermission
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function grantPermission(
        int $userId,
        int $clientId,
        int $featureId,
        int $grantorId,
        ?int $managerId = null
    ): UserFeaturePermission {
        // Validate inputs
        $this->validateGrantPermissionInputs($userId, $clientId, $featureId, $grantorId, $managerId);

        // Check if permission already exists
        $existingPermission = $this->findExistingPermission($userId, $clientId, $featureId);
        
        if ($existingPermission) {
            if ($existingPermission->is_enabled) {
                throw new RuntimeException("User already has this permission enabled for the specified client and feature.");
            }
            
            // Re-enable existing permission
            return $this->reEnablePermission($existingPermission, $grantorId, $managerId);
        }

        // Create new permission within a database transaction
        return DB::transaction(function () use ($userId, $clientId, $featureId, $grantorId, $managerId) {
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => true,
            ]);

            Log::info('User permission granted', [
                'permission_id' => $permission->id,
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
                'granted_at' => now(),
            ]);

            return $permission;
        });
    }

    /**
     * Revoke a feature permission from a user.
     *
     * @param UserFeaturePermission $permission
     * @return bool
     * @throws RuntimeException
     */
    public function revokePermission(UserFeaturePermission $permission): bool
    {
        if (!$permission->is_enabled) {
            throw new RuntimeException("Permission is already disabled.");
        }

        return DB::transaction(function () use ($permission) {
            $success = $permission->disable();

            if ($success) {
                Log::info('User permission revoked', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'revoked_at' => now(),
                ]);
            }

            return $success;
        });
    }

    /**
     * Check if a user can manage another user within the client scope.
     * Based on delegation-based RBAC system where admins can grant management rights.
     *
     * @param int $managerId The user attempting to manage
     * @param int $targetUserId The user being managed
     * @param int $clientId The client scope
     * @return bool
     */
    public function canManageUser(int $managerId, int $targetUserId, int $clientId): bool
    {
        // Users cannot manage themselves
        if ($managerId === $targetUserId) {
            return false;
        }

        // Check if manager has explicit management permission for the target user
        $managementPermission = UserFeaturePermission::active()
            ->forClient($clientId)
            ->forFeature(self::FEATURE_POCKET_EXPENSE_MANAGE_USERS)
            ->where('user_id', $managerId)
            ->where(function ($query) use ($targetUserId) {
                $query->whereNull('manager_user_id')  // Full management rights
                      ->orWhereHas('managedUsers', function ($subQuery) use ($targetUserId) {
                          $subQuery->where('user_id', $targetUserId);
                      });
            })
            ->exists();

        if ($managementPermission) {
            return true;
        }

        // Check if manager is the grantor of any permissions for the target user
        $grantorPermission = UserFeaturePermission::active()
            ->forClient($clientId)
            ->where('user_id', $targetUserId)
            ->where('grantor_id', $managerId)
            ->exists();

        return $grantorPermission;
    }

    /**
     * Get all users that a specific user can manage within a client.
     *
     * @param int $managerId The user who is managing
     * @param int $clientId The client scope
     * @return Collection<int, User>
     */
    public function getUserManagedUsers(int $managerId, int $clientId): Collection
    {
        // Get users where the manager is either the grantor or has explicit management permission
        $userIds = UserFeaturePermission::active()
            ->forClient($clientId)
            ->where(function ($query) use ($managerId) {
                $query->where('grantor_id', $managerId)  // Users whose permissions were granted by this manager
                      ->orWhere('manager_user_id', $managerId);  // Users explicitly managed by this manager
            })
            ->distinct()
            ->pluck('user_id')
            ->unique()
            ->filter(function ($userId) use ($managerId) {
                return $userId !== $managerId;  // Exclude self
            });

        if ($userIds->isEmpty()) {
            return collect();
        }

        // Return the actual User models
        return User::whereIn('id', $userIds)
                   ->orderBy('name', 'asc')
                   ->get();
    }

    /**
     * Check if a user has a specific feature permission within a client.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        return UserFeaturePermission::active()
            ->forClient($clientId)
            ->forUser($userId)
            ->forFeature($featureId)
            ->exists();
    }

    /**
     * Get all active permissions for a user within a client.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection<int, UserFeaturePermission>
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        return UserFeaturePermission::active()
            ->forClient($clientId)
            ->forUser($userId)
            ->with(['grantor', 'manager'])
            ->orderBy('feature_id', 'asc')
            ->get();
    }

    /**
     * Get all users who have permissions within a client.
     *
     * @param int $clientId
     * @param int|null $featureId Optional filter by specific feature
     * @return Collection<int, User>
     */
    public function getClientUsers(int $clientId, ?int $featureId = null): Collection
    {
        $query = UserFeaturePermission::active()
            ->forClient($clientId);

        if ($featureId !== null) {
            $query->forFeature($featureId);
        }

        $userIds = $query->distinct()
                        ->pluck('user_id')
                        ->unique();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $userIds)
                   ->orderBy('name', 'asc')
                   ->get();
    }

    /**
     * Transfer management of permissions from one user to another.
     *
     * @param int $fromManagerId
     * @param int $toManagerId
     * @param int $clientId
     * @param array<int>|null $userIds Optional specific users to transfer, null for all
     * @return int Number of permissions transferred
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function transferManagement(
        int $fromManagerId,
        int $toManagerId,
        int $clientId,
        ?array $userIds = null
    ): int {
        // Validate inputs
        if ($fromManagerId === $toManagerId) {
            throw new InvalidArgumentException("Cannot transfer management to the same user.");
        }

        $this->validateUserExists($fromManagerId);
        $this->validateUserExists($toManagerId);
        $this->validateClientExists($clientId);

        return DB::transaction(function () use ($fromManagerId, $toManagerId, $clientId, $userIds) {
            $query = UserFeaturePermission::active()
                ->forClient($clientId)
                ->where('manager_user_id', $fromManagerId);

            if ($userIds !== null && !empty($userIds)) {
                $query->whereIn('user_id', $userIds);
            }

            $permissions = $query->get();
            $transferCount = 0;

            foreach ($permissions as $permission) {
                $permission->manager_user_id = $toManagerId;
                if ($permission->save()) {
                    $transferCount++;
                }
            }

            Log::info('Permission management transferred', [
                'from_manager_id' => $fromManagerId,
                'to_manager_id' => $toManagerId,
                'client_id' => $clientId,
                'user_ids' => $userIds,
                'permissions_transferred' => $transferCount,
                'transferred_at' => now(),
            ]);

            return $transferCount;
        });
    }

    /**
     * Bulk revoke permissions for a user across all features within a client.
     *
     * @param int $userId
     * @param int $clientId
     * @return int Number of permissions revoked
     */
    public function revokeAllUserPermissions(int $userId, int $clientId): int
    {
        return DB::transaction(function () use ($userId, $clientId) {
            $permissions = UserFeaturePermission::active()
                ->forClient($clientId)
                ->forUser($userId)
                ->get();

            $revokedCount = 0;
            foreach ($permissions as $permission) {
                if ($permission->disable()) {
                    $revokedCount++;
                }
            }

            Log::info('All user permissions revoked', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'permissions_revoked' => $revokedCount,
                'revoked_at' => now(),
            ]);

            return $revokedCount;
        });
    }

    /**
     * Update the manager for a specific permission.
     *
     * @param UserFeaturePermission $permission
     * @param int|null $newManagerId
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updatePermissionManager(UserFeaturePermission $permission, ?int $newManagerId): bool
    {
        if ($newManagerId !== null) {
            $this->validateUserExists($newManagerId);
            
            // Cannot set user as manager of their own permission
            if ($newManagerId === $permission->user_id) {
                throw new InvalidArgumentException("User cannot be the manager of their own permission.");
            }
        }

        $oldManagerId = $permission->manager_user_id;
        $permission->manager_user_id = $newManagerId;

        if ($permission->save()) {
            Log::info('Permission manager updated', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'old_manager_id' => $oldManagerId,
                'new_manager_id' => $newManagerId,
                'updated_at' => now(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get permissions statistics for a client.
     *
     * @param int $clientId
     * @return array<string, mixed>
     */
    public function getClientPermissionStats(int $clientId): array
    {
        $totalPermissions = UserFeaturePermission::forClient($clientId)->count();
        $activePermissions = UserFeaturePermission::active()->forClient($clientId)->count();
        $disabledPermissions = $totalPermissions - $activePermissions;

        $userCount = UserFeaturePermission::active()
            ->forClient($clientId)
            ->distinct('user_id')
            ->count('user_id');

        $featureBreakdown = [];
        foreach (self::$validFeatureIds as $featureId) {
            $count = UserFeaturePermission::active()
                ->forClient($clientId)
                ->forFeature($featureId)
                ->count();
            
            $featureBreakdown[$featureId] = $count;
        }

        return [
            'client_id' => $clientId,
            'total_permissions' => $totalPermissions,
            'active_permissions' => $activePermissions,
            'disabled_permissions' => $disabledPermissions,
            'users_with_permissions' => $userCount,
            'feature_breakdown' => $featureBreakdown,
            'generated_at' => now(),
        ];
    }

    /**
     * Validate inputs for granting permission.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @param int $grantorId
     * @param int|null $managerId
     * @throws InvalidArgumentException
     */
    protected function validateGrantPermissionInputs(
        int $userId,
        int $clientId,
        int $featureId,
        int $grantorId,
        ?int $managerId = null
    ): void {
        // Validate feature ID
        if (!in_array($featureId, self::$validFeatureIds)) {
            throw new InvalidArgumentException("Invalid feature ID: {$featureId}");
        }

        // Validate users exist
        $this->validateUserExists($userId);
        $this->validateUserExists($grantorId);
        
        if ($managerId !== null) {
            $this->validateUserExists($managerId);
            
            // Manager cannot be the same as the user receiving the permission
            if ($managerId === $userId) {
                throw new InvalidArgumentException("Manager cannot be the same as the user receiving the permission.");
            }
        }

        // Validate client exists
        $this->validateClientExists($clientId);

        // Grantor cannot grant permission to themselves
        if ($grantorId === $userId) {
            throw new InvalidArgumentException("User cannot grant permission to themselves.");
        }
    }

    /**
     * Validate that a user exists.
     *
     * @param int $userId
     * @throws InvalidArgumentException
     */
    protected function validateUserExists(int $userId): void
    {
        if (!User::where('id', $userId)->exists()) {
            throw new InvalidArgumentException("User with ID {$userId} does not exist.");
        }
    }

    /**
     * Validate that a client exists.
     *
     * @param int $clientId
     * @throws InvalidArgumentException
     */
    protected function validateClientExists(int $clientId): void
    {
        if (!Client::where('id', $clientId)->exists()) {
            throw new InvalidArgumentException("Client with ID {$clientId} does not exist.");
        }
    }

    /**
     * Find existing permission for user, client, and feature combination.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return UserFeaturePermission|null
     */
    protected function findExistingPermission(int $userId, int $clientId, int $featureId): ?UserFeaturePermission
    {
        return UserFeaturePermission::forClient($clientId)
            ->forUser($userId)
            ->forFeature($featureId)
            ->first();
    }

    /**
     * Re-enable an existing disabled permission.
     *
     * @param UserFeaturePermission $permission
     * @param int $grantorId
     * @param int|null $managerId
     * @return UserFeaturePermission
     */
    protected function reEnablePermission(
        UserFeaturePermission $permission,
        int $grantorId,
        ?int $managerId = null
    ): UserFeaturePermission {
        return DB::transaction(function () use ($permission, $grantorId, $managerId) {
            // Update the permission details
            $permission->grantor_id = $grantorId;
            $permission->manager_user_id = $managerId;
            $permission->is_enabled = true;
            $permission->save();

            Log::info('User permission re-enabled', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
                're_enabled_at' => now(),
            ]);

            return $permission;
        });
    }

    /**
     * Get available feature IDs.
     *
     * @return array<int>
     */
    public static function getValidFeatureIds(): array
    {
        return self::$validFeatureIds;
    }

    /**
     * Get feature name by ID.
     *
     * @param int $featureId
     * @return string
     */
    public static function getFeatureName(int $featureId): string
    {
        return match ($featureId) {
            self::FEATURE_POCKET_EXPENSE_CREATE => 'Create Expenses',
            self::FEATURE_POCKET_EXPENSE_VIEW => 'View Expenses',
            self::FEATURE_POCKET_EXPENSE_EDIT => 'Edit Expenses',
            self::FEATURE_POCKET_EXPENSE_DELETE => 'Delete Expenses',
            self::FEATURE_POCKET_EXPENSE_APPROVE => 'Approve Expenses',
            self::FEATURE_POCKET_EXPENSE_UPLOAD => 'Upload CSV Files',
            self::FEATURE_POCKET_EXPENSE_MANAGE_USERS => 'Manage Users',
            default => 'Unknown Feature',
        };
    }

    /**
     * Check if a feature ID is valid.
     *
     * @param int $featureId
     * @return bool
     */
    public static function isValidFeatureId(int $featureId): bool
    {
        return in_array($featureId, self::$validFeatureIds);
    }
}