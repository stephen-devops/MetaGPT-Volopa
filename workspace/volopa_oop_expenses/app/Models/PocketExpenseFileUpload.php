<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Database\Factories\PocketExpenseFileUploadFactory;

/**
 * PocketExpenseFileUpload Model
 * 
 * Represents CSV file uploads for batch expense processing. Tracks the upload
 * lifecycle from initial file upload through validation to final processing,
 * including error tracking and processing statistics.
 * 
 * Database Table: pocket_expense_file_uploads
 * 
 * Relationships:
 * - BelongsTo User (target user for whom expenses will be created)
 * - BelongsTo User (admin who uploaded the file) via createdBy
 * - BelongsTo Client (multi-tenant context)
 * - HasMany PocketExpenseUploadsData (individual CSV rows)
 * 
 * Status Flow: uploaded -> validating -> validation_failed/processing -> completed/failed
 * 
 * Key Features:
 * - Tracks file metadata and processing statistics
 * - JSON validation errors with line numbers and details
 * - Processing timestamps for upload lifecycle tracking
 * - Soft deletes for audit trail preservation
 * - Multi-tenant scoping via client_id
 * 
 * @property int $id Primary key
 * @property string|null $uuid External UUID reference for tracking
 * @property int $user_id Target user for whom expenses will be created
 * @property int $client_id Client context for multi-tenancy
 * @property int $created_by_user_id Admin user who performed the upload
 * @property string $file_name Original uploaded file name
 * @property string $file_path Storage path to the uploaded CSV file
 * @property int $total_records Total number of data rows in the CSV file
 * @property int $valid_records Number of records that passed validation
 * @property array|null $validation_errors JSON array of validation errors
 * @property string $status Upload processing status
 * @property Carbon|null $uploaded_at Timestamp when file was initially uploaded
 * @property Carbon|null $validated_at Timestamp when validation completed
 * @property Carbon|null $processed_at Timestamp when batch processing completed
 * @property Carbon|null $created_at Laravel timestamp - record creation
 * @property Carbon|null $updated_at Laravel timestamp - record last update
 * @property Carbon|null $deleted_at Laravel soft delete timestamp
 * 
 * @property-read User $user Target user relationship
 * @property-read User $createdBy Admin who uploaded the file relationship
 * @property-read Client $client Client relationship
 * @property-read Collection<PocketExpenseUploadsData> $uploadsData Individual CSV rows collection
 * 
 * @method static PocketExpenseFileUploadFactory factory() Create model factory instance
 */
class PocketExpenseFileUpload extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_file_uploads';

    /**
     * The attributes that are mass assignable.
     * 
     * Note: Uses explicit fillable list for security. Only safe fields
     * that can be mass-assigned during upload creation and updates.
     *
     * @var array<string>
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
     * The attributes that should be hidden for serialization.
     * 
     * Hides internal file paths and sensitive processing details
     * from API responses unless explicitly included.
     *
     * @var array<string>
     */
    protected $hidden = [
        'file_path', // Internal storage path should not be exposed
        'deleted_at', // Soft delete timestamp hidden by default
    ];

    /**
     * The attributes that should be cast.
     * 
     * Handles proper data type casting for database values,
     * JSON decoding for validation errors, and Carbon date instances.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'created_by_user_id' => 'integer',
        'total_records' => 'integer',
        'valid_records' => 'integer',
        'validation_errors' => 'array', // JSON to array casting
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
     * Sets sensible defaults for processing statistics and status workflow.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'total_records' => 0,
        'valid_records' => 0,
        'status' => 'uploaded',
        'validation_errors' => null,
    ];

    /**
     * Upload processing status enumeration.
     * 
     * Defines valid status values for upload lifecycle tracking.
     * Status flow: uploaded -> validating -> validation_failed/processing -> completed/failed
     */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get all valid upload status values.
     *
     * @return array<string>
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_UPLOADED,
            self::STATUS_VALIDATING,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Get the target user for whom expenses will be created.
     * 
     * This is the user who will own the expenses created from the CSV upload.
     * In API terms, this is the 'expense_user_id' field.
     *
     * @return BelongsTo<User, PocketExpenseFileUpload>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the admin user who performed the upload.
     * 
     * This is the authenticated admin user who initiated the CSV upload.
     * In API terms, this is the 'user_id' field (requesting admin).
     *
     * @return BelongsTo<User, PocketExpenseFileUpload>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the client context for multi-tenancy.
     * 
     * All uploads are scoped to a specific client for data isolation.
     *
     * @return BelongsTo<Client, PocketExpenseFileUpload>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get all individual CSV row data records for this upload.
     * 
     * Each row from the CSV file is stored as a separate record
     * for granular processing and error tracking.
     *
     * @return HasMany<PocketExpenseUploadsData>
     */
    public function uploadsData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id');
    }

    /**
     * Scope query to specific client for multi-tenant filtering.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope query to specific target user.
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
     * Scope query to specific upload status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query to uploads created by specific admin user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $createdByUserId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, int $createdByUserId)
    {
        return $query->where('created_by_user_id', $createdByUserId);
    }

    /**
     * Scope query to recently uploaded files (within specified hours).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $hours Number of hours to look back (default: 24)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('uploaded_at', '>=', Carbon::now()->subHours($hours));
    }

    /**
     * Scope query to completed uploads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope query to failed uploads (validation failed or processing failed).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED]);
    }

    /**
     * Scope query to uploads pending processing (uploaded or validating).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePendingProcessing($query)
    {
        return $query->whereIn('status', [self::STATUS_UPLOADED, self::STATUS_VALIDATING]);
    }

    /**
     * Check if the upload is in a completed state (success or failure).
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Check if the upload processing was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the upload has validation errors.
     *
     * @return bool
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    /**
     * Check if the upload is currently being processed.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_VALIDATING,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Get the success rate as a percentage (0-100).
     * 
     * Calculates the percentage of valid records out of total records.
     * Returns 0 if no total records to avoid division by zero.
     *
     * @return float
     */
    public function getSuccessRate(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }

        return round(($this->valid_records / $this->total_records) * 100, 2);
    }

    /**
     * Get the number of failed records.
     *
     * @return int
     */
    public function getFailedRecordsCount(): int
    {
        return $this->total_records - $this->valid_records;
    }

    /**
     * Get validation errors grouped by line number.
     * 
     * Returns validation errors organized by CSV line number for easier
     * error reporting and user feedback.
     *
     * @return array<int, array>
     */
    public function getValidationErrorsByLine(): array
    {
        if (!$this->hasValidationErrors()) {
            return [];
        }

        $errorsByLine = [];
        foreach ($this->validation_errors as $error) {
            $lineNumber = $error['line_number'] ?? 0;
            if (!isset($errorsByLine[$lineNumber])) {
                $errorsByLine[$lineNumber] = [];
            }
            $errorsByLine[$lineNumber][] = $error;
        }

        ksort($errorsByLine); // Sort by line number
        return $errorsByLine;
    }

    /**
     * Get a summary of validation errors by error type.
     * 
     * Groups validation errors by error type for reporting and analytics.
     *
     * @return array<string, int>
     */
    public function getValidationErrorSummary(): array
    {
        if (!$this->hasValidationErrors()) {
            return [];
        }

        $errorSummary = [];
        foreach ($this->validation_errors as $error) {
            $errorType = $error['field'] ?? 'unknown';
            if (!isset($errorSummary[$errorType])) {
                $errorSummary[$errorType] = 0;
            }
            $errorSummary[$errorType]++;
        }

        return $errorSummary;
    }

    /**
     * Get the processing duration in seconds.
     * 
     * Calculates time between upload and completion/failure.
     * Returns null if processing is not yet complete.
     *
     * @return int|null
     */
    public function getProcessingDuration(): ?int
    {
        if (!$this->uploaded_at) {
            return null;
        }

        $endTime = $this->processed_at ?? $this->validated_at ?? $this->updated_at;
        if (!$endTime) {
            return null;
        }

        return $this->uploaded_at->diffInSeconds($endTime);
    }

    /**
     * Mark the upload as validated with results.
     * 
     * Updates the upload record with validation results and sets
     * the appropriate status based on validation outcome.
     *
     * @param int $totalRecords
     * @param int $validRecords
     * @param array $validationErrors
     * @return bool
     */
    public function markAsValidated(int $totalRecords, int $validRecords, array $validationErrors = []): bool
    {
        $this->total_records = $totalRecords;
        $this->valid_records = $validRecords;
        $this->validation_errors = empty($validationErrors) ? null : $validationErrors;
        $this->validated_at = Carbon::now();
        
        // Set status based on validation outcome
        $this->status = empty($validationErrors) ? self::STATUS_PROCESSING : self::STATUS_VALIDATION_FAILED;
        
        return $this->save();
    }

    /**
     * Mark the upload as processing started.
     *
     * @return bool
     */
    public function markAsProcessing(): bool
    {
        $this->status = self::STATUS_PROCESSING;
        return $this->save();
    }

    /**
     * Mark the upload as successfully completed.
     *
     * @return bool
     */
    public function markAsCompleted(): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->processed_at = Carbon::now();
        return $this->save();
    }

    /**
     * Mark the upload as failed with optional error information.
     *
     * @param array $processingErrors Additional processing errors to record
     * @return bool
     */
    public function markAsFailed(array $processingErrors = []): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->processed_at = Carbon::now();
        
        // Merge processing errors with existing validation errors
        if (!empty($processingErrors)) {
            $existingErrors = $this->validation_errors ?? [];
            $this->validation_errors = array_merge($existingErrors, $processingErrors);
        }
        
        return $this->save();
    }

    /**
     * Get the file extension from the uploaded file name.
     *
     * @return string|null
     */
    public function getFileExtension(): ?string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Get the file size in bytes if file exists.
     *
     * @return int|null
     */
    public function getFileSize(): ?int
    {
        if (!$this->file_path || !file_exists(storage_path('app/' . $this->file_path))) {
            return null;
        }

        return filesize(storage_path('app/' . $this->file_path));
    }

    /**
     * Get human-readable file size.
     *
     * @return string|null
     */
    public function getFormattedFileSize(): ?string
    {
        $bytes = $this->getFileSize();
        if ($bytes === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Check if the uploaded file still exists in storage.
     *
     * @return bool
     */
    public function fileExists(): bool
    {
        return $this->file_path && file_exists(storage_path('app/' . $this->file_path));
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return PocketExpenseFileUploadFactory
     */
    protected static function newFactory(): PocketExpenseFileUploadFactory
    {
        return PocketExpenseFileUploadFactory::new();
    }

    /**
     * Boot the model.
     * 
     * Sets up model event listeners for automatic timestamp management
     * and UUID generation on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set uploaded_at timestamp on creation if not already set
        static::creating(function ($upload) {
            if (!$upload->uploaded_at) {
                $upload->uploaded_at = Carbon::now();
            }
        });

        // Automatically generate UUID on creation if not already set
        static::creating(function ($upload) {
            if (!$upload->uuid) {
                $upload->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Convert the model instance to an array.
     * 
     * Customizes the array representation to include computed properties
     * and properly formatted data for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // Add computed properties for API responses
        $array['success_rate'] = $this->getSuccessRate();
        $array['failed_records_count'] = $this->getFailedRecordsCount();
        $array['processing_duration'] = $this->getProcessingDuration();
        $array['file_size'] = $this->getFormattedFileSize();
        $array['is_completed'] = $this->isCompleted();
        $array['is_successful'] = $this->isSuccessful();
        $array['has_validation_errors'] = $this->hasValidationErrors();

        return $array;
    }
}