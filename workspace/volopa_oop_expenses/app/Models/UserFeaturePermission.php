<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserFeaturePermission Model
 *
 * Represents user feature permissions with delegation table pattern for role-based hierarchy.
 * Manages OOP Expense feature access (feature_id = 16) with grantor and optional manager relationships.
 * Uses Volopa legacy timestamp pattern and multi-tenant client scoping.
 *
 * @property int $id Primary key
 * @property int $user_id User receiving the permission
 * @property int $client_id Client context for the permission
 * @property int $feature_id Feature being granted (16 = OOP Expense)
 * @property int $grantor_id User who granted this permission
 * @property int|null $manager_user_id User who can manage the target user (optional)
 * @property int $is_enabled Whether permission is active (1 = enabled, 0 = disabled)
 * @property \DateTime|null $create_time Record creation timestamp
 * @property \DateTime|null $update_time Record update timestamp
 *
 * @property-read \App\Models\User $user User receiving the permission
 * @property-read \App\Models\User $client Client context
 * @property-read \App\Models\User $grantor User who granted this permission
 * @property-read \App\Models\User|null $manager User who can manage the target user
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
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped using Laravel convention.
     * We use Volopa legacy timestamp pattern instead.
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
        'is_enabled' => 'integer',
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
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => 1,
        'feature_id' => 16, // Default to OOP Expense feature
    ];

    /**
     * Boot the model and set up event listeners for Volopa legacy timestamps.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set create_time on model creation
        static::creating(function ($model) {
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            if (empty($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Automatically update update_time on model updates
        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get the user who receives this permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client context for this permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the user who granted this permission.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id', 'id');
    }

    /**
     * Get the user who can manage the target user (optional).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id', 'id');
    }

    /**
     * Scope query to enabled permissions only.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', 1);
    }

    /**
     * Scope query to disabled permissions only.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', 0);
    }

    /**
     * Scope query to specific client.
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
     * Scope query to specific user.
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
     * Scope query to specific feature.
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
     * Scope query to OOP Expense feature (feature_id = 16).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOopExpense($query)
    {
        return $query->where('feature_id', 16);
    }

    /**
     * Scope query to permissions granted by specific user.
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
     * Scope query to permissions with management rights.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithManager($query)
    {
        return $query->whereNotNull('manager_user_id');
    }

    /**
     * Scope query to permissions without management rights.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithoutManager($query)
    {
        return $query->whereNull('manager_user_id');
    }

    /**
     * Scope query to permissions managed by specific user.
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
     * Check if the permission is currently enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->is_enabled === 1;
    }

    /**
     * Check if the permission is currently disabled.
     *
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->is_enabled === 0;
    }

    /**
     * Check if this permission includes management rights.
     *
     * @return bool
     */
    public function hasManager(): bool
    {
        return !is_null($this->manager_user_id);
    }

    /**
     * Check if this permission is for OOP Expense feature.
     *
     * @return bool
     */
    public function isOopExpensePermission(): bool
    {
        return $this->feature_id === 16;
    }

    /**
     * Enable this permission.
     *
     * @return bool
     */
    public function enable(): bool
    {
        $this->is_enabled = 1;
        return $this->save();
    }

    /**
     * Disable this permission.
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->is_enabled = 0;
        return $this->save();
    }

    /**
     * Set the manager for this permission.
     *
     * @param int $managerId
     * @return bool
     */
    public function setManager(int $managerId): bool
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
     * Get a string representation of the permission status.
     *
     * @return string
     */
    public function getStatusAttribute(): string
    {
        return $this->isEnabled() ? 'enabled' : 'disabled';
    }

    /**
     * Get a formatted string for display purposes.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $userName = $this->user ? $this->user->name : 'Unknown User';
        $clientName = $this->client ? $this->client->name : 'Unknown Client';
        $status = $this->isEnabled() ? 'Enabled' : 'Disabled';
        
        return "{$userName} - {$clientName} - OOP Expense - {$status}";
    }

    /**
     * Convert the model instance to an array for API responses.
     * Includes computed attributes and relationship data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes
        $array['status'] = $this->getStatusAttribute();
        $array['has_manager'] = $this->hasManager();
        $array['is_oop_expense'] = $this->isOopExpensePermission();
        
        return $array;
    }

    /**
     * Get the route key name for model binding.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Get all available permission scopes for query building.
     *
     * @return array<string, string>
     */
    public static function getAvailableScopes(): array
    {
        return [
            'enabled' => 'Filter to enabled permissions only',
            'disabled' => 'Filter to disabled permissions only',
            'forClient' => 'Filter to specific client (requires client_id parameter)',
            'forUser' => 'Filter to specific user (requires user_id parameter)',
            'forFeature' => 'Filter to specific feature (requires feature_id parameter)',
            'oopExpense' => 'Filter to OOP Expense feature permissions',
            'grantedBy' => 'Filter to permissions granted by specific user (requires grantor_id parameter)',
            'withManager' => 'Filter to permissions with management rights',
            'withoutManager' => 'Filter to permissions without management rights',
            'managedBy' => 'Filter to permissions managed by specific user (requires manager_id parameter)',
        ];
    }

    /**
     * Create a new permission with validation.
     *
     * @param array $attributes
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function createPermission(array $attributes): self
    {
        // Validate required attributes
        $required = ['user_id', 'client_id', 'feature_id', 'grantor_id'];
        foreach ($required as $field) {
            if (!isset($attributes[$field]) || empty($attributes[$field])) {
                throw new \InvalidArgumentException("Required field '{$field}' is missing or empty");
            }
        }

        // Set defaults
        $attributes['is_enabled'] = $attributes['is_enabled'] ?? 1;
        $attributes['feature_id'] = $attributes['feature_id'] ?? 16; // Default to OOP Expense

        return static::create($attributes);
    }

    /**
     * Find permission by user, client, and feature combination.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return static|null
     */
    public static function findByUserClientFeature(int $userId, int $clientId, int $featureId): ?self
    {
        return static::where('user_id', $userId)
                     ->where('client_id', $clientId)
                     ->where('feature_id', $featureId)
                     ->first();
    }

    /**
     * Check if a specific permission exists and is enabled.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $featureId
     * @return bool
     */
    public static function hasPermission(int $userId, int $clientId, int $featureId): bool
    {
        return static::where('user_id', $userId)
                     ->where('client_id', $clientId)
                     ->where('feature_id', $featureId)
                     ->enabled()
                     ->exists();
    }

    /**
     * Check if a user has OOP Expense permission for a specific client.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    public static function hasOopExpensePermission(int $userId, int $clientId): bool
    {
        return static::hasPermission($userId, $clientId, 16);
    }

    /**
     * Get all permissions for a user within a client.
     *
     * @param int $userId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUserPermissions(int $userId, int $clientId)
    {
        return static::forUser($userId)
                     ->forClient($clientId)
                     ->enabled()
                     ->with(['user', 'client', 'grantor', 'manager'])
                     ->get();
    }

    /**
     * Get all permissions granted by a specific user.
     *
     * @param int $grantorId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPermissionsGrantedBy(int $grantorId, int $clientId)
    {
        return static::grantedBy($grantorId)
                     ->forClient($clientId)
                     ->with(['user', 'client', 'grantor', 'manager'])
                     ->get();
    }

    /**
     * Get all permissions managed by a specific user.
     *
     * @param int $managerId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPermissionsManagedBy(int $managerId, int $clientId)
    {
        return static::managedBy($managerId)
                     ->forClient($clientId)
                     ->with(['user', 'client', 'grantor', 'manager'])
                     ->get();
    }
}