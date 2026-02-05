<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * UserFeaturePermission Model
 * 
 * Manages user permissions for features within client contexts.
 * Implements role-based access control (RBAC) with hierarchical permissions.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property int $feature_id
 * @property int|null $grantor_id
 * @property int|null $manager_user_id
 * @property bool $is_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read User $user
 * @property-read Client $client
 * @property-read Feature $feature
 * @property-read User|null $grantor
 * @property-read User|null $manager
 */
class UserFeaturePermission extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_feature_permission';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
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
     * The attributes that should be cast.
     *
     * @var array<string, string>
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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
    ];

    /**
     * Get the user that owns this permission.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with this permission.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the feature associated with this permission.
     *
     * @return BelongsTo
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    /**
     * Get the user who granted this permission.
     *
     * @return BelongsTo
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the manager user associated with this permission.
     *
     * @return BelongsTo
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Scope a query to only include enabled permissions.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope a query to only include disabled permissions.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope a query to filter by user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by client.
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by feature.
     *
     * @param Builder $query
     * @param int $featureId
     * @return Builder
     */
    public function scopeForFeature(Builder $query, int $featureId): Builder
    {
        return $query->where('feature_id', $featureId);
    }

    /**
     * Scope a query to filter by user, client, and feature.
     *
     * @param Builder $query
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return Builder
     */
    public function scopeForUserClientFeature(Builder $query, int $userId, int $clientId, int $featureId): Builder
    {
        return $query->where([
            'user_id' => $userId,
            'client_id' => $clientId,
            'feature_id' => $featureId,
        ]);
    }

    /**
     * Scope a query to filter by manager user.
     *
     * @param Builder $query
     * @param int $managerUserId
     * @return Builder
     */
    public function scopeForManager(Builder $query, int $managerUserId): Builder
    {
        return $query->where('manager_user_id', $managerUserId);
    }

    /**
     * Scope a query to filter by grantor.
     *
     * @param Builder $query
     * @param int $grantorId
     * @return Builder
     */
    public function scopeForGrantor(Builder $query, int $grantorId): Builder
    {
        return $query->where('grantor_id', $grantorId);
    }

    /**
     * Check if the permission is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }

    /**
     * Check if the permission is disabled.
     *
     * @return bool
     */
    public function isDisabled(): bool
    {
        return !$this->is_enabled;
    }

    /**
     * Enable this permission.
     *
     * @return bool
     */
    public function enable(): bool
    {
        $this->is_enabled = true;
        return $this->save();
    }

    /**
     * Disable this permission.
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->is_enabled = false;
        return $this->save();
    }

    /**
     * Check if this permission has a manager assigned.
     *
     * @return bool
     */
    public function hasManager(): bool
    {
        return !is_null($this->manager_user_id);
    }

    /**
     * Check if this permission was granted by someone.
     *
     * @return bool
     */
    public function hasGrantor(): bool
    {
        return !is_null($this->grantor_id);
    }

    /**
     * Get a unique identifier for this permission combination.
     *
     * @return string
     */
    public function getPermissionKey(): string
    {
        return sprintf(
            'user:%d:client:%d:feature:%d',
            $this->user_id,
            $this->client_id,
            $this->feature_id
        );
    }

    /**
     * Create or update a permission for the given parameters.
     *
     * @param array $attributes
     * @return static
     */
    public static function createOrUpdate(array $attributes): static
    {
        $permission = static::where([
            'user_id' => $attributes['user_id'],
            'client_id' => $attributes['client_id'],
            'feature_id' => $attributes['feature_id'],
        ])->first();

        if ($permission) {
            $permission->fill($attributes);
            $permission->save();
            return $permission;
        }

        return static::create($attributes);
    }

    /**
     * Check if a user has permission for a feature within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public static function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        return static::enabled()
            ->forUserClientFeature($userId, $clientId, $featureId)
            ->exists();
    }

    /**
     * Get all enabled permissions for a user within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUserPermissions(int $userId, int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::enabled()
            ->forUser($userId)
            ->forClient($clientId)
            ->with(['feature', 'manager', 'grantor'])
            ->get();
    }

    /**
     * Get all permissions managed by a specific user.
     *
     * @param int $managerUserId
     * @param int|null $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getManagedPermissions(int $managerUserId, ?int $clientId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = static::enabled()
            ->forManager($managerUserId)
            ->with(['user', 'feature', 'client']);

        if ($clientId) {
            $query->forClient($clientId);
        }

        return $query->get();
    }

    /**
     * Bulk enable/disable permissions for multiple users.
     *
     * @param array $userIds
     * @param int $clientId
     * @param int $featureId
     * @param bool $isEnabled
     * @return int Number of affected rows
     */
    public static function bulkUpdatePermissions(array $userIds, int $clientId, int $featureId, bool $isEnabled): int
    {
        return static::whereIn('user_id', $userIds)
            ->forClient($clientId)
            ->forFeature($featureId)
            ->update([
                'is_enabled' => $isEnabled,
                'updated_at' => now(),
            ]);
    }
}