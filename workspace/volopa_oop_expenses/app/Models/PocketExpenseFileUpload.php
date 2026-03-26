<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Pocket Expense File Upload Model
 * 
 * Manages CSV file uploads for batch expense processing.
 * Tracks upload status, validation results, and processing progress.
 * Uses Laravel SoftDeletes pattern for this table.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property int $created_by_user_id
 * @property string $file_name
 * @property string $file_path
 * @property int $total_records
 * @property int $valid_records
 * @property array|null $validation_errors
 * @property string $status
 * @property \Illuminate\Support\Carbon $uploaded_at
 * @property \Illuminate\Support\Carbon|null $validated_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
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
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
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
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'uploaded',
        'total_records' => 0,
        'valid_records' => 0,
    ];

    /**
     * Boot the model.
     * Auto-generate UUID on creation and set uploaded_at timestamp.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            if (empty($model->uploaded_at)) {
                $model->uploaded_at = now();
            }
        });
    }

    /**
     * Get the user that owns this upload.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this upload.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who created this upload.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the upload data rows associated with this upload.
     */
    public function uploadsData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id');
    }

    /**
     * Scope a query to only include uploads for a specific client.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include uploads for a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include uploads with a specific status.
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
     * Scope a query to only include uploads that are uploaded.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUploaded($query)
    {
        return $query->where('status', 'uploaded');
    }

    /**
     * Scope a query to only include uploads that are validating.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidating($query)
    {
        return $query->where('status', 'validating');
    }

    /**
     * Scope a query to only include uploads that are processing.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope a query to only include uploads that are completed.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include uploads that have failed.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to include uploads created by a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $createdByUserId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, int $createdByUserId)
    {
        return $query->where('created_by_user_id', $createdByUserId);
    }

    /**
     * Check if this upload is in uploaded status.
     *
     * @return bool
     */
    public function isUploaded(): bool
    {
        return $this->status === 'uploaded';
    }

    /**
     * Check if this upload is in validating status.
     *
     * @return bool
     */
    public function isValidating(): bool
    {
        return $this->status === 'validating';
    }

    /**
     * Check if this upload is in processing status.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if this upload is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if this upload has failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if this upload has validation errors.
     *
     * @return bool
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    /**
     * Get the number of validation errors.
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        return is_array($this->validation_errors) ? count($this->validation_errors) : 0;
    }

    /**
     * Get the success rate of this upload (valid_records / total_records).
     *
     * @return float
     */
    public function getSuccessRate(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }
        
        return round(($this->valid_records / $this->total_records) * 100, 2);
    }

    /**
     * Update the upload status and related timestamps.
     *
     * @param string $status
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        $this->status = $status;
        
        // Update appropriate timestamp based on status
        switch ($status) {
            case 'validating':
                // No specific timestamp for validating
                break;
            case 'processing':
                if (is_null($this->validated_at)) {
                    $this->validated_at = now();
                }
                break;
            case 'completed':
            case 'failed':
                if (is_null($this->validated_at)) {
                    $this->validated_at = now();
                }
                if (is_null($this->processed_at)) {
                    $this->processed_at = now();
                }
                break;
        }
        
        return $this->save();
    }

    /**
     * Set validation results for this upload.
     *
     * @param int $totalRecords
     * @param int $validRecords
     * @param array|null $errors
     * @return bool
     */
    public function setValidationResults(int $totalRecords, int $validRecords, ?array $errors = null): bool
    {
        $this->total_records = $totalRecords;
        $this->valid_records = $validRecords;
        $this->validation_errors = $errors;
        $this->validated_at = now();
        
        // Update status based on validation results
        if (!empty($errors)) {
            $this->status = 'failed';
        } else {
            $this->status = 'processing';
        }
        
        return $this->save();
    }

    /**
     * Mark this upload as completed.
     *
     * @return bool
     */
    public function markAsCompleted(): bool
    {
        return $this->updateStatus('completed');
    }

    /**
     * Mark this upload as failed with optional error message.
     *
     * @param array|null $errors
     * @return bool
     */
    public function markAsFailed(?array $errors = null): bool
    {
        if (!is_null($errors)) {
            $this->validation_errors = $errors;
        }
        
        return $this->updateStatus('failed');
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

    /**
     * Get the file size if available from file path.
     *
     * @return int|null File size in bytes, null if file doesn't exist
     */
    public function getFileSize(): ?int
    {
        if (file_exists($this->file_path)) {
            return filesize($this->file_path);
        }
        
        return null;
    }

    /**
     * Get the formatted file size with units.
     *
     * @return string
     */
    public function getFormattedFileSize(): string
    {
        $size = $this->getFileSize();
        
        if (is_null($size)) {
            return 'Unknown';
        }
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Get the processing duration in seconds.
     *
     * @return int|null Duration in seconds, null if not processed
     */
    public function getProcessingDuration(): ?int
    {
        if (is_null($this->processed_at) || is_null($this->uploaded_at)) {
            return null;
        }
        
        return $this->processed_at->diffInSeconds($this->uploaded_at);
    }

    /**
     * Check if this upload can be reprocessed.
     *
     * @return bool
     */
    public function canReprocess(): bool
    {
        return in_array($this->status, ['failed', 'completed']);
    }

    /**
     * Get the upload progress as percentage.
     *
     * @return int
     */
    public function getProgressPercentage(): int
    {
        switch ($this->status) {
            case 'uploaded':
                return 10;
            case 'validating':
                return 30;
            case 'processing':
                return 70;
            case 'completed':
                return 100;
            case 'failed':
                return 0;
            default:
                return 0;
        }
    }
}