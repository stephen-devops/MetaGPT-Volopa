## Code: app/Models/PocketExpenseUploadsData.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * PocketExpenseUploadsData Model
 * 
 * Represents staging data for CSV upload processing.
 * Stores individual expense records from CSV files before they are synchronized.
 * 
 * @property int $id
 * @property int $upload_id Reference to pocket_expense_file_uploads table
 * @property int $line_number Line number in the original CSV file
 * @property string $status Processing status of this individual expense record
 * @property array $expense_data JSON storage of expense data from CSV row
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * 
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
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'expense_data' => '[]',
    ];

    /**
     * The possible values for status enum.
     *
     * @var array<int, string>
     */
    public const STATUS_VALUES = [
        'pending',
        'processing',
        'synced',
        'failed',
    ];

    /**
     * Batch size for processing uploads data records.
     *
     * @var int
     */
    public const PROCESSING_BATCH_SIZE = 100;

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Ensure all queries are scoped by client context through upload relationship
        static::addGlobalScope('client_scoped', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->whereHas('upload', function ($query) {
                    $query->where('client_id', auth()->user()->client_id);
                });
            }
        });
    }

    /**
     * Get the file upload record that this data belongs to.
     *
     * @return BelongsTo<\App\Models\PocketExpenseFileUpload, PocketExpenseUploadsData>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseFileUpload::class, 'upload_id');
    }

    /**
     * Scope a query to filter by specific status.
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
     * Scope a query to filter by specific upload.
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
     * Scope a query to only include processing records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', 'processing');
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
     * @param int $startLine
     * @param int $endLine
     * @return Builder
     */
    public function scopeByLineNumberRange(Builder $query, int $startLine, int $endLine): Builder
    {
        return $query->whereBetween('line_number', [$startLine, $endLine]);
    }

    /**
     * Scope a query to order by line number.
     *
     * @param Builder $query
     * @param string $direction
     * @return Builder
     */
    public function scopeOrderByLineNumber(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('line_number', $direction);
    }

    /**
     * Check if the record is pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the record is currently being processed.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if the record has been successfully synced.
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    /**
     * Check if the record processing failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get a specific field value from the expense data JSON.
     *
     * @param string $field
     * @param mixed $default
     * @return mixed
     */
    public function getExpenseField(string $field, $default = null)
    {
        $data = $this->expense_data ?? [];