<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserFeaturePermission;
use App\Models\Client;
use App\Models\Feature;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * UserFeaturePermissionService
 * 
 * Service class for managing user feature permissions within the multi-tenant system.
 * Handles permission granting, revoking, checking, and delegation workflows for
 * OOP expense management and other platform features. Provides business logic layer
 * between controllers and models with comprehensive validation and audit support.
 * 
 * Key responsibilities:
 * - Grant and revoke user feature permissions
 * - Check user permission status and capabilities
 * - Manage delegation relationships between users
 * - Handle permission inheritance and cascading
 * - Provide audit trails and permission history
 * - Support multi-tenant permission scoping
 */
class UserFeaturePermissionService
{
    /**
     * OOP Feature ID constant for permission management
     *
     * @var int
     */
    private const OOP_FEATURE_ID = 16;

    /**
     * Primary Administrator role name
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role name
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Admin';

    /**
     * Business User role name
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role name
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Maximum number of permissions a user can have per client
     *
     * @var int
     */
    private const MAX_PERMISSIONS_PER_USER = 50;

    /**
     * Maximum number of users a single user can grant permissions to
     *
     * @var int
     */
    private const MAX_DELEGATED_USERS = 100;

    /**
     * Cache timeout for permission checks (in minutes)
     *
     * @var int
     */
    private const CACHE_TIMEOUT_MINUTES = 15;

    /**
     * Default permission details for new permissions
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_PERMISSION_DETAILS = [
        'can_approve' => false,
        'can_manage' => false,
        'can_delegate' => false,
        'granted_at' => null,
        'granted_reason' => null,
        'expiry_date' => null,
        'notes' => null
    ];

    /**
     * Grant feature permission to a user.
     * 
     * Creates a new UserFeaturePermission record with proper validation,
     * audit trails, and relationship management. Supports delegation
     * scenarios and permission-specific details configuration.
     *
     * @param array<string, mixed> $permissionData
     * @return UserFeaturePermission
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function grantPermission(array $permissionData): UserFeaturePermission
    {
        // Validate required fields
        $this->validatePermissionData($permissionData);

        // Extract and set defaults
        $userId = (int) $permissionData['user_id'];
        $clientId = (int) $permissionData['client_id'];
        $featureId = (int) $permissionData['feature_id'];
        $grantorId = (int) $permissionData['grantor_id'];
        $managerUserId = isset($permissionData['manager_user_id']) ? (int) $permissionData['manager_user_id'] : null;
        $isEnabled = $permissionData['is_enabled'] ?? true;
        $detailsJson = $permissionData['details_json'] ?? null;

        // Check if permission already exists
        $existingPermission = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->first();

        if ($existingPermission) {
            throw new \InvalidArgumentException(
                "User {$userId} already has permission for feature {$featureId} in client {$clientId}"
            );
        }

        // Validate business rules
        $this->validatePermissionGrantRules($userId, $clientId, $featureId, $grantorId, $managerUserId);

        try {
            DB::beginTransaction();

            // Create the permission record
            $permission = UserFeaturePermission::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerUserId,
                'is_enabled' => $isEnabled,
                'details_json' => $this->processPermissionDetails($detailsJson)
            ]);

            // Log the permission grant
            $this->logPermissionAction('granted', $permission, $grantorId, [
                'reason' => $detailsJson['granted_reason'] ?? 'Permission granted via API',
                'details' => $detailsJson
            ]);

            DB::commit();

            // Clear related caches
            $this->clearPermissionCache($userId, $clientId);

            return $permission->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to grant permission', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => $featureId,
                'grantor_id' => $grantorId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to grant permission: ' . $e->getMessage());
        }
    }

    /**
     * Revoke feature permission from a user.
     * 
     * Removes or disables a UserFeaturePermission record with proper
     * cascade handling for dependent permissions and relationships.
     * Maintains audit trails and handles cleanup operations.
     *
     * @param int $permissionId
     * @return bool
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function revokePermission(int $permissionId): bool
    {
        $permission = UserFeaturePermission::find($permissionId);

        if (!$permission) {
            throw new \InvalidArgumentException("Permission with ID {$permissionId} not found");
        }

        // Check for dependent permissions before revoking
        $dependentPermissions = $this->getDependentPermissions($permission);
        if ($dependentPermissions->isNotEmpty()) {
            throw new \RuntimeException(
                "Cannot revoke permission: user has granted {$dependentPermissions->count()} dependent permissions to others"
            );
        }

        try {
            DB::beginTransaction();

            // Log the revocation before deletion
            $this->logPermissionAction('revoked', $permission, auth()->user()->id ?? null, [
                'reason' => 'Permission revoked via API'
            ]);

            // Handle soft delete vs hard delete based on business rules
            if ($this->shouldSoftDeletePermission($permission)) {
                $permission->update(['is_enabled' => false]);
                $result = true;
            } else {
                $result = $permission->delete();
            }

            DB::commit();

            // Clear related caches
            $this->clearPermissionCache($permission->user_id, $permission->client_id);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to revoke permission', [
                'permission_id' => $permissionId,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to revoke permission: ' . $e->getMessage());
        }
    }

    /**
     * Check if a user has permission for a specific feature.
     * 
     * Performs comprehensive permission checking including role-based
     * defaults, explicit permissions, inheritance, and caching for
     * performance optimization.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function checkPermission(int $userId, int $clientId, int $featureId): bool
    {
        // Check cache first
        $cacheKey = "user_permission_{$userId}_{$clientId}_{$featureId}";
        
        if (cache()->has($cacheKey)) {
            return cache()->get($cacheKey, false);
        }

        $hasPermission = $this->performPermissionCheck($userId, $clientId, $featureId);

        // Cache the result
        cache()->put($cacheKey, $hasPermission, now()->addMinutes(self::CACHE_TIMEOUT_MINUTES));

        return $hasPermission;
    }

    /**
     * Get all users that a manager user can manage.
     * 
     * Returns collection of users based on delegation relationships,
     * role hierarchies, and permission management rights. Used for
     * building management interfaces and access control.
     *
     * @param int $managerUserId
     * @param int $clientId
     * @return Collection<int, User>
     */
    public function getManagedUsers(int $managerUserId, int $clientId): Collection
    {
        $manager = User::find($managerUserId);
        
        if (!$manager || $manager->client_id !== $clientId) {
            return collect();
        }

        // Primary Admins and Admins can manage all users in their client
        if (in_array($manager->role, [self::ROLE_PRIMARY_ADMIN, self::ROLE_ADMIN])) {
            return User::where('client_id', $clientId)
                ->where('id', '!=', $managerUserId)
                ->orderBy('name')
                ->get();
        }

        // Business Users can manage users they have granted permissions to or are designated to manage
        if ($manager->role === self::ROLE_BUSINESS_USER) {
            $grantedUserIds = UserFeaturePermission::where('grantor_id', $managerUserId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->pluck('user_id')
                ->unique();

            $managedUserIds = UserFeaturePermission::where('manager_user_id', $managerUserId)
                ->where('client_id', $clientId)
                ->where('is_enabled', true)
                ->pluck('user_id')
                ->unique();

            $allManagedUserIds = $grantedUserIds->merge($managedUserIds)->unique();

            if ($allManagedUserIds->isEmpty()) {
                return collect();
            }

            return User::whereIn('id', $allManagedUserIds)
                ->where('client_id', $clientId)
                ->orderBy('name')
                ->get();
        }

        // Card Users cannot manage other users
        return collect();
    }

    /**
     * Get all permissions for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection<int, UserFeaturePermission>
     */
    public function getUserPermissions(int $userId, int $clientId): Collection
    {
        return UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->with(['feature', 'grantor', 'manager'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all permissions granted by a specific user.
     *
     * @param int $grantorId
     * @param int $clientId
     * @return Collection<int, UserFeaturePermission>
     */
    public function getGrantedPermissions(int $grantorId, int $clientId): Collection
    {
        return UserFeaturePermission::where('grantor_id', $grantorId)
            ->where('client_id', $clientId)
            ->with(['user', 'feature', 'manager'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all permissions managed by a specific user.
     *
     * @param int $managerId
     * @param int $clientId
     * @return Collection<int, UserFeaturePermission>
     */
    public function getManagedPermissions(int $managerId, int $clientId): Collection
    {
        return UserFeaturePermission::where('manager_user_id', $managerId)
            ->where('client_id', $clientId)
            ->with(['user', 'feature', 'grantor'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Update permission details and settings.
     *
     * @param int $permissionId
     * @param array<string, mixed> $updateData
     * @return UserFeaturePermission
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function updatePermission(int $permissionId, array $updateData): UserFeaturePermission
    {
        $permission = UserFeaturePermission::find($permissionId);

        if (!$permission) {
            throw new \InvalidArgumentException("Permission with ID {$permissionId} not found");
        }

        // Validate update data
        $this->validatePermissionUpdateData($updateData, $permission);

        try {
            DB::beginTransaction();

            $originalData = $permission->toArray();

            // Update basic fields
            if (isset($updateData['manager_user_id'])) {
                $managerId = $updateData['manager_user_id'];
                if ($managerId !== null) {
                    $this->validateManagerUser((int) $managerId, $permission->client_id, $permission->user_id);
                }
                $permission->manager_user_id = $managerId;
            }

            if (isset($updateData['is_enabled'])) {
                $newStatus = (bool) $updateData['is_enabled'];
                
                // Check for dependent permissions if disabling
                if (!$newStatus && $permission->is_enabled) {
                    $dependentPermissions = $this->getDependentPermissions($permission);
                    if ($dependentPermissions->isNotEmpty()) {
                        throw new \RuntimeException(
                            "Cannot disable permission: user has {$dependentPermissions->count()} dependent permissions"
                        );
                    }
                }
                
                $permission->is_enabled = $newStatus;
            }

            // Update details JSON
            if (isset($updateData['details_json'])) {
                $existingDetails = $permission->details_json ?? [];
                $newDetails = is_array($updateData['details_json']) ? $updateData['details_json'] : [];
                $mergedDetails = array_merge($existingDetails, $newDetails);
                $permission->details_json = $this->processPermissionDetails($mergedDetails);
            }

            $permission->save();

            // Log the update
            $this->logPermissionAction('updated', $permission, auth()->user()->id ?? null, [
                'original_data' => $originalData,
                'updated_fields' => array_keys($updateData),
                'reason' => $updateData['updated_reason'] ?? 'Permission updated via API'
            ]);

            DB::commit();

            // Clear related caches
            $this->clearPermissionCache($permission->user_id, $permission->client_id);

            return $permission->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update permission', [
                'permission_id' => $permissionId,
                'update_data' => $updateData,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to update permission: ' . $e->getMessage());
        }
    }

    /**
     * Get permission statistics for a client.
     *
     * @param int $clientId
     * @return array<string, mixed>
     */
    public function getPermissionStatistics(int $clientId): array
    {
        $totalPermissions = UserFeaturePermission::where('client_id', $clientId)->count();
        $activePermissions = UserFeaturePermission::where('client_id', $clientId)
            ->where('is_enabled', true)
            ->count();
        $inactivePermissions = $totalPermissions - $activePermissions;

        $permissionsByFeature = UserFeaturePermission::where('client_id', $clientId)
            ->where('is_enabled', true)
            ->select('feature_id', DB::raw('count(*) as count'))
            ->groupBy('feature_id')
            ->with('feature')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->feature->name ?? "Feature {$item->feature_id}" => $item->count];
            });

        $permissionsByRole = UserFeaturePermission::where('client_id', $clientId)
            ->where('is_enabled', true)
            ->join('users', 'user_feature_permission.user_id', '=', 'users.id')
            ->select('users.role', DB::raw('count(*) as count'))
            ->groupBy('users.role')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->role => $item->count];
            });

        $recentGrants = UserFeaturePermission::where('client_id', $clientId)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'total_permissions' => $totalPermissions,
            'active_permissions' => $activePermissions,
            'inactive_permissions' => $inactivePermissions,
            'permissions_by_feature' => $permissionsByFeature->toArray(),
            'permissions_by_role' => $permissionsByRole->toArray(),
            'recent_grants_last_7_days' => $recentGrants,
            'most_active_grantor' => $this->getMostActiveGrantor($clientId),
            'permission_utilization_rate' => $this->getPermissionUtilizationRate($clientId)
        ];
    }

    /**
     * Check if a user can delegate permissions to others.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public function canDelegate(int $userId, int $clientId, int $featureId): bool
    {
        $user = User::find($userId);
        
        if (!$user || $user->client_id !== $clientId) {
            return false;
        }

        // Primary Admins and Admins can always delegate
        if (in_array($user->role, [self::ROLE_PRIMARY_ADMIN, self::ROLE_ADMIN])) {
            return true;
        }

        // Business Users need explicit delegation permissions
        if ($user->role === self::ROLE_BUSINESS_USER) {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->where('is_enabled', true)
                ->first();

            if (!$permission || !$permission->details_json) {
                return false;
            }

            return $permission->details_json['can_delegate'] ?? false;
        }

        // Card Users cannot delegate
        return false;
    }

    /**
     * Get users eligible to receive permissions from a grantor.
     *
     * @param int $grantorId
     * @param int $clientId
     * @param int $featureId
     * @return Collection<int, User>
     */
    public function getEligibleUsers(int $grantorId, int $clientId, int $featureId): Collection
    {
        $grantor = User::find($grantorId);
        
        if (!$grantor || $grantor->client_id !== $clientId) {
            return collect();
        }

        // Get users who don't already have this permission
        $existingUserIds = UserFeaturePermission::where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->pluck('user_id');

        $eligibleUsers = User::where('client_id', $clientId)
            ->where('id', '!=', $grantorId)
            ->whereNotIn('id', $existingUserIds)
            ->orderBy('name')
            ->get();

        // Filter based on business rules
        return $eligibleUsers->filter(function ($user) use ($grantor, $featureId) {
            return $this->canGrantToUser($grantor, $user, $featureId);
        });
    }

    /**
     * Bulk grant permissions to multiple users.
     *
     * @param array<int, array<string, mixed>> $permissionsData
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    public function bulkGrantPermissions(array $permissionsData): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'skipped' => [],
            'summary' => [
                'total' => count($permissionsData),
                'successful_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0
            ]
        ];

        DB::beginTransaction();

        try {
            foreach ($permissionsData as $index => $permissionData) {
                try {
                    // Check if permission already exists
                    $existing = UserFeaturePermission::where('user_id', $permissionData['user_id'])
                        ->where('client_id', $permissionData['client_id'])
                        ->where('feature_id', $permissionData['feature_id'])
                        ->first();

                    if ($existing) {
                        $results['skipped'][] = [
                            'index' => $index,
                            'data' => $permissionData,
                            'reason' => 'Permission already exists'
                        ];
                        $results['summary']['skipped_count']++;
                        continue;
                    }

                    $permission = $this->grantPermission($permissionData);
                    
                    $results['successful'][] = [
                        'index' => $index,
                        'permission_id' => $permission->id,
                        'data' => $permissionData
                    ];
                    $results['summary']['successful_count']++;

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'index' => $index,
                        'data' => $permissionData,
                        'error' => $e->getMessage()
                    ];
                    $results['summary']['failed_count']++;
                }
            }

            DB::commit();

            Log::info('Bulk permission grant completed', [
                'summary' => $results['summary']
            ]);

            return $results;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException('Bulk permission grant failed: ' . $e->getMessage());
        }
    }

    /**
     * Get permission history for audit purposes.
     *
     * @param int $userId
     * @param int $clientId
     * @param int|null $featureId
     * @return Collection
     */
    public function getPermissionHistory(int $userId, int $clientId, ?int $featureId = null): Collection
    {
        // This would typically query an audit log table
        // For now, return current permissions with timestamps
        $query = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->with(['feature', 'grantor', 'manager']);

        if ($featureId !== null) {
            $query->where('feature_id', $featureId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Validate permission data for creation.
     *
     * @param array<string, mixed> $permissionData
     * @throws \InvalidArgumentException
     */
    private function validatePermissionData(array $permissionData): void
    {
        $required = ['user_id', 'client_id', 'feature_id', 'grantor_id'];
        
        foreach ($required as $field) {
            if (!isset($permissionData[$field]) || !is_numeric($permissionData[$field])) {
                throw new \InvalidArgumentException("Required field '{$field}' is missing or invalid");
            }
        }

        // Validate user exists and belongs to client
        $user = User::find($permissionData['user_id']);
        if (!$user || $user->client_id !== (int) $permissionData['client_id']) {
            throw new \InvalidArgumentException('Target user does not exist or belongs to different client');
        }

        // Validate grantor exists and belongs to client
        $grantor = User::find($permissionData['grantor_id']);
        if (!$grantor || $grantor->client_id !== (int) $permissionData['client_id']) {
            throw new \InvalidArgumentException('Grantor does not exist or belongs to different client');
        }

        // Validate feature exists
        $feature = Feature::find($permissionData['feature_id']);
        if (!$feature) {
            throw new \InvalidArgumentException('Feature does not exist');
        }

        // Validate manager if provided
        if (isset($permissionData['manager_user_id']) && $permissionData['manager_user_id'] !== null) {
            $manager = User::find($permissionData['manager_user_id']);
            if (!$manager || $manager->client_id !== (int) $permissionData['client_id']) {
                throw new \InvalidArgumentException('Manager does not exist or belongs to different client');
            }
        }
    }

    /**
     * Validate permission grant business rules.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @param int $grantorId
     * @param int|null $managerUserId
     * @throws \InvalidArgumentException
     */
    private function validatePermissionGrantRules(int $userId, int $clientId, int $featureId, int $grantorId, ?int $managerUserId): void
    {
        // Check permission count limits
        $userPermissionCount = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->count();

        if ($userPermissionCount >= self::MAX_PERMISSIONS_PER_USER) {
            throw new \InvalidArgumentException("User has reached maximum permission limit of " . self::MAX_PERMISSIONS_PER_USER);
        }

        // Check delegation limits for grantor
        $grantedCount = UserFeaturePermission::where('grantor_id', $grantorId)
            ->where('client_id', $clientId)
            ->count();

        if ($grantedCount >= self::MAX_DELEGATED_USERS) {
            throw new \InvalidArgumentException("Grantor has reached maximum delegation limit of " . self::MAX_DELEGATED_USERS);
        }

        // Validate grantor has permission to grant this feature
        if (!$this->checkPermission($grantorId, $clientId, $featureId)) {
            throw new \InvalidArgumentException('Grantor does not have permission for this feature');
        }

        // Validate grantor can delegate
        if (!$this->canDelegate($grantorId, $clientId, $featureId)) {
            throw new \InvalidArgumentException('Grantor does not have delegation rights for this feature');
        }

        // Validate manager assignment if provided
        if ($managerUserId !== null) {
            $this->validateManagerUser($managerUserId, $clientId, $userId);
        }

        // Check for circular relationships
        if ($managerUserId !== null && $this->wouldCreateCircularRelationship($userId, $managerUserId, $clientId)) {
            throw new \InvalidArgumentException('Manager assignment would create circular relationship');
        }
    }

    /**
     * Validate manager user assignment.
     *
     * @param int $managerId
     * @param int $clientId
     * @param int $targetUserId
     * @throws \InvalidArgumentException
     */
    private function validateManagerUser(int $managerId, int $clientId, int $targetUserId): void
    {
        $manager = User::find($managerId);
        
        if (!$manager || $manager->client_id !== $clientId) {
            throw new \InvalidArgumentException('Manager does not exist or belongs to different client');
        }

        // Manager cannot be the target user
        if ($managerId === $targetUserId) {
            throw new \InvalidArgumentException('User cannot be their own manager');
        }

        // Manager must have appropriate role or permissions
        $allowedRoles = [self::ROLE_PRIMARY_ADMIN, self::ROLE_ADMIN];
        if (!in_array($manager->role, $allowedRoles)) {
            if ($manager->role === self::ROLE_BUSINESS_USER) {
                // Check if Business User has management permissions
                $hasManagementPermission = UserFeaturePermission::where('user_id', $managerId)
                    ->where('client_id', $clientId)
                    ->where('feature_id', self::OOP_FEATURE_ID)
                    ->where('is_enabled', true)
                    ->whereJsonContains('details_json->can_manage', true)
                    ->exists();

                if (!$hasManagementPermission) {
                    throw new \InvalidArgumentException('Manager does not have management permissions');
                }
            } else {
                throw new \InvalidArgumentException('Manager does not have sufficient role or permissions');
            }
        }
    }

    /**
     * Check if manager assignment would create circular relationship.
     *
     * @param int $userId
     * @param int $managerId
     * @param int $clientId
     * @return bool
     */
    private function wouldCreateCircularRelationship(int $userId, int $managerId, int $clientId): bool
    {
        // Check if the target user is already managing the proposed manager
        return UserFeaturePermission::where('user_id', $managerId)
            ->where('manager_user_id', $userId)
            ->where('client_id', $clientId)
            ->exists();
    }

    /**
     * Process and validate permission details JSON.
     *
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    private function processPermissionDetails(?array $details): ?array
    {
        if (!$details) {
            return null;
        }

        $processedDetails = array_merge(self::DEFAULT_PERMISSION_DETAILS, $details);

        // Validate boolean fields
        foreach (['can_approve', 'can_manage', 'can_delegate'] as $boolField) {
            if (isset($processedDetails[$boolField])) {
                $processedDetails[$boolField] = (bool) $processedDetails[$boolField];
            }
        }

        // Process dates
        if (isset($processedDetails['expiry_date']) && $processedDetails['expiry_date']) {
            try {
                $processedDetails['expiry_date'] = Carbon::parse($processedDetails['expiry_date'])->toISOString();
            } catch (\Exception $e) {
                unset($processedDetails['expiry_date']);
            }
        }

        // Set granted timestamp
        $processedDetails['granted_at'] = now()->toISOString();

        // Clean up null values
        return array_filter($processedDetails, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Perform actual permission check logic.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    private function performPermissionCheck(int $userId, int $clientId, int $featureId): bool
    {
        $user = User::find($userId);
        
        if (!$user || $user->client_id !== $clientId) {
            return false;
        }

        // Primary Admins and Admins have all permissions by default
        if (in_array($user->role, [self::ROLE_PRIMARY_ADMIN, self::ROLE_ADMIN])) {
            return true;
        }

        // Check explicit permission record
        $permission = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->first();

        if (!$permission) {
            return false;
        }

        // Check expiry date if set
        if ($permission->details_json && isset($permission->details_json['expiry_date'])) {
            $expiryDate = Carbon::parse($permission->details_json['expiry_date']);
            if ($expiryDate->isPast()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get dependent permissions that would be affected by revoking a permission.
     *
     * @param UserFeaturePermission $permission
     * @return Collection<int, UserFeaturePermission>
     */
    private function getDependentPermissions(UserFeaturePermission $permission): Collection
    {
        return UserFeaturePermission::where('grantor_id', $permission->user_id)
            ->where('client_id', $permission->client_id)
            ->where('feature_id', $permission->feature_id)
            ->where('is_enabled', true)
            ->get();
    }

    /**
     * Determine if permission should be soft deleted or hard deleted.
     *
     * @param UserFeaturePermission $permission
     * @return bool
     */
    private function shouldSoftDeletePermission(UserFeaturePermission $permission): bool
    {
        // Soft delete if there are dependent permissions or audit requirements
        return $this->getDependentPermissions($permission)->isNotEmpty() ||
               $permission->created_at->diffInDays(now()) < 90; // Keep recent permissions for audit
    }

    /**
     * Validate permission update data.
     *
     * @param array<string, mixed> $updateData
     * @param UserFeaturePermission $permission
     * @throws \InvalidArgumentException
     */
    private function validatePermissionUpdateData(array $updateData, UserFeaturePermission $permission): void
    {
        // Validate manager_user_id if being updated
        if (isset($updateData['manager_user_id']) && $updateData['manager_user_id'] !== null) {
            $managerId = (int) $updateData['manager_user_id'];
            $this->validateManagerUser($managerId, $permission->client_id, $permission->user_id);
        }

        // Validate details_json structure if being updated
        if (isset($updateData['details_json']) && is_array($updateData['details_json'])) {
            $this->validatePermissionDetails($updateData['details_json'], $permission);
        }
    }

    /**
     * Validate permission details structure and values.
     *
     * @param array<string, mixed> $details
     * @param UserFeaturePermission $permission
     * @throws \InvalidArgumentException
     */
    private function validatePermissionDetails(array $details, UserFeaturePermission $permission): void
    {
        $allowedKeys = array_keys(self::DEFAULT_PERMISSION_DETAILS);
        $invalidKeys = array_diff(array_keys($details), $allowedKeys);
        
        if (!empty($invalidKeys)) {
            throw new \InvalidArgumentException('Invalid detail keys: ' . implode(', ', $invalidKeys));
        }

        // Validate expiry date
        if (isset($details['expiry_date']) && $details['expiry_date']) {
            try {
                $expiryDate = Carbon::parse($details['expiry_date']);
                if ($expiryDate->isPast()) {
                    throw new \InvalidArgumentException('Expiry date cannot be in the past');
                }
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid expiry date format');
            }
        }
    }

    /**
     * Clear permission-related cache entries.
     *
     * @param int $userId
     * @param int $clientId
     */
    private function clearPermissionCache(int $userId, int $clientId): void
    {
        // Clear user-specific permission caches
        $features = [self::OOP_FEATURE_ID]; // Add more feature IDs as needed
        
        foreach ($features as $featureId) {
            $cacheKey = "user_permission_{$userId}_{$clientId}_{$featureId}";
            cache()->forget($cacheKey);
        }

        // Clear related summary caches
        cache()->forget("user_permissions_summary_{$userId}_{$clientId}");
        cache()->forget("managed_users_{$userId}_{$clientId}");
    }

    /**
     * Log permission action for audit trail.
     *
     * @param string $action
     * @param UserFeaturePermission $permission
     * @param int|null $actorId
     * @param array<string, mixed> $context
     */
    private function logPermissionAction(string $action, UserFeaturePermission $permission, ?int $actorId, array $context = []): void
    {
        Log::info("Permission {$action}", [
            'permission_id' => $permission->id,
            'user_id' => $permission->user_id,
            'client_id' => $permission->client_id,
            'feature_id' => $permission->feature_id,
            'grantor_id' => $permission->grantor_id,
            'manager_user_id' => $permission->manager_user_id,
            'is_enabled' => $permission->is_enabled,
            'actor_id' => $actorId,
            'action' => $action,
            'context' => $context,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Get the most active permission grantor for a client.
     *
     * @param int $clientId
     * @return array<string, mixed>|null
     */
    private function getMostActiveGrantor(int $clientId): ?array
    {
        $grantor = UserFeaturePermission::where('client_id', $clientId)
            ->select('grantor_id', DB::raw('count(*) as grants_count'))
            ->groupBy('grantor_id')
            ->orderBy('grants_count', 'desc')
            ->with('grantor')
            ->first();

        if (!$grantor) {
            return null;
        }

        return [
            'user_id' => $grantor->grantor_id,
            'name' => $grantor->grantor->name ?? 'Unknown User',
            'grants_count' => $grantor->grants_count
        ];
    }

    /**
     * Calculate permission utilization rate for a client.
     *
     * @param int $clientId
     * @return float
     */
    private function getPermissionUtilizationRate(int $clientId): float
    {
        $totalUsers = User::where('client_id', $clientId)->count();
        $usersWithPermissions = UserFeaturePermission::where('client_id', $clientId)
            ->where('is_enabled', true)
            ->distinct('user_id')
            ->count();

        return $totalUsers > 0 ? ($usersWithPermissions / $totalUsers) * 100 : 0;
    }

    /**
     * Check if grantor can grant permission to a specific user.
     *
     * @param User $grantor
     * @param User $user
     * @param int $featureId
     * @return bool
     */
    private function canGrantToUser(User $grantor, User $user, int $featureId): bool
    {
        // Primary Admins and Admins can grant to anyone (except other Primary Admins)
        if (in_array($grantor->role, [self::ROLE_PRIMARY_ADMIN, self::ROLE_ADMIN])) {
            return $user->role !== self::ROLE_PRIMARY_ADMIN || $grantor->role === self::ROLE_PRIMARY_ADMIN;
        }

        // Business Users can grant to Business Users and Card Users
        if ($grantor->role === self::ROLE_BUSINESS_USER) {
            return in_array($user->role, [self::ROLE_BUSINESS_USER, self::ROLE_CARD_USER]);
        }

        // Card Users cannot grant permissions
        return false;
    }
}