<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PocketExpenseUploadsData Model
 * 
 * Represents individual CSV row data from pocket expense file uploads.
 * This model stores the staging data for each CSV row before it gets processed
 * into actual PocketExpense records.
 *
 * @property int $id
 * @property int $upload_id Foreign key to pocket_expense_file_uploads table
 * @property int $line_number Line number in the original CSV file (including header row)
 * @property string $status Processing status of this individual CSV row
 * @property array $expense_data JSON representation of the parsed CSV row data
 * @property string|null $error_message Error message if processing failed
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * 
 * @property-read \App\Models\PocketExpenseFileUpload $upload
 * 
 * @method static \Database\Factories\PocketExpenseUploadsDataFactory factory($count = null, $state = [])
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
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'upload_id' => 'integer',
        'line_number' => 'integer',
        'expense_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'error_message', // Hide sensitive error details from API responses
    ];

    /**
     * Valid status values for the staging data status enum.
     *
     * @var array<string>
     */
    public const VALID_STATUSES = [
        'pending',
        'processing',
        'synced',
        'failed'
    ];

    /**
     * Default status for new upload data records.
     *
     * @var string
     */
    public const DEFAULT_STATUS = 'pending';

    /**
     * Get the upload that this data row belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
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
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include processing records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope a query to only include synced records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSynced($query)
    {
        return $query->where('status', 'synced');
    }

    /**
     * Scope a query to only include failed records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
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
     * Scope a query to order by line number.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $direction
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrderByLineNumber($query, string $direction = 'asc')
    {
        return $query->orderBy('line_number', $direction);
    }

    /**
     * Check if the status is valid.
     *
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES);
    }

    /**
     * Mark this upload data record as processing.
     *
     * @return bool
     */
    public function markAsProcessing(): bool
    {
        $this->status = 'processing';
        return $this->save();
    }

    /**
     * Mark this upload data record as synced.
     *
     * @return bool
     */
    public function markAsSynced(): bool
    {
        $this->status = 'synced';
        $this->error_message = null; // Clear any previous error
        return $this->save();
    }

    /**
     * Mark this upload data record as failed with an error message.
     *
     * @param string $errorMessage
     * @return bool
     */
    public function markAsFailed(string $errorMessage): bool
    {
        $this->status = 'failed';
        $this->error_message = $errorMessage;
        return $this->save();
    }

    /**
     * Get a specific field value from the expense data JSON.
     *
     * @param string $fieldName
     * @param mixed $default
     * @return mixed
     */
    public function getExpenseField(string $fieldName, $default = null)
    {
        return $this->expense_data[$fieldName] ?? $default;
    }

    /**
     * Set a specific field value in the expense data JSON.
     *
     * @param string $fieldName
     * @param mixed $value
     * @return void
     */
    public function setExpenseField(string $fieldName, $value): void
    {
        $expenseData = $this->expense_data ?? [];
        $expenseData[$fieldName] = $value;
        $this->expense_data = $expenseData;
    }

    /**
     * Check if this upload data record has failed processing.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if this upload data record is pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this upload data record is currently being processed.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if this upload data record has been successfully synced.
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    /**
     * Get the CSV column names from the expense data.
     *
     * @return array
     */
    public function getCsvColumnNames(): array
    {
        return array_keys($this->expense_data ?? []);
    }

    /**
     * Get formatted expense data for logging or display.
     *
     * @return string
     */
    public function getFormattedExpenseData(): string
    {
        if (empty($this->expense_data)) {
            return 'No data available';
        }

        $formatted = [];
        foreach ($this->expense_data as $field => $value) {
            $formatted[] = "{$field}: {$value}";
        }

        return implode(', ', $formatted);
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Set default status when creating new records
        static::creating(function ($model) {
            if (empty($model->status)) {
                $model->status = self::DEFAULT_STATUS;
            }
        });

        // Validate status before saving
        static::saving(function ($model) {
            if (!self::isValidStatus($model->status)) {
                throw new \InvalidArgumentException(
                    "Invalid status: {$model->status}. Valid statuses are: " . 
                    implode(', ', self::VALID_STATUSES)
                );
            }
        });
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Get a string representation of the model for logging.
     *
     * @return string
     */
    public function __toString(): string
    {
        return "PocketExpenseUploadsData #{$this->id} (Upload: {$this->upload_id}, Line: {$this->line_number}, Status: {$this->status})";
    }

    /**
     * Convert the model instance to an array for API responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed fields for API responses
        $array['has_error'] = $this->hasFailed();
        $array['is_processed'] = $this->isSynced();
        $array['csv_column_count'] = count($this->getCsvColumnNames());
        
        return $array;
    }

    /**
     * Get the attributes that should be cast to native types.
     * This method ensures expense_data is always treated as an array.
     *
     * @return array
     */
    protected function getCastType($key)
    {
        if ($key === 'expense_data') {
            return 'array';
        }

        return parent::getCastType($key);
    }

    /**
     * Determine if the model should use timestamps.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The storage format of the model's date columns.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * The connection name for the model.
     * Uses default connection as per platform standards.
     *
     * @var string|null
     */
    protected $connection = null;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';
}