<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PocketExpenseFileUpload Model
 * 
 * Manages CSV file uploads for batch expense processing.
 * Tracks upload status, validation results, and processing progress.
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
     * Get the user for whom expenses are being created (target user).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client that this upload belongs to.
     * Provides multi-tenancy scoping.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the admin user who uploaded this file.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    /**
     * Get all upload data records for this upload.
     * Contains individual CSV row data.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function uploadData(): HasMany
    {
        return $this->hasMany(PocketExpenseUploadsData::class, 'upload_id', 'id');
    }

    /**
     * Scope a query to filter by status.
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
     * Scope a query to filter by client.
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
     * Scope a query to filter by user (target user for expenses).
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
     * Scope a query to filter by admin user who created the upload.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $adminUserId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, int $adminUserId)
    {
        return $query->where('created_by_user_id', $adminUserId);
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
     * Scope a query to only include uploads with validation failures.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidationFailed($query)
    {
        return $query->where('status', 'validation_failed');
    }

    /**
     * Scope a query to only include uploads with validation passed.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidationPassed($query)
    {
        return $query->where('status', 'validation_passed');
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
     * Scope a query to only include uploads that failed.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include uploads that failed during sync.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSyncFailed($query)
    {
        return $query->where('status', 'sync_failed');
    }

    /**
     * Check if the upload status is uploaded.
     *
     * @return bool
     */
    public function isUploaded(): bool
    {
        return $this->status === 'uploaded';
    }

    /**
     * Check if the upload validation failed.
     *
     * @return bool
     */
    public function hasValidationFailed(): bool
    {
        return $this->status === 'validation_failed';
    }

    /**
     * Check if the upload validation passed.
     *
     * @return bool
     */
    public function hasValidationPassed(): bool
    {
        return $this->status === 'validation_passed';
    }

    /**
     * Check if the upload is currently processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if the upload has completed successfully.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the upload failed during processing.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the upload failed during sync.
     *
     * @return bool
     */
    public function hasSyncFailed(): bool
    {
        return $this->status === 'sync_failed';
    }

    /**
     * Check if the upload has validation errors.
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
    public function getValidationErrorCount(): int
    {
        return $this->validation_errors ? count($this->validation_errors) : 0;
    }

    /**
     * Get the number of invalid records.
     *
     * @return int
     */
    public function getInvalidRecordCount(): int
    {
        return $this->total_records - $this->valid_records;
    }

    /**
     * Check if all records are valid.
     *
     * @return bool
     */
    public function hasAllValidRecords(): bool
    {
        return $this->total_records > 0 && $this->valid_records === $this->total_records;
    }

    /**
     * Check if the upload has been validated.
     *
     * @return bool
     */
    public function hasBeenValidated(): bool
    {
        return $this->validated_at !== null;
    }

    /**
     * Check if the upload has been processed.
     *
     * @return bool
     */
    public function hasBeenProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * Update the upload status and set appropriate timestamps.
     *
     * @param string $status
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        $this->status = $status;

        // Set appropriate timestamps based on status
        switch ($status) {
            case 'validation_failed':
            case 'validation_passed':
                if ($this->validated_at === null) {
                    $this->validated_at = now();
                }
                break;
            case 'completed':
                if ($this->processed_at === null) {
                    $this->processed_at = now();
                }
                break;
        }

        return $this->save();
    }

    /**
     * Set validation errors and update status to validation_failed.
     *
     * @param array $errors
     * @return bool
     */
    public function setValidationErrors(array $errors): bool
    {
        $this->validation_errors = $errors;
        $this->status = 'validation_failed';
        $this->validated_at = now();

        return $this->save();
    }

    /**
     * Mark validation as passed and update valid records count.
     *
     * @param int $validRecords
     * @return bool
     */
    public function markValidationPassed(int $validRecords): bool
    {
        $this->valid_records = $validRecords;
        $this->validation_errors = null;
        $this->status = 'validation_passed';
        $this->validated_at = now();

        return $this->save();
    }

    /**
     * Mark upload as completed.
     *
     * @return bool
     */
    public function markCompleted(): bool
    {
        $this->status = 'completed';
        $this->processed_at = now();

        return $this->save();
    }

    /**
     * Mark upload as processing.
     *
     * @return bool
     */
    public function markProcessing(): bool
    {
        return $this->updateStatus('processing');
    }

    /**
     * Mark upload as failed.
     *
     * @return bool
     */
    public function markFailed(): bool
    {
        return $this->updateStatus('failed');
    }

    /**
     * Mark upload as sync failed.
     *
     * @return bool
     */
    public function markSyncFailed(): bool
    {
        return $this->updateStatus('sync_failed');
    }
}