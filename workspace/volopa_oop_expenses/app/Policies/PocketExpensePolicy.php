<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpense;
use Illuminate\Auth\Access\HandlesAuthorization;

class PocketExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any pocket expenses.
     */
    public function viewAny(User $user): bool
    {
        // Check if user has permission to view pocket expenses
        return $this->hasPermissionForFeature($user, 'pocket_expense_management') ||
               $this->hasPermissionForFeature($user, 'view_pocket_expenses');
    }

    /**
     * Determine whether the user can view the pocket expense.
     */
    public function view(User $user, PocketExpense $pocketExpense): bool
    {
        // User can view their own expenses
        if ($pocketExpense->user_id === $user->id) {
            return true;
        }

        // Check if user has general permission to view pocket expenses
        if ($this->hasPermissionForFeature($user, 'pocket_expense_management')) {
            return $this->belongsToClient($user, $pocketExpense->client_id);
        }

        // Check if user can manage the expense owner
        return $this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id);
    }

    /**
     * Determine whether the user can create pocket expenses.
     */
    public function create(User $user): bool
    {
        // Check if user has permission to create pocket expenses
        return $this->hasPermissionForFeature($user, 'pocket_expense_management') ||
               $this->hasPermissionForFeature($user, 'create_pocket_expenses');
    }

    /**
     * Determine whether the user can update the pocket expense.
     */
    public function update(User $user, PocketExpense $pocketExpense): bool
    {
        // Users can only edit their own pending or rejected expenses
        if ($pocketExpense->user_id === $user->id) {
            return in_array($pocketExpense->status, ['pending', 'rejected']);
        }

        // Admins/managers can edit expenses with proper permissions
        if ($this->hasPermissionForFeature($user, 'pocket_expense_management')) {
            return $this->belongsToClient($user, $pocketExpense->client_id) &&
                   in_array($pocketExpense->status, ['pending', 'rejected']);
        }

        return false;
    }

    /**
     * Determine whether the user can delete the pocket expense.
     */
    public function delete(User $user, PocketExpense $pocketExpense): bool
    {
        // Users can only delete their own pending or rejected expenses
        if ($pocketExpense->user_id === $user->id) {
            return in_array($pocketExpense->status, ['pending', 'rejected']);
        }

        // Admins/managers can delete expenses with proper permissions
        if ($this->hasPermissionForFeature($user, 'pocket_expense_management') ||
            $this->hasPermissionForFeature($user, 'delete_pocket_expenses')) {
            return $this->belongsToClient($user, $pocketExpense->client_id) &&
                   in_array($pocketExpense->status, ['pending', 'rejected']);
        }

        return false;
    }

    /**
     * Determine whether the user can restore the pocket expense.
     */
    public function restore(User $user, PocketExpense $pocketExpense): bool
    {
        // Only admins/managers can restore expenses
        if ($this->hasPermissionForFeature($user, 'pocket_expense_management')) {
            return $this->belongsToClient($user, $pocketExpense->client_id);
        }

        return false;
    }

    /**
     * Determine whether the user can permanently delete the pocket expense.
     */
    public function forceDelete(User $user, PocketExpense $pocketExpense): bool
    {
        // Only super admin or specific permission holders can force delete
        return $user->hasRole('super_admin') ||
               ($this->hasPermissionForFeature($user, 'force_delete_pocket_expenses') &&
                $this->belongsToClient($user, $pocketExpense->client_id));
    }

    /**
     * Determine whether the user can approve pocket expenses.
     */
    public function approve(User $user, PocketExpense $pocketExpense): bool
    {
        // Users cannot approve their own expenses
        if ($pocketExpense->user_id === $user->id) {
            return false;
        }

        // Check if expense is in pending status
        if ($pocketExpense->status !== 'pending') {
            return false;
        }

        // Check if user has approval permission
        if (!$this->hasPermissionForFeature($user, 'approve_pocket_expenses')) {
            return false;
        }

        // Check client membership
        if (!$this->belongsToClient($user, $pocketExpense->client_id)) {
            return false;
        }

        // Check if user can manage the expense owner
        return $this->canManageUser($user, $pocketExpense->user_id, $pocketExpense->client_id);
    }

    /**
     * Determine whether the user can reject pocket expenses.
     */
    public function reject(User $user, PocketExpense $pocketExpense): bool
    {
        // Similar to approve but for rejection
        return $this->approve($user, $pocketExpense);
    }

    /**
     * Determine whether the user can create pocket expenses for another user.
     */
    public function createForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Check if user has permission to create expenses for others
        if (!$this->hasPermissionForFeature($user, 'create_pocket_expenses_for_others')) {
            return false;
        }

        // Check client membership
        if (!$this->belongsToClient($user, $clientId)) {
            return false;
        }

        // Check if user can manage the target user
        return $this->canManageUser($user, $targetUserId, $clientId);
    }

    /**
     * Determine whether the user can view expenses for a specific client.
     */
    public function viewForClient(User $user, int $clientId): bool
    {
        // Check if user has permission to view expenses for this client
        if (!$this->hasPermissionForFeature($user, 'pocket_expense_management')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can export pocket expense data.
     */
    public function export(User $user, int $clientId): bool
    {
        // Check if user has export permission
        if (!$this->hasPermissionForFeature($user, 'export_pocket_expenses')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can view expense reports.
     */
    public function viewReports(User $user, int $clientId): bool
    {
        // Check if user has reporting permission
        if (!$this->hasPermissionForFeature($user, 'view_pocket_expense_reports')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can manage expense settings.
     */
    public function manageSettings(User $user, int $clientId): bool
    {
        // Check if user has settings management permission
        if (!$this->hasPermissionForFeature($user, 'manage_pocket_expense_settings')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can bulk approve expenses.
     */
    public function bulkApprove(User $user, int $clientId): bool
    {
        // Check if user has bulk approval permission
        if (!$this->hasPermissionForFeature($user, 'bulk_approve_pocket_expenses')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can reprocess expenses.
     */
    public function reprocess(User $user, PocketExpense $pocketExpense): bool
    {
        // Check if user has reprocess permission
        if (!$this->hasPermissionForFeature($user, 'reprocess_pocket_expenses')) {
            return false;
        }

        // Check client membership
        if (!$this->belongsToClient($user, $pocketExpense->client_id)) {
            return false;
        }

        // Only allow reprocessing of failed or completed expenses
        return in_array($pocketExpense->status, ['rejected', 'approved']);
    }

    /**
     * Determine whether the user can upload CSV files for pocket expenses.
     */
    public function uploadCsv(User $user, int $clientId): bool
    {
        // Check if user has CSV upload permission
        if (!$this->hasPermissionForFeature($user, 'upload_pocket_expense_csv')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can manage expense metadata.
     */
    public function manageMetadata(User $user, PocketExpense $pocketExpense): bool
    {
        // Users can manage metadata of their own expenses if they're editable
        if ($pocketExpense->user_id === $user->id) {
            return $pocketExpense->canBeEdited();
        }

        // Check if user has metadata management permission
        if ($this->hasPermissionForFeature($user, 'manage_pocket_expense_metadata')) {
            return $this->belongsToClient($user, $pocketExpense->client_id);
        }

        return false;
    }

    /**
     * Determine whether the user can configure expense sources.
     */
    public function configureSources(User $user, int $clientId): bool
    {
        // Check if user has source configuration permission
        if (!$this->hasPermissionForFeature($user, 'configure_pocket_expense_sources')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can access FX conversion features.
     */
    public function accessFxConversion(User $user, int $clientId): bool
    {
        // Check if user has FX conversion permission
        if (!$this->hasPermissionForFeature($user, 'access_pocket_expense_fx')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user has a specific feature permission.
     */
    private function hasPermissionForFeature(User $user, string $featureName): bool
    {
        // Super admin can do everything
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin can manage pocket expenses
        if ($user->hasRole('admin') && in_array($featureName, [
            'pocket_expense_management',
            'view_pocket_expenses',
            'create_pocket_expenses',
            'approve_pocket_expenses',
            'export_pocket_expenses',
            'view_pocket_expense_reports',
            'bulk_approve_pocket_expenses',
            'reprocess_pocket_expenses',
            'upload_pocket_expense_csv',
            'manage_pocket_expense_metadata',
            'configure_pocket_expense_sources',
            'access_pocket_expense_fx'
        ])) {
            return true;
        }

        // Manager can approve and manage expenses
        if ($user->hasRole('manager') && in_array($featureName, [
            'view_pocket_expenses',
            'create_pocket_expenses',
            'approve_pocket_expenses',
            'export_pocket_expenses',
            'view_pocket_expense_reports',
            'bulk_approve_pocket_expenses',
            'upload_pocket_expense_csv',
            'manage_pocket_expense_metadata',
            'access_pocket_expense_fx'
        ])) {
            return true;
        }

        // Regular user can create and view their own expenses
        if ($user->hasRole('user') && in_array($featureName, [
            'create_pocket_expenses',
            'view_pocket_expenses',
            'manage_pocket_expense_metadata',
            'access_pocket_expense_fx'
        ])) {
            return true;
        }

        // Check specific feature permissions in database
        return \App\Models\UserFeaturePermission::where('user_id', $user->id)
            ->whereHas('feature', function ($query) use ($featureName) {
                $query->where('name', $featureName);
            })
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if user belongs to a specific client.
     */
    private function belongsToClient(User $user, int $clientId): bool
    {
        // If user has a direct client_id
        if (isset($user->client_id) && $user->client_id === $clientId) {
            return true;
        }

        // If user has multiple clients through a pivot table
        if ($user->clients()->where('client_id', $clientId)->exists()) {
            return true;
        }

        // Super admin can access all clients
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can manage another user.
     */
    private function canManageUser(User $user, int $targetUserId, int $clientId): bool
    {
        // User cannot manage themselves for expense approval
        if ($user->id === $targetUserId) {
            return false;
        }

        // Check if both users belong to the same client
        if (!$this->belongsToClient($user, $clientId)) {
            return false;
        }

        // Check if target user belongs to the client
        $targetUser = User::find($targetUserId);
        if (!$targetUser || !$this->belongsToClient($targetUser, $clientId)) {
            return false;
        }

        // Super admin can manage anyone
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin can manage non-admin users
        if ($user->hasRole('admin') && !$targetUser->hasRole('admin') && !$targetUser->hasRole('super_admin')) {
            return true;
        }

        // Manager can manage regular users
        if ($user->hasRole('manager') && $targetUser->hasRole('user')) {
            return true;
        }

        // Check if user is explicitly set as manager for the target user
        return \App\Models\UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('manager_user_id', $user->id)
            ->exists();
    }

    /**
     * Check expense amount limits for user actions.
     */
    private function checkAmountLimits(User $user, PocketExpense $pocketExpense, string $action): bool
    {
        // Define amount limits based on user role and action
        $limits = [
            'approve' => [
                'user' => 0,           // Users cannot approve
                'manager' => 1000.00,  // Managers can approve up to $1000
                'admin' => 10000.00,   // Admins can approve up to $10000
                'super_admin' => PHP_FLOAT_MAX // Super admins have no limit
            ],
            'create' => [
                'user' => 5000.00,     // Users can create up to $5000
                'manager' => 10000.00, // Managers can create up to $10000
                'admin' => PHP_FLOAT_MAX,
                'super_admin' => PHP_FLOAT_MAX
            ]
        ];

        if (!isset($limits[$action])) {
            return true; // No limits defined for this action
        }

        $userRole = $this->getUserHighestRole($user);
        $limit = $limits[$action][$userRole] ?? 0;

        // Use effective amount (converted if available)
        $amount = $pocketExpense->getEffectiveAmount();

        return $amount <= $limit;
    }

    /**
     * Get user's highest role for limit checking.
     */
    private function getUserHighestRole(User $user): string
    {
        if ($user->hasRole('super_admin')) {
            return 'super_admin';
        }
        if ($user->hasRole('admin')) {
            return 'admin';
        }
        if ($user->hasRole('manager')) {
            return 'manager';
        }
        return 'user';
    }

    /**
     * Check time-based restrictions for expense operations.
     */
    private function checkTimeRestrictions(User $user, PocketExpense $pocketExpense, string $action): bool
    {
        // Example: No expense modifications after 30 days
        if (in_array($action, ['update', 'delete']) && $pocketExpense->created_at->diffInDays(now()) > 30) {
            return $user->hasRole('admin') || $user->hasRole('super_admin');
        }

        // Example: No approvals outside business hours for non-admin users
        if ($action === 'approve' && !$user->hasRole('admin') && !$user->hasRole('super_admin')) {
            $hour = now()->hour;
            return $hour >= 9 && $hour <= 17; // 9 AM to 5 PM
        }

        return true;
    }

    /**
     * Comprehensive authorization check that combines multiple factors.
     */
    public function comprehensiveCheck(User $user, string $action, PocketExpense $pocketExpense = null, array $context = []): bool
    {
        // Basic permission check
        if (!$this->hasPermissionForFeature($user, $action)) {
            return false;
        }

        if ($pocketExpense) {
            // Client membership check
            if (!$this->belongsToClient($user, $pocketExpense->client_id)) {
                return false;
            }

            // Amount limits check
            if (!$this->checkAmountLimits($user, $pocketExpense, $action)) {
                return false;
            }

            // Time restrictions check
            if (!$this->checkTimeRestrictions($user, $pocketExpense, $action)) {
                return false;
            }

            // Status-based restrictions
            if (in_array($action, ['update', 'delete']) && !$pocketExpense->canBeEdited()) {
                return false;
            }

            if ($action === 'approve' && !$pocketExpense->canBeApproved()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user can view expense analytics.
     */
    public function viewAnalytics(User $user, int $clientId): bool
    {
        // Check if user has analytics permission
        if (!$this->hasPermissionForFeature($user, 'view_pocket_expense_analytics')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can manage expense categories.
     */
    public function manageCategories(User $user, int $clientId): bool
    {
        // Check if user has category management permission
        if (!$this->hasPermissionForFeature($user, 'manage_pocket_expense_categories')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can configure expense workflows.
     */
    public function configureWorkflows(User $user, int $clientId): bool
    {
        // Check if user has workflow configuration permission
        if (!$this->hasPermissionForFeature($user, 'configure_pocket_expense_workflows')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can access audit trails.
     */
    public function viewAuditTrail(User $user, int $clientId): bool
    {
        // Check if user has audit trail permission
        if (!$this->hasPermissionForFeature($user, 'view_pocket_expense_audit_trail')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can perform bulk operations.
     */
    public function bulkOperations(User $user, int $clientId): bool
    {
        // Check if user has bulk operations permission
        if (!$this->hasPermissionForFeature($user, 'bulk_pocket_expense_operations')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check rate limiting for expense operations.
     */
    private function checkRateLimit(User $user, string $operation): bool
    {
        // This could implement rate limiting to prevent abuse
        // E.g., max 50 expense submissions per day for regular users
        
        if ($user->hasRole('admin') || $user->hasRole('super_admin')) {
            return true; // No rate limits for admins
        }

        // Example rate limits
        $limits = [
            'create' => 50,   // Max 50 expenses per day
            'update' => 100,  // Max 100 updates per day
            'approve' => 200, // Max 200 approvals per day
        ];

        if (!isset($limits[$operation])) {
            return true;
        }

        // This would typically check against a cache or database
        // For now, always allow (implement based on business requirements)
        return true;
    }

    /**
     * Check if expense requires additional approvals.
     */
    public function requiresAdditionalApproval(User $user, PocketExpense $pocketExpense): bool
    {
        // High-value expenses might require additional approvals
        $amount = $pocketExpense->getEffectiveAmount();
        $userRole = $this->getUserHighestRole($user);

        // Define thresholds that require escalation
        $escalationThresholds = [
            'manager' => 5000.00,  // Manager approvals over $5000 need admin approval
            'admin' => 25000.00,   // Admin approvals over $25000 need super admin approval
        ];

        if (isset($escalationThresholds[$userRole])) {
            return $amount > $escalationThresholds[$userRole];
        }

        return false;
    }

    /**
     * Check if user can override expense validations.
     */
    public function overrideValidations(User $user, int $clientId): bool
    {
        // Only certain roles can override validations
        if (!$this->hasPermissionForFeature($user, 'override_pocket_expense_validations')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can access expense templates.
     */
    public function accessTemplates(User $user, int $clientId): bool
    {
        // Check if user has template access permission
        if (!$this->hasPermissionForFeature($user, 'access_pocket_expense_templates')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can manage expense integrations.
     */
    public function manageIntegrations(User $user, int $clientId): bool
    {
        // Check if user has integration management permission
        if (!$this->hasPermissionForFeature($user, 'manage_pocket_expense_integrations')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can view upload status and manage CSV uploads.
     */
    public function manageUploads(User $user, int $clientId): bool
    {
        // Check if user has upload management permission
        if (!$this->hasPermissionForFeature($user, 'manage_pocket_expense_uploads')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can download upload error files.
     */
    public function downloadUploadErrors(User $user, int $clientId): bool
    {
        // Check if user has error download permission
        if (!$this->hasPermissionForFeature($user, 'download_pocket_expense_upload_errors')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can sync expenses to main service.
     */
    public function syncToMainService(User $user, int $clientId): bool
    {
        // Check if user has sync permission
        if (!$this->hasPermissionForFeature($user, 'sync_pocket_expenses_to_main')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can configure expense types.
     */
    public function configureExpenseTypes(User $user, int $clientId): bool
    {
        // Check if user has expense type configuration permission
        if (!$this->hasPermissionForFeature($user, 'configure_pocket_expense_types')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can view detailed FX conversion information.
     */
    public function viewFxDetails(User $user, int $clientId): bool
    {
        // Check if user has FX details permission
        if (!$this->hasPermissionForFeature($user, 'view_pocket_expense_fx_details')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can manage expense approval workflows.
     */
    public function manageApprovalWorkflows(User $user, int $clientId): bool
    {
        // Check if user has approval workflow management permission
        if (!$this->hasPermissionForFeature($user, 'manage_pocket_expense_approval_workflows')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }
}