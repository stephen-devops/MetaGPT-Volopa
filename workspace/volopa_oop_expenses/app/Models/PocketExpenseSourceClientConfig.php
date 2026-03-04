## Code: app/Models/PocketExpenseSourceClientConfig.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Represents expense source configuration per client with soft delete support.
 * Manages client-specific expense sources including global 'Other' source.
 * 
 * @property int $id
 * @property string|null $uuid External reference UUID
 * @property int|null $client_id Client ID for multi-tenancy, NULL for global records
 * @property string $name Expense source name
 * @property bool $is_default Whether this is the default source for client
 * @property bool $deleted Soft delete flag
 * @property \Illuminate\Support\Carbon|null $delete_time When record was deleted
 * @property \Illuminate\Support\Carbon $create_time Record creation time
 * @property \Illuminate\Support\Carbon $update_time Record last update time
 * 
 * @property-read \App\Models\Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpenseMetadata> $metadata
 */
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
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'create_time';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = 'update_time';

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
     * The attributes that should be cast.
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
        'is_default' => false,
        'deleted' => false,
    ];

    /**
     * Maximum number of active expense sources per client.
     *
     * @var int
     */
    public const MAX_SOURCES_PER_CLIENT = 20;

    /**
     * Global 'Other' source name that cannot be deleted or edited.
     *
     * @var string
     */
    public const GLOBAL_OTHER_SOURCE_NAME = 'Other';

    /**
     * Default source names auto-created on feature enable.
     *
     * @var array<int, string>
     */
    public const DEFAULT_SOURCE_NAMES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
    ];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set timestamps on create/update
        static::creating(function ($model) {
            if (!$model->create_time) {
                $model->create_time = now();
            }
            $model->update_time = now();
        });

        static::updating(function ($model) {
            $model->update_time = now();
        });

        // Ensure all queries exclude deleted records by default
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('deleted', false);
        });

        // Ensure all queries are scoped by authenticated user's client context (except global records)
        static::addGlobalScope('client_scoped', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where(function ($query) {
                    $query->where('client_id', auth()->user()->client_id)
                          ->orWhereNull('client_id'); // Include global records like 'Other'
                });
            }
        });
    }

    /**
     * Get the client that owns this expense source config.
     *
     * @return BelongsTo<\App\Models\Client, PocketExpenseSourceClientConfig>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the metadata records that reference this expense source.
     *
     * @return HasMany<\App\Models\PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id');
    }

    /**
     * Scope a query to only include active (not deleted) records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to include deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted');
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted')->where('deleted', true);
    }

    /**
     * Scope a query to filter by specific client.
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
     * Scope a query to only include global records.
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
     * Scope a query to filter by specific name.
     *
     * @param Builder $query
     * @param string $name
     * @return Builder
     */
    public function scopeByName(Builder $query, string $name): Builder
    {