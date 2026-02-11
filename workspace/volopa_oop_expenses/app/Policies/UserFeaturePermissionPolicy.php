<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserFeaturePermissionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any user feature permissions.
     */
    public function viewAny(User $user): bool
    {
        // Check if user has permission to view user feature permissions
        // This should be based on the user's role or specific permissions
        return $this->hasPermissionForFeature($user, 'user_management');
    }

    /**
     * Determine whether the user can view the user feature permission.
     */
    public function view(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // User can view if they have general permission or if they are the grantor/manager
        return $this->hasPermissionForFeature($user, 'user_management') ||
               $userFeaturePermission->grantor_id === $user->id ||
               $userFeaturePermission->manager_user_id === $user->id;
    }

    /**
     * Determine whether the user can create user feature permissions.
     */
    public function create(User $user): bool
    {
        // Check if user has permission to grant user feature permissions
        return $this->hasPermissionForFeature($user, 'user_management') ||
               $this->hasPermissionForFeature($user, 'grant_permissions');
    }

    /**
     * Determine whether the user can update the user feature permission.
     */
    public function update(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Only the grantor or manager can update the permission
        return $userFeaturePermission->grantor_id === $user->id ||
               $userFeaturePermission->manager_user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the user feature permission.
     */
    public function delete(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Only the grantor or manager can delete/revoke the permission
        return $userFeaturePermission->grantor_id === $user->id ||
               $userFeaturePermission->manager_user_id === $user->id;
    }

    /**
     * Determine whether the user can restore the user feature permission.
     */
    public function restore(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Only the grantor or manager can restore the permission
        return $userFeaturePermission->grantor_id === $user->id ||
               $userFeaturePermission->manager_user_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the user feature permission.
     */
    public function forceDelete(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Only the grantor can permanently delete the permission
        return $userFeaturePermission->grantor_id === $user->id;
    }

    /**
     * Determine whether the user can grant permissions to another user.
     */
    public function grantPermission(User $user, int $targetUserId, int $clientId, int $featureId): bool
    {
        // Check if user has general permission to grant permissions
        if (!$this->hasPermissionForFeature($user, 'grant_permissions')) {
            return false;
        }

        // Check if user belongs to the same client
        if (!$this->belongsToClient($user, $clientId)) {
            return false;
        }

        // Check if user can manage the target user
        if (!$this->canManageUser($user, $targetUserId, $clientId)) {
            return false;
        }

        // Additional business logic: check if user has the feature they're trying to grant
        if (!$this->hasFeaturePermission($user, $featureId, $clientId)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can revoke permissions from another user.
     */
    public function revokePermission(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Check if user has general permission to revoke permissions
        if (!$this->hasPermissionForFeature($user, 'revoke_permissions')) {
            return false;
        }

        // Check if user is the grantor or manager of this permission
        if ($userFeaturePermission->grantor_id !== $user->id && 
            $userFeaturePermission->manager_user_id !== $user->id) {
            return false;
        }

        // Check if user belongs to the same client
        if (!$this->belongsToClient($user, $userFeaturePermission->client_id)) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can enable/disable permissions.
     */
    public function toggle(User $user, UserFeaturePermission $userFeaturePermission): bool
    {
        // Similar to update permission
        return $this->update($user, $userFeaturePermission);
    }

    /**
     * Determine whether the user can manage permissions for a specific client.
     */
    public function manageForClient(User $user, int $clientId): bool
    {
        // Check if user has permission to manage permissions for this client
        if (!$this->hasPermissionForFeature($user, 'user_management')) {
            return false;
        }

        // Check if user belongs to the client
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Determine whether the user can view permissions for a specific user.
     */
    public function viewUserPermissions(User $user, int $targetUserId, int $clientId): bool
    {
        // Check if user has general permission
        if ($this->hasPermissionForFeature($user, 'user_management')) {
            return $this->belongsToClient($user, $clientId);
        }

        // Check if user can manage the target user
        return $this->canManageUser($user, $targetUserId, $clientId);
    }

    /**
     * Check if user has a specific feature permission.
     */
    private function hasPermissionForFeature(User $user, string $featureName): bool
    {
        // This would typically check against a permissions system
        // For now, we'll use a simple role-based check
        
        // Super admin can do everything
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin can manage user permissions
        if ($user->hasRole('admin') && in_array($featureName, [
            'user_management', 
            'grant_permissions', 
            'revoke_permissions'
        ])) {
            return true;
        }

        // Manager can grant/revoke permissions
        if ($user->hasRole('manager') && in_array($featureName, [
            'grant_permissions', 
            'revoke_permissions'
        ])) {
            return true;
        }

        // Check specific feature permissions in database
        return UserFeaturePermission::where('user_id', $user->id)
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
        // This would check if the user belongs to the client
        // The exact implementation depends on your user-client relationship
        
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
        // User cannot manage themselves for permission changes
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
        return UserFeaturePermission::where('user_id', $targetUserId)
            ->where('client_id', $clientId)
            ->where('manager_user_id', $user->id)
            ->exists();
    }

    /**
     * Check if user has a specific feature permission for a client.
     */
    private function hasFeaturePermission(User $user, int $featureId, int $clientId): bool
    {
        // Super admin has all permissions
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if user can delegate permissions.
     */
    public function canDelegatePermissions(User $user, int $clientId): bool
    {
        // Check if user has delegation permission
        if (!$this->hasPermissionForFeature($user, 'delegate_permissions')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can view permission audit logs.
     */
    public function viewAuditLogs(User $user, int $clientId): bool
    {
        // Check if user has audit permission
        if (!$this->hasPermissionForFeature($user, 'view_audit_logs')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can bulk manage permissions.
     */
    public function bulkManage(User $user, int $clientId): bool
    {
        // Check if user has bulk management permission
        if (!$this->hasPermissionForFeature($user, 'bulk_permission_management')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can export permission data.
     */
    public function exportPermissions(User $user, int $clientId): bool
    {
        // Check if user has export permission
        if (!$this->hasPermissionForFeature($user, 'export_permissions')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check if user can manage permission templates.
     */
    public function manageTemplates(User $user, int $clientId): bool
    {
        // Check if user has template management permission
        if (!$this->hasPermissionForFeature($user, 'manage_permission_templates')) {
            return false;
        }

        // Check client membership
        return $this->belongsToClient($user, $clientId);
    }

    /**
     * Check permission hierarchy - ensure user cannot grant higher permissions than they have.
     */
    private function checkPermissionHierarchy(User $user, int $featureId, int $clientId): bool
    {
        // Super admin can grant any permission
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Get the feature being granted
        $feature = \App\Models\Feature::find($featureId);
        if (!$feature) {
            return false;
        }

        // Define permission hierarchy levels
        $hierarchyLevels = [
            'super_admin' => 100,
            'admin' => 80,
            'manager' => 60,
            'user' => 40,
            'guest' => 20,
        ];

        // Get user's highest role level
        $userLevel = 0;
        foreach ($hierarchyLevels as $role => $level) {
            if ($user->hasRole($role)) {
                $userLevel = max($userLevel, $level);
            }
        }

        // Get feature's required level (this would be stored in feature metadata)
        $requiredLevel = $feature->required_level ?? 40; // Default to user level

        // User can only grant permissions at or below their level
        return $userLevel >= $requiredLevel;
    }

    /**
     * Check if user can modify permissions during specific time windows.
     */
    private function checkTimeRestrictions(User $user, int $clientId): bool
    {
        // This could implement business hours restrictions, maintenance windows, etc.
        
        // For now, always allow (implement based on business requirements)
        return true;
    }

    /**
     * Check rate limiting for permission operations.
     */
    private function checkRateLimit(User $user, string $operation): bool
    {
        // This could implement rate limiting to prevent abuse
        // E.g., max 10 permission grants per minute
        
        // For now, always allow (implement based on business requirements)
        return true;
    }

    /**
     * Comprehensive authorization check that combines multiple factors.
     */
    public function comprehensiveCheck(User $user, string $action, array $context = []): bool
    {
        // Extract context
        $clientId = $context['client_id'] ?? null;
        $targetUserId = $context['target_user_id'] ?? null;
        $featureId = $context['feature_id'] ?? null;

        // Basic permission check
        if (!$this->hasPermissionForFeature($user, $action)) {
            return false;
        }

        // Client membership check
        if ($clientId && !$this->belongsToClient($user, $clientId)) {
            return false;
        }

        // Target user management check
        if ($targetUserId && !$this->canManageUser($user, $targetUserId, $clientId)) {
            return false;
        }

        // Permission hierarchy check
        if ($featureId && !$this->checkPermissionHierarchy($user, $featureId, $clientId)) {
            return false;
        }

        // Time restrictions check
        if ($clientId && !$this->checkTimeRestrictions($user, $clientId)) {
            return false;
        }

        // Rate limiting check
        if (!$this->checkRateLimit($user, $action)) {
            return false;
        }

        return true;
    }
}