<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Manages expense source configurations for clients with support for:
 * - Client-specific expense sources (Cash, Corporate Card, Personal Card, etc.)
 * - Global expense sources (Other)
 * - Flag-based soft deletion (deleted + delete_time)
 * - Volopa legacy timestamps (create_time, update_time)
 * - Maximum 20 active sources per client constraint
 * - Unique source names per client
 * 
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property string $name
 * @property bool $is_default
 * @property bool $deleted
 * @property Carbon|null $delete_time
 * @property Carbon|null $create_time
 * @property Carbon|null $update_time
 * @property Client|null $client
 * @property \Illuminate\Database\Eloquent\Collection|PocketExpenseMetadata[] $metadata
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
     * This model uses Volopa legacy timestamps (create_time, update_time).
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
        'uuid',
        'client_id',
        'name',
        'is_default',
        'deleted',
        'delete_time',
        'create_time',
        'update_time',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
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
    protected $hidden = [
        'deleted',
        'delete_time',
    ];

    /**
     * Boot the model.
     * Automatically generate UUID and set timestamps on creation.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            
            if (is_null($model->create_time)) {
                $model->create_time = now();
            }
            
            if (is_null($model->update_time)) {
                $model->update_time = now();
            }
        });

        static::updating(function (self $model): void {
            $model->update_time = now();
        });
    }

    /**
     * Get the client that owns this expense source configuration.
     *
     * @return BelongsTo<Client, PocketExpenseSourceClientConfig>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the metadata records that reference this expense source.
     *
     * @return HasMany<PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id');
    }

    /**
     * Scope to include only active (non-deleted) expense sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope to include only deleted expense sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope to get sources for a specific client.
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
     * Scope to get global sources (client_id = null).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope to get default sources for clients.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to get sources available for a specific client (client's own sources + global sources).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailableForClient($query, int $clientId)
    {
        return $query->where(function ($subQuery) use ($clientId) {
            $subQuery->where('client_id', $clientId)
                     ->orWhereNull('client_id');
        })->active();
    }

    /**
     * Scope to order by name for dropdowns.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('name', 'asc');
    }

    /**
     * Perform flag-based soft delete.
     * Sets deleted = true and delete_time = now().
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        // Global 'Other' record (client_id = NULL) is not deletable
        if (is_null($this->client_id) && $this->name === 'Other') {
            return false;
        }

        $this->deleted = true;
        $this->delete_time = now();
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Restore a flag-based soft deleted expense source.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->delete_time = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Check if the expense source is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if the expense source is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Check if this is a global expense source.
     *
     * @return bool
     */
    public function isGlobal(): bool
    {
        return is_null($this->client_id);
    }

    /**
     * Check if this is a client-specific expense source.
     *
     * @return bool
     */
    public function isClientSpecific(): bool
    {
        return !is_null($this->client_id);
    }

    /**
     * Check if this is the global 'Other' source.
     *
     * @return bool
     */
    public function isOtherSource(): bool
    {
        return is_null($this->client_id) && $this->name === 'Other';
    }

    /**
     * Check if this is a default source for the client.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default === true;
    }

    /**
     * Check if the source can be edited.
     * Global 'Other' source cannot be edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return !$this->isOtherSource();
    }

    /**
     * Check if the source can be deleted.
     * Global 'Other' source cannot be deleted.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return !$this->isOtherSource() && $this->isActive();
    }

    /**
     * Get active expense sources count for a client.
     * Used to enforce the maximum 20 active sources per client constraint.
     *
     * @param int $clientId
     * @return int
     */
    public static function getActiveCountForClient(int $clientId): int
    {
        return static::forClient($clientId)->active()->count();
    }

    /**
     * Check if a client can add more expense sources.
     * Maximum 20 active expense sources per client constraint.
     *
     * @param int $clientId
     * @return bool
     */
    public static function canClientAddMore(int $clientId): bool
    {
        return static::getActiveCountForClient($clientId) < 20;
    }

    /**
     * Get default expense sources for a client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getDefaultsForClient(int $clientId)
    {
        return static::forClient($clientId)->default()->active()->get();
    }

    /**
     * Create the three default expense sources for a client.
     * Auto-created when OOP feature is enabled: Cash (default), Corporate Card, Personal Card.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function createDefaultsForClient(int $clientId)
    {
        $defaults = [
            [
                'uuid' => Str::uuid()->toString(),
                'client_id' => $clientId,
                'name' => 'Cash',
                'is_default' => true,
                'deleted' => false,
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'client_id' => $clientId,
                'name' => 'Corporate Card',
                'is_default' => false,
                'deleted' => false,
                'create_time' => now(),
                'update_time' => now(),
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'client_id' => $clientId,
                'name' => 'Personal Card',
                'is_default' => false,
                'deleted' => false,
                'create_time' => now(),
                'update_time' => now(),
            ],
        ];

        $createdSources = collect();
        
        foreach ($defaults as $sourceData) {
            $createdSources->push(static::create($sourceData));
        }

        return $createdSources;
    }

    /**
     * Check if a source name is unique for a client.
     * Unique constraint: client-specific source names (excluding global sources).
     *
     * @param int $clientId
     * @param string $name
     * @param int|null $excludeId
     * @return bool
     */
    public static function isNameUniqueForClient(int $clientId, string $name, ?int $excludeId = null): bool
    {
        $query = static::forClient($clientId)
                       ->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->count() === 0;
    }

    /**
     * Get sources available in dropdowns for a client.
     * Includes client's own active sources + global sources (like Other).
     * Excludes soft-deleted sources but they remain visible on historical records.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getDropdownOptionsForClient(int $clientId)
    {
        return static::availableForClient($clientId)->ordered()->get();
    }

    /**
     * Find the 'Other' global source.
     *
     * @return PocketExpenseSourceClientConfig|null
     */
    public static function getOtherSource(): ?self
    {
        return static::global()->where('name', 'Other')->first();
    }

    /**
     * Convert to array for API responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed fields for API responses
        $array['is_global'] = $this->isGlobal();
        $array['is_other_source'] = $this->isOtherSource();
        $array['can_be_edited'] = $this->canBeEdited();
        $array['can_be_deleted'] = $this->canBeDeleted();
        
        return $array;
    }

    /**
     * Get the route key for the model.
     * Use UUID for public-facing routes instead of ID.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Retrieve the model for a bound value.
     * Support both ID and UUID route model binding.
     *
     * @param mixed $value
     * @param string|null $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === 'uuid' || (!is_numeric($value) && strlen($value) === 36)) {
            return $this->where('uuid', $value)->first();
        }

        return $this->where('id', $value)->first();
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\PocketExpenseSourceClientConfigFactory
     */
    protected static function newFactory()
    {
        return \Database\Factories\PocketExpenseSourceClientConfigFactory::new();
    }
}