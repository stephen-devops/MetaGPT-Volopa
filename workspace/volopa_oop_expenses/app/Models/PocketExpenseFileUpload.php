## Code: app/Models/PocketExpenseFileUpload.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseFileUpload Model
 * 
 * Manages file upload tracking for pocket expense CSV imports.
 * Tracks upload status, validation results, and processing progress.
 * Includes relationships to users, clients, and upload data records.
 * Uses custom timestamp fields (create_time, update_time) and soft delete functionality.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property int $target_user_id
 * @property string $original_filename
 * @property string $stored_filename
 * @property string $file_path
 * @property string $mime_type
 * @property int $file_size
 * @property string $status
 * @property int $total_records
 * @property int $valid_records
 * @property int $processed_records
 * @property int $failed_records
 * @property array|null $validation_errors
 * @property array|null $processing_errors
 * @property string|null $notes
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon $create_time
 * @property Carbon $update_time
 * @property bool $deleted
 * @property Carbon|null $delete_time
 * 
 * @property-read User $user
 * @property-read Client $client
 * @property-read User $targetUser
 * @property-read Collection|PocketExpenseUploadData[] $uploadData
 */
class PocketExpenseFileUpload extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_file_uploads';

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
        'user_id',
        'client_id',
        'target_user_id',
        'original_filename',
        'stored_filename',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'total_records',
        'valid_records',
        'processed_records',
        'failed_records',
        'validation_errors',
        'processing_errors',
        'notes',
        'started_at',
        'completed_at',
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
        'user_id' => 'integer',
        'client_id' => 'integer',
        'target_user_id' => 'integer',
        'original_filename' => 'string',
        'stored_filename' => 'string',
        'file_path' => 'string',
        'mime_type' => 'string',
        'file_size' => 'integer',
        'status' => 'string',
        'total_records' => 'integer',
        'valid_records' => 'integer',
        'processed_records' => 'integer',
        'failed_records' => 'integer',
        'validation_errors' => 'array',
        'processing_errors' => 'array',
        'notes' => 'string',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
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
        'mime_type' => 'text/csv',
        'file_size' => 0,
        'status' => self::STATUS_UPLOADED,
        'total_records' => 0,
        'valid_records' => 0,
        'processed_records' => 0,
        'failed_records' => 0,
        'deleted' => false,
    ];

    /**
     * Status constants.
     */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_VALIDATION_PASSED = 'validation_passed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SYNC_FAILED = 'sync_failed';

    /**
     * All available statuses.
     */
    public const STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_VALIDATION_PASSED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SYNC_FAILED,
    ];

    /**
     * Status groups for easier querying.
     */
    public const STATUS_GROUP_PENDING = [
        self::STATUS_UPLOADED,
        self::STATUS_VALIDATION_PASSED,
        self::STATUS_PROCESSING,
    ];

    public const STATUS_GROUP_FAILED = [
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_FAILED,
        self::STATUS_SYNC_FAILED,
    ];

    public const STATUS_GROUP_SUCCESS = [
        self::STATUS_COMPLETED,
    ];

    /**
     * MIME type constants.
     */
    public const MIME_TYPE_CSV = 'text/csv';
    public const MIME_TYPE_PLAIN = 'text/plain';
    public const MIME_TYPE_OCTET_STREAM = 'application/octet-stream';

    /**
     * Allowed MIME types for CSV uploads.
     */
    public const ALLOWED_MIME_TYPES = [
        self::MIME_TYPE_CSV,
        self::MIME_TYPE_PLAIN,
        self::MIME_TYPE_OCTET_STREAM,
    ];

    /**
     * Maximum file size (in bytes) - 10MB.
     */
    public const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum number of records in CSV file.
     */
    public const MAX_CSV_RECORDS = 200;

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
     * Get the user who uploaded this file.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with this upload.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the target user for whom expenses are being uploaded.
     *
     * @return BelongsTo
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Get all upload data records associated with this upload.
     *
     * @return HasMany
     */
    public function uploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadData::class, 'upload_id', 'id');
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
     * Scope a query to filter by client.
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by target user.
     *
     * @param Builder $query
     * @param int $targetUserId
     * @return Builder
     */
    public function scopeForTargetUser(Builder $query, int $targetUserId): Builder
    {
        return $query->where('target_user_id', $targetUserId);
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
     * Scope a query to search by original filename.
     *
     * @param Builder $query
     * @param string $filename
     * @return Builder
     */
    public function scopeByOriginalFilename(Builder $query, string $filename): Builder
    {
        return $query->where('original_filename', 'LIKE', '%' . $filename . '%');
    }

    /**
     * Scope a query to only include uploaded status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUploaded(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_UPLOADED);
    }

    /**
     * Scope a query to only include validation failed status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeValidationFailed(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope a query to only include validation passed status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeValidationPassed(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_VALIDATION_PASSED);
    }

    /**
     * Scope a query to only include processing status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include completed status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_COMPLETED);
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
     * Scope a query to only include sync failed status.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSyncFailed(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_SYNC_FAILED);
    }

    /**
     * Scope a query to only include pending uploads.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->byStatuses(self::STATUS_GROUP_PENDING);
    }

    /**
     * Scope a query to only include successful uploads.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->byStatuses(self::STATUS_GROUP_SUCCESS);
    }

    /**
     * Scope a query to only include failed uploads (any type of failure).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFailedAny(Builder $query): Builder
    {
        return $query->byStatuses(self::STATUS_GROUP_FAILED);
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param Builder $query
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Builder
     */
    public function scopeByDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('create_time', [$startDate, $endDate]);
    }

    /**
     * Check if this upload is deleted (soft deleted).
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        