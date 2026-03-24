<?php

namespace App\Policies;

use App\Models\PocketExpense;
use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Policy for PocketExpense model authorization
 * 
 * Handles authorization for pocket expense operations based on user roles,
 * feature permissions, and management hierarchy. Implements the constraint
 * that Primary Admin has full access, Admin gets access only to own expenses
 * by default unless granted management rights, and Business User/Card User
 * cannot approve expenses even with management rights.
 */
class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any pocket expenses.
     * 
     * @param User $user
     * @return Response|bool
     */
    public function viewAny(User $user): Response|bool
    {
        // Check if user has OOP Expense feature permission for their client
        $hasFeaturePermission = $this->hasOopExpenseFeature($user);
        
        if (!$hasFeaturePermission) {
            return Response::deny('You do not have permission to access expense management.');
        }
        
        // All users with OOP Expense feature can view expenses (scoped by their access level)
        return Response::allow();
    }

    /**
     * Determine whether the user can view a specific pocket expense.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function view(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to access expense management.');
        }
        
        // Check client context - expenses must be in same client
        if ($expense->client_id !== $user->client_id) {
            return Response::deny('You cannot access expenses from other organizations.');
        }
        
        // Primary Admin has full access to all users by default
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Users can always view their own expenses
        if ($expense->user_id === $user->id) {
            return Response::allow();
        }
        
        // Admin can view expenses of users they manage
        if ($this->isAdmin($user) && $this->canManageUser($user, $expense->user_id)) {
            return Response::allow();
        }
        
        // Business User and Card User can only view their own expenses
        return Response::deny('You can only view your own expenses.');
    }

    /**
     * Determine whether the user can create pocket expenses.
     * 
     * @param User $user
     * @return Response|bool
     */
    public function create(User $user): Response|bool
    {
        // Check if user has OOP Expense feature permission
        $hasFeaturePermission = $this->hasOopExpenseFeature($user);
        
        if (!$hasFeaturePermission) {
            return Response::deny('You do not have permission to create expenses.');
        }
        
        // All users with feature permission can create expenses
        return Response::allow();
    }

    /**
     * Determine whether the user can update a specific pocket expense.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function update(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to update expenses.');
        }
        
        // Check client context
        if ($expense->client_id !== $user->client_id) {
            return Response::deny('You cannot update expenses from other organizations.');
        }
        
        // Cannot update approved or rejected expenses
        if (in_array($expense->status, ['approved', 'rejected'])) {
            return Response::deny('You cannot update expenses that have been approved or rejected.');
        }
        
        // Primary Admin has full access
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Users can update their own expenses if in draft or submitted status
        if ($expense->user_id === $user->id) {
            return Response::allow();
        }
        
        // Admin can update expenses of users they manage
        if ($this->isAdmin($user) && $this->canManageUser($user, $expense->user_id)) {
            return Response::allow();
        }
        
        // Business User and Card User can only update their own expenses
        return Response::deny('You can only update your own expenses.');
    }

    /**
     * Determine whether the user can delete a specific pocket expense.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function delete(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to delete expenses.');
        }
        
        // Check client context
        if ($expense->client_id !== $user->client_id) {
            return Response::deny('You cannot delete expenses from other organizations.');
        }
        
        // Cannot delete approved expenses
        if ($expense->status === 'approved') {
            return Response::deny('You cannot delete approved expenses.');
        }
        
        // Primary Admin has full access
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Users can delete their own expenses if not approved
        if ($expense->user_id === $user->id) {
            return Response::allow();
        }
        
        // Admin can delete expenses of users they manage (if not approved)
        if ($this->isAdmin($user) && $this->canManageUser($user, $expense->user_id)) {
            return Response::allow();
        }
        
        // Business User and Card User can only delete their own expenses
        return Response::deny('You can only delete your own expenses.');
    }

    /**
     * Determine whether the user can approve a specific pocket expense.
     * 
     * Constraint: Business User and Card User cannot approve expenses even with management rights.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function approve(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to approve expenses.');
        }
        
        // Check client context
        if ($expense->client_id !== $user->client_id) {
            return Response::deny('You cannot approve expenses from other organizations.');
        }
        
        // Only submitted expenses can be approved
        if ($expense->status !== 'submitted') {
            return Response::deny('Only submitted expenses can be approved.');
        }
        
        // Users cannot approve their own expenses
        if ($expense->user_id === $user->id) {
            return Response::deny('You cannot approve your own expenses.');
        }
        
        // Business User and Card User cannot approve expenses even with management rights
        if ($this->isBusinessUser($user) || $this->isCardUser($user)) {
            return Response::deny('Business Users and Card Users cannot approve expenses.');
        }
        
        // Primary Admin has full approval access
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Admin can approve expenses of users they manage
        if ($this->isAdmin($user) && $this->canManageUser($user, $expense->user_id)) {
            return Response::allow();
        }
        
        return Response::deny('You do not have permission to approve this expense.');
    }

    /**
     * Determine whether the user can reject a specific pocket expense.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function reject(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to reject expenses.');
        }
        
        // Check client context
        if ($expense->client_id !== $user->client_id) {
            return Response::deny('You cannot reject expenses from other organizations.');
        }
        
        // Only submitted expenses can be rejected
        if ($expense->status !== 'submitted') {
            return Response::deny('Only submitted expenses can be rejected.');
        }
        
        // Users cannot reject their own expenses
        if ($expense->user_id === $user->id) {
            return Response::deny('You cannot reject your own expenses.');
        }
        
        // Business User and Card User cannot reject expenses even with management rights
        if ($this->isBusinessUser($user) || $this->isCardUser($user)) {
            return Response::deny('Business Users and Card Users cannot reject expenses.');
        }
        
        // Primary Admin has full rejection access
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Admin can reject expenses of users they manage
        if ($this->isAdmin($user) && $this->canManageUser($user, $expense->user_id)) {
            return Response::allow();
        }
        
        return Response::deny('You do not have permission to reject this expense.');
    }

    /**
     * Determine whether the user can submit an expense for approval.
     * 
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function submit(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to submit expenses.');
        }
        
        // Check client context
        if ($expense->client_id !== $user->client_id) {
            return Response::deny('You cannot submit expenses from other organizations.');
        }
        
        // Only draft expenses can be submitted
        if ($expense->status !== 'draft') {
            return Response::deny('Only draft expenses can be submitted for approval.');
        }
        
        // Primary Admin has full access
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Users can submit their own expenses
        if ($expense->user_id === $user->id) {
            return Response::allow();
        }
        
        // Admin can submit expenses of users they manage
        if ($this->isAdmin($user) && $this->canManageUser($user, $expense->user_id)) {
            return Response::allow();
        }
        
        return Response::deny('You can only submit your own expenses or expenses of users you manage.');
    }

    /**
     * Check if the user has OOP Expense feature permission.
     * 
     * @param User $user
     * @return bool
     */
    private function hasOopExpenseFeature(User $user): bool
    {
        // OOP Expense is feature_id = 16 as per constraints
        $permission = UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', 16)
            ->where('is_enabled', 1)
            ->first();
        
        return $permission !== null;
    }

    /**
     * Check if the user is a Primary Admin.
     * 
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        // This would typically check a role field or relationship
        // For now, assume role is stored in user model or related table
        return $user->role === 'primary_admin';
    }

    /**
     * Check if the user is an Admin.
     * 
     * @param User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Check if the user is a Business User.
     * 
     * @param User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        return $user->role === 'business_user';
    }

    /**
     * Check if the user is a Card User.
     * 
     * @param User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        return $user->role === 'card_user';
    }

    /**
     * Check if the user can manage another user.
     * 
     * Based on UserFeaturePermission with manager_user_id relationships.
     * 
     * @param User $user The manager user
     * @param int $targetUserId The user to be managed
     * @return bool
     */
    private function canManageUser(User $user, int $targetUserId): bool
    {
        // Primary Admin can manage all users by default
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }
        
        // Check if there's an explicit management permission where this user is the manager
        $managementPermission = UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $user->client_id)
            ->where('manager_user_id', $user->id)
            ->where('is_enabled', 1)
            ->first();
        
        return $managementPermission !== null;
    }

    /**
     * Determine whether the user can manage expenses for other users.
     * 
     * @param User $user
     * @return Response|bool
     */
    public function manageOthers(User $user): Response|bool
    {
        // Check if user has OOP Expense feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to access expense management.');
        }
        
        // Primary Admin can always manage others
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Admin can manage others if they have explicit management permissions
        if ($this->isAdmin($user)) {
            $hasManagementRights = UserFeaturePermission::where('manager_user_id', $user->id)
                ->where('client_id', $user->client_id)
                ->where('is_enabled', 1)
                ->exists();
            
            if ($hasManagementRights) {
                return Response::allow();
            }
        }
        
        // Business User and Card User cannot manage others even with management rights for approval
        return Response::deny('You do not have permission to manage expenses for other users.');
    }

    /**
     * Determine whether the user can upload CSV files for expense batch processing.
     * 
     * @param User $user
     * @return Response|bool
     */
    public function uploadBatch(User $user): Response|bool
    {
        // Check if user has OOP Expense feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to upload expense batches.');
        }
        
        // Primary Admin can upload for any user
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }
        
        // Admin can upload for users they manage
        if ($this->isAdmin($user)) {
            return Response::allow();
        }
        
        // Business User and Card User typically cannot do batch uploads
        return Response::deny('You do not have permission to upload expense batches.');
    }

    /**
     * Determine whether the user can view expense upload history.
     * 
     * @param User $user
     * @return Response|bool
     */
    public function viewUploads(User $user): Response|bool
    {
        // Check if user has OOP Expense feature permission
        if (!$this->hasOopExpenseFeature($user)) {
            return Response::deny('You do not have permission to view upload history.');
        }
        
        // All users with feature permission can view uploads (scoped by their access level)
        return Response::allow();
    }
}