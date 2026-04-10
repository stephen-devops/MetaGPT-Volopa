<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PocketExpenseUploadsData Model
 * 
 * Stores individual CSV row data for pocket expense uploads.
 * Each row represents a single expense record from the uploaded CSV file.
 * Used for batch processing and tracking individual row status during upload processing.
 * 
 * @property int $id
 * @property int $upload_id
 * @property int $line_number
 * @property string $status
 * @property array $expense_data
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
     * Get the file upload that this data belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id', 'id');
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
     * Scope a query to filter by upload ID.
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
     * Scope a query to filter by line number.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $lineNumber
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByLineNumber($query, int $lineNumber)
    {
        return $query->where('line_number', $lineNumber);
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
     * Check if this upload data record is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this upload data record is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if this upload data record is synced.
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    /**
     * Check if this upload data record failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
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
        return $this->save();
    }

    /**
     * Mark this upload data record as failed.
     *
     * @return bool
     */
    public function markAsFailed(): bool
    {
        $this->status = 'failed';
        return $this->save();
    }

    /**
     * Get a specific field value from the expense data JSON.
     *
     * @param string $fieldName
     * @param mixed $default
     * @return mixed
     */
    public function getExpenseDataField(string $fieldName, $default = null)
    {
        $expenseData = $this->expense_data ?? [];
        return $expenseData[$fieldName] ?? $default;
    }

    /**
     * Set a specific field value in the expense data JSON.
     *
     * @param string $fieldName
     * @param mixed $value
     * @return void
     */
    public function setExpenseDataField(string $fieldName, $value): void
    {
        $expenseData = $this->expense_data ?? [];
        $expenseData[$fieldName] = $value;
        $this->expense_data = $expenseData;
    }

    /**
     * Check if this record represents the header row.
     *
     * @return bool
     */
    public function isHeaderRow(): bool
    {
        return $this->line_number === 1;
    }

    /**
     * Check if this record represents a data row (not header).
     *
     * @return bool
     */
    public function isDataRow(): bool
    {
        return $this->line_number > 1;
    }
}