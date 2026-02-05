## Code: app/Models/PocketExpenseSourceClientConfig.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Manages expense source configurations for clients.
 * Supports global sources (client_id = null) and client-specific sources.
 * Includes soft delete functionality with custom delete_time field.
 * 
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property string $name
 * @property bool $is_default
 * @property bool $deleted
 * @property Carbon|null $delete_time
 * @property Carbon $create_time
 * @property Carbon $update_time
 * 
 * @property-read Client|null $client
 * @property-read Collection|PocketExpenseMetadata[] $expenseMetadata
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
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
        'deleted' => false,
    ];

    /**
     * Global source names that cannot be deleted.
     */
    public const GLOBAL_SOURCES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
        'Other',
    ];

    /**
     * The "Other" source name constant.
     */
    public const OTHER_SOURCE_NAME = 'Other';

    /**
     * Maximum number of active sources per client (excluding global).
     */
    public const MAX_ACTIVE_PER_CLIENT = 20;

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID when creating
        static::creating(function (self $model) {
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

        // Update the update_time when saving
        static::updating(function (self $model) {
            $model->update_time = now();
        });

        // Clear cache when sources are modified
        static::saved(function () {
            static::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
        });
    }

    /**
     * Get the client that owns this expense source configuration.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get all expense metadata records that use this source.
     *
     * @return HasMany
     */
    public function expenseMetadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id', 'id');
    }

    /**
     * Scope a query to only include non-deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include global sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope a query to filter by client.
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
     * Scope a query to include sources available for a client (global + client-specific).
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeAvailableForClient(Builder $query, int $clientId): Builder
    {
        return $query->where(function (Builder $subQuery) use ($clientId) {
            $subQuery->whereNull('client_id')
                ->orWhere('client_id', $clientId);
        });
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
     * Scope a query to only include non-default sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeNonDefault(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }

    /**
     * Scope a query to search by name.
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
     * Scope a query to search by name (case insensitive).
     *
     * @param Builder $query
     * @param string $name
     * @return Builder
     */
    public function scopeByNameInsensitive(Builder $query, string $name): Builder
    {
        return $query->whereRaw('LOWER(name) = LOWER(?)', [$name]);
    }

    /**
     * Scope a query to search by UUID.
     *
     * @param Builder $query
     * @param string $uuid
     * @return Builder
     */
    public function scopeByUuid(Builder $query, string $uuid): Builder
    {
        return $query->where('uuid', $uuid);
    }

    /**
     * Check if this source is global (not client-specific).
     *
     * @return bool
     */
    public function isGlobal(): bool
    {
        return is_null($this->client_id);
    }

    /**
     * Check if this source is client-specific.
     *
     * @return bool
     */
    public function isClientSpecific(): bool
    {
        return !is_null($this->client_id);
    }

    /**
     * Check if this source is the "Other" source.
     *
     * @return bool
     */
    public function isOtherSource(): bool
    {
        return $this->name === self::OTHER_SOURCE_NAME;
    }

    /**
     * Check if this source is a default source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Check if this source is deleted (soft deleted).
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
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
     * Check if this source can be deleted.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        // Global sources cannot be deleted
        if ($this->isGlobal()) {
            return false;
        }

        // Already deleted sources cannot be deleted again
        if ($this->isDeleted()) {
            return false;
        }

        return true;
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
        
        return $this->save();
    }

    /**
     * Restore this soft deleted source.
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
        
        return $this->save();
    }

    /**
     * Get the display name for this source.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->name;
    }

    /**
     * Get the full description including client context.
     *
     * @return string
     */
    public function getFullDescription(): string
    {
        if ($this->isGlobal()) {
            return sprintf('%s (Global)', $this->name);
        }

        return sprintf('%s (Client: %d)', $this->name, $this->client_id);
    }

    /**
     * Get all sources available for a client (active only).
     *
     * @param int $clientId
     * @return Collection
     */
    public static function getAvailableForClient(int $clientId): Collection
    {
        return static::active()
            ->availableForClient($clientId)
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get all global sources (active only).
     *
     * @return Collection
     */
    public static function getGlobalSources(): Collection
    {
        return static::active()
            ->global()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get all client-specific sources (active only).
     *
     * @param int $clientId
     * @return Collection
     */
    public static function getClientSources(int $clientId): Collection
    {
        return static::active()
            ->forClient($clientId)
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a source by name for a specific client (includes global sources).
     *
     * @param string $name
     * @param int $clientId
     * @return static|null
     */
    public static function findByNameForClient(string $name, int $clientId): ?static
    {
        return static::active()
            ->availableForClient($clientId)
            ->byName($name)
            ->first();
    }

    /**
     * Find a source by name for a specific client (case insensitive).
     *
     * @param string $name
     * @param int $clientId
     * @return static|null
     */
    public static function findByNameForClientInsensitive(string $name, int $clientId): ?static
    {
        return static::active()
            ->availableForClient($clientId)
            ->byNameInsensitive($name)
            ->first();
    }

    /**
     * Find a source by UUID.
     *
     * @param string $uuid
     * @return static|null
     */
    public static function findByUuid(string $uuid): ?static
    {
        return static::active()->byUuid($uuid)->first();
    }

    /**
     * Get the "Other" source (global).
     *
     * @return static|null
     */
    public static function getOtherSource(): ?static
    {
        return static::active()
            ->global()
            ->byName(self::OTHER_SOURCE_NAME)
            ->first();
    }

    /**
     * Get default sources for a client.
     *
     * @param int $clientId
     * @return Collection
     */
    public static function getDefaultSourcesForClient(int $clientId): Collection
    {
        return static::active()
            ->availableForClient($clientId)
            ->default()
            ->orderBy('name')
            ->get();
    }

    /**
     * Count active sources for a client (excluding global).
     *
     * @param int $clientId
     * @return int
     */
    public static function countClientSources(int $clientId): int
    {
        return static::active()
            ->forClient($clientId)
            ->count();
    }

    /**
     * Check if a client can add more sources.
     *
     * @param int $clientId
     * @return bool
     */
    public static function canAddMoreSources(int $clientId): bool
    {
        return static::countClientSources($clientId) < self::MAX_ACTIVE_PER_CLIENT;
    }

    /**
     * Validate if a source name is available for a client.
     *
     * @param string $name
     * @param int $clientId
     * @param int|null $excludeId
     * @return bool
     */
    public static function isNameAvailableForClient(string $name, int $clientId, ?int $excludeId = null): bool
    {
        $query = static::active()
            ->availableForClient($clientId)
            ->byNameInsensitive($name);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }

    /**
     * Get sources as key-value pairs for dropdowns.
     *
     * @param int $clientId
     * @return array<int, string>
     */
    public static function getOptionsForDropdown(int $clientId): array
    {
        return static::getAvailableForClient($clientId)
            