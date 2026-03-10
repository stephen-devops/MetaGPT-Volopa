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
 * Authorization policy for pocket expense CRUD operations.
 * This policy controls access to pocket expense operations following the platform's
 * role-based access control system where Primary Administrators have full access by default,
 * and other users need explicit permissions granted through the UserFeaturePermission system.
 * 
 * Platform roles hierarchy:
 * - Primary Admin: Full access to all expenses within their client
 * - Admin: Full access to all expenses within their client
 * - Business User: Access based on delegated permissions and ownership
 * - Card User: Limited access to their own expenses only
 */
class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * OOP Feature ID constant for checking OOP-specific permissions
     *
     * @var int
     */
    private const OOP_FEATURE_ID = 16;

    /**
     * Primary Administrator role name
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role name
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Admin';

    /**
     * Business User role name
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role name
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Determine whether the user can view any pocket expenses.
     * 
     * Primary Admins and Admins can view all expenses within their client.
     * Business Users and Card Users need explicit OOP feature permission.
     * All access is scoped by client_id for multi-tenancy.
     *
     * @param User $user
     * @return Response|bool
     */
    public function viewAny(User $user): Response|bool
    {
        // Primary Administrators have full access within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins have full access within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users need explicit OOP feature permission
        if ($this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to view pocket expenses.');
    }

    /**
     * Determine whether the user can view the pocket expense.
     * 
     * Users can only view expenses within their own client context.
     * Access is further restricted based on role and ownership:
     * - Primary Admins and Admins can view any expense in their client
     * - Business Users can view expenses they own or manage
     * - Card Users can only view their own expenses
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return Response|bool
     */
    public function view(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view pocket expenses.');
        }

        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only view expenses within your own client.');
        }

        // Primary Admins and Admins can view any expense within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can view expenses they own or were created by them
        if ($this->isBusinessUser($user)) {
            if ($this->canAccessExpense($user, $pocketExpense)) {
                return Response::allow();
            }
        }

        // Card Users can only view their own expenses
        if ($this->isCardUser($user)) {
            if ($pocketExpense->user_id === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to view this expense.');
    }

    /**
     * Determine whether the user can create pocket expenses.
     * 
     * Users can create expenses if they have OOP feature permission.
     * Primary Admins and Admins have this permission by default.
     * Other users need explicit permission grants.
     *
     * @param User $user
     * @return Response|bool
     */
    public function create(User $user): Response|bool
    {
        // Primary Administrators can create expenses
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can create expenses
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users need explicit OOP feature permission
        if ($this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to create pocket expenses.');
    }

    /**
     * Determine whether the user can update the pocket expense.
     * 
     * Users can update expenses based on ownership, role, and expense status:
     * - Primary Admins and Admins can update any expense in their client
     * - Business Users can update expenses they own or manage
     * - Card Users can only update their own expenses
     * - Only expenses in draft or rejected status can be edited
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return Response|bool
     */
    public function update(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only update expenses within your own client.');
        }

        // Check if expense can be edited based on status
        if (!$pocketExpense->canBeEdited()) {
            return Response::deny('This expense cannot be edited in its current status.');
        }

        // Primary Admins and Admins can update any expense within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can update expenses they own or manage
        if ($this->isBusinessUser($user)) {
            if ($this->canModifyExpense($user, $pocketExpense)) {
                return Response::allow();
            }
        }

        // Card Users can only update their own expenses
        if ($this->isCardUser($user)) {
            if ($pocketExpense->user_id === $user->id && $this->hasOOPFeaturePermission($user)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to update this expense.');
    }

    /**
     * Determine whether the user can delete the pocket expense.
     * 
     * Similar rules to update but with additional restrictions:
     * - Approved expenses cannot be deleted by anyone except Primary Admins
     * - Users can only delete expenses they have ownership/management rights to
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return Response|bool
     */
    public function delete(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only delete expenses within your own client.');
        }

        // Check if expense can be deleted based on status
        if (!$pocketExpense->canBeDeleted()) {
            return Response::deny('This expense cannot be deleted in its current status.');
        }

        // Primary Admins can delete any expense within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can delete expenses within their client (except approved ones)
        if ($this->isAdmin($user)) {
            if (!$pocketExpense->isApproved()) {
                return Response::allow();
            }
            return Response::deny('Approved expenses can only be deleted by Primary Administrators.');
        }

        // Business Users can delete expenses they own or manage (with restrictions)
        if ($this->isBusinessUser($user)) {
            if ($this->canModifyExpense($user, $pocketExpense) && !$pocketExpense->isApproved()) {
                return Response::allow();
            }
        }

        // Card Users can delete their own expenses (with restrictions)
        if ($this->isCardUser($user)) {
            if ($pocketExpense->user_id === $user->id && 
                $this->hasOOPFeaturePermission($user) && 
                !$pocketExpense->isApproved()) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to delete this expense.');
    }

    /**
     * Determine whether the user can approve the pocket expense.
     * 
     * Only users with administrative roles or specific approval permissions
     * can approve expenses. Business Users can approve expenses for users
     * they manage if they have been granted approval authority.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return Response|bool
     */
    public function approve(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only approve expenses within your own client.');
        }

        // Check if expense can be approved based on status
        if (!$pocketExpense->canBeApproved()) {
            return Response::deny('This expense cannot be approved in its current status.');
        }

        // Users cannot approve their own expenses
        if ($pocketExpense->user_id === $user->id) {
            return Response::deny('You cannot approve your own expenses.');
        }

        // Primary Admins can approve any expense within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can approve expenses within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can approve expenses if they have been granted approval authority
        if ($this->isBusinessUser($user)) {
            if ($this->hasApprovalAuthority($user) && $this->canAccessExpense($user, $pocketExpense)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to approve this expense.');
    }

    /**
     * Determine whether the user can reject the pocket expense.
     * 
     * Similar rules to approve - only users with administrative roles
     * or specific approval permissions can reject expenses.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return Response|bool
     */
    public function reject(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only reject expenses within your own client.');
        }

        // Check if expense can be rejected based on status
        if (!$pocketExpense->canBeRejected()) {
            return Response::deny('This expense cannot be rejected in its current status.');
        }

        // Users cannot reject their own expenses
        if ($pocketExpense->user_id === $user->id) {
            return Response::deny('You cannot reject your own expenses.');
        }

        // Primary Admins can reject any expense within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can reject expenses within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can reject expenses if they have been granted approval authority
        if ($this->isBusinessUser($user)) {
            if ($this->hasApprovalAuthority($user) && $this->canAccessExpense($user, $pocketExpense)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to reject this expense.');
    }

    /**
     * Determine whether the user can submit the pocket expense for approval.
     * 
     * Users can submit their own expenses or expenses they manage.
     * Only expenses in draft status can be submitted.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return Response|bool
     */
    public function submit(User $user, PocketExpense $pocketExpense): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only submit expenses within your own client.');
        }

        // Check if expense can be submitted based on status
        if (!$pocketExpense->canBeSubmitted()) {
            return Response::deny('This expense cannot be submitted in its current status.');
        }

        // Primary Admins and Admins can submit any expense within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can submit expenses they own or manage
        if ($this->isBusinessUser($user)) {
            if ($this->canModifyExpense($user, $pocketExpense)) {
                return Response::allow();
            }
        }

        // Card Users can submit their own expenses
        if ($this->isCardUser($user)) {
            if ($pocketExpense->user_id === $user->id && $this->hasOOPFeaturePermission($user)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to submit this expense.');
    }

    /**
     * Determine whether the user can view expenses for a specific user.
     * 
     * This is used for filtering expenses in list views based on user ownership.
     * Admins can view any user's expenses, while others are more restricted.
     *
     * @param User $user
     * @param int $targetUserId
     * @return Response|bool
     */
    public function viewUserExpenses(User $user, int $targetUserId): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view pocket expenses.');
        }

        // Verify target user is in same client
        if (!$this->isSameClient($user, $targetUserId)) {
            return Response::deny('You can only view expenses for users in your own client.');
        }

        // Users can always view their own expenses
        if ($user->id === $targetUserId) {
            return Response::allow();
        }

        // Primary Admins and Admins can view any user's expenses within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can view expenses for users they manage
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUser($user, $targetUserId)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to view expenses for this user.');
    }

    /**
     * Determine whether the user can perform bulk operations on expenses.
     * 
     * Bulk operations require higher privileges due to their potential impact.
     * Only admins and users with explicit management permissions can perform bulk operations.
     *
     * @param User $user
     * @return Response|bool
     */
    public function bulkOperations(User $user): Response|bool
    {
        // Primary Admins and Admins can perform bulk operations
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users with management permissions can perform limited bulk operations
        if ($this->isBusinessUser($user) && $this->hasManagementPermissions($user)) {
            return Response::allow();
        }

        return Response::deny('Bulk expense operations require administrative privileges.');
    }

    /**
     * Determine whether the user can export expense data.
     * 
     * Export operations are restricted based on data access rights.
     * Users can only export data they have access to view.
     *
     * @param User $user
     * @return Response|bool
     */
    public function export(User $user): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to export expense data.');
        }

        // Primary Admins and Admins can export all client data
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users can export their accessible data
        if ($this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to export expense data.');
    }

    /**
     * Check if a user is a Primary Administrator.
     *
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        return $user->role === self::ROLE_PRIMARY_ADMIN;
    }

    /**
     * Check if a user is an Administrator.
     *
     * @param User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        return $user->role === self::ROLE_ADMIN;
    }

    /**
     * Check if a user is a Business User.
     *
     * @param User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        return $user->role === self::ROLE_BUSINESS_USER;
    }

    /**
     * Check if a user is a Card User.
     *
     * @param User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        return $user->role === self::ROLE_CARD_USER;
    }

    /**
     * Check if a user has OOP feature permission.
     * 
     * This determines if a user has been granted access to the OOP expense
     * management feature. Primary Admins and Admins have this by default.
     *
     * @param User $user
     * @return bool
     */
    private function hasOOPFeaturePermission(User $user): bool
    {
        // Primary Admins and Admins have OOP permission by default
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        // Check explicit permission records for other users
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if a user has approval authority.
     * 
     * This determines if a Business User has been granted the ability
     * to approve or reject expenses submitted by other users.
     *
     * @param User $user
     * @return bool
     */
    private function hasApprovalAuthority(User $user): bool
    {
        // Primary Admins and Admins have approval authority by default
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        // For Business Users, check if they have been granted approval permissions
        // This could be stored as a detail in the UserFeaturePermission or as a separate permission
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->whereJsonContains('details_json->can_approve', true)
            ->exists();
    }

    /**
     * Check if a user has management permissions.
     * 
     * This determines if a user can manage other users' expenses
     * beyond their own, typically used for delegation scenarios.
     *
     * @param User $user
     * @return bool
     */
    private function hasManagementPermissions(User $user): bool
    {
        // Primary Admins and Admins have management permissions by default
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        // Check if user has been granted management permissions
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->whereJsonContains('details_json->can_manage', true)
            ->exists();
    }

    /**
     * Check if a user can access a specific expense.
     * 
     * This determines if a user has the right to view or interact
     * with a specific expense based on ownership and delegation.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return bool
     */
    private function canAccessExpense(User $user, PocketExpense $pocketExpense): bool
    {
        // User can access their own expenses
        if ($pocketExpense->user_id === $user->id) {
            return true;
        }

        // User can access expenses they created for others
        if ($pocketExpense->created_by_user_id === $user->id) {
            return true;
        }

        // Check if user can manage the expense owner
        return $this->canManageUser($user, $pocketExpense->user_id);
    }

    /**
     * Check if a user can modify a specific expense.
     * 
     * This is more restrictive than canAccessExpense and includes
     * additional checks for expense status and modification rights.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @return bool
     */
    private function canModifyExpense(User $user, PocketExpense $pocketExpense): bool
    {
        // Check if user has basic access to the expense
        if (!$this->canAccessExpense($user, $pocketExpense)) {
            return false;
        }

        // User can modify their own expenses
        if ($pocketExpense->user_id === $user->id) {
            return true;
        }

        // Check if user has been granted modification rights for this user
        return $this->hasManagementPermissions($user) && $this->canManageUser($user, $pocketExpense->user_id);
    }

    /**
     * Check if a user can manage another user.
     * 
     * This determines if a user has been granted management rights
     * over another user through the permission delegation system.
     *
     * @param User $user
     * @param int $targetUserId
     * @return bool
     */
    private function canManageUser(User $user, int $targetUserId): bool
    {
        // Check if user has granted permissions to the target user
        $hasGrantedPermissions = UserFeaturePermission::where('grantor_id', $user->id)
            ->where('user_id', $targetUserId)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();

        if ($hasGrantedPermissions) {
            return true;
        }

        // Check if user is designated as manager for the target user
        return UserFeaturePermission::where('manager_user_id', $user->id)
            ->where('user_id', $targetUserId)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if a target user belongs to the same client as the requesting user.
     * 
     * This is a crucial multi-tenancy check to ensure users can only
     * access expenses within their own client context.
     *
     * @param User $user
     * @param int $targetUserId
     * @return bool
     */
    private function isSameClient(User $user, int $targetUserId): bool
    {
        $targetUser = User::find($targetUserId);
        
        if (!$targetUser) {
            return false;
        }

        return $user->client_id === $targetUser->client_id;
    }

    /**
     * Get the user's effective permissions for expense operations.
     * 
     * This returns a summary of what expense operations the user
     * can perform, useful for UI authorization decisions.
     *
     * @param User $user
     * @return array<string, bool>
     */
    public function getEffectivePermissions(User $user): array
    {
        return [
            'can_view_any' => $this->viewAny($user),
            'can_create' => $this->create($user),
            'can_export' => $this->export($user),
            'can_bulk_operations' => $this->bulkOperations($user),
            'has_oop_permission' => $this->hasOOPFeaturePermission($user),
            'has_approval_authority' => $this->hasApprovalAuthority($user),
            'has_management_permissions' => $this->hasManagementPermissions($user),
            'is_primary_admin' => $this->isPrimaryAdmin($user),
            'is_admin' => $this->isAdmin($user),
            'is_business_user' => $this->isBusinessUser($user),
            'is_card_user' => $this->isCardUser($user),
        ];
    }

    /**
     * Authorize expense creation for a specific user.
     * 
     * This provides detailed authorization for expense creation
     * including validation of target user and client context.
     *
     * @param User $user
     * @param int|null $expenseUserId
     * @return Response|bool
     */
    public function authorizeExpenseCreation(User $user, ?int $expenseUserId = null): Response|bool
    {
        // Basic create permission check
        if (!$this->create($user)) {
            return Response::deny('You do not have permission to create pocket expenses.');
        }

        // If no specific user is provided, user is creating for themselves
        if ($expenseUserId === null || $expenseUserId === $user->id) {
            return Response::allow();
        }

        // Check if target user is in same client
        if (!$this->isSameClient($user, $expenseUserId)) {
            return Response::deny('You can only create expenses for users in your own client.');
        }

        // Primary Admins and Admins can create expenses for any user in their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can create expenses for users they manage
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUser($user, $expenseUserId)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to create expenses for this user.');
    }

    /**
     * Check if user can view expense reports and analytics.
     * 
     * This determines access to reporting features and expense analytics.
     * Access is based on role and the scope of data the user can access.
     *
     * @param User $user
     * @return Response|bool
     */
    public function viewReports(User $user): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view expense reports.');
        }

        // Primary Admins and Admins can view all reports
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users with management permissions can view reports for managed users
        if ($this->isBusinessUser($user) && $this->hasManagementPermissions($user)) {
            return Response::allow();
        }

        // Card Users can view their own expense reports
        if ($this->isCardUser($user) && $this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to view expense reports.');
    }

    /**
     * Authorize status change operations on expenses.
     * 
     * This validates that a user can change an expense from one status to another
     * based on their role, permissions, and the expense's current state.
     *
     * @param User $user
     * @param PocketExpense $pocketExpense
     * @param string $newStatus
     * @return Response|bool
     */
    public function changeStatus(User $user, PocketExpense $pocketExpense, string $newStatus): Response|bool
    {
        // Must be within the same client context
        if ($user->client_id !== $pocketExpense->client_id) {
            return Response::deny('You can only change status for expenses within your own client.');
        }

        // Check if the status transition is valid
        if (!$pocketExpense->isValidStatusTransition($pocketExpense->status, $newStatus)) {
            return Response::deny("Invalid status transition from '{$pocketExpense->status}' to '{$newStatus}'.");
        }

        // Handle specific status changes
        switch ($newStatus) {
            case PocketExpense::STATUS_SUBMITTED:
                return $this->submit($user, $pocketExpense);
                
            case PocketExpense::STATUS_APPROVED:
                return $this->approve($user, $pocketExpense);
                
            case PocketExpense::STATUS_REJECTED:
                return $this->reject($user, $pocketExpense);
                
            case PocketExpense::STATUS_DRAFT:
                // Return to draft - similar rules to update
                return $this->update($user, $pocketExpense);
                
            default:
                return Response::deny('Unknown status transition requested.');
        }
    }
}