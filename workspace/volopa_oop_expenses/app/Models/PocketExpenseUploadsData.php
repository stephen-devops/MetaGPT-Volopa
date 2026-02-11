<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'upload_id',
        'row_number',
        'date',
        'merchant_name',
        'amount',
        'currency',
        'description',
        'expense_type',
        'category',
        'source',
        'project_code',
        'cost_center',
        'location',
        'tax_amount',
        'tax_rate',
        'receipt_reference',
        'custom_fields',
        'raw_row_data',
        'validation_status',
        'validation_errors',
        'is_processed',
        'pocket_expense_id',
        'processing_error',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'upload_id' => 'integer',
        'row_number' => 'integer',
        'date' => 'string',
        'merchant_name' => 'string',
        'amount' => 'string',
        'currency' => 'string',
        'description' => 'string',
        'expense_type' => 'string',
        'category' => 'string',
        'source' => 'string',
        'project_code' => 'string',
        'cost_center' => 'string',
        'location' => 'string',
        'tax_amount' => 'string',
        'tax_rate' => 'string',
        'receipt_reference' => 'string',
        'custom_fields' => 'array',
        'raw_row_data' => 'array',
        'validation_status' => 'string',
        'validation_errors' => 'array',
        'is_processed' => 'boolean',
        'pocket_expense_id' => 'integer',
        'processing_error' => 'string',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'validation_status' => 'pending',
        'is_processed' => false,
    ];

    /**
     * The possible validation status values.
     */
    const VALIDATION_STATUS_PENDING = 'pending';
    const VALIDATION_STATUS_VALID = 'valid';
    const VALIDATION_STATUS_INVALID = 'invalid';

    /**
     * Get all possible validation status values.
     */
    public static function getValidationStatusOptions(): array
    {
        return [
            self::VALIDATION_STATUS_PENDING,
            self::VALIDATION_STATUS_VALID,
            self::VALIDATION_STATUS_INVALID,
        ];
    }

    /**
     * Get the file upload that owns this data row.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
    }

    /**
     * Get the pocket expense created from this data row.
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Scope a query to only include data for a specific upload.
     */
    public function scopeForUpload($query, int $uploadId)
    {
        return $query->where('upload_id', $uploadId);
    }

    /**
     * Scope a query to filter by validation status.
     */
    public function scopeWithValidationStatus($query, string $validationStatus)
    {
        return $query->where('validation_status', $validationStatus);
    }

    /**
     * Scope a query to only include pending validation data.
     */
    public function scopePendingValidation($query)
    {
        return $query->where('validation_status', self::VALIDATION_STATUS_PENDING);
    }

    /**
     * Scope a query to only include valid data.
     */
    public function scopeValid($query)
    {
        return $query->where('validation_status', self::VALIDATION_STATUS_VALID);
    }

    /**
     * Scope a query to only include invalid data.
     */
    public function scopeInvalid($query)
    {
        return $query->where('validation_status', self::VALIDATION_STATUS_INVALID);
    }

    /**
     * Scope a query to only include processed data.
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * Scope a query to only include unprocessed data.
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Scope a query to only include data with processing errors.
     */
    public function scopeWithProcessingErrors($query)
    {
        return $query->whereNotNull('processing_error');
    }

    /**
     * Scope a query to only include data without processing errors.
     */
    public function scopeWithoutProcessingErrors($query)
    {
        return $query->whereNull('processing_error');
    }

    /**
     * Scope a query to only include data with validation errors.
     */
    public function scopeWithValidationErrors($query)
    {
        return $query->whereNotNull('validation_errors')
                    ->where('validation_errors', '!=', '[]');
    }

    /**
     * Scope a query to only include data without validation errors.
     */
    public function scopeWithoutValidationErrors($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('validation_errors')
              ->orWhere('validation_errors', '[]');
        });
    }

    /**
     * Scope a query to filter by row number range.
     */
    public function scopeRowNumberRange($query, int $startRow, int $endRow)
    {
        return $query->whereBetween('row_number', [$startRow, $endRow]);
    }

    /**
     * Scope a query to order by row number ascending.
     */
    public function scopeOrderedByRow($query)
    {
        return $query->orderBy('row_number', 'asc');
    }

    /**
     * Scope a query to order by processing status (unprocessed first).
     */
    public function scopeOrderedByProcessingStatus($query)
    {
        return $query->orderBy('is_processed', 'asc')->orderBy('row_number', 'asc');
    }

    /**
     * Check if the validation status is pending.
     */
    public function isPendingValidation(): bool
    {
        return $this->validation_status === self::VALIDATION_STATUS_PENDING;
    }

    /**
     * Check if the validation status is valid.
     */
    public function isValid(): bool
    {
        return $this->validation_status === self::VALIDATION_STATUS_VALID;
    }

    /**
     * Check if the validation status is invalid.
     */
    public function isInvalid(): bool
    {
        return $this->validation_status === self::VALIDATION_STATUS_INVALID;
    }

    /**
     * Check if the data row is processed.
     */
    public function isProcessed(): bool
    {
        return $this->is_processed;
    }

    /**
     * Check if the data row is unprocessed.
     */
    public function isUnprocessed(): bool
    {
        return !$this->is_processed;
    }

    /**
     * Mark the data as valid.
     */
    public function markAsValid(): bool
    {
        return $this->update([
            'validation_status' => self::VALIDATION_STATUS_VALID,
            'validation_errors' => [],
        ]);
    }

    /**
     * Mark the data as invalid.
     */
    public function markAsInvalid(array $errors = []): bool
    {
        return $this->update([
            'validation_status' => self::VALIDATION_STATUS_INVALID,
            'validation_errors' => $errors,
        ]);
    }

    /**
     * Mark the data as processed.
     */
    public function markAsProcessed(int $pocketExpenseId = null): bool
    {
        $updates = [
            'is_processed' => true,
            'processed_at' => now(),
            'processing_error' => null,
        ];

        if ($pocketExpenseId !== null) {
            $updates['pocket_expense_id'] = $pocketExpenseId;
        }

        return $this->update($updates);
    }

    /**
     * Mark the data as processing failed.
     */
    public function markAsProcessingFailed(string $error): bool
    {
        return $this->update([
            'is_processed' => false,
            'processing_error' => $error,
            'processed_at' => now(),
        ]);
    }

    /**
     * Add validation errors.
     */
    public function addValidationErrors(array $errors): bool
    {
        $currentErrors = $this->validation_errors ?? [];
        $updatedErrors = array_merge($currentErrors, $errors);

        return $this->update([
            'validation_errors' => $updatedErrors,
            'validation_status' => self::VALIDATION_STATUS_INVALID,
        ]);
    }

    /**
     * Clear validation errors.
     */
    public function clearValidationErrors(): bool
    {
        return $this->update([
            'validation_errors' => [],
        ]);
    }

    /**
     * Check if the data has validation errors.
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
     * Check if the data has processing error.
     */
    public function hasProcessingError(): bool
    {
        return !empty($this->processing_error);
    }

    /**
     * Get the custom field value by key.
     */
    public function getCustomFieldValue(string $key, mixed $default = null): mixed
    {
        $customFields = $this->custom_fields ?? [];
        return $customFields[$key] ?? $default;
    }

    /**
     * Set a custom field value.
     */
    public function setCustomFieldValue(string $key, mixed $value): bool
    {
        $customFields = $this->custom_fields ?? [];
        $customFields[$key] = $value;
        
        return $this->update(['custom_fields' => $customFields]);
    }

    /**
     * Update multiple custom fields.
     */
    public function updateCustomFields(array $newFields): bool
    {
        $customFields = array_merge($this->custom_fields ?? [], $newFields);
        
        return $this->update(['custom_fields' => $customFields]);
    }

    /**
     * Check if a custom field exists.
     */
    public function hasCustomField(string $key): bool
    {
        $customFields = $this->custom_fields ?? [];
        return array_key_exists($key, $customFields);
    }

    /**
     * Remove a custom field.
     */
    public function removeCustomField(string $key): bool
    {
        $customFields = $this->custom_fields ?? [];
        unset($customFields[$key]);
        
        return $this->update(['custom_fields' => $customFields]);
    }

    /**
     * Get all custom field keys.
     */
    public function getCustomFieldKeys(): array
    {
        $customFields = $this->custom_fields ?? [];
        return array_keys($customFields);
    }

    /**
     * Clear all custom fields.
     */
    public function clearCustomFields(): bool
    {
        return $this->update(['custom_fields' => []]);
    }

    /**
     * Get the raw row data value by key.
     */
    public function getRawRowDataValue(string $key, mixed $default = null): mixed
    {
        $rawRowData = $this->raw_row_data ?? [];
        return $rawRowData[$key] ?? $default;
    }

    /**
     * Set a raw row data value.
     */
    public function setRawRowDataValue(string $key, mixed $value): bool
    {
        $rawRowData = $this->raw_row_data ?? [];
        $rawRowData[$key] = $value;
        
        return $this->update(['raw_row_data' => $rawRowData]);
    }

    /**
     * Update raw row data.
     */
    public function updateRawRowData(array $newData): bool
    {
        $rawRowData = array_merge($this->raw_row_data ?? [], $newData);
        
        return $this->update(['raw_row_data' => $rawRowData]);
    }

    /**
     * Check if raw row data has a key.
     */
    public function hasRawRowDataKey(string $key): bool
    {
        $rawRowData = $this->raw_row_data ?? [];
        return array_key_exists($key, $rawRowData);
    }

    /**
     * Clear raw row data.
     */
    public function clearRawRowData(): bool
    {
        return $this->update(['raw_row_data' => []]);
    }

    /**
     * Get the processed amount as float.
     */
    public function getAmountAsFloat(): ?float
    {
        if (empty($this->amount)) {
            return null;
        }

        $cleanAmount = preg_replace('/[^\d.-]/', '', $this->amount);
        return is_numeric($cleanAmount) ? (float) $cleanAmount : null;
    }

    /**
     * Get the processed tax amount as float.
     */
    public function getTaxAmountAsFloat(): ?float
    {
        if (empty($this->tax_amount)) {
            return null;
        }

        $cleanAmount = preg_replace('/[^\d.-]/', '', $this->tax_amount);
        return is_numeric($cleanAmount) ? (float) $cleanAmount : null;
    }

    /**
     * Get the processed tax rate as float.
     */
    public function getTaxRateAsFloat(): ?float
    {
        if (empty($this->tax_rate)) {
            return null;
        }

        $cleanRate = preg_replace('/[^\d.-]/', '', $this->tax_rate);
        return is_numeric($cleanRate) ? (float) $cleanRate : null;
    }

    /**
     * Get the processed date as Carbon instance.
     */
    public function getDateAsCarbon(): ?\Carbon\Carbon
    {
        if (empty($this->date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($this->date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if the date is valid.
     */
    public function hasValidDate(): bool
    {
        return $this->getDateAsCarbon() !== null;
    }

    /**
     * Check if the amount is valid.
     */
    public function hasValidAmount(): bool
    {
        return $this->getAmountAsFloat() !== null;
    }

    /**
     * Check if required fields are present.
     */
    public function hasRequiredFields(): bool
    {
        return !empty($this->date) && 
               !empty($this->merchant_name) && 
               !empty($this->amount) && 
               !empty($this->currency);
    }

    /**
     * Get missing required fields.
     */
    public function getMissingRequiredFields(): array
    {
        $missing = [];

        if (empty($this->date)) {
            $missing[] = 'date';
        }

        if (empty($this->merchant_name)) {
            $missing[] = 'merchant_name';
        }

        if (empty($this->amount)) {
            $missing[] = 'amount';
        }

        if (empty($this->currency)) {
            $missing[] = 'currency';
        }

        return $missing;
    }

    /**
     * Convert the data row to expense data array.
     */
    public function toExpenseData(): array
    {
        return [
            'date' => $this->date,
            'merchant_name' => $this->merchant_name,
            'amount' => $this->getAmountAsFloat(),
            'currency' => $this->currency,
            'description' => $this->description,
            'expense_type' => $this->expense_type,
            'category' => $this->category,
            'source' => $this->source,
            'project_code' => $this->project_code,
            'cost_center' => $this->cost_center,
            'location' => $this->location,
            'tax_amount' => $this->getTaxAmountAsFloat(),
            'tax_rate' => $this->getTaxRateAsFloat(),
            'receipt_reference' => $this->receipt_reference,
            'custom_fields' => $this->custom_fields,
            'raw_data' => $this->raw_row_data,
        ];
    }

    /**
     * Get data validation summary.
     */
    public function getValidationSummary(): array
    {
        return [
            'row_number' => $this->row_number,
            'validation_status' => $this->validation_status,
            'is_valid' => $this->isValid(),
            'has_required_fields' => $this->hasRequiredFields(),
            'missing_required_fields' => $this->getMissingRequiredFields(),
            'has_valid_date' => $this->hasValidDate(),
            'has_valid_amount' => $this->hasValidAmount(),
            'has_validation_errors' => $this->hasValidationErrors(),
            'validation_error_count' => $this->getValidationErrorCount(),
            'validation_errors' => $this->validation_errors ?? [],
        ];
    }

    /**
     * Get processing summary.
     */
    public function getProcessingSummary(): array
    {
        return [
            'row_number' => $this->row_number,
            'is_processed' => $this->isProcessed(),
            'has_processing_error' => $this->hasProcessingError(),
            'processing_error' => $this->processing_error,
            'pocket_expense_id' => $this->pocket_expense_id,
            'processed_at' => $this->processed_at,
        ];
    }

    /**
     * Bulk create upload data rows.
     */
    public static function bulkCreateForUpload(int $uploadId, array $dataRows): bool
    {
        try {
            $insertData = [];
            $currentTimestamp = now();

            foreach ($dataRows as $rowNumber => $rowData) {
                $insertData[] = array_merge($rowData, [
                    'upload_id' => $uploadId,
                    'row_number' => $rowNumber + 1, // Start from 1
                    'created_at' => $currentTimestamp,
                    'updated_at' => $currentTimestamp,
                ]);
            }

            if (!empty($insertData)) {
                // Split into chunks to avoid SQL query size limits
                $chunks = array_chunk($insertData, 100);
                foreach ($chunks as $chunk) {
                    static::insert($chunk);
                }
                return true;
            }
        } catch (\Exception $e) {
            // Log the exception if needed
        }

        return false;
    }

    /**
     * Get data rows for a specific upload with statistics.
     */
    public static function getDataWithStatsForUpload(int $uploadId): array
    {
        $dataRows = static::forUpload($uploadId)
            ->orderedByRow()
            ->get();

        return [
            'data_rows' => $dataRows,
            'total_rows' => $dataRows->count(),
            'valid_rows' => $dataRows->where('validation_status', self::VALIDATION_STATUS_VALID)->count(),
            'invalid_rows' => $dataRows->where('validation_status', self::VALIDATION_STATUS_INVALID)->count(),
            'pending_rows' => $dataRows->where('validation_status', self::VALIDATION_STATUS_PENDING)->count(),
            'processed_rows' => $dataRows->where('is_processed', true)->count(),
            'unprocessed_rows' => $dataRows->where('is_processed', false)->count(),
            'rows_with_errors' => $dataRows->filter(function ($row) {
                return $row->hasValidationErrors() || $row->hasProcessingError();
            })->count(),
        ];
    }

    /**
     * Get validation statistics for an upload.
     */
    public static function getValidationStatsForUpload(int $uploadId): array
    {
        $dataRows = static::forUpload($uploadId)->get();

        $stats = [
            'total_rows' => $dataRows->count(),
            'validation_status' => [],
            'field_errors' => [],
            'common_errors' => [],
        ];

        // Count by validation status
        foreach (static::getValidationStatusOptions() as $status) {
            $stats['validation_status'][$status] = $dataRows->where('validation_status', $status)->count();
        }

        // Analyze field-specific errors
        $fieldErrors = [];
        foreach ($dataRows as $row) {
            if ($row->hasValidationErrors()) {
                foreach ($row->validation_errors as $error) {
                    if (isset($error['field'])) {
                        $field = $error['field'];
                        if (!isset($fieldErrors[$field])) {
                            $fieldErrors[$field] = 0;
                        }
                        $fieldErrors[$field]++;
                    }
                }
            }
        }

        $stats['field_errors'] = $fieldErrors;

        return $stats;
    }

    /**
     * Get processing statistics for an upload.
     */
    public static function getProcessingStatsForUpload(int $uploadId): array
    {
        $dataRows = static::forUpload($uploadId)->get();

        return [
            'total_rows' => $dataRows->count(),
            'processed_rows' => $dataRows->where('is_processed', true)->count(),
            'unprocessed_rows' => $dataRows->where('is_processed', false)->count(),
            'rows_with_processing_errors' => $dataRows->whereNotNull('processing_error')->count(),
            'successfully_created_expenses' => $dataRows->whereNotNull('pocket_expense_id')->count(),
        ];
    }

    /**
     * Reset validation status for an upload.
     */
    public static function resetValidationForUpload(int $uploadId): int
    {
        return static::forUpload($uploadId)->update([
            'validation_status' => self::VALIDATION_STATUS_PENDING,
            'validation_errors' => [],
        ]);
    }

    /**
     * Reset processing status for an upload.
     */
    public static function resetProcessingForUpload(int $uploadId): int
    {
        return static::forUpload($uploadId)->update([
            'is_processed' => false,
            'pocket_expense_id' => null,
            'processing_error' => null,
            'processed_at' => null,
        ]);
    }

    /**
     * Delete data rows for a specific upload.
     */
    public static function deleteDataForUpload(int $uploadId): int
    {
        return static::forUpload($uploadId)->delete();
    }

    /**
     * Get the next unprocessed valid data rows for an upload.
     */
    public static function getNextUnprocessedForUpload(int $uploadId, int $limit = 100): \Illuminate\Support\Collection
    {
        return static::forUpload($uploadId)
            ->valid()
            ->unprocessed()
            ->orderedByRow()
            ->limit($limit)
            ->get();
    }

    /**
     * Get data rows with processing errors for an upload.
     */
    public static function getProcessingErrorsForUpload(int $uploadId): \Illuminate\Support\Collection
    {
        return static::forUpload($uploadId)
            ->withProcessingErrors()
            ->orderedByRow()
            ->get();
    }

    /**
     * Get data rows with validation errors for an upload.
     */
    public static function getValidationErrorsForUpload(int $uploadId): \Illuminate\Support\Collection
    {
        return static::forUpload($uploadId)
            ->invalid()
            ->withValidationErrors()
            ->orderedByRow()
            ->get();
    }

    /**
     * Mark multiple rows as processed in batch.
     */
    public static function batchMarkAsProcessed(array $rowIds, array $pocketExpenseIds = []): int
    {
        $updates = [
            'is_processed' => true,
            'processed_at' => now(),
            'processing_error' => null,
        ];

        $query = static::whereIn('id', $rowIds);

        // If pocket expense IDs provided, update them in order
        if (!empty($pocketExpenseIds) && count($pocketExpenseIds) === count($rowIds)) {
            $updated = 0;
            foreach ($rowIds as $index => $rowId) {
                if (isset($pocketExpenseIds[$index])) {
                    $rowUpdates = $updates;
                    $rowUpdates['pocket_expense_id'] = $pocketExpenseIds[$index];
                    static::where('id', $rowId)->update($rowUpdates);
                    $updated++;
                }
            }
            return $updated;
        }

        return $query->update($updates);
    }

    /**
     * Mark multiple rows as processing failed in batch.
     */
    public static function batchMarkAsProcessingFailed(array $rowIds, string $error): int
    {
        return static::whereIn('id', $rowIds)->update([
            'is_processed' => false,
            'processing_error' => $error,
            'processed_at' => now(),
        ]);
    }

    /**
     * Clean up orphaned data (where upload_id doesn't exist).
     */
    public static function cleanupOrphaned(): int
    {
        return static::whereNotExists(function ($query) {
            $query->select('id')
                ->from('pocket_expense_file_uploads')
                ->whereRaw('pocket_expense_file_uploads.id = pocket_expense_uploads_data.upload_id');
        })->delete();
    }

    /**
     * Get summary statistics across all uploads.
     */
    public static function getGlobalStatistics(): array
    {
        $total = static::count();

        if ($total === 0) {
            return [
                'total_rows' => 0,
                'by_validation_status' => [],
                'processed_percentage' => 0,
                'success_rate' => 0,
            ];
        }

        $byValidationStatus = static::selectRaw('validation_status, COUNT(*) as count')
            ->groupBy('validation_status')
            ->pluck('count', 'validation_status')
            ->toArray();

        $processedCount = static::where('is_processed', true)->count();
        $successfulCount = static::whereNotNull('pocket_expense_id')->count();

        return [
            'total_rows' => $total,
            'by_validation_status' => $byValidationStatus,
            'processed_percentage' => round(($processedCount / $total) * 100, 2),
            'success_rate' => $processedCount > 0 ? round(($successfulCount / $processedCount) * 100, 2) : 0,
        ];
    }

    /**
     * Find data rows that need reprocessing.
     */
    public static function findRowsNeedingReprocessing(): \Illuminate\Support\Collection
    {
        return static::where('is_processed', false)
            ->where('validation_status', self::VALIDATION_STATUS_VALID)
            ->whereNull('processing_error')
            ->orderedByProcessingStatus()
            ->get();
    }

    /**
     * Get duplicate data detection for an upload.
     */
    public static function findDuplicatesInUpload(int $uploadId): array
    {
        $dataRows = static::forUpload($uploadId)->get();
        $duplicates = [];
        $seen = [];

        foreach ($dataRows as $row) {
            $key = $row->date . '|' . $row->merchant_name . '|' . $row->amount . '|' . $row->currency;
            
            if (isset($seen[$key])) {
                if (!isset($duplicates[$key])) {
                    $duplicates[$key] = [$seen[$key]];
                }
                $duplicates[$key][] = $row;
            } else {
                $seen[$key] = $row;
            }
        }

        return $duplicates;
    }

    /**
     * Export data rows to array format.
     */
    public static function exportDataForUpload(int $uploadId): array
    {
        return static::forUpload($uploadId)
            ->orderedByRow()
            ->get()
            ->map(function ($row) {
                return $row->toExpenseData();
            })
            ->toArray();
    }

    /**
     * Get processing progress for an upload.
     */
    public static function getProcessingProgressForUpload(int $uploadId): array
    {
        $total = static::forUpload($uploadId)->count();
        $processed = static::forUpload($uploadId)->processed()->count();
        $successful = static::forUpload($uploadId)->whereNotNull('pocket_expense_id')->count();
        $failed = static::forUpload($uploadId)->withProcessingErrors()->count();

        return [
            'total_rows' => $total,
            'processed_rows' => $processed,
            'successful_rows' => $successful,
            'failed_rows' => $failed,
            'remaining_rows' => $total - $processed,
            'progress_percentage' => $total > 0 ? round(($processed / $total) * 100, 2) : 0,
            'success_rate' => $processed > 0 ? round(($successful / $processed) * 100, 2) : 0,
        ];
    }
}