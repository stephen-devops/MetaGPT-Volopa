<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseFileUpload Model
 * 
 * Manages CSV file uploads and their processing status in the batch expense upload system.
 * This table manages the upload lifecycle from initial file upload through validation,
 * processing, and completion. Includes soft delete pattern and comprehensive status tracking
 * for batch operations. Uses Laravel's built-in soft delete pattern with deleted_at timestamp.
 * 
 * @property int $id Primary key for file upload tracking
 * @property string $uuid Unique identifier for external references
 * @property int $user_id The user who initiated the upload
 * @property int $client_id The client context for this upload
 * @property int $created_by_user_id User who created this upload record (same as user_id typically)
 * @property string $file_name Original filename of the uploaded CSV
 * @property string $file_path Storage path to the uploaded file
 * @property int $total_records Total number of records found in the CSV file
 * @property int $valid_records Number of records that passed validation
 * @property array|null $validation_errors JSON array of validation errors encountered during processing
 * @property string $status Current processing status of the upload
 * @property Carbon $uploaded_at Timestamp when the file was uploaded
 * @property Carbon|null $validated_at Timestamp when validation completed
 * @property Carbon|null $processed_at Timestamp when processing completed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Client $client
 * @property-read User $createdBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PocketExpenseUploadsData> $uploadsData
 */
class PocketExpenseFileUpload extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_file_uploads';

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
        'uuid',
        'user_id',
        'client_id',
        'created_by_user_id',
        'file_name',
        'file_path',
        'total_records',
        'valid_records',
        'validation_errors',
        'status',
        'uploaded_at',
        'validated_at',
        'processed_at',
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
        'created_by_user_id' => 'integer',
        'file_name' => 'string',
        'file_path' => 'string',
        'total_records' => 'integer',
        'valid_records' => 'integer',
        'validation_errors' => 'array',
        'status' => 'string',
        'uploaded_at' => 'datetime',
        'validated_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'total_records' => 0,
        'valid_records' => 0,
        'status' => 'uploaded',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Valid status values for upload processing workflow.
     *
     * @var array<int, string>
     */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * All valid status values.
     *
     * @var array<int, string>
     */
    public const VALID_STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_VALIDATING,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Status transitions allowed in the workflow.
     *
     * @var array<string, array<int, string>>
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_UPLOADED => [self::STATUS_VALIDATING, self::STATUS_FAILED],
        self::STATUS_VALIDATING => [self::STATUS_VALIDATION_FAILED, self::STATUS_PROCESSING, self::STATUS_FAILED],
        self::STATUS_VALIDATION_FAILED => [self::STATUS_VALIDATING, self::STATUS_FAILED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [self::STATUS_VALIDATING],
    ];

    /**
     * Maximum file size in KB (10MB).
     *
     * @var int
     */
    public const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum number of records per CSV file.
     *
     * @var int
     */
    public const MAX_RECORDS_PER_FILE = 200;

    /**
     * Maximum length for file name.
     *
     * @var int
     */
    public const MAX_FILE_NAME_LENGTH = 255;

    /**
     * Maximum length for file path.
     *
     * @var int
     */
    public const MAX_FILE_PATH_LENGTH = 500;

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID when creating new records
        static::creating(function (PocketExpenseFileUpload $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            
            if (empty($model->uploaded_at)) {
                $model->uploaded_at = now();
            }

            // Set created_by_user_id if not already set
            if (empty($model->created_by_user_id) && auth()->check()) {
                $model->created_by_user_id = auth()->user()->id;
            }

            // Set user_id if not already set and user is authenticated
            if (empty($model->user_id) && auth()->check()) {
                $model->user_id = auth()->user()->id;
            }

            // Set client_id if not already set and user is authenticated
            if (empty($model->client_id) && auth()->check() && auth()->user()->client_id) {
                $model->client_id = auth()->user()->client_id;
            }
        });

        // Automatically scope all queries by client_id for multi-tenancy
        static::addGlobalScope('client_scope', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });

        // Validate status transitions
        static::updating(function (PocketExpenseFileUpload $model) {
            if ($model->isDirty('status')) {
                $originalStatus = $model->getOriginal('status');
                $newStatus = $model->status;
                
                if (!$model->isValidStatusTransition($originalStatus, $newStatus)) {
                    throw new \InvalidArgumentException(
                        "Invalid status transition from '{$originalStatus}' to '{$newStatus}'"
                    );
                }
            }
        });

        // Validate status values
        static::saving(function (PocketExpenseFileUpload $model) {
            if (!self::isValidStatus($model->status)) {
                throw new \InvalidArgumentException(
                    "Invalid status value: {$model->status}. Must be one of: " . 
                    implode(', ', self::VALID_STATUSES)
                );
            }
        });
    }

    /**
     * Get the user who initiated this upload.
     *
     * @return BelongsTo<User, PocketExpenseFileUpload>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this upload.
     *
     * @return BelongsTo<Client, PocketExpenseFileUpload>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who created this upload record.
     *
     * @return BelongsTo<User, PocketExpenseFileUpload>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the uploads data records associated with this upload.
     *
     * @return HasMany<PocketExpenseUploadsData>
     */
    public function uploadsData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id', 'id');
    }

    /**
     * Scope a query to only include uploaded files.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeUploaded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UPLOADED);
    }

    /**
     * Scope a query to only include validating files.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeValidating(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATING);
    }

    /**
     * Scope a query to only include validation failed files.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeValidationFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope a query to only include processing files.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include completed files.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed files.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to filter by specific user.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @param int $userId
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by specific client.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @param int $clientId
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @param string $status
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by upload date range.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeUploadedBetween(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('uploaded_at', [$startDate, $endDate]);
    }

    /**
     * Scope a query to get uploads created by specific user.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @param int $userId
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeCreatedBy(Builder $query, int $userId): Builder
    {
        return $query->where('created_by_user_id', $userId);
    }

    /**
     * Scope a query to get uploads in progress (validating or processing).
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_VALIDATING, self::STATUS_PROCESSING]);
    }

    /**
     * Scope a query to get uploads with errors (validation failed or failed).
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED]);
    }

    /**
     * Scope a query to get successfully processed uploads.
     *
     * @param Builder<PocketExpenseFileUpload> $query
     * @return Builder<PocketExpenseFileUpload>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Check if the upload is in uploaded status.
     *
     * @return bool
     */
    public function isUploaded(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    /**
     * Check if the upload is currently validating.
     *
     * @return bool
     */
    public function isValidating(): bool
    {
        return $this->status === self::STATUS_VALIDATING;
    }

    /**
     * Check if the upload failed validation.
     *
     * @return bool
     */
    public function isValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the upload is currently processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the upload is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the upload failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the upload is in progress.
     *
     * @return bool
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_VALIDATING, self::STATUS_PROCESSING]);
    }

    /**
     * Check if the upload has errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return in_array($this->status, [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED]);
    }

    /**
     * Check if the upload was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the upload can be reprocessed.
     *
     * @return bool
     */
    public function canBeReprocessed(): bool
    {
        return in_array($this->status, [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED]);
    }

    /**
     * Check if the upload can be cancelled.
     *
     * @return bool
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_VALIDATING]);
    }

    /**
     * Mark the upload as validating.
     *
     * @return bool
     */
    public function markAsValidating(): bool
    {
        if (!$this->isUploaded()) {
            return false;
        }

        $this->status = self::STATUS_VALIDATING;
        return $this->save();
    }

    /**
     * Mark the upload as validation failed.
     *
     * @param array|null $validationErrors
     * @return bool
     */
    public function markAsValidationFailed(?array $validationErrors = null): bool
    {
        if (!$this->isValidating()) {
            return false;
        }

        $this->status = self::STATUS_VALIDATION_FAILED;
        $this->validation_errors = $validationErrors;
        $this->validated_at = now();
        
        return $this->save();
    }

    /**
     * Mark the upload as processing.
     *
     * @param int $totalRecords
     * @param int $validRecords
     * @return bool
     */
    public function markAsProcessing(int $totalRecords, int $validRecords): bool
    {
        if (!$this->isValidating()) {
            return false;
        }

        $this->status = self::STATUS_PROCESSING;
        $this->total_records = $totalRecords;
        $this->valid_records = $validRecords;
        $this->validated_at = now();
        
        return $this->save();
    }

    /**
     * Mark the upload as completed.
     *
     * @return bool
     */
    public function markAsCompleted(): bool
    {
        if (!$this->isProcessing()) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->processed_at = now();
        
        return $this->save();
    }

    /**
     * Mark the upload as failed.
     *
     * @param array|null $errors
     * @return bool
     */
    public function markAsFailed(?array $errors = null): bool
    {
        $this->status = self::STATUS_FAILED;
        
        if ($errors !== null) {
            $existingErrors = $this->validation_errors ?? [];
            $this->validation_errors = array_merge($existingErrors, $errors);
        }
        
        return $this->save();
    }

    /**
     * Reset the upload to uploaded status for reprocessing.
     *
     * @return bool
     */
    public function resetForReprocessing(): bool
    {
        if (!$this->canBeReprocessed()) {
            return false;
        }

        $this->status = self::STATUS_UPLOADED;
        $this->validation_errors = null;
        $this->validated_at = null;
        $this->processed_at = null;
        $this->total_records = 0;
        $this->valid_records = 0;
        
        return $this->save();
    }

    /**
     * Get the validation success rate as percentage.
     *
     * @return float
     */
    public function getValidationSuccessRate(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }
        
        return ($this->valid_records / $this->total_records) * 100;
    }

    /**
     * Get the number of invalid records.
     *
     * @return int
     */
    public function getInvalidRecordsCount(): int
    {
        return $this->total_records - $this->valid_records;
    }

    /**
     * Get the file size in bytes (if available in details).
     *
     * @return int|null
     */
    public function getFileSizeBytes(): ?int
    {
        // This would typically be stored during upload or retrieved from file system
        if (file_exists($this->file_path)) {
            return filesize($this->file_path);
        }
        
        return null;
    }

    /**
     * Get the file size in KB.
     *
     * @return float|null
     */
    public function getFileSizeKB(): ?float
    {
        $sizeBytes = $this->getFileSizeBytes();
        
        if ($sizeBytes === null) {
            return null;
        }
        
        return $sizeBytes / 1024;
    }

    /**
     * Get the formatted file size.
     *
     * @return string
     */
    public function getFormattedFileSize(): string
    {
        $sizeBytes = $this->getFileSizeBytes();
        
        if ($sizeBytes === null) {
            return 'Unknown';
        }
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $sizeBytes;
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return number_format($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Get the file extension from file name.
     *
     * @return string|null
     */
    public function getFileExtension(): ?string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Get the base file name without extension.
     *
     * @return string
     */
    public function getBaseFileName(): string
    {
        return pathinfo($this->file_name, PATHINFO_FILENAME);
    }

    /**
     * Check if the file is a CSV file.
     *
     * @return bool
     */
    public function isCsvFile(): bool
    {
        $extension = strtolower($this->getFileExtension() ?? '');
        return in_array($extension, ['csv', 'txt']);
    }

    /**
     * Get the processing duration in seconds.
     *
     * @return int|null
     */
    public function getProcessingDurationSeconds(): ?int
    {
        if ($this->validated_at === null || $this->processed_at === null) {
            return null;
        }
        
        return $this->validated_at->diffInSeconds($this->processed_at);
    }

    /**
     * Get the total processing time from upload to completion.
     *
     * @return int|null
     */
    public function getTotalProcessingTimeSeconds(): ?int
    {
        if ($this->processed_at === null) {
            return null;
        }
        
        return $this->uploaded_at->diffInSeconds($this->processed_at);
    }

    /**
     * Get a human-readable description of this upload.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $userName = $this->user->name ?? 'Unknown User';
        $statusLabel = ucfirst(str_replace('_', ' ', $this->status));
        $recordsInfo = $this->total_records > 0 ? " ({$this->valid_records}/{$this->total_records} records)" : '';
        
        return "{$userName} - {$this->file_name} - {$statusLabel}{$recordsInfo}";
    }

    /**
     * Get formatted processing statistics.
     *
     * @return array<string, mixed>
     */
    public function getProcessingStats(): array
    {
        return [
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'invalid_records' => $this->getInvalidRecordsCount(),
            'success_rate' => $this->getValidationSuccessRate(),
            'success_rate_formatted' => number_format($this->getValidationSuccessRate(), 2) . '%',
            'file_size' => $this->getFormattedFileSize(),
            'processing_duration' => $this->getProcessingDurationSeconds(),
            'total_processing_time' => $this->getTotalProcessingTimeSeconds(),
            'has_validation_errors' => !empty($this->validation_errors),
            'validation_error_count' => count($this->validation_errors ?? []),
        ];
    }

    /**
     * Get validation errors grouped by type.
     *
     * @return array<string, array<int, mixed>>
     */
    public function getGroupedValidationErrors(): array
    {
        $errors = $this->validation_errors ?? [];
        $grouped = [];
        
        foreach ($errors as $error) {
            $type = $error['type'] ?? 'general';
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $error;
        }
        
        return $grouped;
    }

    /**
     * Add a validation error.
     *
     * @param string $type
     * @param string $message
     * @param int|null $lineNumber
     * @param array|null $context
     * @return void
     */
    public function addValidationError(string $type, string $message, ?int $lineNumber = null, ?array $context = null): void
    {
        $errors = $this->validation_errors ?? [];
        
        $error = [
            'type' => $type,
            'message' => $message,
            'timestamp' => now()->toISOString(),
        ];
        
        if ($lineNumber !== null) {
            $error['line_number'] = $lineNumber;
        }
        
        if ($context !== null) {
            $error['context'] = $context;
        }
        
        $errors[] = $error;
        $this->validation_errors = $errors;
    }

    /**
     * Clear all validation errors.
     *
     * @return void
     */
    public function clearValidationErrors(): void
    {
        $this->validation_errors = null;
    }

    /**
     * Check if the upload belongs to a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Check if the upload belongs to a specific client.
     *
     * @param int $clientId
     * @return bool
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Check if the upload was created by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function wasCreatedBy(int $userId): bool
    {
        return $this->created_by_user_id === $userId;
    }

    /**
     * Find an upload by UUID.
     *
     * @param string $uuid
     * @return PocketExpenseFileUpload|null
     */
    public static function findByUuid(string $uuid): ?PocketExpenseFileUpload
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * Get uploads for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseFileUpload>
     */
    public static function getForUserAndClient(int $userId, int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUser($userId)->forClient($clientId)->orderBy('uploaded_at', 'desc')->get();
    }

    /**
     * Get uploads by status for a client.
     *
     * @param int $clientId
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseFileUpload>
     */
    public static function getByStatusForClient(int $clientId, string $status): \Illuminate\Database\Eloquent\Collection
    {
        return static::forClient($clientId)->byStatus($status)->orderBy('uploaded_at', 'desc')->get();
    }

    /**
     * Get recent uploads for a user.
     *
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseFileUpload>
     */
    public static function getRecentForUser(int $userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUser($userId)
                    ->orderBy('uploaded_at', 'desc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Get uploads summary for a client.
     *
     * @param int $clientId
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return array<string, mixed>
     */
    public static function getSummaryForClient(int $clientId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = static::forClient($clientId);
        
        if ($startDate && $endDate) {
            $query->uploadedBetween($startDate, $endDate);
        }
        
        $uploads = $query->get();
        
        return [
            'total_uploads' => $uploads->count(),
            'successful_uploads' => $uploads->where('status', self::STATUS_COMPLETED)->count(),
            'failed_uploads' => $uploads->whereIn('status', [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED])->count(),
            'in_progress_uploads' => $uploads->whereIn('status', [self::STATUS_VALIDATING, self::STATUS_PROCESSING])->count(),
            'total_records_processed' => $uploads->sum('total_records'),
            'total_valid_records' => $uploads->sum('valid_records'),
            'average_success_rate' => $uploads->where('total_records', '>', 0)->avg(function ($upload) {
                return $upload->getValidationSuccessRate();
            }) ?: 0,
        ];
    }

    /**
     * Get uploads grouped by status for a client.
     *
     * @param int $clientId
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, PocketExpenseFileUpload>>
     */
    public static function getGroupedByStatusForClient(int $clientId): array
    {
        $uploads = static::forClient($clientId)->orderBy('uploaded_at', 'desc')->get();

        return [
            'uploaded' => $uploads->filter(fn($upload) => $upload->isUploaded()),
            'validating' => $uploads->filter(fn($upload) => $upload->isValidating()),
            'validation_failed' => $uploads->filter(fn($upload) => $upload->isValidationFailed()),
            'processing' => $uploads->filter(fn($upload) => $upload->isProcessing()),
            'completed' => $uploads->filter(fn($upload) => $upload->isCompleted()),
            'failed' => $uploads->filter(fn($upload) => $upload->isFailed()),
        ];
    }

    /**
     * Get uploads that need attention (failed or with validation errors).
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseFileUpload>
     */
    public static function getNeedingAttentionForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forClient($clientId)->withErrors()->orderBy('uploaded_at', 'desc')->get();
    }

    /**
     * Validate if a status value is valid.
     *
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES, true);
    }

    /**
     * Check if a status transition is valid.
     *
     * @param string $fromStatus
     * @param string $toStatus
     * @return bool
     */
    public function isValidStatusTransition(string $fromStatus, string $toStatus): bool
    {
        if (!self::isValidStatus($fromStatus) || !self::isValidStatus($toStatus)) {
            return false;
        }
        
        if ($fromStatus === $toStatus) {
            return true; // Same status is always valid
        }
        
        return in_array($toStatus, self::STATUS_TRANSITIONS[$fromStatus] ?? [], true);
    }

    /**
     * Get available status transitions from current status.
     *
     * @return array<int, string>
     */
    public function getAvailableStatusTransitions(): array
    {
        return self::STATUS_TRANSITIONS[$this->status] ?? [];
    }

    /**
     * Get status options as a key-value array for dropdowns.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return array_combine(
            self::VALID_STATUSES,
            array_map(fn($status) => ucfirst(str_replace('_', ' ', $status)), self::VALID_STATUSES)
        );
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
        $array['is_uploaded'] = $this->isUploaded();
        $array['is_validating'] = $this->isValidating();
        $array['is_validation_failed'] = $this->isValidationFailed();
        $array['is_processing'] = $this->isProcessing();
        $array['is_completed'] = $this->isCompleted();
        $array['is_failed'] = $this->isFailed();
        $array['is_in_progress'] = $this->isInProgress();
        $array['has_errors'] = $this->hasErrors();
        $array['is_successful'] = $this->isSuccessful();
        $array['can_be_reprocessed'] = $this->canBeReprocessed();
        $array['can_be_cancelled'] = $this->canBeCancelled();
        $array['validation_success_rate'] = $this->getValidationSuccessRate();
        $array['invalid_records_count'] = $this->getInvalidRecordsCount();
        $array['formatted_file_size'] = $this->getFormattedFileSize();
        $array['file_extension'] = $this->getFileExtension();
        $array['base_file_name'] = $this->getBaseFileName();
        $array['is_csv_file'] = $this->isCsvFile();
        $array['processing_duration_seconds'] = $this->getProcessingDurationSeconds();
        $array['total_processing_time_seconds'] = $this->getTotalProcessingTimeSeconds();
        $array['description'] = $this->getDescription();
        $array['processing_stats'] = $this->getProcessingStats();
        $array['grouped_validation_errors'] = $this->getGroupedValidationErrors();
        $array['available_status_transitions'] = $this->getAvailableStatusTransitions();
        
        return $array;
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     * @param string|null $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
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