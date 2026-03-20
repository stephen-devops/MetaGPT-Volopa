<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserFeaturePermission Model
 * 
 * Represents user feature permissions with delegation-based RBAC system.
 * Supports multi-tenant client scoping and permission management hierarchy.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property int $feature_id
 * @property int $grantor_id
 * @property int|null $manager_user_id
 * @property bool $is_enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $grantor
 * @property-read \App\Models\User|null $manager
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
     * Get the user that owns the permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\UserFeaturePermission>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client that this permission is scoped to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Client, \App\Models\UserFeaturePermission>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who granted this permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\UserFeaturePermission>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the user who manages this permission (optional delegation).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\UserFeaturePermission>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Scope a query to only include active permissions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope a query to only include permissions for a specific client.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include permissions for a specific feature.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $featureId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForFeature($query, int $featureId)
    {
        return $query->where('feature_id', $featureId);
    }

    /**
     * Scope a query to only include permissions granted by a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $grantorId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGrantedBy($query, int $grantorId)
    {
        return $query->where('grantor_id', $grantorId);
    }

    /**
     * Scope a query to only include permissions managed by a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $managerId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeManagedBy($query, int $managerId)
    {
        return $query->where('manager_user_id', $managerId);
    }

    /**
     * Check if the permission is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_enabled === true;
    }

    /**
     * Check if the permission has a designated manager.
     *
     * @return bool
     */
    public function hasManager(): bool
    {
        return !is_null($this->manager_user_id);
    }

    /**
     * Enable the permission.
     *
     * @return bool
     */
    public function enable(): bool
    {
        $this->is_enabled = true;
        return $this->save();
    }

    /**
     * Disable the permission.
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->is_enabled = false;
        return $this->save();
    }

    /**
     * Set the manager for this permission.
     *
     * @param int|null $managerId
     * @return bool
     */
    public function setManager(?int $managerId): bool
    {
        $this->manager_user_id = $managerId;
        return $this->save();
    }

    /**
     * Remove the manager from this permission.
     *
     * @return bool
     */
    public function removeManager(): bool
    {
        $this->manager_user_id = null;
        return $this->save();
    }

    /**
     * Get a string representation of the permission for logging/debugging.
     *
     * @return string
     */
    public function getDescriptiveNameAttribute(): string
    {
        return sprintf(
            'User %d - Feature %d - Client %d (%s)',
            $this->user_id,
            $this->feature_id,
            $this->client_id,
            $this->is_enabled ? 'enabled' : 'disabled'
        );
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Ensure permissions are properly scoped and validated on creation
        static::creating(function (UserFeaturePermission $permission) {
            // Ensure is_enabled has a default value
            if (is_null($permission->is_enabled)) {
                $permission->is_enabled = true;
            }
        });

        // Log permission changes for audit purposes
        static::updated(function (UserFeaturePermission $permission) {
            if ($permission->isDirty('is_enabled')) {
                \Log::info('User permission status changed', [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'old_status' => $permission->getOriginal('is_enabled') ? 'enabled' : 'disabled',
                    'new_status' => $permission->is_enabled ? 'enabled' : 'disabled',
                    'changed_at' => now(),
                ]);
            }
        });
    }
}