<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UserFeaturePermissionService
 * 
 * Business logic service for managing user feature permissions with delegation capabilities.
 * Supports role-based access control where Admins can grant permissions to their managed users.
 */
class UserFeaturePermissionService
{
    /**
     * Create a new user feature permission.
     *
     * @param array $data
     * @return UserFeaturePermission
     * @throws \Exception
     */
    public function createPermission(array $data): UserFeaturePermission
    {
        try {
            DB::beginTransaction();

            // Validate that the target user belongs to the specified client
            $targetUser = User::where('id', $data['target_user_id'])
                             ->where('client_id', $data['client_id'])
                             ->firstOrFail();

            // TODO: Validate that the grantor has permission to grant this feature
            // This would require checking the grantor's role and existing permissions
            // For now, assuming the authorization is handled at the policy level

            // Check for existing permission to prevent duplicates
            $existingPermission = UserFeaturePermission::where([
                'user_id' => $data['target_user_id'],
                'client_id' => $data['client_id'],
                'feature_id' => $data['feature_id'],
            ])->first();

            if ($existingPermission) {
                throw new \Exception('Permission already exists for this user and feature');
            }

            // Create the permission record
            $permission = UserFeaturePermission::create([
                'user_id' => $data['target_user_id'],
                'client_id' => $data['client_id'],
                'feature_id' => $data['feature_id'],
                'grantor_id' => $data['grantor_id'],
                'manager_user_id' => $data['manager_user_id'] ?? null,
                'is_enabled' => $data['is_enabled'] ?? true,
            ]);

            DB::commit();

            Log::info('User feature permission created', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'grantor_id' => $permission->grantor_id,
            ]);

            return $permission;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create user feature permission', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing user feature permission.
     *
     * @param UserFeaturePermission $permission
     * @param array $data
     * @return UserFeaturePermission
     * @throws \Exception
     */
    public function updatePermission(UserFeaturePermission $permission, array $data): UserFeaturePermission
    {
        try {
            DB::beginTransaction();

            // Only allow updating manager_user_id and is_enabled fields for security
            $updateData = [];
            
            if (array_key_exists('manager_user_id', $data)) {
                $updateData['manager_user_id'] = $data['manager_user_id'];
                
                // Validate manager belongs to the same client if provided
                if ($data['manager_user_id']) {
                    User::where('id', $data['manager_user_id'])
                        ->where('client_id', $permission->client_id)
                        ->firstOrFail();
                }
            }

            if (array_key_exists('is_enabled', $data)) {
                $updateData['is_enabled'] = (bool) $data['is_enabled'];
            }

            if (empty($updateData)) {
                throw new \Exception('No valid fields provided for update');
            }

            $permission->update($updateData);
            $permission->refresh();

            DB::commit();

            Log::info('User feature permission updated', [
                'permission_id' => $permission->id,
                'updated_fields' => array_keys($updateData),
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
            ]);

            return $permission;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update user feature permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Revoke a user feature permission (soft delete by disabling).
     *
     * @param UserFeaturePermission $permission
     * @return bool
     * @throws \Exception
     */
    public function revokePermission(UserFeaturePermission $permission): bool
    {
        try {
            DB::beginTransaction();

            $permission->update(['is_enabled' => false]);

            DB::commit();

            Log::info('User feature permission revoked', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to revoke user feature permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get all users that a specific user can manage within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection
     */
    public function getUserManagedUsers(int $userId, int $clientId): Collection
    {
        // Get users where the specified user is set as manager_user_id
        $managedUserIds = UserFeaturePermission::where('manager_user_id', $userId)
                                                ->where('client_id', $clientId)
                                                ->where('is_enabled', true)
                                                ->pluck('user_id')
                                                ->unique();

        if ($managedUserIds->isEmpty()) {
            return new Collection();
        }

        // TODO: Replace with actual User model query when User model structure is confirmed
        // For now, returning a placeholder collection
        return User::whereIn('id', $managedUserIds)
                   ->where('client_id', $clientId)
                   ->get();
    }

    /**
     * Check if a user can manage a target user within a client context.
     *
     * @param int $userId
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function canUserManageTarget(int $userId, int $targetUserId, int $clientId): bool
    {
        // User can always manage themselves
        if ($userId === $targetUserId) {
            return true;
        }

        // TODO: Implement role-based checks
        // Primary Admin should have access to all users by default
        // Admin should have access only to their own managed users
        // For now, checking if there's an explicit management relationship

        $managementPermission = UserFeaturePermission::where('user_id', $targetUserId)
                                                      ->where('manager_user_id', $userId)
                                                      ->where('client_id', $clientId)
                                                      ->where('is_enabled', true)
                                                      ->exists();

        return $managementPermission;
    }

    /**
     * Get permissions for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        return UserFeaturePermission::where('user_id', $userId)
                                    ->where('client_id', $clientId)
                                    ->where('is_enabled', true)
                                    ->with(['feature', 'grantor', 'manager'])
                                    ->get();
    }

    /**
     * Check if a user has a specific feature permission.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function hasFeaturePermission(int $userId, int $clientId, int $featureId): bool
    {
        return UserFeaturePermission::where('user_id', $userId)
                                    ->where('client_id', $clientId)
                                    ->where('feature_id', $featureId)
                                    ->where('is_enabled', true)
                                    ->exists();
    }

    /**
     * Get all permissions granted by a specific user.
     *
     * @param int $grantorId
     * @param int $clientId
     * @return Collection
     */
    public function getPermissionsGrantedBy(int $grantorId, int $clientId): Collection
    {
        return UserFeaturePermission::where('grantor_id', $grantorId)
                                    ->where('client_id', $clientId)
                                    ->with(['user', 'feature', 'manager'])
                                    ->orderBy('create_time', 'desc')
                                    ->get();
    }

    /**
     * Bulk revoke permissions for a user across all features in a client.
     *
     * @param int $userId
     * @param int $clientId
     * @return int Number of permissions revoked
     */
    public function revokeAllUserPermissions(int $userId, int $clientId): int
    {
        try {
            DB::beginTransaction();

            $revokedCount = UserFeaturePermission::where('user_id', $userId)
                                                 ->where('client_id', $clientId)
                                                 ->where('is_enabled', true)
                                                 ->update(['is_enabled' => false]);

            DB::commit();

            Log::info('Bulk revoked user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'revoked_count' => $revokedCount,
            ]);

            return $revokedCount;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk revoke user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Transfer management of permissions from one manager to another.
     *
     * @param int $fromManagerId
     * @param int $toManagerId
     * @param int $clientId
     * @return int Number of permissions transferred
     */
    public function transferPermissionManagement(int $fromManagerId, int $toManagerId, int $clientId): int
    {
        try {
            DB::beginTransaction();

            // Validate that both managers belong to the same client
            User::where('id', $toManagerId)
                ->where('client_id', $clientId)
                ->firstOrFail();

            $transferredCount = UserFeaturePermission::where('manager_user_id', $fromManagerId)
                                                     ->where('client_id', $clientId)
                                                     ->update(['manager_user_id' => $toManagerId]);

            DB::commit();

            Log::info('Permission management transferred', [
                'from_manager_id' => $fromManagerId,
                'to_manager_id' => $toManagerId,
                'client_id' => $clientId,
                'transferred_count' => $transferredCount,
            ]);

            return $transferredCount;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to transfer permission management', [
                'from_manager_id' => $fromManagerId,
                'to_manager_id' => $toManagerId,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}