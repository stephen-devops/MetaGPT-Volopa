## Code: app/Policies/UserFeaturePermissionPolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * UserFeaturePermissionPolicy
 * 
 * Authorization policy for user feature permission management operations.
 * Implements delegation-based RBAC system with role hierarchy enforcement.
 * 
 * Business Rules:
 * - Primary Administrator has full access to all users' permissions
 * - Administrator requires explicit delegation to manage other users' permissions
 * - Business User and Card User cannot approve expenses even with management rights
 * - Permission delegation can be granted by Primary Admin to any user regardless of role
 * - Admin can only grant access to their own managed users, not all users
 * - Revoked users fall back to Primary Administrator management until reassigned
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Primary Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Administrator';

    /**
     * Business User role identifier.
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role identifier.
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Determine whether the user can view any user feature permissions.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Only authenticated users with valid client context can view permissions
        if (!$user->client_id) {
            return false;
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can view permissions they manage
        if ($this->isAdministrator($user)) {
            return $this->hasAnyManagedPermissions($user);
        }

        // Business User and Card User cannot view permissions
        return false;
    }

    /**
     * Determine whether the user can view the user feature permission.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function view(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return false;
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can view permissions they manage
        if ($this->isAdministrator($user)) {
            return $userFeaturePermission->manager_user_id === $user->id;
        }

        // Users can view their own permissions
        return $userFeaturePermission->user_id === $user->id;
    }

    /**
     * Determine whether the user can create user feature permissions.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Only authenticated users with valid client context can create permissions
        if (!$user->client_id) {
            return false;
        }

        // Primary Administrator can grant permissions to any user
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can grant permissions to their managed users
        if ($this->isAdministrator($user)) {
            return $this->hasAnyManagedUsers($user);
        }

        // Business User and Card User cannot grant permissions
        return false;
    }

    /**
     * Determine whether the user can grant a specific permission.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function grant(User $user, int $targetUserId, int $clientId, int $featureId): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $clientId) {
            return false;
        }

        // Cannot grant permissions to self
        if ($user->id === $targetUserId) {
            return false;
        }

        // Primary Administrator can grant to any user within same client
        if ($this->isPrimaryAdministrator($user)) {
            return $this->isValidTargetUser($targetUserId, $clientId);
        }

        // Administrator can only grant to their managed users
        if ($this->isAdministrator($user)) {
            return $this->canManageUser($user, $targetUserId, $clientId);
        }

        // Business User and Card User cannot grant permissions
        return false;
    }

    /**
     * Determine whether the user can update the user feature permission.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function update(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return false;
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can update permissions they manage
        if ($this->isAdministrator($user)) {
            return $userFeaturePermission->manager_user_id === $user->id;
        }

        // Business User and Card User cannot update permissions
        return false;
    }

    /**
     * Determine whether the user can delete/revoke the user feature permission.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function delete(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return false;
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can revoke permissions they manage
        if ($this->isAdministrator($user)) {
            return $userFeaturePermission->manager_user_id === $user->id;
        }

        // Business User and Card User cannot revoke permissions
        return false;
    }

    /**
     * Determine whether the user can revoke a specific permission.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    public function revoke(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        return $this->delete($user, $userFeaturePermission);
    }

    /**
     * Determine whether the user can manage permissions for a target user.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function manageUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $clientId) {
            return false;
        }

        // Cannot manage self
        if ($user->id === $targetUserId) {
            return false;
        }

        // Primary Administrator can manage any user within same client
        if ($this->isPrimaryAdministrator($user)) {
            return $this->isValidTargetUser($targetUserId, $clientId);
        }

        // Administrator can manage their assigned users
        if ($this->isAdministrator($user)) {
            return $this->canManageUser($user, $targetUserId, $clientId);
        }

        // Business User and Card User cannot manage other