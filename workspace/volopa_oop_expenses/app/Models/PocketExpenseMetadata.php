<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\PocketExpenseMetadataFactory;

/**
 * PocketExpenseMetadata Model
 * 
 * Stores metadata for pocket expenses including categories, tracking codes, projects,
 * files, expense sources, and additional fields. Supports different metadata types
 * with specific reference IDs and soft delete functionality.
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
 * @property int $deleted
 * @property \DateTime|null $delete_time
 * @property \DateTime $create_time
 * @property \DateTime|null $update_time
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
     * Get the pocket expense this metadata belongs to.
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the transaction category (when metadata_type is 'category').
     * 
     * TODO: Replace with actual TransactionCategory model when available
     */
    public function transactionCategory(): BelongsTo
    {
        // TODO: Implement actual TransactionCategory model relationship
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code (when metadata_type is tracking_code_type_1 or tracking_code_type_2).
     * 
     * TODO: Replace with actual TrackingCode model when available
     */
    public function trackingCode(): BelongsTo
    {
        // TODO: Implement actual TrackingCode model relationship
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the project (when metadata_type is 'project').
     * 
     * TODO: Replace with actual ConfigurableProject model when available
     */
    public function project(): BelongsTo
    {
        // TODO: Implement actual ConfigurableProject model relationship
        return $this->belongsTo(ConfigurableProject::class, 'project_id');
    }

    /**
     * Get the file store (when metadata_type is 'file').
     * 
     * TODO: Replace with actual FileStore model when available
     */
    public function fileStore(): BelongsTo
    {
        // TODO: Implement actual FileStore model relationship
        return $this->belongsTo(FileStore::class, 'file_store_id');
    }

    /**
     * Get the expense source (when metadata_type is 'expense_source').
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field (when metadata_type is 'additional_field').
     * 
     * TODO: Replace with actual ExpenseAdditionalField model when available
     */
    public function additionalField(): BelongsTo
    {
        // TODO: Implement actual ExpenseAdditionalField model relationship
        return $this->belongsTo(ExpenseAdditionalField::class, 'additional_field_id');
    }

    /**
     * Get the user associated with this metadata.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope a query to only include non-deleted metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include deleted metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', 1);
    }

    /**
     * Scope a query to only include metadata for a specific expense.
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
     * Scope a query to only include metadata of a specific type.
     *
     * @param Builder $query
     * @param string $metadataType
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $metadataType): Builder
    {
        return $query->where('metadata_type', $metadataType);
    }

    /**
     * Scope a query to only include category metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCategory(Builder $query): Builder
    {
        return $query->where('metadata_type', 'category');
    }

    /**
     * Scope a query to only include tracking code type 1 metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTrackingCodeType1(Builder $query): Builder
    {
        return $query->where('metadata_type', 'tracking_code_type_1');
    }

    /**
     * Scope a query to only include tracking code type 2 metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTrackingCodeType2(Builder $query): Builder
    {
        return $query->where('metadata_type', 'tracking_code_type_2');
    }

    /**
     * Scope a query to only include project metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProject(Builder $query): Builder
    {
        return $query->where('metadata_type', 'project');
    }

    /**
     * Scope a query to only include additional field metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAdditionalField(Builder $query): Builder
    {
        return $query->where('metadata_type', 'additional_field');
    }

    /**
     * Scope a query to only include file metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFile(Builder $query): Builder
    {
        return $query->where('metadata_type', 'file');
    }

    /**
     * Scope a query to only include expense source metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeExpenseSource(Builder $query): Builder
    {
        return $query->where('metadata_type', 'expense_source');
    }

    /**
     * Check if this metadata is currently active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Check if this metadata is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === 1;
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
     * Check if this metadata is for category.
     *
     * @return bool
     */
    public function isCategory(): bool
    {
        return $this->metadata_type === 'category';
    }

    /**
     * Check if this metadata is for tracking code (type 1 or 2).
     *
     * @return bool
     */
    public function isTrackingCode(): bool
    {
        return in_array($this->metadata_type, ['tracking_code_type_1', 'tracking_code_type_2']);
    }

    /**
     * Check if this metadata is for project.
     *
     * @return bool
     */
    public function isProject(): bool
    {
        return $this->metadata_type === 'project';
    }

    /**
     * Check if this metadata is for file.
     *
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->metadata_type === 'file';
    }

    /**
     * Check if this metadata is for expense source.
     *
     * @return bool
     */
    public function isExpenseSource(): bool
    {
        return $this->metadata_type === 'expense_source';
    }

    /**
     * Check if this metadata is for additional field.
     *
     * @return bool
     */
    public function isAdditionalField(): bool
    {
        return $this->metadata_type === 'additional_field';
    }

    /**
     * Soft delete this metadata by setting deleted flag and delete_time.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = 1;
        $this->delete_time = now();
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Restore this metadata by clearing deleted flag and delete_time.
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
     * Get the reference ID based on metadata type.
     *
     * @return int|null
     */
    public function getReferenceId(): ?int
    {
        return match ($this->metadata_type) {
            'category' => $this->transaction_category_id,
            'tracking_code_type_1', 'tracking_code_type_2' => $this->tracking_code_id,
            'project' => $this->project_id,
            'file' => $this->file_store_id,
            'expense_source' => $this->expense_source_id,
            'additional_field' => $this->additional_field_id,
            default => null,
        };
    }

    /**
     * Set the reference ID based on metadata type.
     *
     * @param int $referenceId
     * @return void
     */
    public function setReferenceId(int $referenceId): void
    {
        match ($this->metadata_type) {
            'category' => $this->transaction_category_id = $referenceId,
            'tracking_code_type_1', 'tracking_code_type_2' => $this->tracking_code_id = $referenceId,
            'project' => $this->project_id = $referenceId,
            'file' => $this->file_store_id = $referenceId,
            'expense_source' => $this->expense_source_id = $referenceId,
            'additional_field' => $this->additional_field_id = $referenceId,
            default => null,
        };
    }

    /**
     * Get the display name for this metadata.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $typeName = ucwords(str_replace('_', ' ', $this->metadata_type));
        $referenceId = $this->getReferenceId();
        
        return "{$typeName}" . ($referenceId ? " (ID: {$referenceId})" : "");
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return PocketExpenseMetadataFactory
     */
    protected static function newFactory(): PocketExpenseMetadataFactory
    {
        return PocketExpenseMetadataFactory::new();
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
        });

        // Update update_time on updating
        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get validation rules for metadata type and reference validation.
     *
     * @return array
     */
    public static function getValidationRules(): array
    {
        return [
            'pocket_expense_id' => ['required', 'integer', 'exists:pocket_expense,id'],
            'metadata_type' => [
                'required', 
                'string', 
                'in:category,tracking_code_type_1,tracking_code_type_2,project,additional_field,file,expense_source'
            ],
            'transaction_category_id' => ['nullable', 'integer', 'exists:transaction_category,id'],
            'tracking_code_id' => ['nullable', 'integer', 'exists:tracking_codes,id'],
            'project_id' => ['nullable', 'integer', 'exists:configurable_projects,id'],
            'file_store_id' => ['nullable', 'integer', 'exists:file_store,id'],
            'expense_source_id' => ['nullable', 'integer', 'exists:pocket_expense_source_client_config,id'],
            'additional_field_id' => ['nullable', 'integer', 'exists:expense_additional_field,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'details_json' => ['nullable', 'array'],
        ];
    }
}