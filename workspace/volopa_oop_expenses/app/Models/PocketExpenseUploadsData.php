<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pocket Expense Uploads Data Model
 * 
 * Manages individual CSV row data for batch expense uploads.
 * Tracks processing status and stores expense data for each row.
 * 
 * @property int $id
 * @property int $upload_id
 * @property int $line_number
 * @property string $status
 * @property array|null $expense_data
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
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Get the file upload that owns this data row.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
    }

    /**
     * Scope a query to only include pending upload data.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include processed upload data.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope a query to only include failed upload data.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include data for a specific upload.
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
     * Check if this upload data is pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this upload data is processed.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    /**
     * Check if this upload data processing failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark this upload data as processed.
     *
     * @return bool
     */
    public function markAsProcessed(): bool
    {
        $this->status = 'processed';
        return $this->save();
    }

    /**
     * Mark this upload data as failed.
     *
     * @return bool
     */
    public function markAsFailed(): bool
    {
        $this->status = 'failed';
        return $this->save();
    }

    /**
     * Get the expense data as an array.
     *
     * @return array|null
     */
    public function getExpenseData(): ?array
    {
        return $this->expense_data;
    }

    /**
     * Set the expense data from an array.
     *
     * @param array $data
     * @return bool
     */
    public function setExpenseData(array $data): bool
    {
        $this->expense_data = $data;
        return $this->save();
    }

    /**
     * Get a specific field from the expense data.
     *
     * @param string $field
     * @return mixed|null
     */
    public function getExpenseDataField(string $field)
    {
        return $this->expense_data[$field] ?? null;
    }

    /**
     * Check if the expense data contains a specific field.
     *
     * @param string $field
     * @return bool
     */
    public function hasExpenseDataField(string $field): bool
    {
        return isset($this->expense_data[$field]);
    }

    /**
     * Get the CSV line number (including header row).
     *
     * @return int
     */
    public function getLineNumber(): int
    {
        return $this->line_number;
    }

    /**
     * Get the display status with proper formatting.
     *
     * @return string
     */
    public function getDisplayStatus(): string
    {
        return ucfirst($this->status);
    }
}