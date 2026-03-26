<?php

namespace App\Policies;

use App\Models\PocketExpense;
use App\Models\User;
use App\Models\UserFeaturePermission;

/**
 * Pocket Expense Policy
 * 
 * Authorization policy for expense CRUD operations.
 * Implements permission-based access control with client scoping and user role management.
 * Handles delegation through UserFeaturePermission model.
 */
class PocketExpensePolicy
{
    /**
     * The OOP Expense feature ID as per system constraints.
     */
    const OOP_EXPENSE_FEATURE_ID = 16;

    /**
     * Determine whether the user can view any expenses.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Implement client context check - user must belong to a client
        // TODO: Check if client has OOP feature enabled (feature_id = 16)
        
        // Primary Admin has full access to all users by default
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // Admin gets full access only to own expenses by default; needs explicit grant for others
        if ($this->isAdmin($user)) {
            return true; // Can view own expenses, delegation handled in view() method
        }
        
        // Business User and Card User can view their own expenses
        if ($this->isBusinessUser($user) || $this->isCardUser($user)) {
            return true; // Can view own expenses, scoped in controller
        }
        
        return false;
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
        // Check if expense belongs to same client as user
        if (!$this->belongsToSameClient($user, $expense)) {
            return false;
        }
        
        // Primary Admin has full access to all users by default
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // User can always view their own expenses
        if ($expense->user_id === $user->id) {
            return true;
        }
        
        // Admin can view expenses of users they manage via explicit grants
        if ($this->isAdmin($user)) {
            return $this->hasManagementPermission($user, $expense->user_id, $expense->client_id);
        }
        
        // Business User and Card User cannot view other users' expenses
        return false;
    }

    /**
     * Determine whether the user can create expenses.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // TODO: Check if client has OOP feature enabled (feature_id = 16)
        
        // All user roles can create expenses for themselves or managed users
        return $this->isPrimaryAdmin($user) || 
               $this->isAdmin($user) || 
               $this->isBusinessUser($user) || 
               $this->isCardUser($user);
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
        // Check if expense belongs to same client as user
        if (!$this->belongsToSameClient($user, $expense)) {
            return false;
        }
        
        // Only draft expenses can be updated per business rules
        if (!$expense->canEdit()) {
            return false;
        }
        
        // Primary Admin has full access
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // User can always update their own draft expenses
        if ($expense->user_id === $user->id) {
            return true;
        }
        
        // Admin can update expenses of users they manage via explicit grants
        if ($this->isAdmin($user)) {
            return $this->hasManagementPermission($user, $expense->user_id, $expense->client_id);
        }
        
        // Business User and Card User cannot update other users' expenses
        return false;
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
        // Check if expense belongs to same client as user
        if (!$this->belongsToSameClient($user, $expense)) {
            return false;
        }
        
        // Only draft expenses can be deleted per business rules
        if (!$expense->canDelete()) {
            return false;
        }
        
        // Primary Admin has full access
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // User can always delete their own draft expenses
        if ($expense->user_id === $user->id) {
            return true;
        }
        
        // Admin can delete expenses of users they manage via explicit grants
        if ($this->isAdmin($user)) {
            return $this->hasManagementPermission($user, $expense->user_id, $expense->client_id);
        }
        
        // Business User and Card User cannot delete other users' expenses
        return false;
    }

    /**
     * Determine whether the user can approve expenses.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    public function approve(User $user, PocketExpense $expense): bool
    {
        // Check if expense belongs to same client as user
        if (!$this->belongsToSameClient($user, $expense)) {
            return false;
        }
        
        // Only submitted expenses can be approved
        if (!$expense->canApprove()) {
            return false;
        }
        
        // Business User and Card User cannot approve expenses even with management rights
        if ($this->isBusinessUser($user) || $this->isCardUser($user)) {
            return false;
        }
        
        // Primary Admin can approve all expenses
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // Admin can approve expenses of users they manage via explicit grants
        if ($this->isAdmin($user)) {
            // Users cannot approve their own expenses (business rule)
            if ($expense->user_id === $user->id) {
                return false;
            }
            
            return $this->hasManagementPermission($user, $expense->user_id, $expense->client_id);
        }
        
        return false;
    }

    /**
     * Determine whether the user can reject expenses.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    public function reject(User $user, PocketExpense $expense): bool
    {
        // Same rules as approve
        return $this->approve($user, $expense);
    }

    /**
     * Determine whether the user can manage expenses for another user.
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    public function manage(User $user, int $targetUserId, int $clientId): bool
    {
        // Primary Admin has full access to all users by default
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // User can always manage their own expenses
        if ($targetUserId === $user->id) {
            return true;
        }
        
        // Check for explicit management permission
        return $this->hasManagementPermission($user, $targetUserId, $clientId);
    }

    /**
     * Check if user is Primary Admin.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        // TODO: Implement role check based on User model role field or relationship
        // This should check the user's role against platform role system
        return false;
    }

    /**
     * Check if user is Admin.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        // TODO: Implement role check based on User model role field or relationship
        // This should check the user's role against platform role system
        return false;
    }

    /**
     * Check if user is Business User.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        // TODO: Implement role check based on User model role field or relationship
        // This should check the user's role against platform role system
        return false;
    }

    /**
     * Check if user is Card User.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        // TODO: Implement role check based on User model role field or relationship
        // This should check the user's role against platform role system
        return false;
    }

    /**
     * Check if user belongs to same client as the expense.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpense $expense
     * @return bool
     */
    private function belongsToSameClient(User $user, PocketExpense $expense): bool
    {
        // TODO: Implement client membership check
        // This should verify that user belongs to the same client as the expense
        // Need to check User model for client_id field or relationship
        return true; // Placeholder - implement based on User model structure
    }

    /**
     * Check if user has explicit management permission for target user in client context.
     *
     * @param \App\Models\User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    private function hasManagementPermission(User $user, int $targetUserId, int $clientId): bool
    {
        // Check if there's an active UserFeaturePermission granting management access
        return UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('feature_id', self::OOP_EXPENSE_FEATURE_ID)
            ->where('manager_user_id', $user->id)
            ->where('is_enabled', true)
            ->exists();
    }
}