## Code: app/Policies/UserFeaturePermissionPolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;

class UserFeaturePermissionPolicy
{
    /**
     * Determine whether the user can view any user feature permissions.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Only authenticated users can view permissions
        if (!$user) {
            return false;
        }

        // Primary Admin has full access to all permissions
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can view permissions for their managed users and their own
        if ($user->isAdmin()) {
            return true;
        }

        // Business Users and Card Users cannot view permissions
        return false;
    }

    /**
     * Determine whether the user can view the specific user feature permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $permission): bool
    {
        // Only authenticated users can view permissions
        if (!$user) {
            return false;
        }

        // Primary Admin has full access to all permissions
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can view permissions for their managed users and their own
        if ($user->isAdmin()) {
            // Can view their own permissions
            if ($permission->user_id === $user->id) {
                return true;
            }

            // Can view permissions for users they manage
            if ($permission->manager_user_id === $user->id) {
                return true;
            }

            // Can view permissions they granted
            if ($permission->grantor_id === $user->id) {
                return true;
            }

            return false;
        }

        // Business Users and Card Users can only view their own permissions
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $permission->user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create user feature permissions.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Only authenticated users can create permissions
        if (!$user) {
            return false;
        }

        // Primary Admin can grant permissions to anyone
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can grant permissions but only to their managed users
        if ($user->isAdmin()) {
            return true;
        }

        // Business Users and Card Users cannot grant permissions
        return false;
    }

    /**
     * Determine whether the user can update the user feature permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $permission): bool
    {
        // Only authenticated users can update permissions
        if (!$user) {
            return false;
        }

        // Primary Admin has full access to update any permission
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can update permissions for their managed users
        if ($user->isAdmin()) {
            // Can update permissions they granted
            if ($permission->grantor_id === $user->id) {
                return true;
            }

            // Can update permissions for users they manage
            if ($permission->manager_user_id === $user->id) {
                return true;
            }

            return false;
        }

        // Business Users and Card Users cannot update permissions
        return false;
    }

    /**
     * Determine whether the user can delete the user feature permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $permission): bool
    {
        // Only authenticated users can delete permissions
        if (!$user) {
            return false;
        }

        // Primary Admin has full access to delete any permission
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can delete permissions for their managed users
        if ($user->isAdmin()) {
            // Can delete permissions they granted
            if ($permission->grantor_id === $user->id) {
                return true;
            }

            // Can delete permissions for users they manage
            if ($permission->manager_user_id === $user->id) {
                return true;
            }

            return false;
        }

        // Business Users and Card Users cannot delete permissions
        return false;
    }

    /**
     * Determine whether the user can grant permissions to a specific target user.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function canGrantToUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Only authenticated users can grant permissions
        if (!$user) {
            return false;
        }

        // Primary Admin can grant to anyone within the same client
        if ($user->isPrimaryAdmin()) {
            return $this->userBelongsToClient($targetUserId, $clientId);
        }

        // Admin can only grant to users they manage
        if ($user->isAdmin()) {
            return $this->canUserManageTarget($user->id, $targetUserId, $clientId);
        }

        // Business Users and Card Users cannot grant permissions
        return false;
    }

    /**
     * Determine whether the user can assign a specific manager to a permission.
     *
     * @param User $user
     * @param int $managerUserId
     * @param int $clientId
     * @return bool
     */
    public function canAssignManager(User $user, int $managerUserId, int $clientId): bool
    {
        // Only authenticated users can assign managers
        if (!$user) {
            return false;
        }

        // Primary Admin can assign any manager within the same client
        if ($user->isPrimaryAdmin()) {
            return $this->userBelongsToClient($managerUserId, $clientId);
        }

        // Admin can only assign themselves or their managed users as managers
        if ($user->isAdmin()) {
            // Can assign themselves
            if ($managerUserId === $user->id) {
                return true;
            }

            // Can assign users they manage
            return $this->canUserManageTarget($user->id, $managerUserId, $clientId);
        }

        return false;
    }

    /**
     * Determine whether the user can manage permissions for a specific client.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    public function canManageClientPermissions(User $user, int $clientId): bool
    {
        // Only authenticated users can manage client permissions
        if (!$user) {
            return false;
        }

        // Primary Admin has full access to all clients
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can manage permissions for their client
        if ($user->isAdmin()) {
            return $user->client_id === $clientId;
        }

        return false;
    }

    /**
     * Check if a user belongs to a specific client.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    private function userBelongsToClient(int $userId, int $clientId): bool
    {
        $targetUser = User::find($userId);
        
        if (!$targetUser) {
            return false;
        }

        return $targetUser->client_id === $clientId;
    }

    /**
     * Check if a manager user can manage a target user within a client.
     *
     * @param int $managerUserId
     * @param int $targetUserId
     * @param int $clientId
     * @return