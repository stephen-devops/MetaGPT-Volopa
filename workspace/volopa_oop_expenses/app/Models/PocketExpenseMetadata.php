## Code: app/Models/PocketExpenseMetadata.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

/**
 * PocketExpenseMetadata Model
 * 
 * Manages metadata associated with pocket expenses.
 * Supports various metadata types including categories, tracking codes, projects, files, and expense sources.
 * Uses polymorphic relationships to reference different entity types.
 * Includes soft delete functionality with custom delete_time field.
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
 * @property Carbon $create_time
 * @property Carbon $update_time
 * @property bool $deleted
 * @property Carbon|null $delete_time
 * 
 * @property-read PocketExpense $expense
 * @property-read User $user
 * @property-read TransactionCategory|null $transactionCategory
 * @property-read TrackingCode|null $trackingCode
 * @property-read Project|null $project
 * @property-read FileStore|null $fileStore
 * @property-read PocketExpenseSourceClientConfig|null $expenseSource
 * @property-read AdditionalField|null $additionalField
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
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'create_time';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = 'update_time';

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
     * Metadata type constants.
     */
    public const TYPE_CATEGORY = 'category';
    public const TYPE_TRACKING_CODE_TYPE_1 = 'tracking_code_type_1';
    public const TYPE_TRACKING_CODE_TYPE_2 = 'tracking_code_type_2';
    public const TYPE_PROJECT = 'project';
    public const TYPE_ADDITIONAL_FIELD = 'additional_field';
    public const TYPE_FILE = 'file';
    public const TYPE_EXPENSE_SOURCE = 'expense_source';

    /**
     * All available metadata types.
     */
    public const METADATA_TYPES = [
        self::TYPE_CATEGORY,
        self::TYPE_TRACKING_CODE_TYPE_1,
        self::TYPE_TRACKING_CODE_TYPE_2,
        self::TYPE_PROJECT,
        self::TYPE_ADDITIONAL_FIELD,
        self::TYPE_FILE,
        self::TYPE_EXPENSE_SOURCE,
    ];

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Set create_time and update_time when creating
        static::creating(function (self $model) {
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            
            if (empty($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Update the update_time when saving
        static::updating(function (self $model) {
            $model->update_time = now();
        });
    }

    /**
     * Get the pocket expense that owns this metadata.
     *
     * @return BelongsTo
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id', 'id');
    }

    /**
     * Get the user associated with this metadata.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the transaction category associated with this metadata.
     *
     * @return BelongsTo
     */
    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code associated with this metadata.
     *
     * @return BelongsTo
     */
    public function trackingCode(): BelongsTo
    {
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the project associated with this metadata.
     *
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the file store associated with this metadata.
     *
     * @return BelongsTo
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class, 'file_store_id');
    }

    /**
     * Get the expense source associated with this metadata.
     *
     * @return BelongsTo
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field associated with this metadata.
     *
     * @return BelongsTo
     */
    public function additionalField(): BelongsTo
    {
        return $this->belongsTo(AdditionalField::class, 'additional_field_id');
    }

    /**
     * Scope a query to only include non-deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to filter by pocket expense.
     *
     * @param Builder $query
     * @param int $expenseId
     * @return Builder
     */
    public function scopeForExpense(Builder $query, int $expenseId): Builder
    {
        return $query->where('pocket_expense_id', $expenseId);
    }

    /**
     * Scope a query to filter by metadata type.
     *
     * @param Builder $query
     * @param string $metadataType
     * @return Builder
     */
    public function scopeByType(Builder $query, string $metadataType): Builder
    {
        return $query->where('metadata_type', $metadataType);
    }

    /**
     * Scope a query to filter by multiple metadata types.
     *
     * @param Builder $query
     * @param array $metadataTypes
     * @return Builder
     */
    public function scopeByTypes(Builder $query, array $metadataTypes): Builder
    {
        return $query->whereIn('metadata_type', $metadataTypes);
    }

    /**
     * Scope a query to filter by user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by transaction category.
     *
     * @param Builder $query
     * @param int $categoryId
     * @return Builder
     */
    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('transaction_category_id', $categoryId);
    }

    /**
     * Scope a query to filter by tracking code.
     *
     * @param Builder $query
     * @param int $trackingCodeId
     * @return Builder
     */
    public function scopeByTrackingCode(Builder $query, int $trackingCodeId): Builder
    {
        return $query->where('tracking_code_id', $trackingCodeId);
    }

    /**
     * Scope a query to filter by project.
     *
     * @param Builder $query
     * @param int $projectId
     * @return Builder
     */
    public function scopeByProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope a query to filter by file store.
     *
     * @param Builder $query
     * @param int $fileStoreId
     * @return Builder
     */
    public function scopeByFileStore(Builder $query, int $fileStoreId): Builder
    {
        return $query->where('file_store_id', $fileStoreId);
    }

    /**
     * Scope a query to filter by expense source.
     *
     * @param Builder $query
     * @param int $expenseSourceId
     * @return Builder
     */
    public function scopeByExpenseSource(Builder $query, int $expenseSourceId): Builder
    {
        return $query->where('expense_source_id', $expenseSourceId);
    }

    /**
     * Scope a query to filter by additional field.
     *
     * @param Builder $query
     * @param int $additionalFieldId
     * @return Builder
     */
    public function scopeByAdditionalField(Builder $query, int $additionalFieldId): Builder
    {
        return $query->where('additional_field_id', $additionalFieldId);
    }

    /**
     * Scope a query to only include category metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCategories(Builder $query): Builder
    {
        return $query->byType(self::TYPE_CATEGORY);
    }

    /**
     * Scope a query to only include tracking code type 1 metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTrackingCodeType1(Builder $query): Builder
    {
        return $query->byType(self::TYPE_TRACKING_CODE_TYPE_1);
    }

    /**
     * Scope a query to only include tracking code type 2 metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTrackingCodeType2(Builder $query): Builder
    {
        return $query->byType(self::TYPE_TRACKING_CODE_TYPE_2);
    }

    /**
     * Scope a query to only include project metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProjects(Builder $query): Builder
    {
        return $query->byType(self::TYPE_PROJECT);
    }

    /**
     * Scope a query to only include additional field metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAdditionalFields(Builder $query): Builder
    {
        return $query->byType(self::TYPE_ADDITIONAL_FIELD);
    }

    /**
     * Scope a query to only include file metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFiles(Builder $query): Builder
    {
        return $query->byType(self::TYPE_FILE);
    }

    /**
     * Scope a query to only include expense source metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeExpenseSources(Builder $query): Builder
    {
        return $query->byType(self::TYPE_EXPENSE_SOURCE);
    }

    /**
     * Check if this metadata is deleted (soft deleted).
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
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
    public function isType(string $type): bool
    {
        return $this->metadata_type === $type;
    }

    /**
     * Check if this metadata is category type.
     *
     * @return bool
     */
    public function isCategory(): bool
    {
        return $this->isType(self::TYPE_CATEGORY);
    }

    /**
     * Check if this metadata is tracking code type 1.
     *
     * @return bool
     */
    public function isTrackingCodeType1(): bool
    {
        return $this->isType(self::TYPE_TRACKING_CODE_TYPE_1);
    }

    /**
     * Check if this metadata is tracking code type 2.
     *
     * @return bool
     */
    public function isTrackingCodeType2(): bool
    {
        return $this->isType(self::TYPE_TRACKING_CODE_TYPE_2);
    }

    /**
     * Check if this metadata is project