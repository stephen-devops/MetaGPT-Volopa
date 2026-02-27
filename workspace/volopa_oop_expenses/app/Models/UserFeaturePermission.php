<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * The attributes that should be cast to native types.
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
     * Get the user that this permission belongs to.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client that this permission belongs to.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the feature that this permission belongs to.
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
     * Get the manager user for this permission.
     *
     * @return BelongsTo
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
     * Scope a query to only include permissions for a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
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
     * Check if this permission is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_enabled;
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
     * Get a string representation of the permission for logging.
     *
     * @return string
     */
    public function getLogDescription(): string
    {
        return sprintf(
            'Permission for user %d on client %d for feature %d (granted by %d, managed by %d)',
            $this->user_id,
            $this->client_id,
            $this->feature_id,
            $this->grantor_id,
            $this->manager_user_id
        );
    }
}