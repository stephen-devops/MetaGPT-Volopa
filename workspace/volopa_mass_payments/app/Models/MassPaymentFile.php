<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class MassPaymentFile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'mass_payment_files';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'tcc_account_id',
        'filename',
        'original_filename',
        'file_path',
        'status',
        'total_amount',
        'currency',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'validation_summary',
        'validation_errors',
        'created_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'metadata'
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'file_path',
        'deleted_at'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'string',
        'tcc_account_id' => 'string',
        'total_amount' => 'decimal:2',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
        'validation_summary' => 'json',
        'validation_errors' => 'json',
        'created_by' => 'string',
        'approved_by' => 'string',
        'approved_at' => 'datetime',
        'metadata' => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The attributes that should be mutated to dates.
     */
    protected $dates = [
        'approved_at',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Default values for attributes.
     */
    protected $attributes = [
        'status' => 'uploading',
        'total_amount' => '0.00',
        'total_rows' => 0,
        'valid_rows' => 0,
        'invalid_rows' => 0
    ];

    /**
     * Status constants for the mass payment file.
     */
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_VALIDATION_COMPLETED = 'validation_completed';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING_PAYMENTS = 'processing_payments';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    /**
     * Get all available status values.
     */
    public static function getAvailableStatuses(): array
    {
        return [
            self::STATUS_UPLOADING,
            self::STATUS_PROCESSING,
            self::STATUS_VALIDATION_COMPLETED,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_PROCESSING_PAYMENTS,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED
        ];
    }

    /**
     * Get the TCC account that owns the mass payment file.
     */
    public function tccAccount(): BelongsTo
    {
        return $this->belongsTo(TccAccount::class, 'tcc_account_id', 'id');
    }

    /**
     * Get the payment instructions for the mass payment file.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class, 'mass_payment_file_id', 'id');
    }

    /**
     * Get the approvals for the mass payment file.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(MassPaymentFileApproval::class, 'mass_payment_file_id', 'id');
    }

    /**
     * Scope a query to only include files for a specific client.
     */
    public function scopeForClient($query, string $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include files with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include files for a specific currency.
     */
    public function scopeForCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include files created by a specific user.
     */
    public function scopeCreatedBy($query, string $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope a query to only include approved files.
     */
    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_by')
                    ->whereNotNull('approved_at');
    }

    /**
     * Scope a query to only include files pending approval.
     */
    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    /**
     * Check if the file is in uploading status.
     */
    public function isUploading(): bool
    {
        return $this->status === self::STATUS_UPLOADING;
    }

    /**
     * Check if the file is being processed.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the file validation is completed.
     */
    public function isValidationCompleted(): bool
    {
        return $this->status === self::STATUS_VALIDATION_COMPLETED;
    }

    /**
     * Check if the file validation failed.
     */
    public function isValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the file is pending approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * Check if the file is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if payments are being processed.
     */
    public function isProcessingPayments(): bool
    {
        return $this->status === self::STATUS_PROCESSING_PAYMENTS;
    }

    /**
     * Check if the file processing is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the file is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the file processing failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the file has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return $this->invalid_rows > 0;
    }

    /**
     * Check if the file can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this->isPendingApproval() && !$this->hasValidationErrors();
    }

    /**
     * Check if the file can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADING,
            self::STATUS_PROCESSING,
            self::STATUS_VALIDATION_COMPLETED,
            self::STATUS_PENDING_APPROVAL
        ]);
    }

    /**
     * Mark the file as processing.
     */
    public function markAsProcessing(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark the file validation as completed.
     */
    public function markValidationCompleted(array $validationSummary = null): bool
    {
        return $this->update([
            'status' => self::STATUS_VALIDATION_COMPLETED,
            'validation_summary' => $validationSummary
        ]);
    }

    /**
     * Mark the file validation as failed.
     */
    public function markValidationFailed(array $validationErrors = null): bool
    {
        return $this->update([
            'status' => self::STATUS_VALIDATION_FAILED,
            'validation_errors' => $validationErrors
        ]);
    }

    /**
     * Mark the file as pending approval.
     */
    public function markPendingApproval(): bool
    {
        return $this->update(['status' => self::STATUS_PENDING_APPROVAL]);
    }

    /**
     * Mark the file as approved.
     */
    public function markAsApproved(string $approvedBy, Carbon $approvedAt = null): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt ?? Carbon::now()
        ]);
    }

    /**
     * Mark the file as processing payments.
     */
    public function markProcessingPayments(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING_PAYMENTS]);
    }

    /**
     * Mark the file as completed.
     */
    public function markAsCompleted(): bool
    {
        return $this->update(['status' => self::STATUS_COMPLETED]);
    }

    /**
     * Mark the file as cancelled.
     */
    public function markAsCancelled(string $rejectionReason = null): bool
    {
        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'rejection_reason' => $rejectionReason
        ]);
    }

    /**
     * Mark the file as failed.
     */
    public function markAsFailed(string $rejectionReason = null): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'rejection_reason' => $rejectionReason
        ]);
    }

    /**
     * Update validation statistics.
     */
    public function updateValidationStats(int $totalRows, int $validRows, int $invalidRows): bool
    {
        return $this->update([
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows
        ]);
    }

    /**
     * Calculate the validation success rate.
     */
    public function getValidationSuccessRate(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->valid_rows / $this->total_rows) * 100, 2);
    }

    /**
     * Get the human readable file size.
     */
    public function getFormattedFileSize(): string
    {
        if (!file_exists($this->file_path)) {
            return 'Unknown';
        }

        $bytes = filesize($this->file_path);
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get the duration since creation in human readable format.
     */
    public function getProcessingDuration(): string
    {
        return $this->created_at->diffForHumans(Carbon::now(), true);
    }
}