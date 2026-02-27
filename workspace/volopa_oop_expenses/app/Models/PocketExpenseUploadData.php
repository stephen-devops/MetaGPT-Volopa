## Code: app/Models/PocketExpenseUploadData.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class PocketExpenseUploadData extends Model
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
     * The attributes that should be cast to native types.
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
     * Valid status values for upload data records.
     *
     * @var array<string>
     */
    const VALID_STATUSES = [
        'pending',
        'synced',
        'failed',
        'skipped',
    ];

    /**
     * Default batch size for processing uploads.
     *
     * @var int
     */
    const DEFAULT_BATCH_SIZE = 100;

    /**
     * Get the file upload that this data record belongs to.
     *
     * @return BelongsTo
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
    }

    /**
     * Scope a query to only include records for a specific upload.
     *
     * @param Builder $query
     * @param int $uploadId
     * @return Builder
     */
    public function scopeForUpload(Builder $query, int $uploadId): Builder
    {
        return $query->where('upload_id', $uploadId);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include pending records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include synced records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('status', 'synced');
    }

    /**
     * Scope a query to only include failed records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include skipped records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('status', 'skipped');
    }

    /**
     * Scope a query to filter by line number.
     *
     * @param Builder $query
     * @param int $lineNumber
     * @return Builder
     */
    public function scopeByLineNumber(Builder $query, int $lineNumber): Builder
    {
        return $query->where('line_number', $lineNumber);
    }

    /**
     * Scope a query to filter by line number range.
     *
     * @param Builder $query
     * @param int $fromLine
     * @param int $toLine
     * @return Builder
     */
    public function scopeByLineRange(Builder $query, int $fromLine, int $toLine): Builder
    {
        return $query->whereBetween('line_number', [$fromLine, $toLine]);
    }

    /**
     * Scope a query to order by line number.
     *
     * @param Builder $query
     * @param string $direction
     * @return Builder
     */
    public function scopeOrderByLine(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('line_number', $direction);
    }

    /**
     * Scope a query to get records in batches for processing.
     *
     * @param Builder $query
     * @param int $batchSize
     * @return Builder
     */
    public function scopeBatch(Builder $query, int $batchSize = self::DEFAULT_BATCH_SIZE): Builder
    {
        return $query->limit($batchSize);
    }

    /**
     * Check if this record is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if this record has been synced.
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    /**
     * Check if this record has failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if this record has been skipped.
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    /**
     * Mark this record as synced.
     *
     * @return bool
     */
    public function markAsSynced(): bool
    {
        $this->status = 'synced';
        return $this->save();
    }

    /**
     * Mark this record as failed.
     *
     * @return bool
     */
    public function markAsFailed(): bool
    {
        $this->status = 'failed';
        return $this->save();
    }

    /**
     * Mark this record as skipped.
     *
     * @return bool
     */
    public function markAsSkipped(): bool
    {
        $this->status = 'skipped';
        return $this->save();
    }

    /**
     * Get the expense data as an array.
     *
     * @return array<string, mixed>
     */
    public function getExpenseDataArray(): array
    {
        return is_array($this->expense_data) ? $this->expense_data : [];
    }

    /**
     * Get a specific field from the expense data.
     *
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    public function getExpenseDataField(string $field, mixed $default = null): mixed
    {
        $data = $this->getExpenseDataArray();
        return $data[$field] ?? $default;
    }

    /**
     * Set the expense data.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public