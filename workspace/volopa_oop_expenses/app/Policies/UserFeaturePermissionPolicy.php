<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * UserFeaturePermissionPolicy
 * 
 * Authorization policy for user permission management operations.
 * This policy controls access to user feature permission CRUD operations
 * following the platform's role-based access control system where
 * Primary Administrators have full access by default, and other users
 * can only manage permissions they have been granted access to.
 * 
 * Platform roles hierarchy:
 * - Primary Admin: Full access to all permission operations
 * - Admin: Can manage permissions for users within their client
 * - Business User: Limited access based on delegated permissions
 * - Card User: No permission management access by default
 */
class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * OOP Feature ID constant for checking OOP-specific permissions
     *
     * @var int
     */
    private const OOP_FEATURE_ID = 16;

    /**
     * Primary Administrator role name
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role name
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Admin';

    /**
     * Business User role name
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role name
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Determine whether the user can view any user feature permissions.
     * 
     * Primary Admins can view all permissions.
     * Admins can view permissions within their client.
     * Business Users can view permissions they have been granted access to manage.
     *
     * @param User $user
     * @return Response|bool
     */
    public function viewAny(User $user): Response|bool
    {
        // Primary Administrators have full access
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can view permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can view if they have been granted permission management access
        if ($this->isBusinessUser($user) && $this->hasOOPPermissionManagementAccess($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to view user feature permissions.');
    }

    /**
     * Determine whether the user can view the user feature permission.
     * 
     * Users can only view permissions within their own client context
     * and must have appropriate role-based access.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return Response|bool
     */
    public function view(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view user feature permissions.');
        }

        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return Response::deny('You can only view permissions within your own client.');
        }

        // Primary Admins can view any permission within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can view permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can view permissions they manage or were granted by them
        if ($this->isBusinessUser($user)) {
            if ($this->canManagePermission($user, $userFeaturePermission)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to view this user feature permission.');
    }

    /**
     * Determine whether the user can create user feature permissions.
     * 
     * Only Primary Admins and Admins can create new permissions.
     * Business Users cannot create permissions but can delegate existing ones.
     *
     * @param User $user
     * @return Response|bool
     */
    public function create(User $user): Response|bool
    {
        // Primary Administrators can create permissions
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can create permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users cannot create new permissions
        return Response::deny('Only Primary Administrators and Admins can create user feature permissions.');
    }

    /**
     * Determine whether the user can update the user feature permission.
     * 
     * Users can update permissions based on their role and relationship to the permission.
     * Primary Admins have full access, while others have restricted access.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return Response|bool
     */
    public function update(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return Response::deny('You can only update permissions within your own client.');
        }

        // Primary Admins can update any permission within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can update permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can update permissions they manage or were granted by them
        if ($this->isBusinessUser($user)) {
            if ($this->canManagePermission($user, $userFeaturePermission)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to update this user feature permission.');
    }

    /**
     * Determine whether the user can delete the user feature permission.
     * 
     * Similar rules to update - users can delete permissions they have management access to.
     * Deleting permissions requires higher privileges to prevent accidental access removal.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return Response|bool
     */
    public function delete(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return Response::deny('You can only delete permissions within your own client.');
        }

        // Primary Admins can delete any permission within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can delete permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can delete permissions they manage (but with more restrictions)
        if ($this->isBusinessUser($user)) {
            // Can only delete if they were the grantor or manager
            if ($userFeaturePermission->grantor_id === $user->id || 
                $userFeaturePermission->manager_user_id === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to delete this user feature permission.');
    }

    /**
     * Determine whether the user can grant permissions to other users.
     * 
     * This is used for delegation scenarios where users can grant
     * permissions they already have to other users within their client.
     *
     * @param User $user
     * @param int $featureId
     * @param int $targetUserId
     * @return Response|bool
     */
    public function grantPermission(User $user, int $featureId, int $targetUserId): Response|bool
    {
        // Primary Admins can grant any permission
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can grant permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can grant permissions they have and manage
        if ($this->isBusinessUser($user)) {
            // Check if user has the permission for the feature they want to grant
            if ($this->hasFeaturePermission($user, $featureId)) {
                // Verify target user is in same client
                $targetUser = User::find($targetUserId);
                if ($targetUser && $targetUser->client_id === $user->client_id) {
                    return Response::allow();
                }
            }
        }

        return Response::deny('You do not have permission to grant this feature permission.');
    }

    /**
     * Determine whether the user can revoke permissions from other users.
     * 
     * Users can revoke permissions they previously granted or manage.
     * More restrictive than granting to prevent unauthorized access removal.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return Response|bool
     */
    public function revokePermission(User $user, UserFeaturePermission $userFeaturePermission): Response|bool
    {
        // Must be within the same client context
        if ($user->client_id !== $userFeaturePermission->client_id) {
            return Response::deny('You can only revoke permissions within your own client.');
        }

        // Primary Admins can revoke any permission
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can revoke permissions within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can revoke permissions they granted or manage
        if ($this->isBusinessUser($user)) {
            if ($userFeaturePermission->grantor_id === $user->id || 
                $userFeaturePermission->manager_user_id === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to revoke this user feature permission.');
    }

    /**
     * Determine whether the user can manage delegated users.
     * 
     * This allows users to see and manage users they have granted permissions to.
     * Important for the delegation workflow in OOP expense management.
     *
     * @param User $user
     * @param int $targetUserId
     * @return Response|bool
     */
    public function manageDelegatedUser(User $user, int $targetUserId): Response|bool
    {
        // Primary Admins can manage any user in their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can manage users in their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can manage users they have granted permissions to
        if ($this->isBusinessUser($user)) {
            // Check if user has granted permissions to the target user
            $hasGrantedPermissions = UserFeaturePermission::where('grantor_id', $user->id)
                ->where('user_id', $targetUserId)
                ->where('client_id', $user->client_id)
                ->exists();
                
            if ($hasGrantedPermissions) {
                return Response::allow();
            }

            // Or if they are designated as manager
            $isManager = UserFeaturePermission::where('manager_user_id', $user->id)
                ->where('user_id', $targetUserId)
                ->where('client_id', $user->client_id)
                ->exists();
                
            if ($isManager) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to manage this user.');
    }

    /**
     * Check if a user is a Primary Administrator.
     *
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        return $user->role === self::ROLE_PRIMARY_ADMIN;
    }

    /**
     * Check if a user is an Administrator.
     *
     * @param User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        return $user->role === self::ROLE_ADMIN;
    }

    /**
     * Check if a user is a Business User.
     *
     * @param User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        return $user->role === self::ROLE_BUSINESS_USER;
    }

    /**
     * Check if a user is a Card User.
     *
     * @param User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        return $user->role === self::ROLE_CARD_USER;
    }

    /**
     * Check if a user has OOP permission management access.
     * 
     * This determines if a Business User has been granted the ability
     * to manage OOP expense permissions for other users.
     *
     * @param User $user
     * @return bool
     */
    private function hasOOPPermissionManagementAccess(User $user): bool
    {
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if a user has permission for a specific feature.
     *
     * @param User $user
     * @param int $featureId
     * @return bool
     */
    private function hasFeaturePermission(User $user, int $featureId): bool
    {
        // Primary Admins and Admins have all permissions by default
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        // Check explicit permission records for other users
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if a user can manage a specific permission record.
     * 
     * This determines if a user has the authority to modify
     * or view details of a specific permission record.
     *
     * @param User $user
     * @param UserFeaturePermission $userFeaturePermission
     * @return bool
     */
    private function canManagePermission(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // User can manage if they were the grantor
        if ($userFeaturePermission->grantor_id === $user->id) {
            return true;
        }

        // User can manage if they are designated as the manager
        if ($userFeaturePermission->manager_user_id === $user->id) {
            return true;
        }

        // User can manage their own permissions (view only)
        if ($userFeaturePermission->user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Check if a target user belongs to the same client as the requesting user.
     * 
     * This is a crucial multi-tenancy check to ensure users can only
     * manage permissions within their own client context.
     *
     * @param User $user
     * @param int $targetUserId
     * @return bool
     */
    private function isSameClient(User $user, int $targetUserId): bool
    {
        $targetUser = User::find($targetUserId);
        
        if (!$targetUser) {
            return false;
        }

        return $user->client_id === $targetUser->client_id;
    }

    /**
     * Check if the client has the OOP feature enabled.
     * 
     * This verifies that the client has access to the OOP expense
     * management feature before allowing permission operations.
     *
     * @param int $clientId
     * @return bool
     */
    private function clientHasOOPFeature(int $clientId): bool
    {
        // Check if client has OOP feature enabled via ClientFeatures relationship
        // This would typically query a client_features pivot table or similar
        // For now, we'll assume all clients have access unless explicitly disabled
        return true; // TODO: Implement actual ClientFeatures check when model is available
    }

    /**
     * Get the user's effective permissions for permission management.
     * 
     * This returns a summary of what permission operations the user
     * can perform, useful for UI authorization decisions.
     *
     * @param User $user
     * @return array<string, bool>
     */
    public function getEffectivePermissions(User $user): array
    {
        return [
            'can_view_any' => $this->viewAny($user),
            'can_create' => $this->create($user),
            'can_grant_permissions' => $this->isPrimaryAdmin($user) || 
                                     $this->isAdmin($user) || 
                                     ($this->isBusinessUser($user) && $this->hasOOPPermissionManagementAccess($user)),
            'can_revoke_permissions' => $this->isPrimaryAdmin($user) || 
                                      $this->isAdmin($user) || 
                                      $this->isBusinessUser($user),
            'can_manage_delegated_users' => $this->isPrimaryAdmin($user) || 
                                          $this->isAdmin($user) || 
                                          $this->isBusinessUser($user),
            'is_primary_admin' => $this->isPrimaryAdmin($user),
            'is_admin' => $this->isAdmin($user),
            'is_business_user' => $this->isBusinessUser($user),
            'has_oop_access' => $this->hasFeaturePermission($user, self::OOP_FEATURE_ID),
            'has_oop_management_access' => $this->hasOOPPermissionManagementAccess($user),
        ];
    }

    /**
     * Authorize permission creation with specific validation.
     * 
     * This provides detailed authorization for permission creation
     * including validation of target user, feature, and client context.
     *
     * @param User $user
     * @param array<string, mixed> $permissionData
     * @return Response|bool
     */
    public function authorizePermissionCreation(User $user, array $permissionData): Response|bool
    {
        // Basic create permission check
        if (!$this->create($user)) {
            return Response::deny('You do not have permission to create user feature permissions.');
        }

        $targetUserId = $permissionData['user_id'] ?? null;
        $featureId = $permissionData['feature_id'] ?? null;

        // Validate required fields
        if (!$targetUserId || !$featureId) {
            return Response::deny('Missing required permission data.');
        }

        // Check if target user is in same client
        if (!$this->isSameClient($user, $targetUserId)) {
            return Response::deny('You can only create permissions for users in your own client.');
        }

        // Check if client has the feature enabled
        if (!$this->clientHasOOPFeature($user->client_id)) {
            return Response::deny('The requested feature is not enabled for your client.');
        }

        // Additional validation for Business Users granting permissions
        if ($this->isBusinessUser($user)) {
            if (!$this->hasFeaturePermission($user, $featureId)) {
                return Response::deny('You can only grant permissions that you already have.');
            }
        }

        return Response::allow();
    }

    /**
     * Check if user can perform bulk permission operations.
     * 
     * Bulk operations require higher privileges due to their potential impact.
     *
     * @param User $user
     * @return Response|bool
     */
    public function bulkOperations(User $user): Response|bool
    {
        // Only Primary Admins and Admins can perform bulk operations
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        return Response::deny('Bulk permission operations require Administrator privileges.');
    }
}