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
     */
    public function viewAny(User $user): bool
    {
        return $this->hasClientAccess($user);
    }

    /**
     * Determine whether the user can view the mass payment file.
     */
    public function view(User $user, MassPaymentFile $massPaymentFile): bool
    {
        return $this->checkClientAccess($user, $massPaymentFile);
    }

    /**
     * Determine whether the user can create mass payment files.
     */
    public function create(User $user): bool
    {
        return $this->hasClientAccess($user) && $this->hasPermission($user, 'mass_payments.create');
    }

    /**
     * Determine whether the user can update the mass payment file.
     */
    public function update(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Only allow updates in draft status
        if (!$massPaymentFile->isDraft()) {
            return false;
        }

        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.update');
    }

    /**
     * Determine whether the user can approve the mass payment file.
     */
    public function approve(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Cannot approve own uploads (segregation of duties)
        if ($this->isOwnUpload($user, $massPaymentFile)) {
            return false;
        }

        // Can only approve files awaiting approval
        if (!$massPaymentFile->canBeApproved()) {
            return false;
        }

        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.approve');
    }

    /**
     * Determine whether the user can delete the mass payment file.
     */
    public function delete(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Can only delete files in certain statuses
        if (!$massPaymentFile->canBeDeleted()) {
            return false;
        }

        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.delete');
    }

    /**
     * Determine whether the user can restore the mass payment file.
     */
    public function restore(User $user, MassPaymentFile $massPaymentFile): bool
    {
        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.restore');
    }

    /**
     * Determine whether the user can permanently delete the mass payment file.
     */
    public function forceDelete(User $user, MassPaymentFile $massPaymentFile): bool
    {
        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.force_delete');
    }

    /**
     * Determine whether the user can download template files.
     */
    public function downloadTemplate(User $user): bool
    {
        return $this->hasClientAccess($user) && $this->hasPermission($user, 'mass_payments.download_template');
    }

    /**
     * Determine whether the user can view payment instructions for the file.
     */
    public function viewInstructions(User $user, MassPaymentFile $massPaymentFile): bool
    {
        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.view_instructions');
    }

    /**
     * Determine whether the user can export data from the mass payment file.
     */
    public function export(User $user, MassPaymentFile $massPaymentFile): bool
    {
        return $this->checkClientAccess($user, $massPaymentFile) && 
               $this->hasPermission($user, 'mass_payments.export');
    }

    /**
     * Check if the user has client access (has a client_id).
     */
    private function hasClientAccess(User $user): bool
    {
        return !empty($user->client_id) && is_string($user->client_id);
    }

    /**
     * Check if the user belongs to the same client as the mass payment file.
     */
    private function checkClientAccess(User $user, MassPaymentFile $massPaymentFile): bool
    {
        if (!$this->hasClientAccess($user)) {
            return false;
        }

        return $user->client_id === $massPaymentFile->client_id;
    }

    /**
     * Check if the user has a specific permission.
     */
    private function hasPermission(User $user, string $permission): bool
    {
        // Check if user has the required permission
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($permission);
        }

        // Check if user has permissions array
        if (property_exists($user, 'permissions') && is_array($user->permissions)) {
            return in_array($permission, $user->permissions);
        }

        // Check user roles for permission
        if (method_exists($user, 'hasRole')) {
            $adminRoles = ['admin', 'super_admin', 'mass_payment_admin'];
            foreach ($adminRoles as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }

            // Check specific roles for specific permissions
            return match ($permission) {
                'mass_payments.create', 'mass_payments.update', 'mass_payments.delete' => 
                    $user->hasRole('mass_payment_creator') || $user->hasRole('mass_payment_manager'),
                'mass_payments.approve' => 
                    $user->hasRole('mass_payment_approver') || $user->hasRole('mass_payment_manager'),
                'mass_payments.view_instructions', 'mass_payments.export' => 
                    $user->hasRole('mass_payment_viewer') || $user->hasRole('mass_payment_creator') || 
                    $user->hasRole('mass_payment_approver') || $user->hasRole('mass_payment_manager'),
                'mass_payments.download_template' => 
                    $user->hasRole('mass_payment_creator') || $user->hasRole('mass_payment_manager'),
                'mass_payments.restore', 'mass_payments.force_delete' => 
                    $user->hasRole('mass_payment_admin'),
                default => false,
            };
        }

        // Default fallback - check if user is active
        return $this->isActiveUser($user);
    }

    /**
     * Check if the mass payment file was uploaded by the current user.
     */
    private function isOwnUpload(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // Check if the file has created_by field
        if (property_exists($massPaymentFile, 'created_by') && !empty($massPaymentFile->created_by)) {
            return $user->id === $massPaymentFile->created_by;
        }

        // Fallback: check created_at timestamp with small time window (assuming recent upload)
        if (property_exists($user, 'last_activity_at')) {
            $timeDiff = abs($user->last_activity_at->timestamp - $massPaymentFile->created_at->timestamp);
            return $timeDiff < 300; // 5 minutes window
        }

        // Conservative approach: assume it's not own upload if we can't determine
        return false;
    }

    /**
     * Check if the user is active and can perform actions.
     */
    private function isActiveUser(User $user): bool
    {
        // Check user status
        if (property_exists($user, 'status')) {
            return $user->status === 'active';
        }

        // Check if user is enabled
        if (property_exists($user, 'is_active')) {
            return $user->is_active === true;
        }

        // Check email verification
        if (property_exists($user, 'email_verified_at')) {
            return !is_null($user->email_verified_at);
        }

        // Default to true if no status fields exist
        return true;
    }

    /**
     * Determine if the user can view files by status.
     */
    public function viewByStatus(User $user, string $status): bool
    {
        if (!$this->hasClientAccess($user)) {
            return false;
        }

        // Restrict certain statuses based on permissions
        $restrictedStatuses = ['processing', 'completed'];
        
        if (in_array($status, $restrictedStatuses)) {
            return $this->hasPermission($user, 'mass_payments.view_processed');
        }

        return $this->hasPermission($user, 'mass_payments.view');
    }

    /**
     * Determine if the user can access TCC account for mass payments.
     */
    public function accessTccAccount(User $user, string $tccAccountId): bool
    {
        if (!$this->hasClientAccess($user)) {
            return false;
        }

        // Check if user has access to specific TCC account
        if (method_exists($user, 'hasAccessToTccAccount')) {
            return $user->hasAccessToTccAccount($tccAccountId);
        }

        // Check user's accessible TCC accounts
        if (property_exists($user, 'accessible_tcc_accounts') && is_array($user->accessible_tcc_accounts)) {
            return in_array($tccAccountId, $user->accessible_tcc_accounts);
        }

        // Default: allow access if user has mass payment permissions
        return $this->hasPermission($user, 'mass_payments.create');
    }

    /**
     * Determine if the user can process files with specific currency.
     */
    public function processCurrency(User $user, string $currency): bool
    {
        if (!$this->hasClientAccess($user)) {
            return false;
        }

        // Check user's allowed currencies
        if (property_exists($user, 'allowed_currencies') && is_array($user->allowed_currencies)) {
            return in_array(strtoupper($currency), $user->allowed_currencies);
        }

        // Check currency restrictions by role
        if (method_exists($user, 'hasRole')) {
            $restrictedCurrencies = ['EUR', 'USD', 'GBP']; // High-value currencies
            
            if (in_array(strtoupper($currency), $restrictedCurrencies)) {
                return $user->hasRole('senior_approver') || $user->hasRole('mass_payment_admin');
            }
        }

        // Default: allow all currencies for users with create permission
        return $this->hasPermission($user, 'mass_payments.create');
    }

    /**
     * Determine if the user can handle files with specific amount threshold.
     */
    public function processAmount(User $user, float $totalAmount): bool
    {
        if (!$this->hasClientAccess($user)) {
            return false;
        }

        // Check user's transaction limits
        if (property_exists($user, 'daily_limit') && $user->daily_limit > 0) {
            if ($totalAmount > $user->daily_limit) {
                return false;
            }
        }

        // High-value transaction approval requirements
        $highValueThreshold = 100000.00; // $100,000
        
        if ($totalAmount > $highValueThreshold) {
            return $this->hasPermission($user, 'mass_payments.high_value') || 
                   $this->hasPermission($user, 'mass_payments.unlimited');
        }

        return $this->hasPermission($user, 'mass_payments.create');
    }

    /**
     * Before hook for all policy methods.
     */
    public function before(User $user, string $ability): ?bool
    {
        // Super admin can do everything
        if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
            return true;
        }

        // System user can do everything
        if (property_exists($user, 'is_system') && $user->is_system === true) {
            return true;
        }

        // If user doesn't have client access, deny all actions
        if (!$this->hasClientAccess($user)) {
            return false;
        }

        return null; // Continue to specific policy method
    }
}