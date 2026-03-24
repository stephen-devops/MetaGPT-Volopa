<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * PocketExpenseUploadsData Model
 * 
 * Represents individual CSV row data for batch expense upload processing.
 * Each record stores one parsed CSV row with its processing status and
 * any errors that occurred during validation or expense creation.
 * 
 * This model uses Laravel standard timestamps (created_at, updated_at)
 * instead of Volopa legacy timestamp pattern since it's used for
 * temporary upload processing data rather than permanent business records.
 * 
 * @property int $id Primary key
 * @property int $upload_id Foreign key to pocket_expense_file_uploads
 * @property int $line_number Line number in original CSV file (starting from 2, after header)
 * @property string $status Processing status: pending, processing, completed, failed
 * @property array $expense_data JSON object containing parsed CSV row data with field mappings
 * @property array|null $processing_errors JSON array of processing errors if status=failed
 * @property int|null $created_expense_id Reference to pocket_expense.id if successfully processed
 * @property Carbon $created_at Laravel timestamp for record creation
 * @property Carbon $updated_at Laravel timestamp for last update
 * 
 * @property-read PocketExpenseFileUpload $upload Parent upload batch record
 * @property-read PocketExpense|null $createdExpense Created expense record if processing succeeded
 */
class PocketExpenseUploadsData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_uploads_data';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     * Uses Laravel standard timestamps (created_at, updated_at).
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'upload_id',
        'line_number',
        'status',
        'expense_data',
        'processing_errors',
        'created_expense_id',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'upload_id' => 'integer',
        'line_number' => 'integer',
        'status' => 'string',
        'expense_data' => 'array', // JSON field cast to array
        'processing_errors' => 'array', // JSON field cast to array, nullable
        'created_expense_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<string>
     */
    protected $hidden = [
        // No sensitive fields to hide for upload data processing
    ];

    /**
     * Default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'processing_errors' => null,
        'created_expense_id' => null,
    ];

    /**
     * Validation rules for status enum values.
     *
     * @var array<string>
     */
    public static array $statusOptions = [
        'pending',
        'processing', 
        'completed',
        'failed',
    ];

    /**
     * Get the parent upload batch that this data row belongs to.
     *
     * @return BelongsTo<PocketExpenseFileUpload, PocketExpenseUploadsData>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id', 'id');
    }

    /**
     * Get the created expense record if this data row was successfully processed.
     *
     * @return BelongsTo<PocketExpense, PocketExpenseUploadsData>
     */
    public function createdExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'created_expense_id', 'id');
    }

    /**
     * Scope to filter by upload batch ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $uploadId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUpload($query, int $uploadId)
    {
        return $query->where('upload_id', $uploadId);
    }

    /**
     * Scope to filter by processing status.
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
     * Scope to get pending records ready for processing.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get completed records that successfully created expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed')
                    ->whereNotNull('created_expense_id');
    }

    /**
     * Scope to get failed records with processing errors.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed')
                    ->whereNotNull('processing_errors');
    }

    /**
     * Scope to order by line number for processing in CSV order.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByLine($query)
    {
        return $query->orderBy('line_number', 'asc');
    }

    /**
     * Check if this data row is pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this data row is currently being processed.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if this data row completed successfully.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed' && $this->created_expense_id !== null;
    }

    /**
     * Check if this data row failed processing.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if this data row has processing errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->processing_errors);
    }

    /**
     * Mark this data row as processing.
     *
     * @return bool
     */
    public function markAsProcessing(): bool
    {
        $this->status = 'processing';
        return $this->save();
    }

    /**
     * Mark this data row as completed with the created expense ID.
     *
     * @param int $createdExpenseId
     * @return bool
     */
    public function markAsCompleted(int $createdExpenseId): bool
    {
        $this->status = 'completed';
        $this->created_expense_id = $createdExpenseId;
        $this->processing_errors = null; // Clear any previous errors
        return $this->save();
    }

    /**
     * Mark this data row as failed with processing errors.
     *
     * @param array $errors Array of error messages
     * @return bool
     */
    public function markAsFailed(array $errors): bool
    {
        $this->status = 'failed';
        $this->processing_errors = $errors;
        $this->created_expense_id = null; // Clear any previous expense reference
        return $this->save();
    }

    /**
     * Get the expense data for a specific field from the CSV row.
     *
     * @param string $field Field name (e.g., 'date', 'merchant_name', 'amount')
     * @param mixed $default Default value if field not found
     * @return mixed
     */
    public function getExpenseDataField(string $field, $default = null)
    {
        return $this->expense_data[$field] ?? $default;
    }

    /**
     * Set expense data for a specific field.
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @return void
     */
    public function setExpenseDataField(string $field, $value): void
    {
        $expenseData = $this->expense_data ?? [];
        $expenseData[$field] = $value;
        $this->expense_data = $expenseData;
    }

    /**
     * Add a processing error to the errors array.
     *
     * @param string $field Field name where error occurred
     * @param string $message Error message
     * @param mixed $value The invalid value that caused the error
     * @return void
     */
    public function addProcessingError(string $field, string $message, $value = null): void
    {
        $errors = $this->processing_errors ?? [];
        $errors[] = [
            'field' => $field,
            'message' => $message,
            'value' => $value,
            'line_number' => $this->line_number,
        ];
        $this->processing_errors = $errors;
    }

    /**
     * Clear all processing errors.
     *
     * @return void
     */
    public function clearProcessingErrors(): void
    {
        $this->processing_errors = null;
    }

    /**
     * Get formatted error summary for display.
     *
     * @return string
     */
    public function getErrorSummary(): string
    {
        if (!$this->hasErrors()) {
            return '';
        }

        $errorCount = count($this->processing_errors);
        $firstError = $this->processing_errors[0]['message'] ?? 'Unknown error';
        
        if ($errorCount === 1) {
            return $firstError;
        }
        
        return $firstError . " (and " . ($errorCount - 1) . " more error" . ($errorCount > 2 ? "s" : "") . ")";
    }

    /**
     * Get the original CSV line number for display purposes.
     * Adds 1 to account for header row in CSV file.
     *
     * @return int
     */
    public function getCsvLineNumber(): int
    {
        return $this->line_number + 1; // Add 1 for header row
    }

    /**
     * Convert expense data to format suitable for PocketExpense creation.
     * Maps CSV field names to database field names and applies any necessary transformations.
     *
     * @return array
     */
    public function getExpenseDataForCreation(): array
    {
        $expenseData = $this->expense_data ?? [];
        
        // Map CSV fields to database fields and apply transformations
        return [
            'date' => $expenseData['date'] ?? null,
            'merchant_name' => $expenseData['merchant_name'] ?? '',
            'merchant_description' => $expenseData['merchant_description'] ?? null,
            'expense_type' => $expenseData['expense_type_id'] ?? null,
            'currency' => $expenseData['currency'] ?? 'GBP',
            'amount' => $expenseData['amount'] ?? 0,
            'merchant_address' => $expenseData['merchant_address'] ?? null,
            'vat_amount' => $expenseData['vat_amount'] ?? null,
            'notes' => $expenseData['notes'] ?? null,
            'status' => 'draft', // All batch uploaded expenses start as draft
        ];
    }

    /**
     * Get metadata information extracted from expense data.
     * Returns array of metadata records to be created with the expense.
     *
     * @return array
     */
    public function getMetadataForCreation(): array
    {
        $expenseData = $this->expense_data ?? [];
        $metadata = [];
        
        // Category metadata
        if (!empty($expenseData['category_id'])) {
            $metadata[] = [
                'metadata_type' => 'category',
                'transaction_category_id' => $expenseData['category_id'],
                'details_json' => json_encode([
                    'category_name' => $expenseData['category_name'] ?? null,
                ]),
            ];
        }
        
        // Expense source metadata
        if (!empty($expenseData['expense_source_id'])) {
            $metadata[] = [
                'metadata_type' => 'expense_source',
                'expense_source_id' => $expenseData['expense_source_id'],
                'details_json' => json_encode([
                    'source_name' => $expenseData['expense_source_name'] ?? null,
                    'source_note' => $expenseData['expense_source_note'] ?? null,
                ]),
            ];
        }
        
        // Tracking code metadata
        if (!empty($expenseData['tracking_code_id'])) {
            $metadata[] = [
                'metadata_type' => 'tracking_code_type_1',
                'tracking_code_id' => $expenseData['tracking_code_id'],
                'details_json' => json_encode([
                    'tracking_code' => $expenseData['tracking_code_name'] ?? null,
                ]),
            ];
        }
        
        // Project metadata
        if (!empty($expenseData['project_id'])) {
            $metadata[] = [
                'metadata_type' => 'project',
                'project_id' => $expenseData['project_id'],
                'details_json' => json_encode([
                    'project_name' => $expenseData['project_name'] ?? null,
                ]),
            ];
        }
        
        return $metadata;
    }

    /**
     * Check if the status value is valid.
     *
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::$statusOptions);
    }

    /**
     * Boot method to set up model event listeners.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate status on saving
        static::saving(function (PocketExpenseUploadsData $model) {
            if (!self::isValidStatus($model->status)) {
                throw new \InvalidArgumentException("Invalid status: {$model->status}. Valid options are: " . implode(', ', self::$statusOptions));
            }
        });
    }
}