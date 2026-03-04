## Code: app/Models/UserFeaturePermission.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserFeaturePermission Model
 * 
 * Represents delegation-based RBAC system for user feature permissions.
 * Manages permission grants between users within client contexts.
 * 
 * @property int $id
 * @property int $user_id User receiving the permission
 * @property int $client_id Client context for multi-tenancy
 * @property int $feature_id Feature being granted access to
 * @property int $grantor_id User who granted this permission
 * @property int $manager_user_id User who manages this permission
 * @property bool $is_enabled Whether permission is active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * 
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\Feature $feature
 * @property-read \App\Models\User $grantor
 * @property-read \App\Models\User $manager
 */
class UserFeaturePermission extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_feature_permissions';

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
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
    ];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Ensure all queries are scoped by authenticated user's client context
        static::addGlobalScope('client_scoped', function ($builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });
    }

    /**
     * Get the user who receives this permission.
     *
     * @return BelongsTo<\App\Models\User, UserFeaturePermission>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this permission.
     *
     * @return BelongsTo<\App\Models\Client, UserFeaturePermission>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the feature that this permission grants access to.
     *
     * @return BelongsTo<\App\Models\Feature, UserFeaturePermission>
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    /**
     * Get the user who granted this permission.
     *
     * @return BelongsTo<\App\Models\User, UserFeaturePermission>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    /**
     * Get the user who manages this permission.
     *
     * @return BelongsTo<\App\Models\User, UserFeaturePermission>
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
     * Scope a query to filter by specific user.
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
     * Scope a query to filter by specific feature.
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
     * Scope a query to filter by specific manager.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $managerId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForManager($query, int $managerId)
    {
        return $query->where('manager_user_id', $managerId);
    }

    /**
     * Scope a query to filter by specific grantor.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $grantorId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForGrantor($query, int $grantorId)
    {
        return $query->where('grantor_id', $grantorId);
    }

    /**
     * Scope a query to filter by specific client.
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
     * Check if the permission is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_enabled === true;
    }

    