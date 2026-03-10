<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * PocketExpenseMetadata Model
 * 
 * Manages normalized metadata associated with pocket expenses. This table externalizes
 * inline fields from the main expense table to support various metadata types including
 * categories, tracking codes, projects, file attachments, expense sources, and additional fields.
 * Uses soft delete pattern and supports flexible JSON details storage.
 * Uses custom timestamp pattern (create_time/update_time) and soft delete pattern (deleted/delete_time).
 * 
 * @property int $id Primary key for pocket expense metadata
 * @property int $pocket_expense_id Foreign key to pocket_expense table
 * @property string $metadata_type Type of metadata being stored
 * @property int|null $transaction_category_id Foreign key to transaction_category table for category metadata
 * @property int|null $tracking_code_id Foreign key to tracking_code table for tracking code metadata
 * @property int|null $project_id Foreign key to project table for project metadata
 * @property int|null $file_store_id Foreign key to file_store table for file attachment metadata
 * @property int|null $expense_source_id Foreign key to pocket_expense_source_client_config table for source metadata
 * @property int|null $additional_field_id Foreign key to additional_field table for custom field metadata
 * @property int $user_id User who created this metadata record
 * @property array|null $details_json JSON field for storing flexible metadata details and configurations
 * @property Carbon $create_time Timestamp when the metadata record was created
 * @property Carbon $update_time Timestamp when the metadata record was last updated
 * @property bool $deleted Soft delete flag
 * @property Carbon|null $delete_time Timestamp when the metadata record was soft deleted
 * @property-read PocketExpense $pocketExpense
 * @property-read TransactionCategory|null $transactionCategory
 * @property-read TrackingCode|null $trackingCode
 * @property-read Project|null $project
 * @property-read FileStore|null $fileStore
 * @property-read PocketExpenseSourceClientConfig|null $expenseSource
 * @property-read AdditionalField|null $additionalField
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
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
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
     * Valid metadata type values.
     *
     * @var array<int, string>
     */
    public const METADATA_TYPE_CATEGORY = 'category';
    public const METADATA_TYPE_TRACKING_CODE = 'tracking_code';
    public const METADATA_TYPE_PROJECT = 'project';
    public const METADATA_TYPE_FILE_ATTACHMENT = 'file_attachment';
    public const METADATA_TYPE_EXPENSE_SOURCE = 'expense_source';
    public const METADATA_TYPE_ADDITIONAL_FIELD = 'additional_field';
    public const METADATA_TYPE_OTHER = 'other';

    /**
     * All valid metadata types.
     *
     * @var array<int, string>
     */
    public const VALID_METADATA_TYPES = [
        self::METADATA_TYPE_CATEGORY,
        self::METADATA_TYPE_TRACKING_CODE,
        self::METADATA_TYPE_PROJECT,
        self::METADATA_TYPE_FILE_ATTACHMENT,
        self::METADATA_TYPE_EXPENSE_SOURCE,
        self::METADATA_TYPE_ADDITIONAL_FIELD,
        self::METADATA_TYPE_OTHER,
    ];

    /**
     * Metadata type field mappings.
     * Maps metadata type to the foreign key field that should be populated.
     *
     * @var array<string, string>
     */
    public const METADATA_TYPE_FIELD_MAPPING = [
        self::METADATA_TYPE_CATEGORY => 'transaction_category_id',
        self::METADATA_TYPE_TRACKING_CODE => 'tracking_code_id',
        self::METADATA_TYPE_PROJECT => 'project_id',
        self::METADATA_TYPE_FILE_ATTACHMENT => 'file_store_id',
        self::METADATA_TYPE_EXPENSE_SOURCE => 'expense_source_id',
        self::METADATA_TYPE_ADDITIONAL_FIELD => 'additional_field_id',
    ];

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Set timestamps when creating new records
        static::creating(function (PocketExpenseMetadata $model) {
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            
            if (empty($model->update_time)) {
                $model->update_time = now();
            }

            // Set user_id if not already set and user is authenticated
            if (empty($model->user_id) && auth()->check()) {
                $model->user_id = auth()->user()->id;
            }
        });

        // Update the update_time when updating records
        static::updating(function (PocketExpenseMetadata $model) {
            $model->update_time = now();
        });

        // Apply soft delete scope by default
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('deleted', false);
        });

        // Validate metadata type values
        static::saving(function (PocketExpenseMetadata $model) {
            if (!self::isValidMetadataType($model->metadata_type)) {
                throw new \InvalidArgumentException(
                    "Invalid metadata_type value: {$model->metadata_type}. Must be one of: " . 
                    implode(', ', self::VALID_METADATA_TYPES)
                );
            }

            // Validate that the correct foreign key field is set for the metadata type
            $model->validateForeignKeyForMetadataType();
        });
    }

    /**
     * Get the pocket expense that owns this metadata.
     *
     * @return BelongsTo<PocketExpense, PocketExpenseMetadata>
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the transaction category for this metadata (if category type).
     *
     * @return BelongsTo<TransactionCategory, PocketExpenseMetadata>
     */
    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code for this metadata (if tracking code type).
     *
     * @return BelongsTo<TrackingCode, PocketExpenseMetadata>
     */
    public function trackingCode(): BelongsTo
    {
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the project for this metadata (if project type).
     *
     * @return BelongsTo<Project, PocketExpenseMetadata>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the file store for this metadata (if file attachment type).
     *
     * @return BelongsTo<FileStore, PocketExpenseMetadata>
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class, 'file_store_id');
    }

    /**
     * Get the expense source for this metadata (if expense source type).
     *
     * @return BelongsTo<PocketExpenseSourceClientConfig, PocketExpenseMetadata>
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field for this metadata (if additional field type).
     *
     * @return BelongsTo<AdditionalField, PocketExpenseMetadata>
     */
    public function additionalField(): BelongsTo
    {
        return $this->belongsTo(AdditionalField::class, 'additional_field_id');
    }

    /**
     * Get the user who created this metadata record.
     *
     * @return BelongsTo<User, PocketExpenseMetadata>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope a query to filter by specific pocket expense.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $pocketExpenseId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeForPocketExpense(Builder $query, int $pocketExpenseId): Builder
    {
        return $query->where('pocket_expense_id', $pocketExpenseId);
    }

    /**
     * Scope a query to filter by metadata type.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param string $metadataType
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByMetadataType(Builder $query, string $metadataType): Builder
    {
        return $query->where('metadata_type', $metadataType);
    }

    /**
     * Scope a query to filter by specific user.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $userId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include category metadata.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeCategory(Builder $query): Builder
    {
        return $query->where('metadata_type', self::METADATA_TYPE_CATEGORY);
    }

    /**
     * Scope a query to only include tracking code metadata.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeTrackingCode(Builder $query): Builder
    {
        return $query->where('metadata_type', self::METADATA_TYPE_TRACKING_CODE);
    }

    /**
     * Scope a query to only include project metadata.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeProject(Builder $query): Builder
    {
        return $query->where('metadata_type', self::METADATA_TYPE_PROJECT);
    }

    /**
     * Scope a query to only include file attachment metadata.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeFileAttachment(Builder $query): Builder
    {
        return $query->where('metadata_type', self::METADATA_TYPE_FILE_ATTACHMENT);
    }

    /**
     * Scope a query to only include expense source metadata.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeExpenseSource(Builder $query): Builder
    {
        return $query->where('metadata_type', self::METADATA_TYPE_EXPENSE_SOURCE);
    }

    /**
     * Scope a query to only include additional field metadata.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeAdditionalField(Builder $query): Builder
    {
        return $query->where('metadata_type', self::METADATA_TYPE_ADDITIONAL_FIELD);
    }

    /**
     * Scope a query to filter by transaction category ID.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $categoryId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByTransactionCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('transaction_category_id', $categoryId);
    }

    /**
     * Scope a query to filter by tracking code ID.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $trackingCodeId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByTrackingCode(Builder $query, int $trackingCodeId): Builder
    {
        return $query->where('tracking_code_id', $trackingCodeId);
    }

    /**
     * Scope a query to filter by project ID.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $projectId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope a query to filter by file store ID.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $fileStoreId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByFileStore(Builder $query, int $fileStoreId): Builder
    {
        return $query->where('file_store_id', $fileStoreId);
    }

    /**
     * Scope a query to filter by expense source ID.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $expenseSourceId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByExpenseSource(Builder $query, int $expenseSourceId): Builder
    {
        return $query->where('expense_source_id', $expenseSourceId);
    }

    /**
     * Scope a query to filter by additional field ID.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @param int $additionalFieldId
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeByAdditionalField(Builder $query, int $additionalFieldId): Builder
    {
        return $query->where('additional_field_id', $additionalFieldId);
    }

    /**
     * Scope a query to include deleted records.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted');
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder<PocketExpenseMetadata> $query
     * @return Builder<PocketExpenseMetadata>
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted')->where('deleted', true);
    }

    /**
     * Check if this metadata is a category type.
     *
     * @return bool
     */
    public function isCategory(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_CATEGORY;
    }

    /**
     * Check if this metadata is a tracking code type.
     *
     * @return bool
     */
    public function isTrackingCode(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_TRACKING_CODE;
    }

    /**
     * Check if this metadata is a project type.
     *
     * @return bool
     */
    public function isProject(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_PROJECT;
    }

    /**
     * Check if this metadata is a file attachment type.
     *
     * @return bool
     */
    public function isFileAttachment(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_FILE_ATTACHMENT;
    }

    /**
     * Check if this metadata is an expense source type.
     *
     * @return bool
     */
    public function isExpenseSource(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_EXPENSE_SOURCE;
    }

    /**
     * Check if this metadata is an additional field type.
     *
     * @return bool
     */
    public function isAdditionalField(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_ADDITIONAL_FIELD;
    }

    /**
     * Check if this metadata is other type.
     *
     * @return bool
     */
    public function isOther(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_OTHER;
    }

    /**
     * Check if this metadata is currently deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if this metadata is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Soft delete this metadata.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        if ($this->isDeleted()) {
            return false;
        }

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
        if (!$this->isDeleted()) {
            return false;
        }

        $this->deleted = false;
        $this->delete_time = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Force delete this metadata permanently.
     * Should only be used for maintenance operations.
     *
     * @return bool|null
     */
    public function forceDelete(): ?bool
    {
        return parent::delete();
    }

    /**
     * Get the foreign key value for the current metadata type.
     *
     * @return int|null
     */
    public function getForeignKeyValue(): ?int
    {
        $fieldName = self::METADATA_TYPE_FIELD_MAPPING[$this->metadata_type] ?? null;
        
        if ($fieldName === null) {
            return null;
        }
        
        return $this->{$fieldName};
    }

    /**
     * Set the foreign key value for the current metadata type.
     *
     * @param int|null $value
     * @return void
     */
    public function setForeignKeyValue(?int $value): void
    {
        $fieldName = self::METADATA_TYPE_FIELD_MAPPING[$this->metadata_type] ?? null;
        
        if ($fieldName !== null) {
            $this->{$fieldName} = $value;
        }
    }

    /**
     * Get the related model instance based on metadata type.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getRelatedModel(): ?\Illuminate\Database\Eloquent\Model
    {
        switch ($this->metadata_type) {
            case self::METADATA_TYPE_CATEGORY:
                return $this->transactionCategory;
            case self::METADATA_TYPE_TRACKING_CODE:
                return $this->trackingCode;
            case self::METADATA_TYPE_PROJECT:
                return $this->project;
            case self::METADATA_TYPE_FILE_ATTACHMENT:
                return $this->fileStore;
            case self::METADATA_TYPE_EXPENSE_SOURCE:
                return $this->expenseSource;
            case self::METADATA_TYPE_ADDITIONAL_FIELD:
                return $this->additionalField;
            default:
                return null;
        }
    }

    /**
     * Get a human-readable description of this metadata.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $relatedModel = $this->getRelatedModel();
        $relatedName = $relatedModel->name ?? $relatedModel->title ?? 'Unknown';
        $expenseReference = $this->pocketExpense->uuid ?? 'Unknown Expense';
        
        return "Metadata ({$this->metadata_type}): {$relatedName} for Expense {$expenseReference}";
    }

    /**
     * Get the display value for this metadata.
     *
     * @return string
     */
    public function getDisplayValue(): string
    {
        $relatedModel = $this->getRelatedModel();
        
        if ($relatedModel) {
            return $relatedModel->name ?? $relatedModel->title ?? $relatedModel->description ?? (string)$relatedModel->id;
        }
        
        if ($this->details_json && isset($this->details_json['display_value'])) {
            return (string)$this->details_json['display_value'];
        }
        
        return ucfirst(str_replace('_', ' ', $this->metadata_type));
    }

    /**
     * Get details from the JSON field.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->details_json ?? [];
        }
        
        return $this->details_json[$key] ?? $default;
    }

    /**
     * Set a detail in the JSON field.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setDetail(string $key, $value): void
    {
        $details = $this->details_json ?? [];
        $details[$key] = $value;
        $this->details_json = $details;
    }

    /**
     * Remove a detail from the JSON field.
     *
     * @param string $key
     * @return void
     */
    public function removeDetail(string $key): void
    {
        $details = $this->details_json ?? [];
        unset($details[$key]);
        $this->details_json = empty($details) ? null : $details;
    }

    /**
     * Check if the metadata belongs to a specific pocket expense.
     *
     * @param int $pocketExpenseId
     * @return bool
     */
    public function belongsToPocketExpense(int $pocketExpenseId): bool
    {
        return $this->pocket_expense_id === $pocketExpenseId;
    }

    /**
     * Check if the metadata was created by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function wasCreatedBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Find metadata by pocket expense and metadata type.
     *
     * @param int $pocketExpenseId
     * @param string $metadataType
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata>
     */
    public static function findByExpenseAndType(int $pocketExpenseId, string $metadataType): \Illuminate\Database\Eloquent\Collection
    {
        return static::forPocketExpense($pocketExpenseId)
                    ->byMetadataType($metadataType)
                    ->get();
    }

    /**
     * Get all metadata for a specific pocket expense.
     *
     * @param int $pocketExpenseId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata>
     */
    public static function getForExpense(int $pocketExpenseId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forPocketExpense($pocketExpenseId)->orderBy('metadata_type')->get();
    }

    /**
     * Get metadata grouped by type for a pocket expense.
     *
     * @param int $pocketExpenseId
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata>>
     */
    public static function getGroupedByTypeForExpense(int $pocketExpenseId): array
    {
        $metadata = static::getForExpense($pocketExpenseId);
        
        $grouped = [];
        foreach (self::VALID_METADATA_TYPES as $type) {
            $grouped[$type] = $metadata->filter(fn($item) => $item->metadata_type === $type);
        }
        
        return $grouped;
    }

    /**
     * Create category metadata for a pocket expense.
     *
     * @param int $pocketExpenseId
     * @param int $categoryId
     * @param int $userId
     * @param array|null $details
     * @return PocketExpenseMetadata
     */
    public static function createCategoryMetadata(int $pocketExpenseId, int $categoryId, int $userId, ?array $details = null): PocketExpenseMetadata
    {
        return static::create([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_CATEGORY,
            'transaction_category_id' => $categoryId,
            'user_id' => $userId,
            'details_json' => $details,
            'create_time' => now(),
            'update_time' => now(),
        ]);
    }

    /**
     * Create expense source metadata for a pocket expense.
     *
     * @param int $pocketExpenseId
     * @param int $sourceId
     * @param int $userId
     * @param array|null $details
     * @return PocketExpenseMetadata
     */
    public static function createExpenseSourceMetadata(int $pocketExpenseId, int $sourceId, int $userId, ?array $details = null): PocketExpenseMetadata
    {
        return static::create([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_EXPENSE_SOURCE,
            'expense_source_id' => $sourceId,
            'user_id' => $userId,
            'details_json' => $details,
            'create_time' => now(),
            'update_time' => now(),
        ]);
    }

    /**
     * Create file attachment metadata for a pocket expense.
     *
     * @param int $pocketExpenseId
     * @param int $fileStoreId
     * @param int $userId
     * @param array|null $details
     * @return PocketExpenseMetadata
     */
    public static function createFileAttachmentMetadata(int $pocketExpenseId, int $fileStoreId, int $userId, ?array $details = null): PocketExpenseMetadata
    {
        return static::create([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_FILE_ATTACHMENT,
            'file_store_id' => $fileStoreId,
            'user_id' => $userId,
            'details_json' => $details,
            'create_time' => now(),
            'update_time' => now(),
        ]);
    }

    /**
     * Validate that the correct foreign key field is set for the metadata type.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateForeignKeyForMetadataType(): void
    {
        $requiredField = self::METADATA_TYPE_FIELD_MAPPING[$this->metadata_type] ?? null;
        
        // For 'other' type, no specific foreign key is required
        if ($this->metadata_type === self::METADATA_TYPE_OTHER) {
            return;
        }
        
        if ($requiredField === null) {
            return; // No validation needed for unmapped types
        }
        
        if (empty($this->{$requiredField})) {
            throw new \InvalidArgumentException(
                "Metadata type '{$this->metadata_type}' requires '{$requiredField}' to be set"
            );
        }
        
        // Clear other foreign key fields that are not relevant for this metadata type
        foreach (self::METADATA_TYPE_FIELD_MAPPING as $type => $field) {
            if ($type !== $this->metadata_type && $field !== $requiredField) {
                $this->{$field} = null;
            }
        }
    }

    /**
     * Validate if a metadata type value is valid.
     *
     * @param string $metadataType
     * @return bool
     */
    public static function isValidMetadataType(string $metadataType): bool
    {
        return in_array($metadataType, self::VALID_METADATA_TYPES, true);
    }

    /**
     * Get metadata types as a key-value array for dropdowns.
     *
     * @return array<string, string>
     */
    public static function getMetadataTypeOptions(): array
    {
        return array_combine(
            self::VALID_METADATA_TYPES,
            array_map(fn($type) => ucfirst(str_replace('_', ' ', $type)), self::VALID_METADATA_TYPES)
        );
    }

    /**
     * Get the count of metadata records by type for a pocket expense.
     *
     * @param int $pocketExpenseId
     * @return array<string, int>
     */
    public static function getCountByTypeForExpense(int $pocketExpenseId): array
    {
        $counts = static::forPocketExpense($pocketExpenseId)
                       ->selectRaw('metadata_type, COUNT(*) as count')
                       ->groupBy('metadata_type')
                       ->pluck('count', 'metadata_type')
                       ->toArray();
        
        // Fill in missing types with 0
        foreach (self::VALID_METADATA_TYPES as $type) {
            if (!isset($counts[$type])) {
                $counts[$type] = 0;
            }
        }
        
        return $counts;
    }

    /**
     * Get the most frequently used metadata types.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, object>
     */
    public static function getMostUsedTypes(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return static::selectRaw('metadata_type, COUNT(*) as usage_count')
                    ->groupBy('metadata_type')
                    ->orderBy('usage_count', 'desc')
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
        $array['is_category'] = $this->isCategory();
        $array['is_tracking_code'] = $this->isTrackingCode();
        $array['is_project'] = $this->isProject();
        $array['is_file_attachment'] = $this->isFileAttachment();
        $array['is_expense_source'] = $this->isExpenseSource();
        $array['is_additional_field'] = $this->isAdditionalField();
        $array['is_other'] = $this->isOther();
        $array['is_active'] = $this->isActive();
        $array['foreign_key_value'] = $this->getForeignKeyValue();
        $array['description'] = $this->getDescription();
        $array['display_value'] = $this->getDisplayValue();
        
        return $array;
    }

    /**
     * Convert the model to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getDescription();
    }
}