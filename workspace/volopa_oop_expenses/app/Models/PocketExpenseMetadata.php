<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PocketExpenseMetadata Model
 * 
 * Stores flexible metadata for pocket expenses using a pivot table approach.
 * Supports various metadata types including categories, tracking codes, projects,
 * files, expense sources, and additional fields. Uses flag-based soft delete
 * and Volopa legacy timestamps.
 * 
 * @property int $id
 * @property int $pocket_expense_id Foreign key to pocket_expense table
 * @property string $metadata_type Type of metadata (category, tracking_code_type_1, etc.)
 * @property int|null $transaction_category_id Foreign key to transaction category reference
 * @property int|null $tracking_code_id Foreign key to tracking code reference
 * @property int|null $project_id Foreign key to project reference  
 * @property int|null $file_store_id Foreign key to file storage reference
 * @property int|null $expense_source_id Foreign key to pocket_expense_source_client_config
 * @property int|null $additional_field_id Foreign key to additional field reference
 * @property int $user_id User associated with this metadata entry
 * @property array|null $details_json Additional metadata stored as JSON
 * @property \DateTime|null $create_time Volopa legacy creation timestamp
 * @property \DateTime|null $update_time Volopa legacy update timestamp
 * @property bool $deleted Flag-based soft delete indicator
 * @property \DateTime|null $delete_time Timestamp when metadata was soft deleted
 * 
 * @property-read PocketExpense $pocketExpense
 * @property-read PocketExpenseSourceClientConfig|null $expenseSource
 * @property-read User $user
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
     * Using custom Volopa legacy timestamps instead of Laravel's created_at/updated_at.
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
        'create_time',
        'update_time',
        'deleted',
        'delete_time',
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
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'create_time',
        'update_time',
        'delete_time',
    ];

    /**
     * Valid metadata types as per database enum constraint.
     *
     * @var array<int, string>
     */
    public const METADATA_TYPES = [
        'category',
        'tracking_code_type_1',
        'tracking_code_type_2',
        'project',
        'additional_field',
        'file',
        'expense_source',
    ];

    /**
     * Default values for new instances.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'deleted' => false,
        'transaction_category_id' => null,
        'tracking_code_id' => null,
        'project_id' => null,
        'file_store_id' => null,
        'expense_source_id' => null,
        'additional_field_id' => null,
        'details_json' => null,
        'delete_time' => null,
    ];

    /**
     * Boot the model.
     * Set up model event listeners for automatic timestamp management.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set create_time on creation
        static::creating(function (PocketExpenseMetadata $model): void {
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            if (empty($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Automatically update update_time on modification
        static::updating(function (PocketExpenseMetadata $model): void {
            $model->update_time = now();
        });

        // Global scope to exclude soft deleted records by default
        static::addGlobalScope('active', function ($builder) {
            $builder->where('deleted', false);
        });
    }

    /**
     * Get the pocket expense that owns this metadata.
     *
     * @return BelongsTo<PocketExpense, PocketExpenseMetadata>
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id', 'id');
    }

    /**
     * Get the expense source configuration referenced by this metadata.
     * Only applicable when metadata_type is 'expense_source'.
     *
     * @return BelongsTo<PocketExpenseSourceClientConfig, PocketExpenseMetadata>
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id', 'id');
    }

    /**
     * Get the user associated with this metadata entry.
     *
     * @return BelongsTo<User, PocketExpenseMetadata>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Note: The following foreign key relationships are intentionally not defined as Eloquent relationships
     * because the referenced tables (transaction categories, tracking codes, projects, file store, 
     * additional fields) are not yet defined in the current project scope. These IDs are stored for
     * future integration with existing Volopa platform reference tables.
     * 
     * When those tables are available, add these relationships:
     * - transactionCategory(): BelongsTo (transaction_category_id)
     * - trackingCode(): BelongsTo (tracking_code_id) 
     * - project(): BelongsTo (project_id)
     * - fileStore(): BelongsTo (file_store_id)
     * - additionalField(): BelongsTo (additional_field_id)
     */

    /**
     * Scope to include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDeleted($query)
    {
        return $query->withoutGlobalScope('active');
    }

    /**
     * Scope to get only soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnlyDeleted($query)
    {
        return $query->withoutGlobalScope('active')->where('deleted', true);
    }

    /**
     * Scope to filter by metadata type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('metadata_type', $type);
    }

    /**
     * Scope to get category metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategories($query)
    {
        return $query->where('metadata_type', 'category');
    }

    /**
     * Scope to get expense source metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpenseSources($query)
    {
        return $query->where('metadata_type', 'expense_source');
    }

    /**
     * Scope to get file metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFiles($query)
    {
        return $query->where('metadata_type', 'file');
    }

    /**
     * Scope to get tracking code metadata (both types).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCodes($query)
    {
        return $query->whereIn('metadata_type', ['tracking_code_type_1', 'tracking_code_type_2']);
    }

    /**
     * Scope to get project metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProjects($query)
    {
        return $query->where('metadata_type', 'project');
    }

    /**
     * Scope to filter by user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by expense.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $expenseId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForExpense($query, int $expenseId)
    {
        return $query->where('pocket_expense_id', $expenseId);
    }

    /**
     * Check if this metadata record is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return (bool) $this->deleted;
    }

    /**
     * Check if this metadata is of a specific type.
     *
     * @param string $type
     * @return bool
     */
    public function isType(string $type): bool
    {
        return $this->metadata_type === $type;
    }

    /**
     * Check if this metadata is a category type.
     *
     * @return bool
     */
    public function isCategory(): bool
    {
        return $this->metadata_type === 'category';
    }

    /**
     * Check if this metadata is an expense source type.
     *
     * @return bool
     */
    public function isExpenseSource(): bool
    {
        return $this->metadata_type === 'expense_source';
    }

    /**
     * Check if this metadata is a file type.
     *
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->metadata_type === 'file';
    }

    /**
     * Check if this metadata is a tracking code type.
     *
     * @return bool
     */
    public function isTrackingCode(): bool
    {
        return in_array($this->metadata_type, ['tracking_code_type_1', 'tracking_code_type_2']);
    }

    /**
     * Check if this metadata is a project type.
     *
     * @return bool
     */
    public function isProject(): bool
    {
        return $this->metadata_type === 'project';
    }

    /**
     * Get a specific detail from the JSON details field.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(string $key, $default = null)
    {
        $details = $this->details_json ?? [];
        return $details[$key] ?? $default;
    }

    /**
     * Set a specific detail in the JSON details field.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setDetail(string $key, $value): self
    {
        $details = $this->details_json ?? [];
        $details[$key] = $value;
        $this->details_json = $details;
        
        return $this;
    }

    /**
     * Get the source note from details_json (used when expense source is "Other").
     *
     * @return string|null
     */
    public function getSourceNote(): ?string
    {
        return $this->getDetail('source_note');
    }

    /**
     * Set the source note in details_json (used when expense source is "Other").
     *
     * @param string $note
     * @return self
     */
    public function setSourceNote(string $note): self
    {
        return $this->setDetail('source_note', $note);
    }

    /**
     * Check if the metadata type is valid.
     *
     * @param string $type
     * @return bool
     */
    public static function isValidMetadataType(string $type): bool
    {
        return in_array($type, self::METADATA_TYPES);
    }

    /**
     * Get all valid metadata types.
     *
     * @return array<int, string>
     */
    public static function getValidMetadataTypes(): array
    {
        return self::METADATA_TYPES;
    }

    /**
     * Perform flag-based soft delete.
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
     * Restore a soft deleted record.
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
     * Get the route key for the model.
     * Uses the primary key by default.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Convert the model instance to an array.
     * Excludes soft delete fields for external API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Remove internal soft delete fields from array representation
        unset($array['deleted'], $array['delete_time']);
        
        return $array;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\PocketExpenseMetadataFactory
     */
    protected static function newFactory(): \Database\Factories\PocketExpenseMetadataFactory
    {
        return \Database\Factories\PocketExpenseMetadataFactory::new();
    }
}