## Code: app/Policies/UserPermissionPolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class UserPermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Feature ID for user permission management.
     */
    const USER_PERMISSION_FEATURE_ID = 2;

    /**
     * Determine whether the user can view any permissions.
     */
    public function viewAny(User $user): bool
    {
        // Users can view permissions if they have permission management feature
        return $this->hasUserPermissionFeature($user);
    }

    /**
     * Determine whether the user can view the permission.
     */
    public function view(User $user, UserFeaturePermission $permission): bool
    {
        // Users can view permissions they granted
        if ($permission->wasGrantedBy($user->id)) {
            return $this->hasUserPermissionFeature($user);
        }

        // Users can view permissions for users they manage
        if ($permission->isManagedBy($user->id)) {
            return $this->hasUserPermissionFeature($user);
        }

        // Users can view their own permissions
        if ($permission->belongsToUser($user->id)) {
            return $this->hasUserPermissionFeature($user);
        }

        // Check if user has management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $permission->client_id);
    }

    /**
     * Determine whether the user can create permissions.
     */
    public function create(User $user): bool
    {
        return $this->hasUserPermissionFeature($user);
    }

    /**
     * Determine whether the user can grant permission for a specific user and client.
     */
    public function grant(User $user, int $targetUserId, int $clientId, int $featureId): bool
    {
        // Users cannot grant permissions to themselves
        if ($targetUserId === $user->id) {
            return false;
        }

        // Check if user has permission management feature for this client
        if (!$this->hasUserPermissionFeatureForClient($user, $clientId)) {
            return false;
        }

        // Check if user has management permissions for this client
        if (!$this->hasManagementPermissionsForClient($user, $clientId)) {
            return false;
        }

        // Check if user can manage the target user within this client
        return $this->canManageUserInClient($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can update the permission.
     */
    public function update(User $user, UserFeaturePermission $permission): bool
    {
        // Users can update permissions they granted
        if ($permission->wasGrantedBy($user->id)) {
            return $this->hasUserPermissionFeatureForClient($user, $permission->client_id);
        }

        // Check if user has management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $permission->client_id);
    }

    /**
     * Determine whether the user can delete/revoke the permission.
     */
    public function delete(User $user, UserFeaturePermission $permission): bool
    {
        return $this->revoke($user, $permission);
    }

    /**
     * Determine whether the user can revoke the permission.
     */
    public function revoke(User $user, UserFeaturePermission $permission): bool
    {
        // Users can revoke permissions they granted
        if ($permission->wasGrantedBy($user->id)) {
            return $this->hasUserPermissionFeatureForClient($user, $permission->client_id);
        }

        // Check if user has management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $permission->client_id);
    }

    /**
     * Determine whether the user can enable the permission.
     */
    public function enable(User $user, UserFeaturePermission $permission): bool
    {
        return $this->update($user, $permission);
    }

    /**
     * Determine whether the user can disable the permission.
     */
    public function disable(User $user, UserFeaturePermission $permission): bool
    {
        return $this->update($user, $permission);
    }

    /**
     * Determine whether the user can assign manager to the permission.
     */
    public function assignManager(User $user, UserFeaturePermission $permission, int $managerId): bool
    {
        // Cannot assign themselves as manager
        if ($managerId === $user->id) {
            return false;
        }

        // Users can assign managers for permissions they granted
        if ($permission->wasGrantedBy($user->id)) {
            return $this->hasUserPermissionFeatureForClient($user, $permission->client_id);
        }

        // Check if user has management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $permission->client_id);
    }

    /**
     * Determine whether the user can remove manager from the permission.
     */
    public function removeManager(User $user, UserFeaturePermission $permission): bool
    {
        // Users can remove managers from permissions they granted
        if ($permission->wasGrantedBy($user->id)) {
            return $this->hasUserPermissionFeatureForClient($user, $permission->client_id);
        }

        // Check if user has management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $permission->client_id);
    }

    /**
     * Determine whether the user can view permissions for a specific client.
     */
    public function viewClientPermissions(User $user, int $clientId): bool
    {
        return $this->hasUserPermissionFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can view permissions for a specific user within a client.
     */
    public function viewUserPermissions(User $user, int $targetUserId, int $clientId): bool
    {
        // Users can always view their own permissions
        if ($targetUserId === $user->id) {
            return $this->hasUserPermissionFeatureForClient($user, $clientId);
        }

        // Check if user can manage the target user within this client
        return $this->canManageUserInClient($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can bulk grant permissions.
     */
    public function bulkGrant(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can bulk revoke permissions.
     */
    public function bulkRevoke(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can bulk enable permissions.
     */
    public function bulkEnable(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can bulk disable permissions.
     */
    public function bulkDisable(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can export permission data.
     */
    public function exportPermissions(User $user, int $clientId): bool
    {
        return $this->hasUserPermissionFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can view permission analytics.
     */
    public function viewAnalytics(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId) || 
               $this->hasUserPermissionFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can view permission audit trail.
     */
    public function viewAuditTrail(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can manage permission hierarchy.
     */
    public function manageHierarchy(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can delegate permissions.
     */
    public function delegatePermissions(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can configure permission settings.
     */
    public function configureSettings(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Determine whether the user can view permission statistics.
     */
    public function viewStats(User $user, int $clientId): bool
    {
        return $this->hasUserPermissionFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can access advanced permission features.
     */
    public function accessAdvancedFeatures(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user has the user permission management feature enabled.
     */
    protected function hasUserPermissionFeature(User $user): bool
    {
        return UserFeaturePermission::forUser($user->id)
            ->forFeature(self::USER_PERMISSION_FEATURE_ID)
            ->enabled()
            ->exists();
    }

    /**
     * Check if user has the user permission management feature for a specific client.
     */
    protected function hasUserPermissionFeatureForClient(User $user, int $clientId): bool
    {
        return UserFeaturePermission::hasPermission($user->id, $clientId, self::USER_PERMISSION_FEATURE_ID);
    }

    /**
     * Check if user has management permissions for a client.
     */
    protected function hasManagementPermissionsForClient(User $user, int $clientId): bool
    {
        // First, check if user has the permission management feature for this client
        if (!$this->hasUserPermissionFeatureForClient($user, $clientId)) {
            return false;
        }

        // Check if user is a manager for any user in this client for the permission feature
        $hasManagementRole = UserFeaturePermission::forClient($clientId)
            ->forFeature(self::USER_PERMISSION_FEATURE_ID)
            ->managedBy($user->id)
            ->enabled()
            ->exists();

        if ($hasManagementRole) {
            return true;
        }

        // Check if user has granted permissions to others (indicating management capability)
        $hasGrantedPermissions = UserFeaturePermission::forClient($clientId)
            ->grantedBy($user->id)
            ->exists();

        return $hasGrantedPermissions;
    }

    /**
     * Check if user can manage a specific user within a client.
     */
    protected function canManageUserInClient(User $user, int $targetUserId, int $clientId): bool
    {
        // Users cannot manage themselves through this method
        if ($targetUserId === $user->id) {
            return false;
        }

        // First, check if user has permission management feature for this client
        if (!$this->hasUserPermissionFeatureForClient($user, $clientId)) {
            return false;
        }

        // Check if user is assigned as manager for any of the target user's permissions
        $isManagerForUser = UserFeaturePermission::forUserAndClient($targetUserId, $clientId)
            ->managedBy($user->id)
            ->exists();

        if ($isManagerForUser) {
            return true;
        }

        // Check if user has general management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can grant a specific feature permission.
     */
    protected function canGrantFeaturePermission(User $user, int $clientId, int $featureId): bool
    {
        // Check if user has permission management feature for this client
        if (!$this->hasUserPermissionFeatureForClient($user, $clientId)) {
            return false;
        }

        // Check if user has management permissions for this client
        if (!$this->hasManagementPermissionsForClient($user, $clientId)) {
            return false;
        }

        // Additional feature-specific restrictions could be implemented here
        // For now, if user has management permissions, they can grant any feature
        return true;
    }

    /**
     * Check if user can modify permission for a specific feature.
     */
    protected function canModifyFeaturePermission(User $user, UserFeaturePermission $permission): bool
    {
        // Check if user has permission management feature for the permission's client
        if (!$this->hasUserPermissionFeatureForClient($user, $permission->client_id)) {
            return false;
        }

        // Users can modify permissions they granted
        if ($permission->wasGrantedBy($user->id)) {
            return true;
        }

        // Check if user has management permissions for this client
        return $this->hasManagementPermissionsForClient($user, $permission->client_id);
    }

    /**
     * Check if user can view permission history.
     */
    public function viewPermissionHistory(User $user, UserFeaturePermission $permission): bool
    {
        // Users can view history if they can view the permission
        return $this->view($user, $permission);
    }

    /**
     * Check if user can transfer permission ownership.
     */
    public function transferOwnership(User $user, UserFeaturePermission $permission, int $newGrantorId): bool
    {
        // Only the original grantor can transfer ownership
        if (!$permission->wasGrantedBy($user->id)) {
            return false;
        }

        // Cannot transfer to themselves
        if ($newGrantorId === $user->id) {
            return false;
        }

        // Check if user has permission management feature for this client
        return $this->hasUserPermissionFeatureForClient($user, $permission->client_id);
    }

    /**
     * Check if user can create permission templates.
     */
    public function createTemplates(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can apply permission templates.
     */
    public function applyTemplates(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can manage permission templates.
     */
    public function manageTemplates(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can schedule permission changes.
     */
    public function scheduleChanges(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can approve permission requests.
     */
    public function approveRequests(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can reject permission requests.
     */
    public function rejectRequests(User $user, int $clientId): bool
    {
        return $this->hasManagementPermissionsForClient($user, $clientId);
    }

    /**
     * Check if user can view permission requests.
     */
    public function view