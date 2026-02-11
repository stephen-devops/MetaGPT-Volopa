<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class UserPermissionService
{
    /**
     * Grant a feature permission to a user.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @param int $grantorId
     * @param int $managerId
     * @return UserFeaturePermission
     * @throws Exception
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId): UserFeaturePermission
    {
        try {
            return DB::transaction(function () use ($userId, $clientId, $featureId, $grantorId, $managerId) {
                // Check if permission already exists (including soft deleted)
                $existingPermission = UserFeaturePermission::withTrashed()
                    ->where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->where('feature_id', $featureId)
                    ->first();

                if ($existingPermission) {
                    if ($existingPermission->trashed()) {
                        // Restore the soft-deleted permission and update it
                        $existingPermission->restore();
                        $existingPermission->update([
                            'grantor_id' => $grantorId,
                            'manager_user_id' => $managerId,
                            'is_enabled' => true,
                        ]);
                        
                        Log::info('User permission restored', [
                            'user_id' => $userId,
                            'client_id' => $clientId,
                            'feature_id' => $featureId,
                            'grantor_id' => $grantorId,
                            'manager_id' => $managerId
                        ]);
                        
                        return $existingPermission->fresh();
                    } else {
                        // Update existing active permission
                        $existingPermission->update([
                            'grantor_id' => $grantorId,
                            'manager_user_id' => $managerId,
                            'is_enabled' => true,
                        ]);
                        
                        Log::info('User permission updated', [
                            'user_id' => $userId,
                            'client_id' => $clientId,
                            'feature_id' => $featureId,
                            'grantor_id' => $grantorId,
                            'manager_id' => $managerId
                        ]);
                        
                        return $existingPermission->fresh();
                    }
                }

                // Validate that all referenced entities exist
                $this->validateEntitiesExist($userId, $clientId, $featureId, $grantorId, $managerId);

                // Create new permission
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
                    'manager_id' => $managerId
                ]);

                return $permission;
            });
        } catch (Exception $e) {
            Log::error('Failed to grant user permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to grant user permission: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Revoke a feature permission from a user.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     * @throws Exception
     */
    public function revokePermission(int $userId, int $clientId, int $featureId): bool
    {
        try {
            return DB::transaction(function () use ($userId, $clientId, $featureId) {
                $permission = UserFeaturePermission::where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->where('feature_id', $featureId)
                    ->first();

                if (!$permission) {
                    Log::warning('Attempted to revoke non-existent permission', [
                        'user_id' => $userId,
                        'client_id' => $clientId,
                        'feature_id' => $featureId
                    ]);
                    return false;
                }

                // Soft delete the permission
                $result = $permission->delete();

                if ($result) {
                    Log::info('User permission revoked', [
                        'permission_id' => $permission->id,
                        'user_id' => $userId,
                        'client_id' => $clientId,
                        'feature_id' => $featureId
                    ]);
                }

                return $result;
            });
        } catch (Exception $e) {
            Log::error('Failed to revoke user permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to revoke user permission: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all permissions for a user within a client.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        try {
            $permissions = UserFeaturePermission::with(['feature', 'grantor', 'manager'])
                ->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->get();

            Log::debug('Retrieved user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'count' => $permissions->count()
            ]);

            return $permissions;
        } catch (Exception $e) {
            Log::error('Failed to retrieve user permissions', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return collect();
        }
    }

    /**
     * Check if a manager can manage a target user.
     *
     * @param int $managerId
     * @param int $targetUserId
     * @return bool
     */
    public function canManageUser(int $managerId, int $targetUserId): bool
    {
        // Manager cannot manage themselves
        if ($managerId === $targetUserId) {
            return false;
        }

        try {
            $manager = User::find($managerId);
            $targetUser = User::find($targetUserId);

            if (!$manager || !$targetUser) {
                Log::warning('Invalid user IDs provided for management check', [
                    'manager_id' => $managerId,
                    'target_user_id' => $targetUserId,
                    'manager_exists' => $manager ? true : false,
                    'target_user_exists' => $targetUser ? true : false
                ]);
                return false;
            }

            // Super admin can manage anyone
            if ($this->hasRole($manager, 'super_admin')) {
                return true;
            }

            // Admin can manage non-admin users
            if ($this->hasRole($manager, 'admin')) {
                return !$this->hasRole($targetUser, 'admin') && !$this->hasRole($targetUser, 'super_admin');
            }

            // Manager can manage regular users
            if ($this->hasRole($manager, 'manager')) {
                return $this->hasRole($targetUser, 'user');
            }

            // Check if manager is explicitly assigned to manage the target user
            $hasExplicitManagement = UserFeaturePermission::where('user_id', $targetUserId)
                ->where('manager_user_id', $managerId)
                ->where('is_enabled', true)
                ->exists();

            if ($hasExplicitManagement) {
                Log::debug('Manager has explicit permission to manage user', [
                    'manager_id' => $managerId,
                    'target_user_id' => $targetUserId
                ]);
            }

            return $hasExplicitManagement;

        } catch (Exception $e) {
            Log::error('Failed to check user management permission', [
                'manager_id' => $managerId,
                'target_user_id' => $targetUserId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Get all users that a manager can manage within a client.
     *
     * @param int $managerId
     * @param int $clientId
     * @return Collection
     */
    public function getManagedUsers(int $managerId, int $clientId): Collection
    {
        try {
            $manager = User::find($managerId);
            if (!$manager) {
                return collect();
            }

            // Super admin can manage all users in the client
            if ($this->hasRole($manager, 'super_admin')) {
                return $this->getUsersForClient($clientId);
            }

            // Get users explicitly managed by this manager
            $explicitlyManagedUserIds = UserFeaturePermission::where('manager_user_id', $managerId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->distinct()
                ->pluck('user_id');

            // Get users based on role hierarchy
            $roleBasedUsers = collect();
            if ($this->hasRole($manager, 'admin')) {
                $roleBasedUsers = $this->getUsersForClient($clientId)
                    ->reject(function ($user) {
                        return $this->hasRole($user, 'admin') || $this->hasRole($user, 'super_admin');
                    });
            } elseif ($this->hasRole($manager, 'manager')) {
                $roleBasedUsers = $this->getUsersForClient($clientId)
                    ->filter(function ($user) {
                        return $this->hasRole($user, 'user');
                    });
            }

            // Combine explicitly managed users with role-based users
            $allManagedUserIds = $explicitlyManagedUserIds->merge($roleBasedUsers->pluck('id'))->unique();

            $managedUsers = User::whereIn('id', $allManagedUserIds)
                ->whereNull('deleted_at')
                ->get();

            Log::debug('Retrieved managed users', [
                'manager_id' => $managerId,
                'client_id' => $clientId,
                'count' => $managedUsers->count()
            ]);

            return $managedUsers;

        } catch (Exception $e) {
            Log::error('Failed to retrieve managed users', [
                'manager_id' => $managerId,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return collect();
        }
    }

    /**
     * Enable a user's feature permission.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function enablePermission(int $userId, int $clientId, int $featureId): bool
    {
        try {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();

            if (!$permission) {
                Log::warning('Attempted to enable non-existent permission', [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId
                ]);
                return false;
            }

            $result = $permission->update(['is_enabled' => true]);

            if ($result) {
                Log::info('User permission enabled', [
                    'permission_id' => $permission->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to enable user permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Disable a user's feature permission.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function disablePermission(int $userId, int $clientId, int $featureId): bool
    {
        try {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();

            if (!$permission) {
                Log::warning('Attempted to disable non-existent permission', [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId
                ]);
                return false;
            }

            $result = $permission->update(['is_enabled' => false]);

            if ($result) {
                Log::info('User permission disabled', [
                    'permission_id' => $permission->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId
                ]);
            }

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to disable user permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Bulk grant permissions to multiple users.
     *
     * @param array $userIds
     * @param int $clientId
     * @param array $featureIds
     * @param int $grantorId
     * @param int $managerId
     * @return array
     */
    public function bulkGrantPermissions(array $userIds, int $clientId, array $featureIds, int $grantorId, int $managerId): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total_processed' => 0,
            'total_successful' => 0,
            'total_failed' => 0
        ];

        try {
            return DB::transaction(function () use ($userIds, $clientId, $featureIds, $grantorId, $managerId, &$results) {
                foreach ($userIds as $userId) {
                    foreach ($featureIds as $featureId) {
                        $results['total_processed']++;
                        
                        try {
                            $permission = $this->grantPermission($userId, $clientId, $featureId, $grantorId, $managerId);
                            
                            $results['successful'][] = [
                                'user_id' => $userId,
                                'feature_id' => $featureId,
                                'permission_id' => $permission->id
                            ];
                            $results['total_successful']++;
                            
                        } catch (Exception $e) {
                            $results['failed'][] = [
                                'user_id' => $userId,
                                'feature_id' => $featureId,
                                'error' => $e->getMessage()
                            ];
                            $results['total_failed']++;
                        }
                    }
                }

                Log::info('Bulk grant permissions completed', [
                    'client_id' => $clientId,
                    'grantor_id' => $grantorId,
                    'manager_id' => $managerId,
                    'total_processed' => $results['total_processed'],
                    'total_successful' => $results['total_successful'],
                    'total_failed' => $results['total_failed']
                ]);

                return $results;
            });

        } catch (Exception $e) {
            Log::error('Bulk grant permissions failed', [
                'client_id' => $clientId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Bulk grant permissions failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bulk revoke permissions from multiple users.
     *
     * @param array $userIds
     * @param int $clientId
     * @param array $featureIds
     * @return array
     */
    public function bulkRevokePermissions(array $userIds, int $clientId, array $featureIds): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total_processed' => 0,
            'total_successful' => 0,
            'total_failed' => 0
        ];

        try {
            return DB::transaction(function () use ($userIds, $clientId, $featureIds, &$results) {
                foreach ($userIds as $userId) {
                    foreach ($featureIds as $featureId) {
                        $results['total_processed']++;
                        
                        try {
                            $revoked = $this->revokePermission($userId, $clientId, $featureId);
                            
                            if ($revoked) {
                                $results['successful'][] = [
                                    'user_id' => $userId,
                                    'feature_id' => $featureId
                                ];
                                $results['total_successful']++;
                            } else {
                                $results['failed'][] = [
                                    'user_id' => $userId,
                                    'feature_id' => $featureId,
                                    'error' => 'Permission not found or already revoked'
                                ];
                                $results['total_failed']++;
                            }
                            
                        } catch (Exception $e) {
                            $results['failed'][] = [
                                'user_id' => $userId,
                                'feature_id' => $featureId,
                                'error' => $e->getMessage()
                            ];
                            $results['total_failed']++;
                        }
                    }
                }

                Log::info('Bulk revoke permissions completed', [
                    'client_id' => $clientId,
                    'total_processed' => $results['total_processed'],
                    'total_successful' => $results['total_successful'],
                    'total_failed' => $results['total_failed']
                ]);

                return $results;
            });

        } catch (Exception $e) {
            Log::error('Bulk revoke permissions failed', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Bulk revoke permissions failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get permissions summary for a client.
     *
     * @param int $clientId
     * @return array
     */
    public function getClientPermissionsSummary(int $clientId): array
    {
        try {
            $totalPermissions = UserFeaturePermission::where('client_id', $clientId)->count();
            $enabledPermissions = UserFeaturePermission::where('client_id', $clientId)
                ->where('is_enabled', true)
                ->count();
            $disabledPermissions = UserFeaturePermission::where('client_id', $clientId)
                ->where('is_enabled', false)
                ->count();

            $uniqueUsers = UserFeaturePermission::where('client_id', $clientId)
                ->where('is_enabled', true)
                ->distinct()
                ->count('user_id');

            $uniqueFeatures = UserFeaturePermission::where('client_id', $clientId)
                ->where('is_enabled', true)
                ->distinct()
                ->count('feature_id');

            $summary = [
                'client_id' => $clientId,
                'total_permissions' => $totalPermissions,
                'enabled_permissions' => $enabledPermissions,
                'disabled_permissions' => $disabledPermissions,
                'unique_users_with_permissions' => $uniqueUsers,
                'unique_features_granted' => $uniqueFeatures,
                'permissions_by_feature' => $this->getPermissionsByFeature($clientId),
                'permissions_by_grantor' => $this->getPermissionsByGrantor($clientId),
            ];

            Log::debug('Generated client permissions summary', [
                'client_id' => $clientId,
                'total_permissions' => $totalPermissions,
                'enabled_permissions' => $enabledPermissions
            ]);

            return $summary;

        } catch (Exception $e) {
            Log::error('Failed to generate client permissions summary', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'client_id' => $clientId,
                'error' => 'Failed to generate summary: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate that all referenced entities exist.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @param int $grantorId
     * @param int $managerId
     * @throws ModelNotFoundException
     */
    private function validateEntitiesExist(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId): void
    {
        // Validate user exists
        if (!User::where('id', $userId)->whereNull('deleted_at')->exists()) {
            throw new ModelNotFoundException("User with ID {$userId} not found");
        }

        // Validate client exists and is active
        if (!Client::where('id', $clientId)->where('is_active', true)->exists()) {
            throw new ModelNotFoundException("Active client with ID {$clientId} not found");
        }

        // Validate feature exists and is active
        if (!Feature::where('id', $featureId)->where('is_active', true)->exists()) {
            throw new ModelNotFoundException("Active feature with ID {$featureId} not found");
        }

        // Validate grantor exists
        if (!User::where('id', $grantorId)->whereNull('deleted_at')->exists()) {
            throw new ModelNotFoundException("Grantor user with ID {$grantorId} not found");
        }

        // Validate manager exists
        if (!User::where('id', $managerId)->whereNull('deleted_at')->exists()) {
            throw new ModelNotFoundException("Manager user with ID {$managerId} not found");
        }
    }

    /**
     * Check if user has a specific role.
     *
     * @param User $user
     * @param string $role
     * @return bool
     */
    private function hasRole(User $user, string $role): bool
    {
        return method_exists($user, 'hasRole') ? $user->hasRole($role) : false;
    }

    /**
     * Get users for a specific client.
     *
     * @param int $clientId
     * @return Collection
     */
    private function getUsersForClient(int $clientId): Collection
    {
        try {
            // This would depend on your user-client relationship structure
            // Assuming either direct relationship or pivot table
            
            // Option 1: Direct client_id on users table
            $directUsers = User::where('client_id', $clientId)
                ->whereNull('deleted_at')
                ->get();

            if ($directUsers->isNotEmpty()) {
                return $directUsers;
            }

            // Option 2: Many-to-many relationship through pivot table
            $client = Client::find($clientId);
            if ($client && method_exists($client, 'users')) {
                return $client->users()->whereNull('users.deleted_at')->get();
            }

            return collect();

        } catch (Exception $e) {
            Log::error('Failed to get users for client', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return collect();
        }
    }

    /**
     * Get permissions grouped by feature for a client.
     *
     * @param int $clientId
     * @return array
     */
    private function getPermissionsByFeature(int $clientId): array
    {
        try {
            return UserFeaturePermission::with('feature')
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->get()
                ->groupBy('feature.name')
                ->map(function ($permissions) {
                    return $permissions->count();
                })
                ->toArray();

        } catch (Exception $e) {
            Log::error('Failed to get permissions by feature', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Get permissions grouped by grantor for a client.
     *
     * @param int $clientId
     * @return array
     */
    private function getPermissionsByGrantor(int $clientId): array
    {
        try {
            return UserFeaturePermission::with('grantor')
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->get()
                ->groupBy('grantor.name')
                ->map(function ($permissions) {
                    return $permissions->count();
                })
                ->toArray();

        } catch (Exception $e) {
            Log::error('Failed to get permissions by grantor', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}