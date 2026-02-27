## Code: app/Policies/PocketExpensePolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;

class PocketExpensePolicy
{
    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * Determine whether the user can view any pocket expenses.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Only authenticated users can view expenses
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Primary Admin has full access to all expenses
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin gets full access only to own expenses by default; needs explicit grant for others
        if ($user->isAdmin()) {
            return true;
        }

        // Business User and Card User can view expenses if they have permission
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the specific pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function view(User $user, PocketExpense $expense): bool
    {
        // Only authenticated users can view expenses
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($expense->client_id !== $user->client_id) {
            return false;
        }

        // Primary Admin has full access to all expenses within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Users can always view their own expenses
        if ($expense->user_id === $user->id) {
            return true;
        }

        // Admin can view expenses if they have managing access to the expense owner
        if ($user->isAdmin()) {
            return $this->canUserManageExpenseOwner($user, $expense);
        }

        // Business User and Card User can only view their own expenses
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $expense->user_id === $user->id;
        }

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
        // Only authenticated users can create expenses
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // All users with OOP expenses permission can create expenses
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
        // Only authenticated users can update expenses
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($expense->client_id !== $user->client_id) {
            return false;
        }

        // Cannot update approved or rejected expenses
        if (in_array($expense->status, ['approved', 'rejected'])) {
            return false;
        }

        // Primary Admin has full access to all expenses within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Users can always update their own expenses (if not approved/rejected)
        if ($expense->user_id === $user->id) {
            return true;
        }

        // Admin can update expenses if they have managing access to the expense owner
        if ($user->isAdmin()) {
            return $this->canUserManageExpenseOwner($user, $expense);
        }

        // Business User and Card User can only update their own expenses
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $expense->user_id === $user->id;
        }

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
        // Only authenticated users can delete expenses
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($expense->client_id !== $user->client_id) {
            return false;
        }

        // Cannot delete approved expenses
        if ($expense->status === 'approved') {
            return false;
        }

        // Primary Admin has full access to all expenses within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Users can always delete their own expenses (if not approved)
        if ($expense->user_id === $user->id) {
            return true;
        }

        // Admin can delete expenses if they have managing access to the expense owner
        if ($user->isAdmin()) {
            return $this->canUserManageExpenseOwner($user, $expense);
        }

        // Business User and Card User can only delete their own expenses
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return $expense->user_id === $user->id;
        }

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
        // Only authenticated users can approve expenses
        if (!$user) {
            return false;
        }

        // Check if user has OOP expenses feature permission
        if (!$this->hasOopExpensePermission($user)) {
            return false;
        }

        // Must belong to the same client
        if ($expense->client_id !== $user->client_id) {
            return false;
        }

        // Can only approve submitted expenses
        if ($expense->status !== 'submitted') {
            return false;
        }

        // Users cannot approve their own expenses
        if ($expense->user_id === $user->id) {
            return false;
        }

        // Business User and Card User cannot approve expenses even with management rights
        if ($user->isBusinessUser() || $user->isCardUser()) {
            return false;
        }

        // Primary Admin has full access to approve all expenses within their scope
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can approve expenses if they have managing access to the expense owner
        if ($user->isAdmin()) {
            return $this->canUserManageExpenseOwner($user, $expense);
        }

        return false;
    }

    /**
     * Determine whether the user can reject the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    public function reject(User $user, PocketExpense $expense): bool
    {
        // Same logic as approve -