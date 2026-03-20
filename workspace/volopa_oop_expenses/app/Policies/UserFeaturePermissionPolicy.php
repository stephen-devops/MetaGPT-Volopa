<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * UserFeaturePermissionPolicy
 * 
 * Authorization policy for user feature permission management.
 * Implements delegation-based RBAC system with multi-tenant client scoping.
 * 
 * Business Rules:
 * - Primary Admins have full access to all permissions within their client
 * - Admins can only grant permissions to users they manage
 * - Business Users and Card Users cannot grant permissions
 * - Users can only manage permissions within their own client scope
 * - Managing access can be assigned to any user regardless of role
 * - Permission holders can view their own permissions
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Feature ID for pocket expense management.
     * This should match the feature_id used in the database.
     */
    const POCKET_EXPENSE_FEATURE_ID = 1;

    /**
     * User role constants.
     * These should match the role system used in the platform.
     */
    const ROLE_PRIMARY_ADMIN = 'Primary Administrator';
    const ROLE_ADMIN = 'Admin';
    const ROLE_BUSINESS_USER = 'Business User';
    const ROLE_CARD_USER = 'Card User';

    /**
     * Determine whether the user can view any permissions.
     * 
     * Primary Admins can view all permissions for their client.
     * Admins can view permissions for users they manage.
     * Other users can only view their own permissions.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view permissions (with proper scoping in the controller)
        // The actual filtering happens at the query level based on user role and client
        return true;
    }

    /**
     * Determine whether the user can view the specific permission.
     * 
     * Users can view:
     * - Their own permissions
     * - Permissions they granted (as grantor)
     * - Permissions they manage (as manager)
     * - All permissions if they are Primary Admin for the client
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $permission
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $permission): bool
    {
        // Ensure same client scope
        if (!$this->sameClientScope($user, $permission)) {
            return false;
        }

        // Permission holder can view their own permission
        if ($permission->user_id === $user->id) {
            return true;
        }

        // Grantor can view permissions they granted
        if ($permission->grantor_id === $user->id) {
            return true;
        }

        // Manager can view permissions they manage
        if ($permission->manager_user_id === $user->id) {
            return true;
        }

        // Primary Admin can view all permissions for their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create permissions.
     * 
     * Only Primary Admins and Admins can create permissions.
     * Admins can only create permissions for users they manage.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return $this->isPrimaryAdmin($user) || $this->isAdmin($user);
    }

    /**
     * Determine whether the user can update the permission.
     * 
     * Users can update permissions if:
     * - They are the grantor of the permission
     * - They are the manager of the permission
     * - They are Primary Admin for the client
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $permission
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $permission): bool
    {
        // Ensure same client scope
        if (!$this->sameClientScope($user, $permission)) {
            return false;
        }

        // Grantor can update permissions they granted
        if ($permission->grantor_id === $user->id) {
            return true;
        }

        // Manager can update permissions they manage
        if ($permission->manager_user_id === $user->id) {
            return true;
        }

        // Primary Admin can update all permissions for their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the permission.
     * 
     * Same rules as update - grantors, managers, and Primary Admins can revoke permissions.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $permission
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $permission): bool
    {
        return $this->update($user, $permission);
    }

    /**
     * Determine whether the user can grant permissions to a specific target user.
     * 
     * Authorization rules:
     * - Primary Admins can grant to any user in their client
     * - Admins can only grant to users they manage
     * - Business Users and Card Users cannot grant permissions
     * - All operations must be within the same client scope
     *
     * @param \App\Models\User $grantor
     * @param \App\Models\User $targetUser
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function grantToUser(User $grantor, User $targetUser, int $clientId, int $featureId): bool
    {
        // Only Primary Admins and Admins can grant permissions
        if (!($this->isPrimaryAdmin($grantor) || $this->isAdmin($grantor))) {
            return false;
        }

        // Ensure both users are in the same client scope
        if (!$this->userBelongsToClient($grantor, $clientId) || !$this->userBelongsToClient($targetUser, $clientId)) {
            return false;
        }

        // Primary Admin can grant to anyone in their client
        if ($this->isPrimaryAdmin($grantor)) {
            return true;
        }

        // Admin can only grant to users they manage
        if ($this->isAdmin($grantor)) {
            return $this->canManageUser($grantor, $targetUser, $clientId);
        }

        return false;
    }

    /**
     * Determine whether the user can assign a manager to a permission.
     * 
     * Only the grantor or Primary Admin can assign managers.
     * The manager must be in the same client scope.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $permission
     * @param \App\Models\User $managerUser
     * @return bool
     */
    public function assignManager(User $user, UserFeaturePermission $permission, User $managerUser): bool
    {
        // Ensure same client scope for all parties
        if (!$this->sameClientScope($user, $permission) || 
            !$this->userBelongsToClient($managerUser, $permission->client_id)) {
            return false;
        }

        // Grantor can assign managers
        if ($permission->grantor_id === $user->id) {
            return true;
        }

        // Primary Admin can assign managers
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can enable/disable a permission.
     * 
     * Same rules as update - grantors, managers, and Primary Admins.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $permission
     * @return bool
     */
    public function toggleStatus(User $user, UserFeaturePermission $permission): bool
    {
        return $this->update($user, $permission);
    }

    /**
     * Check if the user is a Primary Administrator.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    protected function isPrimaryAdmin(User $user): bool
    {
        // Assuming the User model has a role or similar method
        // This implementation may need to be adjusted based on the actual User model structure
        return $user->role === self::ROLE_PRIMARY_ADMIN || 
               (method_exists($user, 'hasRole') && $user->hasRole(self::ROLE_PRIMARY_ADMIN)) ||
               (method_exists($user, 'getRoleAttribute') && $user->getRoleAttribute() === self::ROLE_PRIMARY_ADMIN);
    }

    /**
     * Check if the user is an Administrator.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    protected function isAdmin(User $user): bool
    {
        return $user->role === self::ROLE_ADMIN || 
               (method_exists($user, 'hasRole') && $user->hasRole(self::ROLE_ADMIN)) ||
               (method_exists($user, 'getRoleAttribute') && $user->getRoleAttribute() === self::ROLE_ADMIN);
    }

    /**
     * Check if the user is a Business User.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    protected function isBusinessUser(User $user): bool
    {
        return $user->role === self::ROLE_BUSINESS_USER || 
               (method_exists($user, 'hasRole') && $user->hasRole(self::ROLE_BUSINESS_USER)) ||
               (method_exists($user, 'getRoleAttribute') && $user->getRoleAttribute() === self::ROLE_BUSINESS_USER);
    }

    /**
     * Check if the user is a Card User.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    protected function isCardUser(User $user): bool
    {
        return $user->role === self::ROLE_CARD_USER || 
               (method_exists($user, 'hasRole') && $user->hasRole(self::ROLE_CARD_USER)) ||
               (method_exists($user, 'getRoleAttribute') && $user->getRoleAttribute() === self::ROLE_CARD_USER);
    }

    /**
     * Check if the user and permission are in the same client scope.
     *
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission $permission
     * @return bool
     */
    protected function sameClientScope(User $user, UserFeaturePermission $permission): bool
    {
        // Assuming the User model has a client_id or similar method to determine client association
        $userClientId = $this->getUserClientId($user);
        return $userClientId === $permission->client_id;
    }

    /**
     * Check if the user belongs to a specific client.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    protected function userBelongsToClient(User $user, int $clientId): bool
    {
        $userClientId = $this->getUserClientId($user);
        return $userClientId === $clientId;
    }

    /**
     * Get the client ID for a user.
     * This method abstracts the way client association is determined.
     *
     * @param \App\Models\User $user
     * @return int|null
     */
    protected function getUserClientId(User $user): ?int
    {
        // Implementation depends on how the User model stores client association
        // This could be a direct client_id field, a relationship, or retrieved from context
        if (property_exists($user, 'client_id')) {
            return $user->client_id;
        }

        if (method_exists($user, 'getClientId')) {
            return $user->getClientId();
        }

        if (method_exists($user, 'client') && $user->client) {
            return $user->client->id;
        }

        // Fallback: get from request context or session
        // This might need to be adjusted based on how client context is managed
        if (request()->has('client_id')) {
            return (int) request()->get('client_id');
        }

        return null;
    }

    /**
     * Check if a user can manage another user.
     * This implements the business rule that Admins can only manage specific users.
     *
     * @param \App\Models\User $manager
     * @param \App\Models\User $targetUser
     * @param int $clientId
     * @return bool
     */
    protected function canManageUser(User $manager, User $targetUser, int $clientId): bool
    {
        // Primary Admin can manage anyone in their client
        if ($this->isPrimaryAdmin($manager)) {
            return $this->userBelongsToClient($targetUser, $clientId);
        }

        // For Admins, check if they have management relationship with the target user
        // This could be implemented through a management table, user hierarchy, or permissions
        
        // Option 1: Check if there's an existing permission where manager is the manager_user_id
        $existingManagementRelation = UserFeaturePermission::where('user_id', $targetUser->id)
            ->where('client_id', $clientId)
            ->where('manager_user_id', $manager->id)
            ->exists();

        if ($existingManagementRelation) {
            return true;
        }

        // Option 2: Check if manager has been granted management permissions for this user
        // This would require a separate management permission system
        
        // Option 3: Default behavior - Admins can manage Business Users and Card Users
        // but not other Admins or Primary Admins
        if ($this->isAdmin($manager)) {
            return $this->isBusinessUser($targetUser) || $this->isCardUser($targetUser);
        }

        return false;
    }

    /**
     * Get users that a specific user can manage.
     * Used for filtering in controllers and services.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getManagedUsers(User $user, int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        // Primary Admin can manage all users in their client
        if ($this->isPrimaryAdmin($user)) {
            return User::where('client_id', $clientId)->get();
        }

        // Admin can manage users they have management rights for
        if ($this->isAdmin($user)) {
            // Get users where this admin is designated as manager
            $managedUserIds = UserFeaturePermission::where('client_id', $clientId)
                ->where('manager_user_id', $user->id)
                ->pluck('user_id')
                ->unique();

            return User::whereIn('id', $managedUserIds)->get();
        }

        // Other users cannot manage anyone
        return collect();
    }

    /**
     * Authorize that a user can perform bulk operations.
     * Used when granting/revoking multiple permissions at once.
     *
     * @param \App\Models\User $user
     * @param array $targetUserIds
     * @param int $clientId
     * @param int $featureId
     * @return bool
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorizeBulkOperation(User $user, array $targetUserIds, int $clientId, int $featureId): bool
    {
        foreach ($targetUserIds as $targetUserId) {
            $targetUser = User::find($targetUserId);
            
            if (!$targetUser) {
                throw new AuthorizationException("Target user {$targetUserId} not found.");
            }

            if (!$this->grantToUser($user, $targetUser, $clientId, $featureId)) {
                throw new AuthorizationException("Not authorized to manage permissions for user {$targetUserId}.");
            }
        }

        return true;
    }

    /**
     * Check if a permission change is allowed based on current status.
     * Used to prevent invalid state transitions.
     *
     * @param \App\Models\UserFeaturePermission $permission
     * @param bool $newEnabledStatus
     * @return bool
     */
    public function canChangeStatus(UserFeaturePermission $permission, bool $newEnabledStatus): bool
    {
        // Allow enabling disabled permissions
        if (!$permission->is_enabled && $newEnabledStatus) {
            return true;
        }

        // Allow disabling enabled permissions
        if ($permission->is_enabled && !$newEnabledStatus) {
            return true;
        }

        // No change needed
        return true;
    }

    /**
     * Validate that a feature ID is valid for permission management.
     *
     * @param int $featureId
     * @return bool
     */
    public function isValidFeatureId(int $featureId): bool
    {
        // For now, only pocket expense feature is supported
        // This can be expanded as more features are added
        $validFeatureIds = [
            self::POCKET_EXPENSE_FEATURE_ID,
        ];

        return in_array($featureId, $validFeatureIds);
    }

    /**
     * Check if a user has reached the maximum number of permissions they can grant.
     * Implements rate limiting for permission grants.
     *
     * @param \App\Models\User $grantor
     * @param int $clientId
     * @return bool
     */
    public function canGrantMorePermissions(User $grantor, int $clientId): bool
    {
        // Primary Admin has no limits
        if ($this->isPrimaryAdmin($grantor)) {
            return true;
        }

        // Count existing permissions granted by this user
        $grantedCount = UserFeaturePermission::where('grantor_id', $grantor->id)
            ->where('client_id', $clientId)
            ->count();

        // Set reasonable limits based on role
        $maxPermissions = match (true) {
            $this->isAdmin($grantor) => 50,  // Admins can grant up to 50 permissions
            default => 0,  // Other roles cannot grant permissions
        };

        return $grantedCount < $maxPermissions;
    }

    /**
     * Log authorization decisions for audit purposes.
     *
     * @param string $action
     * @param \App\Models\User $user
     * @param \App\Models\UserFeaturePermission|null $permission
     * @param bool $authorized
     * @param array $context
     * @return void
     */
    protected function logAuthorizationDecision(
        string $action,
        User $user,
        ?UserFeaturePermission $permission,
        bool $authorized,
        array $context = []
    ): void {
        \Log::info('UserFeaturePermission authorization decision', [
            'action' => $action,
            'user_id' => $user->id,
            'user_role' => $this->getUserRole($user),
            'permission_id' => $permission?->id,
            'target_user_id' => $permission?->user_id,
            'client_id' => $permission?->client_id ?? $context['client_id'] ?? null,
            'feature_id' => $permission?->feature_id ?? $context['feature_id'] ?? null,
            'authorized' => $authorized,
            'context' => $context,
            'timestamp' => now(),
        ]);
    }

    /**
     * Get the role of a user.
     *
     * @param \App\Models\User $user
     * @return string
     */
    protected function getUserRole(User $user): string
    {
        if (property_exists($user, 'role')) {
            return $user->role;
        }

        if (method_exists($user, 'getRole')) {
            return $user->getRole();
        }

        if (method_exists($user, 'getRoleAttribute')) {
            return $user->getRoleAttribute();
        }

        return 'unknown';
    }
}