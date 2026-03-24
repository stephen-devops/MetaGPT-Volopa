<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Database\Factories\PocketExpenseMetadataFactory;

/**
 * PocketExpenseMetadata Model
 * 
 * Represents normalized metadata for pocket expenses including categories,
 * tracking codes, projects, files, expense sources, and additional fields.
 * Uses a discriminator pattern with metadata_type enum to determine which
 * foreign key relationship is active per record.
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
 * @property \DateTime|null $create_time
 * @property \DateTime|null $update_time
 * @property int $deleted
 * @property \DateTime|null $delete_time
 * 
 * @property-read PocketExpense $pocketExpense
 * @property-read \App\Models\TransactionCategory|null $transactionCategory
 * @property-read \App\Models\TrackingCode|null $trackingCode
 * @property-read \App\Models\ConfigurableProject|null $project
 * @property-read \App\Models\FileStore|null $fileStore
 * @property-read PocketExpenseSourceClientConfig|null $expenseSource
 * @property-read \App\Models\ExpenseAdditionalField|null $additionalField
 * @property-read \App\Models\User|null $user
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
     * Indicates if the model should be timestamped using Laravel conventions.
     * We use Volopa legacy timestamp pattern instead.
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
        'details_json' => 'json',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'deleted' => 'integer',
        'delete_time' => 'datetime',
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
     * Valid metadata type enum values.
     *
     * @var array<string>
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
     * Boot the model and set up event listeners.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Set create_time on creation
        static::creating(function (PocketExpenseMetadata $model) {
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
        });

        // Set update_time on creation and updates
        static::saving(function (PocketExpenseMetadata $model) {
            $model->update_time = now();
        });

        // Apply soft delete scope to exclude deleted records by default
        static::addGlobalScope('active', function ($query) {
            $query->where('deleted', 0);
        });
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\PocketExpenseMetadataFactory
     */
    protected static function newFactory(): PocketExpenseMetadataFactory
    {
        return PocketExpenseMetadataFactory::new();
    }

    /**
     * Get the parent pocket expense that owns this metadata.
     *
     * @return BelongsTo<PocketExpense, PocketExpenseMetadata>
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the transaction category when metadata_type is 'category'.
     *
     * @return BelongsTo<\App\Models\TransactionCategory, PocketExpenseMetadata>
     */
    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(\App\Models\TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code when metadata_type is 'tracking_code_type_1' or 'tracking_code_type_2'.
     *
     * @return BelongsTo<\App\Models\TrackingCode, PocketExpenseMetadata>
     */
    public function trackingCode(): BelongsTo
    {
        return $this->belongsTo(\App\Models\TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the configurable project when metadata_type is 'project'.
     *
     * @return BelongsTo<\App\Models\ConfigurableProject, PocketExpenseMetadata>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ConfigurableProject::class, 'project_id');
    }

    /**
     * Get the file store record when metadata_type is 'file'.
     *
     * @return BelongsTo<\App\Models\FileStore, PocketExpenseMetadata>
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FileStore::class, 'file_store_id');
    }

    /**
     * Get the expense source configuration when metadata_type is 'expense_source'.
     *
     * @return BelongsTo<PocketExpenseSourceClientConfig, PocketExpenseMetadata>
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field configuration when metadata_type is 'additional_field'.
     *
     * @return BelongsTo<\App\Models\ExpenseAdditionalField, PocketExpenseMetadata>
     */
    public function additionalField(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ExpenseAdditionalField::class, 'additional_field_id');
    }

    /**
     * Get the user associated with this metadata (for user-specific metadata).
     *
     * @return BelongsTo<\App\Models\User, PocketExpenseMetadata>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Scope a query to only include specific metadata type.
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
        return $query->where('metadata_type', 'category');
    }

    /**
     * Scope a query to only include tracking code metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $trackingType Optional specific tracking type (type_1 or type_2)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCode($query, ?string $trackingType = null)
    {
        if ($trackingType && in_array("tracking_code_{$trackingType}", self::METADATA_TYPES)) {
            return $query->where('metadata_type', "tracking_code_{$trackingType}");
        }

        return $query->whereIn('metadata_type', ['tracking_code_type_1', 'tracking_code_type_2']);
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
     * Scope a query to only include file metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFile($query)
    {
        return $query->where('metadata_type', 'file');
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
     * Scope a query to include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDeleted($query)
    {
        return $query->withoutGlobalScope('active');
    }

    /**
     * Scope a query to only include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnlyDeleted($query)
    {
        return $query->withoutGlobalScope('active')->where('deleted', 1);
    }

    /**
     * Scope a query for specific expense ID.
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
     * Get the active reference model based on metadata type.
     * Returns the related model instance that corresponds to the metadata type.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getActiveReference(): ?\Illuminate\Database\Eloquent\Model
    {
        switch ($this->metadata_type) {
            case 'category':
                return $this->transaction_category_id ? $this->transactionCategory : null;
            case 'tracking_code_type_1':
            case 'tracking_code_type_2':
                return $this->tracking_code_id ? $this->trackingCode : null;
            case 'project':
                return $this->project_id ? $this->project : null;
            case 'file':
                return $this->file_store_id ? $this->fileStore : null;
            case 'expense_source':
                return $this->expense_source_id ? $this->expenseSource : null;
            case 'additional_field':
                return $this->additional_field_id ? $this->additionalField : null;
            default:
                return null;
        }
    }

    /**
     * Get the reference ID for the active metadata type.
     *
     * @return int|null
     */
    public function getActiveReferenceId(): ?int
    {
        switch ($this->metadata_type) {
            case 'category':
                return $this->transaction_category_id;
            case 'tracking_code_type_1':
            case 'tracking_code_type_2':
                return $this->tracking_code_id;
            case 'project':
                return $this->project_id;
            case 'file':
                return $this->file_store_id;
            case 'expense_source':
                return $this->expense_source_id;
            case 'additional_field':
                return $this->additional_field_id;
            default:
                return null;
        }
    }

    /**
     * Check if this metadata has details in JSON format.
     *
     * @return bool
     */
    public function hasDetails(): bool
    {
        return !empty($this->details_json);
    }

    /**
     * Get a specific detail from the JSON details.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(string $key, $default = null)
    {
        if (!$this->hasDetails()) {
            return $default;
        }

        return data_get($this->details_json, $key, $default);
    }

    /**
     * Set a specific detail in the JSON details.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setDetail(string $key, $value): void
    {
        $details = $this->details_json ?: [];
        data_set($details, $key, $value);
        $this->details_json = $details;
    }

    /**
     * Check if the metadata type is valid.
     *
     * @param string $type
     * @return bool
     */
    public static function isValidMetadataType(string $type): bool
    {
        return in_array($type, self::METADATA_TYPES, true);
    }

    /**
     * Perform soft delete on this metadata record.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = 1;
        $this->delete_time = now();
        return $this->save();
    }

    /**
     * Restore a soft deleted metadata record.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        return $this->save();
    }

    /**
     * Check if the metadata record is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return (bool) $this->deleted;
    }

    /**
     * Get metadata for a specific expense and type.
     *
     * @param int $expenseId
     * @param string $metadataType
     * @return static|null
     */
    public static function findForExpenseAndType(int $expenseId, string $metadataType): ?self
    {
        return static::where('pocket_expense_id', $expenseId)
            ->where('metadata_type', $metadataType)
            ->first();
    }

    /**
     * Create or update metadata for a specific expense and type.
     *
     * @param int $expenseId
     * @param string $metadataType
     * @param array $attributes
     * @return static
     */
    public static function updateOrCreateForExpenseAndType(int $expenseId, string $metadataType, array $attributes): self
    {
        return static::updateOrCreate(
            [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => $metadataType,
                'deleted' => 0,
            ],
            array_merge($attributes, [
                'metadata_type' => $metadataType,
            ])
        );
    }

    /**
     * Validate that only the appropriate foreign key is set for the metadata type.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    public function validateForeignKeyConsistency(): void
    {
        if (!self::isValidMetadataType($this->metadata_type)) {
            throw new \InvalidArgumentException("Invalid metadata type: {$this->metadata_type}");
        }

        $allowedKeys = [
            'category' => ['transaction_category_id'],
            'tracking_code_type_1' => ['tracking_code_id'],
            'tracking_code_type_2' => ['tracking_code_id'],
            'project' => ['project_id'],
            'file' => ['file_store_id'],
            'expense_source' => ['expense_source_id'],
            'additional_field' => ['additional_field_id'],
        ];

        $allKeys = [
            'transaction_category_id',
            'tracking_code_id',
            'project_id',
            'file_store_id',
            'expense_source_id',
            'additional_field_id',
        ];

        $allowed = $allowedKeys[$this->metadata_type] ?? [];
        $forbidden = array_diff($allKeys, $allowed);

        // Check that forbidden keys are null
        foreach ($forbidden as $key) {
            if (!is_null($this->$key)) {
                throw new \InvalidArgumentException(
                    "Foreign key '{$key}' should be null for metadata type '{$this->metadata_type}'"
                );
            }
        }

        // Check that at least one allowed key is set (unless it's user_id only metadata)
        if (!empty($allowed)) {
            $hasActiveKey = false;
            foreach ($allowed as $key) {
                if (!is_null($this->$key)) {
                    $hasActiveKey = true;
                    break;
                }
            }

            if (!$hasActiveKey) {
                throw new \InvalidArgumentException(
                    "At least one of the following keys must be set for metadata type '{$this->metadata_type}': " . 
                    implode(', ', $allowed)
                );
            }
        }
    }

    /**
     * Override the save method to validate foreign key consistency.
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = []): bool
    {
        $this->validateForeignKeyConsistency();
        return parent::save($options);
    }
}