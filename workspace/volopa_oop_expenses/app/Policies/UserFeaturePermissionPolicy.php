<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * UserFeaturePermissionPolicy
 * 
 * Authorization policy for user feature permission operations.
 * Implements role-based access control with delegation support.
 * 
 * Key constraints:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own permissions by default; needs explicit grant for others
 * - Admin can only grant access to their own managed users (not all users)
 * - Managing access can be given to any user irrespective of role
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any permissions.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Implement role checking when User role system is confirmed
        // For now, allow authenticated users to view permissions
        // This should be restricted based on user role (Primary Admin, Admin, etc.)
        return true;
    }

    /**
     * Determine whether the user can view the permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $permission): bool
    {
        // User can view their own permissions
        if ($user->id === $permission->user_id) {
            return true;
        }

        // User can view permissions they granted
        if ($user->id === $permission->grantor_id) {
            return true;
        }

        // User can view permissions for users they manage
        if ($user->id === $permission->manager_user_id) {
            return true;
        }

        // TODO: Add Primary Admin check when role system is confirmed
        // Primary Admin should have full access to all permissions
        
        return false;
    }

    /**
     * Determine whether the user can create permissions.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // TODO: Implement role checking when User role system is confirmed
        // Only Primary Admin and Admin roles should be able to create permissions
        // Admin can only grant access to their own managed users (not all users)
        return true;
    }

    /**
     * Determine whether the user can update the permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $permission): bool
    {
        // User can update permissions they granted
        if ($user->id === $permission->grantor_id) {
            return true;
        }

        // TODO: Add Primary Admin check when role system is confirmed
        // Primary Admin should have full access to update any permission
        
        // TODO: Add manager delegation check
        // Users with management rights should be able to update permissions
        // for their managed users
        
        return false;
    }

    /**
     * Determine whether the user can delete the permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $permission): bool
    {
        // User can delete permissions they granted
        if ($user->id === $permission->grantor_id) {
            return true;
        }

        // TODO: Add Primary Admin check when role system is confirmed
        // Primary Admin should have full access to delete any permission
        
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
    public function grantToUser(User $user, int $targetUserId, int $clientId): bool
    {
        // TODO: Implement role and management relationship checking
        // Admin can only grant access to their own managed users (not all users)
        // Primary Admin has full access to all users by default
        
        // For now, allow if user is not granting to themselves
        return $user->id !== $targetUserId;
    }

    /**
     * Determine whether the user can manage permissions for a specific client.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    public function manageForClient(User $user, int $clientId): bool
    {
        // TODO: Implement client access checking
        // User should only be able to manage permissions for clients they have access to
        // This should check if user belongs to the client or has cross-client access
        
        return true;
    }

    /**
     * Determine whether the user can enable/disable a permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function toggle(User $user, UserFeaturePermission $permission): bool
    {
        // Same rules as update - user can toggle permissions they granted
        return $this->update($user, $permission);
    }

    /**
     * Determine whether the user can transfer permission ownership.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function transfer(User $user, UserFeaturePermission $permission): bool
    {
        // Only the grantor can transfer ownership
        if ($user->id === $permission->grantor_id) {
            return true;
        }

        // TODO: Add Primary Admin check when role system is confirmed
        // Primary Admin should be able to transfer any permission
        
        return false;
    }

    /**
     * Determine whether the user can set a manager for a permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function setManager(User $user, UserFeaturePermission $permission): bool
    {
        // User can set manager for permissions they granted
        if ($user->id === $permission->grantor_id) {
            return true;
        }

        // TODO: Add Primary Admin check when role system is confirmed
        // Primary Admin should be able to set manager for any permission
        
        return false;
    }
}