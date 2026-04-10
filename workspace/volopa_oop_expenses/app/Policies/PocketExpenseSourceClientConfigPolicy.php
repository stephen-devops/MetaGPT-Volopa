<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * PocketExpenseSourceClientConfigPolicy
 * 
 * Authorization policy for expense source management operations.
 * Handles permissions for creating, viewing, updating, and deleting expense sources.
 * Enforces client-specific access and prevents modification of global 'Other' source.
 */
class PocketExpenseSourceClientConfigPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any expense sources for a specific client.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    public function viewAny(User $user, int $clientId): bool
    {
        // TODO: Implement check if user has OOP Expense feature permission (feature_id = 16) for this client
        // Should query UserFeaturePermission table for enabled permission
        // For now, allow all authenticated users - this should be replaced with proper permission check
        return true;
    }

    /**
     * Determine whether the user can view the specific expense source.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function view(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Allow viewing global 'Other' source for all users
        if ($source->isGlobalOther()) {
            return true;
        }

        // For client-specific sources, ensure user has access to the client
        // TODO: Implement proper client access validation
        // Should check if user belongs to the same client or has management rights
        return true;
    }

    /**
     * Determine whether the user can create expense sources for a specific client.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    public function create(User $user, int $clientId): bool
    {
        // TODO: Implement permission checks:
        // 1. User must have OOP Expense feature permission (feature_id = 16) for this client
        // 2. User must be Admin or Primary Admin role
        // 3. Check if client has reached maximum 20 active expense sources limit
        // For now, allow all authenticated users - this should be replaced with proper permission check
        return true;
    }

    /**
     * Determine whether the user can update the specific expense source.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function update(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' source cannot be edited as per constraints
        if ($source->isGlobalOther()) {
            return false;
        }

        // For client-specific sources, check permissions
        // TODO: Implement permission checks:
        // 1. User must have OOP Expense feature permission for the source's client
        // 2. User must be Admin or Primary Admin role
        // 3. User must belong to the same client or have management rights over the client
        return true;
    }

    /**
     * Determine whether the user can delete the specific expense source.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function delete(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' source cannot be deleted as per constraints
        if ($source->isGlobalOther()) {
            return false;
        }

        // Cannot delete sources that are already soft deleted
        if ($source->isDeleted()) {
            return false;
        }

        // For client-specific sources, check permissions
        // TODO: Implement permission checks:
        // 1. User must have OOP Expense feature permission for the source's client
        // 2. User must be Admin or Primary Admin role
        // 3. User must belong to the same client or have management rights over the client
        // 4. Check if source is referenced in existing expense metadata (should prevent deletion)
        return true;
    }

    /**
     * Determine whether the user can restore a soft-deleted expense source.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function restore(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' source cannot be deleted, so no need to restore
        if ($source->isGlobalOther()) {
            return false;
        }

        // Can only restore sources that are currently deleted
        if (!$source->isDeleted()) {
            return false;
        }

        // TODO: Implement permission checks:
        // 1. User must have OOP Expense feature permission for the source's client
        // 2. User must be Admin or Primary Admin role
        // 3. User must belong to the same client or have management rights over the client
        // 4. Check if restoring would exceed the 20 active sources limit per client
        return true;
    }

    /**
     * Determine whether the user can force delete the expense source.
     * This is typically used for permanent deletion from the database.
     *
     * @param \App\Models\User $user
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function forceDelete(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' source can never be force deleted
        if ($source->isGlobalOther()) {
            return false;
        }

        // TODO: Implement permission checks:
        // 1. Only Primary Admin should be able to force delete
        // 2. Check if source is referenced in any expense metadata (should prevent force deletion)
        // 3. This operation should be extremely restricted
        return false; // Default to deny for security
    }

    /**
     * Helper method to check if user has OOP Expense feature permission for a client.
     * This is a placeholder for the actual permission checking logic.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    private function hasOOPExpensePermission(User $user, int $clientId): bool
    {
        // TODO: Implement actual permission check:
        // Query UserFeaturePermission table where:
        // - user_id = $user->id
        // - client_id = $clientId  
        // - feature_id = 16 (OOP Expense feature)
        // - is_enabled = true
        return true; // Placeholder - always allow for now
    }

    /**
     * Helper method to check if user belongs to or manages a specific client.
     * This is a placeholder for the actual client access validation.
     *
     * @param \App\Models\User $user
     * @param int $clientId
     * @return bool
     */
    private function hasClientAccess(User $user, int $clientId): bool
    {
        // TODO: Implement actual client access check:
        // This should verify if user belongs to the client or has management rights
        // May need to check user-client relationships or management hierarchy
        return true; // Placeholder - always allow for now
    }

    /**
     * Helper method to check if user has required role for expense source operations.
     * This is a placeholder for role-based authorization.
     *
     * @param \App\Models\User $user
     * @return bool
     */
    private function hasRequiredRole(User $user): bool
    {
        // TODO: Implement role checking:
        // Should verify user is Admin or Primary Admin
        // Business User and Card User should not have source management rights
        return true; // Placeholder - always allow for now
    }
}