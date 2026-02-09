## Code: app/Policies/ExpensePolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\OopExpense;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Feature ID for out-of-pocket expenses.
     */
    const OOP_EXPENSE_FEATURE_ID = 1;

    /**
     * Determine whether the user can view any expenses.
     */
    public function viewAny(User $user): bool
    {
        // Users can always view their own expenses if they have the feature enabled
        return $this->hasOopExpenseFeature($user);
    }

    /**
     * Determine whether the user can view the expense.
     */
    public function view(User $user, OopExpense $expense): bool
    {
        // Users can view their own expenses
        if ($expense->belongsToUser($user->id)) {
            return $this->hasOopExpenseFeature($user);
        }

        // Check if user has permission to manage expenses for the expense owner
        return $this->canManageUserExpenses($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can create expenses.
     */
    public function create(User $user): bool
    {
        return $this->hasOopExpenseFeature($user);
    }

    /**
     * Determine whether the user can create expenses for another user.
     */
    public function createForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Users can always create expenses for themselves
        if ($targetUserId === $user->id) {
            return $this->hasOopExpenseFeature($user);
        }

        // Check if user has permission to manage expenses for the target user
        return $this->canManageUserExpenses($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can update the expense.
     */
    public function update(User $user, OopExpense $expense): bool
    {
        // Can only update pending expenses
        if (!$expense->canBeUpdated()) {
            return false;
        }

        // Users can update their own expenses
        if ($expense->belongsToUser($user->id)) {
            return $this->hasOopExpenseFeature($user);
        }

        // Check if user has permission to manage expenses for the expense owner
        return $this->canManageUserExpenses($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can delete the expense.
     */
    public function delete(User $user, OopExpense $expense): bool
    {
        // Can only delete pending expenses
        if (!$expense->canBeDeleted()) {
            return false;
        }

        // Users can delete their own expenses
        if ($expense->belongsToUser($user->id)) {
            return $this->hasOopExpenseFeature($user);
        }

        // Check if user has permission to manage expenses for the expense owner
        return $this->canManageUserExpenses($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can approve the expense.
     */
    public function approve(User $user, OopExpense $expense): bool
    {
        // Can only approve pending expenses
        if (!$expense->canBeApproved()) {
            return false;
        }

        // Users cannot approve their own expenses
        if ($expense->belongsToUser($user->id)) {
            return false;
        }

        // Check if user has permission to manage expenses for the expense owner
        return $this->canManageUserExpenses($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can reject the expense.
     */
    public function reject(User $user, OopExpense $expense): bool
    {
        // Can only reject pending expenses
        if (!$expense->canBeRejected()) {
            return false;
        }

        // Users cannot reject their own expenses
        if ($expense->belongsToUser($user->id)) {
            return false;
        }

        // Check if user has permission to manage expenses for the expense owner
        return $this->canManageUserExpenses($user, $expense->user_id, $expense->client_id);
    }

    /**
     * Determine whether the user can upload CSV files for expenses.
     */
    public function uploadCsv(User $user): bool
    {
        return $this->hasOopExpenseFeature($user);
    }

    /**
     * Determine whether the user can upload CSV files for another user's expenses.
     */
    public function uploadCsvForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Users can always upload for themselves
        if ($targetUserId === $user->id) {
            return $this->hasOopExpenseFeature($user);
        }

        // Check if user has permission to manage expenses for the target user
        return $this->canManageUserExpenses($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can view upload status.
     */
    public function viewUploadStatus(User $user, int $uploadUserId, int $clientId): bool
    {
        // Users can view their own upload status
        if ($uploadUserId === $user->id) {
            return $this->hasOopExpenseFeature($user);
        }

        // Check if user has permission to manage expenses for the upload user
        return $this->canManageUserExpenses($user, $uploadUserId, $clientId);
    }

    /**
     * Determine whether the user can view expenses for a specific client.
     */
    public function viewClientExpenses(User $user, int $clientId): bool
    {
        // Check if user has the OOP expense feature for this client
        return $this->hasOopExpenseFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can manage expenses for a specific user within a client.
     */
    public function manageUserExpenses(User $user, int $targetUserId, int $clientId): bool
    {
        return $this->canManageUserExpenses($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can view expense reports for a client.
     */
    public function viewExpenseReports(User $user, int $clientId): bool
    {
        return $this->canManageUserExpenses($user, null, $clientId) || 
               $this->hasOopExpenseFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can export expense data.
     */
    public function exportExpenses(User $user, int $clientId): bool
    {
        return $this->hasOopExpenseFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can view expense analytics.
     */
    public function viewAnalytics(User $user, int $clientId): bool
    {
        return $this->canManageUserExpenses($user, null, $clientId) || 
               $this->hasOopExpenseFeatureForClient($user, $clientId);
    }

    /**
     * Determine whether the user can bulk approve expenses.
     */
    public function bulkApprove(User $user, int $clientId): bool
    {
        return $this->canManageUserExpenses($user, null, $clientId);
    }

    /**
     * Determine whether the user can bulk reject expenses.
     */
    public function bulkReject(User $user, int $clientId): bool
    {
        return $this->canManageUserExpenses($user, null, $clientId);
    }

    /**
     * Determine whether the user can configure expense settings.
     */
    public function configureExpenseSettings(User $user, int $clientId): bool
    {
        return $this->canManageUserExpenses($user, null, $clientId);
    }

    /**
     * Check if user has the out-of-pocket expense feature enabled.
     */
    protected function hasOopExpenseFeature(User $user): bool
    {
        // Get all clients where user has the OOP expense feature
        $hasFeature = UserFeaturePermission::forUser($user->id)
            ->forFeature(self::OOP_EXPENSE_FEATURE_ID)
            ->enabled()
            ->exists();

        return $hasFeature;
    }

    /**
     * Check if user has the out-of-pocket expense feature for a specific client.
     */
    protected function hasOopExpenseFeatureForClient(User $user, int $clientId): bool
    {
        return UserFeaturePermission::hasPermission($user->id, $clientId, self::OOP_EXPENSE_FEATURE_ID);
    }

    /**
     * Check if user can manage expenses for another user within a client.
     * This includes having manager permissions or being granted administrative access.
     */
    protected function canManageUserExpenses(User $user, ?int $targetUserId, int $clientId): bool
    {
        // First, check if user has the OOP expense feature for this client
        if (!$this->hasOopExpenseFeatureForClient($user, $clientId)) {
            return false;
        }

        // If no target user specified, check for general management permissions
        if ($targetUserId === null) {
            return $this->hasManagementPermissions($user, $clientId);
        }

        // Users can always manage their own expenses
        if ($targetUserId === $user->id) {
            return true;
        }

        // Check if user is a manager for the target user
        return $this->isManagerForUser($user, $targetUserId, $clientId);
    }

    /**
     * Check if user has management permissions for a client.
     */
    protected function hasManagementPermissions(User $user, int $clientId): bool
    {
        // Check if user is a manager for any user in this client for the OOP expense feature
        $hasManagementRole = UserFeaturePermission::forClient($clientId)
            ->forFeature(self::OOP_EXPENSE_FEATURE_ID)
            ->managedBy($user->id)
            ->enabled()
            ->exists();

        return $hasManagementRole;
    }

    /**
     * Check if user is a manager for a specific target user.
     */
    protected function isManagerForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Check if the user is assigned as manager for the target user's OOP expense permission
        $isManager = UserFeaturePermission::forUserAndClient($targetUserId, $clientId)
            ->forFeature(self::OOP_EXPENSE_FEATURE_ID)
            ->managedBy($user->id)
            ->enabled()
            ->exists();

        return $isManager;
    }

    /**
     * Check if user can access expense data for a specific date range.
     */
    public function viewExpensesInDateRange(User $user, int $clientId, string $startDate, string $endDate): bool
    {
        // Basic permission check first
        if (!$this->hasOopExpenseFeatureForClient($user, $clientId)) {
            return false;
        }

        // Additional date range restrictions could be implemented here
        // For now, if user has access to client expenses, they can view any date range
        return true;
    }

    /**
     * Check if user can filter expenses by specific criteria.
     */
    public function filterExpenses(User $user, int $clientId, array $filters = []): bool
    {
        if (!$this->hasOopExpenseFeatureForClient($user, $clientId)) {
            return false;
        }

        // Additional filter-specific restrictions could be implemented here
        // For example, restrict viewing certain expense categories or amounts
        return true;
    }

    /**
     * Check if user can view expense attachments/receipts.
     */
    public function viewExpenseAttachments(User $user, OopExpense $expense): bool
    {
        // Users can view attachments if they can view the expense
        return $this->view($user, $expense);
    }

    /**
     * Check if user can upload expense receipts/attachments.
     */
    public function uploadExpenseAttachments(User $user, OopExpense $expense): bool
    {
        // Users can upload attachments if they can update the expense
        return $this->update($user, $expense);
    }

    /**
     * Check if user can delete expense attachments.
     */
    public function deleteExpenseAttachments(User $user, OopExpense $expense): bool
    {
        // Users can delete attachments if they can update the expense
        return $this->update($user, $expense);
    }

    /**
     * Check if user can view expense history/audit trail.
     */
    public function viewExpenseHistory(User $user, OopExpense $expense): bool
    {
        // Users can view history if they can view the expense
        return $this->view($user, $expense);
    }

    /**
     * Check if user can add comments/notes to expenses.
     */
    public function addExpenseComments(User $user, OopExpense $expense): bool
    {
        // Users can add comments if they can view the expense
        return $this->view($user, $expense);
    }

    /**
     * Check if user can edit comments/notes on expenses.
     */
    public function editExpenseComments(User $user, OopExpense $expense): bool
    {
        // Users can edit comments if they can update the expense
        return $this->update($user, $expense);
    }

    /**
     * Check if user can delete comments/notes from expenses.
     */
    public function deleteExpenseComments(User $user, OopExpense $expense): bool
    {
        // Users can delete comments if they can update the expense
        return $this->update($user, $expense);
    }

    /**
     * Check if user can duplicate an expense.
     */
    public function duplicate(User $user, OopExpense $expense): bool
    {
        // Users can duplicate if they can view the original and create new expenses
        return $this->view($user, $expense) && $this->create($user);
    }

    /**
     * Check if user can resubmit a rejected expense.
     */
    public function resubmit(User $user, OopExpense $expense): bool
    {
        // Only the expense owner can resubmit rejected expenses
        if (!$expense->belongsToUser($user->id)) {
            return false;
        }

        // Can only resubmit rejected expenses
        if (!$expense->isRejected()) {
            return false;
        }

        return $this->hasOopExpenseFeature($user);
    }

    /**
     * Check if user can withdraw/cancel a pending expense.
     */
    public function withdraw(User $user, OopExpense $expense): bool
    {
        // Only the expense owner can withdraw pending expenses
        if (!$expense->belongsToUser($user->id)) {
            return false;
        }

        // Can only withdraw pending expenses
        if (!$expense->isPending()) {
            return false;
        }

        return $this->hasOopExpenseFeature($user);
    }

    /**
     * Check if user can view expense statistics and summaries.
     */
    public function viewExpenseStats(User $user, int $clientId): bool
    {
        return $this->hasOopExpenseFeatureForClient($user, $clientId);
    }

    /**
     * Check if user can access advanced expense features.
     */
    public function accessAdvancedFeatures(User $user, int $clientId): bool
    {
        // Advanced features might require management permissions
        return $this->hasManagementPermissions($user, $clientId);
    }

    /**
     * Before hook for all policy methods.
     */
    public function before(User $user, string $ability): ?bool
    {
        // Super administrators can do everything
        if ($user->hasRole('super