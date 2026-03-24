<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
     * The primary key for the model.
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
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = 'updated_at';

    /**
     * The name of the "deleted at" column.
     *
     * @var string
     */
    const DELETED_AT = 'deleted_at';

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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'file_path', // Hide internal storage path for security
    ];

    /**
     * The attributes that should be cast.
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
        'validation_errors' => 'array',
        'uploaded_at' => 'datetime',
        'validated_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'uploaded_at',
        'validated_at',
        'processed_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The model's default values for attributes.
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
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (PocketExpenseFileUpload $upload) {
            if (empty($upload->uuid)) {
                $upload->uuid = Str::uuid()->toString();
            }
            
            if (empty($upload->uploaded_at)) {
                $upload->uploaded_at = now();
            }
        });
    }

    /**
     * Upload status constants.
     */
    const STATUS_UPLOADED = 'uploaded';
    const STATUS_VALIDATION_FAILED = 'validation_failed';
    const STATUS_VALIDATION_PASSED = 'validation_passed';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_SYNC_FAILED = 'sync_failed';

    /**
     * Get all valid upload statuses.
     *
     * @return array<string>
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_UPLOADED,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_VALIDATION_PASSED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SYNC_FAILED,
        ];
    }

    /**
     * Get the target user for whom expenses are being uploaded.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client context for multi-tenancy.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the admin user who performed the upload.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    /**
     * Get the upload data rows for this upload.
     *
     * @return HasMany
     */
    public function uploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id', 'id');
    }

    /**
     * Scope a query to only include uploads for a specific client.
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
     * Scope a query to only include uploads for a specific user.
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
     * Scope a query to only include uploads created by a specific admin user.
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
     * Scope a query to only include uploads with a specific status.
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
     * Scope a query to only include uploads that have completed processing.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SYNC_FAILED,
        ]);
    }

    /**
     * Scope a query to only include uploads that are still processing.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', [
            self::STATUS_UPLOADED,
            self::STATUS_VALIDATION_PASSED,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Scope a query to only include uploads that failed validation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidationFailed($query)
    {
        return $query->where('status', self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope a query to only include uploads from a specific date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Carbon\Carbon|string $startDate
     * @param \Carbon\Carbon|string|null $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUploadedBetween($query, $startDate, $endDate = null)
    {
        $query->where('uploaded_at', '>=', $startDate);
        
        if ($endDate) {
            $query->where('uploaded_at', '<=', $endDate);
        }
        
        return $query;
    }

    /**
     * Check if the upload has completed successfully.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the upload has failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_SYNC_FAILED,
            self::STATUS_VALIDATION_FAILED,
        ]);
    }

    /**
     * Check if the upload is still processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADED,
            self::STATUS_VALIDATION_PASSED,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Check if validation has passed.
     *
     * @return bool
     */
    public function hasPassedValidation(): bool
    {
        return in_array($this->status, [
            self::STATUS_VALIDATION_PASSED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
        ]);
    }

    /**
     * Check if validation has failed.
     *
     * @return bool
     */
    public function hasFailedValidation(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Get the success rate of the upload (valid records / total records).
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
     * Get the error count (total records - valid records).
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        return max(0, $this->total_records - $this->valid_records);
    }

    /**
     * Check if the upload has validation errors.
     *
     * @return bool
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors) && is_array($this->validation_errors) && count($this->validation_errors) > 0;
    }

    /**
     * Get validation errors as a collection for easier manipulation.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getValidationErrorsCollection()
    {
        return collect($this->validation_errors ?? []);
    }

    /**
     * Update the upload status and set appropriate timestamps.
     *
     * @param string $status
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        if (!in_array($status, self::getValidStatuses())) {
            return false;
        }

        $this->status = $status;

        // Set appropriate timestamps based on status
        switch ($status) {
            case self::STATUS_VALIDATION_PASSED:
            case self::STATUS_VALIDATION_FAILED:
                if (!$this->validated_at) {
                    $this->validated_at = now();
                }
                break;
                
            case self::STATUS_COMPLETED:
            case self::STATUS_FAILED:
            case self::STATUS_SYNC_FAILED:
                if (!$this->processed_at) {
                    $this->processed_at = now();
                }
                break;
        }

        return $this->save();
    }

    /**
     * Mark the upload as validation failed with error details.
     *
     * @param array $validationErrors
     * @param int $totalRecords
     * @param int $validRecords
     * @return bool
     */
    public function markValidationFailed(array $validationErrors, int $totalRecords = 0, int $validRecords = 0): bool
    {
        $this->status = self::STATUS_VALIDATION_FAILED;
        $this->validation_errors = $validationErrors;
        $this->total_records = $totalRecords;
        $this->valid_records = $validRecords;
        $this->validated_at = now();

        return $this->save();
    }

    /**
     * Mark the upload as validation passed.
     *
     * @param int $totalRecords
     * @param int $validRecords
     * @return bool
     */
    public function markValidationPassed(int $totalRecords, int $validRecords): bool
    {
        $this->status = self::STATUS_VALIDATION_PASSED;
        $this->total_records = $totalRecords;
        $this->valid_records = $validRecords;
        $this->validation_errors = null;
        $this->validated_at = now();

        return $this->save();
    }

    /**
     * Mark the upload as completed successfully.
     *
     * @return bool
     */
    public function markCompleted(): bool
    {
        return $this->updateStatus(self::STATUS_COMPLETED);
    }

    /**
     * Mark the upload as failed during processing.
     *
     * @return bool
     */
    public function markFailed(): bool
    {
        return $this->updateStatus(self::STATUS_FAILED);
    }

    /**
     * Mark the upload as sync failed (expenses created but some sync issues).
     *
     * @return bool
     */
    public function markSyncFailed(): bool
    {
        return $this->updateStatus(self::STATUS_SYNC_FAILED);
    }

    /**
     * Get a human-readable status description.
     *
     * @return string
     */
    public function getStatusDescription(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADED => 'File uploaded, awaiting validation',
            self::STATUS_VALIDATION_FAILED => 'Validation failed - see error details',
            self::STATUS_VALIDATION_PASSED => 'Validation passed, queued for processing',
            self::STATUS_PROCESSING => 'Processing expenses in background',
            self::STATUS_COMPLETED => 'Processing completed successfully',
            self::STATUS_FAILED => 'Processing failed',
            self::STATUS_SYNC_FAILED => 'Expenses created but sync issues occurred',
            default => 'Unknown status',
        };
    }

    /**
     * Get the file extension from the file name.
     *
     * @return string|null
     */
    public function getFileExtension(): ?string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Get the file name without extension.
     *
     * @return string
     */
    public function getFileBaseName(): string
    {
        return pathinfo($this->file_name, PATHINFO_FILENAME);
    }

    /**
     * Check if the file is a CSV file based on its name.
     *
     * @return bool
     */
    public function isCsvFile(): bool
    {
        $extension = strtolower($this->getFileExtension() ?? '');
        return in_array($extension, ['csv', 'txt']);
    }

    /**
     * Get elapsed time since upload in human readable format.
     *
     * @return string
     */
    public function getElapsedTime(): string
    {
        if (!$this->uploaded_at) {
            return 'Unknown';
        }

        return $this->uploaded_at->diffForHumans();
    }

    /**
     * Get processing duration if available.
     *
     * @return string|null
     */
    public function getProcessingDuration(): ?string
    {
        if (!$this->uploaded_at || !$this->processed_at) {
            return null;
        }

        return $this->uploaded_at->diffForHumans($this->processed_at, true);
    }

    /**
     * Get validation duration if available.
     *
     * @return string|null
     */
    public function getValidationDuration(): ?string
    {
        if (!$this->uploaded_at || !$this->validated_at) {
            return null;
        }

        return $this->uploaded_at->diffForHumans($this->validated_at, true);
    }

    /**
     * Convert the model to an array with computed attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes
        $array['success_rate'] = $this->getSuccessRate();
        $array['error_count'] = $this->getErrorCount();
        $array['status_description'] = $this->getStatusDescription();
        $array['file_extension'] = $this->getFileExtension();
        $array['elapsed_time'] = $this->getElapsedTime();
        $array['processing_duration'] = $this->getProcessingDuration();
        $array['validation_duration'] = $this->getValidationDuration();
        $array['is_csv_file'] = $this->isCsvFile();
        $array['is_completed'] = $this->isCompleted();
        $array['has_failed'] = $this->hasFailed();
        $array['is_processing'] = $this->isProcessing();
        $array['has_passed_validation'] = $this->hasPassedValidation();
        $array['has_failed_validation'] = $this->hasFailedValidation();
        $array['has_validation_errors'] = $this->hasValidationErrors();
        
        return $array;
    }
}