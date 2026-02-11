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
     */
    protected $table = 'pocket_expense_file_uploads';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'client_id',
        'created_by_user_id',
        'original_filename',
        'stored_filename',
        'file_path',
        'file_size',
        'file_type',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'validation_errors',
        'processing_summary',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'created_by_user_id' => 'integer',
        'original_filename' => 'string',
        'stored_filename' => 'string',
        'file_path' => 'string',
        'file_size' => 'integer',
        'file_type' => 'string',
        'status' => 'string',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
        'validation_errors' => 'array',
        'processing_summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'file_size' => 0,
        'file_type' => 'csv',
        'status' => 'uploading',
        'total_rows' => 0,
        'processed_rows' => 0,
        'successful_rows' => 0,
        'failed_rows' => 0,
    ];

    /**
     * The possible status values.
     */
    const STATUS_UPLOADING = 'uploading';
    const STATUS_VALIDATING = 'validating';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * The possible file type values.
     */
    const FILE_TYPE_CSV = 'csv';
    const FILE_TYPE_EXCEL = 'excel';
    const FILE_TYPE_XLSX = 'xlsx';

    /**
     * Boot the model and generate UUID.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get all possible status values.
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_UPLOADING,
            self::STATUS_VALIDATING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Get all possible file type values.
     */
    public static function getFileTypeOptions(): array
    {
        return [
            self::FILE_TYPE_CSV,
            self::FILE_TYPE_EXCEL,
            self::FILE_TYPE_XLSX,
        ];
    }

    /**
     * Get the user whose expenses are being created (target user).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with the upload.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who performed the upload (admin user).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the upload data rows associated with this upload.
     */
    public function uploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id');
    }

    /**
     * Scope a query to only include uploads for a specific client.
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include uploads for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include uploads created by a specific user.
     */
    public function scopeCreatedBy($query, int $createdByUserId)
    {
        return $query->where('created_by_user_id', $createdByUserId);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include uploading status.
     */
    public function scopeUploading($query)
    {
        return $query->where('status', self::STATUS_UPLOADING);
    }

    /**
     * Scope a query to only include validating status.
     */
    public function scopeValidating($query)
    {
        return $query->where('status', self::STATUS_VALIDATING);
    }

    /**
     * Scope a query to only include processing status.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include completed status.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed status.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include cancelled status.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope a query to filter by file type.
     */
    public function scopeByFileType($query, string $fileType)
    {
        return $query->where('file_type', $fileType);
    }

    /**
     * Scope a query to only include CSV files.
     */
    public function scopeCsvFiles($query)
    {
        return $query->where('file_type', self::FILE_TYPE_CSV);
    }

    /**
     * Scope a query to only include Excel files.
     */
    public function scopeExcelFiles($query)
    {
        return $query->whereIn('file_type', [self::FILE_TYPE_EXCEL, self::FILE_TYPE_XLSX]);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope a query to only include recent uploads.
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope a query to only include active uploads (not completed, failed, or cancelled).
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_UPLOADING,
            self::STATUS_VALIDATING,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Scope a query to only include finished uploads (completed, failed, or cancelled).
     */
    public function scopeFinished($query)
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Scope a query to include uploads with errors.
     */
    public function scopeWithErrors($query)
    {
        return $query->whereNotNull('validation_errors')
                    ->where('validation_errors', '!=', '[]');
    }

    /**
     * Scope a query to order by creation date descending.
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Check if the upload is uploading.
     */
    public function isUploading(): bool
    {
        return $this->status === self::STATUS_UPLOADING;
    }

    /**
     * Check if the upload is validating.
     */
    public function isValidating(): bool
    {
        return $this->status === self::STATUS_VALIDATING;
    }

    /**
     * Check if the upload is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the upload is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the upload has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the upload is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the upload is active (in progress).
     */
    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADING,
            self::STATUS_VALIDATING,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Check if the upload is finished.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Check if the file type is CSV.
     */
    public function isCsv(): bool
    {
        return $this->file_type === self::FILE_TYPE_CSV;
    }

    /**
     * Check if the file type is Excel.
     */
    public function isExcel(): bool
    {
        return in_array($this->file_type, [self::FILE_TYPE_EXCEL, self::FILE_TYPE_XLSX]);
    }

    /**
     * Mark the upload as validating.
     */
    public function markAsValidating(): bool
    {
        return $this->update([
            'status' => self::STATUS_VALIDATING,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    /**
     * Mark the upload as processing.
     */
    public function markAsProcessing(): bool
    {
        return $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    /**
     * Mark the upload as completed.
     */
    public function markAsCompleted(array $summary = []): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'processing_summary' => array_merge($this->processing_summary ?? [], $summary),
        ]);
    }

    /**
     * Mark the upload as failed.
     */
    public function markAsFailed(array $errors = []): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'validation_errors' => array_merge($this->validation_errors ?? [], $errors),
        ]);
    }

    /**
     * Mark the upload as cancelled.
     */
    public function markAsCancelled(): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Update the row counts.
     */
    public function updateRowCounts(int $totalRows = null, int $processedRows = null, int $successfulRows = null, int $failedRows = null): bool
    {
        $updates = [];

        if ($totalRows !== null) {
            $updates['total_rows'] = $totalRows;
        }

        if ($processedRows !== null) {
            $updates['processed_rows'] = $processedRows;
        }

        if ($successfulRows !== null) {
            $updates['successful_rows'] = $successfulRows;
        }

        if ($failedRows !== null) {
            $updates['failed_rows'] = $failedRows;
        }

        return !empty($updates) ? $this->update($updates) : false;
    }

    /**
     * Increment the processed rows count.
     */
    public function incrementProcessedRows(int $count = 1): bool
    {
        return $this->increment('processed_rows', $count);
    }

    /**
     * Increment the successful rows count.
     */
    public function incrementSuccessfulRows(int $count = 1): bool
    {
        return $this->increment('successful_rows', $count);
    }

    /**
     * Increment the failed rows count.
     */
    public function incrementFailedRows(int $count = 1): bool
    {
        return $this->increment('failed_rows', $count);
    }

    /**
     * Add validation errors.
     */
    public function addValidationErrors(array $errors): bool
    {
        $currentErrors = $this->validation_errors ?? [];
        $updatedErrors = array_merge($currentErrors, $errors);

        return $this->update(['validation_errors' => $updatedErrors]);
    }

    /**
     * Clear validation errors.
     */
    public function clearValidationErrors(): bool
    {
        return $this->update(['validation_errors' => []]);
    }

    /**
     * Update processing summary.
     */
    public function updateProcessingSummary(array $summary): bool
    {
        $currentSummary = $this->processing_summary ?? [];
        $updatedSummary = array_merge($currentSummary, $summary);

        return $this->update(['processing_summary' => $updatedSummary]);
    }

    /**
     * Clear processing summary.
     */
    public function clearProcessingSummary(): bool
    {
        return $this->update(['processing_summary' => []]);
    }

    /**
     * Check if the upload has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        $errors = $this->validation_errors ?? [];
        return !empty($errors);
    }

    /**
     * Get the count of validation errors.
     */
    public function getValidationErrorCount(): int
    {
        $errors = $this->validation_errors ?? [];
        return count($errors);
    }

    /**
     * Check if the upload has processing summary.
     */
    public function hasProcessingSummary(): bool
    {
        $summary = $this->processing_summary ?? [];
        return !empty($summary);
    }

    /**
     * Get the processing progress percentage.
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->processed_rows / $this->total_rows) * 100, 2);
    }

    /**
     * Get the success rate percentage.
     */
    public function getSuccessRate(): float
    {
        if ($this->processed_rows === 0) {
            return 0.0;
        }

        return round(($this->successful_rows / $this->processed_rows) * 100, 2);
    }

    /**
     * Get the failure rate percentage.
     */
    public function getFailureRate(): float
    {
        if ($this->processed_rows === 0) {
            return 0.0;
        }

        return round(($this->failed_rows / $this->processed_rows) * 100, 2);
    }

    /**
     * Get the processing duration in seconds.
     */
    public function getProcessingDuration(): ?int
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }

        return $this->completed_at->diffInSeconds($this->started_at);
    }

    /**
     * Get the processing duration formatted as human readable.
     */
    public function getFormattedProcessingDuration(): string
    {
        $duration = $this->getProcessingDuration();

        if ($duration === null) {
            return 'N/A';
        }

        if ($duration < 60) {
            return $duration . ' seconds';
        }

        if ($duration < 3600) {
            return round($duration / 60, 1) . ' minutes';
        }

        return round($duration / 3600, 1) . ' hours';
    }

    /**
     * Get the formatted file size.
     */
    public function getFormattedFileSize(): string
    {
        $bytes = $this->file_size;

        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Get the estimated time remaining for processing.
     */
    public function getEstimatedTimeRemaining(): ?int
    {
        if (!$this->isActive() || $this->processed_rows === 0 || !$this->started_at) {
            return null;
        }

        $remainingRows = $this->total_rows - $this->processed_rows;
        $elapsedTime = $this->started_at->diffInSeconds(now());
        $processingRate = $this->processed_rows / $elapsedTime;

        if ($processingRate <= 0) {
            return null;
        }

        return (int) round($remainingRows / $processingRate);
    }

    /**
     * Get the estimated time remaining formatted as human readable.
     */
    public function getFormattedEstimatedTimeRemaining(): string
    {
        $seconds = $this->getEstimatedTimeRemaining();

        if ($seconds === null) {
            return 'N/A';
        }

        if ($seconds < 60) {
            return $seconds . ' seconds';
        }

        if ($seconds < 3600) {
            return round($seconds / 60, 1) . ' minutes';
        }

        return round($seconds / 3600, 1) . ' hours';
    }

    /**
     * Get upload statistics summary.
     */
    public function getStatisticsSummary(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'file_name' => $this->original_filename,
            'file_size' => $this->getFormattedFileSize(),
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'successful_rows' => $this->successful_rows,
            'failed_rows' => $this->failed_rows,
            'progress_percentage' => $this->getProgressPercentage(),
            'success_rate' => $this->getSuccessRate(),
            'failure_rate' => $this->getFailureRate(),
            'processing_duration' => $this->getFormattedProcessingDuration(),
            'estimated_time_remaining' => $this->getFormattedEstimatedTimeRemaining(),
            'has_errors' => $this->hasValidationErrors(),
            'error_count' => $this->getValidationErrorCount(),
            'created_at' => $this->created_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
        ];
    }

    /**
     * Find upload by UUID.
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * Find upload by UUID or fail.
     */
    public static function findByUuidOrFail(string $uuid): self
    {
        return static::where('uuid', $uuid)->firstOrFail();
    }

    /**
     * Get uploads for a specific client with statistics.
     */
    public static function getUploadsWithStatsForClient(int $clientId): array
    {
        $uploads = static::forClient($clientId)
            ->with(['user', 'creator'])
            ->latest()
            ->get();

        return [
            'uploads' => $uploads,
            'total_uploads' => $uploads->count(),
            'active_uploads' => $uploads->where('status', 'in', [
                self::STATUS_UPLOADING,
                self::STATUS_VALIDATING,
                self::STATUS_PROCESSING,
            ])->count(),
            'completed_uploads' => $uploads->where('status', self::STATUS_COMPLETED)->count(),
            'failed_uploads' => $uploads->where('status', self::STATUS_FAILED)->count(),
            'cancelled_uploads' => $uploads->where('status', self::STATUS_CANCELLED)->count(),
            'total_rows_processed' => $uploads->sum('processed_rows'),
            'total_successful_rows' => $uploads->sum('successful_rows'),
            'total_failed_rows' => $uploads->sum('failed_rows'),
        ];
    }

    /**
     * Get recent upload activity for a client.
     */
    public static function getRecentActivityForClient(int $clientId, int $hours = 24): array
    {
        $uploads = static::forClient($clientId)
            ->recent($hours)
            ->with(['user', 'creator'])
            ->latest()
            ->get();

        return [
            'recent_uploads' => $uploads,
            'upload_count' => $uploads->count(),
            'rows_processed' => $uploads->sum('processed_rows'),
            'successful_rows' => $uploads->sum('successful_rows'),
            'failed_rows' => $uploads->sum('failed_rows'),
        ];
    }

    /**
     * Get upload status distribution for a client.
     */
    public static function getStatusDistributionForClient(int $clientId): array
    {
        $uploads = static::forClient($clientId)->get();
        $distribution = [];

        foreach (static::getStatusOptions() as $status) {
            $distribution[$status] = $uploads->where('status', $status)->count();
        }

        return $distribution;
    }

    /**
     * Clean up old completed uploads.
     */
    public static function cleanupOldUploads(int $daysOld = 30): int
    {
        return static::finished()
            ->where('completed_at', '<', now()->subDays($daysOld))
            ->delete();
    }

    /**
     * Cancel all active uploads for a user.
     */
    public static function cancelActiveUploadsForUser(int $userId): int
    {
        return static::forUser($userId)
            ->active()
            ->update([
                'status' => self::STATUS_CANCELLED,
                'completed_at' => now(),
            ]);
    }

    /**
     * Cancel all active uploads for a client.
     */
    public static function cancelActiveUploadsForClient(int $clientId): int
    {
        return static::forClient($clientId)
            ->active()
            ->update([
                'status' => self::STATUS_CANCELLED,
                'completed_at' => now(),
            ]);
    }

    /**
     * Get upload performance metrics.
     */
    public static function getPerformanceMetrics(int $days = 30): array
    {
        $uploads = static::where('created_at', '>=', now()->subDays($days))
            ->finished()
            ->get();

        if ($uploads->isEmpty()) {
            return [
                'average_processing_time' => 0,
                'average_success_rate' => 0,
                'average_file_size' => 0,
                'total_uploads' => 0,
                'total_rows_processed' => 0,
            ];
        }

        $totalProcessingTime = 0;
        $uploadsWithDuration = 0;

        foreach ($uploads as $upload) {
            $duration = $upload->getProcessingDuration();
            if ($duration !== null) {
                $totalProcessingTime += $duration;
                $uploadsWithDuration++;
            }
        }

        return [
            'average_processing_time' => $uploadsWithDuration > 0 ? round($totalProcessingTime / $uploadsWithDuration, 2) : 0,
            'average_success_rate' => round($uploads->avg(function ($upload) {
                return $upload->getSuccessRate();
            }), 2),
            'average_file_size' => round($uploads->avg('file_size'), 2),
            'total_uploads' => $uploads->count(),
            'total_rows_processed' => $uploads->sum('processed_rows'),
        ];
    }

    /**
     * Check if a user can create a new upload.
     */
    public static function canUserCreateUpload(int $userId, int $clientId, int $maxActiveUploads = 5): bool
    {
        $activeUploadsCount = static::forUser($userId)
            ->forClient($clientId)
            ->active()
            ->count();

        return $activeUploadsCount < $maxActiveUploads;
    }

    /**
     * Get the count of active uploads for a user and client.
     */
    public static function getActiveUploadCountForUser(int $userId, int $clientId): int
    {
        return static::forUser($userId)
            ->forClient($clientId)
            ->active()
            ->count();
    }

    /**
     * Create a new upload with automatic file naming.
     */
    public static function createUpload(array $attributes): self
    {
        // Generate stored filename if not provided
        if (empty($attributes['stored_filename']) && !empty($attributes['original_filename'])) {
            $extension = pathinfo($attributes['original_filename'], PATHINFO_EXTENSION);
            $attributes['stored_filename'] = Str::uuid()->toString() . '.' . $extension;
        }

        // Set default file path if not provided
        if (empty($attributes['file_path']) && !empty($attributes['stored_filename'])) {
            $attributes['file_path'] = 'uploads/pocket-expenses/' . date('Y/m/d') . '/' . $attributes['stored_filename'];
        }

        return static::create($attributes);
    }

    /**
     * Check if the upload can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return $this->isActive();
    }

    /**
     * Check if the upload can be retried.
     */
    public function canBeRetried(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    /**
     * Check if the upload can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return $this->isFinished();
    }

    /**
     * Reset upload for retry.
     */
    public function resetForRetry(): bool
    {
        return $this->update([
            'status' => self::STATUS_UPLOADING,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'validation_errors' => [],
            'processing_summary' => [],
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Get the route key name for URL generation.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the value of the model's route key.
     */
    public function getRouteKey(): string
    {
        return $this->uuid;
    }

    /**
     * Resolve a route binding query for the given value.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->where('uuid', $value)->first();
    }
}