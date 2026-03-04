## Code: app/Services/UserPermissionService.php

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;
use InvalidArgumentException;

/**
 * UserPermissionService
 * 
 * Service class for managing user feature permissions in the delegation-based RBAC system.
 * Handles permission granting, revoking, validation, and user management operations
 * with multi-tenant support and role hierarchy enforcement.
 * 
 * Business Rules:
 * - Primary Administrator has full access to all users' permissions
 * - Administrator requires explicit delegation to manage other users' permissions
 * - Business User and Card User cannot approve expenses even with management rights
 * - Permission delegation can be granted by Primary Admin to any user regardless of role
 * - Admin can only grant access to their own managed users, not all users
 * - Revoked users fall back to Primary Administrator management until reassigned
 */
class UserPermissionService
{
    /**
     * Primary Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Administrator';

    /**
     * Business User role identifier.
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role identifier.
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Default feature ID for pocket expense management.
     *
     * @var int
     */
    private const DEFAULT_POCKET_EXPENSE_FEATURE_ID = 1;

    /**
     * Grant a feature permission to a user with delegation management.
     *
     * @param int $userId User receiving the permission
     * @param int $clientId Client context for multi-tenancy
     * @param int $featureId Feature being granted access to
     * @param int $grantorId User who is granting this permission
     * @param int $managerId User who will manage this permission
     * @return UserFeaturePermission The created permission record
     * 
     * @throws InvalidArgumentException If validation fails
     * @throws Exception If permission creation fails
     */
    public function grantPermission(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId): UserFeaturePermission
    {
        // Validate input parameters
        $this->validatePermissionInput($userId, $clientId, $featureId, $grantorId, $managerId);

        // Check if grantor has authority to grant permission
        $grantor = User::where('id', $grantorId)
            ->where('client_id', $clientId)
            ->first();

        if (!$grantor) {
            throw new InvalidArgumentException('Grantor user not found or does not belong to the specified client.');
        }

        if (!$this->canGrantPermission($grantor, $userId, $clientId, $featureId)) {
            throw new InvalidArgumentException('Grantor does not have authority to grant this permission.');
        }

        // Check if permission already exists
        $existingPermission = UserFeaturePermission::where([
            'user_id' => $userId,
            'client_id' => $clientId,
            'feature_id' => $featureId,
        ])->first();

        if ($existingPermission) {
            if ($existingPermission->is_enabled) {
                throw new InvalidArgumentException('Permission already exists and is active.');
            } else {
                // Reactivate existing disabled permission
                $existingPermission->update([
                    'is_enabled' => true,
                    'grantor_id' => $grantorId,
                    'manager_user_id' => $managerId,
                ]);

                Log::info('User feature permission reactivated', [
                    'permission_id' => $existingPermission->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'feature_id' => $featureId,
                    'grantor_id' => $grantorId,
                    'manager_id' => $managerId,
                ]);

                return $existingPermission->fresh();
            }
        }

        // Validate manager user
        $manager = User::where('id', $managerId)
            ->where('client_id', $clientId)
            ->first();

        if (!$manager) {
            throw new InvalidArgumentException('Manager user not found or does not belong to the specified client.');
        }

        // Validate target user
        $targetUser = User::where('id', $userId)
            ->where('client_id', $clientId)
            ->first();

        if (!$targetUser) {
            throw new InvalidArgumentException('Target user not found or does not belong to the specified client.');
        }

        try {
            DB::beginTransaction();

            // Create new permission record
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => true,
            ]);

            DB::commit();

            Log::info('User feature permission granted', [
                'permission_id' => $permission->id,
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
            ]);

            return $permission;

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to grant user feature permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_id' => $managerId,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to grant permission: ' . $e->getMessage());
        }
    }

    /**
     * Revoke a user feature permission.
     *
     * @param int $permissionId The permission ID to revoke
     * @param int $revokerUserId User who is revoking the permission
     * @return bool True if permission was successfully revoked
     * 
     * @throws InvalidArgumentException If validation fails
     * @throws Exception If permission revocation fails
     */
    public function revokePermission(int $permissionId, int $revokerUserId): bool
    {
        if ($permissionId <= 0) {
            throw new InvalidArgumentException('Permission ID must be a positive integer.');
        }

        if ($revokerUserId <= 0) {
            throw new InvalidArgumentException('Revoker user ID must be a positive integer.');
        }

        // Find the permission to revoke
        $permission = UserFeaturePermission::where('id', $permissionId)->first();

        if (!$permission) {
            throw new InvalidArgumentException('Permission not found.');
        }

        // Get the revoker user
        $revoker = User::where('id', $revokerUserId)
            ->where('client_id', $permission->client_id)
            ->first();

        if (!$revoker) {
            throw new InvalidArgumentException('Revoker user not found or does not belong to the same client.');
        }

        // Check if revoker has authority to revoke this permission
        if (!$this->canRevokePermission($revoker, $permission)) {
            throw new InvalidArgumentException('You do not have authority to revoke this permission.');
        }

        try {
            DB::beginTransaction();

            // Disable the permission instead of deleting it (