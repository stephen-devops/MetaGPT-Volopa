<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserFeaturePermission extends Model
{
    use HasFactory, SoftDeletes;

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
     * The attributes that should be cast.
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
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Default attribute values.
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
     * Scope a query to only include enabled permissions.
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope a query to only include permissions for a specific client.
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include permissions for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include permissions for a specific feature.
     */
    public function scopeForFeature($query, int $featureId)
    {
        return $query->where('feature_id', $featureId);
    }

    /**
     * Scope a query to only include permissions managed by a specific user.
     */
    public function scopeManagedBy($query, int $managerUserId)
    {
        return $query->where('manager_user_id', $managerUserId);
    }

    /**
     * Check if the permission is currently active.
     */
    public function isActive(): bool
    {
        return $this->is_enabled && !$this->trashed();
    }

    /**
     * Enable the permission.
     */
    public function enable(): bool
    {
        return $this->update(['is_enabled' => true]);
    }

    /**
     * Disable the permission.
     */
    public function disable(): bool
    {
        return $this->update(['is_enabled' => false]);
    }
}