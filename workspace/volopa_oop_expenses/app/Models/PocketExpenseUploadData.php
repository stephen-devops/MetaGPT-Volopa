## Code: app/Models/PocketExpenseUploadData.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseUploadData Model
 * 
 * Manages individual data rows from CSV upload files.
 * Stores validated expense data in JSON format and tracks processing status.
 * Links to the parent file upload and any created expense records.
 * Uses custom timestamp fields (create_time, update_time) and soft delete functionality.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $upload_id
 * @property int $line_number
 * @property string $status
 * @property array $expense_data
 * @property array|null $validation_errors
 * @property array|null $processing_errors
 * @property int|null $created_expense_id
 * @property string|null $notes
 * @property Carbon|null $synced_at
 * @property Carbon|null $failed_at
 * @property Carbon $create_time
 * @property Carbon $update_time
 * @property bool $deleted
 * @property Carbon|null $delete_time
 * 
 * @property-read PocketExpenseFileUpload $upload
 * @property-read PocketExpense|null $createdExpense
 */
class PocketExpenseUploadData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_upload_data';

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
        'uuid',
        'upload_id',
        'line_number',
        'status',
        'expense_data',
        'validation_errors',
        'processing_errors',
        'created_expense_id',
        'notes',
        'synced_at',
        'failed_at',
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
        'upload_id' => 'integer',
        'line_number' => 'integer',
        'status' => 'string',
        'expense_data' => 'array',
        'validation_errors' => 'array',
        'processing_errors' => 'array',
        'created_expense_id' => 'integer',
        'notes' => 'string',
        'synced_at' => 'datetime',
        'failed_at' => 'datetime',
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
        'status' => self::STATUS_PENDING,
        'deleted' => false,
    ];

    /**
     * Status constants.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * All available statuses.
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    /**
     * Status groups for easier querying.
     */
    public const STATUS_GROUP_PROCESSED = [
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    public const STATUS_GROUP_SUCCESSFUL = [
        self::STATUS_SYNCED,
    ];

    public const STATUS_GROUP_UNSUCCESSFUL = [
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    /**
     * Expected expense data fields in JSON.
     */
    public const EXPENSE_DATA_FIELDS = [
        'date',
        'merchant_name',
        'merchant_description',
        'expense_type',
        'currency',
        'amount',
        'merchant_address',
        'merchant_country',
        'vat_amount',
        'user_converted_amount',
        'notes',
        'expense_source_id',
        'source_note',
    ];

    /**
     * Validation error types.
     */
    public const VALIDATION_ERROR_REQUIRED_FIELD = 'required_field';
    public const VALIDATION_ERROR_INVALID_FORMAT = 'invalid_format';
    public const VALIDATION_ERROR_INVALID_VALUE = 'invalid_value';
    public const VALIDATION_ERROR_REFERENCE_NOT_FOUND = 'reference_not_found';
    public const VALIDATION_ERROR_CONDITIONAL_REQUIRED = 'conditional_required';

    /**
     * Processing error types.
     */
    public const PROCESSING_ERROR_DATABASE = 'database_error';
    public const PROCESSING_ERROR_FOREIGN_KEY = 'foreign_key_violation';
    public const PROCESSING_ERROR_DUPLICATE = 'duplicate_record';
    public const PROCESSING_ERROR_UNKNOWN = 'unknown_error';

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID when creating
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            
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
     * Get the file upload that owns this data row.
     *
     * @return BelongsTo
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id', 'id');
    }

    /**
     * Get the expense record that was created from this data row.
     *
     * @return BelongsTo
     */
    public function createdExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'created_expense_id', 'id');
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
     * Scope a query to filter by upload.
     *
     * @param Builder $query
     * @param int $uploadId
     * @return Builder
     */
    public function scopeForUpload(Builder $query, int $uploadId): Builder
    {
        return $query->where('upload_id', $uploadId);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by multiple statuses.
     *
     * @param Builder $query
     * @param array $statuses
     * @return Builder
     */
    public function scopeByStatuses(Builder $query, array $statuses): Builder
    {
        return $query->whereIn('status', $statuses);
    }

    /**
     * Scope a query to filter by line number.
     *
     * @param Builder $query
     * @param int $lineNumber
     * @return Builder
     */
    public function scopeByLineNumber(Builder $query, int $lineNumber): Builder
    {
        return $query->where('line_number', $lineNumber);
    }

    /**
     * Scope a query to filter by line number range.
     *
     * @param Builder $query
     * @param int $startLine
     * @param int $endLine
     * @return Builder
     */
    public function scopeByLineRange(Builder $query, int $startLine, int $endLine): Builder
    {
        return $query->whereBetween('line_number', [$startLine, $endLine]);
    }

    /**
     * Scope a query to search by UUID.
     *
     * @param Builder $query
     * @param string $uuid
     * @return Builder
     */
    public function scopeByUuid(Builder $query, string $uuid): Builder
    {
        return $query->where('uuid', $uuid);
    }

    /**
     * Scope a query to filter by created expense.
     *
     * @param Builder $query
     * @param int $expenseId
     * @return Builder
     */
    public function scopeByCreatedExpense(Builder $query, int $expenseId): Builder
    {
        return $query->where('created_expense_id', $expenseId);
    }

    /**
     * Scope a query to only include pending status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include synced status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSynced(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_SYNCED);
    }

    /**
     * Scope a query to only include failed status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include skipped status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSkipped(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_SKIPPED);
    }

    /**
     * Scope a query to only include processed records (any final status).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->byStatuses(self::STATUS_GROUP_PROCESSED);
    }

    /**
     * Scope a query to only include successfully processed records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->byStatuses(self::STATUS_GROUP_SUCCESSFUL);
    }

    /**
     * Scope a query to only include unsuccessfully processed records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnsuccessful(Builder $query): Builder
    {
        return $query->byStatuses(self::STATUS_GROUP_UNSUCCESSFUL);
    }

    /**
     * Scope a query to only include records with validation errors.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithValidationErrors(Builder $query): Builder
    {
        return $query->whereNotNull('validation_errors');
    }

    /**
     * Scope a query to only include records with processing errors.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithProcessingErrors(Builder $query): Builder
    {
        return $query->whereNotNull('processing_errors');
    }

    /**
     * Scope a query to only include records without errors.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithoutErrors(Builder $query): Builder
    {
        return $query->whereNull('validation_errors')
            ->whereNull('processing_errors');
    }

    /**
     * Scope a query to order by line number.
     *
     * @param Builder $query
     * @param string $direction
     * @return Builder
     */
    public function scopeOrderByLine(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('line_number', $direction);
    }

    /**
     * Check if this data row is deleted (soft deleted).
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Check if this data row is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !$this->deleted;
    }

    /**
     * Check if this data row is pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this data row has been synced successfully.
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    /**
     * Check if this data row failed processing.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if this data row was skipped.
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    /**
     * Check if this data row has been processed (any final status).
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return in_array($this->status, self::STATUS_GROUP_PROCESSED);
    }

    /**
     * Check if this data row was processed successfully.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, self::STATUS_GROUP_SUCCESSFUL);
    }

    /**
     * Check if this data row has validation errors.
     *
     * @return bool
     */
    public function hasValidationErrors