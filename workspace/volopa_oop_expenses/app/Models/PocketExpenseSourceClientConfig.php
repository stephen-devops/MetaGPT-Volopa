<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PocketExpenseSourceClientConfig Model
 * 
 * Manages expense sources configured per client.
 * Handles both client-specific sources and the global 'Other' source.
 * Uses flag-based soft delete pattern.
 * 
 * @property int $id
 * @property string $uuid
 * @property int|null $client_id
 * @property string $name
 * @property int $is_default
 * @property int $deleted
 * @property \Illuminate\Support\Carbon|null $delete_time
 * @property \Illuminate\Support\Carbon $create_time
 * @property \Illuminate\Support\Carbon|null $update_time
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
     * This table uses custom timestamp columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'create_time';

    /**
     * The name of the "updated at" column.
     *
     * @var string
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
        'is_default' => 'integer',
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
     * Get the client that owns this expense source.
     * Returns null for the global 'Other' record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get all metadata records that reference this expense source.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'expense_source_id', 'id');
    }

    /**
     * Scope a query to only include active (non-deleted) sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include deleted sources.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', 1);
    }

    /**
     * Scope a query to filter by client.
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
     * Scope a query to filter by source name.
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
     * Check if this source is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Check if this source is deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === 1;
    }

    /**
     * Check if this source is a default source.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->is_default === 1;
    }

    /**
     * Check if this source is the global 'Other' source.
     *
     * @return bool
     */
    public function isGlobalOther(): bool
    {
        return $this->client_id === null && $this->name === 'Other';
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
     * Soft delete this source by setting deleted flag and timestamp.
     * The global 'Other' source cannot be deleted.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        // Prevent deletion of global 'Other' source as per constraints
        if ($this->isGlobalOther()) {
            return false;
        }

        $this->deleted = 1;
        $this->delete_time = now();
        
        return $this->save();
    }

    /**
     * Restore this source by removing deleted flag and timestamp.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        
        return $this->save();
    }
}