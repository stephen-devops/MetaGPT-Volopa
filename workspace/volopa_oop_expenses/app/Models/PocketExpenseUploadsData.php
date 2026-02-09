## Development Plan

The file to be created for the PocketExpenseUploadsData model:

- app/Models/PocketExpenseUploadsData.php

## Incremental Change

- app/Models/PocketExpenseUploadsData.php: Eloquent model for CSV upload row data with UUID, file upload relationship, row validation status, and soft deletes

## Code: app/Models/PocketExpenseUploadsData.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PocketExpenseUploadsData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pocket_expense_uploads_data';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'file_upload_id',
        'row_number',
        'original_data',
        'processed_data',
        'validation_errors',
        'row_status',
        'created_expense_id',
        'processing_notes',
        'validated_at',
        'processed_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'validation_errors',
        'original_data',
        'processed_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'row_number' => 'integer',
        'original_data' => 'array',
        'processed_data' => 'array',
        'validation_errors' => 'array',
        'created_expense_id' => 'integer',
        'validated_at' => 'datetime',
        'processed_at' => 'datetime',
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
        'row_status' => 'pending',
        'deleted' => false,
    ];

    /**
     * Valid row status values.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    const STATUS_PROCESSED = 'processed';
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
     * Get all valid row status values.
     */
    public static function getValidRowStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_VALID,
            self::STATUS_INVALID,
            self::STATUS_PROCESSED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Get the file upload that owns this data row.
     */
    public function fileUpload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'file_upload_id');
    }

    /**
     * Get the created expense if this row was successfully processed.
     */
    public function createdExpense(): BelongsTo
    {
        return $this->belongsTo(OopExpense::class, 'created_expense_id');
    }

    /**
     * Scope a query to only include data for a specific file upload.
     */
    public function scopeForFileUpload(Builder $query, int $fileUploadId): Builder
    {
        return $query->where('file_upload_id', $fileUploadId);
    }

    /**
     * Scope a query to only include data with a specific row status.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('row_status', $status);
    }

    /**
     * Scope a query to only include pending rows.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include valid rows.
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_VALID);
    }

    /**
     * Scope a query to only include invalid rows.
     */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_INVALID);
    }

    /**
     * Scope a query to only include processed rows.
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_PROCESSED);
    }

    /**
     * Scope a query to only include failed rows.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('row_status', self::STATUS_FAILED);
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
     * Scope a query to order by row number.
     */
    public function scopeOrderByRowNumber(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('row_number', $direction);
    }

    /**
     * Scope a query to include rows that have been successfully processed into expenses.
     */
    public function scopeWithCreatedExpense(Builder $query): Builder
    {
        return $query->whereNotNull('created_expense_id');
    }

    /**
     * Scope a query to include rows that have not been processed into expenses.
     */
    public function scopeWithoutCreatedExpense(Builder $query): Builder
    {
        return $query->whereNull('created_expense_id');
    }

    /**
     * Check if the row is pending.
     */
    public function isPending(): bool
    {
        return $this->row_status === self::STATUS_PENDING;
    }

    /**
     * Check if the row is valid.
     */
    public function isValid(): bool
    {
        return $this->row_status === self::STATUS_VALID;
    }

    /**
     * Check if the row is invalid.
     */
    public function isInvalid(): bool
    {
        return $this->row_status === self::STATUS_INVALID;
    }

    /**
     * Check if the row has been processed.
     */
    public function isProcessed(): bool
    {
        return $this->row_status === self::STATUS_PROCESSED;
    }

    /**
     * Check if the row processing has failed.
     */
    public function isFailed(): bool
    {
        return $this->row_status === self::STATUS_FAILED;
    }

    /**
     * Check if the row is soft deleted.
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Mark the row as valid.
     */
    public function markAsValid(): bool
    {
        $this->row_status = self::STATUS_VALID;
        $this->validated_at = now();
        return $this->save();
    }

    /**
     * Mark the row as invalid.
     */
    public function markAsInvalid(array $validationErrors = []): bool
    {
        $this->row_status = self::STATUS_INVALID;
        $this->validated_at = now();
        
        if (!empty($validationErrors)) {
            $this->validation_errors = $validationErrors;
        }
        
        return $this->save();
    }

    /**
     * Mark the row as processed.
     */
    public function markAsProcessed(int $createdExpenseId = null, string $notes = null): bool
    {
        $this->row_status = self::STATUS_PROCESSED;
        $this->processed_at = now();
        
        if ($createdExpenseId) {
            $this->created_expense_id = $createdExpenseId;
        }
        
        if ($notes) {
            $this->processing_notes = $notes;
        }
        
        return $this->save();
    }

    /**
     * Mark the row processing as failed.
     */
    public function markAsFailed(string $notes = null): bool
    {
        $this->row_status = self::STATUS_FAILED;
        $this->processed_at = now();
        
        if ($notes) {
            $this->processing_notes = $notes;
        }
        
        return $this->save();
    }

    /**
     * Soft delete the row.
     */
    public function softDelete(): bool
    {
        $this->deleted = true;
        $this->deleted_at = now();
        return $this->save();
    }

    /**
     * Restore the soft deleted row.
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->deleted_at = null;
        return $this->save();
    }

    /**
     * Set processed data.
     */
    public function setProcessedData(array $data): bool
    {
        $this->processed_data = $data;
        return $this->save();
    }

    /**
     * Add validation error.
     */
    public function addValidationError(string $field, string $error, mixed $value = null): bool
    {
        $errors = $this->validation_errors ?? [];
        $errors[] = [
            'field' => $field,
            'error' => $error,
            'value' => $value,
            'line_number' => $this->row_number,
        ];
        $this->validation_errors = $errors;
        return $this->save();
    }

    /**
     * Add multiple validation errors.
     */
    public function addValidationErrors(array $errors): bool
    {
        $existingErrors = $this->validation_errors ?? [];
        
        foreach ($errors as $error) {
            $existingErrors[] = array_merge($error, [
                'line_number' => $this->row_number,
            ]);
        }
        
        $this->validation_errors = $existingErrors;
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
     * Check if the row has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors) && is_array($this->validation_errors) && count($this->validation_errors) > 0;
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
     * Check if the row has been processed into an expense.
     */
    public function hasCreatedExpense(): bool
    {
        return !is_null($this->created_expense_id);
    }

    /**
     * Check if the row has processing notes.
     */
    public function hasProcessingNotes(): bool
    {
        return !empty($this->processing_notes);
    }

    /**
     * Check if the row has processed data.
     */
    public function hasProcessedData(): bool
    {
        return !empty($this->processed_data) && is_array($this->processed_data);
    }

    /**
     * Get a specific field value from original data.
     */
    public function getOriginalField(string $field, mixed $default = null): mixed
    {
        if (empty($this->original_data) || !is_array($this->original_data)) {
            return $default;
        }
        
        return $this->original_data[$field] ?? $default;
    }

    /**
     * Get a specific field value from processed data.
     */
    public function getProcessedField(string $field, mixed $default = null): mixed
    {
        if (!$this->hasProcessedData()) {
            return $default;
        }
        
        return $this->processed_data[$field] ?? $default;
    }

    /**
     * Set a specific field value in processed data.
     */
    public function setProcessedField(string $field, mixed $value): void
    {
        $processedData = $this->processed_data ?? [];
        $processedData[$field] = $value;
        $this->processed_data = $processedData;
    }

    /**
     * Remove a specific field from processed data.
     */
    public function removeProcessedField(string $field): void
    {
        if (!$this->hasProcessedData()) {
            return;
        }
        
        $processedData = $this->processed_data;
        unset($processedData[$field]);
        $this->processed_data = $processedData;
    }

    /**
     * Check if the row belongs to a specific file upload.
     */
    public function belongsToFileUpload(int $fileUploadId): bool
    {
        return $this->file_upload_id === $fileUploadId;
    }

    /**
     * Get the age of the row in hours since creation.
     */
    public function getAgeInHoursAttribute(): int
    {
        return now()->diffInHours($this->created_at);
    }

    /**
     * Get the processing time in minutes.
     */
    public function getProcessingTimeInMinutesAttribute(): ?int
    {
        if (!$this->processed_at || !$this->validated_at) {
            return null;
        }
        
        return $this->validated_at->diffInMinutes($this->processed_at);
    }

    /**
     * Check if the row processing took too long.
     */
    public function isProcessingDelayed(int $maxMinutes = 30): bool
    {
        $processingTime = $this->getProcessingTimeInMinutesAttribute();
        
        if ($processingTime === null) {
            return false;
        }
        
        return $processingTime > $maxMinutes;
    }

    /**
     * Get rows that need reprocessing (valid but not processed).
     */
    public function scopeNeedsReprocessing(Builder $query): Builder
    {
        return $query->valid()
                    ->whereNull('created_expense_id')
                    ->where('validated_at', '<', now()->subMinutes(30));
    }

    /**
     * Get rows that are stuck in processing.
     */
    public function scopeStuckInProcessing(Builder $query, int $maxHours = 2): Builder
    {
        return $query->pending()
                    ->where('created_at', '<', now()->subHours($maxHours));
    }

    /**
     * Get summary statistics for a file upload.
     */
    public static function getUploadSummary(int $fileUploadId): array
    {
        $query = static::where('file_upload_id', $fileUp