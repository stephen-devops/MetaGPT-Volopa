<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Manages expense source configurations for clients. Each client can have
 * up to 20 active expense sources (Cash, Corporate Card, Personal Card, etc.).
 * Includes the global 'Other' source which is client-agnostic and cannot be deleted.
 * 
 * Uses Volopa legacy patterns:
 * - Soft delete with deleted flag and delete_time
 * - Legacy timestamps: create_time, update_time
 * - Multi-tenant scoping via client_id
 * 
 * Business Rules:
 * - Maximum 20 active expense sources per client
 * - Unique source names per client (excluding soft-deleted)
 * - Global 'Other' record (client_id = NULL) is not deletable/editable
 * - Three defaults auto-created: Cash, Corporate Card, Personal Card
 * 
 * @property int $id Primary key
 * @property string|null $uuid External UUID reference
 * @property int|null $client_id Client owner (NULL for global Other record)
 * @property string $name Expense source name (max 180 chars)
 * @property int $is_default Whether this is a default source for the client
 * @property int $deleted Soft delete flag (0 = active, 1 = deleted)
 * @property Carbon|null $delete_time Soft delete timestamp
 * @property Carbon|null $create_time Record creation timestamp
 * @property Carbon|null $update_time Record update timestamp
 * 
 * @property-read Client|null $client
 * @property-read \Illuminate\Database\Eloquent\Collection<PocketExpense> $pocketExpenses
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
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     * We use Volopa legacy timestamp pattern instead of Laravel's.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
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
        'is_default' => 'integer',
        'deleted' => 'integer',
        'delete_time' => 'datetime',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'deleted',
        'delete_time',
    ];

    /**
     * The "booted" method of the model.
     * Set up model event handlers for automatic UUID generation and timestamp management.
     *
     * @return void
     */
    protected static function booted(): void
    {
        // Generate UUID on creating (if not already set)
        static::creating(function (self $model) {
            if (empty($model->uuid) && $model->client_id !== null) {
                $model->uuid = Str::uuid()->toString();
            }
            
            // Set Volopa legacy timestamps
            $now = Carbon::now();
            if (empty($model->create_time)) {
                $model->create_time = $now;
            }
            if (empty($model->update_time)) {
                $model->update_time = $now;
            }
        });

        // Update timestamp on updating
        static::updating(function (self $model) {
            $model->update_time = Carbon::now();
        });
    }

    /**
     * Get the client that owns this expense source.
     * Returns null for the global 'Other' record.
     *
     * @return BelongsTo<Client, PocketExpenseSourceClientConfig>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the pocket expenses that use this expense source.
     * Relationship through pocket_expense_metadata where metadata_type = 'expense_source'.
     *
     * @return HasMany<PocketExpense>
     */
    public function pocketExpenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_source_id', 'id');
    }

    /**
     * Scope a query to only include active (non-soft-deleted) sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include soft-deleted sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', 1);
    }

    /**
     * Scope a query to only include sources for a specific client.
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
     * Scope a query to only include global sources (client_id is NULL).
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
        return $query->where('is_default', 1);
    }

    /**
     * Scope a query to only include non-default sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNonDefault($query)
    {
        return $query->where('is_default', 0);
    }

    /**
     * Scope a query to get sources available for a client.
     * Includes client-specific sources and the global 'Other' source.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailableForClient($query, int $clientId)
    {
        return $query->active()
            ->where(function ($q) use ($clientId) {
                $q->where('client_id', $clientId)
                  ->orWhereNull('client_id'); // Include global 'Other' record
            });
    }

    /**
     * Scope a query by source name.
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
     * Determine if this is the global 'Other' expense source.
     *
     * @return bool
     */
    public function isGlobalOther(): bool
    {
        return $this->client_id === null && $this->name === 'Other';
    }

    /**
     * Determine if this is a default expense source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default === 1;
    }

    /**
     * Determine if this expense source is active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Determine if this expense source is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === 1;
    }

    /**
     * Determine if this expense source can be deleted.
     * Global 'Other' record cannot be deleted.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return !$this->isGlobalOther();
    }

    /**
     * Determine if this expense source can be edited.
     * Global 'Other' record cannot be edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return !$this->isGlobalOther();
    }

    /**
     * Soft delete this expense source.
     * Cannot delete the global 'Other' record.
     *
     * @return bool
     * @throws \RuntimeException If attempting to delete global 'Other' record
     */
    public function softDelete(): bool
    {
        if ($this->isGlobalOther()) {
            throw new \RuntimeException('Cannot delete the global Other expense source.');
        }

        $this->deleted = 1;
        $this->delete_time = Carbon::now();
        $this->update_time = Carbon::now();

        return $this->save();
    }

    /**
     * Restore this soft deleted expense source.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        $this->update_time = Carbon::now();

        return $this->save();
    }

    /**
     * Get the count of active expense sources for a specific client.
     *
     * @param int $clientId
     * @return int
     */
    public static function getActiveCountForClient(int $clientId): int
    {
        return static::active()
            ->forClient($clientId)
            ->count();
    }

    /**
     * Check if a client has reached the maximum allowed expense sources (20).
     *
     * @param int $clientId
     * @return bool
     */
    public static function hasReachedMaximumForClient(int $clientId): bool
    {
        return static::getActiveCountForClient($clientId) >= 20;
    }

    /**
     * Get all active expense sources available for a client.
     * Includes client-specific sources and the global 'Other' source.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getAvailableForClient(int $clientId)
    {
        return static::availableForClient($clientId)
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Get all default expense sources for a client.
     * Used for auto-creation of Cash, Corporate Card, Personal Card.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getDefaultsForClient(int $clientId)
    {
        return static::active()
            ->forClient($clientId)
            ->default()
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Find an expense source by name for a specific client.
     *
     * @param int $clientId
     * @param string $name
     * @return static|null
     */
    public static function findByNameForClient(int $clientId, string $name): ?self
    {
        return static::active()
            ->availableForClient($clientId)
            ->byName($name)
            ->first();
    }

    /**
     * Find the global 'Other' expense source.
     *
     * @return static|null
     */
    public static function findGlobalOther(): ?self
    {
        return static::active()
            ->global()
            ->byName('Other')
            ->first();
    }

    /**
     * Create the three default expense sources for a client.
     * Called when OOP feature is enabled for a client.
     *
     * @param int $clientId
     * @return array<string, static>
     */
    public static function createDefaultsForClient(int $clientId): array
    {
        $defaults = [
            'Cash' => [
                'name' => 'Cash',
                'is_default' => 1,
            ],
            'Corporate Card' => [
                'name' => 'Corporate Card',
                'is_default' => 1,
            ],
            'Personal Card' => [
                'name' => 'Personal Card',
                'is_default' => 1,
            ],
        ];

        $created = [];
        $now = Carbon::now();

        foreach ($defaults as $key => $config) {
            // Check if default already exists
            $existing = static::active()
                ->forClient($clientId)
                ->byName($config['name'])
                ->first();

            if (!$existing) {
                $created[$key] = static::create([
                    'uuid' => Str::uuid()->toString(),
                    'client_id' => $clientId,
                    'name' => $config['name'],
                    'is_default' => $config['is_default'],
                    'deleted' => 0,
                    'delete_time' => null,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            } else {
                $created[$key] = $existing;
            }
        }

        return $created;
    }

    /**
     * Validate that source name is unique for the client (excluding soft-deleted).
     *
     * @param string $name
     * @param int $clientId
     * @param int|null $excludeId ID to exclude from uniqueness check (for updates)
     * @return bool
     */
    public static function isNameUniqueForClient(string $name, int $clientId, ?int $excludeId = null): bool
    {
        $query = static::active()
            ->forClient($clientId)
            ->byName($name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->count() === 0;
    }

    /**
     * Get a display-friendly name for this expense source.
     * Adds context for global sources.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        if ($this->isGlobalOther()) {
            return $this->name . ' (Global)';
        }

        return $this->name;
    }

    /**
     * Get an array representation suitable for API responses.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'display_name' => $this->getDisplayName(),
            'is_default' => $this->isDefault(),
            'is_global' => $this->isGlobalOther(),
            'can_be_edited' => $this->canBeEdited(),
            'can_be_deleted' => $this->canBeDeleted(),
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
        ];
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes for API responses
        $array['is_active'] = $this->isActive();
        $array['is_global_other'] = $this->isGlobalOther();
        $array['can_be_deleted'] = $this->canBeDeleted();
        $array['can_be_edited'] = $this->canBeEdited();
        $array['display_name'] = $this->getDisplayName();

        return $array;
    }
}