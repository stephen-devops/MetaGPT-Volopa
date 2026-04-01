<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\PocketExpenseUploadsDataFactory;

/**
 * PocketExpenseUploadsData Model
 * 
 * Stores individual CSV row data from batch uploads for processing.
 * Each record represents one expense line from the uploaded CSV file.
 * Status tracking enables batch processing and error handling.
 * 
 * @property int $id
 * @property int $upload_id
 * @property int $line_number
 * @property string $status
 * @property array $expense_data
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
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
     * Status constants for expense data processing.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SYNCED = 'synced';
    const STATUS_FAILED = 'failed';

    /**
     * Get the file upload this data belongs to.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
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
     * Scope a query to only include processing records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include synced records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSynced($query)
    {
        return $query->where('status', self::STATUS_SYNCED);
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
     * Scope a query to get records ready for batch processing.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeReadyForProcessing($query)
    {
        return $query->where('status', self::STATUS_PENDING)
                    ->orderBy('upload_id')
                    ->orderBy('line_number');
    }

    /**
     * Check if this record is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if this record is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if this record is synced.
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    /**
     * Check if this record failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark this record as processing.
     *
     * @return bool
     */
    public function markAsProcessing(): bool
    {
        $this->status = self::STATUS_PROCESSING;
        return $this->save();
    }

    /**
     * Mark this record as synced.
     *
     * @return bool
     */
    public function markAsSynced(): bool
    {
        $this->status = self::STATUS_SYNCED;
        return $this->save();
    }

    /**
     * Mark this record as failed.
     *
     * @return bool
     */
    public function markAsFailed(): bool
    {
        $this->status = self::STATUS_FAILED;
        return $this->save();
    }

    /**
     * Get specific field from expense data.
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
     * Set specific field in expense data.
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
     * Get the display name for this upload data record.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $merchantName = $this->getExpenseDataField('Merchant Name', 'Unknown Merchant');
        return "Line {$this->line_number}: {$merchantName}";
    }

    /**
     * Get all available status options.
     *
     * @return array
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_SYNCED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\PocketExpenseUploadsDataFactory
     */
    protected static function newFactory()
    {
        return PocketExpenseUploadsDataFactory::new();
    }
}