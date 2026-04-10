<?php

namespace App\Policies;

use App\Models\PocketExpense;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * PocketExpensePolicy
 * 
 * Authorization policy for expense operations.
 * Handles permissions based on user roles and delegation hierarchy.
 * 
 * Permission rules:
 * - Primary Admin: Full access to all users by default
 * - Admin: Full access only to own expenses by default; needs explicit grant for others
 * - Business User and Card User: Cannot approve expenses even with management rights
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to their own managed users (not all users)
 */
class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any expenses.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Check if user has OOP Expense feature enabled (feature_id = 16)
        // This requires implementation of ClientFeatures model and checking mechanism
        return true; // Placeholder - should verify feature access
    }

    /**
     * Determine whether the user can view the expense.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    public function view(User $user, PocketExpense $expense): bool
    {
        // User can view their own expenses
        if ($user->id === $expense->user_id) {
            return true;
        }

        // TODO: Check if user has management rights over the expense owner
        // This requires UserPermissionService::canManageUser implementation
        return false; // Placeholder - should check delegation hierarchy
    }

    /**
     * Determine whether the user can create expenses.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // TODO: Check if user has OOP Expense feature enabled (feature_id = 16)
        // This requires implementation of ClientFeatures model and checking mechanism
        return true; // Placeholder - should verify feature access
    }

    /**
     * Determine whether the user can update the expense.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    public function update(User $user, PocketExpense $expense): bool
    {
        // Cannot update approved or rejected expenses
        if (in_array($expense->status, ['approved', 'rejected'])) {
            return false;
        }

        // User can update their own expenses
        if ($user->id === $expense->user_id) {
            return true;
        }

        // TODO: Check if user has management rights over the expense owner
        // This requires UserPermissionService::canManageUser implementation
        return false; // Placeholder - should check delegation hierarchy
    }

    /**
     * Determine whether the user can delete the expense.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    public function delete(User $user, PocketExpense $expense): bool
    {
        // Cannot delete approved expenses
        if ($expense->status === 'approved') {
            return false;
        }

        // User can delete their own expenses
        if ($user->id === $expense->user_id) {
            return true;
        }

        // TODO: Check if user has management rights over the expense owner
        // This requires UserPermissionService::canManageUser implementation
        return false; // Placeholder - should check delegation hierarchy
    }

    /**
     * Determine whether the user can approve the expense.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Auth\Access\Response
     */
    public function approve(User $user, PocketExpense $expense): Response
    {
        // Cannot approve own expenses
        if ($user->id === $expense->user_id) {
            return Response::deny('Users cannot approve their own expenses.');
        }

        // Only submitted expenses can be approved
        if ($expense->status !== 'submitted') {
            return Response::deny('Only submitted expenses can be approved.');
        }

        // TODO: Check user role from users table or related role table
        // Business User and Card User cannot approve expenses even with management rights
        $userRole = $this->getUserRole($user);
        if (in_array($userRole, ['Business User', 'Card User'])) {
            return Response::deny('Your role does not have approval permissions.');
        }

        // TODO: Check if user has management rights over the expense owner
        // This requires UserPermissionService::canManageUser implementation
        $canManage = $this->canManageExpenseOwner($user, $expense);
        if (!$canManage) {
            return Response::deny('You do not have permission to approve this user\'s expenses.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can reject the expense.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Auth\Access\Response
     */
    public function reject(User $user, PocketExpense $expense): Response
    {
        // Cannot reject own expenses
        if ($user->id === $expense->user_id) {
            return Response::deny('Users cannot reject their own expenses.');
        }

        // Only submitted expenses can be rejected
        if ($expense->status !== 'submitted') {
            return Response::deny('Only submitted expenses can be rejected.');
        }

        // TODO: Check user role from users table or related role table
        // Business User and Card User cannot reject expenses even with management rights
        $userRole = $this->getUserRole($user);
        if (in_array($userRole, ['Business User', 'Card User'])) {
            return Response::deny('Your role does not have approval permissions.');
        }

        // TODO: Check if user has management rights over the expense owner
        // This requires UserPermissionService::canManageUser implementation
        $canManage = $this->canManageExpenseOwner($user, $expense);
        if (!$canManage) {
            return Response::deny('You do not have permission to reject this user\'s expenses.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can manage expenses for a specific user.
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function manageUserExpenses(User $user, int $targetUserId, int $clientId): bool
    {
        // User can always manage their own expenses
        if ($user->id === $targetUserId) {
            return true;
        }

        // TODO: Check if user has management rights over the target user
        // This requires UserPermissionService::canManageUser implementation
        return $this->canManageUser($user->id, $targetUserId, $clientId);
    }

    /**
     * Get the user's role.
     * TODO: Implement proper role lookup from users table or role relationship.
     *
     * @param \App\Models\User $user
     * @return string
     */
    private function getUserRole(User $user): string
    {
        // TODO: Implement role lookup from user model or related role table
        // Available roles: Primary Admin, Admin, Business User, Card User
        return 'Admin'; // Placeholder - should get actual role from database
    }

    /**
     * Check if user can manage the expense owner.
     * TODO: Implement delegation hierarchy check.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    private function canManageExpenseOwner(User $user, PocketExpense $expense): bool
    {
        // TODO: Implement UserPermissionService::canManageUser check
        return $this->canManageUser($user->id, $expense->user_id, $expense->client_id);
    }

    /**
     * Check if manager user can manage target user.
     * TODO: Implement actual permission check using UserPermissionService.
     *
     * @param int $managerId
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    private function canManageUser(int $managerId, int $targetUserId, int $clientId): bool
    {
        // TODO: Implement UserPermissionService::canManageUser($managerId, $targetUserId, $clientId)
        // This should check the user_feature_permission table for delegation hierarchy
        return false; // Placeholder - should check permission table
    }
}