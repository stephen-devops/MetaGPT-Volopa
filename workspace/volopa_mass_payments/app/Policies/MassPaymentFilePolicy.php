<?php

namespace App\Policies;

use App\Models\MassPaymentFile;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MassPaymentFilePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any mass payment files.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Users can view mass payment files if they belong to the same client
        return $user->client_id !== null;
    }

    /**
     * Determine whether the user can view the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function view(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can only view files belonging to their client
        return $user->client_id === $massPaymentFile->client_id;
    }

    /**
     * Determine whether the user can create mass payment files.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Users can create mass payment files if they belong to a client and have upload permission
        return $user->client_id !== null 
            && $this->hasPermission($user, 'mass_payments.upload');
    }

    /**
     * Determine whether the user can update the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function update(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can only update files belonging to their client
        // Files can only be updated in draft or validation_failed status
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.edit')
            && in_array($massPaymentFile->status, [
                MassPaymentFile::STATUS_DRAFT,
                MassPaymentFile::STATUS_VALIDATION_FAILED,
            ]);
    }

    /**
     * Determine whether the user can delete the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function delete(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can only delete files belonging to their client
        // Files can only be deleted if they are in deletable status
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.delete')
            && $massPaymentFile->canBeDeleted();
    }

    /**
     * Determine whether the user can restore the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function restore(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can only restore files belonging to their client
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.restore');
    }

    /**
     * Determine whether the user can permanently delete the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function forceDelete(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Only system administrators can permanently delete files
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.force_delete')
            && $this->hasRole($user, 'admin');
    }

    /**
     * Determine whether the user can approve the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function approve(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can only approve files belonging to their client
        // Users cannot approve their own uploads
        // Files must be in awaiting_approval status
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.approve')
            && $user->id !== $massPaymentFile->uploaded_by
            && $massPaymentFile->canBeApproved();
    }

    /**
     * Determine whether the user can reprocess the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function reprocess(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can reprocess failed files belonging to their client
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.reprocess')
            && $massPaymentFile->hasFailed();
    }

    /**
     * Determine whether the user can download the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function download(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can download files belonging to their client
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.download');
    }

    /**
     * Determine whether the user can view validation errors.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function viewValidationErrors(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can view validation errors for files belonging to their client
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.view_errors')
            && $massPaymentFile->hasValidationFailed();
    }

    /**
     * Determine whether the user can view payment instructions.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function viewPaymentInstructions(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can view payment instructions for files belonging to their client
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.view_instructions');
    }

    /**
     * Determine whether the user can cancel the mass payment file.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function cancel(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can cancel files belonging to their client
        // Files can only be cancelled in specific statuses
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.cancel')
            && in_array($massPaymentFile->status, [
                MassPaymentFile::STATUS_DRAFT,
                MassPaymentFile::STATUS_AWAITING_APPROVAL,
                MassPaymentFile::STATUS_APPROVED,
            ]);
    }

    /**
     * Determine whether the user can view audit trail.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function viewAuditTrail(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can view audit trail for files belonging to their client
        // Only users with audit permission can view audit trail
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.audit');
    }

    /**
     * Determine whether the user can export file data.
     *
     * @param User $user
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     */
    public function export(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Users can export files belonging to their client
        return $user->client_id === $massPaymentFile->client_id
            && $this->hasPermission($user, 'mass_payments.export');
    }

    /**
     * Check if the user has a specific permission.
     *
     * @param User $user
     * @param string $permission
     * @return bool
     */
    private function hasPermission(User $user, string $permission): bool
    {
        // Check if user has the specific permission
        // This assumes User model has permissions relationship or method
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission);
        }

        // Fallback to checking user roles if permissions method doesn't exist
        if (method_exists($user, 'can')) {
            return $user->can($permission);
        }

        // Default fallback - check user role-based permissions
        return $this->checkRoleBasedPermissions($user, $permission);
    }

    /**
     * Check if the user has a specific role.
     *
     * @param User $user
     * @param string $role
     * @return bool
     */
    private function hasRole(User $user, string $role): bool
    {
        // Check if user has the specific role
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole($role);
        }

        // Fallback to checking role attribute
        if (isset($user->role)) {
            return $user->role === $role;
        }

        // Default fallback
        return false;
    }

    /**
     * Check role-based permissions as fallback.
     *
     * @param User $user
     * @param string $permission
     * @return bool
     */
    private function checkRoleBasedPermissions(User $user, string $permission): bool
    {
        // Get user role
        $userRole = $user->role ?? 'user';

        // Define role-based permissions mapping
        $rolePermissions = [
            'admin' => [
                'mass_payments.upload',
                'mass_payments.edit',
                'mass_payments.delete',
                'mass_payments.approve',
                'mass_payments.reprocess',
                'mass_payments.download',
                'mass_payments.view_errors',
                'mass_payments.view_instructions',
                'mass_payments.cancel',
                'mass_payments.audit',
                'mass_payments.export',
                'mass_payments.restore',
                'mass_payments.force_delete',
            ],
            'manager' => [
                'mass_payments.upload',
                'mass_payments.edit',
                'mass_payments.delete',
                'mass_payments.approve',
                'mass_payments.download',
                'mass_payments.view_errors',
                'mass_payments.view_instructions',
                'mass_payments.cancel',
                'mass_payments.export',
            ],
            'operator' => [
                'mass_payments.upload',
                'mass_payments.edit',
                'mass_payments.download',
                'mass_payments.view_errors',
                'mass_payments.view_instructions',
                'mass_payments.export',
            ],
            'viewer' => [
                'mass_payments.download',
                'mass_payments.view_errors',
                'mass_payments.view_instructions',
            ],
        ];

        // Check if role has permission
        return isset($rolePermissions[$userRole]) 
            && in_array($permission, $rolePermissions[$userRole]);
    }

    /**
     * Determine if user can perform bulk operations.
     *
     * @param User $user
     * @return bool
     */
    public function bulkOperations(User $user): bool
    {
        return $user->client_id !== null
            && $this->hasPermission($user, 'mass_payments.bulk_operations');
    }

    /**
     * Determine if user can view system statistics.
     *
     * @param User $user
     * @return bool
     */
    public function viewStatistics(User $user): bool
    {
        return $user->client_id !== null
            && $this->hasPermission($user, 'mass_payments.statistics');
    }

    /**
     * Determine if user can configure system settings.
     *
     * @param User $user
     * @return bool
     */
    public function configureSettings(User $user): bool
    {
        return $this->hasPermission($user, 'mass_payments.configure')
            && $this->hasRole($user, 'admin');
    }

    /**
     * Before hook - runs before all policy methods.
     *
     * @param User $user
     * @param string $ability
     * @return bool|null
     */
    public function before(User $user, string $ability): ?bool
    {
        // Super admin users can do everything
        if ($this->hasRole($user, 'super_admin')) {
            return true;
        }

        // Users without client_id cannot access mass payment features
        if ($user->client_id === null && $ability !== 'viewAny') {
            return false;
        }

        // Inactive users cannot perform any actions
        if (isset($user->is_active) && !$user->is_active) {
            return false;
        }

        // Continue with regular policy checks
        return null;
    }
}