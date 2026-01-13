<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class TccAccount extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'tcc_accounts';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'account_number',
        'account_name',
        'currency',
        'balance',
        'available_balance',
        'is_active',
        'account_type',
        'bank_name',
        'bank_code',
        'iban',
        'swift_code',
        'country_code',
    ];

    /**
     * The attributes that should be guarded from mass assignment.
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'client_id' => 'integer',
        'balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'is_active' => 'boolean',
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
     * Account type enum constants
     */
    public const TYPE_FUNDING = 'funding';
    public const TYPE_OPERATING = 'operating';
    public const TYPE_SETTLEMENT = 'settlement';
    public const TYPE_ESCROW = 'escrow';

    /**
     * Get all available account types
     */
    public static function getAccountTypes(): array
    {
        return [
            self::TYPE_FUNDING,
            self::TYPE_OPERATING,
            self::TYPE_SETTLEMENT,
            self::TYPE_ESCROW,
        ];
    }

    /**
     * Get the client that owns the TCC account.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get all mass payment files using this TCC account.
     */
    public function massPaymentFiles(): HasMany
    {
        return $this->hasMany(MassPaymentFile::class, 'tcc_account_id');
    }

    /**
     * Scope to filter by client ID (multi-tenant architecture).
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to filter by currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope to filter by account type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('account_type', $type);
    }

    /**
     * Scope to get active accounts only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get inactive accounts.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope to get funding accounts.
     */
    public function scopeFunding(Builder $query): Builder
    {
        return $query->where('account_type', self::TYPE_FUNDING);
    }

    /**
     * Scope to get operating accounts.
     */
    public function scopeOperating(Builder $query): Builder
    {
        return $query->where('account_type', self::TYPE_OPERATING);
    }

    /**
     * Scope to get settlement accounts.
     */
    public function scopeSettlement(Builder $query): Builder
    {
        return $query->where('account_type', self::TYPE_SETTLEMENT);
    }

    /**
     * Scope to get escrow accounts.
     */
    public function scopeEscrow(Builder $query): Builder
    {
        return $query->where('account_type', self::TYPE_ESCROW);
    }

    /**
     * Scope to get accounts with sufficient balance.
     */
    public function scopeWithSufficientBalance(Builder $query, float $amount): Builder
    {
        return $query->where('available_balance', '>=', $amount);
    }

    /**
     * Check if the account is active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Check if the account is inactive.
     */
    public function isInactive(): bool
    {
        return $this->is_active === false;
    }

    /**
     * Check if the account is a funding account.
     */
    public function isFundingAccount(): bool
    {
        return $this->account_type === self::TYPE_FUNDING;
    }

    /**
     * Check if the account is an operating account.
     */
    public function isOperatingAccount(): bool
    {
        return $this->account_type === self::TYPE_OPERATING;
    }

    /**
     * Check if the account is a settlement account.
     */
    public function isSettlementAccount(): bool
    {
        return $this->account_type === self::TYPE_SETTLEMENT;
    }

    /**
     * Check if the account is an escrow account.
     */
    public function isEscrowAccount(): bool
    {
        return $this->account_type === self::TYPE_ESCROW;
    }

    /**
     * Check if the account has sufficient balance.
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->available_balance >= $amount;
    }

    /**
     * Check if the account can be used for payments.
     */
    public function canBeUsedForPayments(): bool
    {
        return $this->isActive() && $this->isFundingAccount();
    }

    /**
     * Activate the account.
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * Deactivate the account.
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Update account balance.
     */
    public function updateBalance(float $newBalance): void
    {
        $this->update(['balance' => $newBalance]);
    }

    /**
     * Update available balance.
     */
    public function updateAvailableBalance(float $newAvailableBalance): void
    {
        $this->update(['available_balance' => $newAvailableBalance]);
    }

    /**
     * Reserve funds for payment processing.
     */
    public function reserveFunds(float $amount): bool
    {
        if (!$this->hasSufficientBalance($amount)) {
            return false;
        }

        $this->updateAvailableBalance($this->available_balance - $amount);
        return true;
    }

    /**
     * Release reserved funds.
     */
    public function releaseFunds(float $amount): void
    {
        $this->updateAvailableBalance($this->available_balance + $amount);
    }

    /**
     * Deduct funds from both balance and available balance.
     */
    public function deductFunds(float $amount): bool
    {
        if (!$this->hasSufficientBalance($amount)) {
            return false;
        }

        $this->update([
            'balance' => $this->balance - $amount,
            'available_balance' => $this->available_balance - $amount,
        ]);

        return true;
    }

    /**
     * Add funds to both balance and available balance.
     */
    public function addFunds(float $amount): void
    {
        $this->update([
            'balance' => $this->balance + $amount,
            'available_balance' => $this->available_balance + $amount,
        ]);
    }

    /**
     * Get formatted balance with currency.
     */
    public function getFormattedBalanceAttribute(): string
    {
        return number_format($this->balance, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted available balance with currency.
     */
    public function getFormattedAvailableBalanceAttribute(): string
    {
        return number_format($this->available_balance, 2) . ' ' . $this->currency;
    }

    /**
     * Get reserved amount (difference between balance and available balance).
     */
    public function getReservedAmountAttribute(): float
    {
        return $this->balance - $this->available_balance;
    }

    /**
     * Get formatted reserved amount with currency.
     */
    public function getFormattedReservedAmountAttribute(): string
    {
        return number_format($this->getReservedAmountAttribute(), 2) . ' ' . $this->currency;
    }

    /**
     * Get display-friendly account type name.
     */
    public function getAccountTypeDisplayAttribute(): string
    {
        return match ($this->account_type) {
            self::TYPE_FUNDING => 'Funding Account',
            self::TYPE_OPERATING => 'Operating Account',
            self::TYPE_SETTLEMENT => 'Settlement Account',
            self::TYPE_ESCROW => 'Escrow Account',
            default => 'Unknown Account Type',
        };
    }

    /**
     * Get account status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return $this->isActive() ? 'Active' : 'Inactive';
    }

    /**
     * Get masked account number for display.
     */
    public function getMaskedAccountNumberAttribute(): string
    {
        if (strlen($this->account_number) <= 4) {
            return $this->account_number;
        }

        return '****' . substr($this->account_number, -4);
    }

    /**
     * Get account identifier for display (name and masked number).
     */
    public function getDisplayIdentifierAttribute(): string
    {
        return $this->account_name . ' (' . $this->getMaskedAccountNumberAttribute() . ')';
    }

    /**
     * Get total mass payment files count for this account.
     */
    public function getMassPaymentFilesCountAttribute(): int
    {
        return $this->massPaymentFiles()->count();
    }

    /**
     * Get total mass payment files amount processed through this account.
     */
    public function getTotalProcessedAmountAttribute(): float
    {
        return $this->massPaymentFiles()
                    ->where('status', MassPaymentFile::STATUS_COMPLETED)
                    ->sum('total_amount');
    }

    /**
     * Boot the model and apply global scopes.
     */
    protected static function booted(): void
    {
        // Apply client scoping globally for multi-tenant architecture
        static::addGlobalScope('client', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });
    }
}