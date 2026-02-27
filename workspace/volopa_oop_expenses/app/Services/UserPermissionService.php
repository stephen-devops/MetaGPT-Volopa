## Code: app/Services/UserPermissionService.php

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeaturePermission;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Exception;

class UserPermissionService
{
    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * Default source names that are auto-created on feature enable.
     *
     * @var array<string>
     */
    private const DEFAULT_SOURCE_NAMES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
    ];

    /**
     * Grant permission to a user for a specific feature within a client.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @param int $grantorId
     * @param int $managerId
     * @return UserFeaturePermission
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function grantPermission(
        int $userId, 
        int $clientId, 
        int $featureId, 
        int $grantorId, 
        int $managerId
    ): UserFeaturePermission {
        // Validate input parameters
        if ($userId <= 0 || $clientId <= 0 || $featureId <= 0 || $grantorId <= 0 || $managerId <= 0) {
            throw new InvalidArgumentException('All IDs must be positive integers');
        }

        // Validate that grantor and manager users exist and belong to the correct client
        $this->validateUserExists($userId, $clientId);
        $this->validateUserExists($grantorId, $clientId);
        $this->validateUserExists($managerId, $clientId);

        // Check if permission already exists
        $existingPermission = UserFeaturePermission::where([
            'user_id' => $userId,
            'client_id' => $clientId,
            'feature_id' => $featureId,
        ])->first();

        if ($existingPermission) {
            throw new InvalidArgumentException('Permission already exists for this user, client, and feature combination');
        }

        try {
            DB::beginTransaction();

            // Create the permission record
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => true,
            ]);

            // If this is the OOP Expenses feature, seed default expense sources
            if ($featureId === self::OOP_EXPENSES_FEATURE_ID) {
                $this->seedDefaultExpenseSources($clientId);
            }

            DB::commit();

            return $permission->fresh();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to grant permission: ' . $e->getMessage());
        }
    }

    /**
     * Revoke permission for a user, client, and feature combination.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     */
    public function revokePermission(int $userId, int $clientId, int $featureId): bool
    {
        // Validate input parameters
        if ($userId <= 0 || $clientId <= 0 || $featureId <= 0) {
            throw new InvalidArgumentException('All IDs must be positive integers');
        }

        $permission = UserFeaturePermission::where([
            'user_id' => $userId,
            'client_id' => $clientId,
            'feature_id' => $featureId,
        ])->first();

        if (!$permission) {
            throw new ModelNotFoundException('Permission not found for the specified user, client, and feature');
        }

        try {
            return $permission->delete();
        } catch (Exception $e) {
            throw new Exception('Failed to revoke permission: ' . $e->getMessage());
        }
    }

    /**
     * Check if a manager user can manage a target user within a client.
     *
     * @param int $managerId
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function checkUserCanManage(int $managerId, int $targetUserId, int $clientId): bool
    {
        // Validate input parameters
        if ($managerId <= 0 || $targetUserId <= 0 || $clientId <= 0) {
            return false;
        }

        // Users can always manage themselves
        if ($managerId === $targetUserId) {
            return true;
        }

        try {
            // Get manager user
            $manager = User::where('id', $managerId)
                          ->where('client_id', $clientId)
                          ->first();

            if (!$manager) {
                return false;
            }

            // Get target user
            $targetUser = User::where('id', $targetUserId)
                             ->where('client_id', $clientId)
                             ->first();

            if (!$targetUser) {
                return false;
            }

            // Primary Admin has full access to all users
            if ($manager->isPrimaryAdmin()) {
                return true;
            }

            // Admin can manage users they have been granted managing access to
            if ($manager->isAdmin()) {
                // Check if there's a permission record where this admin is the manager
                $managingPermission = UserFeaturePermission::where([
                    'user_id' => $targetUserId,
                    'client_id' => $clientId,
                    'manager_user_id' => $managerId,
                    'is_enabled' => true,
                ])->exists();

                return $managingPermission;
            }

            // Business Users and Card Users cannot manage other users
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get all users that a specific manager can manage within a client.
     *
     * @param int $managerId
     * @param int $clientId
     * @return Collection
     */
    public function getUserManagedUsers(int $managerId, int $clientId): Collection
    {
        // Validate input parameters
        if ($managerId <= 0 || $clientId <= 0) {
            return collect();
        }

        try {
            // Get manager user
            $manager = User::where('id', $managerId)
                          ->where('client_id', $clientId)
                          ->first();

            if (!$manager) {
                return collect();
            }

            // Primary Admin has access to all users in the client
            if ($manager->isPrimaryAdmin()) {
                return User::where('client_id', $clientId)
                          ->where('id', '!=', $managerId)
                          ->get();
            }

            // Admin gets users they manage through permissions
            if ($manager->isAdmin()) {
                $managedUserIds = UserFeaturePermission::where([
                    'manager_user_id' => $managerId,
                    'client_id' => $clientId,
                    'is_enabled' => true,
                ])->pluck('user_id')->unique();

                return User::whereIn('id', $managedUserIds)
                          ->where('client_id', $clientId)
                          ->get();
            }

            // Business Users and Card Users cannot manage other users
            return collect();
        } catch (Exception $e) {
            return collect();
        }
    }

    /**
     * Get default permissions for a client (used for setup/initialization).
     *
     * @param int $clientId
     * @return Collection
     */
    public function getDefaultPerm