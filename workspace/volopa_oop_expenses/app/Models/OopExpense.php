<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OopExpense extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'oop_expenses';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'client_id',
        'date',
        'merchant_name',
        'amount',
        'currency',
        'status',
        'description',
        'receipt_url',
        'category',
        'converted_amount',
        'converted_currency',
        'fx_rate',
        'fx_commission',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'metadata',
        'is_reimbursable',
        'reimbursed_amount',
        'reimbursed_at',
        'expense_code',
        'project_code',
        'cost_center',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'date' => 'date',
        'amount' => 'decimal:2',
        'converted_amount' => 'decimal:2',
        'fx_rate' => 'decimal:6',
        'fx_commission' => 'decimal:4',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'metadata' => 'array',
        'is_reimbursable' => 'boolean',
        'reimbursed_amount' => 'decimal:2',
        'reimbursed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'status' => 'pending',
        'fx_commission' => 0.0000,
        'is_reimbursable' => true,
    ];

    /**
     * The possible status values.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PROCESSING = 'processing';

    /**
     * Get all possible status values.
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_PROCESSING,
        ];
    }

    /**
     * Get the user that owns the expense.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with the expense.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who approved this expense.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to only include expenses for a specific client.
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include expenses for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include expenses with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include pending expenses.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include approved expenses.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected expenses.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to only include processing expenses.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include reimbursable expenses.
     */
    public function scopeReimbursable($query)
    {
        return $query->where('is_reimbursable', true);
    }

    /**
     * Scope a query to only include non-reimbursable expenses.
     */
    public function scopeNonReimbursable($query)
    {
        return $query->where('is_reimbursable', false);
    }

    /**
     * Check if the expense is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the expense is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the expense is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if the expense is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the expense is reimbursable.
     */
    public function isReimbursable(): bool
    {
        return $this->is_reimbursable;
    }

    /**
     * Check if the expense has been reimbursed.
     */
    public function isReimbursed(): bool
    {
        return !is_null($this->reimbursed_at) && !is_null($this->reimbursed_amount);
    }

    /**
     * Check if the expense has FX conversion applied.
     */
    public function hasFxConversion(): bool
    {
        return !is_null($this->converted_amount) && !is_null($this->converted_currency) && !is_null($this->fx_rate);
    }

    /**
     * Mark the expense as approved.
     */
    public function approve(int $approverId): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Mark the expense as rejected.
     */
    public function reject(int $approverId, string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Mark the expense as processing.
     */
    public function markAsProcessing(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark the expense as reimbursed.
     */
    public function markAsReimbursed(float $amount): bool
    {
        return $this->update([
            'reimbursed_amount' => $amount,
            'reimbursed_at' => now(),
        ]);
    }

    /**
     * Apply FX conversion to the expense.
     */
    public function applyFxConversion(float $convertedAmount, string $convertedCurrency, float $fxRate, float $fxCommission = 0.0000): bool
    {
        return $this->update([
            'converted_amount' => $convertedAmount,
            'converted_currency' => $convertedCurrency,
            'fx_rate' => $fxRate,
            'fx_commission' => $fxCommission,
        ]);
    }

    /**
     * Get the effective amount (converted or original).
     */
    public function getEffectiveAmount(): float
    {
        return $this->converted_amount ?? $this->amount;
    }

    /**
     * Get the effective currency (converted or original).
     */
    public function getEffectiveCurrency(): string
    {
        return $this->converted_currency ?? $this->currency;
    }

    /**
     * Get formatted amount with currency.
     */
    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted converted amount with currency.
     */
    public function getFormattedConvertedAmount(): string
    {
        if ($this->hasFxConversion()) {
            return number_format($this->converted_amount, 2) . ' ' . $this->converted_currency;
        }

        return $this->getFormattedAmount();
    }
}