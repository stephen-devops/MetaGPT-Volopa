## Code: app/Models/PocketExpenseSourceClientConfig.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PocketExpenseSourceClientConfig extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_source_client_config';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'client_id',
        'name',
        'is_default',
        'deleted',
        'delete_time',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'uuid' => 'string',
        'client_id' => 'integer',
        'name' => 'string',
        'is_default' => 'boolean',
        'deleted' => 'boolean',
        'delete_time' => 'datetime',
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
        'is_default' => false,
        'deleted' => false,
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'delete_time',
        'create_time',
        'update_time',
    ];

    /**
     * Define the timestamp column names for custom timestamp fields.
     *
     * @var string
     */
    const CREATED_AT = 'create_time';
    const UPDATED_AT = 'update_time';

    /**
     * Maximum number of active sources per client.
     *
     * @var int
     */
    const MAX_SOURCES_PER_CLIENT = 20;

    /**
     * Default source names that are auto-created on feature enable.
     *
     * @var array<string>
     */
    const DEFAULT_SOURCE_NAMES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
    ];

    /**
     * Global "Other" source name (cannot be deleted or edited).
     *
     * @var string
     */
    const GLOBAL_OTHER_SOURCE = 'Other';

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate UUID when creating new records
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });

        // Update the update_time when saving
        static::saving(function (self $model): void {
            $model->update_time = now();
        });
    }

    /**
     * Get the client that this source configuration belongs to.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get all expense metadata records that reference this source.
     *
     * @return HasMany
     */
    public function expenseMetadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id');
    }

    /**
     * Scope a query to only include active (not deleted) sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include sources for a specific client.
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include global sources (client_id is null).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope a query to only include default sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to filter by source name.
     *
     * @param Builder $query
     * @param string $name
     * @return Builder
     */
    public function scopeByName(Builder $query, string $name): Builder
    {
        return $query->where('name', $name);
    }

    /**
     * Scope a query to get sources available for a client (including global Other).
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeAvailableForClient(Builder $query, int $clientId): Builder
    {
        return $query->where(function (Builder $q) use ($clientId): void {
            $q->where('client_id', $clientId)
              ->orWhere(function (Builder $subQ): void {
                  $subQ->whereNull('client_id')
                       ->where('name', self::GLOBAL_OTHER_SOURCE);
              });
        })->where('deleted', false);
    }

    /**
     * Check if this source is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !$this->deleted;
    }

    /**
     * Check if this source is deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Check if this is the global "Other" source.
     *
     * @return bool
     */
    public function isGlobalOther(): bool
    {
        return $this->client_id === null && $this->name === self::GLOBAL_OTHER_SOURCE;
    }

    /**
     * Check if this is a default source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Check if this source can be deleted.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        // Global "Other" source cannot be deleted
        if ($this->isGlobalOther()) {
            return false;
        }

        // Already deleted sources cannot be deleted again
        if ($this->isDeleted()) {
            return false;
        }

        return true;
    }

    /**
     * Check if this source can be edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        // Global "Other"