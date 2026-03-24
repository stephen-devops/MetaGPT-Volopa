<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization policy for UserFeaturePermission operations
 * 
 * Handles authorization logic for user feature permission management operations
 * following platform role hierarchy and management delegation patterns.
 * 
 * Permission Rules:
 * - Primary Admin has full access to all users by default
 * - Admin gets full access only to own permissions by default; needs explicit grant for others
 * - Business User and Card User cannot manage permissions
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to their own managed users (not all users)
 * - Only Primary Admin or Volopa Admin can revoke management rights
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * User role constants based on platform role hierarchy
     */
    const ROLE_PRIMARY_ADMIN = 'Primary Admin';
    const ROLE_ADMIN = 'Admin';
    const ROLE_BUSINESS_USER = 'Business User';
    const ROLE_CARD_USER = 'Card User';
    const ROLE_VOLOPA_ADMIN = 'Volopa Admin';

    /**
     * OOP Expense feature ID constant
     */
    const OOP_EXPENSE_FEATURE_ID = 16;

    /**
     * Determine whether the user can view any user feature permissions.
     * 
     * Primary Admin can view all permissions. Admin can view permissions
     * for users they manage or their own permissions. Other roles cannot
     * view permissions unless they have explicit management rights.
     *
     * @param User $user The authenticated user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Primary Admin has full access to all users by default
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin gets access to own permissions and managed users
        if ($this->isAdmin($user)) {
            return true;
        }

        // Check if user has explicit management rights for any users
        if ($this->hasManagementRights($user)) {
            return true;
        }

        // Business User and Card User cannot view permissions by default
        return false;
    }

    /**
     * Determine whether the user can view the specific user feature permission.
     * 
     * User can view permission if they can manage the target user or if
     * the permission belongs to themselves.
     *
     * @param User $user The authenticated user
     * @param UserFeaturePermission $permission The permission to view
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Admin has full access to all permissions
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User can view their own permissions
        if ($permission->user_id === $user->id && $permission->client_id === $this->getUserClientId($user)) {
            return true;
        }

        // Check if user can manage the target user of this permission
        if ($this->canManageTargetUser($user, $permission->user_id, $permission->client_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create user feature permissions.
     * 
     * Primary Admin can grant permissions to any user. Admin can grant
     * permissions only to users they manage. Other roles cannot grant
     * permissions unless they have explicit management rights.
     *
     * @param User $user The authenticated user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Primary Admin has full access to grant permissions
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can grant permissions to their managed users
        if ($this->isAdmin($user)) {
            return true;
        }

        // Check if user has explicit management rights
        if ($this->hasManagementRights($user)) {
            return true;
        }

        // Business User and Card User cannot grant permissions
        return false;
    }

    /**
     * Determine whether the user can update the specific user feature permission.
     * 
     * User can update permission if they can manage the target user.
     * Updates include enabling/disabling permissions and changing manager assignments.
     *
     * @param User $user The authenticated user
     * @param UserFeaturePermission $permission The permission to update
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Admin has full access to update all permissions
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User cannot update their own permissions (prevents self-privilege escalation)
        if ($permission->user_id === $user->id) {
            return false;
        }

        // Check if user can manage the target user of this permission
        if ($this->canManageTargetUser($user, $permission->user_id, $permission->client_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete (revoke) the user feature permission.
     * 
     * Only Primary Admin or Volopa Admin can revoke management rights.
     * Regular Admin can revoke permissions for users they manage but cannot
     * revoke management rights themselves.
     *
     * @param User $user The authenticated user
     * @param UserFeaturePermission $permission The permission to delete
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Admin and Volopa Admin can revoke any permission including management rights
        if ($this->isPrimaryAdmin($user) || $this->isVolopaAdmin($user)) {
            return true;
        }

        // User cannot revoke their own permissions (prevents self-lockout)
        if ($permission->user_id === $user->id) {
            return false;
        }

        // Admin can revoke permissions for users they manage (but not management rights)
        if ($this->isAdmin($user)) {
            // Check if this is a management right permission - only Primary Admin can revoke these
            if ($permission->manager_user_id !== null) {
                return false;
            }

            // Check if user can manage the target user of this permission
            if ($this->canManageTargetUser($user, $permission->user_id, $permission->client_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can grant permission to a specific target user.
     * 
     * This method is called during permission creation to verify the user
     * can grant permission to the specified target user within the client context.
     *
     * @param User $user The authenticated user attempting to grant permission
     * @param int $targetUserId The user ID receiving the permission
     * @param int $clientId The client context
     * @return bool
     */
    public function canGrantToUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Primary Admin can grant to any user
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User cannot grant permissions to themselves (prevents self-privilege escalation)
        if ($targetUserId === $user->id) {
            return false;
        }

        // Check if user can manage the target user
        if ($this->canManageTargetUser($user, $targetUserId, $clientId)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can assign a specific manager to a permission.
     * 
     * This method is called during permission creation/update to verify the user
     * can assign management rights to the specified manager user.
     *
     * @param User $user The authenticated user attempting to assign manager
     * @param int|null $managerUserId The manager user ID being assigned (null if no manager)
     * @param int $clientId The client context
     * @return bool
     */
    public function canAssignManager(User $user, ?int $managerUserId, int $clientId): bool
    {
        // No manager assignment is always allowed
        if ($managerUserId === null) {
            return true;
        }

        // Primary Admin can assign any manager
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User cannot assign themselves as manager (prevents circular management)
        if ($managerUserId === $user->id) {
            return false;
        }

        // Admin can assign managers from their own managed users
        if ($this->isAdmin($user)) {
            return $this->canManageTargetUser($user, $managerUserId, $clientId);
        }

        return false;
    }

    /**
     * Check if the user is a Primary Admin.
     *
     * @param User $user
     * @return bool
     */
    protected function isPrimaryAdmin(User $user): bool
    {
        // This would typically check user's role from a role relationship or attribute
        // For now, assuming a role attribute exists on the User model
        return $user->role === self::ROLE_PRIMARY_ADMIN;
    }

    /**
     * Check if the user is an Admin.
     *
     * @param User $user
     * @return bool
     */
    protected function isAdmin(User $user): bool
    {
        return $user->role === self::ROLE_ADMIN;
    }

    /**
     * Check if the user is a Business User.
     *
     * @param User $user
     * @return bool
     */
    protected function isBusinessUser(User $user): bool
    {
        return $user->role === self::ROLE_BUSINESS_USER;
    }

    /**
     * Check if the user is a Card User.
     *
     * @param User $user
     * @return bool
     */
    protected function isCardUser(User $user): bool
    {
        return $user->role === self::ROLE_CARD_USER;
    }

    /**
     * Check if the user is a Volopa Admin.
     *
     * @param User $user
     * @return bool
     */
    protected function isVolopaAdmin(User $user): bool
    {
        return $user->role === self::ROLE_VOLOPA_ADMIN;
    }

    /**
     * Check if the user has explicit management rights for any users.
     * 
     * This method checks if the user has been granted management permissions
     * through the UserFeaturePermission system.
     *
     * @param User $user
     * @return bool
     */
    protected function hasManagementRights(User $user): bool
    {
        $clientId = $this->getUserClientId($user);
        
        return UserFeaturePermission::where('manager_user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('feature_id', self::OOP_EXPENSE_FEATURE_ID)
            ->where('is_enabled', 1)
            ->exists();
    }

    /**
     * Check if the user can manage a specific target user.
     * 
     * This method determines if the authenticated user has management rights
     * over the specified target user within the client context.
     *
     * @param User $user The authenticated user
     * @param int $targetUserId The user ID to check management rights for
     * @param int $clientId The client context
     * @return bool
     */
    protected function canManageTargetUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Primary Admin can manage any user
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if the user has explicit management rights for the target user
        return UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('feature_id', self::OOP_EXPENSE_FEATURE_ID)
            ->where('manager_user_id', $user->id)
            ->where('is_enabled', 1)
            ->exists();
    }

    /**
     * Get the client ID for the authenticated user.
     * 
     * This method retrieves the client context for the user. In a multi-tenant
     * system, this would typically come from the user's relationship or session context.
     *
     * @param User $user
     * @return int
     */
    protected function getUserClientId(User $user): int
    {
        // This implementation assumes the client ID is available via a relationship or attribute
        // In practice, this might come from the user's client relationship, session context, 
        // or be passed through the request context
        return $user->client_id ?? 1; // Default fallback - should be properly implemented
    }

    /**
     * Determine if the user belongs to the same client as the permission context.
     * 
     * This method ensures multi-tenant security by verifying the user operates
     * within the same client context as the permission being accessed.
     *
     * @param User $user
     * @param int $permissionClientId
     * @return bool
     */
    protected function isSameClient(User $user, int $permissionClientId): bool
    {
        $userClientId = $this->getUserClientId($user);
        return $userClientId === $permissionClientId;
    }

    /**
     * Check if the user has specific feature access.
     * 
     * This method verifies if the user has been granted access to a specific feature
     * (like OOP Expense feature) within their client context.
     *
     * @param User $user
     * @param int $featureId
     * @return bool
     */
    protected function hasFeatureAccess(User $user, int $featureId): bool
    {
        $clientId = $this->getUserClientId($user);
        
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', 1)
            ->exists();
    }

    /**
     * Before hook for all policy methods.
     * 
     * This method runs before any specific policy method and can be used
     * for early authorization checks or to bypass normal authorization
     * for super users.
     *
     * @param User $user
     * @param string $ability
     * @return bool|null
     */
    public function before(User $user, string $ability): ?bool
    {
        // Volopa Admin has unrestricted access to all permission operations
        if ($this->isVolopaAdmin($user)) {
            return true;
        }

        // For all other users, continue with normal policy checks
        return null;
    }
}