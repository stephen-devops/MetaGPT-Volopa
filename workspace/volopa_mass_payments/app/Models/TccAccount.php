## Code: app/Models/TccAccount.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class TccAccount extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'tcc_accounts';

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
        'account_name',
        'account_number',
        'account_type',
        'currency',
        'balance',
        'available_balance',
        'reserved_balance',
        'status',
        'bank_name',
        'bank_code',
        'bank_address',
        'account_holder_name',
        'account_holder_address',
        'iban',
        'swift_code',
        'routing_number',
        'sort_code',
        'description',
        'is_default',
        'daily_limit',
        'monthly_limit',
        'minimum_balance',
        'overdraft_limit',
        'interest_rate',
        'fee_structure',
        'supported_currencies',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'string',
        'account_name' => 'string',
        'account_number' => 'string',
        'account_type' => 'string',
        'currency' => 'string',
        'balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'reserved_balance' => 'decimal:2',
        'status' => 'string',
        'bank_name' => 'string',
        'bank_code' => 'string',
        'bank_address' => 'string',
        'account_holder_name' => 'string',
        'account_holder_address' => 'string',
        'iban' => 'string',
        'swift_code' => 'string',
        'routing_number' => 'string',
        'sort_code' => 'string',
        'description' => 'string',
        'is_default' => 'boolean',
        'daily_limit' => 'decimal:2',
        'monthly_limit' => 'decimal:2',
        'minimum_balance' => 'decimal:2',
        'overdraft_limit' => 'decimal:2',
        'interest_rate' => 'decimal:4',
        'fee_structure' => 'array',
        'supported_currencies' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be guarded from mass assignment.
     */
    protected $guarded = [
        'id',
        'balance',
        'available_balance',
        'reserved_balance',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
        'account_number',
        'iban',
        'routing_number',
        'sort_code',
        'balance',
        'available_balance',
        'reserved_balance',
    ];

    /**
     * Account type constants
     */
    public const TYPE_CURRENT = 'current';
    public const TYPE_SAVINGS = 'savings';
    public const TYPE_BUSINESS = 'business';
    public const TYPE_ESCROW = 'escrow';
    public const TYPE_TRUST = 'trust';

    /**
     * Valid account types
     */
    public const VALID_TYPES = [
        self::TYPE_CURRENT,
        self::TYPE_SAVINGS,
        self::TYPE_BUSINESS,
        self::TYPE_ESCROW,
        self::TYPE_TRUST,
    ];

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_CLOSED = 'closed';

    /**
     * Valid status values
     */
    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_FROZEN,
        self::STATUS_CLOSED,
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Apply client scoping globally
        static::addGlobalScope('client', function (Builder $query) {
            if (auth()->check() && auth()->user()->client_id) {
                $query->where('client_id', auth()->user()->client_id);
            }
        });

        // Set default values on creating
        static::creating(function ($account) {
            if (!$account->status) {
                $account->status = self::STATUS_ACTIVE;
            }
            if (!$account->account_type) {
                $account->account_type = self::TYPE_CURRENT;
            }
            if ($account->balance === null) {
                $account->balance = 0.00;
            }
            if ($account->available_balance === null) {
                $account->available_balance = 0.00;
            }
            if ($account->reserved_balance === null) {
                $account->reserved_balance = 0.00;
            }
            if ($account->is_default === null) {
                $account->is_default = false;
            }
            if ($account->daily_limit === null) {
                $account->daily_limit = 0.00;
            }
            if ($account->monthly_limit === null) {
                $account->monthly_limit = 0.00;
            }
            if ($account->minimum_balance === null) {
                $account->minimum_balance = 0.00;
            }
            if ($account->overdraft_limit === null) {
                $account->overdraft_limit = 0.00;
            }
            if ($account->interest_rate === null) {
                $account->interest_rate = 0.0000;
            }
            if (!$account->fee_structure) {
                $account->fee_structure = [];
            }
            if (!$account->supported_currencies) {
                $account->supported_currencies = [$account->currency];
            }
            if (!$account->metadata) {
                $account->metadata = [];
            }
        });
    }

    /**
     * Get the client that owns the TCC account.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the mass payment files for the TCC account.
     */
    public function massPaymentFiles(): HasMany
    {
        return $this->hasMany(MassPaymentFile::class, 'tcc_account_id', 'id');
    }

    /**
     * Scope a query to filter by client ID.
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by account type.
     */
    public function scopeByType(Builder $query, string $accountType): Builder
    {
        return $query->where('account_type', $accountType);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Scope a query to filter by supported currency.
     */
    public function scopeBySupportedCurrency(Builder $query, string $currency): Builder
    {
        return $query->whereJsonContains('supported_currencies', strtoupper($currency));
    }

    /**
     * Scope a query to get active accounts.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope a query to get default accounts.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to get accounts with sufficient balance.
     */
    public function scopeWithSufficientBalance(Builder $query, float $amount): Builder
    {
        return $query->where('available_balance', '>=', $amount);
    }

    /**
     * Scope a query to get business accounts.
     */
    public function scopeBusiness(Builder $query): Builder
    {
        return $query->where('account_type', self::TYPE_BUSINESS);
    }

    /**
     * Check if the account is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the account is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /**
     * Check if the account is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Check if the account is frozen.
     */
    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    /**
     * Check if the account is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Check if the account is a business account.
     */
    public function isBusiness(): bool
    {
        return $this->account_type === self::TYPE_BUSINESS;
    }

    /**
     * Check if the account is the default account.
     */
    public function isDefault(): bool
    {
        return $this->is_default;
    }

    /**
     * Check if the account can be used for transactions.
     */
    public function canTransact(): bool
    {
        return $this->isActive();
    }

    /**
     * Check if the account has sufficient balance for a transaction.
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->available_balance >= $amount;
    }

    /**
     * Check if a transaction amount is within daily limit.
     */
    public function isWithinDailyLimit(float $amount): bool
    {
        if ($this->daily_limit <= 0) {
            return true; // No limit set
        }

        // This would typically check against daily transaction totals
        // For now, we'll just check against the available balance
        return $amount <= $this->daily_limit;
    }

    /**
     * Check if a transaction amount is within monthly limit.
     */
    public function isWithinMonthlyLimit(float $amount): bool
    {
        if ($this->monthly_limit <= 0) {
            return true; // No limit set
        }

        // This would typically check against monthly transaction totals
        // For now, we'll just check against the available balance
        return $amount <= $this->monthly_limit;
    }

    /**
     * Check if the account supports a specific currency.
     */
    public function supportsCurrency(string $currency): bool
    {
        $supportedCurrencies = $this->supported_currencies ?? [];
        return in_array(strtoupper($currency), $supportedCurrencies);
    }

    /**
     * Check if the account balance is above minimum required.
     */
    public function isAboveMinimumBalance(): bool
    {
        return $this->balance >= $this->minimum_balance;
    }

    /**
     * Get the account identifier for display.
     */
    public function getAccountIdentifier(): string
    {
        return $this->iban ?: ($this->account_number ?: $this->id);
    }

    /**
     * Get the formatted balance with currency.
     */
    public function getFormattedBalance(): string
    {
        return number_format($this->balance, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get the formatted available balance with currency.
     */
    public function getFormattedAvailableBalance(): string
    {
        return number_format($this->available_balance, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get the formatted bank details.
     */
    public function getFormattedBankDetails(): string
    {
        $bankParts = array_filter([
            $this->bank_name,
            $this->swift_code,
            $this->bank_address,
        ]);

        return implode(', ', $bankParts);
    }

    /**
     * Get the utilization percentage of available balance.
     */
    public function getUtilizationPercentage(): float
    {
        if ($this->balance <= 0) {
            return 0.0;
        }

        $utilized = $this->balance - $this->available_balance;
        return round(($utilized / $this->balance) * 100, 2);
    }

    /**
     * Add a supported currency.
     */
    public function addSupportedCurrency(string $currency): bool
    {
        $currencies = $this->supported_currencies ?? [];
        $currency = strtoupper($currency);

        if (!in_array($currency, $currencies)) {
            $currencies[] = $currency;
            $this->supported_currencies = $currencies;
            return $this->save();
        }

        return true;
    }

    /**
     * Remove a supported currency.
     */
    public function removeSupportedCurrency(string $currency): bool
    {
        $currencies = $this->supported_currencies ?? [];
        $currency = strtoupper($currency);

        $index = array_search($currency, $currencies);
        if ($index !== false) {
            unset($currencies[$index]);
            $this->supported_currencies = array_values($currencies);
            return $this->save();
        }

        return true;
    }

    /**
     * Reserve balance for a transaction.
     */
    public function reserveBalance(float $amount): bool
    {
        if (!$this->hasSufficientBalance($amount)) {
            return false;
        }

        $this->available_balance -= $amount;
        $this->reserved_balance += $amount;

        return $this->save();
    }

    /**
     * Release reserved balance.
     */
    public function releaseReservedBalance(float $amount): bool
    {
        if ($this->reserved_balance < $amount) {
            return false;
        }

        $this->available_balance += $amount;
        $this->reserved_balance -= $amount;

        return $this->