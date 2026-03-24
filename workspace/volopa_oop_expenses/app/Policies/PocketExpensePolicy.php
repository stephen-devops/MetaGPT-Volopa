<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy class for PocketExpense authorization.
 * 
 * Implements hierarchical RBAC based on user roles and delegation rights:
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
     * OOP Expenses feature ID as per system constraints.
     */
    private const OOP_EXPENSES_FEATURE_ID = 16;

    /**
     * User roles that have approval capabilities.
     */
    private const APPROVAL_CAPABLE_ROLES = ['Primary Admin', 'Admin'];

    /**
     * Determine whether the user can view any expenses.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // User must have OOP Expenses feature enabled for their client
        return $this->hasOopExpensesAccess($user);
    }

    /**
     * Determine whether the user can view the expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function view(User $user, PocketExpense $expense): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Must be in the same client context
        if ($user->client_id !== $expense->client_id) {
            return false;
        }

        // Can view own expenses
        if ($user->id === $expense->user_id) {
            return true;
        }

        // Primary Admin has access to all expenses in their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if user has explicit permission to manage this expense's owner
        return $this->canManageUser($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can create expenses.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // User must have OOP Expenses feature enabled
        return $this->hasOopExpensesAccess($user);
    }

    /**
     * Determine whether the user can update the expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function update(User $user, PocketExpense $expense): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Must be in the same client context
        if ($user->client_id !== $expense->client_id) {
            return false;
        }

        // Cannot update deleted expenses
        if ($expense->deleted) {
            return false;
        }

        // Cannot update approved expenses unless Primary Admin
        if ($expense->status === 'approved' && !$this->isPrimaryAdmin($user)) {
            return false;
        }

        // Can update own expenses (if not approved)
        if ($user->id === $expense->user_id) {
            return true;
        }

        // Primary Admin can update any expense in their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if user has explicit permission to manage this expense's owner
        return $this->canManageUser($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can delete the expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function delete(User $user, PocketExpense $expense): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Must be in the same client context
        if ($user->client_id !== $expense->client_id) {
            return false;
        }

        // Cannot delete already deleted expenses
        if ($expense->deleted) {
            return false;
        }

        // Cannot delete approved expenses unless Primary Admin
        if ($expense->status === 'approved' && !$this->isPrimaryAdmin($user)) {
            return false;
        }

        // Can delete own expenses (if not approved)
        if ($user->id === $expense->user_id) {
            return true;
        }

        // Primary Admin can delete any expense in their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if user has explicit permission to manage this expense's owner
        return $this->canManageUser($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can approve the expense.
     * 
     * Business User and Card User cannot approve expenses even with management rights.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function approve(User $user, PocketExpense $expense): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Must be in the same client context
        if ($user->client_id !== $expense->client_id) {
            return false;
        }

        // Cannot approve deleted expenses
        if ($expense->deleted) {
            return false;
        }

        // Cannot approve own expenses
        if ($user->id === $expense->user_id) {
            return false;
        }

        // Only certain roles can approve expenses
        if (!in_array($user->role, self::APPROVAL_CAPABLE_ROLES, true)) {
            return false;
        }

        // Expense must be in submitted status to be approved
        if ($expense->status !== 'submitted') {
            return false;
        }

        // Primary Admin can approve any submitted expense in their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can only approve expenses for users they manage
        if ($user->role === 'Admin') {
            return $this->canManageUser($user, $expense->user_id, $expense->client_id);
        }

        return false;
    }

    /**
     * Determine whether the user can reject the expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function reject(User $user, PocketExpense $expense): bool
    {
        // Rejection follows the same rules as approval
        return $this->approve($user, $expense);
    }

    /**
     * Determine whether the user can restore a soft-deleted expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function restore(User $user, PocketExpense $expense): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Must be in the same client context
        if ($user->client_id !== $expense->client_id) {
            return false;
        }

        // Can only restore deleted expenses
        if (!$expense->deleted) {
            return false;
        }

        // Primary Admin can restore any expense in their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Can restore own expenses
        if ($user->id === $expense->user_id) {
            return true;
        }

        // Check if user has explicit permission to manage this expense's owner
        return $this->canManageUser($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can force delete the expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function forceDelete(User $user, PocketExpense $expense): bool
    {
        // Only Primary Admin can force delete expenses
        return $this->isPrimaryAdmin($user) && 
               $user->client_id === $expense->client_id;
    }

    /**
     * Determine whether the user can create expenses for another user.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function createForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Must be in the same client context
        if ($user->client_id !== $clientId) {
            return false;
        }

        // Can always create for self
        if ($user->id === $targetUserId) {
            return true;
        }

        // Primary Admin can create for any user in their client
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if user has explicit permission to manage the target user
        return $this->canManageUser($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can upload CSV files for another user.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function uploadCsvForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // CSV upload follows the same rules as creating expenses for another user
        return $this->createForUser($user, $targetUserId, $clientId);
    }

    /**
     * Check if the user has OOP Expenses feature access.
     *
     * @param User $user
     * @return bool
     */
    private function hasOopExpensesAccess(User $user): bool
    {
        // Check if user has an active UserFeaturePermission for OOP Expenses
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_EXPENSES_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if the user is a Primary Admin.
     *
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        return $user->role === 'Primary Admin';
    }

    /**
     * Check if the user can manage another user based on explicit permissions.
     * 
     * Admin can only grant access to their own managed users (not all users).
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    private function canManageUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Check if there's an explicit permission granting this user management rights
        // over the target user for the OOP Expenses feature
        return UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('feature_id', self::OOP_EXPENSES_FEATURE_ID)
            ->where('manager_user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Determine if the user can manage expenses in general (for UI display).
     * This is used to show/hide management interfaces.
     *
     * @param User $user
     * @return bool
     */
    public function manageAny(User $user): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Primary Admin can always manage
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Check if user has any management permissions
        return UserFeaturePermission::where('manager_user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_EXPENSES_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Determine if the user can view expense reports/analytics.
     *
     * @param User $user
     * @return bool
     */
    public function viewReports(User $user): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Primary Admin can view all reports
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin role can view reports for users they manage
        if ($user->role === 'Admin') {
            return $this->manageAny($user);
        }

        // Other roles can only view their own expense data
        return true;
    }

    /**
     * Determine if the user can export expense data.
     *
     * @param User $user
     * @return bool
     */
    public function export(User $user): bool
    {
        // Export follows the same rules as viewing reports
        return $this->viewReports($user);
    }

    /**
     * Determine if the user can view expense audit logs.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function viewAuditLog(User $user, PocketExpense $expense): bool
    {
        // Must be able to view the expense first
        if (!$this->view($user, $expense)) {
            return false;
        }

        // Primary Admin can view all audit logs
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can view audit logs for expenses they can manage
        if ($user->role === 'Admin') {
            return $this->canManageUser($user, $expense->user_id, $expense->client_id);
        }

        // Users can view audit logs for their own expenses
        return $user->id === $expense->user_id;
    }

    /**
     * Check if user has permission to perform bulk operations.
     *
     * @param User $user
     * @return bool
     */
    public function bulkOperations(User $user): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // Primary Admin can perform bulk operations
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin can perform bulk operations if they manage any users
        if ($user->role === 'Admin') {
            return $this->manageAny($user);
        }

        return false;
    }

    /**
     * Determine if user can access advanced expense features.
     * This includes FX conversion, advanced metadata, etc.
     *
     * @param User $user
     * @return bool
     */
    public function advancedFeatures(User $user): bool
    {
        // Must have OOP Expenses access
        if (!$this->hasOopExpensesAccess($user)) {
            return false;
        }

        // All roles with OOP access get advanced features
        return true;
    }

    /**
     * Before hook - called before any policy method.
     * 
     * @param User $user
     * @param string $ability
     * @return bool|null
     */
    public function before(User $user, string $ability): ?bool
    {
        // Super admin bypass (if your system has one)
        // Uncomment if you have a super admin role that bypasses all policies
        // if ($user->role === 'Super Admin') {
        //     return true;
        // }

        // Allow the policy methods to run
        return null;
    }
}