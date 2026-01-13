<?php

namespace App\Policies;

use App\Models\MassPaymentFile;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class MassPaymentFilePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any mass payment files.
     */
    public function viewAny(User $user): bool
    {
        // Users can view mass payment files for their own client
        return $user->hasPermission('mass_payments.view') || 
               $user->hasRole(['admin', 'finance_manager', 'finance_user']);
    }

    /**
     * Determine whether the user can view the mass payment file.
     */
    public function view(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only view files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Check if user has view permission
        return $user->hasPermission('mass_payments.view') || 
               $user->hasRole(['admin', 'finance_manager', 'finance_user']);
    }

    /**
     * Determine whether the user can create mass payment files.
     */
    public function create(User $user): bool
    {
        // User must have create permission
        return $user->hasPermission('mass_payments.create') || 
               $user->hasRole(['admin', 'finance_manager', 'finance_user']);
    }

    /**
     * Determine whether the user can update the mass payment file.
     */
    public function update(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only update files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Only allow updates on files that are in draft/uploading status
        if (!in_array($massPaymentFile->status, [
            MassPaymentFile::STATUS_UPLOADING,
            MassPaymentFile::STATUS_PROCESSING,
            MassPaymentFile::STATUS_VALIDATION_FAILED
        ])) {
            return false;
        }

        // Check if user has update permission
        return $user->hasPermission('mass_payments.update') || 
               $user->hasRole(['admin', 'finance_manager']);
    }

    /**
     * Determine whether the user can delete the mass payment file.
     */
    public function delete(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only delete files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Only allow deletion of files that haven't been approved or processed
        if (!$massPaymentFile->canBeCancelled()) {
            return false;
        }

        // User cannot delete files they didn't create unless they're admin
        if ($massPaymentFile->created_by !== $user->id && 
            !$user->hasRole(['admin', 'finance_manager'])) {
            return false;
        }

        // Check if user has delete permission
        return $user->hasPermission('mass_payments.delete') || 
               $user->hasRole(['admin', 'finance_manager']);
    }

    /**
     * Determine whether the user can approve the mass payment file.
     */
    public function approve(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only approve files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // File must be in pending approval status
        if (!$massPaymentFile->isPendingApproval()) {
            return false;
        }

        // File must not have validation errors
        if ($massPaymentFile->hasValidationErrors()) {
            return false;
        }

        // User cannot approve their own files (maker-checker principle)
        if ($massPaymentFile->created_by === $user->id) {
            return false;
        }

        // Check approval amount limits based on user role
        if (!$this->hasApprovalLimit($user, $massPaymentFile->total_amount, $massPaymentFile->currency)) {
            return false;
        }

        // Check if user has approve permission
        return $user->hasPermission('mass_payments.approve') || 
               $user->hasRole(['admin', 'finance_manager', 'approver']);
    }

    /**
     * Determine whether the user can cancel/reject the mass payment file.
     */
    public function cancel(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only cancel files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // File must be in a cancellable status
        if (!$massPaymentFile->canBeCancelled()) {
            return false;
        }

        // Check if user has cancel permission
        return $user->hasPermission('mass_payments.cancel') || 
               $user->hasRole(['admin', 'finance_manager', 'approver']);
    }

    /**
     * Determine whether the user can download the mass payment file.
     */
    public function download(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only download files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Check if user has download permission
        return $user->hasPermission('mass_payments.download') || 
               $user->hasRole(['admin', 'finance_manager', 'finance_user', 'approver']);
    }

    /**
     * Determine whether the user can resubmit a failed mass payment file.
     */
    public function resubmit(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only resubmit files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // File must be in validation failed status
        if (!$massPaymentFile->isValidationFailed()) {
            return false;
        }

        // User must be the creator or have admin privileges
        if ($massPaymentFile->created_by !== $user->id && 
            !$user->hasRole(['admin', 'finance_manager'])) {
            return false;
        }

        // Check if user has create permission (resubmit is similar to create)
        return $user->hasPermission('mass_payments.create') || 
               $user->hasRole(['admin', 'finance_manager', 'finance_user']);
    }

    /**
     * Check if user has sufficient approval limit for the given amount and currency.
     */
    private function hasApprovalLimit(User $user, float $amount, string $currency): bool
    {
        // Admin users have unlimited approval limits
        if ($user->hasRole('admin')) {
            return true;
        }

        // Get user's approval limits from user metadata or role configuration
        $approvalLimits = $user->metadata['approval_limits'] ?? [];
        
        // Default approval limits by role
        $defaultLimits = $this->getDefaultApprovalLimits();
        
        // Get the user's role-based limits
        $userRoles = $user->getRoleNames()->toArray();
        $userLimit = 0;
        
        foreach ($userRoles as $role) {
            if (isset($defaultLimits[$role][$currency])) {
                $userLimit = max($userLimit, $defaultLimits[$role][$currency]);
            }
        }

        // Override with user-specific limits if configured
        if (isset($approvalLimits[$currency])) {
            $userLimit = $approvalLimits[$currency];
        }

        return $amount <= $userLimit;
    }

    /**
     * Get default approval limits by role and currency.
     */
    private function getDefaultApprovalLimits(): array
    {
        return [
            'finance_manager' => [
                'USD' => 100000.00,
                'EUR' => 90000.00,
                'GBP' => 80000.00,
                'default' => 50000.00
            ],
            'approver' => [
                'USD' => 50000.00,
                'EUR' => 45000.00,
                'GBP' => 40000.00,
                'default' => 25000.00
            ],
            'finance_user' => [
                'USD' => 10000.00,
                'EUR' => 9000.00,
                'GBP' => 8000.00,
                'default' => 5000.00
            ]
        ];
    }

    /**
     * Determine whether the user can perform bulk operations.
     */
    public function bulkOperation(User $user): bool
    {
        // Only admin and finance managers can perform bulk operations
        return $user->hasRole(['admin', 'finance_manager']);
    }

    /**
     * Determine whether the user can view audit logs for mass payment files.
     */
    public function viewAuditLogs(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only view audit logs for files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Check if user has audit view permission
        return $user->hasPermission('mass_payments.audit') || 
               $user->hasRole(['admin', 'finance_manager', 'compliance_officer']);
    }

    /**
     * Determine whether the user can export mass payment file data.
     */
    public function export(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only export files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Check if user has export permission
        return $user->hasPermission('mass_payments.export') || 
               $user->hasRole(['admin', 'finance_manager', 'finance_user']);
    }

    /**
     * Check if user can access files across different TCC accounts within their client.
     */
    public function crossAccountAccess(User $user): bool
    {
        // Only admin and finance managers can access files across TCC accounts
        return $user->hasRole(['admin', 'finance_manager']);
    }

    /**
     * Determine if the user can force approve a file (bypass normal approval rules).
     */
    public function forceApprove(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only force approve files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // Only admin users can force approve
        if (!$user->hasRole('admin')) {
            return false;
        }

        // File must be in a state where force approval makes sense
        return in_array($massPaymentFile->status, [
            MassPaymentFile::STATUS_PENDING_APPROVAL,
            MassPaymentFile::STATUS_VALIDATION_FAILED
        ]);
    }

    /**
     * Determine if user can view detailed validation errors.
     */
    public function viewValidationErrors(User $user, MassPaymentFile $massPaymentFile): bool
    {
        // User can only view validation errors for files from their own client
        if ($user->client_id !== $massPaymentFile->client_id) {
            return false;
        }

        // User must be the creator or have elevated privileges
        if ($massPaymentFile->created_by !== $user->id && 
            !$user->hasRole(['admin', 'finance_manager', 'approver'])) {
            return false;
        }

        return true;
    }
}