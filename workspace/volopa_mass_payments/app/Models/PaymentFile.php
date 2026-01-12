<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class PaymentFile extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'file_size',
        'status',
        'total_records',
        'valid_records',
        'invalid_records',
        'total_amount',
        'currency',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'file_size' => 'integer',
        'total_records' => 'integer',
        'valid_records' => 'integer',
        'invalid_records' => 'integer',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Available status values for the payment file.
     */
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_READY_FOR_PROCESSING = 'ready_for_processing';
    public const STATUS_PROCESSING_PAYMENTS = 'processing_payments';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Available currency codes.
     */
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_GBP = 'GBP';

    /**
     * Get the user that owns the payment file.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the payment instructions for the payment file.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class);
    }

    /**
     * Get the approvals for the payment file.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Get the validation errors for the payment file.
     */
    public function validationErrors(): HasMany
    {
        return $this->hasMany(ValidationError::class);
    }

    /**
     * Scope a query to only include files with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include files for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include files that require approval.
     */
    public function scopeRequiringApproval($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_VALIDATED
        ]);
    }

    /**
     * Scope a query to only include files ready for processing.
     */
    public function scopeReadyForProcessing($query)
    {
        return $query->whereIn('status', [
            self::STATUS_APPROVED,
            self::STATUS_READY_FOR_PROCESSING
        ]);
    }

    /**
     * Check if the payment file is in a processing state.
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_PROCESSING,
            self::STATUS_PROCESSING_PAYMENTS
        ]);
    }

    /**
     * Check if the payment file is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the payment file has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the payment file requires approval.
     */
    public function requiresApproval(): bool
    {
        return in_array($this->status, [
            self::STATUS_VALIDATED,
            self::STATUS_PENDING_APPROVAL
        ]);
    }

    /**
     * Check if the payment file is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the payment file is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Get the formatted file size in human readable format.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * Get the success rate as a percentage.
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }

        return round(($this->valid_records / $this->total_records) * 100, 2);
    }

    /**
     * Update the file statistics.
     */
    public function updateStatistics(int $totalRecords, int $validRecords, int $invalidRecords, float $totalAmount): bool
    {
        return $this->update([
            'total_records' => $totalRecords,
            'valid_records' => $validRecords,
            'invalid_records' => $invalidRecords,
            'total_amount' => $totalAmount,
        ]);
    }

    /**
     * Update the file status.
     */
    public function updateStatus(string $status): bool
    {
        return $this->update(['status' => $status]);
    }

    /**
     * Get all valid status values.
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_UPLOADED,
            self::STATUS_PROCESSING,
            self::STATUS_VALIDATED,
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_READY_FOR_PROCESSING,
            self::STATUS_PROCESSING_PAYMENTS,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Get all valid currency codes.
     */
    public static function getValidCurrencies(): array
    {
        return [
            self::CURRENCY_USD,
            self::CURRENCY_EUR,
            self::CURRENCY_GBP,
        ];
    }
}