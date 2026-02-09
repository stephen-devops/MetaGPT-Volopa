## Code: app/Models/PocketExpenseFileUpload.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PocketExpenseFileUpload extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pocket_expense_file_uploads';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'client_id',
        'expense_user_id',
        'original_filename',
        'stored_filename',
        'file_path',
        'file_size',
        'mime_type',
        'status',
        'total_records',
        'valid_records',
        'invalid_records',
        'processed_records',
        'validation_errors',
        'processing_errors',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'validation_errors',
        'processing_errors',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'file_size' => 'integer',
        'total_records' => 'integer',
        'valid_records' => 'integer',
        'invalid_records' => 'integer',
        'processed_records' => 'integer',
        'validation_errors' => 'array',
        'processing_errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'deleted_at' => 'datetime',
        'deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [
        'id',
        'uuid',
        'deleted',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'status' => 'uploading',
        'file_size' => 0,
        'mime_type' => 'text/csv',
        'total_records' => 0,
        'valid_records' => 0,
        'invalid_records' => 0,
        'processed_records' => 0,
        'deleted' => false,
    ];

    /**
     * Valid status values for the file upload.
     */
    const STATUS_UPLOADING = 'uploading';
    const STATUS_VALIDATING = 'validating';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get all valid status values.
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_UPLOADING,
            self::STATUS_VALIDATING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Get the user who uploaded the file.
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
     * Get the expense user (target user for the expenses).
     */
    public function expenseUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expense_user_id');
    }

    /**
     * Get all upload data records associated with this file upload.
     */
    public function uploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'file_upload_id');
    }

    /**
     * Get valid upload data records.
     */
    public function validUploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'file_upload_id')
                    ->where('row_status', 'valid');
    }

    /**
     * Get invalid upload data records.
     */
    public function invalidUploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'file_upload_id')
                    ->where('row_status', 'invalid');
    }

    /**
     * Get processed upload data records.
     */
    public function processedUploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'file_upload_id')
                    ->where('row_status', 'processed');
    }

    /**
     * Get failed upload data records.
     */
    public function failedUploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'file_upload_id')
                    ->where('row_status', 'failed');
    }

    /**
     * Scope a query to only include uploads for a specific client.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include uploads for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include uploads for a specific expense user.
     */
    public function scopeForExpenseUser(Builder $query, int $expenseUserId): Builder
    {
        return $query->where('expense_user_id', $expenseUserId);
    }

    /**
     * Scope a query to only include uploads with a specific status.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include uploading uploads.
     */
    public function scopeUploading(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UPLOADING);
    }

    /**
     * Scope a query to only include validating uploads.
     */
    public function scopeValidating(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATING);
    }

    /**
     * Scope a query to only include processing uploads.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include completed uploads.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed uploads.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to exclude soft deleted records.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to include only soft deleted records.
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to order uploads by most recent first.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope a query to include uploads within a date range.
     */
    public function scopeInDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
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
     * Check if the upload is in progress.
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADING,
            self::STATUS_VALIDATING,
            self::STATUS_PROCESSING,
        ]);
    }

    /**
     * Check if the upload is finished (completed or failed).
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Check if the upload is soft deleted.
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Mark the upload as validating.
     */
    public function markAsValidating(): bool
    {
        $this->status = self::STATUS_VALIDATING;
        $this->started_at = $this->started_at ?? now();
        return $this->save();
    }

    /**
     * Mark the upload as processing.
     */
    public function markAsProcessing(): bool
    {
        $this->status = self::STATUS_PROCESSING;
        $this->started_at = $this->started_at ?? now();
        return $this->save();
    }

    /**
     * Mark the upload as completed.
     */
    public function markAsCompleted(): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        return $this->save();
    }

    /**
     * Mark the upload as failed.
     */
    public function markAsFailed(string $errorMessage = null): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->failed_at = now();
        
        if ($errorMessage) {
            $this->error_message = $errorMessage;
        }
        
        return $this->save();
    }

    /**
     * Soft delete the upload.
     */
    public function softDelete(): bool
    {
        $this->deleted = true;
        $this->deleted_at = now();
        return $this->save();
    }

    /**
     * Restore the soft deleted upload.
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->deleted_at = null;
        return $this->save();
    }

    /**
     * Update record counts.
     */
    public function updateRecordCounts(int $total = null, int $valid = null, int $invalid = null, int $processed = null): bool
    {
        if ($total !== null) {
            $this->total_records = $total;
        }
        
        if ($valid !== null) {
            $this->valid_records = $valid;
        }
        
        if ($invalid !== null) {
            $this->invalid_records = $invalid;
        }
        
        if ($processed !== null) {
            $this->processed_records = $processed;
        }
        
        return $this->save();
    }

    /**
     * Add validation error.
     */
    public function addValidationError(array $error): bool
    {
        $errors = $this->validation_errors ?? [];
        $errors[] = $error;
        $this->validation_errors = $errors;
        return $this->save();
    }

    /**
     * Add multiple validation errors.
     */
    public function addValidationErrors(array $errors): bool
    {
        $existingErrors = $this->validation_errors ?? [];
        $this->validation_errors = array_merge($existingErrors, $errors);
        return $this->save();
    }

    /**
     * Clear validation errors.
     */
    public function clearValidationErrors(): bool
    {
        $this->validation_errors = [];
        return $this->save();
    }

    /**
     * Add processing error.
     */
    public function addProcessingError(array $error): bool
    {
        $errors = $this->processing_errors ?? [];
        $errors[] = $error;
        $this->processing_errors = $errors;
        return $this->save();
    }

    /**
     * Add multiple processing errors.
     */
    public function addProcessingErrors(array $errors): bool
    {
        $existingErrors = $this->processing_errors ?? [];
        $this->processing_errors = array_merge($existingErrors, $errors);
        return $this->save();
    }

    /**
     * Clear processing errors.
     */
    public function clearProcessingErrors(): bool
    {
        $this->processing_errors = [];
        return $this->save();
    }

    /**
     * Check if the upload has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors) && is_array($this->validation_errors) && count($this->validation_errors) > 0;
    }

    /**
     * Check if the upload has processing errors.
     */
    public function hasProcessingErrors(): bool
    {
        return !empty($this->processing_errors) && is_array($this->processing_errors) && count($this->processing_errors) > 0;
    }

    /**
     * Get validation error count.
     */
    public function getValidationErrorCount(): int
    {
        if (!$this->hasValidationErrors()) {
            return 0;
        }
        
        return count($this->validation_errors);
    }

    /**
     * Get processing error count.
     */
    public function getProcessingErrorCount(): int
    {
        if (!$this->hasProcessingErrors()) {
            return 0;
        }
        
        return count($this->processing_errors);
    }

    /**
     * Get total error count.
     */
    public function getTotalErrorCount(): int
    {
        return $this->getValidationErrorCount() + $this->getProcessingErrorCount();
    }

    /**
     * Get success rate as percentage.
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }
        
        return round(($this->valid_