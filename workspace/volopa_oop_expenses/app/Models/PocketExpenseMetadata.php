<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PocketExpenseMetadata Model
 * 
 * Represents normalized metadata for pocket expenses including categories, tracking codes,
 * projects, file attachments, expense sources, and additional custom fields.
 * Uses Volopa's timestamp and soft delete patterns with JSON field support.
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
 * @property int $user_id
 * @property array|null $details_json
 * @property \Carbon\Carbon $create_time
 * @property \Carbon\Carbon|null $update_time
 * @property bool $deleted
 * @property \Carbon\Carbon|null $delete_time
 * @property-read \App\Models\PocketExpense $pocketExpense
 * @property-read \App\Models\User $user
 * @property-read \App\Models\TransactionCategory|null $transactionCategory
 * @property-read \App\Models\TrackingCode|null $trackingCode
 * @property-read \App\Models\ConfigurableProject|null $project
 * @property-read \App\Models\FileStore|null $fileStore
 * @property-read \App\Models\PocketExpenseSourceClientConfig|null $expenseSource
 * @property-read \App\Models\ExpenseAdditionalField|null $additionalField
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
        'deleted' => false,
    ];

    /**
     * The attributes that should be validated as dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'create_time',
        'update_time',
        'delete_time',
    ];

    /**
     * Metadata type enum values.
     */
    const TYPE_CATEGORY = 'category';
    const TYPE_TRACKING_CODE_TYPE_1 = 'tracking_code_type_1';
    const TYPE_TRACKING_CODE_TYPE_2 = 'tracking_code_type_2';
    const TYPE_PROJECT = 'project';
    const TYPE_ADDITIONAL_FIELD = 'additional_field';
    const TYPE_FILE = 'file';
    const TYPE_EXPENSE_SOURCE = 'expense_source';

    /**
     * Valid metadata type values.
     *
     * @var array<string>
     */
    public static array $validMetadataTypes = [
        self::TYPE_CATEGORY,
        self::TYPE_TRACKING_CODE_TYPE_1,
        self::TYPE_TRACKING_CODE_TYPE_2,
        self::TYPE_PROJECT,
        self::TYPE_ADDITIONAL_FIELD,
        self::TYPE_FILE,
        self::TYPE_EXPENSE_SOURCE,
    ];

    /**
     * Get the pocket expense that this metadata belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\PocketExpense, \App\Models\PocketExpenseMetadata>
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the user who created this metadata entry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpenseMetadata>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the transaction category if this metadata is category type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\TransactionCategory, \App\Models\PocketExpenseMetadata>
     */
    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code if this metadata is tracking code type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\TrackingCode, \App\Models\PocketExpenseMetadata>
     */
    public function trackingCode(): BelongsTo
    {
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the project if this metadata is project type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\ConfigurableProject, \App\Models\PocketExpenseMetadata>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ConfigurableProject::class, 'project_id');
    }

    /**
     * Get the file store if this metadata is file type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\FileStore, \App\Models\PocketExpenseMetadata>
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class, 'file_store_id');
    }

    /**
     * Get the expense source if this metadata is expense source type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\PocketExpenseSourceClientConfig, \App\Models\PocketExpenseMetadata>
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field if this metadata is additional field type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\ExpenseAdditionalField, \App\Models\PocketExpenseMetadata>
     */
    public function additionalField(): BelongsTo
    {
        return $this->belongsTo(ExpenseAdditionalField::class, 'additional_field_id');
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
     * Scope a query to only include soft deleted metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include metadata for a specific expense.
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
     * Scope a query to only include category metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategory($query)
    {
        return $query->where('metadata_type', self::TYPE_CATEGORY);
    }

    /**
     * Scope a query to only include tracking code type 1 metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCodeType1($query)
    {
        return $query->where('metadata_type', self::TYPE_TRACKING_CODE_TYPE_1);
    }

    /**
     * Scope a query to only include tracking code type 2 metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCodeType2($query)
    {
        return $query->where('metadata_type', self::TYPE_TRACKING_CODE_TYPE_2);
    }

    /**
     * Scope a query to only include project metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProject($query)
    {
        return $query->where('metadata_type', self::TYPE_PROJECT);
    }

    /**
     * Scope a query to only include file metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFile($query)
    {
        return $query->where('metadata_type', self::TYPE_FILE);
    }

    /**
     * Scope a query to only include expense source metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpenseSource($query)
    {
        return $query->where('metadata_type', self::TYPE_EXPENSE_SOURCE);
    }

    /**
     * Scope a query to only include additional field metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAdditionalField($query)
    {
        return $query->where('metadata_type', self::TYPE_ADDITIONAL_FIELD);
    }

    /**
     * Scope a query to only include metadata created by a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Check if the metadata is currently active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Check if the metadata is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if this is category metadata.
     *
     * @return bool
     */
    public function isCategory(): bool
    {
        return $this->metadata_type === self::TYPE_CATEGORY;
    }

    /**
     * Check if this is tracking code metadata.
     *
     * @return bool
     */
    public function isTrackingCode(): bool
    {
        return in_array($this->metadata_type, [self::TYPE_TRACKING_CODE_TYPE_1, self::TYPE_TRACKING_CODE_TYPE_2]);
    }

    /**
     * Check if this is project metadata.
     *
     * @return bool
     */
    public function isProject(): bool
    {
        return $this->metadata_type === self::TYPE_PROJECT;
    }

    /**
     * Check if this is file metadata.
     *
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->metadata_type === self::TYPE_FILE;
    }

    /**
     * Check if this is expense source metadata.
     *
     * @return bool
     */
    public function isExpenseSource(): bool
    {
        return $this->metadata_type === self::TYPE_EXPENSE_SOURCE;
    }

    /**
     * Check if this is additional field metadata.
     *
     * @return bool
     */
    public function isAdditionalField(): bool
    {
        return $this->metadata_type === self::TYPE_ADDITIONAL_FIELD;
    }

    /**
     * Get the related entity based on metadata type.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getRelatedEntity(): ?\Illuminate\Database\Eloquent\Model
    {
        return match ($this->metadata_type) {
            self::TYPE_CATEGORY => $this->transactionCategory,
            self::TYPE_TRACKING_CODE_TYPE_1, self::TYPE_TRACKING_CODE_TYPE_2 => $this->trackingCode,
            self::TYPE_PROJECT => $this->project,
            self::TYPE_FILE => $this->fileStore,
            self::TYPE_EXPENSE_SOURCE => $this->expenseSource,
            self::TYPE_ADDITIONAL_FIELD => $this->additionalField,
            default => null,
        };
    }

    /**
     * Get the foreign key field name for the current metadata type.
     *
     * @return string|null
     */
    public function getForeignKeyField(): ?string
    {
        return match ($this->metadata_type) {
            self::TYPE_CATEGORY => 'transaction_category_id',
            self::TYPE_TRACKING_CODE_TYPE_1, self::TYPE_TRACKING_CODE_TYPE_2 => 'tracking_code_id',
            self::TYPE_PROJECT => 'project_id',
            self::TYPE_FILE => 'file_store_id',
            self::TYPE_EXPENSE_SOURCE => 'expense_source_id',
            self::TYPE_ADDITIONAL_FIELD => 'additional_field_id',
            default => null,
        };
    }

    /**
     * Get the foreign key value for the current metadata type.
     *
     * @return int|null
     */
    public function getForeignKeyValue(): ?int
    {
        $field = $this->getForeignKeyField();
        return $field ? $this->$field : null;
    }

    /**
     * Set the foreign key value for the current metadata type.
     *
     * @param int|null $value
     * @return void
     */
    public function setForeignKeyValue(?int $value): void
    {
        $field = $this->getForeignKeyField();
        if ($field) {
            $this->$field = $value;
        }
    }

    /**
     * Soft delete the metadata.
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
     * Restore the soft deleted metadata.
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
     * Add additional details to the JSON field.
     *
     * @param array $details
     * @return bool
     */
    public function addDetails(array $details): bool
    {
        $existingDetails = $this->details_json ?? [];
        $this->details_json = array_merge($existingDetails, $details);
        return $this->save();
    }

    /**
     * Get a specific detail from the JSON field.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(string $key, mixed $default = null): mixed
    {
        return $this->details_json[$key] ?? $default;
    }

    /**
     * Set a specific detail in the JSON field.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setDetail(string $key, mixed $value): bool
    {
        $details = $this->details_json ?? [];
        $details[$key] = $value;
        $this->details_json = $details;
        return $this->save();
    }

    /**
     * Remove a specific detail from the JSON field.
     *
     * @param string $key
     * @return bool
     */
    public function removeDetail(string $key): bool
    {
        $details = $this->details_json ?? [];
        if (isset($details[$key])) {
            unset($details[$key]);
            $this->details_json = $details;
            return $this->save();
        }
        return false;
    }

    /**
     * Clear all details from the JSON field.
     *
     * @return bool
     */
    public function clearDetails(): bool
    {
        $this->details_json = null;
        return $this->save();
    }

    /**
     * Get all metadata for a specific expense grouped by type.
     *
     * @param int $expenseId
     * @return \Illuminate\Support\Collection
     */
    public static function getGroupedForExpense(int $expenseId): \Illuminate\Support\Collection
    {
        return static::active()
                     ->forExpense($expenseId)
                     ->with(['transactionCategory', 'trackingCode', 'project', 'fileStore', 'expenseSource', 'additionalField'])
                     ->get()
                     ->groupBy('metadata_type');
    }

    /**
     * Create metadata for an expense.
     *
     * @param int $expenseId
     * @param string $metadataType
     * @param int $userId
     * @param int|null $foreignKeyValue
     * @param array|null $details
     * @return static
     */
    public static function createMetadata(
        int $expenseId,
        string $metadataType,
        int $userId,
        ?int $foreignKeyValue = null,
        ?array $details = null
    ): static {
        $metadata = new static([
            'pocket_expense_id' => $expenseId,
            'metadata_type' => $metadataType,
            'user_id' => $userId,
            'details_json' => $details,
            'create_time' => now(),
            'deleted' => false,
        ]);

        // Set the appropriate foreign key based on metadata type
        if ($foreignKeyValue) {
            $metadata->setForeignKeyValue($foreignKeyValue);
        }

        $metadata->save();
        return $metadata;
    }

    /**
     * Find or create metadata for an expense and type.
     *
     * @param int $expenseId
     * @param string $metadataType
     * @param int $userId
     * @param int|null $foreignKeyValue
     * @param array|null $details
     * @return static
     */
    public static function findOrCreateForExpense(
        int $expenseId,
        string $metadataType,
        int $userId,
        ?int $foreignKeyValue = null,
        ?array $details = null
    ): static {
        $existing = static::active()
                          ->forExpense($expenseId)
                          ->ofType($metadataType)
                          ->first();

        if ($existing) {
            // Update existing metadata
            if ($foreignKeyValue) {
                $existing->setForeignKeyValue($foreignKeyValue);
            }
            if ($details) {
                $existing->details_json = $details;
            }
            $existing->save();
            return $existing;
        }

        return static::createMetadata($expenseId, $metadataType, $userId, $foreignKeyValue, $details);
    }

    /**
     * Validate if a metadata type value is valid.
     *
     * @param string $metadataType
     * @return bool
     */
    public static function isValidMetadataType(string $metadataType): bool
    {
        return in_array($metadataType, self::$validMetadataTypes);
    }

    /**
     * Get all valid metadata types.
     *
     * @return array<string>
     */
    public static function getValidMetadataTypes(): array
    {
        return self::$validMetadataTypes;
    }

    /**
     * Get metadata types that require foreign key references.
     *
     * @return array<string>
     */
    public static function getTypesWithForeignKeys(): array
    {
        return [
            self::TYPE_CATEGORY,
            self::TYPE_TRACKING_CODE_TYPE_1,
            self::TYPE_TRACKING_CODE_TYPE_2,
            self::TYPE_PROJECT,
            self::TYPE_FILE,
            self::TYPE_EXPENSE_SOURCE,
            self::TYPE_ADDITIONAL_FIELD,
        ];
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Set defaults and validate on creation
        static::creating(function (PocketExpenseMetadata $metadata) {
            if (is_null($metadata->create_time)) {
                $metadata->create_time = now();
            }
            
            // Ensure defaults
            if (is_null($metadata->deleted)) {
                $metadata->deleted = false;
            }

            // Validate metadata type
            if (!self::isValidMetadataType($metadata->metadata_type)) {
                throw new \InvalidArgumentException('Invalid metadata type: ' . $metadata->metadata_type);
            }

            // Validate that required foreign key is set for types that need it
            $foreignKeyField = match ($metadata->metadata_type) {
                self::TYPE_CATEGORY => 'transaction_category_id',
                self::TYPE_TRACKING_CODE_TYPE_1, self::TYPE_TRACKING_CODE_TYPE_2 => 'tracking_code_id',
                self::TYPE_PROJECT => 'project_id',
                self::TYPE_FILE => 'file_store_id',
                self::TYPE_EXPENSE_SOURCE => 'expense_source_id',
                self::TYPE_ADDITIONAL_FIELD => 'additional_field_id',
                default => null,
            };

            // For types that require foreign keys, validate that the key is provided
            if ($foreignKeyField && in_array($metadata->metadata_type, self::getTypesWithForeignKeys())) {
                if (is_null($metadata->$foreignKeyField)) {
                    throw new \InvalidArgumentException(
                        "Foreign key {$foreignKeyField} is required for metadata type: " . $metadata->metadata_type
                    );
                }
            }

            // Clear other foreign key fields that don't match the current metadata type
            $allForeignKeys = [
                'transaction_category_id',
                'tracking_code_id',
                'project_id',
                'file_store_id',
                'expense_source_id',
                'additional_field_id',
            ];

            foreach ($allForeignKeys as $key) {
                if ($key !== $foreignKeyField) {
                    $metadata->$key = null;
                }
            }
        });

        // Handle updates
        static::updating(function (PocketExpenseMetadata $metadata) {
            // Set update_time (handled by database trigger, but set here for consistency)
            $metadata->update_time = now();

            // Validate metadata type on updates
            if ($metadata->isDirty('metadata_type') && !self::isValidMetadataType($metadata->metadata_type)) {
                throw new \InvalidArgumentException('Invalid metadata type: ' . $metadata->metadata_type);
            }

            // If metadata type changed, clear inappropriate foreign keys
            if ($metadata->isDirty('metadata_type')) {
                $newForeignKeyField = match ($metadata->metadata_type) {
                    self::TYPE_CATEGORY => 'transaction_category_id',
                    self::TYPE_TRACKING_CODE_TYPE_1, self::TYPE_TRACKING_CODE_TYPE_2 => 'tracking_code_id',
                    self::TYPE_PROJECT => 'project_id',
                    self::TYPE_FILE => 'file_store_id',
                    self::TYPE_EXPENSE_SOURCE => 'expense_source_id',
                    self::TYPE_ADDITIONAL_FIELD => 'additional_field_id',
                    default => null,
                };

                $allForeignKeys = [
                    'transaction_category_id',
                    'tracking_code_id',
                    'project_id',
                    'file_store_id',
                    'expense_source_id',
                    'additional_field_id',
                ];

                foreach ($allForeignKeys as $key) {
                    if ($key !== $newForeignKeyField) {
                        $metadata->$key = null;
                    }
                }
            }
        });

        // Log metadata changes for audit purposes
        static::updated(function (PocketExpenseMetadata $metadata) {
            if ($metadata->isDirty(['metadata_type', 'deleted'])) {
                \Log::info('Expense metadata updated', [
                    'metadata_id' => $metadata->id,
                    'expense_id' => $metadata->pocket_expense_id,
                    'metadata_type' => $metadata->metadata_type,
                    'user_id' => $metadata->user_id,
                    'deleted' => $metadata->deleted,
                    'changed_fields' => array_keys($metadata->getDirty()),
                    'updated_at' => now(),
                ]);
            }
        });

        // Log soft deletion events
        static::updated(function (PocketExpenseMetadata $metadata) {
            if ($metadata->isDirty('deleted') && $metadata->deleted === true) {
                \Log::info('Expense metadata soft deleted', [
                    'metadata_id' => $metadata->id,
                    'expense_id' => $metadata->pocket_expense_id,
                    'metadata_type' => $metadata->metadata_type,
                    'user_id' => $metadata->user_id,
                    'deleted_at' => $metadata->delete_time,
                ]);
            }
        });

        // Log restoration events
        static::updated(function (PocketExpenseMetadata $metadata) {
            if ($metadata->isDirty('deleted') && $metadata->deleted === false) {
                \Log::info('Expense metadata restored', [
                    'metadata_id' => $metadata->id,
                    'expense_id' => $metadata->pocket_expense_id,
                    'metadata_type' => $metadata->metadata_type,
                    'user_id' => $metadata->user_id,
                    'restored_at' => now(),
                ]);
            }
        });

        // Prevent hard deletion - use soft delete instead
        static::deleting(function (PocketExpenseMetadata $metadata) {
            if (!$metadata->isDeleted()) {
                throw new \RuntimeException('Cannot delete metadata. Use soft delete instead.');
            }
        });
    }
}