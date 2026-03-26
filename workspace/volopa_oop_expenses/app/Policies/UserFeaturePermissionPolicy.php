<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * User Feature Permission Policy
 * 
 * Authorization policy for user permission management operations.
 * Implements RBAC constraints and delegation rules as per system design.
 * 
 * Key authorization rules:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own expenses by default; needs explicit grant for others
 * - Business User and Card User cannot approve expenses even with management rights
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to their own managed users (not all users)
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any user feature permissions.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Check if user has Primary Admin role or explicit management permissions
        // This requires integration with User model role system and client context
        return true; // Placeholder - implement role-based authorization
    }

    /**
     * Determine whether the user can view the user feature permission.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // User can view if they are:
        // 1. The grantor of the permission
        // 2. The manager of the permission
        // 3. The user who has the permission
        // 4. Primary Admin with full access
        return $user->id === $userFeaturePermission->grantor_id
            || $user->id === $userFeaturePermission->manager_user_id
            || $user->id === $userFeaturePermission->user_id
            || $this->isPrimaryAdmin($user); // TODO: Implement role check
    }

    /**
     * Determine whether the user can create user feature permissions.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // User can create permissions if they have management rights
        // TODO: Check if user has admin role or explicit management permissions
        return $this->hasManagementRights($user);
    }

    /**
     * Determine whether the user can update the user feature permission.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // User can update if they are:
        // 1. The grantor of the permission
        // 2. Primary Admin with full access
        return $user->id === $userFeaturePermission->grantor_id
            || $this->isPrimaryAdmin($user);
    }

    /**
     * Determine whether the user can delete the user feature permission.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // User can delete (revoke) if they are:
        // 1. The grantor of the permission
        // 2. Primary Admin with full access
        return $user->id === $userFeaturePermission->grantor_id
            || $this->isPrimaryAdmin($user);
    }

    /**
     * Determine whether the user can restore the user feature permission.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function restore(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Same as delete policy
        return $this->delete($user, $userFeaturePermission);
    }

    /**
     * Determine whether the user can permanently delete the user feature permission.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function forceDelete(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Only Primary Admin can force delete
        return $this->isPrimaryAdmin($user);
    }

    /**
     * Determine whether the user can grant permissions to a specific target user.
     *
     * @param \App\Models\User $grantor
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function grantToUser(User $grantor, int $targetUserId, int $clientId): bool
    {
        // Admin can only grant access to their own managed users (not all users)
        if ($this->isPrimaryAdmin($grantor)) {
            return true; // Primary Admin has full access
        }

        // TODO: Check if grantor manages the target user within the client context
        // This requires integration with user management system to verify
        // if grantor has management rights over targetUserId in clientId
        return $this->canManageUser($grantor, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can manage permissions within a client.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    public function manageInClient(User $user, int $clientId): bool
    {
        // TODO: Check if user has management permissions within the specific client
        // This requires checking UserFeaturePermission records where:
        // - manager_user_id = $user->id
        // - client_id = $clientId
        // - is_enabled = true
        return UserFeaturePermission::where('manager_user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->exists()
            || $this->isPrimaryAdmin($user);
    }

    /**
     * Check if user has Primary Admin role.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        // TODO: Implement Primary Admin role check
        // This requires integration with User model role system
        // return $user->hasRole('Primary Admin');
        return false; // Placeholder
    }

    /**
     * Check if user has management rights.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function hasManagementRights(User $user): bool
    {
        // TODO: Check if user has Admin role or explicit management permissions
        // Managing access can be given to any user irrespective of role
        // return $user->hasRole(['Primary Admin', 'Admin']) || $user->hasManagementPermissions();
        return true; // Placeholder - allow for testing
    }

    /**
     * Check if grantor can manage target user within client context.
     *
     * @param \App\Models\User $grantor
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    private function canManageUser(User $grantor, int $targetUserId, int $clientId): bool
    {
        // TODO: Implement user management hierarchy check
        // Admin can only grant access to their own managed users
        // This requires checking if grantor has management relationship with targetUserId
        // within the specific client context
        return UserFeaturePermission::where('manager_user_id', $grantor->id)
            ->where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->exists();
    }
}