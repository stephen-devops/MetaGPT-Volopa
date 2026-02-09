## Code: app/Services/UserPermissionService.php

```php
<?php

namespace App\Services;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UserPermissionService
{
    /**
     * Feature ID for user permission management.
     */
    const USER_PERMISSION_FEATURE_ID = 2;

    /**
     * Grant permission to a user for a specific feature and client.
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId = null): UserFeaturePermission
    {
        return DB::transaction(function () use ($userId, $clientId, $featureId, $grantorId, $managerId) {
            try {
                // Check if permission already exists
                $existingPermission = UserFeaturePermission::findForUserClientAndFeature($userId, $clientId, $featureId);
                
                if ($existingPermission) {
                    throw new \InvalidArgumentException('Permission already exists for this user, client, and feature combination');
                }

                // Validate that the grantor has permission management rights
                if (!$this->canGrantPermission($grantorId, $clientId, $featureId)) {
                    throw new \InvalidArgumentException('Grantor does not have permission management rights for this client and feature');
                }

                // Validate that the manager (if provided) has permission management rights
                if ($managerId && !$this->canManagePermission($managerId, $clientId, $featureId)) {
                    throw new \InvalidArgumentException('Manager does not have permission management rights for this client and feature');
                }

                // Create the permission
                $permission = UserFeaturePermission::create([
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'manager_user_id' => $managerId,
                    'is_enabled' => true,
                ]);

                Log::info('Permission granted successfully', [
                    'permission_id' => $permission->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'manager_id' => $managerId,
                ]);

                return $permission;

            } catch (\Exception $e) {
                Log::error('Error granting permission', [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'manager_id' => $managerId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Revoke permission for a user.
     */
    public function revokePermission(UserFeaturePermission $permission): bool
    {
        return DB::transaction(function () use ($permission) {
            try {
                $permissionId = $permission->id;
                $userId = $permission->user_id;
                $clientId = $permission->client_id;
                $featureId = $permission->feature_id;

                $success = $permission->delete();

                if ($success) {
                    Log::info('Permission revoked successfully', [
                        'permission_id' => $permissionId,
                        'user_id' => $userId,
                        'client_id' => $clientId,
                        'feature_id' => $featureId,
                    ]);
                }

                return $success;

            } catch (\Exception $e) {
                Log::error('Error revoking permission', [
                    'permission_id' => $permission->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Get all permissions for a user.
     */
    public function getUserPermissions(User $user): Collection
    {
        try {
            return UserFeaturePermission::forUser($user->id)
                ->with(['client', 'feature', 'grantor', 'manager'])
                ->enabled()
                ->latest()
                ->get();

        } catch (\Exception $e) {
            Log::error('Error getting user permissions', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if a user has a specific permission.
     */
    public function hasPermission(User $user, string $featureName, int $clientId): bool
    {
        try {
            // First, try to find the feature by name
            $feature = Feature::where('name', $featureName)->first();
            
            if (!$feature) {
                Log::warning('Feature not found', [
                    'feature_name' => $featureName,
                    'user_id' => $user->id,
                    'client_id' => $clientId,
                ]);
                return false;
            }

            return UserFeaturePermission::hasPermission($user->id, $clientId, $feature->id);

        } catch (\Exception $e) {
            Log::error('Error checking permission', [
                'user_id' => $user->id,
                'feature_name' => $featureName,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Enable a permission.
     */
    public function enablePermission(UserFeaturePermission $permission): bool
    {
        try {
            if ($permission->isEnabled()) {
                return true; // Already enabled
            }

            $success = $permission->enable();

            if ($success) {
                Log::info('Permission enabled successfully', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Error enabling permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Disable a permission.
     */
    public function disablePermission(UserFeaturePermission $permission): bool
    {
        try {
            if ($permission->isDisabled()) {
                return true; // Already disabled
            }

            $success = $permission->disable();

            if ($success) {
                Log::info('Permission disabled successfully', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Error disabling permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Toggle permission enabled status.
     */
    public function togglePermission(UserFeaturePermission $permission): bool
    {
        try {
            $wasEnabled = $permission->isEnabled();
            $success = $permission->toggle();

            if ($success) {
                $action = $wasEnabled ? 'disabled' : 'enabled';
                Log::info("Permission {$action} successfully", [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'previous_status' => $wasEnabled ? 'enabled' : 'disabled',
                    'new_status' => $wasEnabled ? 'disabled' : 'enabled',
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Error toggling permission', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Set manager for a permission.
     */
    public function setPermissionManager(UserFeaturePermission $permission, int $managerId): bool
    {
        try {
            // Validate that the manager has permission management rights
            if (!$this->canManagePermission($managerId, $permission->client_id, $permission->feature_id)) {
                throw new \InvalidArgumentException('Manager does not have permission management rights for this client and feature');
            }

            $success = $permission->setManager($managerId);

            if ($success) {
                Log::info('Permission manager set successfully', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'manager_id' => $managerId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Error setting permission manager', [
                'permission_id' => $permission->id,
                'manager_id' => $managerId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Remove manager from a permission.
     */
    public function removePermissionManager(UserFeaturePermission $permission): bool
    {
        try {
            $previousManagerId = $permission->manager_user_id;
            $success = $permission->removeManager();

            if ($success) {
                Log::info('Permission manager removed successfully', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'previous_manager_id' => $previousManagerId,
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            Log::error('Error removing permission manager', [
                'permission_id' => $permission->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get permissions for a specific client.
     */
    public function getClientPermissions(int $clientId, array $filters = []): Collection
    {
        try {
            $query = UserFeaturePermission::forClient($clientId)
                ->with(['user', 'feature', 'grantor', 'manager']);

            // Apply filters
            if (isset($filters['feature_id'])) {
                $query->forFeature($filters['feature_id']);
            }

            if (isset($filters['user_id'])) {
                $query->forUser($filters['user_id']);
            }

            if (isset($filters['enabled'])) {
                if ($filters['enabled']) {
                    $query->enabled();
                } else {
                    $query->disabled();
                }
            }

            if (isset($filters['grantor_id'])) {
                $query->grantedBy($filters['grantor_id']);
            }

            if (isset($filters['manager_id'])) {
                $query->managedBy($filters['manager_id']);
            }

            return $query->latest()->get();

        } catch (\Exception $e) {
            Log::error('Error getting client permissions', [
                'client_id' => $clientId,
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get permissions that a user has granted to others.
     */
    public function getGrantedPermissions(User $grantor, int $clientId = null): Collection
    {
        try {
            $query = UserFeaturePermission::grantedBy($grantor->id)
                ->with(['user', 'client', 'feature', 'manager']);

            if ($clientId) {
                $query->forClient($clientId);
            }

            return $query->latest()->get();

        } catch (\Exception $e) {
            Log::error('Error getting granted permissions', [
                'grantor_id' => $grantor->id,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get permissions that a user manages.
     */
    public function getManagedPermissions(User $manager, int $clientId = null): Collection
    {
        try {
            $query = UserFeaturePermission::managedBy($manager->id)
                ->with(['user', 'client', 'feature', 'grantor']);

            if ($clientId) {
                $query->forClient($clientId);
            }

            return $query->latest()->get();

        } catch (\Exception $e) {
            Log::error('Error getting managed permissions', [
                'manager_id' => $manager->id,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Bulk grant permissions to multiple users.
     */
    public function bulkGrantPermissions(array $userIds, int $clientId, int $featureId, int $grantorId, int $managerId = null): array
    {
        return DB::transaction(function () use ($userIds, $clientId, $featureId, $grantorId, $managerId) {
            $results = [
                'granted' => [],
                'failed' => [],
                'skipped' => [],
            ];

            try {
                foreach ($userIds as $userId) {
                    try {
                        // Check if permission already exists
                        $existing = UserFeaturePermission::findForUserClientAndFeature($userId, $clientId, $featureId);
                        
                        if ($existing) {
                            $results['skipped'][] = [
                                'user_id' => $userId,
                                'reason' => 'Permission already exists',
                            ];
                            continue;
                        }

                        $permission = $this->grantPermission($userId, $clientId, $featureId, $grantorId, $managerId);
                        $results['granted'][] = $permission->id;

                    } catch (\Exception $e) {
                        $results['failed'][] = [
                            'user_id' => $userId,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                Log::info('Bulk grant permissions completed', [
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'manager_id' => $managerId,
                    'granted_count' => count($results['granted']),
                    'failed_count' => count($results['failed']),
                    'skipped_count' => count($results['skipped']),
                ]);

                return $results;

            } catch (\Exception $e) {
                Log::error('Error in bulk grant permissions', [
                    'user_ids' => $userIds,
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    