<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Manages client-specific expense source configurations. This table stores the
 * configured expense sources available to each client, including default sources
 * and custom sources. Also includes a global 'Other' source that is available to all clients.
 * Uses custom timestamp pattern (create_time/update_time) and soft delete pattern.
 * 
 * @property int $id Primary key for expense source configuration
 * @property string $uuid Unique identifier for external references
 * @property int|null $client_id The client this source belongs to. NULL for global sources like Other
 * @property string $name The name of the expense source
 * @property bool $is_default Whether this is a default source for the client
 * @property bool $deleted Soft delete flag
 * @property Carbon|null $delete_time Timestamp when the record was soft deleted
 * @property Carbon $create_time Timestamp when the record was created
 * @property Carbon $update_time Timestamp when the record was last updated
 * @property-read Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata> $metadata
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
     * We use custom timestamps (create_time/update_time).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

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
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'deleted' => false,
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
     * Default expense source names that are created when feature is enabled.
     *
     * @var array<int, string>
     */
    public const DEFAULT_SOURCES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
    ];

    /**
     * Global expense sources that are available to all clients.
     *
     * @var array<int, string>
     */
    public const GLOBAL_SOURCES = [
        'Other',
    ];

    /**
     * Maximum number of active expense sources per client.
     *
     * @var int
     */
    public const MAX_SOURCES_PER_CLIENT = 20;

    /**
     * Maximum length for source name.
     *
     * @var int
     */
    public const MAX_NAME_LENGTH = 100;

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID when creating new records
        static::creating(function (PocketExpenseSourceClientConfig $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            
            if (empty($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Update the update_time when updating records
        static::updating(function (PocketExpenseSourceClientConfig $model) {
            $model->update_time = now();
        });

        // Apply soft delete scope by default
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('deleted', false);
        });

        // Automatically scope all queries by client_id for multi-tenancy
        static::addGlobalScope('client_scope', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where(function ($query) {
                    $query->where('client_id', auth()->user()->client_id)
                          ->orWhereNull('client_id'); // Include global sources
                });
            }
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
     * Get the metadata records that use this expense source.
     *
     * @return HasMany<PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id', 'id');
    }

    /**
     * Scope a query to only include default sources.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to only include non-default sources.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }

    /**
     * Scope a query to only include global sources.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope a query to only include client-specific sources.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @param int $clientId
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by source name.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @param string $name
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeByName(Builder $query, string $name): Builder
    {
        return $query->where('name', $name);
    }

    /**
     * Scope a query to include deleted records.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted');
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted')->where('deleted', true);
    }

    /**
     * Scope a query to get sources available to a specific client.
     *
     * @param Builder<PocketExpenseSourceClientConfig> $query
     * @param int $clientId
     * @return Builder<PocketExpenseSourceClientConfig>
     */
    public function scopeAvailableToClient(Builder $query, int $clientId): Builder
    {
        return $query->where(function ($q) use ($clientId) {
            $q->where('client_id', $clientId)
              ->orWhereNull('client_id'); // Include global sources
        });
    }

    /**
     * Check if this source is a default source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default === true;
    }

    /**
     * Check if this source is a custom source.
     *
     * @return bool
     */
    public function isCustom(): bool
    {
        return $this->is_default === false;
    }

    /**
     * Check if this source is a global source.
     *
     * @return bool
     */
    public function isGlobal(): bool
    {
        return $this->client_id === null;
    }

    /**
     * Check if this source is client-specific.
     *
     * @return bool
     */
    public function isClientSpecific(): bool
    {
        return $this->client_id !== null;
    }

    /**
     * Check if this source is currently deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if this source is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Check if this source can be deleted.
     * Global sources cannot be deleted or edited.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return !$this->isGlobal() && !$this->isDeleted();
    }

    /**
     * Check if this source can be edited.
     * Global sources cannot be deleted or edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return !$this->isGlobal() && !$this->isDeleted();
    }

    /**
     * Soft delete this source.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        if (!$this->canBeDeleted()) {
            return false;
        }

        $this->deleted = true;
        $this->delete_time = now();
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Restore a soft deleted source.
     *
     * @return bool
     */
    public function restore(): bool
    {
        if (!$this->isDeleted()) {
            return false;
        }

        $this->deleted = false;
        $this->delete_time = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Force delete this source permanently.
     * Should only be used for maintenance operations.
     *
     * @return bool|null
     */
    public function forceDelete(): ?bool
    {
        if ($this->isGlobal()) {
            return false;
        }

        return parent::delete();
    }

    /**
     * Get a human-readable description of this source.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $type = $this->isGlobal() ? 'Global' : ($this->isDefault() ? 'Default' : 'Custom');
        $clientName = $this->client->name ?? 'All Clients';
        $status = $this->isDeleted() ? '(Deleted)' : '';

        return "{$this->name} - {$type} source for {$clientName} {$status}";
    }

    /**
     * Get the number of metadata records using this source.
     *
     * @return int
     */
    public function getUsageCount(): int
    {
        return $this->metadata()->count();
    }

    /**
     * Check if this source is being used by any metadata records.
     *
     * @return bool
     */
    public function isBeingUsed(): bool
    {
        return $this->getUsageCount() > 0;
    }

    /**
     * Find a source by UUID.
     *
     * @param string $uuid
     * @return PocketExpenseSourceClientConfig|null
     */
    public static function findByUuid(string $uuid): ?PocketExpenseSourceClientConfig
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * Find a source by name for a specific client.
     *
     * @param string $name
     * @param int $clientId
     * @return PocketExpenseSourceClientConfig|null
     */
    public static function findByNameForClient(string $name, int $clientId): ?PocketExpenseSourceClientConfig
    {
        return static::availableToClient($clientId)->byName($name)->first();
    }

    /**
     * Get all sources available to a specific client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseSourceClientConfig>
     */
    public static function getAvailableForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::availableToClient($clientId)->orderBy('name')->get();
    }

    /**
     * Get all default sources for a specific client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseSourceClientConfig>
     */
    public static function getDefaultForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forClient($clientId)->default()->orderBy('name')->get();
    }

    /**
     * Get all global sources.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseSourceClientConfig>
     */
    public static function getGlobalSources(): \Illuminate\Database\Eloquent\Collection
    {
        return static::global()->orderBy('name')->get();
    }

    /**
     * Create default sources for a client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseSourceClientConfig>
     */
    public static function createDefaultSources(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        $sources = collect();

        foreach (self::DEFAULT_SOURCES as $sourceName) {
            // Check if source already exists
            $existing = static::forClient($clientId)->byName($sourceName)->first();
            
            if (!$existing) {
                $source = static::create([
                    'uuid' => (string) Str::uuid(),
                    'client_id' => $clientId,
                    'name' => $sourceName,
                    'is_default' => true,
                    'deleted' => false,
                    'create_time' => now(),
                    'update_time' => now(),
                ]);
                
                $sources->push($source);
            } else {
                $sources->push($existing);
            }
        }

        return $sources;
    }

    /**
     * Get the count of active sources for a client.
     *
     * @param int $clientId
     * @return int
     */
    public static function getActiveCountForClient(int $clientId): int
    {
        return static::forClient($clientId)->count();
    }

    /**
     * Check if a client has reached the maximum number of sources.
     *
     * @param int $clientId
     * @return bool
     */
    public static function hasReachedMaxSources(int $clientId): bool
    {
        return static::getActiveCountForClient($clientId) >= self::MAX_SOURCES_PER_CLIENT;
    }

    /**
     * Validate if a source name is unique for a client.
     *
     * @param string $name
     * @param int $clientId
     * @param int|null $excludeId
     * @return bool
     */
    public static function isNameUniqueForClient(string $name, int $clientId, ?int $excludeId = null): bool
    {
        $query = static::availableToClient($clientId)->byName($name);
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        
        return $query->count() === 0;
    }

    /**
     * Get sources grouped by type for a client.
     *
     * @param int $clientId
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, PocketExpenseSourceClientConfig>>
     */
    public static function getGroupedForClient(int $clientId): array
    {
        $sources = static::availableToClient($clientId)->orderBy('name')->get();

        return [
            'global' => $sources->filter(fn($source) => $source->isGlobal()),
            'default' => $sources->filter(fn($source) => $source->isDefault() && $source->isClientSpecific()),
            'custom' => $sources->filter(fn($source) => $source->isCustom() && $source->isClientSpecific()),
        ];
    }

    /**
     * Get sources as a key-value array for dropdowns.
     *
     * @param int $clientId
     * @return array<int, string>
     */
    public static function getOptionsForClient(int $clientId): array
    {
        return static::availableToClient($clientId)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->toArray();
    }

    /**
     * Get the most frequently used sources for a client.
     *
     * @param int $clientId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseSourceClientConfig>
     */
    public static function getMostUsedForClient(int $clientId, int $limit = 5): \Illuminate\Database\Eloquent\Collection
    {
        return static::availableToClient($clientId)
                    ->withCount('metadata')
                    ->orderBy('metadata_count', 'desc')
                    ->orderBy('name')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes
        $array['is_global'] = $this->isGlobal();
        $array['is_client_specific'] = $this->isClientSpecific();
        $array['is_active'] = $this->isActive();
        $array['can_be_deleted'] = $this->canBeDeleted();
        $array['can_be_edited'] = $this->canBeEdited();
        $array['description'] = $this->getDescription();
        $array['usage_count'] = $this->getUsageCount();
        $array['is_being_used'] = $this->isBeingUsed();
        
        return $array;
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     * @param string|null $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}