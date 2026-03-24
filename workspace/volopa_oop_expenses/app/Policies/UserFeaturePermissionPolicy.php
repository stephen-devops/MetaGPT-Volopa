<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy for UserFeaturePermission authorization
 * 
 * Handles permission checks for user feature permission management operations.
 * Implements hierarchical RBAC based on user roles and delegation rights.
 * 
 * Key constraints:
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
     * Primary Admin can view all permissions.
     * Other users can only view permissions they granted or manage.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Primary Admin has unrestricted access
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin users can view permissions for users they manage
        if ($this->isAdmin($user)) {
            return true;
        }

        // Business User and Card User have limited view access to their own granted permissions
        if ($this->isBusinessUser($user) || $this->isCardUser($user)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the user feature permission.
     * 
     * Users can view permissions if they:
     * - Are the grantor of the permission
     * - Are the manager of the target user
     * - Are Primary Admin
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Admin can view all permissions
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User can view if they granted the permission
        if ($permission->grantor_id === $user->id) {
            return true;
        }

        // User can view if they manage the target user
        if ($permission->manager_user_id === $user->id) {
            return true;
        }

        // Admin can view permissions within their client scope
        if ($this->isAdmin($user) && $this->sharesSameClient($user, $permission)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create user feature permissions.
     * 
     * Permission granting rules:
     * - Primary Admin can grant to any user
     * - Admin can grant to users they manage within same client
     * - Business User and Card User cannot grant permissions
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Primary Admin can grant permissions to anyone
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can grant permissions within their scope
        if ($this->isAdmin($user)) {
            return true;
        }

        // Business User and Card User cannot grant permissions
        return false;
    }

    /**
     * Determine whether the user can update the user feature permission.
     * 
     * Update operations are limited - mainly for enabling/disabling permissions.
     * Same rules as create apply.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Admin can update any permission
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User can update permissions they granted
        if ($permission->grantor_id === $user->id) {
            return true;
        }

        // Admin can update permissions within their client scope if they manage the target user
        if ($this->isAdmin($user) && 
            $this->sharesSameClient($user, $permission) &&
            $this->canManageTargetUser($user, $permission->user_id, $permission->client_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the user feature permission.
     * 
     * Deletion (revocation) rules:
     * - Primary Admin can revoke any permission
     * - Admin can revoke permissions they granted to users they manage
     * - Permission grantor can revoke their own granted permissions
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Admin can revoke any permission
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // User can revoke permissions they granted
        if ($permission->grantor_id === $user->id) {
            return true;
        }

        // Admin can revoke permissions within their client scope if they manage the target user
        if ($this->isAdmin($user) && 
            $this->sharesSameClient($user, $permission) &&
            $this->canManageTargetUser($user, $permission->user_id, $permission->client_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the user feature permission.
     * 
     * Restoration follows the same rules as creation.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function restore(User $user, UserFeaturePermission $permission): bool
    {
        return $this->create($user) && $this->view($user, $permission);
    }

    /**
     * Determine whether the user can permanently delete the user feature permission.
     * 
     * Force delete is restricted to Primary Admin only.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    public function forceDelete(User $user, UserFeaturePermission $permission): bool
    {
        // Only Primary Admin can permanently delete permissions
        return $this->isPrimaryAdmin($user);
    }

    /**
     * Determine whether the user can grant permissions to a specific target user.
     * 
     * This method checks if the authenticated user can grant permissions to
     * a specific target user within a client context.
     *
     * @param User $user The authenticated user attempting to grant permission
     * @param int $targetUserId The user who would receive the permission
     * @param int $clientId The client context
     * @return bool
     */
    public function grantToUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Primary Admin can grant to any user
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can grant to users they manage within the same client
        if ($this->isAdmin($user) && $this->canManageTargetUser($user, $targetUserId, $clientId)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can manage permissions for a specific feature.
     * 
     * Feature-specific permission checks. Some features may have additional
     * restrictions beyond user role checks.
     *
     * @param User $user
     * @param int $featureId
     * @return bool
     */
    public function manageFeature(User $user, int $featureId): bool
    {
        // Primary Admin can manage all features
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // For OOP Expenses feature (ID 16), Admin level access required
        if ($featureId === 16 && $this->isAdmin($user)) {
            return true;
        }

        // Additional feature-specific logic can be added here
        // For now, default to admin-level requirement
        return $this->isAdmin($user);
    }

    /**
     * Check if user is Primary Admin.
     * 
     * Primary Admin has unrestricted access across all clients and users.
     *
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        // Implementation depends on how roles are stored
        // This could be a role field, relationship, or permission check
        return $user->role === 'Primary Admin' || 
               $user->hasRole('Primary Admin') ||
               $user->can('manage-all-users');
    }

    /**
     * Check if user is Admin.
     * 
     * Admin users have elevated privileges within their client scope.
     *
     * @param User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        return $user->role === 'Admin' || 
               $user->hasRole('Admin') ||
               $this->isPrimaryAdmin($user);
    }

    /**
     * Check if user is Business User.
     * 
     * Business User has limited administrative capabilities.
     *
     * @param User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        return $user->role === 'Business User' || 
               $user->hasRole('Business User');
    }

    /**
     * Check if user is Card User.
     * 
     * Card User has the most limited access permissions.
     *
     * @param User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        return $user->role === 'Card User' || 
               $user->hasRole('Card User');
    }

    /**
     * Check if user and permission belong to the same client.
     * 
     * Ensures client-scoped access control.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    private function sharesSameClient(User $user, UserFeaturePermission $permission): bool
    {
        // This assumes user has a client_id field or relationship
        // Implementation may vary based on user-client relationship model
        return $user->client_id === $permission->client_id ||
               $user->clients()->where('id', $permission->client_id)->exists();
    }

    /**
     * Check if user can manage a specific target user within a client.
     * 
     * Verifies hierarchical management relationships and client scope.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    private function canManageTargetUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Primary Admin can manage anyone
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if user has existing management permissions for the target user
        $existingPermission = UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('manager_user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();

        if ($existingPermission) {
            return true;
        }

        // Admin can manage users within their client if they have been granted management rights
        if ($this->isAdmin($user)) {
            // Check if admin has been granted management rights for this client
            $hasManagementRights = UserFeaturePermission::where('user_id', $user->id)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->exists();

            return $hasManagementRights;
        }

        return false;
    }

    /**
     * Check if the user has management delegation for a specific client.
     * 
     * Determines if user has been delegated management permissions
     * for users within a specific client.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    private function hasManagementDelegation(User $user, int $clientId): bool
    {
        return UserFeaturePermission::where('manager_user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Validate permission creation constraints.
     * 
     * Additional business rules for permission creation:
     * - Prevent duplicate permissions
     * - Validate feature availability for client
     * - Check permission hierarchy
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    private function validatePermissionConstraints(User $user, int $targetUserId, int $clientId, int $featureId): bool
    {
        // Check if permission already exists
        $existingPermission = UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->exists();

        if ($existingPermission) {
            return false;
        }

        // Validate that feature is enabled for the client
        // This would require checking client features configuration
        // Implementation depends on ClientFeatures model structure

        // Prevent users from granting permissions higher than their own level
        if (!$this->isPrimaryAdmin($user) && !$this->canManageFeature($user, $featureId)) {
            return false;
        }

        return true;
    }

    /**
     * Check if user can approve expenses.
     * 
     * As per constraints: Business User and Card User cannot approve expenses 
     * even with management rights.
     *
     * @param User $user
     * @return bool
     */
    private function canApproveExpenses(User $user): bool
    {
        // Primary Admin can approve
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can approve
        if ($this->isAdmin($user)) {
            return true;
        }

        // Business User and Card User cannot approve expenses
        return false;
    }

    /**
     * Get the maximum number of users a user can manage.
     * 
     * Implements limits on management scope to prevent privilege escalation.
     *
     * @param User $user
     * @return int|null Null means unlimited
     */
    private function getManagementLimit(User $user): ?int
    {
        if ($this->isPrimaryAdmin($user)) {
            return null; // Unlimited
        }

        if ($this->isAdmin($user)) {
            return 100; // Reasonable limit for admin users
        }

        return 0; // Business User and Card User cannot manage others
    }

    /**
     * Check if granting this permission would exceed management limits.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    private function wouldExceedManagementLimit(User $user, int $clientId): bool
    {
        $limit = $this->getManagementLimit($user);
        
        if ($limit === null) {
            return false; // No limit
        }

        if ($limit === 0) {
            return true; // Cannot manage anyone
        }

        $currentCount = UserFeaturePermission::where('grantor_id', $user->id)
            ->where('client_id', $clientId)
            ->where('is_enabled', true)
            ->count();

        return $currentCount >= $limit;
    }

    /**
     * Authorize permission creation with full constraint validation.
     * 
     * Comprehensive check that combines all business rules and constraints.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @param int $featureId
     * @param int $managerId
     * @return bool
     */
    public function authorizePermissionCreation(User $user, int $targetUserId, int $clientId, int $featureId, int $managerId): bool
    {
        // Basic create permission check
        if (!$this->create($user)) {
            return false;
        }

        // Check if user can grant to specific target
        if (!$this->grantToUser($user, $targetUserId, $clientId)) {
            return false;
        }

        // Check if user can manage the specific feature
        if (!$this->manageFeature($user, $featureId)) {
            return false;
        }

        // Validate permission constraints
        if (!$this->validatePermissionConstraints($user, $targetUserId, $clientId, $featureId)) {
            return false;
        }

        // Check management limits
        if ($this->wouldExceedManagementLimit($user, $clientId)) {
            return false;
        }

        // Verify manager assignment is valid
        if (!$this->canManageTargetUser($user, $targetUserId, $clientId)) {
            return false;
        }

        return true;
    }
}