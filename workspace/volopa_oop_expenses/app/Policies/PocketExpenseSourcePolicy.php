<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpenseSourceClientConfig;

/**
 * PocketExpenseSourcePolicy
 * 
 * Authorization policy for PocketExpenseSourceClientConfig operations.
 * Implements role-based access control with client-scoped permissions.
 * 
 * Business Rules:
 * - Only Primary Admin has full access to all sources by default
 * - Admin gets full access only to their own client sources by default
 * - Business User and Card User have limited access based on management rights
 * - Managing access can be given to any user irrespective of role
 * - Admin can only grant access to sources within their managed clients
 * - Global 'Other' source (client_id = NULL) is not deletable or editable
 */
class PocketExpenseSourcePolicy
{
    /**
     * Determine whether the user can view any expense sources.
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // TODO: Implement role-based check when User model role methods are available
        // For now, allow authenticated users to view sources they have access to
        return true;
    }

    /**
     * Determine whether the user can view the specific expense source.
     *
     * @param User $user
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function view(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' source is visible to all authenticated users
        if ($source->isGlobalOther()) {
            return true;
        }

        // TODO: Implement proper role and permission checking
        // For now, users can view sources from their client context
        return $this->belongsToUserClient($user, $source);
    }

    /**
     * Determine whether the user can create expense sources.
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // TODO: Implement role-based access control
        // Primary Admin and Admin should be able to create sources
        // Business User and Card User should not be able to create sources
        
        // For now, allow authenticated users with admin-like roles
        return true;
    }

    /**
     * Determine whether the user can update the expense source.
     *
     * @param User $user
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function update(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' record is not editable as per system constraints
        if ($source->isGlobalOther()) {
            return false;
        }

        // TODO: Implement proper role-based permissions
        // Only Primary Admin and Admin should be able to update sources
        // Must belong to user's client context
        return $this->belongsToUserClient($user, $source) && $this->canModifySources($user);
    }

    /**
     * Determine whether the user can delete the expense source.
     *
     * @param User $user
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function delete(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' record is not deletable as per system constraints
        if ($source->isGlobalOther()) {
            return false;
        }

        // TODO: Implement proper role-based permissions
        // Only Primary Admin and Admin should be able to delete sources
        // Must belong to user's client context
        return $this->belongsToUserClient($user, $source) && $this->canModifySources($user);
    }

    /**
     * Determine whether the user can restore the expense source.
     *
     * @param User $user
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function restore(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' record cannot be restored (it should never be deleted)
        if ($source->isGlobalOther()) {
            return false;
        }

        // TODO: Implement proper role-based permissions
        // Only Primary Admin and Admin should be able to restore sources
        return $this->belongsToUserClient($user, $source) && $this->canModifySources($user);
    }

    /**
     * Determine whether the user can force delete the expense source.
     *
     * @param User $user
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     */
    public function forceDelete(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // Global 'Other' record cannot be force deleted
        if ($source->isGlobalOther()) {
            return false;
        }

        // TODO: Implement proper role-based permissions
        // Only Primary Admin should be able to force delete sources
        return $this->isPrimaryAdmin($user) && $this->belongsToUserClient($user, $source);
    }

    /**
     * Check if the expense source belongs to the user's client context.
     *
     * @param User $user
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     */
    private function belongsToUserClient(User $user, PocketExpenseSourceClientConfig $source): bool
    {
        // TODO: Implement proper user-client relationship checking
        // For now, assume users have access to sources in their client context
        
        // Global sources are available to all clients
        if (is_null($source->client_id)) {
            return true;
        }

        // TODO: Get user's client_id from User model when available
        // return $user->client_id === $source->client_id;
        
        // Placeholder implementation
        return true;
    }

    /**
     * Check if the user has permissions to modify sources.
     *
     * @param User $user
     * @return bool
     */
    private function canModifySources(User $user): bool
    {
        // TODO: Implement proper role checking when User model methods are available
        // Primary Admin and Admin roles should be able to modify sources
        // Business User and Card User should not be able to modify sources
        
        return $this->isPrimaryAdmin($user) || $this->isAdmin($user);
    }

    /**
     * Check if the user is a Primary Admin.
     *
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        // TODO: Implement actual Primary Admin role check when User model is available
        // This should check the user's role field or relationship
        return false;
    }

    /**
     * Check if the user is an Admin.
     *
     * @param User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        // TODO: Implement actual Admin role check when User model is available
        // This should check the user's role field or relationship
        return false;
    }

    /**
     * Check if the user is a Business User.
     *
     * @param User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        // TODO: Implement actual Business User role check when User model is available
        return false;
    }

    /**
     * Check if the user is a Card User.
     *
     * @param User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        // TODO: Implement actual Card User role check when User model is available
        return false;
    }

    /**
     * Check if the user has management rights for the given client.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    private function hasManagementRights(User $user, int $clientId): bool
    {
        // TODO: Implement management rights checking via UserFeaturePermissionService
        // This should check if the user has been granted management access
        // for the specific client context, irrespective of their role
        return false;
    }

    /**
     * Check if the user can manage sources for the given client.
     *
     * @param User $user
     * @param int $clientId
     * @return bool
     */
    public function canManageClientSources(User $user, int $clientId): bool
    {
        // Primary Admin has access to all clients by default
        if ($this->isPrimaryAdmin($user)) {
            return true;
        }

        // Admin has access to their own client context
        if ($this->isAdmin($user)) {
            // TODO: Check if user belongs to the client
            return true;
        }

        // Business User and Card User need explicit management rights
        return $this->hasManagementRights($user, $clientId);
    }

    /**
     * Check if creating a new source would exceed the client limit.
     *
     * @param int $clientId
     * @return bool
     */
    public function wouldExceedClientLimit(int $clientId): bool
    {
        // TODO: Implement check for maximum 20 active expense sources per client
        $activeSourcesCount = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->count();
        
        return $activeSourcesCount >= 20;
    }
}