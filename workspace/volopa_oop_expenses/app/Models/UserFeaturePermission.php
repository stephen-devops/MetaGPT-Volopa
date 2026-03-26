<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User Feature Permission Model
 * 
 * Manages user permissions for specific features within client contexts.
 * Implements RBAC with delegation capabilities.
 * 
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property int $feature_id
 * @property int $grantor_id
 * @property int $manager_user_id
 * @property bool $is_enabled
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
        'id' => 'integer',
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
     * Get the user that owns this permission.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this permission.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who granted this permission.
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the manager user for this permission.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
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
     * Check if this permission is currently active (enabled).
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
}