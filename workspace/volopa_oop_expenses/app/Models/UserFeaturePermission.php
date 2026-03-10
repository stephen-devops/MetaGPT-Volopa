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
 * Manages user-specific feature access permissions within the multi-tenant environment.
 * This model enables delegation of OOP expense management rights from Primary Administrators 
 * to other users within the same client.
 * 
 * @property int $id
 * @property int $user_id The user receiving the permission
 * @property int $client_id The client context for this permission
 * @property int $feature_id The feature being granted access to (e.g., 16 for OOP expenses)
 * @property int $grantor_id The user who granted this permission
 * @property int|null $manager_user_id Optional designated manager for this permission
 * @property bool $is_enabled Whether this permission is currently active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Client $client
 * @property-read Feature $feature
 * @property-read User $grantor
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
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically scope all queries by client_id for multi-tenancy
        static::addGlobalScope('client_scope', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });
    }

    /**
     * Get the user who receives this permission.
     *
     * @return BelongsTo<User, UserFeaturePermission>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this permission.
     *
     * @return BelongsTo<Client, UserFeaturePermission>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the feature being granted access to.
     *
     * @return BelongsTo<Feature, UserFeaturePermission>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    /**
     * Get the user who granted this permission.
     *
     * @return BelongsTo<User, UserFeaturePermission>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the optional designated manager for this permission.
     *
     * @return BelongsTo<User, UserFeaturePermission>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Scope a query to only include enabled permissions.
     *
     * @param Builder<UserFeaturePermission> $query
     * @return Builder<UserFeaturePermission>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope a query to only include disabled permissions.
     *
     * @param Builder<UserFeaturePermission> $query
     * @return Builder<UserFeaturePermission>
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope a query to filter by specific feature.
     *
     * @param Builder<UserFeaturePermission> $query
     * @param int $featureId
     * @return Builder<UserFeaturePermission>
     */
    public function scopeForFeature(Builder $query, int $featureId): Builder
    {
        return $query->where('feature_id', $featureId);
    }

    /**
     * Scope a query to filter by specific user.
     *
     * @param Builder<UserFeaturePermission> $query
     * @param int $userId
     * @return Builder<UserFeaturePermission>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by specific grantor.
     *
     * @param Builder<UserFeaturePermission> $query
     * @param int $grantorId
     * @return Builder<UserFeaturePermission>
     */
    public function scopeGrantedBy(Builder $query, int $grantorId): Builder
    {
        return $query->where('grantor_id', $grantorId);
    }

    /**
     * Scope a query to filter by specific manager.
     *
     * @param Builder<UserFeaturePermission> $query
     * @param int $managerId
     * @return Builder<UserFeaturePermission>
     */
    public function scopeManagedBy(Builder $query, int $managerId): Builder
    {
        return $query->where('manager_user_id', $managerId);
    }

    /**
     * Scope a query to filter by client and feature combination.
     *
     * @param Builder<UserFeaturePermission> $query
     * @param int $clientId
     * @param int $featureId
     * @return Builder<UserFeaturePermission>
     */
    public function scopeForClientFeature(Builder $query, int $clientId, int $featureId): Builder
    {
        return $query->where('client_id', $clientId)
                    ->where('feature_id', $featureId);
    }

    /**
     * Check if this permission is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_enabled === true;
    }

    /**
     * Check if this permission is currently inactive.
     *
     * @return bool
     */
    public function isInactive(): bool
    {
        return $this->is_enabled === false;
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
     * Get a human-readable description of this permission.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $featureName = $this->feature->name ?? 'Unknown Feature';
        $userName = $this->user->name ?? 'Unknown User';
        $clientName = $this->client->name ?? 'Unknown Client';
        $status = $this->is_enabled ? 'Enabled' : 'Disabled';

        return "{$userName} has {$status} access to {$featureName} for {$clientName}";
    }

    /**
     * Check if the permission was granted by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function wasGrantedBy(int $userId): bool
    {
        return $this->grantor_id === $userId;
    }

    /**
     * Check if the permission is managed by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function isManagedBy(int $userId): bool
    {
        return $this->manager_user_id === $userId;
    }

    /**
     * Check if the permission belongs to a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Check if the permission belongs to a specific client.
     *
     * @param int $clientId
     * @return bool
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Check if the permission is for a specific feature.
     *
     * @param int $featureId
     * @return bool
     */
    public function isForFeature(int $featureId): bool
    {
        return $this->feature_id === $featureId;
    }
}