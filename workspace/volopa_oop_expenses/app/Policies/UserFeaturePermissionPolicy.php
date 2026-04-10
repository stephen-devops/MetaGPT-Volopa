<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * UserFeaturePermissionPolicy
 * 
 * Handles authorization for user feature permission operations.
 * Implements RBAC constraints including delegation hierarchy and role-based access control.
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any user feature permissions.
     * Only Primary Admin has full access to all users by default.
     * Admin gets access only to own permissions and users they manage.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): Response|bool
    {
        // TODO: Implement user role checking when User model has role relationships
        // For now, allow authenticated users to view permissions they have access to
        return true;
    }

    /**
     * Determine whether the user can view the user feature permission.
     * Users can view permissions they own or permissions for users they manage.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // User can view their own permissions
        if ($user->id === $userFeaturePermission->user_id) {
            return true;
        }

        // User can view permissions they granted
        if ($user->id === $userFeaturePermission->grantor_id) {
            return true;
        }

        // TODO: Implement role-based checking when User model has role relationships
        // Primary Admin should have access to all permissions
        // Admin should have access only to permissions for users they manage

        return false;
    }

    /**
     * Determine whether the user can create user feature permissions.
     * Admin can only grant access to their own managed users (not all users).
     * Business User and Card User cannot grant permissions.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user): Response|bool
    {
        // TODO: Implement user role checking when User model has role relationships
        // Business User and Card User should not be able to create permissions
        // Admin should only be able to grant to users they manage
        // Primary Admin should have full access

        // For now, allow authenticated users to create permissions
        return true;
    }

    /**
     * Determine whether the user can update the user feature permission.
     * Only the grantor or Primary Admin can update permissions.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // User can update permissions they granted
        if ($user->id === $userFeaturePermission->grantor_id) {
            return true;
        }

        // TODO: Implement role-based checking when User model has role relationships
        // Primary Admin should be able to update any permission

        return false;
    }

    /**
     * Determine whether the user can delete the user feature permission.
     * Only the grantor or Primary Admin can revoke permissions.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // User can delete permissions they granted
        if ($user->id === $userFeaturePermission->grantor_id) {
            return true;
        }

        // TODO: Implement role-based checking when User model has role relationships
        // Primary Admin should be able to delete any permission

        return false;
    }

    /**
     * Determine whether the user can restore the user feature permission.
     * Only the grantor or Primary Admin can restore permissions.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function restore(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // Same logic as delete - only grantor or Primary Admin
        return $this->delete($user, $userFeaturePermission);
    }

    /**
     * Determine whether the user can permanently delete the user feature permission.
     * Only the grantor or Primary Admin can permanently delete permissions.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $userFeaturePermission
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function forceDelete(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // Same logic as delete - only grantor or Primary Admin
        return $this->delete($user, $userFeaturePermission);
    }

    /**
     * Determine whether the user can grant permission to manage another user.
     * Admin can only grant access to their own managed users (not all users).
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function grantToUser(User $user, int $targetUserId, int $clientId): Response|bool
    {
        // TODO: Implement management relationship checking
        // This method should verify that $user can manage $targetUserId within $clientId
        // Admin should only be able to grant permissions to users they manage
        // Primary Admin should have full access

        // For now, allow authenticated users to grant permissions
        return true;
    }

    /**
     * Determine whether the user can manage permissions for a specific client.
     * All operations must be scoped by client_id for multi-tenancy.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function manageForClient(User $user, int $clientId): Response|bool
    {
        // TODO: Implement client membership checking
        // User should only be able to manage permissions within their own client(s)
        
        // For now, allow authenticated users to manage permissions for any client
        return true;
    }

    /**
     * Determine whether the user can grant a specific feature permission.
     * Feature access should be validated based on client feature enablement.
     *
     * @param \App\Models\User $user
     * @param int $featureId
     * @param int $clientId
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function grantFeature(User $user, int $featureId, int $clientId): Response|bool
    {
        // TODO: Implement feature enablement checking
        // Should verify that the feature (e.g., OOP Expense feature_id = 16) 
        // is enabled for the specified client

        // For now, allow granting any feature
        return true;
    }
}