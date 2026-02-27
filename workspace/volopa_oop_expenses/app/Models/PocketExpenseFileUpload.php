## Code: app/Models/PocketExpenseFileUpload.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PocketExpenseFileUpload extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_file_uploads';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'client_id',
        'created_by_user_id',
        'file_name',
        'file_path',
        'total_records',
        'valid_records',
        'validation_errors',
        'status',
        'uploaded_at',
        'validated_at',
        'processed_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'uuid' => 'string',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'created_by_user_id' => 'integer',
        'file_name' => 'string',
        'file_path' => 'string',
        'total_records' => 'integer',
        'valid_records' => 'integer',
        'validation_errors' => 'array',
        'status' => 'string',
        'uploaded_at' => 'datetime',
        'validated_at' => 'datetime',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
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
        'total_records' => 0,
        'valid_records' => 0,
        'status' => 'uploaded',
    ];

    /**
     * Valid status values for file uploads.
     *
     * @var array<string>
     */
    const VALID_STATUSES = [
        'uploaded',
        'processing',
        'completed',
        'failed',
        'validation_failed',
    ];

    /**
     * Maximum file size in KB (10MB).
     *
     * @var int
     */
    const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum CSV rows allowed per file.
     *
     * @var int
     */
    const MAX_CSV_ROWS = 200;

    /**
     * Allowed file extensions.
     *
     * @var array<string>
     */
    const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate UUID when creating new records
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            
            // Set uploaded_at if not already set
            if (empty($model->uploaded_at)) {
                $model->uploaded_at = now();
            }
        });
    }

    /**
     * Get the user that this upload belongs to (target user for expenses).
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client that this upload belongs to.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who created/uploaded this file.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get all upload data records for this file upload.
     *
     * @return HasMany
     */
    public function uploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadData::class, 'upload_id');
    }

    /**
     * Scope a query to only include uploads for a specific client.
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include uploads for a specific user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include uploads created by a specific user.
     *
     * @param Builder $query
     * @param int $createdByUserId
     * @return Builder
     */
    public function scopeCreatedBy(Builder $query, int $createdByUserId): Builder
    {
        return $query->where('created_by_user_id', $createdByUserId);
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
     * Scope a query to only include completed uploads.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include failed uploads.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include processing uploads.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope a query to only include validation failed uploads.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeValidationFailed(Builder $query): Builder
    {
        return $query->where('status', 'validation_failed');
    }

    /**
     * Scope a query to filter by upload date range.
     *
     * @param Builder $query
     * @param string $dateFrom
     * @param string $dateTo
     * @return Builder
     */
    public function scopeUploadedBetween(Builder $query, string $dateFrom, string $dateTo): Builder
    {
        return $query->whereBetween('uploaded_at', [$dateFrom, $dateTo]);