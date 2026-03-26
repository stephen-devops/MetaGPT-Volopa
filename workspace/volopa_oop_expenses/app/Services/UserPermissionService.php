<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * User Permission Service
 * 
 * Manages user feature permissions with RBAC and delegation capabilities.
 * Handles granting, revoking, and checking permissions within client contexts.
 * Enforces permission constraints and manages user-manager relationships.
 */
class UserPermissionService
{
    /**
     * The OOP Expense feature ID constant.
     * Feature ID 16 represents the OOP Expense feature.
     */
    const OOP_EXPENSE_FEATURE_ID = 16;

    /**
     * Grant permission to a user for a specific feature within a client context.
     *
     * @param int $userId The user to grant permission to
     * @param int $clientId The client context
     * @param int $featureId The feature ID to grant permission for
     * @param int $grantorId The user granting the permission
     * @param int $managerId The manager user who will manage this permission
     * @return UserFeaturePermission
     * @throws \Exception
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId): UserFeaturePermission
    {
        return DB::transaction(function () use ($userId, $clientId, $featureId, $grantorId, $managerId) {
            // Check if permission already exists
            $existingPermission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();

            if ($existingPermission) {
                if ($existingPermission->is_enabled) {
                    throw new \Exception('Permission already granted for this user and feature');
                }
                
                // Re-enable existing permission
                $existingPermission->is_enabled = true;
                $existingPermission->grantor_id = $grantorId;
                $existingPermission->manager_user_id = $managerId;
                $existingPermission->save();
                
                return $existingPermission;
            }

            // Validate that grantor can grant permissions (should have appropriate role/permission)
            $this->validateGrantorPermissions($grantorId, $clientId, $featureId);

            // Validate that manager belongs to the same client
            $this->validateManagerBelongsToClient($managerId, $clientId);

            // Validate that user belongs to the client
            $this->validateUserBelongsToClient($userId, $clientId);

            // Create new permission
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => true,
            ]);

            return $permission;
        });
    }

    /**
     * Revoke permission for a user within a client context for a specific feature.
     *
     * @param int $userId The user to revoke permission from
     * @param int $clientId The client context
     * @param int $featureId The feature ID to revoke permission for
     * @return bool
     * @throws \Exception
     */
    public function revokePermission(int $userId, int $clientId, int $featureId): bool
    {
        return DB::transaction(function () use ($userId, $clientId, $featureId) {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();

            if (!$permission) {
                throw new \Exception('Permission not found');
            }

            if (!$permission->is_enabled) {
                throw new \Exception('Permission is already revoked');
            }

            // Disable the permission (soft revoke)
            $permission->is_enabled = false;
            return $permission->save();
        });
    }

    /**
     * Check if a user has permission for a specific feature within a client context.
     *
     * @param int $userId The user to check permission for
     * @param int $clientId The client context
     * @param int $featureId The feature ID to check permission for
     * @return bool
     */
    public function checkPermission(int $userId, int $clientId, int $featureId): bool
    {
        $permission = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->first();

        return $permission !== null;
    }

    /**
     * Get all users managed by a specific manager within a client context.
     *
     * @param int $managerId The manager user ID
     * @param int $clientId The client context
     * @return Collection
     */
    public function getUserManagedBy(int $managerId, int $clientId): Collection
    {
        return UserFeaturePermission::where('manager_user_id', $managerId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->with(['user', 'client'])
            ->get()
            ->pluck('user')
            ->unique('id');
    }

    /**
     * Get all permissions granted by a specific grantor within a client context.
     *
     * @param int $grantorId The grantor user ID
     * @param int $clientId The client context
     * @return Collection
     */
    public function getPermissionsGrantedBy(int $grantorId, int $clientId): Collection
    {
        return UserFeaturePermission::where('grantor_id', $grantorId)
            ->where('client_id', $clientId)
            ->with(['user', 'client', 'grantor', 'manager'])
            ->get();
    }

    /**
     * Get all active permissions for a specific user within a client context.
     *
     * @param int $userId The user ID
     * @param int $clientId The client context
     * @return Collection
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        return UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->with(['client', 'grantor', 'manager'])
            ->get();
    }

    /**
     * Check if a user has OOP Expense feature permission within a client context.
     *
     * @param int $userId The user ID
     * @param int $clientId The client context
     * @return bool
     */
    public function hasOOPExpensePermission(int $userId, int $clientId): bool
    {
        return $this->checkPermission($userId, $clientId, self::OOP_EXPENSE_FEATURE_ID);
    }

    /**
     * Grant OOP Expense permission to a user.
     *
     * @param int $userId The user to grant permission to
     * @param int $clientId The client context
     * @param int $grantorId The user granting the permission
     * @param int $managerId The manager user
     * @return UserFeaturePermission
     */
    public function grantOOPExpensePermission(int $userId, int $clientId, int $grantorId, int $managerId): UserFeaturePermission
    {
        return $this->grantPermission($userId, $clientId, self::OOP_EXPENSE_FEATURE_ID, $grantorId, $managerId);
    }

    /**
     * Revoke OOP Expense permission for a user.
     *
     * @param int $userId The user to revoke permission from
     * @param int $clientId The client context
     * @return bool
     */
    public function revokeOOPExpensePermission(int $userId, int $clientId): bool
    {
        return $this->revokePermission($userId, $clientId, self::OOP_EXPENSE_FEATURE_ID);
    }

    /**
     * Validate that a grantor has the appropriate permissions to grant access.
     * 
     * @param int $grantorId The grantor user ID
     * @param int $clientId The client context
     * @param int $featureId The feature ID
     * @throws \Exception
     */
    private function validateGrantorPermissions(int $grantorId, int $clientId, int $featureId): void
    {
        // TODO: Implement grantor permission validation
        // This should check if the grantor has the appropriate role (Primary Admin or Admin)
        // and has the authority to grant permissions within the client context.
        // For now, we assume the calling code has already validated the grantor's permissions.
        
        // Validate that grantor exists and belongs to client
        $grantor = User::find($grantorId);
        if (!$grantor) {
            throw new \Exception('Grantor user not found');
        }

        // TODO: Add role-based validation
        // - Primary Admin has full access to all users by default
        // - Admin can only grant access to their own managed users
        // - Business User and Card User cannot approve expenses even with management rights
    }

    /**
     * Validate that a manager belongs to the specified client.
     *
     * @param int $managerId The manager user ID
     * @param int $clientId The client context
     * @throws \Exception
     */
    private function validateManagerBelongsToClient(int $managerId, int $clientId): void
    {
        $manager = User::find($managerId);
        if (!$manager) {
            throw new \Exception('Manager user not found');
        }

        // TODO: Implement client membership validation
        // This should verify that the manager user belongs to the specified client
        // The exact implementation depends on how user-client relationships are stored
        // in the platform (could be through a pivot table, user.client_id, etc.)
    }

    /**
     * Validate that a user belongs to the specified client.
     *
     * @param int $userId The user ID
     * @param int $clientId The client context
     * @throws \Exception
     */
    private function validateUserBelongsToClient(int $userId, int $clientId): void
    {
        $user = User::find($userId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $client = Client::find($clientId);
        if (!$client) {
            throw new \Exception('Client not found');
        }

        // TODO: Implement client membership validation
        // This should verify that the user belongs to the specified client
        // The exact implementation depends on how user-client relationships are stored
        // in the platform (could be through a pivot table, user.client_id, etc.)
    }

    /**
     * Get count of active permissions for a client and feature.
     *
     * @param int $clientId The client context
     * @param int $featureId The feature ID
     * @return int
     */
    public function getPermissionCount(int $clientId, int $featureId): int
    {
        return UserFeaturePermission::where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->count();
    }

    /**
     * Check if a user can manage another user within a client context.
     *
     * @param int $managerId The potential manager user ID
     * @param int $userId The user to be managed
     * @param int $clientId The client context
     * @return bool
     */
    public function canManageUser(int $managerId, int $userId, int $clientId): bool
    {
        $permission = UserFeaturePermission::where('user_id', $userId)
            ->where('manager_user_id', $managerId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->first();

        return $permission !== null;
    }

    /**
     * Transfer management of permissions from one manager to another.
     *
     * @param int $oldManagerId The current manager user ID
     * @param int $newManagerId The new manager user ID
     * @param int $clientId The client context
     * @return int Number of permissions transferred
     */
    public function transferManagement(int $oldManagerId, int $newManagerId, int $clientId): int
    {
        return DB::transaction(function () use ($oldManagerId, $newManagerId, $clientId) {
            // Validate that new manager belongs to client
            $this->validateManagerBelongsToClient($newManagerId, $clientId);

            $transferCount = UserFeaturePermission::where('manager_user_id', $oldManagerId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->update([
                    'manager_user_id' => $newManagerId,
                    'updated_at' => now(),
                ]);

            return $transferCount;
        });
    }
}