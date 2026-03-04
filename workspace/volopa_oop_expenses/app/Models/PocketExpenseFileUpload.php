## Code: app/Models/PocketExpenseFileUpload.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * PocketExpenseFileUpload Model
 * 
 * Represents CSV file uploads for batch expense processing with status tracking.
 * Manages upload lifecycle from file upload through validation to completion.
 * 
 * @property int $id
 * @property string|null $uuid External reference UUID
 * @property int $user_id User who uploaded the file
 * @property int $client_id Client context for multi-tenancy
 * @property int $created_by_user_id User who created this upload record
 * @property string $file_name Original name of uploaded file
 * @property string $file_path Storage path of uploaded file
 * @property int $total_records Total number of records in uploaded file
 * @property int $valid_records Number of valid records after validation
 * @property array|null $validation_errors JSON array of validation errors
 * @property string $status Current processing status of upload
 * @property \Illuminate\Support\Carbon $uploaded_at When file was uploaded
 * @property \Illuminate\Support\Carbon|null $validated_at When validation was completed
 * @property \Illuminate\Support\Carbon|null $processed_at When processing was completed
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * 
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\User $createdBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpenseUploadsData> $uploadsData
 */
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
     * The attributes that should be cast.
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
        'total_records' => 0,
        'valid_records' => 0,
        'status' => 'uploaded',
        'validation_errors' => null,
    ];

    /**
     * The possible values for status enum.
     *
     * @var array<int, string>
     */
    public const STATUS_VALUES = [
        'uploaded',
        'validation_failed',
        'validation_passed',
        'processing',
        'completed',
        'failed',
        'sync_failed',
    ];

    /**
     * Maximum file size in KB for CSV uploads.
     *
     * @var int
     */
    public const MAX_FILE_SIZE_KB = 10240; // 10MB

    /**
     * Maximum number of rows allowed per CSV file.
     *
     * @var int
     */
    public const MAX_ROWS_PER_FILE = 200;

    /**
     * Storage path prefix for uploaded files.
     *
     * @var string
     */
    public const STORAGE_PATH_PREFIX = 'pocket-expense-uploads';

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Generate UUID on creation and set uploaded_at
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
            if (!$model->uploaded_at) {
                $model->uploaded_at = now();
            }
        });

        // Ensure all queries are scoped by authenticated user's client context
        static::addGlobalScope('client_scoped', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });
    }

    /**
     * Get the user who uploaded the file.
     *
     * @return BelongsTo<\App\Models\User, PocketExpenseFileUpload>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this upload.
     *
     * @return BelongsTo<\App\Models\Client, PocketExpenseFileUpload>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who created this upload record.
     *
     * @return BelongsTo<\App\Models\User, PocketExpenseFileUpload>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the upload data records associated with this file upload.
     *
     * @return HasMany<\App\Models\PocketExpenseUploadsData>
     */
    public function uploadsData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id');
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
     * Scope a query to filter by specific user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */