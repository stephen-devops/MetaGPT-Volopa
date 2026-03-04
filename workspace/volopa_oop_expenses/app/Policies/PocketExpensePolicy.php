## Code: app/Policies/PocketExpensePolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * PocketExpensePolicy
 * 
 * Authorization policy for pocket expense operations with role-based access control.
 * Implements multi-tenant security with delegation-based permission management.
 * 
 * Business Rules:
 * - Only Primary Administrator has full access to all users' expenses by default
 * - Administrator requires explicit delegation to manage other users' expenses
 * - Business User and Card User cannot approve expenses even with management rights
 * - All queries and mutations must be scoped by client_id for multi-tenancy
 * - user_id must match authenticated user (server-side validation)
 * - Target entities (expense_user_id) must belong to the same client_id
 * - Managing access enables create/view/edit but approval rights depend on original user role
 */
class PocketExpensePolicy
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
     * Feature ID for pocket expense management.
     *
     * @var int
     */
    private const POCKET_EXPENSE_FEATURE_ID = 1; // Assuming feature ID 1 for pocket expenses

    /**
     * Determine whether the user can view any pocket expenses.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Only authenticated users with valid client context can view expenses
        if (!$user->client_id) {
            return false;
        }

        // All authenticated users can view some expenses (their own or managed)
        return true;
    }

    /**
     * Determine whether the user can view the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return bool
     */
    public function view(User $user, PocketExpense $pocketExpense): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $pocketExpense->client_id) {
            return false;
        }

        // Users can always view their own expenses
        if ($user->id === $pocketExpense->user_id) {
            return true;
        }

        // Primary Administrator has full access to all expenses within client
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Check if user has delegated permission to manage this expense owner
        return $this->canManageExpenseUser($user, $pocketExpense->user_id, $pocketExpense->client_id);
    }

    /**
     * Determine whether the user can create pocket expenses.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Only authenticated users with valid client context can create expenses
        if (!$user->client_id) {
            return false;
        }

        // All authenticated users can create their own expenses
        return true;
    }

    /**
     * Determine whether the user can create expenses for a specific user.
     *
     * @param User $user
     * @param int $expenseUserId
     * @param int $clientId
     * @return bool
     */
    public function createFor(User $user, int $expenseUserId, int $clientId): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $clientId) {
            return false;
        }

        // Users can create their own expenses
        if ($user->id === $expenseUserId) {
            return true;
        }

        // Primary Administrator can create expenses for any user within client
        if ($this->isPrimaryAdministrator($user)) {
            return $this->isValidTargetUser($expenseUserId, $clientId);
        }

        // Check if user has delegated permission to manage the target user
        return $this->canManageExpenseUser($user, $expenseUserId, $clientId);
    }

    /**
     * Determine whether the user can update the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return bool
     */
    public function update(User $user, PocketExpense $pocketExpense): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $pocketExpense->client_id) {
            return false;
        }

        // Cannot update approved or rejected expenses
        if (in_array($pocketExpense->status, ['approved', 'rejected'])) {
            return false;
        }

        // Users can update their own expenses
        if ($user->id === $pocketExpense->user_id) {
            return true;
        }

        // Primary Administrator can update any expense within client
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Check if user has delegated permission to manage this expense owner
        return $this->canManageExpenseUser($user, $pocketExpense->user_id, $pocketExpense->client_id);
    }

    /**
     * Determine whether the user can delete the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return bool
     */
    public function delete(User $user, PocketExpense $pocketExpense): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $pocketExpense->client_id) {
            return false;
        }

        // Cannot delete approved expenses
        if ($pocketExpense->status === 'approved') {
            return false;
        }

        // Users can delete their own expenses
        if ($user->id === $pocketExpense->user_id) {
            return true;
        }

        // Primary Administrator can delete any expense within client
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Check if user has delegated permission to manage this expense owner
        return $this->canManageExpenseUser($user, $pocketExpense->user_id, $pocketExpense->client_id);
    }

    /**
     * Determine whether the user can approve the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return bool
     */
    public function approve(User $user, PocketExpense $pocketExpense): bool
    {
        // Ensure client context matches
        if ($user->client_id !== $pocketExpense->client_id) {
            return false;
        }

        // Cannot approve own expenses
        if ($user->id === $pocketExpense->user_id) {
            return false;
        }

        // Only submitted expenses can be approved
        if ($pocketExpense->status !== 'submitted') {
            return false;
        }

        // Business User and Card User cannot approve expenses even with management rights
        if (in_array($this->getUserRole($user), [self::ROLE_BUSINESS_USER, self::ROLE_CARD_USER])) {
            return false;
        }

        // Primary Administrator can approve any expense
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can approve expenses they manage
        if ($this->isAdministrator($user)) {
            return $this->canManageExpenseUser($user, $pocket