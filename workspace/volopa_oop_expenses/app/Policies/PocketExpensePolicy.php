## Code: app/Policies/PocketExpensePolicy.php

```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use App\Services\PermissionService;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * PocketExpensePolicy
 * 
 * Handles authorization for pocket expense operations using RBAC.
 * Checks user_feature_permission table for proper access control.
 * Implements hierarchical permissions with manager-based delegation.
 */
class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Feature ID for pocket expense management.
     */
    private const POCKET_EXPENSE_FEATURE_ID = 1;

    /**
     * The permission service instance.
     *
     * @var PermissionService
     */
    protected PermissionService $permissionService;

    /**
     * Create a new policy instance.
     *
     * @param PermissionService $permissionService
     */
    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Determine whether the user can view any pocket expenses.
     *
     * @param User $user
     * @param int $clientId
     * @return Response|bool
     */
    public function viewAny(User $user, int $clientId): Response|bool
    {
        // Check if user has pocket expense feature permission for this client
        if (!$this->hasFeaturePermission($user->id, $clientId)) {
            return Response::deny('User does not have permission to view pocket expenses for this client.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function view(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasFeaturePermission($user->id, $expense->client_id)) {
            return Response::deny('User does not have permission to view pocket expenses for this client.');
        }

        // Check if user can view this specific expense
        if ($this->canAccessExpense($user, $expense)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to view this pocket expense.');
    }

    /**
     * Determine whether the user can create pocket expenses.
     *
     * @param User $user
     * @param int $clientId
     * @param int $targetUserId
     * @return Response|bool
     */
    public function create(User $user, int $clientId, int $targetUserId): Response|bool
    {
        // Check if user has pocket expense feature permission for this client
        if (!$this->hasFeaturePermission($user->id, $clientId)) {
            return Response::deny('User does not have permission to create pocket expenses for this client.');
        }

        // Check if user can create expenses for the target user
        if ($this->canManageUserExpenses($user, $targetUserId, $clientId)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to create pocket expenses for the specified user.');
    }

    /**
     * Determine whether the user can update the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function update(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasFeaturePermission($user->id, $expense->client_id)) {
            return Response::deny('User does not have permission to update pocket expenses for this client.');
        }

        // Cannot update approved or rejected expenses
        if ($expense->isApproved() || $expense->isRejected()) {
            return Response::deny('Cannot update expenses that have been approved or rejected.');
        }

        // Check if user can manage this expense
        if ($this->canAccessExpense($user, $expense)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to update this pocket expense.');
    }

    /**
     * Determine whether the user can delete the pocket expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function delete(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasFeaturePermission($user->id, $expense->client_id)) {
            return Response::deny('User does not have permission to delete pocket expenses for this client.');
        }

        // Cannot delete approved expenses
        if ($expense->isApproved()) {
            return Response::deny('Cannot delete approved expenses.');
        }

        // Check if user can manage this expense
        if ($this->canAccessExpense($user, $expense)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to delete this pocket expense.');
    }

    /**
     * Determine whether the user can approve pocket expenses.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function approve(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasFeaturePermission($user->id, $expense->client_id)) {
            return Response::deny('User does not have permission to approve pocket expenses for this client.');
        }

        // Cannot approve own expenses
        if ($expense->user_id === $user->id) {
            return Response::deny('Users cannot approve their own expenses.');
        }

        // Can only approve submitted expenses
        if (!$expense->isSubmitted()) {
            return Response::deny('Only submitted expenses can be approved.');
        }

        // Check if user is a manager for the expense owner
        if ($this->isManagerFor($user, $expense->user_id, $expense->client_id)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to approve this pocket expense.');
    }

    /**
     * Determine whether the user can reject pocket expenses.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return Response|bool
     */
    public function reject(User $user, PocketExpense $expense): Response|bool
    {
        // Check basic feature permission
        if (!$this->hasFeaturePermission($user->id, $expense->client_id)) {
            return Response::deny('User does not have permission to reject pocket expenses for this client.');
        }

        // Cannot reject own expenses
        if ($expense->user_id === $user->id) {
            return Response::deny('Users cannot reject their own expenses.');
        }

        // Can only reject submitted expenses
        if (!$expense->isSubmitted()) {
            return Response::deny('Only submitted expenses can be rejected.');
        }

        // Check if user is a manager for the expense owner
        if ($this->isManagerFor($user, $expense->user_id, $expense->client_id)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to reject this pocket expense.');
    }

    /**
     * Determine whether the user can upload CSV files for pocket expenses.
     *
     * @param User $user
     * @param int $clientId
     * @param int $targetUserId
     * @return Response|bool
     */
    public function uploadCSV(User $user, int $clientId, int $targetUserId): Response|bool
    {
        // Check if user has pocket expense feature permission for this client
        if (!$this->hasFeaturePermission($user->id, $clientId)) {
            return Response::deny('User does not have permission to upload pocket expenses for this client.');
        }

        // Check if user can manage expenses for the target user
        if ($this->canManageUserExpenses($user, $targetUserId, $clientId)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to upload pocket expenses for the specified user.');
    }

    /**
     * Determine whether the user can view upload status and results.
     *
     * @param User $user
     * @param int $clientId
     * @param int $uploadUserId
     * @return Response|bool
     */
    public function viewUploadStatus(User $user, int $clientId, int $uploadUserId): Response|bool
    {
        // Check if user has pocket expense feature permission for this client
        if (!$this->hasFeaturePermission($user->id, $clientId)) {
            return Response::deny('User does not have permission to view upload status for this client.');
        }

        // Can view own uploads
        if ($user->id === $uploadUserId) {
            return Response::allow();
        }

        // Can view uploads if user is a manager
        if ($this->isManagerFor($user, $uploadUserId, $clientId)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to view this upload status.');
    }

    /**
     * Determine whether the user can manage expense sources.
     *
     * @param User $user
     * @param int $clientId
     * @return Response|bool
     */
    public function manageExpenseSources(User $user, int $clientId): Response|bool
    {
        // Check if user has pocket expense feature permission for this client
        if (!$this->hasFeaturePermission($user->id, $clientId)) {
            return Response::deny('User does not have permission to manage expense sources for this client.');
        }

        // Only managers can manage expense sources
        if ($this->hasManagerRole($user->id, $clientId)) {
            return Response::allow();
        }

        return Response::deny('User does not have permission to manage expense sources.');
    }

    /**
     * Check if user has the pocket expense feature permission for a client.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    protected function hasFeaturePermission(int $userId, int $clientId): bool
    {
        return UserFeaturePermission::hasPermission(
            $userId,
            $clientId,
            self::POCKET_EXPENSE_FEATURE_ID
        );
    }

    /**
     * Check if user can access a specific expense.
     *
     * @param User $user
     * @param PocketExpense $expense
     * @return bool
     */
    protected function canAccessExpense(User $user, PocketExpense $expense): bool
    {
        // Can access own expenses
        if ($expense->user_id === $user->id) {
            return true;
        }

        // Can access expenses if user is a manager for the expense owner
        if ($this->isManagerFor($user, $expense->user_id, $expense->client_id)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can manage expenses for another user.
     *
     * @param User $user
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    protected function canManageUserExpenses(User $user, int $targetUserId, int $clientId): bool
    {
        // Can manage own expenses
        if ($user->id === $targetUserId) {
            return true;
        }

        // Can manage expenses if user is a manager for the target user
        if ($this->isManagerFor($user, $targetUserId, $clientId)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user is a manager for another user within a client context.
     *
     * @param User $managerUser
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    protected function isManagerFor(User $managerUser, int $targetUserId, int $clientId): bool
    {
        // Check if there's a permission record where the manager is assigned
        return UserFeaturePermission::enabled()
            ->forUser($targetUserId)
            ->forClient($clientId)
            ->forFeature(self::POCKET_EXPENSE_FEATURE_ID)
            ->forManager($managerUser->id)
            ->exists();
    }

    /**
     * Check if user has manager role within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    protected function hasManagerRole(int $userId, int $clientId): bool
    {
        // Check if user is assigned as manager for any users in this client
        return UserFeaturePermission::enabled()
            ->forClient($clientId)
            ->forFeature(self::POCKET_EXPENSE_FEATURE_ID)
            ->forManager($userId)
            ->exists();
    }

    /**
     * Check if user is a primary admin or admin.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    protected function isAdmin(User $user, int $clientId): bool
    {
        // This would typically check user roles or permissions
        // Implementation depends on your user role system
        return $this->permissionService->isAdmin($user->id, $clientId);
    }

    /**
     * Check if user can view all expenses within a client (admin/manager privilege).
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    protected function canViewAllExpenses(User $user, int $clientId): bool
    {
        // Admins can view all expenses
        if ($this->isAdmin($user, $clientId)) {
            return true;
        }

        // Managers can view expenses for their managed users
        if ($this->hasManagerRole($user->id, $clientId)) {
            return true;
        }

        return false;
    }

    /**
     * Get users that the given user can manage within a client context.
     *
     * @param User $user
     * @param int $clientId
     * @return array
     */
    protected function getManagedUserIds(User $user, int $clientId): array
    {
        $managedUsers = UserFeaturePermission::enabled()
            ->forClient($clientId)
            ->forFeature(self::POCKET_EXPENSE_FEATURE_ID)
            ->forManager($user->id)
            ->pluck('user_id')
            ->toArray();

        // Always include the user themselves
        $managedUsers[] = $user->id;

        return array_unique($managedUsers);
    }

    /**
     * Check if the expense is in an editable state.
     *
     * @param PocketExpense $expense
     * @return bool
     */
    protected function isExpenseEditable(PocketExpense $expense): bool
    {
        return $expense->isDraft() || $expense->isSubmitted();
    }

    /**
     * Check if the expense can be deleted.
     *
     * @param PocketExpense $expense
     * @return bool
     */
    protected function isExpenseDeletable(PocketExpense $expense): bool
    {
        return !$expense->isApproved();
    }

    /**
     * Check if the expense can be approved or rejected.
     *
     * @param PocketExpense $expense
     * @return bool
     */
    protected function isExpenseApprovable(PocketExpense $expense): bool
    {
        return $expense->isSubmitted();
    }

    /**
     * Check if user has sufficient permissions for bulk operations.
     *
     * @param User $user
     * @param int $clientId
     * @param array $expenseIds
     * @return bool
     */
    protected function canBulkOperate(User $user, int $clientId, array $expenseIds): bool
    {
        // Check if user has feature permission
        if (!$this->hasFeaturePermission($user->id, $clientId)) {
            return false;
        }

        // Get all expenses in the bulk operation
        $expenses = PocketExpense::whereIn('id', $expenseIds)