<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\PocketExpenseSourceClientConfigFactory;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Manages expense source configurations for clients with support for global 'Other' record.
 * Enforces unique source names per client and supports soft delete functionality.
 * Maximum 20 active expense sources per client as per system constraints.
 * 
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property string $name
 * @property bool $is_default
 * @property int $deleted
 * @property \DateTime|null $delete_time
 * @property \DateTime $create_time
 * @property \DateTime|null $update_time
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
     * Disable Laravel's default timestamps as we use Volopa legacy pattern
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
        'deleted' => 'integer',
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
     * Get the client this source belongs to (nullable for global 'Other' record).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get all expenses that use this source through metadata.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id');
    }

    /**
     * Scope a query to only include non-deleted sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include deleted sources.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', 1);
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
     * Scope a query to get available sources for a client (client sources + global 'Other').
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeAvailableForClient(Builder $query, int $clientId): Builder
    {
        return $query->active()
                    ->where(function ($subQuery) use ($clientId) {
                        $subQuery->where('client_id', $clientId)
                                ->orWhereNull('client_id');
                    });
    }

    /**
     * Check if this source is currently active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Check if this source is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === 1;
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
     * Check if this is the global 'Other' source.
     *
     * @return bool
     */
    public function isGlobalOther(): bool
    {
        return is_null($this->client_id) && $this->name === 'Other';
    }

    /**
     * Check if this source belongs to a specific client.
     *
     * @param int $clientId
     * @return bool
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Soft delete this source by setting deleted flag and delete_time.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        // Prevent deletion of global 'Other' record as per system constraints
        if ($this->isGlobalOther()) {
            return false;
        }

        $this->deleted = 1;
        $this->delete_time = now();
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Restore this source by clearing deleted flag and delete_time.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Get the display name for this source.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $prefix = $this->isGlobalOther() ? '[Global] ' : '';
        $suffix = $this->isDefault() ? ' (Default)' : '';
        
        return $prefix . $this->name . $suffix;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return PocketExpenseSourceClientConfigFactory
     */
    protected static function newFactory(): PocketExpenseSourceClientConfigFactory
    {
        return PocketExpenseSourceClientConfigFactory::new();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Set create_time on creation
        static::creating(function ($model) {
            if (is_null($model->create_time)) {
                $model->create_time = now();
            }
            if (is_null($model->update_time)) {
                $model->update_time = now();
            }
            
            // Generate UUID if not set
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid()->toString();
            }
        });

        // Update update_time on updating
        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get validation rules for unique constraint checking.
     *
     * @param int|null $excludeId
     * @return array
     */
    public static function getUniqueValidationRules(?int $excludeId = null): array
    {
        $uniqueRule = 'unique:pocket_expense_source_client_config,name,NULL,id,client_id';
        
        if ($excludeId) {
            $uniqueRule .= ',' . $excludeId;
        }

        return [
            'name' => ['required', 'string', 'max:100', $uniqueRule],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ];
    }
}