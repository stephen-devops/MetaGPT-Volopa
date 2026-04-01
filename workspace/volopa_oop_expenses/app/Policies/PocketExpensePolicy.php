<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PocketExpensePolicy
 * 
 * Authorization policy for PocketExpense operations including approval rights.
 * Implements role-based access control with delegation capabilities where
 * Admins can grant access to their managed users.
 * 
 * Permission Rules:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own expenses by default; needs explicit grant for others
 * - Business User and Card User cannot approve expenses even with management rights
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to their own managed users (not all users)
 */
class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any pocket expenses.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Implement role checking when User role system is confirmed
        // For now, allow authenticated users to view expenses (will be scoped by other constraints)
        return true;
    }

    /**
     * Determine whether the user can view the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function view(User $user, PocketExpense $expense): bool
    {
        // Users can always view their own expenses
        if ($expense->user_id === $user->id) {
            return true;
        }

        // Check if user has explicit permission to manage this expense user
        if ($this->hasManagementPermission($user, $expense->user_id, $expense->client_id)) {
            return true;
        }

        // TODO: Implement Primary Admin role check when User role system is confirmed
        // Primary Admins have full access to all users by default
        
        return false;
    }

    /**
     * Determine whether the user can create pocket expenses.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // All authenticated users can create expenses (will be scoped by client_id)
        return true;
    }

    /**
     * Determine whether the user can update the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function update(User $user, PocketExpense $expense): bool
    {
        // Users can update their own expenses (with status restrictions)
        if ($expense->user_id === $user->id) {
            // Cannot update approved or rejected expenses
            return !in_array($expense->status, ['approved', 'rejected']);
        }

        // Check if user has explicit permission to manage this expense user
        if ($this->hasManagementPermission($user, $expense->user_id, $expense->client_id)) {
            return true;
        }

        // TODO: Implement Primary Admin role check when User role system is confirmed
        // Primary Admins have full access to all users by default
        
        return false;
    }

    /**
     * Determine whether the user can delete the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function delete(User $user, PocketExpense $expense): bool
    {
        // Users can delete their own expenses (with status restrictions)
        if ($expense->user_id === $user->id) {
            // Cannot delete approved expenses
            return $expense->status !== 'approved';
        }

        // Check if user has explicit permission to manage this expense user
        if ($this->hasManagementPermission($user, $expense->user_id, $expense->client_id)) {
            // Managers can delete non-approved expenses
            return $expense->status !== 'approved';
        }

        // TODO: Implement Primary Admin role check when User role system is confirmed
        // Primary Admins have full access to all users by default
        
        return false;
    }

    /**
     * Determine whether the user can approve the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function approve(User $user, PocketExpense $expense): bool
    {
        // Users cannot approve their own expenses
        if ($expense->user_id === $user->id) {
            return false;
        }

        // Only submitted expenses can be approved
        if ($expense->status !== 'submitted') {
            return false;
        }

        // TODO: Implement role checking when User role system is confirmed
        // Business User and Card User cannot approve expenses even with management rights
        // Only Primary Admin and Admin roles can approve expenses
        
        // Check if user has explicit permission to manage this expense user
        if ($this->hasManagementPermission($user, $expense->user_id, $expense->client_id)) {
            // TODO: Add role check to ensure user has approval rights (not Business User or Card User)
            return true;
        }

        // TODO: Implement Primary Admin role check when User role system is confirmed
        // Primary Admins have full access to all users by default
        
        return false;
    }

    /**
     * Check if the user has management permission for the target user in the given client context.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    protected function hasManagementPermission(User $user, int $targetUserId, int $clientId): bool
    {
        // TODO: Implement feature ID lookup when Feature model is confirmed
        // For now, using feature_id = 16 (OOP Expense) as per system constraints
        $featureId = 16;

        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('manager_user_id', $targetUserId)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Determine if the user has admin-level permissions.
     * 
     * @param User $user
     * @return bool
     */
    protected function isAdmin(User $user): bool
    {
        // TODO: Implement when User role system is confirmed
        // Should check if user has Primary Admin or Admin role
        return false;
    }

    /**
     * Determine if the user has primary admin permissions.
     * 
     * @param User $user
     * @return bool
     */
    protected function isPrimaryAdmin(User $user): bool
    {
        // TODO: Implement when User role system is confirmed
        // Primary Admin has full access to all users by default
        return false;
    }

    /**
     * Determine if the user can approve expenses based on their role.
     * 
     * @param User $user
     * @return bool
     */
    protected function canApproveExpenses(User $user): bool
    {
        // TODO: Implement when User role system is confirmed
        // Business User and Card User cannot approve expenses even with management rights
        // Only Primary Admin and Admin roles can approve expenses
        return false;
    }

    /**
     * Check if the user belongs to the same client as the expense.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    protected function belongsToSameClient(User $user, PocketExpense $expense): bool
    {
        // TODO: Implement when User-Client relationship is confirmed
        // Should check if user belongs to the same client as the expense
        return true; // Placeholder - assume same client for now
    }
}