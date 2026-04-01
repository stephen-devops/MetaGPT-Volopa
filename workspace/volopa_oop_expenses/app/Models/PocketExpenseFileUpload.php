<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Database\Factories\PocketExpenseFileUploadFactory;
use Illuminate\Support\Str;

/**
 * PocketExpenseFileUpload Model
 * 
 * Manages CSV file uploads for batch expense processing.
 * Tracks upload status, validation results, and processing progress.
 * Supports soft deletes and comprehensive error tracking.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property int $created_by_user_id
 * @property string $file_name
 * @property string $file_path
 * @property int $total_records
 * @property int $valid_records
 * @property array|null $validation_errors
 * @property string $status
 * @property \Carbon\Carbon|null $uploaded_at
 * @property \Carbon\Carbon|null $validated_at
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property \Carbon\Carbon|null $deleted_at
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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Available status values as per system constraints.
     */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_VALIDATION_PASSED = 'validation_passed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SYNC_FAILED = 'sync_failed';

    /**
     * Get the user for whom the expenses are being uploaded (target user).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client this upload belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the admin user who created this upload.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get all upload data records for this upload.
     */
    public function uploadsData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id');
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
     * Scope a query to only include uploads created by a specific admin.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $adminId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, int $adminId)
    {
        return $query->where('created_by_user_id', $adminId);
    }

    /**
     * Scope a query to only include recent uploads (within specified days).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Check if the upload is currently uploaded status.
     *
     * @return bool
     */
    public function isUploaded(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    /**
     * Check if the upload validation failed.
     *
     * @return bool
     */
    public function isValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the upload validation passed.
     *
     * @return bool
     */
    public function isValidationPassed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_PASSED;
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
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_SYNC_FAILED]);
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
     * Get the count of validation errors.
     *
     * @return int
     */
    public function getValidationErrorCount(): int
    {
        if (!$this->hasValidationErrors()) {
            return 0;
        }

        return isset($this->validation_errors['errors']) 
            ? count($this->validation_errors['errors']) 
            : 0;
    }

    /**
     * Get validation errors in a formatted array.
     *
     * @return array
     */
    public function getFormattedValidationErrors(): array
    {
        if (!$this->hasValidationErrors()) {
            return [];
        }

        return $this->validation_errors['errors'] ?? [];
    }

    /**
     * Get the success rate of the upload.
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
     * Get the file size in a human-readable format.
     *
     * @return string
     */
    public function getHumanFileSize(): string
    {
        if (!file_exists($this->file_path)) {
            return 'File not found';
        }

        $size = filesize($this->file_path);
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Mark the upload as validation failed with errors.
     *
     * @param array $validationErrors
     * @return bool
     */
    public function markValidationFailed(array $validationErrors): bool
    {
        $this->status = self::STATUS_VALIDATION_FAILED;
        $this->validation_errors = $validationErrors;
        $this->validated_at = now();
        $this->valid_records = 0;

        return $this->save();
    }

    /**
     * Mark the upload as validation passed.
     *
     * @param int $validRecordCount
     * @return bool
     */
    public function markValidationPassed(int $validRecordCount): bool
    {
        $this->status = self::STATUS_VALIDATION_PASSED;
        $this->validation_errors = null;
        $this->validated_at = now();
        $this->valid_records = $validRecordCount;

        return $this->save();
    }

    /**
     * Mark the upload as processing.
     *
     * @return bool
     */
    public function markProcessing(): bool
    {
        $this->status = self::STATUS_PROCESSING;
        
        return $this->save();
    }

    /**
     * Mark the upload as completed.
     *
     * @return bool
     */
    public function markCompleted(): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->processed_at = now();

        return $this->save();
    }

    /**
     * Mark the upload as failed.
     *
     * @param string $errorMessage
     * @return bool
     */
    public function markFailed(string $errorMessage): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->validation_errors = [
            'processing_error' => $errorMessage,
            'failed_at' => now()->toISOString()
        ];
        $this->processed_at = now();

        return $this->save();
    }

    /**
     * Mark the upload as sync failed.
     *
     * @param string $errorMessage
     * @return bool
     */
    public function markSyncFailed(string $errorMessage): bool
    {
        $this->status = self::STATUS_SYNC_FAILED;
        $this->validation_errors = [
            'sync_error' => $errorMessage,
            'failed_at' => now()->toISOString()
        ];
        $this->processed_at = now();

        return $this->save();
    }

    /**
     * Get the display name for this upload.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $statusLabel = ucfirst(str_replace('_', ' ', $this->status));
        return "{$this->file_name} ({$statusLabel})";
    }

    /**
     * Get a summary of this upload.
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'file_name' => $this->file_name,
            'status' => $this->status,
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'error_count' => $this->getValidationErrorCount(),
            'success_rate' => $this->getSuccessRate(),
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
        ];
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
     */
    protected static function boot(): void
    {
        parent::boot();

        // Set UUID and uploaded_at on creation
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            
            if (is_null($model->uploaded_at)) {
                $model->uploaded_at = now();
            }
            
            // Set default status if not provided
            if (empty($model->status)) {
                $model->status = self::STATUS_UPLOADED;
            }
            
            // Initialize counters if not provided
            if (is_null($model->total_records)) {
                $model->total_records = 0;
            }
            
            if (is_null($model->valid_records)) {
                $model->valid_records = 0;
            }
        });
    }

    /**
     * Get all available status options.
     *
     * @return array
     */
    public static function getAvailableStatuses(): array
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
     * Get status display labels.
     *
     * @return array
     */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_UPLOADED => 'Uploaded',
            self::STATUS_VALIDATION_FAILED => 'Validation Failed',
            self::STATUS_VALIDATION_PASSED => 'Validation Passed',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_SYNC_FAILED => 'Sync Failed',
        ];
    }

    /**
     * Get the status label for this upload.
     *
     * @return string
     */
    public function getStatusLabel(): string
    {
        $labels = self::getStatusLabels();
        return $labels[$this->status] ?? 'Unknown';
    }
}