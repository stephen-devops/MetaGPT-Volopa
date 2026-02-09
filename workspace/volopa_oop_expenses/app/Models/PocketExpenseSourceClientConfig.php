## Code: app/Models/PocketExpenseSourceClientConfig.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PocketExpenseSourceClientConfig extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pocket_expense_source_client_config';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'name',
        'is_default',
        'is_other',
        'is_system',
        'sort_order',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        // No hidden attributes for this model
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'client_id' => 'integer',
        'is_default' => 'boolean',
        'is_other' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
        'deleted' => 'boolean',
        'delete_time' => 'datetime',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
    ];

    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [
        'id',
        'uuid',
        'deleted',
        'delete_time',
        'create_time',
        'update_time',
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'is_default' => false,
        'is_other' => false,
        'is_system' => false,
        'sort_order' => 100,
        'deleted' => false,
    ];

    /**
     * Indicates if the model should use timestamps.
     */
    public $timestamps = false;

    /**
     * Default expense sources that are created when a client enables the feature.
     */
    const DEFAULT_SOURCES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
    ];

    /**
     * Maximum number of active sources allowed per client.
     */
    const MAX_ACTIVE_SOURCES_PER_CLIENT = 20;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
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

        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get the client that owns the source configuration.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Scope a query to only include sources for a specific client.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to exclude soft deleted records.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to include only soft deleted records.
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include default sources.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to only include non-default sources.
     */
    public function scopeNonDefault(Builder $query): Builder
    {
        return $query->where('is_default', false);
    }

    /**
     * Scope a query to only include "Other" sources.
     */
    public function scopeOther(Builder $query): Builder
    {
        return $query->where('is_other', true);
    }

    /**
     * Scope a query to exclude "Other" sources.
     */
    public function scopeNonOther(Builder $query): Builder
    {
        return $query->where('is_other', false);
    }

    /**
     * Scope a query to only include system sources.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope a query to exclude system sources.
     */
    public function scopeNonSystem(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope a query to only include global sources (client_id is null).
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope a query to exclude global sources.
     */
    public function scopeClientSpecific(Builder $query): Builder
    {
        return $query->whereNotNull('client_id');
    }

    /**
     * Scope a query to order sources by sort order.
     */
    public function scopeOrderBySortOrder(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('sort_order', $direction);
    }

    /**
     * Scope a query to order sources by name.
     */
    public function scopeOrderByName(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('name', $direction);
    }

    /**
     * Scope a query to search sources by name.
     */
    public function scopeSearchByName(Builder $query, string $search): Builder
    {
        return $query->where('name', 'LIKE', '%' . $search . '%');
    }

    /**
     * Scope a query to get active sources (not deleted, ordered by sort_order).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->notDeleted()->orderBySortOrder();
    }

    /**
     * Check if the source is default.
     */
    public function isDefault(): bool
    {
        return $this->is_default === true;
    }

    /**
     * Check if the source is "Other".
     */
    public function isOther(): bool
    {
        return $this->is_other === true;
    }

    /**
     * Check if the source is system-managed.
     */
    public function isSystem(): bool
    {
        return $this->is_system === true;
    }

    /**
     * Check if the source is global (applies to all clients).
     */
    public function isGlobal(): bool
    {
        return is_null($this->client_id);
    }

    /**
     * Check if the source is client-specific.
     */
    public function isClientSpecific(): bool
    {
        return !is_null($this->client_id);
    }

    /**
     * Check if the source is soft deleted.
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if the source can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return !$this->isSystem() && !$this->isOther();
    }

    /**
     * Check if the source can be edited.
     */
    public function canBeEdited(): bool
    {
        return !$this->isSystem() && !$this->isOther();
    }

    /**
     * Soft delete the source.
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
     * Restore the soft deleted source.
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->delete_time = null;
        return $this->save();
    }

    /**
     * Mark the source as default.
     */
    public function markAsDefault(): bool
    {
        if ($this->isSystem() || $this->isOther()) {
            return false;
        }

        // Remove default flag from other sources for the same client
        if ($this->client_id) {
            static::forClient($this->client_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);
        }

        $this->is_default = true;
        return $this->save();
    }

    /**
     * Remove the default flag from this source.
     */
    public function removeDefault(): bool
    {
        $this->is_default = false;
        return $this->save();
    }

    /**
     * Update the sort order.
     */
    public function updateSortOrder(int $sortOrder): bool
    {
        $this->sort_order = $sortOrder;
        return $this->save();
    }

    /**
     * Check if the source belongs to a specific client.
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Get the age of the source in days.
     */
    public function getAgeInDaysAttribute(): int
    {
        return now()->diffInDays($this->create_time);
    }

    /**
     * Check if the source is older than the specified number of days.
     */
    public function isOlderThan(int $days): bool
    {
        return $this->getAgeInDaysAttribute() > $days;
    }

    /**
     * Get all active sources for a client (including global "Other").
     */
    public static function getActiveSourcesForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        // Get client-specific sources
        $clientSources = static::forClient($clientId)
                            ->active()
                            ->get();

        // Get global "Other" source
        $otherSource = static::global()
                         ->other()
                         ->active()
                         ->first();

        $sources = $clientSources;
        if ($otherSource) {
            $sources->push($otherSource);
        }

        return $sources->sortBy('sort_order')->values();
    }

    /**
     * Get the default source for a client.
     */
    public static function getDefaultSourceForClient(int $clientId): ?self
    {
        return static::forClient($clientId)
                    ->default()
                    ->active()
                    ->first();
    }

    /**
     * Get the global "Other" source.
     */
    public static function getGlobalOtherSource(): ?self
    {
        return static::global()
                    ->other()
                    ->active()
                    ->first();
    }

    /**
     * Create default sources for a client when they enable the feature.
     */
    public static function createDefaultSourcesForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        $sources = collect();
        $sortOrder = 10;

        foreach (static::DEFAULT_SOURCES as $index => $sourceName) {
            $source = static::create([
                'client_id' => $clientId,
                'name' => $sourceName,
                'is_default' => $index === 0, // First source is default
                'is_other' => false,
                'is_system' => false,
                'sort_order' => $sortOrder,
            ]);

            $sources->push($source);
            $sortOrder += 10;
        }

        return $sources;
    }

    /**
     * Find a source by name for a client.
     */
    public static function findByNameForClient(string $name, int $clientId): ?self
    {
        // First try client-specific sources
        $source = static::forClient($clientId)
                        ->where('name', $name)
                        ->active()
                        ->first();

        // If not found and name is "Other", try global source
        if (!$source && strtolower($name) === 'other') {
            $source = static::getGlobalOtherSource();
        }

        return $source;
    }

    /**
     * Check if a client has reached the maximum number of sources.
     */
    public static function hasReachedMaxSourcesForClient(int $clientId): bool
    {
        $activeCount = static::forClient($clientId)
                            ->active()
                            ->count();

        return $activeCount >= static::MAX_ACTIVE_SOURCES_PER_CLIENT;
    }

    /**
     * Get the count of active sources for a client.
     */
    public static function getActiveSourceCountForClient(int $clientId): int
    {
        return static::forClient($clientId)
                    ->active()
                    ->count();
    }

    /**
     * Validate if a source name is unique for a client.
     */
    public static function isNameUniqueForClient(string $name, int $clientId, int $excludeId = null): bool
    {
        $query = static::forClient($clientId)
                      ->where('name', $name)
                      ->active();

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }

    /**
     * Get the next available sort order for a client.
     */
    public static function getNextSortOrderForClient(int $clientId): int
    {
        $maxSortOrder = static::forClient($clientId)
                             ->active()
                             ->max('sort_order');

        return ($maxSortOrder ?? 0) + 10;
    }

    /**
     * Reorder sources for a client.
     */
    public static function reorderSourcesForClient(int $clientId, array $sourceIds): bool
    {
        $sortOrder = 10;
        
        foreach ($sourceIds as $sourceId) {
            $source = static::where('id', $sourceId)
                           ->where('client_id', $clientId)
                           ->first();

            if ($source && $source->canBeEdited()) {
                $source->updateSortOrder($sortOrder);
                $sortOrder += 10;
            }
        }

        return true;
    }

    /**
     * Get sources with statistics for a client.
     */
    public static function getSourcesWithStatsForClient(int $clientId): array
    {
        $sources = static::getActiveSourcesForClient($clientId);
        
        return [
            'sources' => $sources,
            'total_count' => $sources->count(),
            'client_specific_count' => $sources->where('client_id', $clientId)->count(),
            'default_source' => $sources->where('is_default', true)->first(),
            'has_other' => $sources->where('is_other', true)->isNotEmpty(),
            'can_add_more' => !static::hasReachedMaxSourcesForClient($clientId),
            'max_allowed' => static::MAX_ACTIVE_SOURCES_PER_CLIENT,
        ];
    }

    /**
     * Duplicate a source (create a copy with a new name).
     */
    public function duplicate(string $newName): ?self