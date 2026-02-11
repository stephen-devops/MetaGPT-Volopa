<?php

namespace App\Policies;

use App\Models\User;
use App\Models\OopExpense;
use Illuminate\Auth\Access\HandlesAuthorization;

class OopExpensePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any OOP expenses.
     */
    public function viewAny(User $user): bool
    {
        // Check if user has permission to view OOP expenses
        return $this->hasPermissionForFeature($user, 'oop_expense_management') ||
               $this->hasPermissionForFeature($user, 'view_oop_expenses');
    }

    /**
     * Determine whether the user can view the OOP expense.
     */
    public function view(User $user, OopExpense $oopExpense): bool
    {
        // User can view their own expenses
        if ($oopExpense->user_id === $user->id) {
            return true;
        }

        // Check if user has general permission to view OOP expenses
        if ($this->hasPermissionForFeature($user, 'oop_expense_management')) {
            return $this->belongsToClient($user, $oopExpense->client_id);
        }

        // Check if user can manage the expense owner
        return $this->canManageUser($user, $oopExpense->user_id, $oopExpense->client_id);
    }

    /**
     * Determine whether the user can create OOP expenses.
     */
    public function create(User $user): bool
    {
        // Check if user has permission to create OOP expenses
        return $this->hasPermissionForFeature($user, 'oop_expense_management') ||
               $this->hasPermissionForFeature($user, 'create_oop_expenses');
    }

    /**
     * Determine whether the user can update the OOP expense.
     */
    public function update(User $user, OopExpense $oopExpense): bool
    {
        // Users can only edit their own pending or rejected expenses
        if ($oopExpense->user_id === $user->id) {
            return in_array($oopExpense->status, ['pending', 'rejected']);
        }

        // Admins/managers can edit expenses with proper permissions
        if ($this->hasPermissionForFeature($user, 'oop_expense_management')) {
            return $this->belongsToClient($user, $oopExpense->client_id) &&
                   in_array($oopExpense->status, ['pending', 'rejected']);
        }

        return false;
    }

    /**
     * Determine whether the user can delete the OOP expense.
     */
    public function delete(User $user, OopExpense $oopExpense): bool
    {
        // Users can only delete their own pending or rejected expenses
        if ($oopExpense->user_id === $user->id) {
            return in_array($oopExpense->status, ['pending', 'rejected']);
        }

        // Admins/managers can delete expenses with proper permissions
        if ($this->hasPermissionForFeature($user, 'oop_expense_management') ||
            $this->hasPermissionForFeature($user, 'delete_oop_expenses')) {
            return $this->belongsToClient($user, $oopExpense->client_id) &&
                   in_array($oopExpense->status, ['pending', 'rejected']);
        }

        return false;
    }

    /**
     * Determine whether the user can restore the OOP expense.
     */
    public function restore(User $user, OopExpense $oopExpense): bool
    {
        // Only admins/managers can restore expenses
        if ($this->hasPermissionForFeature($user, 'oop_expense_management')) {
            return $this->belongsToClient($user, $oopExpense->client_id);
        }

        return false;
    }

    /**
     * Determine whether the user can permanently delete the OOP expense.
     */
    public function forceDelete(User $user, OopExpense $oopExpense): bool
    {
        // Only super admin or specific permission holders can force delete
        return $user->hasRole('super_admin') ||
               ($this->hasPermissionForFeature($user, 'force_delete_oop_expenses') &&
                $this->belongsToClient($user, $oopExpense->client_id));
    }

    /**
     * Determine whether the user can approve OOP expenses.
     */
    public function approve(User $user, OopExpense $oopExpense): bool
    {
        // Users cannot approve their own expenses
        if ($oopExpense->user_id === $user->id) {
            return false;
        }

        // Check if expense is in pending status
        if ($oopExpense->status !== 'pending') {
            return false;
        }

        // Check if user has approval permission
        if (!$this->hasPermissionForFeature($user, 'approve_oop_expenses')) {
            return false;
        }

        // Check client membership
        if (!$this->belongsToClient($user, $oopExpense->client_id)) {
            return false;
        }

        // Check if user can manage the expense owner
        return $this->canManageUser($user, $oopExpense->user_id, $oopExpense->client_id);
    }

    /**
     * Determine whether the user can reject OOP expenses.
     */
    public function reject(User $user, OopExpense $oopExpense): bool
    {
        // Similar to approve but for rejection
        return $this->approve($user, $oopExpense);
    }

    /**
     * Determine whether the user can create OOP expenses for another user.
     */
    public function createForUser(User $user, int $targetUserId, int $clientId): bool
    {
        // Check if user has permission to create expenses for others
        if (!$this->hasPermissionForFeature($user, 'create_oop_expenses_for_others')) {
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
        if (!$this->hasPermissionForFeature($user, 'oop_expense_management')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can export OOP expense data.
     */
    public function export(User $user, int $clientId): bool
    {
        // Check if user has export permission
        if (!$this->hasPermissionForFeature($user, 'export_oop_expenses')) {
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
        if (!$this->hasPermissionForFeature($user, 'view_oop_expense_reports')) {
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
        if (!$this->hasPermissionForFeature($user, 'manage_oop_expense_settings')) {
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
        if (!$this->hasPermissionForFeature($user, 'bulk_approve_oop_expenses')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can reprocess expenses.
     */
    public function reprocess(User $user, OopExpense $oopExpense): bool
    {
        // Check if user has reprocess permission
        if (!$this->hasPermissionForFeature($user, 'reprocess_oop_expenses')) {
            return false;
        }

        // Check client membership
        if (!$this->belongsToClient($user, $oopExpense->client_id)) {
            return false;
        }

        // Only allow reprocessing of failed or completed expenses
        return in_array($oopExpense->status, ['rejected', 'approved']);
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

        // Admin can manage OOP expenses
        if ($user->hasRole('admin') && in_array($featureName, [
            'oop_expense_management',
            'view_oop_expenses',
            'create_oop_expenses',
            'approve_oop_expenses',
            'export_oop_expenses',
            'view_oop_expense_reports',
            'bulk_approve_oop_expenses',
            'reprocess_oop_expenses'
        ])) {
            return true;
        }

        // Manager can approve and manage expenses
        if ($user->hasRole('manager') && in_array($featureName, [
            'view_oop_expenses',
            'create_oop_expenses',
            'approve_oop_expenses',
            'export_oop_expenses',
            'view_oop_expense_reports',
            'bulk_approve_oop_expenses'
        ])) {
            return true;
        }

        // Regular user can create and view their own expenses
        if ($user->hasRole('user') && in_array($featureName, [
            'create_oop_expenses',
            'view_oop_expenses'
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
    private function checkAmountLimits(User $user, OopExpense $oopExpense, string $action): bool
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
        $amount = $oopExpense->getEffectiveAmount();

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
    private function checkTimeRestrictions(User $user, OopExpense $oopExpense, string $action): bool
    {
        // Example: No expense modifications after 30 days
        if (in_array($action, ['update', 'delete']) && $oopExpense->created_at->diffInDays(now()) > 30) {
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
    public function comprehensiveCheck(User $user, string $action, OopExpense $oopExpense = null, array $context = []): bool
    {
        // Basic permission check
        if (!$this->hasPermissionForFeature($user, $action)) {
            return false;
        }

        if ($oopExpense) {
            // Client membership check
            if (!$this->belongsToClient($user, $oopExpense->client_id)) {
                return false;
            }

            // Amount limits check
            if (!$this->checkAmountLimits($user, $oopExpense, $action)) {
                return false;
            }

            // Time restrictions check
            if (!$this->checkTimeRestrictions($user, $oopExpense, $action)) {
                return false;
            }

            // Status-based restrictions
            if (in_array($action, ['update', 'delete']) && !$oopExpense->canBeEdited()) {
                return false;
            }

            if ($action === 'approve' && !$oopExpense->canBeApproved()) {
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
        if (!$this->hasPermissionForFeature($user, 'view_oop_expense_analytics')) {
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
        if (!$this->hasPermissionForFeature($user, 'manage_oop_expense_categories')) {
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
        if (!$this->hasPermissionForFeature($user, 'configure_oop_expense_workflows')) {
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
        if (!$this->hasPermissionForFeature($user, 'view_oop_expense_audit_trail')) {
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
        if (!$this->hasPermissionForFeature($user, 'bulk_oop_expense_operations')) {
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
    public function requiresAdditionalApproval(User $user, OopExpense $oopExpense): bool
    {
        // High-value expenses might require additional approvals
        $amount = $oopExpense->getEffectiveAmount();
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
        if (!$this->hasPermissionForFeature($user, 'override_oop_expense_validations')) {
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
        if (!$this->hasPermissionForFeature($user, 'access_oop_expense_templates')) {
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
        if (!$this->hasPermissionForFeature($user, 'manage_oop_expense_integrations')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }
}