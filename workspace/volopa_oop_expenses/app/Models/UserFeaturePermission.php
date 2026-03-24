<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\UserFeaturePermissionFactory;

/**
 * User Feature Permission Model
 * 
 * Manages hierarchical RBAC permissions for features like OOP Expenses.
 * Supports delegation of management rights between users within client contexts.
 * 
 * @property int $id
 * @property int $user_id Target user receiving the permission
 * @property int $client_id Client context for the permission
 * @property int $feature_id Feature being granted access to (e.g., 16 for OOP Expenses)
 * @property int $grantor_id User who granted this permission
 * @property int $manager_user_id User who can manage the target user
 * @property bool $is_enabled Whether the permission is currently active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read User $user
 * @property-read User $client
 * @property-read User $grantor
 * @property-read User $manager
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
     * @var array<string>
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
     * @var array<string>
     */
    protected $hidden = [
        // No hidden attributes for this model
    ];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
        'feature_id' => 16, // Default to OOP Expenses feature as per system constraints
    ];

    /**
     * Boot the model and set up event listeners.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set feature_id to OOP Expenses if not specified
        static::creating(function (UserFeaturePermission $permission) {
            if (!$permission->feature_id) {
                $permission->feature_id = 16; // OOP Expenses feature
            }
        });
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\UserFeaturePermissionFactory
     */
    protected static function newFactory(): UserFeaturePermissionFactory
    {
        return UserFeaturePermissionFactory::new();
    }

    /**
     * Get the target user who receives this permission.
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
     * Get the user who granted this permission.
     *
     * @return BelongsTo<User, UserFeaturePermission>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the user who can manage the target user.
     *
     * @return BelongsTo<User, UserFeaturePermission>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /**
     * Scope to filter by enabled permissions only.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope to filter by disabled permissions only.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope to filter permissions by client.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to filter permissions by feature.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @param int $featureId
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeForFeature($query, int $featureId)
    {
        return $query->where('feature_id', $featureId);
    }

    /**
     * Scope to filter permissions for OOP Expenses feature.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeForOopExpenses($query)
    {
        return $query->where('feature_id', 16);
    }

    /**
     * Scope to filter permissions by target user.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter permissions by grantor.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @param int $grantorId
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeGrantedBy($query, int $grantorId)
    {
        return $query->where('grantor_id', $grantorId);
    }

    /**
     * Scope to filter permissions by manager.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @param int $managerId
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeManagedBy($query, int $managerId)
    {
        return $query->where('manager_user_id', $managerId);
    }

    /**
     * Scope to get permissions with related user, client, grantor, and manager data.
     *
     * @param \Illuminate\Database\Eloquent\Builder<UserFeaturePermission> $query
     * @return \Illuminate\Database\Eloquent\Builder<UserFeaturePermission>
     */
    public function scopeWithRelations($query)
    {
        return $query->with(['user', 'client', 'grantor', 'manager']);
    }

    /**
     * Check if this permission is currently active.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->is_enabled;
    }

    /**
     * Check if this permission is disabled.
     *
     * @return bool
     */
    public function isDisabled(): bool
    {
        return !$this->is_enabled;
    }

    /**
     * Check if this permission is for the OOP Expenses feature.
     *
     * @return bool
     */
    public function isForOopExpenses(): bool
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
        return $this->update(['is_enabled' => true]);
    }

    /**
     * Disable this permission.
     *
     * @return bool
     */
    public function disable(): bool
    {
        return $this->update(['is_enabled' => false]);
    }

    /**
     * Get the feature name based on feature_id.
     *
     * @return string
     */
    public function getFeatureName(): string
    {
        return match ($this->feature_id) {
            16 => 'OOP Expenses',
            default => 'Feature ID ' . $this->feature_id,
        };
    }

    /**
     * Check if the grantor and manager are the same user (self-granted permission).
     *
     * @return bool
     */
    public function isSelfGranted(): bool
    {
        return $this->grantor_id === $this->manager_user_id;
    }

    /**
     * Get a human-readable description of this permission.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $status = $this->is_enabled ? 'enabled' : 'disabled';
        $featureName = $this->getFeatureName();
        
        return "Permission for {$featureName} is {$status} for user {$this->user_id} in client {$this->client_id}";
    }

    /**
     * Convert the permission to an array with human-readable information.
     *
     * @return array<string, mixed>
     */
    public function toDetailedArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'feature_id' => $this->feature_id,
            'feature_name' => $this->getFeatureName(),
            'grantor_id' => $this->grantor_id,
            'manager_user_id' => $this->manager_user_id,
            'is_enabled' => $this->is_enabled,
            'is_self_granted' => $this->isSelfGranted(),
            'description' => $this->getDescription(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        // Log permission changes for audit trail
        static::created(function (UserFeaturePermission $permission) {
            \Log::info('User feature permission created', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
                'grantor_id' => $permission->grantor_id,
                'manager_user_id' => $permission->manager_user_id,
            ]);
        });

        static::updated(function (UserFeaturePermission $permission) {
            if ($permission->isDirty('is_enabled')) {
                $status = $permission->is_enabled ? 'enabled' : 'disabled';
                \Log::info("User feature permission {$status}", [
                    'permission_id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'previous_status' => $permission->getOriginal('is_enabled') ? 'enabled' : 'disabled',
                    'new_status' => $status,
                ]);
            }
        });

        static::deleted(function (UserFeaturePermission $permission) {
            \Log::info('User feature permission deleted', [
                'permission_id' => $permission->id,
                'user_id' => $permission->user_id,
                'client_id' => $permission->client_id,
                'feature_id' => $permission->feature_id,
            ]);
        });
    }

    /**
     * Get validation rules for this model.
     *
     * @param int|null $id For update operations, exclude current record from unique checks
     * @return array<string, string>
     */
    public static function getValidationRules(?int $id = null): array
    {
        $uniqueRule = 'unique:user_feature_permission,user_id,NULL,id,client_id,{client_id},feature_id,{feature_id}';
        if ($id) {
            $uniqueRule = "unique:user_feature_permission,user_id,{$id},id,client_id,{client_id},feature_id,{feature_id}";
        }

        return [
            'user_id' => 'required|integer|exists:users,id',
            'client_id' => 'required|integer|exists:clients,id',
            'feature_id' => 'required|integer|min:1',
            'grantor_id' => 'required|integer|exists:users,id',
            'manager_user_id' => 'required|integer|exists:users,id',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public static function getValidationMessages(): array
    {
        return [
            'user_id.required' => 'Target user is required.',
            'user_id.exists' => 'Selected user does not exist.',
            'client_id.required' => 'Client is required.',
            'client_id.exists' => 'Selected client does not exist.',
            'feature_id.required' => 'Feature ID is required.',
            'feature_id.min' => 'Feature ID must be a positive number.',
            'grantor_id.required' => 'Grantor user is required.',
            'grantor_id.exists' => 'Grantor user does not exist.',
            'manager_user_id.required' => 'Manager user is required.',
            'manager_user_id.exists' => 'Manager user does not exist.',
            'is_enabled.boolean' => 'Permission status must be true or false.',
        ];
    }
}