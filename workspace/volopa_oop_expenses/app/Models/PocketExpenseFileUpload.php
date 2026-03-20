<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * PocketExpenseFileUpload Model
 * 
 * Represents file uploads for batch CSV processing of pocket expenses.
 * Tracks upload status, validation results, and processing statistics.
 * Uses Laravel SoftDeletes trait for data retention and cleanup.
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
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $createdBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpenseUploadsData> $uploadsData
 * @property-read int|null $uploads_data_count
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
        'validation_errors' => 'json',
        'status' => 'string',
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
    protected $hidden = [
        'file_path',
        'created_by_user_id',
        'deleted_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'uploaded',
        'total_records' => 0,
        'valid_records' => 0,
    ];

    /**
     * Status enum values.
     */
    const STATUS_UPLOADED = 'uploaded';
    const STATUS_VALIDATION_FAILED = 'validation_failed';
    const STATUS_VALIDATION_PASSED = 'validation_passed';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_SYNC_FAILED = 'sync_failed';

    /**
     * Valid status values.
     *
     * @var array<string>
     */
    public static array $validStatuses = [
        self::STATUS_UPLOADED,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_VALIDATION_PASSED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_SYNC_FAILED,
    ];

    /**
     * Get the user who initiated the upload.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpenseFileUpload>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client that this upload is scoped to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Client, \App\Models\PocketExpenseFileUpload>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who created this upload record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpenseFileUpload>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the individual upload data records for this upload batch.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PocketExpenseUploadsData>
     */
    public function uploadsData(): HasMany
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
     * Scope a query to only include uploaded files.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUploaded($query)
    {
        return $query->where('status', self::STATUS_UPLOADED);
    }

    /**
     * Scope a query to only include validation failed uploads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidationFailed($query)
    {
        return $query->where('status', self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope a query to only include validation passed uploads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidationPassed($query)
    {
        return $query->where('status', self::STATUS_VALIDATION_PASSED);
    }

    /**
     * Scope a query to only include processing uploads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include completed uploads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed uploads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_SYNC_FAILED]);
    }

    /**
     * Scope a query to include uploads that need cleanup (old completed/failed uploads).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $daysOld
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsCleanup($query, int $daysOld = 30)
    {
        $cutoffDate = now()->subDays($daysOld);
        
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SYNC_FAILED])
                     ->where('processed_at', '<', $cutoffDate);
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
     * Check if the upload failed validation.
     *
     * @return bool
     */
    public function hasValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the upload passed validation.
     *
     * @return bool
     */
    public function hasValidationPassed(): bool
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
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_SYNC_FAILED]);
    }

    /**
     * Check if the upload has validation errors.
     *
     * @return bool
     */
    public function hasValidationErrors(): bool
    {
        return !is_null($this->validation_errors) && !empty($this->validation_errors);
    }

    /**
     * Check if the upload can be reprocessed.
     *
     * @return bool
     */
    public function canReprocess(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_SYNC_FAILED,
            self::STATUS_VALIDATION_PASSED
        ]);
    }

    /**
     * Set the upload status to validation failed.
     *
     * @param array $errors
     * @return bool
     */
    public function markValidationFailed(array $errors = []): bool
    {
        $this->status = self::STATUS_VALIDATION_FAILED;
        $this->validation_errors = $errors;
        $this->validated_at = now();
        return $this->save();
    }

    /**
     * Set the upload status to validation passed.
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
     * Set the upload status to processing.
     *
     * @return bool
     */
    public function markProcessing(): bool
    {
        if (!$this->hasValidationPassed()) {
            return false;
        }

        $this->status = self::STATUS_PROCESSING;
        return $this->save();
    }

    /**
     * Set the upload status to completed.
     *
     * @return bool
     */
    public function markCompleted(): bool
    {
        if (!$this->isProcessing()) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->processed_at = now();
        return $this->save();
    }

    /**
     * Set the upload status to failed.
     *
     * @param array $errors
     * @return bool
     */
    public function markFailed(array $errors = []): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->validation_errors = array_merge($this->validation_errors ?? [], $errors);
        $this->processed_at = now();
        return $this->save();
    }

    /**
     * Set the upload status to sync failed.
     *
     * @param array $errors
     * @return bool
     */
    public function markSyncFailed(array $errors = []): bool
    {
        $this->status = self::STATUS_SYNC_FAILED;
        $this->validation_errors = array_merge($this->validation_errors ?? [], $errors);
        $this->processed_at = now();
        return $this->save();
    }

    /**
     * Update validation statistics.
     *
     * @param int $totalRecords
     * @param int $validRecords
     * @return bool
     */
    public function updateValidationStats(int $totalRecords, int $validRecords): bool
    {
        $this->total_records = $totalRecords;
        $this->valid_records = $validRecords;
        return $this->save();
    }

    /**
     * Get the validation success rate as a percentage.
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
     * Get the status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADED => 'Uploaded',
            self::STATUS_VALIDATION_FAILED => 'Validation Failed',
            self::STATUS_VALIDATION_PASSED => 'Validation Passed',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_SYNC_FAILED => 'Sync Failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Get the processing duration in seconds.
     *
     * @return int|null
     */
    public function getProcessingDuration(): ?int
    {
        if (is_null($this->validated_at) || is_null($this->processed_at)) {
            return null;
        }

        return $this->validated_at->diffInSeconds($this->processed_at);
    }

    /**
     * Get a summary of the upload for logging/display.
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
            'invalid_records' => $this->getInvalidRecordsCount(),
            'success_rate' => $this->getValidationSuccessRate(),
            'has_errors' => $this->hasValidationErrors(),
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'processing_duration' => $this->getProcessingDuration(),
        ];
    }

    /**
     * Get the file size if file still exists.
     *
     * @return int|null
     */
    public function getFileSize(): ?int
    {
        if (empty($this->file_path) || !file_exists($this->file_path)) {
            return null;
        }

        return filesize($this->file_path);
    }

    /**
     * Check if the physical file still exists.
     *
     * @return bool
     */
    public function fileExists(): bool
    {
        return !empty($this->file_path) && file_exists($this->file_path);
    }

    /**
     * Delete the physical file if it exists.
     *
     * @return bool
     */
    public function deleteFile(): bool
    {
        if ($this->fileExists()) {
            return unlink($this->file_path);
        }

        return true;
    }

    /**
     * Find upload by UUID.
     *
     * @param string $uuid
     * @return \App\Models\PocketExpenseFileUpload|null
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
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function forUserAndClient(int $userId, int $clientId): \Illuminate\Database\Eloquent\Builder
    {
        return static::forUser($userId)->forClient($clientId);
    }

    /**
     * Get recent uploads for a client (last 30 days).
     *
     * @param int $clientId
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getRecentForClient(int $clientId, int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return static::forClient($clientId)
                     ->where('created_at', '>=', now()->subDays($days))
                     ->orderBy('created_at', 'desc')
                     ->get();
    }

    /**
     * Get pending uploads that need processing.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPendingProcessing(): \Illuminate\Database\Eloquent\Collection
    {
        return static::validationPassed()
                     ->orderBy('validated_at', 'asc')
                     ->get();
    }

    /**
     * Create a new upload record.
     *
     * @param array $attributes
     * @return static
     */
    public static function createUpload(array $attributes): static
    {
        $attributes['uuid'] = $attributes['uuid'] ?? Str::uuid()->toString();
        $attributes['uploaded_at'] = $attributes['uploaded_at'] ?? now();
        $attributes['status'] = $attributes['status'] ?? self::STATUS_UPLOADED;
        
        return static::create($attributes);
    }

    /**
     * Validate if a status value is valid.
     *
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::$validStatuses);
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Generate UUID on creation if not provided
        static::creating(function (PocketExpenseFileUpload $upload) {
            if (empty($upload->uuid)) {
                $upload->uuid = Str::uuid()->toString();
            }
            
            // Set uploaded_at if not provided
            if (is_null($upload->uploaded_at)) {
                $upload->uploaded_at = now();
            }
            
            // Ensure defaults
            if (empty($upload->status)) {
                $upload->status = self::STATUS_UPLOADED;
            }
            
            if (is_null($upload->total_records)) {
                $upload->total_records = 0;
            }
            
            if (is_null($upload->valid_records)) {
                $upload->valid_records = 0;
            }

            // Validate status
            if (!self::isValidStatus($upload->status)) {
                throw new \InvalidArgumentException('Invalid upload status: ' . $upload->status);
            }

            // Validate file_name length
            if (isset($upload->file_name) && strlen($upload->file_name) > 255) {
                $upload->file_name = substr($upload->file_name, 0, 255);
            }

            // Validate file_path length
            if (isset($upload->file_path) && strlen($upload->file_path) > 500) {
                throw new \InvalidArgumentException('File path too long (max 500 characters): ' . $upload->file_path);
            }
        });

        // Handle updates
        static::updating(function (PocketExpenseFileUpload $upload) {
            // Validate status changes
            if ($upload->isDirty('status') && !self::isValidStatus($upload->status)) {
                throw new \InvalidArgumentException('Invalid upload status: ' . $upload->status);
            }

            // Ensure valid_records never exceeds total_records
            if ($upload->isDirty(['total_records', 'valid_records'])) {
                if ($upload->valid_records > $upload->total_records) {
                    $upload->valid_records = $upload->total_records;
                }
            }

            // Set processed_at when status changes to completed/failed
            if ($upload->isDirty('status') && 
                in_array($upload->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_SYNC_FAILED]) &&
                is_null($upload->processed_at)) {
                $upload->processed_at = now();
            }

            // Set validated_at when status changes to validation passed/failed
            if ($upload->isDirty('status') && 
                in_array($upload->status, [self::STATUS_VALIDATION_PASSED, self::STATUS_VALIDATION_FAILED]) &&
                is_null($upload->validated_at)) {
                $upload->validated_at = now();
            }
        });

        // Log upload status changes for audit purposes
        static::updated(function (PocketExpenseFileUpload $upload) {
            if ($upload->isDirty('status')) {
                \Log::info('Upload status changed', [
                    'upload_id' => $upload->id,
                    'uuid' => $upload->uuid,
                    'user_id' => $upload->user_id,
                    'client_id' => $upload->client_id,
                    'file_name' => $upload->file_name,
                    'old_status' => $upload->getOriginal('status'),
                    'new_status' => $upload->status,
                    'total_records' => $upload->total_records,
                    'valid_records' => $upload->valid_records,
                    'changed_at' => now(),
                ]);
            }
        });

        // Clean up physical file when soft deleting
        static::deleting(function (PocketExpenseFileUpload $upload) {
            // Only delete file if it's a soft delete (not force delete)
            if (method_exists($upload, 'isForceDeleting') && !$upload->isForceDeleting()) {
                // Log file cleanup attempt
                \Log::info('Cleaning up upload file on soft delete', [
                    'upload_id' => $upload->id,
                    'uuid' => $upload->uuid,
                    'file_path' => $upload->file_path,
                    'file_exists' => $upload->fileExists(),
                ]);
                
                // Delete the physical file
                $upload->deleteFile();
            }
        });

        // Clean up physical file when force deleting
        static::forceDeleted(function (PocketExpenseFileUpload $upload) {
            // Log file cleanup attempt
            \Log::info('Cleaning up upload file on force delete', [
                'upload_id' => $upload->id,
                'uuid' => $upload->uuid,
                'file_path' => $upload->file_path,
                'file_exists' => $upload->fileExists(),
            ]);
            
            // Delete the physical file
            $upload->deleteFile();
        });

        // Log creation events
        static::created(function (PocketExpenseFileUpload $upload) {
            \Log::info('New upload record created', [
                'upload_id' => $upload->id,
                'uuid' => $upload->uuid,
                'user_id' => $upload->user_id,
                'client_id' => $upload->client_id,
                'file_name' => $upload->file_name,
                'status' => $upload->status,
                'created_at' => $upload->created_at,
            ]);
        });
    }
}