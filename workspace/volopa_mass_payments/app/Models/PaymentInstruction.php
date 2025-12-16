<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class PaymentInstruction extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'payment_file_id',
        'row_number',
        'beneficiary_name',
        'beneficiary_account',
        'amount',
        'currency',
        'settlement_method',
        'payment_purpose',
        'reference',
        'status',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payment_file_id' => 'integer',
        'row_number' => 'integer',
        'amount' => 'decimal:2',
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
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Available status values for the payment instruction.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Available currency codes.
     */
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_GBP = 'GBP';

    /**
     * Available settlement methods.
     */
    public const SETTLEMENT_SEPA = 'SEPA';
    public const SETTLEMENT_SWIFT = 'SWIFT';
    public const SETTLEMENT_FASTER_PAYMENTS = 'FASTER_PAYMENTS';
    public const SETTLEMENT_ACH = 'ACH';
    public const SETTLEMENT_WIRE = 'WIRE';

    /**
     * Get the payment file that owns the payment instruction.
     */
    public function paymentFile(): BelongsTo
    {
        return $this->belongsTo(PaymentFile::class);
    }

    /**
     * Scope a query to only include instructions with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include instructions for a specific payment file.
     */
    public function scopeForPaymentFile($query, int $paymentFileId)
    {
        return $query->where('payment_file_id', $paymentFileId);
    }

    /**
     * Scope a query to only include instructions with a specific currency.
     */
    public function scopeWithCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include instructions with a specific settlement method.
     */
    public function scopeWithSettlementMethod($query, string $settlementMethod)
    {
        return $query->where('settlement_method', $settlementMethod);
    }

    /**
     * Scope a query to only include pending instructions.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include processing instructions.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include completed instructions.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include failed instructions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to only include cancelled instructions.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope a query to only include processed instructions (completed or failed).
     */
    public function scopeProcessed($query)
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED
        ]);
    }

    /**
     * Scope a query to only include unprocessed instructions.
     */
    public function scopeUnprocessed($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING
        ]);
    }

    /**
     * Check if the payment instruction is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the payment instruction is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the payment instruction is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the payment instruction has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the payment instruction is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the payment instruction has been processed.
     */
    public function isProcessed(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED
        ]);
    }

    /**
     * Update the payment instruction status.
     */
    public function updateStatus(string $status): bool
    {
        $data = ['status' => $status];
        
        // Set processed_at timestamp if status is completed or failed
        if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED])) {
            $data['processed_at'] = Carbon::now();
        }

        return $this->update($data);
    }

    /**
     * Mark the payment instruction as processing.
     */
    public function markAsProcessing(): bool
    {
        return $this->updateStatus(self::STATUS_PROCESSING);
    }

    /**
     * Mark the payment instruction as completed.
     */
    public function markAsCompleted(): bool
    {
        return $this->updateStatus(self::STATUS_COMPLETED);
    }

    /**
     * Mark the payment instruction as failed.
     */
    public function markAsFailed(): bool
    {
        return $this->updateStatus(self::STATUS_FAILED);
    }

    /**
     * Mark the payment instruction as cancelled.
     */
    public function markAsCancelled(): bool
    {
        return $this->updateStatus(self::STATUS_CANCELLED);
    }

    /**
     * Get the formatted amount with currency symbol.
     */
    public function getFormattedAmountAttribute(): string
    {
        $symbols = [
            self::CURRENCY_USD => '$',
            self::CURRENCY_EUR => '€',
            self::CURRENCY_GBP => '£',
        ];

        $symbol = $symbols[$this->currency] ?? '';
        
        return $symbol . number_format($this->amount, 2);
    }

    /**
     * Get the settlement method display name.
     */
    public function getSettlementMethodDisplayAttribute(): string
    {
        $displayNames = [
            self::SETTLEMENT_SEPA => 'SEPA Credit Transfer',
            self::SETTLEMENT_SWIFT => 'SWIFT Wire Transfer',
            self::SETTLEMENT_FASTER_PAYMENTS => 'UK Faster Payments',
            self::SETTLEMENT_ACH => 'ACH Transfer',
            self::SETTLEMENT_WIRE => 'Wire Transfer',
        ];

        return $displayNames[$this->settlement_method] ?? $this->settlement_method;
    }

    /**
     * Get all valid status values.
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
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

    /**
     * Get all valid settlement methods.
     */
    public static function getValidSettlementMethods(): array
    {
        return [
            self::SETTLEMENT_SEPA,
            self::SETTLEMENT_SWIFT,
            self::SETTLEMENT_FASTER_PAYMENTS,
            self::SETTLEMENT_ACH,
            self::SETTLEMENT_WIRE,
        ];
    }

    /**
     * Get settlement methods available for a specific currency.
     */
    public static function getSettlementMethodsForCurrency(string $currency): array
    {
        $currencyMethods = [
            self::CURRENCY_USD => [
                self::SETTLEMENT_ACH,
                self::SETTLEMENT_WIRE,
                self::SETTLEMENT_SWIFT,
            ],
            self::CURRENCY_EUR => [
                self::SETTLEMENT_SEPA,
                self::SETTLEMENT_SWIFT,
                self::SETTLEMENT_WIRE,
            ],
            self::CURRENCY_GBP => [
                self::SETTLEMENT_FASTER_PAYMENTS,
                self::SETTLEMENT_SWIFT,
                self::SETTLEMENT_WIRE,
            ],
        ];

        return $currencyMethods[$currency] ?? [];
    }

    /**
     * Validate if a settlement method is valid for a given currency.
     */
    public static function isSettlementMethodValidForCurrency(string $settlementMethod, string $currency): bool
    {
        $validMethods = self::getSettlementMethodsForCurrency($currency);
        
        return in_array($settlementMethod, $validMethods);
    }

    /**
     * Get the minimum processing time in hours for a settlement method.
     */
    public function getMinimumProcessingTimeAttribute(): int
    {
        $processingTimes = [
            self::SETTLEMENT_SEPA => 1,
            self::SETTLEMENT_FASTER_PAYMENTS => 0, // Instant
            self::SETTLEMENT_ACH => 24,
            self::SETTLEMENT_WIRE => 4,
            self::SETTLEMENT_SWIFT => 24,
        ];

        return $processingTimes[$this->settlement_method] ?? 24;
    }

    /**
     * Get the maximum processing time in hours for a settlement method.
     */
    public function getMaximumProcessingTimeAttribute(): int
    {
        $processingTimes = [
            self::SETTLEMENT_SEPA => 24,
            self::SETTLEMENT_FASTER_PAYMENTS => 1,
            self::SETTLEMENT_ACH => 72,
            self::SETTLEMENT_WIRE => 24,
            self::SETTLEMENT_SWIFT => 120, // 5 days
        ];

        return $processingTimes[$this->settlement_method] ?? 120;
    }
}