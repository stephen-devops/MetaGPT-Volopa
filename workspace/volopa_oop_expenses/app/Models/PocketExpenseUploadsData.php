<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * PocketExpenseUploadsData Model
 * 
 * Manages individual CSV row data during the batch upload process. This table contains
 * the raw expense data from each CSV row along with its validation status and line number tracking.
 * Each record represents one row from an uploaded CSV file and its processing status.
 * Uses Laravel's built-in timestamps (created_at/updated_at).
 * 
 * @property int $id Primary key for upload data record
 * @property int $upload_id Foreign key to pocket_expense_file_uploads table
 * @property int $line_number Line number in the CSV file (excluding header row)
 * @property string $status Processing status of this individual CSV row
 * @property array $expense_data JSON object containing the parsed expense data from the CSV row
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PocketExpenseFileUpload $upload
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
     * Uses Laravel's built-in timestamps.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'upload_id',
        'line_number',
        'status',
        'expense_data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'upload_id' => 'integer',
        'line_number' => 'integer',
        'status' => 'string',
        'expense_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Valid status values for individual CSV row processing.
     *
     * @var array<int, string>
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    /**
     * All valid status values.
     *
     * @var array<int, string>
     */
    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VALID,
        self::STATUS_INVALID,
        self::STATUS_PROCESSED,
        self::STATUS_FAILED,
    ];

    /**
     * Status transitions allowed in the workflow.
     *
     * @var array<string, array<int, string>>
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_VALID, self::STATUS_INVALID],
        self::STATUS_VALID => [self::STATUS_PROCESSED, self::STATUS_FAILED],
        self::STATUS_INVALID => [], // Invalid rows cannot transition to other states
        self::STATUS_PROCESSED => [], // Processed rows are final
        self::STATUS_FAILED => [self::STATUS_VALID], // Failed rows can be retried
    ];

    /**
     * Expected CSV column names for expense data.
     *
     * @var array<int, string>
     */
    public const EXPECTED_CSV_COLUMNS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'Merchant Address',
        'VAT Amount',
        'VAT %',
        'Notes',
        'Source',
        'Source Note',
    ];

    /**
     * Required CSV columns that must have values.
     *
     * @var array<int, string>
     */
    public const REQUIRED_CSV_COLUMNS = [
        'Date',
        'Merchant Name',
        'Expense Type',
        'Currency',
        'Amount',
    ];

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Validate status values
        static::saving(function (PocketExpenseUploadsData $model) {
            if (!self::isValidStatus($model->status)) {
                throw new \InvalidArgumentException(
                    "Invalid status value: {$model->status}. Must be one of: " . 
                    implode(', ', self::VALID_STATUSES)
                );
            }
        });

        // Validate status transitions
        static::updating(function (PocketExpenseUploadsData $model) {
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
    }

    /**
     * Get the upload that owns this upload data record.
     *
     * @return BelongsTo<PocketExpenseFileUpload, PocketExpenseUploadsData>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
    }

    /**
     * Scope a query to filter by specific upload.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @param int $uploadId
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeForUpload(Builder $query, int $uploadId): Builder
    {
        return $query->where('upload_id', $uploadId);
    }

    /**
     * Scope a query to filter by line number.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @param int $lineNumber
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeByLineNumber(Builder $query, int $lineNumber): Builder
    {
        return $query->where('line_number', $lineNumber);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @param string $status
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include pending records.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include valid records.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALID);
    }

    /**
     * Scope a query to only include invalid records.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeInvalid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_INVALID);
    }

    /**
     * Scope a query to only include processed records.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Scope a query to only include failed records.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to get records ready for processing (valid status).
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeReadyForProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALID);
    }

    /**
     * Scope a query to get records with errors (invalid or failed).
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_INVALID, self::STATUS_FAILED]);
    }

    /**
     * Scope a query to get successfully processed records.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeSuccessfullyProcessed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Scope a query to order by line number.
     *
     * @param Builder<PocketExpenseUploadsData> $query
     * @param string $direction
     * @return Builder<PocketExpenseUploadsData>
     */
    public function scopeOrderByLineNumber(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('line_number', $direction);
    }

    /**
     * Check if the record is in pending status.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the record is valid.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /**
     * Check if the record is invalid.
     *
     * @return bool
     */
    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID;
    }

    /**
     * Check if the record is processed.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Check if the record failed processing.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the record is ready for processing.
     *
     * @return bool
     */
    public function isReadyForProcessing(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /**
     * Check if the record has errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return in_array($this->status, [self::STATUS_INVALID, self::STATUS_FAILED]);
    }

    /**
     * Check if the record was successfully processed.
     *
     * @return bool
     */
    public function wasSuccessfullyProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Check if the record can be retried.
     *
     * @return bool
     */
    public function canBeRetried(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark the record as valid.
     *
     * @return bool
     */
    public function markAsValid(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->status = self::STATUS_VALID;
        return $this->save();
    }

    /**
     * Mark the record as invalid.
     *
     * @param array|null $validationErrors
     * @return bool
     */
    public function markAsInvalid(?array $validationErrors = null): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->status = self::STATUS_INVALID;
        
        if ($validationErrors !== null) {
            $this->addValidationErrors($validationErrors);
        }
        
        return $this->save();
    }

    /**
     * Mark the record as processed.
     *
     * @return bool
     */
    public function markAsProcessed(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->status = self::STATUS_PROCESSED;
        return $this->save();
    }

    /**
     * Mark the record as failed.
     *
     * @param array|null $errors
     * @return bool
     */
    public function markAsFailed(?array $errors = null): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->status = self::STATUS_FAILED;
        
        if ($errors !== null) {
            $this->addValidationErrors($errors);
        }
        
        return $this->save();
    }

    /**
     * Reset the record to valid status for retry.
     *
     * @return bool
     */
    public function resetForRetry(): bool
    {
        if (!$this->canBeRetried()) {
            return false;
        }

        $this->status = self::STATUS_VALID;
        $this->clearValidationErrors();
        
        return $this->save();
    }

    /**
     * Get expense data field value.
     *
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    public function getExpenseDataField(string $field, $default = null)
    {
        return $this->expense_data[$field] ?? $default;
    }

    /**
     * Set expense data field value.
     *
     * @param string $field
     * @param mixed $value
     * @return void
     */
    public function setExpenseDataField(string $field, $value): void
    {
        $data = $this->expense_data ?? [];
        $data[$field] = $value;
        $this->expense_data = $data;
    }

    /**
     * Remove expense data field.
     *
     * @param string $field
     * @return void
     */
    public function removeExpenseDataField(string $field): void
    {
        $data = $this->expense_data ?? [];
        unset($data[$field]);
        $this->expense_data = empty($data) ? [] : $data;
    }

    /**
     * Check if expense data has a specific field.
     *
     * @param string $field
     * @return bool
     */
    public function hasExpenseDataField(string $field): bool
    {
        return isset($this->expense_data[$field]);
    }

    /**
     * Get validation errors from expense data.
     *
     * @return array<int, mixed>
     */
    public function getValidationErrors(): array
    {
        return $this->getExpenseDataField('validation_errors', []);
    }

    /**
     * Add validation errors to expense data.
     *
     * @param array<int, mixed> $errors
     * @return void
     */
    public function addValidationErrors(array $errors): void
    {
        $existingErrors = $this->getValidationErrors();
        $allErrors = array_merge($existingErrors, $errors);
        $this->setExpenseDataField('validation_errors', $allErrors);
    }

    /**
     * Add a single validation error to expense data.
     *
     * @param string $field
     * @param string $message
     * @param string|null $code
     * @return void
     */
    public function addValidationError(string $field, string $message, ?string $code = null): void
    {
        $error = [
            'field' => $field,
            'message' => $message,
            'line_number' => $this->line_number,
            'timestamp' => now()->toISOString(),
        ];
        
        if ($code !== null) {
            $error['code'] = $code;
        }
        
        $this->addValidationErrors([$error]);
    }

    /**
     * Clear validation errors from expense data.
     *
     * @return void
     */
    public function clearValidationErrors(): void
    {
        $this->removeExpenseDataField('validation_errors');
    }

    /**
     * Check if the record has validation errors.
     *
     * @return bool
     */
    public function hasValidationErrors(): bool
    {
        $errors = $this->getValidationErrors();
        return !empty($errors);
    }

    /**
     * Get the count of validation errors.
     *
     * @return int
     */
    public function getValidationErrorsCount(): int
    {
        return count($this->getValidationErrors());
    }

    /**
     * Get validation errors grouped by field.
     *
     * @return array<string, array<int, mixed>>
     */
    public function getValidationErrorsGroupedByField(): array
    {
        $errors = $this->getValidationErrors();
        $grouped = [];
        
        foreach ($errors as $error) {
            $field = $error['field'] ?? 'general';
            if (!isset($grouped[$field])) {
                $grouped[$field] = [];
            }
            $grouped[$field][] = $error;
        }
        
        return $grouped;
    }

    /**
     * Get parsed expense data for processing.
     * Extracts the core expense fields from the CSV data.
     *
     * @return array<string, mixed>
     */
    public function getParsedExpenseData(): array
    {
        $data = $this->expense_data ?? [];
        
        return [
            'date' => $this->parseDate($data['Date'] ?? null),
            'merchant_name' => $this->sanitizeString($data['Merchant Name'] ?? ''),
            'merchant_description' => $this->sanitizeString($data['Merchant Description'] ?? null),
            'expense_type' => $this->parseExpenseType($data['Expense Type'] ?? null),
            'currency' => $this->sanitizeString($data['Currency'] ?? ''),
            'amount' => $this->parseAmount($data['Amount'] ?? null),
            'merchant_address' => $this->sanitizeString($data['Merchant Address'] ?? null),
            'vat_amount' => $this->parseAmount($data['VAT Amount'] ?? null),
            'notes' => $this->sanitizeString($data['Notes'] ?? null),
            'source' => $this->sanitizeString($data['Source'] ?? null),
            'source_note' => $this->sanitizeString($data['Source Note'] ?? null),
        ];
    }

    /**
     * Parse date from CSV string.
     *
     * @param string|null $dateString
     * @return Carbon|null
     */
    protected function parseDate(?string $dateString): ?Carbon
    {
        if (empty($dateString)) {
            return null;
        }
        
        try {
            // Expected format: DD/MM/YYYY
            return Carbon::createFromFormat('d/m/Y', trim($dateString));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse amount from CSV string.
     *
     * @param string|null $amountString
     * @return float|null
     */
    protected function parseAmount(?string $amountString): ?float
    {
        if (empty($amountString)) {
            return null;
        }
        
        // Remove currency symbols, spaces, and convert to float
        $cleaned = preg_replace('/[^\d.,\-]/', '', trim($amountString));
        
        if (empty($cleaned)) {
            return null;
        }
        
        // Handle comma as decimal separator
        if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') === false) {
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) {
            // Both comma and dot present, assume comma is thousands separator
            $cleaned = str_replace(',', '', $cleaned);
        }
        
        return (float) $cleaned;
    }

    /**
     * Parse expense type from CSV string.
     *
     * @param string|null $expenseTypeString
     * @return int|null
     */
    protected function parseExpenseType(?string $expenseTypeString): ?int
    {
        if (empty($expenseTypeString)) {
            return null;
        }
        
        // Try to find matching expense type by name
        $expenseType = OptPocketExpenseType::findByOption(trim($expenseTypeString));
        
        return $expenseType ? $expenseType->id : null;
    }

    /**
     * Sanitize string value from CSV.
     *
     * @param string|null $value
     * @return string|null
     */
    protected function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        $sanitized = trim($value);
        
        if ($sanitized === '') {
            return null;
        }
        
        // Remove potential SQL injection attempts and clean the string
        return htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate the CSV row data against expected format.
     *
     * @return array<int, array<string, mixed>>
     */
    public function validateRowData(): array
    {
        $errors = [];
        $data = $this->expense_data ?? [];
        
        // Check required fields
        foreach (self::REQUIRED_CSV_COLUMNS as $requiredField) {
            if (empty($data[$requiredField])) {
                $errors[] = [
                    'field' => $requiredField,
                    'message' => "Required field '{$requiredField}' is missing or empty",
                    'code' => 'required_field_missing',
                ];
            }
        }
        
        // Validate date format
        if (!empty($data['Date'])) {
            $parsedDate = $this->parseDate($data['Date']);
            if ($parsedDate === null) {
                $errors[] = [
                    'field' => 'Date',
                    'message' => 'Date must be in DD/MM/YYYY format',
                    'code' => 'invalid_date_format',
                ];
            } elseif ($parsedDate->lt(now()->subYears(3))) {
                $errors[] = [
                    'field' => 'Date',
                    'message' => 'Date cannot be older than 3 years',
                    'code' => 'date_too_old',
                ];
            } elseif ($parsedDate->gt(now())) {
                $errors[] = [
                    'field' => 'Date',
                    'message' => 'Date cannot be in the future',
                    'code' => 'future_date',
                ];
            }
        }
        
        // Validate merchant name length
        if (!empty($data['Merchant Name'])) {
            if (strlen($data['Merchant Name']) > PocketExpense::MAX_MERCHANT_NAME_LENGTH) {
                $errors[] = [
                    'field' => 'Merchant Name',
                    'message' => 'Merchant name cannot exceed ' . PocketExpense::MAX_MERCHANT_NAME_LENGTH . ' characters',
                    'code' => 'merchant_name_too_long',
                ];
            }
        }
        
        // Validate currency code
        if (!empty($data['Currency'])) {
            if (strlen($data['Currency']) !== 3) {
                $errors[] = [
                    'field' => 'Currency',
                    'message' => 'Currency must be a 3-letter ISO code',
                    'code' => 'invalid_currency_format',
                ];
            }
        }
        
        // Validate amount
        if (!empty($data['Amount'])) {
            $parsedAmount = $this->parseAmount($data['Amount']);
            if ($parsedAmount === null) {
                $errors[] = [
                    'field' => 'Amount',
                    'message' => 'Amount must be a valid number',
                    'code' => 'invalid_amount_format',
                ];
            } elseif ($parsedAmount == 0) {
                $errors[] = [
                    'field' => 'Amount',
                    'message' => 'Amount cannot be zero',
                    'code' => 'zero_amount',
                ];
            }
        }
        
        // Validate VAT percentage if present
        if (!empty($data['VAT %'])) {
            $vatPercentage = str_replace('%', '', trim($data['VAT %']));
            if (!is_numeric($vatPercentage)) {
                $errors[] = [
                    'field' => 'VAT %',
                    'message' => 'VAT percentage must be numeric',
                    'code' => 'invalid_vat_percentage',
                ];
            } elseif ($vatPercentage < 0 || $vatPercentage > 100) {
                $errors[] = [
                    'field' => 'VAT %',
                    'message' => 'VAT percentage must be between 0 and 100',
                    'code' => 'vat_percentage_out_of_range',
                ];
            }
        }
        
        // Validate expense type
        if (!empty($data['Expense Type'])) {
            $expenseTypeId = $this->parseExpenseType($data['Expense Type']);
            if ($expenseTypeId === null) {
                $errors[] = [
                    'field' => 'Expense Type',
                    'message' => "Unknown expense type: {$data['Expense Type']}",
                    'code' => 'unknown_expense_type',
                ];
            }
        }
        
        // Validate source note requirement
        if (!empty($data['Source']) && trim($data['Source']) === 'Other') {
            if (empty($data['Source Note'])) {
                $errors[] = [
                    'field' => 'Source Note',
                    'message' => 'Source Note is required when Source is "Other"',
                    'code' => 'source_note_required',
                ];
            }
        }
        
        return $errors;
    }

    /**
     * Get a human-readable description of this upload data record.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $uploadFileName = $this->upload->file_name ?? 'Unknown File';
        $merchantName = $this->getExpenseDataField('Merchant Name', 'Unknown Merchant');
        $statusLabel = ucfirst($this->status);
        
        return "Line {$this->line_number} from {$uploadFileName}: {$merchantName} - {$statusLabel}";
    }

    /**
     * Get formatted row summary for display.
     *
     * @return array<string, mixed>
     */
    public function getRowSummary(): array
    {
        $data = $this->expense_data ?? [];
        
        return [
            'line_number' => $this->line_number,
            'status' => $this->status,
            'status_label' => ucfirst($this->status),
            'merchant_name' => $data['Merchant Name'] ?? 'N/A',
            'expense_type' => $data['Expense Type'] ?? 'N/A',
            'amount' => $data['Amount'] ?? 'N/A',
            'currency' => $data['Currency'] ?? 'N/A',
            'date' => $data['Date'] ?? 'N/A',
            'has_validation_errors' => $this->hasValidationErrors(),
            'validation_errors_count' => $this->getValidationErrorsCount(),
        ];
    }

    /**
     * Check if the record belongs to a specific upload.
     *
     * @param int $uploadId
     * @return bool
     */
    public function belongsToUpload(int $uploadId): bool
    {
        return $this->upload_id === $uploadId;
    }

    /**
     * Find upload data by upload ID and line number.
     *
     * @param int $uploadId
     * @param int $lineNumber
     * @return PocketExpenseUploadsData|null
     */
    public static function findByUploadAndLine(int $uploadId, int $lineNumber): ?PocketExpenseUploadsData
    {
        return static::forUpload($uploadId)->byLineNumber($lineNumber)->first();
    }

    /**
     * Get all upload data for a specific upload.
     *
     * @param int $uploadId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseUploadsData>
     */
    public static function getForUpload(int $uploadId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUpload($uploadId)->orderByLineNumber()->get();
    }

    /**
     * Get upload data by status for a specific upload.
     *
     * @param int $uploadId
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseUploadsData>
     */
    public static function getByStatusForUpload(int $uploadId, string $status): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUpload($uploadId)->byStatus($status)->orderByLineNumber()->get();
    }

    /**
     * Get records ready for processing for a specific upload.
     *
     * @param int $uploadId
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseUploadsData>
     */
    public static function getReadyForProcessingByUpload(int $uploadId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUpload($uploadId)->readyForProcessing()->orderByLineNumber()->get();
    }

    /**
     * Get upload data grouped by status for a specific upload.
     *
     * @param int $uploadId
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, PocketExpenseUploadsData>>
     */
    public static function getGroupedByStatusForUpload(int $uploadId): array
    {
        $data = static::getForUpload($uploadId);

        return [
            'pending' => $data->filter(fn($record) => $record->isPending()),
            'valid' => $data->filter(fn($record) => $record->isValid()),
            'invalid' => $data->filter(fn($record) => $record->isInvalid()),
            'processed' => $data->filter(fn($record) => $record->isProcessed()),
            'failed' => $data->filter(fn($record) => $record->isFailed()),
        ];
    }

    /**
     * Get processing summary for a specific upload.
     *
     * @param int $uploadId
     * @return array<string, mixed>
     */
    public static function getProcessingSummaryForUpload(int $uploadId): array
    {
        $data = static::getForUpload($uploadId);
        
        $totalRecords = $data->count();
        $validRecords = $data->where('status', self::STATUS_VALID)->count();
        $invalidRecords = $data->where('status', self::STATUS_INVALID)->count();
        $processedRecords = $data->where('status', self::STATUS_PROCESSED)->count();
        $failedRecords = $data->where('status', self::STATUS_FAILED)->count();
        $pendingRecords = $data->where('status', self::STATUS_PENDING)->count();
        
        return [
            'total_records' => $totalRecords,
            'valid_records' => $validRecords,
            'invalid_records' => $invalidRecords,
            'processed_records' => $processedRecords,
            'failed_records' => $failedRecords,
            'pending_records' => $pendingRecords,
            'success_rate' => $totalRecords > 0 ? ($validRecords / $totalRecords) * 100 : 0,
            'processing_rate' => $validRecords > 0 ? ($processedRecords / $validRecords) * 100 : 0,
        ];
    }

    /**
     * Bulk create upload data records from CSV rows.
     *
     * @param int $uploadId
     * @param array<int, array<string, mixed>> $csvRows
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseUploadsData>
     */
    public static function createFromCsvRows(int $uploadId, array $csvRows): \Illuminate\Database\Eloquent\Collection
    {
        $records = collect();
        
        foreach ($csvRows as $lineNumber => $rowData) {
            $record = static::create([
                'upload_id' => $uploadId,
                'line_number' => $lineNumber,
                'status' => self::STATUS_PENDING,
                'expense_data' => $rowData,
            ]);
            
            $records->push($record);
        }
        
        return $records;
    }

    /**
     * Bulk update status for multiple records.
     *
     * @param array<int, int> $recordIds
     * @param string $status
     * @return int Number of affected records
     */
    public static function bulkUpdateStatus(array $recordIds, string $status): int
    {
        if (!self::isValidStatus($status)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        
        return static::whereIn('id', $recordIds)->update(['status' => $status]);
    }

    /**
     * Delete all upload data for a specific upload.
     *
     * @param int $uploadId
     * @return int Number of deleted records
     */
    public static function deleteForUpload(int $uploadId): int
    {
        return static::forUpload($uploadId)->delete();
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
            array_map(fn($status) => ucfirst($status), self::VALID_STATUSES)
        );
    }

    /**
     * Get CSV column mapping for validation.
     *
     * @return array<int, string>
     */
    public static function getCsvColumnMapping(): array
    {
        return self::EXPECTED_CSV_COLUMNS;
    }

    /**
     * Get required CSV columns.
     *
     * @return array<int, string>
     */
    public static function getRequiredCsvColumns(): array
    {
        return self::REQUIRED_CSV_COLUMNS;
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
        $array['is_pending'] = $this->isPending();
        $array['is_valid'] = $this->isValid();
        $array['is_invalid'] = $this->isInvalid();
        $array['is_processed'] = $this->isProcessed();
        $array['is_failed'] = $this->isFailed();
        $array['is_ready_for_processing'] = $this->isReadyForProcessing();
        $array['has_errors'] = $this->hasErrors();
        $array['was_successfully_processed'] = $this->wasSuccessfullyProcessed();
        $array['can_be_retried'] = $this->canBeRetried();
        $array['has_validation_errors'] = $this->hasValidationErrors();
        $array['validation_errors_count'] = $this->getValidationErrorsCount();
        $array['validation_errors'] = $this->getValidationErrors();
        $array['validation_errors_grouped'] = $this->getValidationErrorsGroupedByField();
        $array['parsed_expense_data'] = $this->getParsedExpenseData();
        $array['description'] = $this->getDescription();
        $array['row_summary'] = $this->getRowSummary();
        $array['available_status_transitions'] = $this->getAvailableStatusTransitions();
        
        return $array;
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