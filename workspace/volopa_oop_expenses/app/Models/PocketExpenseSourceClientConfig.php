<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Represents expense source configurations for clients with soft delete support.
 * Used for categorizing expense sources like "Company Credit Card", "Personal Cash", etc.
 * 
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property string $name
 * @property bool $is_default
 * @property bool $deleted
 * @property \Carbon\Carbon|null $delete_time
 * @property \Carbon\Carbon $create_time
 * @property \Carbon\Carbon|null $update_time
 * @property-read \App\Models\Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpenseMetadata> $metadata
 * @property-read int|null $metadata_count
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
     * Indicates if the model should be timestamped using Volopa pattern.
     * We override Laravel timestamps to use Volopa's create_time/update_time pattern.
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
    protected $hidden = [
        'deleted',
        'delete_time',
    ];

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
     * Get the client that this source configuration belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Client, \App\Models\PocketExpenseSourceClientConfig>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the metadata entries that reference this expense source.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id', 'id')
                    ->where('deleted', false);
    }

    /**
     * Scope a query to only include active (non-deleted) records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include records for a specific client.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClient($query, ?int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include global sources (no client_id).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope a query to only include default sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to find source by name.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $name
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByName($query, string $name)
    {
        return $query->where('name', $name);
    }

    /**
     * Check if the source is currently active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Check if the source is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if this is a global source (not client-specific).
     *
     * @return bool
     */
    public function isGlobal(): bool
    {
        return is_null($this->client_id);
    }

    /**
     * Check if this is the default source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default === true;
    }

    /**
     * Soft delete the source.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = true;
        $this->delete_time = now();
        return $this->save();
    }

    /**
     * Restore the soft deleted source.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->delete_time = null;
        return $this->save();
    }

    /**
     * Set this source as the default for its client.
     *
     * @return bool
     */
    public function setAsDefault(): bool
    {
        // First, unset any existing default for this client
        static::forClient($this->client_id)
              ->active()
              ->where('id', '!=', $this->id)
              ->update(['is_default' => false]);

        $this->is_default = true;
        return $this->save();
    }

    /**
     * Remove default status from this source.
     *
     * @return bool
     */
    public function removeDefault(): bool
    {
        $this->is_default = false;
        return $this->save();
    }

    /**
     * Get the count of associated metadata entries.
     *
     * @return int
     */
    public function getMetadataCountAttribute(): int
    {
        return $this->metadata()->count();
    }

    /**
     * Check if this source can be safely deleted.
     * Cannot delete if there are associated metadata entries.
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return $this->metadata()->count() === 0;
    }

    /**
     * Get all active sources for a specific client, including global sources.
     *
     * @param int|null $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAvailableForClient(?int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
                     ->where(function ($query) use ($clientId) {
                         $query->whereNull('client_id')  // Global sources
                               ->orWhere('client_id', $clientId);  // Client-specific sources
                     })
                     ->orderBy('is_default', 'desc')
                     ->orderBy('name', 'asc')
                     ->get();
    }

    /**
     * Get the default source for a specific client.
     *
     * @param int|null $clientId
     * @return \App\Models\PocketExpenseSourceClientConfig|null
     */
    public static function getDefaultForClient(?int $clientId): ?PocketExpenseSourceClientConfig
    {
        return static::active()
                     ->default()
                     ->where(function ($query) use ($clientId) {
                         $query->whereNull('client_id')  // Global default
                               ->orWhere('client_id', $clientId);  // Client-specific default
                     })
                     ->orderBy('client_id', 'desc')  // Prefer client-specific over global
                     ->first();
    }

    /**
     * Find source by UUID.
     *
     * @param string $uuid
     * @return \App\Models\PocketExpenseSourceClientConfig|null
     */
    public static function findByUuid(string $uuid): ?PocketExpenseSourceClientConfig
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * Find active source by name for a specific client.
     *
     * @param string $name
     * @param int|null $clientId
     * @return \App\Models\PocketExpenseSourceClientConfig|null
     */
    public static function findByNameForClient(string $name, ?int $clientId): ?PocketExpenseSourceClientConfig
    {
        return static::active()
                     ->byName($name)
                     ->where(function ($query) use ($clientId) {
                         $query->whereNull('client_id')
                               ->orWhere('client_id', $clientId);
                     })
                     ->orderBy('client_id', 'desc')  // Prefer client-specific over global
                     ->first();
    }

    /**
     * Create a new source configuration.
     *
     * @param array $attributes
     * @return static
     */
    public static function createSource(array $attributes): static
    {
        $attributes['uuid'] = $attributes['uuid'] ?? Str::uuid()->toString();
        $attributes['create_time'] = now();
        
        return static::create($attributes);
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Generate UUID on creation if not provided
        static::creating(function (PocketExpenseSourceClientConfig $source) {
            if (empty($source->uuid)) {
                $source->uuid = Str::uuid()->toString();
            }
            
            if (is_null($source->create_time)) {
                $source->create_time = now();
            }
            
            // Ensure defaults
            if (is_null($source->deleted)) {
                $source->deleted = false;
            }
            
            if (is_null($source->is_default)) {
                $source->is_default = false;
            }
        });

        // Handle default source logic on updates
        static::updating(function (PocketExpenseSourceClientConfig $source) {
            // Set update_time (handled by database trigger, but set here for consistency)
            $source->update_time = now();
            
            // If setting this as default, unset others for the same client
            if ($source->isDirty('is_default') && $source->is_default === true) {
                static::forClient($source->client_id)
                      ->active()
                      ->where('id', '!=', $source->id)
                      ->update(['is_default' => false]);
            }
        });

        // Prevent hard deletion if there are associated metadata entries
        static::deleting(function (PocketExpenseSourceClientConfig $source) {
            if (!$source->canDelete()) {
                throw new \RuntimeException('Cannot delete expense source that has associated metadata entries. Use soft delete instead.');
            }
        });

        // Log source configuration changes for audit purposes
        static::updated(function (PocketExpenseSourceClientConfig $source) {
            if ($source->isDirty(['name', 'is_default', 'deleted'])) {
                \Log::info('Expense source configuration updated', [
                    'source_id' => $source->id,
                    'uuid' => $source->uuid,
                    'client_id' => $source->client_id,
                    'name' => $source->name,
                    'is_default' => $source->is_default,
                    'deleted' => $source->deleted,
                    'changed_fields' => array_keys($source->getDirty()),
                    'updated_at' => now(),
                ]);
            }
        });

        // Log soft deletion events
        static::updated(function (PocketExpenseSourceClientConfig $source) {
            if ($source->isDirty('deleted') && $source->deleted === true) {
                \Log::info('Expense source soft deleted', [
                    'source_id' => $source->id,
                    'uuid' => $source->uuid,
                    'client_id' => $source->client_id,
                    'name' => $source->name,
                    'deleted_at' => $source->delete_time,
                ]);
            }
        });

        // Log restoration events
        static::updated(function (PocketExpenseSourceClientConfig $source) {
            if ($source->isDirty('deleted') && $source->deleted === false) {
                \Log::info('Expense source restored', [
                    'source_id' => $source->id,
                    'uuid' => $source->uuid,
                    'client_id' => $source->client_id,
                    'name' => $source->name,
                    'restored_at' => now(),
                ]);
            }
        });
    }
}