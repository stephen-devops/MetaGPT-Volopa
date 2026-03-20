<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * PocketExpensePolicy
 * 
 * Authorization policy for PocketExpense operations with delegation-based RBAC system.
 * Supports multi-tenant client scoping and role-based permission management.
 * Implements Volopa's user role hierarchy and feature permission model.
 * 
 * Role hierarchy (descending authority):
 * - Primary Administrator: Full access to all users by default
 * - Admin: Can only access their own managed users
 * - Business User: Limited access based on permissions
 * - Card User: Limited access based on permissions
 */
class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Feature ID for pocket expense management.
     * This should match the feature ID in the feature management system.
     */
    const POCKET_EXPENSE_FEATURE_ID = 1;

    /**
     * User role constants.
     */
    const ROLE_PRIMARY_ADMIN = 'Primary Administrator';
    const ROLE_ADMIN = 'Admin';
    const ROLE_BUSINESS_USER = 'Business User';
    const ROLE_CARD_USER = 'Card User';

    /**
     * Determine whether the user can view any expenses.
     * Users can view expenses if they have the appropriate permissions.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user): Response|bool
    {
        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator has full access to view expenses.');
        }

        // Check if user has pocket expense feature permission for any client
        $hasPermission = UserFeaturePermission::where('user_id', $user->id)
            ->where('feature_id', self::POCKET_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();

        if ($hasPermission) {
            return Response::allow('User has pocket expense feature permission.');
        }

        return Response::deny('User does not have permission to view pocket expenses.');
    }

    /**
     * Determine whether the user can view the specific expense.
     * Users can view an expense if:
     * - They own the expense
     * - They are a Primary Administrator
     * - They are an Admin with management rights over the expense owner
     * - They have appropriate feature permissions for the client
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // User can always view their own expenses
        if ($user->id === $pocketExpense->user_id) {
            return Response::allow('User can view their own expense.');
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator has full access to view any expense.');
        }

        // Check if user can manage the expense owner
        if ($this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id)) {
            return Response::allow('User has management rights over the expense owner.');
        }

        // Check if user has feature permission for this client
        if ($this->hasFeaturePermissionForClient($user, $pocketExpense->client_id)) {
            return Response::allow('User has pocket expense feature permission for this client.');
        }

        return Response::deny('User does not have permission to view this expense.');
    }

    /**
     * Determine whether the user can create expenses.
     * Users can create expenses if they have the appropriate permissions.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function create(User $user): Response|bool
    {
        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator can create expenses.');
        }

        // Check if user has pocket expense feature permission for any client
        $hasPermission = UserFeaturePermission::where('user_id', $user->id)
            ->where('feature_id', self::POCKET_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();

        if ($hasPermission) {
            return Response::allow('User has pocket expense feature permission.');
        }

        return Response::deny('User does not have permission to create pocket expenses.');
    }

    /**
     * Determine whether the user can update the specific expense.
     * Users can update an expense if:
     * - They own the expense and it's in draft status
     * - They are a Primary Administrator
     * - They are an Admin with management rights over the expense owner
     * - They have appropriate feature permissions for the client and the expense is editable
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function update(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Cannot update soft deleted expenses
        if ($pocketExpense->isDeleted()) {
            return Response::deny('Cannot update a deleted expense.');
        }

        // User can update their own expense if it's in draft status
        if ($user->id === $pocketExpense->user_id && $pocketExpense->canEdit()) {
            return Response::allow('User can update their own draft expense.');
        }

        // Primary Administrator has full access (but respect business rules)
        if ($this->isPrimaryAdministrator($user)) {
            if (!$pocketExpense->canEdit()) {
                return Response::deny('Expense cannot be edited in its current status.');
            }
            return Response::allow('Primary Administrator can update expenses.');
        }

        // Check if user can manage the expense owner
        if ($this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id)) {
            if (!$pocketExpense->canEdit()) {
                return Response::deny('Expense cannot be edited in its current status.');
            }
            return Response::allow('User has management rights over the expense owner.');
        }

        // Check if user has feature permission for this client
        if ($this->hasFeaturePermissionForClient($user, $pocketExpense->client_id)) {
            if ($user->id !== $pocketExpense->user_id) {
                return Response::deny('User can only update their own expenses.');
            }
            if (!$pocketExpense->canEdit()) {
                return Response::deny('Expense cannot be edited in its current status.');
            }
            return Response::allow('User has pocket expense feature permission for this client.');
        }

        return Response::deny('User does not have permission to update this expense.');
    }

    /**
     * Determine whether the user can delete the specific expense.
     * Users can delete an expense if:
     * - They own the expense and it's in draft status
     * - They are a Primary Administrator
     * - They are an Admin with management rights over the expense owner
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function delete(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Cannot delete already deleted expenses
        if ($pocketExpense->isDeleted()) {
            return Response::deny('Expense is already deleted.');
        }

        // Cannot delete expenses that are not in draft status
        if (!$pocketExpense->canDelete()) {
            return Response::deny('Only draft expenses can be deleted.');
        }

        // User can delete their own expense if it's deletable
        if ($user->id === $pocketExpense->user_id) {
            return Response::allow('User can delete their own draft expense.');
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator can delete expenses.');
        }

        // Check if user can manage the expense owner
        if ($this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id)) {
            return Response::allow('User has management rights over the expense owner.');
        }

        return Response::deny('User does not have permission to delete this expense.');
    }

    /**
     * Determine whether the user can approve the specific expense.
     * Business Users and Card Users cannot approve expenses even with management rights.
     * Only Primary Administrator and Admin roles can approve expenses.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function approve(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Cannot approve soft deleted expenses
        if ($pocketExpense->isDeleted()) {
            return Response::deny('Cannot approve a deleted expense.');
        }

        // Cannot approve expenses that are not submitted
        if (!$pocketExpense->canApprove()) {
            return Response::deny('Expense must be submitted for approval first.');
        }

        // Users cannot approve their own expenses
        if ($user->id === $pocketExpense->user_id) {
            return Response::deny('Users cannot approve their own expenses.');
        }

        // Business Users and Card Users cannot approve expenses
        $userRole = $this->getUserRole($user);
        if (in_array($userRole, [self::ROLE_BUSINESS_USER, self::ROLE_CARD_USER])) {
            return Response::deny('Business Users and Card Users cannot approve expenses.');
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator can approve expenses.');
        }

        // Admin role can approve if they have management rights or feature permission
        if ($userRole === self::ROLE_ADMIN) {
            // Check if user can manage the expense owner
            if ($this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id)) {
                return Response::allow('Admin has management rights over the expense owner.');
            }

            // Check if user has feature permission for this client
            if ($this->hasFeaturePermissionForClient($user, $pocketExpense->client_id)) {
                return Response::allow('Admin has pocket expense feature permission for this client.');
            }
        }

        return Response::deny('User does not have permission to approve this expense.');
    }

    /**
     * Determine whether the user can reject the specific expense.
     * Business Users and Card Users cannot reject expenses even with management rights.
     * Only Primary Administrator and Admin roles can reject expenses.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function reject(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Cannot reject soft deleted expenses
        if ($pocketExpense->isDeleted()) {
            return Response::deny('Cannot reject a deleted expense.');
        }

        // Cannot reject expenses that are not submitted
        if (!$pocketExpense->canReject()) {
            return Response::deny('Expense must be submitted for approval first.');
        }

        // Users cannot reject their own expenses
        if ($user->id === $pocketExpense->user_id) {
            return Response::deny('Users cannot reject their own expenses.');
        }

        // Business Users and Card Users cannot reject expenses
        $userRole = $this->getUserRole($user);
        if (in_array($userRole, [self::ROLE_BUSINESS_USER, self::ROLE_CARD_USER])) {
            return Response::deny('Business Users and Card Users cannot reject expenses.');
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator can reject expenses.');
        }

        // Admin role can reject if they have management rights or feature permission
        if ($userRole === self::ROLE_ADMIN) {
            // Check if user can manage the expense owner
            if ($this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id)) {
                return Response::allow('Admin has management rights over the expense owner.');
            }

            // Check if user has feature permission for this client
            if ($this->hasFeaturePermissionForClient($user, $pocketExpense->client_id)) {
                return Response::allow('Admin has pocket expense feature permission for this client.');
            }
        }

        return Response::deny('User does not have permission to reject this expense.');
    }

    /**
     * Determine whether the user can submit the specific expense for approval.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function submit(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Cannot submit soft deleted expenses
        if ($pocketExpense->isDeleted()) {
            return Response::deny('Cannot submit a deleted expense.');
        }

        // Cannot submit expenses that are not in draft status
        if (!$pocketExpense->canSubmit()) {
            return Response::deny('Only draft expenses can be submitted for approval.');
        }

        // Use the same logic as update for consistency
        return $this->update($user, $pocketExpense);
    }

    /**
     * Determine whether the user can revert the specific expense to draft.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $pocketExpense
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function revert(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Cannot revert soft deleted expenses
        if ($pocketExpense->isDeleted()) {
            return Response::deny('Cannot revert a deleted expense.');
        }

        // Can only revert submitted or rejected expenses
        if (!in_array($pocketExpense->status, [PocketExpense::STATUS_SUBMITTED, PocketExpense::STATUS_REJECTED])) {
            return Response::deny('Only submitted or rejected expenses can be reverted to draft.');
        }

        // User can revert their own expense
        if ($user->id === $pocketExpense->user_id) {
            return Response::allow('User can revert their own expense to draft.');
        }

        // Primary Administrator has full access
        if ($this->isPrimaryAdministrator($user)) {
            return Response::allow('Primary Administrator can revert expenses.');
        }

        // Check if user can manage the expense owner
        if ($this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id)) {
            return Response::allow('User has management rights over the expense owner.');
        }

        return Response::deny('User does not have permission to revert this expense.');
    }

    /**
     * Check if the user is a Primary Administrator.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    protected function isPrimaryAdministrator(User $user): bool
    {
        return $this->getUserRole($user) === self::ROLE_PRIMARY_ADMIN;
    }

    /**
     * Get the user's role.
     * This method should be implemented based on your user role system.
     *
     * @param \App\Models\User $user
     * @return string
     */
    protected function getUserRole(User $user): string
    {
        // This assumes the User model has a 'role' attribute or relationship
        // Adjust based on your actual user role implementation
        if (isset($user->role)) {
            return $user->role;
        }

        // Fallback to check role relationship if it exists
        if (method_exists($user, 'role') && $user->role) {
            return $user->role->name ?? self::ROLE_CARD_USER;
        }

        // Default to lowest privilege role
        return self::ROLE_CARD_USER;
    }

    /**
     * Check if the user can manage another user within a specific client.
     * Admin role can only grant access to their own managed users, not all users.
     * Managing access can be assigned to any user regardless of role.
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    protected function canManageUser(User $user, int $targetUserId, int $clientId): bool
    {
        // User cannot manage themselves in this context
        if ($user->id === $targetUserId) {
            return false;
        }

        // Primary Administrator can manage all users
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Check if user has a management permission for the target user
        $hasManagementPermission = UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('manager_user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();

        return $hasManagementPermission;
    }

    /**
     * Check if the user has pocket expense feature permission for a specific client.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    protected function hasFeaturePermissionForClient(User $user, int $clientId): bool
    {
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('feature_id', self::POCKET_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if the user has any active feature permissions.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    protected function hasAnyFeaturePermission(User $user): bool
    {
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('feature_id', self::POCKET_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if the user can access expenses for a specific client.
     * This is used for client-scoped queries and filters.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    public function accessClient(User $user, int $clientId): bool
    {
        // Primary Administrator has access to all clients
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Check if user has feature permission for this specific client
        return $this->hasFeaturePermissionForClient($user, $clientId);
    }

    /**
     * Get the list of client IDs that the user can access.
     * This is used for filtering expenses in list views.
     *
     * @param \App\Models\User $user
     * @return array
     */
    public function getAccessibleClientIds(User $user): array
    {
        // Primary Administrator has access to all clients
        if ($this->isPrimaryAdministrator($user)) {
            // Return all client IDs - this should be implemented based on your Client model
            // For now, return an empty array to indicate no filtering needed
            return [];
        }

        // Get client IDs where user has pocket expense feature permission
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('feature_id', self::POCKET_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->pluck('client_id')
            ->toArray();
    }

    /**
     * Check if the user can create expenses for a specific target user and client.
     * This is used when creating expenses on behalf of other users.
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function createForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // User can always create expenses for themselves
        if ($user->id === $targetUserId) {
            return $this->hasFeaturePermissionForClient($user, $clientId) || $this->isPrimaryAdministrator($user);
        }

        // Primary Administrator can create for anyone
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Check if user can manage the target user
        return $this->canManageUser($user, $targetUserId, $clientId);
    }

    /**
     * Authorize CSV upload operations.
     * Users need appropriate permissions to upload expenses for target users.
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function uploadCsv(User $user, int $targetUserId, int $clientId): Response|bool
    {
        // Check if user can create expenses for the target user
        if (!$this->createForUser($user, $targetUserId, $clientId)) {
            return Response::deny('User does not have permission to create expenses for the specified user.');
        }

        // Additional CSV-specific checks can be added here
        // For example, checking upload limits, file size restrictions, etc.

        return Response::allow('User can upload CSV expenses for the target user.');
    }
}