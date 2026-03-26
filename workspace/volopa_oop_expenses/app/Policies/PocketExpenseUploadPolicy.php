<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpenseFileUpload;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Pocket Expense Upload Policy
 * 
 * Authorization policy for CSV upload operations and file upload management.
 * Implements permission checks based on user roles and feature permissions.
 * 
 * Constraints:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own expenses by default; needs explicit grant for others
 * - Business User and Card User cannot approve expenses even with management rights
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to their own managed users (not all users)
 */
class PocketExpenseUploadPolicy
{
    use HandlesAuthorization;

    /**
     * OOP Expense feature ID as per system constraints.
     */
    private const OOP_EXPENSE_FEATURE_ID = 16;

    /**
     * Determine whether the user can view any uploads.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Implement role-based access check
        // Primary Admin has full access, others need feature permission
        return $this->hasOOPExpensePermission($user);
    }

    /**
     * Determine whether the user can view the upload.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseFileUpload $upload
     * @return bool
     */
    public function view(User $user, PocketExpenseFileUpload $upload): bool
    {
        // User can view upload if they have OOP permission for the client
        // and either they created it or they have management rights over the target user
        if (!$this->hasOOPExpensePermissionForClient($user, $upload->client_id)) {
            return false;
        }

        // Can view own uploads
        if ($upload->created_by_user_id === $user->id) {
            return true;
        }

        // Can view uploads for users they manage
        return $this->canManageUser($user, $upload->user_id, $upload->client_id);
    }

    /**
     * Determine whether the user can create uploads.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @param int $targetUserId
     * @return bool
     */
    public function create(User $user, int $clientId, int $targetUserId): bool
    {
        // Must have OOP expense permission for the client
        if (!$this->hasOOPExpensePermissionForClient($user, $clientId)) {
            return false;
        }

        // Can create uploads for themselves
        if ($targetUserId === $user->id) {
            return true;
        }

        // Can create uploads for users they manage
        return $this->canManageUser($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can upload CSV files.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @param int $expenseUserId
     * @return bool
     */
    public function uploadCSV(User $user, int $clientId, int $expenseUserId): bool
    {
        return $this->create($user, $clientId, $expenseUserId);
    }

    /**
     * Determine whether the user can update the upload.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseFileUpload $upload
     * @return bool
     */
    public function update(User $user, PocketExpenseFileUpload $upload): bool
    {
        // Only the creator can update upload records
        // Uploads are typically read-only after creation except for status updates
        return $upload->created_by_user_id === $user->id 
            && $this->hasOOPExpensePermissionForClient($user, $upload->client_id);
    }

    /**
     * Determine whether the user can delete the upload.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseFileUpload $upload
     * @return bool
     */
    public function delete(User $user, PocketExpenseFileUpload $upload): bool
    {
        // Only the creator can delete uploads, and only if not yet processed
        if ($upload->created_by_user_id !== $user->id) {
            return false;
        }

        if (!$this->hasOOPExpensePermissionForClient($user, $upload->client_id)) {
            return false;
        }

        // Cannot delete uploads that are already processed or completed
        return !in_array($upload->status, ['completed', 'processing']);
    }

    /**
     * Determine whether the user can view upload status.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseFileUpload $upload
     * @return bool
     */
    public function viewStatus(User $user, PocketExpenseFileUpload $upload): bool
    {
        return $this->view($user, $upload);
    }

    /**
     * Determine whether the user can retry failed uploads.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseFileUpload $upload
     * @return bool
     */
    public function retry(User $user, PocketExpenseFileUpload $upload): bool
    {
        // Only the creator can retry failed uploads
        return $upload->created_by_user_id === $user->id 
            && $upload->status === 'failed'
            && $this->hasOOPExpensePermissionForClient($user, $upload->client_id);
    }

    /**
     * Check if user has OOP expense permission.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function hasOOPExpensePermission(User $user): bool
    {
        // TODO: Implement role check for Primary Admin (full access by default)
        // For now, check if user has any OOP expense permission
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('feature_id', self::OOP_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if user has OOP expense permission for a specific client.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    private function hasOOPExpensePermissionForClient(User $user, int $clientId): bool
    {
        // TODO: Implement role check for Primary Admin (full access by default)
        // For now, check if user has OOP expense permission for the client
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('feature_id', self::OOP_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if user can manage another user (has management rights).
     *
     * @param \App\Models\User $manager
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    private function canManageUser(User $manager, int $targetUserId, int $clientId): bool
    {
        // TODO: Implement proper management rights check
        // Admin can only grant access to their own managed users (not all users)
        // Managing access can be given to any user irrespective of role
        
        return UserFeaturePermission::where('manager_user_id', $manager->id)
            ->where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('feature_id', self::OOP_EXPENSE_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if user has the required role for full access.
     * Primary Admin has full access to all users by default.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        // TODO: Implement role check against platform user roles
        // Platform roles: Primary Admin, Admin, Business User, Card User
        // This requires integration with existing user role system
        return false; // Placeholder - need to check user->role or similar
    }

    /**
     * Check if client has OOP feature enabled.
     *
     * @param int $clientId
     * @return bool
     */
    private function clientHasOOPFeatureEnabled(int $clientId): bool
    {
        // TODO: Implement client feature check
        // Feature enablement is controlled via Sales/Ops through Admin.Volopa
        // This requires checking against ClientFeatures model or similar
        return true; // Placeholder - assume enabled for now
    }
}