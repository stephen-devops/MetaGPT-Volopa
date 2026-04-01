<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\UserFeaturePermissionFactory;

/**
 * UserFeaturePermission Model
 * 
 * Manages user permissions for specific features within a client context.
 * Supports delegation where an admin can grant permissions to their managed users.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property int $feature_id
 * @property int $grantor_id
 * @property int|null $manager_user_id
 * @property bool $is_enabled
 * @property \DateTime $create_time
 * @property \DateTime|null $update_time
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
     * Disable Laravel's default timestamps as we use Volopa legacy pattern
     *
     * @var bool
     */
    public $timestamps = false;

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
        'create_time' => 'datetime',
        'update_time' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Get the user who owns this permission.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client this permission belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the feature this permission is for.
     * 
     * TODO: Replace with actual Feature model when features table structure is confirmed
     */
    public function feature(): BelongsTo
    {
        // TODO: Implement actual Feature model relationship
        // For now, this is a placeholder based on the migration foreign key constraint
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    /**
     * Get the user who granted this permission.
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the user who manages the permission holder (optional delegation).
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Scope a query to only include enabled permissions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
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
     * Scope a query to only include permissions for users managed by a specific manager.
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
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\UserFeaturePermissionFactory
     */
    protected static function newFactory()
    {
        return UserFeaturePermissionFactory::new();
    }

    /**
     * Check if this permission is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_enabled;
    }

    /**
     * Check if this permission has a delegated manager.
     *
     * @return bool
     */
    public function hasDelegatedManager(): bool
    {
        return !is_null($this->manager_user_id);
    }

    /**
     * Get the display name for this permission.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        // TODO: Implement feature name lookup when Feature model is available
        return "Feature #{$this->feature_id} Permission for User #{$this->user_id}";
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Set create_time on creation
        static::creating(function ($model) {
            if (is_null($model->create_time)) {
                $model->create_time = now();
            }
            if (is_null($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Update update_time on updating
        static::updating(function ($model) {
            $model->update_time = now();
        });
    }
}