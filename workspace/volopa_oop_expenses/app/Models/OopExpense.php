## Code: app/Models/OopExpense.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Carbon\Carbon;

class OopExpense extends Model
{
    use HasFactory;

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
        'description',
        'transaction_type',
        'currency',
        'amount',
        'merchant_address',
        'country',
        'source',
        'category',
        'custom_fields',
        'tracking_code_i',
        'tracking_code_ii',
        'project_id',
        'vat',
        'status',
        'receipt_path',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'custom_fields',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'vat' => 'decimal:2',
        'custom_fields' => 'array',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [
        'id',
        'approved_by',
        'approved_at',
        'created_at',
        'updated_at',
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'transaction_type' => 'Point of Sale',
        'status' => 'pending',
    ];

    /**
     * Valid status values for the expense.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    /**
     * Valid transaction types for the expense.
     */
    const TRANSACTION_TYPE_POINT_OF_SALE = 'Point of Sale';
    const TRANSACTION_TYPE_ATM_WITHDRAWAL = 'ATM Withdrawal';
    const TRANSACTION_TYPE_FEE_CHARGES = 'Fee & Charges';
    const TRANSACTION_TYPE_REFUND = 'Refund from Merchant';

    /**
     * Get all valid status values.
     */
    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    /**
     * Get all valid transaction types.
     */
    public static function getValidTransactionTypes(): array
    {
        return [
            self::TRANSACTION_TYPE_POINT_OF_SALE,
            self::TRANSACTION_TYPE_ATM_WITHDRAWAL,
            self::TRANSACTION_TYPE_FEE_CHARGES,
            self::TRANSACTION_TYPE_REFUND,
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
     * Get the client that owns the expense.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the user who approved the expense.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the project associated with the expense.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Scope a query to only include expenses for a specific client.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include expenses for a specific user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include expenses with a specific status.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include pending expenses.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include approved expenses.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected expenses.
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to include expenses within a date range.
     */
    public function scopeInDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
    }

    /**
     * Scope a query to include expenses with a specific currency.
     */
    public function scopeWithCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to include expenses with amount greater than or equal to a value.
     */
    public function scopeMinAmount(Builder $query, float $amount): Builder
    {
        return $query->where('amount', '>=', $amount);
    }

    /**
     * Scope a query to include expenses with amount less than or equal to a value.
     */
    public function scopeMaxAmount(Builder $query, float $amount): Builder
    {
        return $query->where('amount', '<=', $amount);
    }

    /**
     * Scope a query to search by merchant name.
     */
    public function scopeSearchMerchant(Builder $query, string $search): Builder
    {
        return $query->where('merchant_name', 'LIKE', '%' . $search . '%');
    }

    /**
     * Scope a query to include expenses with a specific transaction type.
     */
    public function scopeWithTransactionType(Builder $query, string $transactionType): Builder
    {
        return $query->where('transaction_type', $transactionType);
    }

    /**
     * Scope a query to order expenses by most recent first.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope a query to order expenses by date (most recent first).
     */
    public function scopeOrderByDate(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('date', $direction);
    }

    /**
     * Scope a query to order expenses by amount.
     */
    public function scopeOrderByAmount(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('amount', $direction);
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
     * Check if the expense can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this->isPending();
    }

    /**
     * Check if the expense can be rejected.
     */
    public function canBeRejected(): bool
    {
        return $this->isPending();
    }

    /**
     * Check if the expense can be updated.
     */
    public function canBeUpdated(): bool
    {
        return $this->isPending();
    }

    /**
     * Check if the expense can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return $this->isPending();
    }

    /**
     * Mark the expense as approved.
     */
    public function markAsApproved(int $approvedByUserId): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by = $approvedByUserId;
        $this->approved_at = now();

        return $this->save();
    }

    /**
     * Mark the expense as rejected.
     */
    public function markAsRejected(int $approvedByUserId): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->approved_by = $approvedByUserId;
        $this->approved_at = now();

        return $this->save();
    }

    /**
     * Get the formatted amount with currency symbol.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get the absolute amount (without negative sign).
     */
    public function getAbsoluteAmountAttribute(): float
    {
        return abs($this->amount);
    }

    /**
     * Check if the expense amount is negative.
     */
    public function isNegativeAmount(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Check if the expense amount is positive.
     */
    public function isPositiveAmount(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Get the status badge color for UI display.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_APPROVED => 'success',
            self::STATUS_REJECTED => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get the status label for UI display.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => 'Unknown',
        };
    }

    /**
     * Get the transaction type label for UI display.
     */
    public function getTransactionTypeLabelAttribute(): string
    {
        return $this->transaction_type;
    }

    /**
     * Check if the expense has a receipt.
     */
    public function hasReceipt(): bool
    {
        return !empty($this->receipt_path);
    }

    /**
     * Check if the expense has VAT.
     */
    public function hasVat(): bool
    {
        return !is_null($this->vat) && $this->vat > 0;
    }

    /**
     * Get the VAT amount based on the expense amount and VAT percentage.
     */
    public function getVatAmountAttribute(): float
    {
        if (!$this->hasVat()) {
            return 0.00;
        }

        return round(($this->amount * $this->vat) / 100, 2);
    }

    /**
     * Get the amount excluding VAT.
     */
    public function getAmountExcludingVatAttribute(): float
    {
        if (!$this->hasVat()) {
            return $this->amount;
        }

        return round($this->amount - $this->getVatAmountAttribute(), 2);
    }

    /**
     * Check if the expense has custom fields.
     */
    public function hasCustomFields(): bool
    {
        return !empty($this->custom_fields) && is_array($this->custom_fields);
    }

    /**
     * Get a specific custom field value.
     */
    public function getCustomField(string $key, mixed $default = null): mixed
    {
        if (!$this->hasCustomFields()) {
            return $default;
        }

        return $this->custom_fields[$key] ?? $default;
    }

    /**
     * Set a custom field value.
     */
    public function setCustomField(string $key, mixed $value): void
    {
        $customFields = $this->custom_fields ?? [];
        $customFields[$key] = $value;
        $this->custom_fields = $customFields;
    }

    /**
     * Remove a custom field.
     */
    public function removeCustomField(string $key): void
    {
        if (!$this->hasCustomFields()) {
            return;
        }

        $customFields = $this->custom_fields;
        unset($customFields[$key]);
        $this->custom_fields = $customFields;
    }

    /**
     * Check if the expense belongs to a specific user.
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Check if the expense belongs to a specific client.
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Get the age of the expense in days.
     */
    public function getAgeInDaysAttribute(): int
    {
        return now()->diffInDays($this->created_at);
    }

    /**
     * Check if the expense is older than the specified number of days.
     */
    public function isOlderThan(int $days): bool
    {
        return $this->getAgeInDaysAttribute() > $days;
    }

    /**
     * Get expenses that need attention (pending for too long).
     */
    public function scopeNeedsAttention(Builder $query, int $days = 7): Builder
    {
        return $query->pending()
                    ->where('created_at', '<', now()->subDays($days));
    }

    /**
     * Get the total amount for a collection of expenses.
     */
    public static function getTotalAmount($expenses): float
    {
        if (is_a($expenses, Builder::class)) {
            return $expenses->sum('amount');
        }

        return $expenses->sum('amount');
    }

    /**
     * Get expenses grouped by currency.
     */
    public function scopeGroupByCurrency(Builder $query): Builder
    {
        return $query->selectRaw('currency, COUNT(*) as count, SUM(amount) as total_amount')
                    ->groupBy('currency');
    }

    /**
     * Get expenses grouped by status.
     */
    public function scopeGroupByStatus(Builder $query): Builder
    {
        return $query->selectRaw('status, COUNT(*)