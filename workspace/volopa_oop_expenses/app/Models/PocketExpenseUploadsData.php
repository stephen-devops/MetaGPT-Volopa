<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PocketExpenseUploadsData Model
 * 
 * Represents individual CSV row data for batch expense uploads.
 * Each record represents one line from the uploaded CSV file with its processing status.
 * 
 * @property int $id
 * @property int $upload_id
 * @property int $line_number
 * @property string $status
 * @property array $expense_data
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\PocketExpenseFileUpload $upload
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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'line_number' => 1,
    ];

    /**
     * Status enum values.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_VALIDATED = 'validated';
    const STATUS_VALIDATION_FAILED = 'validation_failed';
    const STATUS_PROCESSED = 'processed';
    const STATUS_FAILED = 'failed';

    /**
     * Valid status values.
     *
     * @var array<string>
     */
    public static array $validStatuses = [
        self::STATUS_PENDING,
        self::STATUS_VALIDATED,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_PROCESSED,
        self::STATUS_FAILED,
    ];

    /**
     * Get the upload batch record that this data belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\PocketExpenseFileUpload, \App\Models\PocketExpenseUploadsData>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
    }

    /**
     * Scope a query to only include records with a specific status.
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
     * Scope a query to only include pending records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include validated records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidated($query)
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    /**
     * Scope a query to only include validation failed records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidationFailed($query)
    {
        return $query->where('status', self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope a query to only include processed records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessed($query)
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    /**
     * Scope a query to only include failed records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include records for a specific upload.
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
     * Scope a query to only include records for a specific line number.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $lineNumber
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForLine($query, int $lineNumber)
    {
        return $query->where('line_number', $lineNumber);
    }

    /**
     * Scope a query to order by line number ascending.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByLine($query)
    {
        return $query->orderBy('line_number', 'asc');
    }

    /**
     * Scope a query to get records ready for processing (validated status).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReadyForProcessing($query)
    {
        return $query->validated()->orderByLine();
    }

    /**
     * Check if the record is currently pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the record has been validated successfully.
     *
     * @return bool
     */
    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    /**
     * Check if the record failed validation.
     *
     * @return bool
     */
    public function hasValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the record has been processed successfully.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    /**
     * Check if the record processing failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the record can be processed (must be validated).
     *
     * @return bool
     */
    public function canProcess(): bool
    {
        return $this->isValidated();
    }

    /**
     * Mark the record as validated.
     *
     * @return bool
     */
    public function markAsValidated(): bool
    {
        $this->status = self::STATUS_VALIDATED;
        return $this->save();
    }

    /**
     * Mark the record as validation failed.
     *
     * @return bool
     */
    public function markAsValidationFailed(): bool
    {
        $this->status = self::STATUS_VALIDATION_FAILED;
        return $this->save();
    }

    /**
     * Mark the record as processed.
     *
     * @return bool
     */
    public function markAsProcessed(): bool
    {
        $this->status = self::STATUS_PROCESSED;
        return $this->save();
    }

    /**
     * Mark the record as failed.
     *
     * @return bool
     */
    public function markAsFailed(): bool
    {
        $this->status = self::STATUS_FAILED;
        return $this->save();
    }

    /**
     * Get a specific field value from the expense data JSON.
     *
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    public function getExpenseField(string $field, mixed $default = null): mixed
    {
        return $this->expense_data[$field] ?? $default;
    }

    /**
     * Set a specific field value in the expense data JSON.
     *
     * @param string $field
     * @param mixed $value
     * @return bool
     */
    public function setExpenseField(string $field, mixed $value): bool
    {
        $expenseData = $this->expense_data ?? [];
        $expenseData[$field] = $value;
        $this->expense_data = $expenseData;
        return $this->save();
    }

    /**
     * Update multiple expense data fields at once.
     *
     * @param array $fields
     * @return bool
     */
    public function updateExpenseData(array $fields): bool
    {
        $expenseData = $this->expense_data ?? [];
        $expenseData = array_merge($expenseData, $fields);
        $this->expense_data = $expenseData;
        return $this->save();
    }

    /**
     * Get the status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_VALIDATED => 'Validated',
            self::STATUS_VALIDATION_FAILED => 'Validation Failed',
            self::STATUS_PROCESSED => 'Processed',
            self::STATUS_FAILED => 'Processing Failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Get a descriptive name for the record including line number and status.
     *
     * @return string
     */
    public function getDescriptiveNameAttribute(): string
    {
        return sprintf(
            'Line %d (%s)',
            $this->line_number,
            $this->getStatusDisplayAttribute()
        );
    }

    /**
     * Check if expense data contains all required fields for processing.
     *
     * @param array $requiredFields
     * @return bool
     */
    public function hasRequiredFields(array $requiredFields = []): bool
    {
        if (empty($requiredFields)) {
            $requiredFields = ['date', 'merchant_name', 'currency', 'amount'];
        }

        $expenseData = $this->expense_data ?? [];
        
        foreach ($requiredFields as $field) {
            if (!isset($expenseData[$field]) || empty($expenseData[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get missing required fields from expense data.
     *
     * @param array $requiredFields
     * @return array
     */
    public function getMissingFields(array $requiredFields = []): array
    {
        if (empty($requiredFields)) {
            $requiredFields = ['date', 'merchant_name', 'currency', 'amount'];
        }

        $expenseData = $this->expense_data ?? [];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($expenseData[$field]) || empty($expenseData[$field])) {
                $missingFields[] = $field;
            }
        }

        return $missingFields;
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
     * Get records for batch processing with limit.
     *
     * @param int $uploadId
     * @param int $limit
     * @param int $offset
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getBatchForProcessing(int $uploadId, int $limit = 100, int $offset = 0): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUpload($uploadId)
                     ->readyForProcessing()
                     ->offset($offset)
                     ->limit($limit)
                     ->get();
    }

    /**
     * Get processing statistics for a specific upload.
     *
     * @param int $uploadId
     * @return array
     */
    public static function getProcessingStats(int $uploadId): array
    {
        $stats = static::forUpload($uploadId)
                      ->selectRaw('status, COUNT(*) as count')
                      ->groupBy('status')
                      ->pluck('count', 'status')
                      ->toArray();

        return [
            'total' => array_sum($stats),
            'pending' => $stats[self::STATUS_PENDING] ?? 0,
            'validated' => $stats[self::STATUS_VALIDATED] ?? 0,
            'validation_failed' => $stats[self::STATUS_VALIDATION_FAILED] ?? 0,
            'processed' => $stats[self::STATUS_PROCESSED] ?? 0,
            'failed' => $stats[self::STATUS_FAILED] ?? 0,
        ];
    }

    /**
     * Create a batch of upload data records.
     *
     * @param int $uploadId
     * @param array $dataRows
     * @return int Number of records created
     */
    public static function createBatch(int $uploadId, array $dataRows): int
    {
        $records = [];
        
        foreach ($dataRows as $lineNumber => $expenseData) {
            $records[] = [
                'upload_id' => $uploadId,
                'line_number' => $lineNumber,
                'status' => self::STATUS_PENDING,
                'expense_data' => json_encode($expenseData),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return static::insert($records) ? count($records) : 0;
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Validate and set defaults on creation
        static::creating(function (PocketExpenseUploadsData $record) {
            // Ensure status has a default value
            if (empty($record->status)) {
                $record->status = self::STATUS_PENDING;
            }

            // Validate status
            if (!self::isValidStatus($record->status)) {
                throw new \InvalidArgumentException('Invalid upload data status: ' . $record->status);
            }

            // Ensure line number is positive
            if ($record->line_number <= 0) {
                throw new \InvalidArgumentException('Line number must be positive: ' . $record->line_number);
            }

            // Ensure expense_data is an array
            if (!is_array($record->expense_data)) {
                $record->expense_data = [];
            }
        });

        // Validate status changes on updates
        static::updating(function (PocketExpenseUploadsData $record) {
            if ($record->isDirty('status') && !self::isValidStatus($record->status)) {
                throw new \InvalidArgumentException('Invalid upload data status: ' . $record->status);
            }

            // Prevent line number changes after creation
            if ($record->isDirty('line_number')) {
                throw new \RuntimeException('Line number cannot be changed after record creation.');
            }

            // Prevent upload_id changes after creation
            if ($record->isDirty('upload_id')) {
                throw new \RuntimeException('Upload ID cannot be changed after record creation.');
            }
        });

        // Log status changes for audit purposes
        static::updated(function (PocketExpenseUploadsData $record) {
            if ($record->isDirty('status')) {
                \Log::info('Upload data record status changed', [
                    'record_id' => $record->id,
                    'upload_id' => $record->upload_id,
                    'line_number' => $record->line_number,
                    'old_status' => $record->getOriginal('status'),
                    'new_status' => $record->status,
                    'changed_at' => now(),
                ]);
            }
        });

        // Log processing failures for debugging
        static::updated(function (PocketExpenseUploadsData $record) {
            if ($record->isDirty('status') && in_array($record->status, [self::STATUS_VALIDATION_FAILED, self::STATUS_FAILED])) {
                \Log::warning('Upload data record processing failed', [
                    'record_id' => $record->id,
                    'upload_id' => $record->upload_id,
                    'line_number' => $record->line_number,
                    'status' => $record->status,
                    'expense_data' => $record->expense_data,
                    'failed_at' => now(),
                ]);
            }
        });
    }
}