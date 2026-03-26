<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Pocket Expense Source Client Config Model
 * 
 * Manages client-specific expense sources configuration.
 * Each client can have up to 20 active expense sources with unique names.
 * Includes global 'Other' record that cannot be deleted or edited.
 * 
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property string $name
 * @property bool $is_default
 * @property bool $deleted
 * @property \Illuminate\Support\Carbon|null $delete_time
 * @property \Illuminate\Support\Carbon $create_time
 * @property \Illuminate\Support\Carbon $update_time
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
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     * Using custom timestamp columns per Volopa legacy convention.
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
     * Boot the model.
     * Auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            $model->update_time = now();
        });

        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get the client that owns this expense source configuration.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense metadata that use this source.
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id');
    }

    /**
     * Scope a query to only include active (non-deleted) sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
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
     * Scope a query to only include global sources (client_id is null).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('client_id');
    }

    /**
     * Scope a query to get available sources for a client (active + global).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAvailableForClient($query, int $clientId)
    {
        return $query->where('deleted', false)
                    ->where(function ($subQuery) use ($clientId) {
                        $subQuery->where('client_id', $clientId)
                                ->orWhereNull('client_id');
                    });
    }

    /**
     * Check if this source is the global 'Other' record.
     *
     * @return bool
     */
    public function isGlobalOther(): bool
    {
        return is_null($this->client_id) && $this->name === 'Other';
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
     * Check if this source is a default source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Soft delete this source.
     * Cannot delete global 'Other' record per system constraints.
     *
     * @return bool
     * @throws \Exception
     */
    public function softDelete(): bool
    {
        if ($this->isGlobalOther()) {
            throw new \Exception('Global Other record cannot be deleted');
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
        $this->deleted = false;
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
        return $this->name;
    }

    /**
     * Check if this source can be edited.
     * Global 'Other' record cannot be edited per system constraints.
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return !$this->isGlobalOther();
    }

    /**
     * Check if this source can be deleted.
     * Global 'Other' record cannot be deleted per system constraints.
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return !$this->isGlobalOther();
    }
}