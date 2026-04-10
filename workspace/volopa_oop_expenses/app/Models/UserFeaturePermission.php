<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserFeaturePermission Model
 * 
 * Manages user permissions for specific features within a client context.
 * Handles the RBAC (Role-Based Access Control) delegation hierarchy.
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
     * This is the user who has been granted the permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client that this permission belongs to.
     * Provides multi-tenancy scoping for permissions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the feature that this permission is for.
     * Links to the platform feature registry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function feature(): BelongsTo
    {
        // TODO: Implement Feature model relationship when Feature model is available
        // For now, this is a placeholder for the foreign key constraint
        return $this->belongsTo(Feature::class, 'feature_id', 'id');
    }

    /**
     * Get the user who granted this permission.
     * Tracks the delegation hierarchy for audit purposes.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id', 'id');
    }

    /**
     * Get the user being managed by this permission.
     * This represents the user that the permission holder can manage.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id', 'id');
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
     * Scope a query to only include disabled permissions.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope a query to filter by client.
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
     * Scope a query to filter by feature.
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
     * Scope a query to filter by user.
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
     * Scope a query to filter by grantor.
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
     * Scope a query to filter by managed user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $managerUserId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeManaging($query, int $managerUserId)
    {
        return $query->where('manager_user_id', $managerUserId);
    }
}