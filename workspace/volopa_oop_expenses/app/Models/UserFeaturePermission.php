## Code: app/Models/UserFeaturePermission.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class UserFeaturePermission extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'user_feature_permissions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'client_id',
        'feature_id',
        'grantor_id',
        'manager_user_id',
        'is_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        // No hidden attributes for this model
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'user_id' => 'integer',
        'client_id' => 'integer',
        'feature_id' => 'integer',
        'grantor_id' => 'integer',
        'manager_user_id' => 'integer',
        'is_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'is_enabled' => true,
    ];

    /**
     * Get the user that owns the permission.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with the permission.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the feature associated with the permission.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    /**
     * Get the user who granted the permission.
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the manager user associated with the permission.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Scope a query to only include permissions for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include permissions for a specific client.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include permissions for a specific feature.
     */
    public function scopeForFeature(Builder $query, int $featureId): Builder
    {
        return $query->where('feature_id', $featureId);
    }

    /**
     * Scope a query to only include permissions granted by a specific user.
     */
    public function scopeGrantedBy(Builder $query, int $grantorId): Builder
    {
        return $query->where('grantor_id', $grantorId);
    }

    /**
     * Scope a query to only include permissions managed by a specific user.
     */
    public function scopeManagedBy(Builder $query, int $managerId): Builder
    {
        return $query->where('manager_user_id', $managerId);
    }

    /**
     * Scope a query to only include enabled permissions.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope a query to only include disabled permissions.
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope a query to include permissions for a specific user and client.
     */
    public function scopeForUserAndClient(Builder $query, int $userId, int $clientId): Builder
    {
        return $query->where('user_id', $userId)
                    ->where('client_id', $clientId);
    }

    /**
     * Scope a query to include permissions for a specific user, client, and feature.
     */
    public function scopeForUserClientAndFeature(Builder $query, int $userId, int $clientId, int $featureId): Builder
    {
        return $query->where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->where('feature_id', $featureId);
    }

    /**
     * Scope a query to order permissions by most recent first.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope a query to include permissions within a date range.
     */
    public function scopeInDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope a query to include permissions with manager assigned.
     */
    public function scopeWithManager(Builder $query): Builder
    {
        return $query->whereNotNull('manager_user_id');
    }

    /**
     * Scope a query to include permissions without manager assigned.
     */
    public function scopeWithoutManager(Builder $query): Builder
    {
        return $query->whereNull('manager_user_id');
    }

    /**
     * Check if the permission is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->is_enabled === true;
    }

    /**
     * Check if the permission is disabled.
     */
    public function isDisabled(): bool
    {
        return $this->is_enabled === false;
    }

    /**
     * Enable the permission.
     */
    public function enable(): bool
    {
        $this->is_enabled = true;
        return $this->save();
    }

    /**
     * Disable the permission.
     */
    public function disable(): bool
    {
        $this->is_enabled = false;
        return $this->save();
    }

    /**
     * Toggle the permission enabled status.
     */
    public function toggle(): bool
    {
        $this->is_enabled = !$this->is_enabled;
        return $this->save();
    }

    /**
     * Set the manager for this permission.
     */
    public function setManager(int $managerId): bool
    {
        $this->manager_user_id = $managerId;
        return $this->save();
    }

    /**
     * Remove the manager from this permission.
     */
    public function removeManager(): bool
    {
        $this->manager_user_id = null;
        return $this->save();
    }

    /**
     * Check if the permission has a manager assigned.
     */
    public function hasManager(): bool
    {
        return !is_null($this->manager_user_id);
    }

    /**
     * Check if the permission belongs to a specific user.
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Check if the permission belongs to a specific client.
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Check if the permission is for a specific feature.
     */
    public function isForFeature(int $featureId): bool
    {
        return $this->feature_id === $featureId;
    }

    /**
     * Check if the permission was granted by a specific user.
     */
    public function wasGrantedBy(int $grantorId): bool
    {
        return $this->grantor_id === $grantorId;
    }

    /**
     * Check if the permission is managed by a specific user.
     */
    public function isManagedBy(int $managerId): bool
    {
        return $this->manager_user_id === $managerId;
    }

    /**
     * Get the age of the permission in days.
     */
    public function getAgeInDaysAttribute(): int
    {
        return now()->diffInDays($this->created_at);
    }

    /**
     * Check if the permission is older than the specified number of days.
     */
    public function isOlderThan(int $days): bool
    {
        return $this->getAgeInDaysAttribute() > $days;
    }

    /**
     * Check if a permission exists for the given user, client, and feature.
     */
    public static function exists(int $userId, int $clientId, int $featureId): bool
    {
        return static::forUserClientAndFeature($userId, $clientId, $featureId)->exists();
    }

    /**
     * Find a permission for the given user, client, and feature.
     */
    public static function findForUserClientAndFeature(int $userId, int $clientId, int $featureId): ?self
    {
        return static::forUserClientAndFeature($userId, $clientId, $featureId)->first();
    }

    /**
     * Create or update a permission for the given user, client, and feature.
     */
    public static function createOrUpdate(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId = null, bool $isEnabled = true): self
    {
        $permission = static::findForUserClientAndFeature($userId, $clientId, $featureId);
        
        if ($permission) {
            $permission->update([
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => $isEnabled,
            ]);
            return $permission;
        }
        
        return static::create([
            'user_id' => $userId,
            'client_id' => $clientId,
            'feature_id' => $featureId,
            'grantor_id' => $grantorId,
            'manager_user_id' => $managerId,
            'is_enabled' => $isEnabled,
        ]);
    }

    /**
     * Grant permission for the given user, client, and feature.
     */
    public static function grant(int $userId, int $clientId, int $featureId, int $grantorId, int $managerId = null): self
    {
        return static::createOrUpdate($userId, $clientId, $featureId, $grantorId, $managerId, true);
    }

    /**
     * Revoke permission for the given user, client, and feature.
     */
    public static function revoke(int $userId, int $clientId, int $featureId): bool
    {
        $permission = static::findForUserClientAndFeature($userId, $clientId, $featureId);
        
        if ($permission) {
            return $permission->delete();
        }
        
        return true; // Permission doesn't exist, so consider it revoked
    }

    /**
     * Check if a user has permission for a specific feature and client.
     */
    public static function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        return static::forUserClientAndFeature($userId, $clientId, $featureId)
                    ->enabled()
                    ->exists();
    }

    /**
     * Get all enabled permissions for a user and client.
     */
    public static function getUserPermissions(int $userId, int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUserAndClient($userId, $clientId)
                    ->enabled()
                    ->with(['feature'])
                    ->get();
    }

    /**
     * Get all users who have permission for a specific feature and client.
     */
    public static function getUsersWithPermission(int $clientId, int $featureId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forClient($clientId)
                    ->forFeature($featureId)
                    ->enabled()
                    ->with(['user'])
                    ->get();
    }

    /**
     * Get permission statistics for a client.
     */
    public static function getClientPermissionStats(int $clientId): array
    {
        $totalPermissions = static::forClient($clientId)->count();
        $enabledPermissions = static::forClient($clientId)->enabled()->count();
        $disabledPermissions = static::forClient($clientId)->disabled()->count();
        $uniqueUsers = static::forClient($clientId)->distinct('user_id')->count('user_id');
        $uniqueFeatures = static::forClient($clientId)->distinct('feature_id')->count('feature_id');
        
        return [
            'total_permissions' => $totalPermissions,
            'enabled_permissions' => $enabledPermissions,
            'disabled_permissions' => $disabledPermissions,
            'unique_users' => $uniqueUsers,
            'unique_features' => $uniqueFeatures,
            'enabled_percentage' => $totalPermissions > 0 ? round(($enabledPermissions / $totalPermissions) * 100, 2) : 0,
        ];
    }

    /**
     * Get permission statistics for a user.
     */
    public static function getUserPermissionStats(int $userId): array
    {
        $totalPermissions = static::forUser($userId)->count();
        $enabledPermissions = static::forUser($userId)->enabled()->count();
        $disabledPermissions = static::forUser($userId)->disabled()->count();
        $uniqueClients = static::forUser($userId)->distinct('client_id')->count('client_id');
        $uniqueFeatures = static::forUser($userId)->distinct('feature_id')->count('feature_id');
        
        return [
            'total_permissions' => $totalPermissions,
            'enabled_permissions' => $enabledPermissions,
            'disabled_permissions' => $disabledPermissions,
            'unique_clients' => $uniqueClients,
            'unique_features' => $uniqueFeatures,
            'enabled_percentage' => $totalPermissions > 0 ? round(($enabledPermissions / $totalPermissions) * 100, 2) : 0,
        ];
    }

    /**
     * Get permissions that need review (old permissions without recent updates).
     */
    public static function getNeedsReview(int $daysSinceUpdate = 90): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('updated_at', '<', now()->subDays($daysSinceUpdate))
                    ->enabled()
                    ->with(['user', 'client', 'feature'])
                    ->orderBy('updated_at', 'asc')
                    ->get();
    }

    /**
     * Get permissions granted by a specific user (hierarchical permissions).
     */
    public static function getGrantedByUser(int $grantorId): \Illuminate\Database\Eloquent\Collection
    {
        return static::grantedBy($grantorId)
                    ->with(['user', 'client', 'feature'])
                    ->orderBy('created_at', 'desc')
                    ->get();
    }

    /**
     * Get permissions managed by a specific user.