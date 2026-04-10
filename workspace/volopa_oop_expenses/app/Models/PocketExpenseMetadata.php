<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PocketExpenseMetadata Model
 * 
 * Manages metadata associated with pocket expenses including categories,
 * tracking codes, projects, files, expense sources, and additional fields.
 * Uses flag-based soft delete pattern.
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
 * @property string|null $details_json
 * @property \Illuminate\Support\Carbon $create_time
 * @property \Illuminate\Support\Carbon|null $update_time
 * @property int $deleted
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
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Get the pocket expense that owns this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id', 'id');
    }

    /**
     * Get the transaction category associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function transactionCategory(): BelongsTo
    {
        // TODO: Implement TransactionCategory model relationship when TransactionCategory model is available
        // For now, this is a placeholder for the foreign key constraint
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id', 'id');
    }

    /**
     * Get the tracking code associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trackingCode(): BelongsTo
    {
        // TODO: Implement TrackingCode model relationship when TrackingCode model is available
        // For now, this is a placeholder for the foreign key constraint
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id', 'id');
    }

    /**
     * Get the project associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project(): BelongsTo
    {
        // TODO: Implement ConfigurableProject model relationship when ConfigurableProject model is available
        // For now, this is a placeholder for the foreign key constraint
        return $this->belongsTo(ConfigurableProject::class, 'project_id', 'id');
    }

    /**
     * Get the file store associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fileStore(): BelongsTo
    {
        // TODO: Implement FileStore model relationship when FileStore model is available
        // For now, this is a placeholder for the foreign key constraint
        return $this->belongsTo(FileStore::class, 'file_store_id', 'id');
    }

    /**
     * Get the expense source associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id', 'id');
    }

    /**
     * Get the additional field associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function additionalField(): BelongsTo
    {
        // TODO: Implement ExpenseAdditionalField model relationship when ExpenseAdditionalField model is available
        // For now, this is a placeholder for the foreign key constraint
        return $this->belongsTo(ExpenseAdditionalField::class, 'additional_field_id', 'id');
    }

    /**
     * Get the user associated with this metadata.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Scope a query to only include active (non-deleted) metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include deleted metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', 1);
    }

    /**
     * Scope a query to filter by metadata type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('metadata_type', $type);
    }

    /**
     * Scope a query to filter by pocket expense.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $pocketExpenseId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForPocketExpense($query, int $pocketExpenseId)
    {
        return $query->where('pocket_expense_id', $pocketExpenseId);
    }

    /**
     * Scope a query to filter by user.
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
     * Scope a query to filter by category metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategory($query)
    {
        return $query->where('metadata_type', 'category');
    }

    /**
     * Scope a query to filter by tracking code type 1 metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCodeType1($query)
    {
        return $query->where('metadata_type', 'tracking_code_type_1');
    }

    /**
     * Scope a query to filter by tracking code type 2 metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrackingCodeType2($query)
    {
        return $query->where('metadata_type', 'tracking_code_type_2');
    }

    /**
     * Scope a query to filter by project metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProject($query)
    {
        return $query->where('metadata_type', 'project');
    }

    /**
     * Scope a query to filter by file metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFile($query)
    {
        return $query->where('metadata_type', 'file');
    }

    /**
     * Scope a query to filter by expense source metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpenseSource($query)
    {
        return $query->where('metadata_type', 'expense_source');
    }

    /**
     * Scope a query to filter by additional field metadata.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAdditionalField($query)
    {
        return $query->where('metadata_type', 'additional_field');
    }

    /**
     * Check if this metadata is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Check if this metadata is deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === 1;
    }

    /**
     * Check if this metadata is of category type.
     *
     * @return bool
     */
    public function isCategory(): bool
    {
        return $this->metadata_type === 'category';
    }

    /**
     * Check if this metadata is of tracking code type 1.
     *
     * @return bool
     */
    public function isTrackingCodeType1(): bool
    {
        return $this->metadata_type === 'tracking_code_type_1';
    }

    /**
     * Check if this metadata is of tracking code type 2.
     *
     * @return bool
     */
    public function isTrackingCodeType2(): bool
    {
        return $this->metadata_type === 'tracking_code_type_2';
    }

    /**
     * Check if this metadata is of project type.
     *
     * @return bool
     */
    public function isProject(): bool
    {
        return $this->metadata_type === 'project';
    }

    /**
     * Check if this metadata is of file type.
     *
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->metadata_type === 'file';
    }

    /**
     * Check if this metadata is of expense source type.
     *
     * @return bool
     */
    public function isExpenseSource(): bool
    {
        return $this->metadata_type === 'expense_source';
    }

    /**
     * Check if this metadata is of additional field type.
     *
     * @return bool
     */
    public function isAdditionalField(): bool
    {
        return $this->metadata_type === 'additional_field';
    }

    /**
     * Soft delete this metadata by setting deleted flag and timestamp.
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
     * Restore this metadata by removing deleted flag and timestamp.
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
     * Get the details as an array.
     *
     * @return array|null
     */
    public function getDetailsArray(): ?array
    {
        if ($this->details_json === null) {
            return null;
        }

        return is_array($this->details_json) ? $this->details_json : json_decode($this->details_json, true);
    }

    /**
     * Set the details from an array.
     *
     * @param array|null $details
     * @return void
     */
    public function setDetailsArray(?array $details): void
    {
        $this->details_json = $details;
    }
}