<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pocket Expense Metadata Model
 * 
 * Manages metadata associated with pocket expenses.
 * Supports different metadata types with unique constraints per expense and type.
 * Uses flag-based soft delete pattern per Volopa legacy convention.
 * 
 * @property int $id
 * @property int $pocket_expense_id
 * @property string $metadata_type
 * @property int|null $transaction_category_id
 * @property int|null $tracking_code_id
 * @property int|null $project_id
 * @property int|null $file_store_id
 * @property int|null $expense_source_id
 * @property int|null $additional_field_id
 * @property int|null $user_id
 * @property array|null $details_json
 * @property \Illuminate\Support\Carbon $create_time
 * @property \Illuminate\Support\Carbon|null $update_time
 * @property bool $deleted
 * @property \Illuminate\Support\Carbon|null $delete_time
 */
class PocketExpenseMetadata extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_metadata';

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
        'pocket_expense_id',
        'metadata_type',
        'transaction_category_id',
        'tracking_code_id',
        'project_id',
        'file_store_id',
        'expense_source_id',
        'additional_field_id',
        'user_id',
        'details_json',
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
        'pocket_expense_id' => 'integer',
        'metadata_type' => 'string',
        'transaction_category_id' => 'integer',
        'tracking_code_id' => 'integer',
        'project_id' => 'integer',
        'file_store_id' => 'integer',
        'expense_source_id' => 'integer',
        'additional_field_id' => 'integer',
        'user_id' => 'integer',
        'details_json' => 'array',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'deleted' => 'boolean',
        'delete_time' => 'datetime',
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
        'deleted' => false,
    ];

    /**
     * Boot the model.
     * Manage custom timestamps.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
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
     * Get the expense that owns this metadata.
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the transaction category for this metadata.
     * TODO: Implement relationship when transaction_category table is defined
     */
    public function category(): BelongsTo
    {
        // TODO: Replace with actual TransactionCategory model when available
        return $this->belongsTo(\stdClass::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code for this metadata.
     * TODO: Implement relationship when tracking_code table is defined
     */
    public function trackingCode(): BelongsTo
    {
        // TODO: Replace with actual TrackingCode model when available
        return $this->belongsTo(\stdClass::class, 'tracking_code_id');
    }

    /**
     * Get the project for this metadata.
     * TODO: Implement relationship when project table is defined
     */
    public function project(): BelongsTo
    {
        // TODO: Replace with actual Project model when available
        return $this->belongsTo(\stdClass::class, 'project_id');
    }

    /**
     * Get the file store for this metadata.
     * TODO: Implement relationship when file_store table is defined
     */
    public function file(): BelongsTo
    {
        // TODO: Replace with actual FileStore model when available
        return $this->belongsTo(\stdClass::class, 'file_store_id');
    }

    /**
     * Get the expense source for this metadata.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field for this metadata.
     * TODO: Implement relationship when additional_field table is defined
     */
    public function additionalField(): BelongsTo
    {
        // TODO: Replace with actual AdditionalField model when available
        return $this->belongsTo(\stdClass::class, 'additional_field_id');
    }

    /**
     * Get the user associated with this metadata.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope a query to only include metadata for a specific expense.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $pocketExpenseId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForExpense($query, int $pocketExpenseId)
    {
        return $query->where('pocket_expense_id', $pocketExpenseId);
    }

    /**
     * Scope a query to only include active (non-deleted) metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include metadata of a specific type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $metadataType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $metadataType)
    {
        return $query->where('metadata_type', $metadataType);
    }

    /**
     * Scope a query to only include transaction category metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTransactionCategory($query)
    {
        return $query->where('metadata_type', 'transaction_category');
    }

    /**
     * Scope a query to only include tracking code metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCode($query)
    {
        return $query->where('metadata_type', 'tracking_code');
    }

    /**
     * Scope a query to only include project metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProject($query)
    {
        return $query->where('metadata_type', 'project');
    }

    /**
     * Scope a query to only include file store metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFileStore($query)
    {
        return $query->where('metadata_type', 'file_store');
    }

    /**
     * Scope a query to only include expense source metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpenseSource($query)
    {
        return $query->where('metadata_type', 'expense_source');
    }

    /**
     * Scope a query to only include additional field metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAdditionalField($query)
    {
        return $query->where('metadata_type', 'additional_field');
    }

    /**
     * Scope a query to only include source note metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSourceNote($query)
    {
        return $query->where('metadata_type', 'source_note');
    }

    /**
     * Check if this metadata is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !$this->deleted;
    }

    /**
     * Check if this metadata is of a specific type.
     *
     * @param string $type
     * @return bool
     */
    public function isOfType(string $type): bool
    {
        return $this->metadata_type === $type;
    }

    /**
     * Soft delete this metadata using flag-based soft delete.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = true;
        $this->delete_time = now();
        $this->update_time = now();
        
        return $this->save();
    }

    /**
     * Restore a soft deleted metadata.
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
     * Get the details JSON as an array.
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details_json ?? [];
    }

    /**
     * Set the details JSON from an array.
     *
     * @param array $details
     * @return void
     */
    public function setDetails(array $details): void
    {
        $this->details_json = $details;
    }

    /**
     * Get a specific detail value by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(string $key, $default = null)
    {
        $details = $this->getDetails();
        return $details[$key] ?? $default;
    }

    /**
     * Set a specific detail value by key.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setDetail(string $key, $value): void
    {
        $details = $this->getDetails();
        $details[$key] = $value;
        $this->setDetails($details);
    }

    /**
     * Get the metadata type display name.
     *
     * @return string
     */
    public function getTypeDisplayName(): string
    {
        return match ($this->metadata_type) {
            'transaction_category' => 'Transaction Category',
            'tracking_code' => 'Tracking Code',
            'project' => 'Project',
            'file_store' => 'File Store',
            'expense_source' => 'Expense Source',
            'additional_field' => 'Additional Field',
            'source_note' => 'Source Note',
            default => ucfirst(str_replace('_', ' ', $this->metadata_type)),
        };
    }
}