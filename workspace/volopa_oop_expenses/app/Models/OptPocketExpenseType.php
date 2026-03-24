<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * OptPocketExpenseType Model
 * 
 * Represents expense type options for pocket expenses with amount sign conventions.
 * This model manages the predefined expense types: ATM Withdrawal, Point of Sale,
 * Fee & Charges, and Refund from Merchant, each with their corresponding amount
 * sign (positive for refunds, negative for all others).
 * 
 * @property int $id Primary key
 * @property string $option Expense type name
 * @property string $amount_sign Amount sign convention (positive|negative)
 * @property Carbon|null $create_time Volopa legacy creation timestamp
 * @property Carbon|null $update_time Volopa legacy update timestamp
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpense> $pocketExpenses
 */
class OptPocketExpenseType extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'opt_pocket_expense_type';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped using Laravel conventions.
     * We use Volopa legacy timestamp pattern instead.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'option',
        'amount_sign',
        'create_time',
        'update_time',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'option' => 'string',
        'amount_sign' => 'string',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Boot the model and set up event listeners.
     * Automatically set create_time and update_time using Volopa legacy pattern.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->create_time)) {
                $model->create_time = Carbon::now();
            }
            if (empty($model->update_time)) {
                $model->update_time = Carbon::now();
            }
        });

        static::updating(function ($model) {
            $model->update_time = Carbon::now();
        });
    }

    /**
     * Get all pocket expenses that use this expense type.
     * 
     * @return HasMany<\App\Models\PocketExpense>
     */
    public function pocketExpenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type', 'id');
    }

    /**
     * Scope a query to only include negative amount sign expense types.
     * These are the majority of expense types (ATM Withdrawal, Point of Sale, Fee & Charges).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNegative($query)
    {
        return $query->where('amount_sign', 'negative');
    }

    /**
     * Scope a query to only include positive amount sign expense types.
     * These are typically refund types (Refund from Merchant).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePositive($query)
    {
        return $query->where('amount_sign', 'positive');
    }

    /**
     * Scope a query to find an expense type by option name.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $option
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByOption($query, string $option)
    {
        return $query->where('option', $option);
    }

    /**
     * Check if this expense type requires a positive amount sign.
     * 
     * @return bool
     */
    public function isPositiveAmount(): bool
    {
        return $this->amount_sign === 'positive';
    }

    /**
     * Check if this expense type requires a negative amount sign.
     * 
     * @return bool
     */
    public function isNegativeAmount(): bool
    {
        return $this->amount_sign === 'negative';
    }

    /**
     * Get the display name for the expense type.
     * This is the same as the option field but provides semantic clarity.
     * 
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->option;
    }

    /**
     * Get a formatted string showing the expense type and its amount sign.
     * Useful for administrative displays and debugging.
     * 
     * @return string
     */
    public function getFormattedTypeAttribute(): string
    {
        $sign = $this->amount_sign === 'positive' ? '+' : '-';
        return "{$this->option} ({$sign})";
    }

    /**
     * Check if this is the ATM Withdrawal expense type.
     * 
     * @return bool
     */
    public function isAtmWithdrawal(): bool
    {
        return $this->option === 'ATM Withdrawal';
    }

    /**
     * Check if this is the Point of Sale expense type.
     * 
     * @return bool
     */
    public function isPointOfSale(): bool
    {
        return $this->option === 'Point of Sale';
    }

    /**
     * Check if this is the Fee & Charges expense type.
     * 
     * @return bool
     */
    public function isFeeAndCharges(): bool
    {
        return $this->option === 'Fee & Charges';
    }

    /**
     * Check if this is the Refund from Merchant expense type.
     * 
     * @return bool
     */
    public function isRefundFromMerchant(): bool
    {
        return $this->option === 'Refund from Merchant';
    }

    /**
     * Get all default expense type options as defined in the platform constraints.
     * Returns an array of option names that should be seeded in the database.
     * 
     * @return array<string>
     */
    public static function getDefaultOptions(): array
    {
        return [
            'ATM Withdrawal',
            'Point of Sale',
            'Fee & Charges',
            'Refund from Merchant',
        ];
    }

    /**
     * Get the amount sign for a given expense type option.
     * Used during seeding and validation to ensure consistency.
     * 
     * @param string $option
     * @return string
     */
    public static function getAmountSignForOption(string $option): string
    {
        return match ($option) {
            'Refund from Merchant' => 'positive',
            'ATM Withdrawal', 'Point of Sale', 'Fee & Charges' => 'negative',
            default => 'negative', // Default to negative for any custom expense types
        };
    }

    /**
     * Create a new expense type with proper validation and defaults.
     * This method ensures amount_sign is set correctly based on the option.
     * 
     * @param string $option
     * @param string|null $amountSign If null, will be determined automatically
     * @return static
     */
    public static function createExpenseType(string $option, ?string $amountSign = null): static
    {
        $amountSign = $amountSign ?: static::getAmountSignForOption($option);
        
        return static::create([
            'option' => $option,
            'amount_sign' => $amountSign,
        ]);
    }

    /**
     * Get expense type by option name with caching considerations.
     * This method can be cached in production for better performance.
     * 
     * @param string $option
     * @return static|null
     */
    public static function findByOption(string $option): ?static
    {
        return static::where('option', $option)->first();
    }

    /**
     * Get all expense types ordered by option name.
     * Useful for dropdowns and admin interfaces.
     * 
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getAllOrdered(): \Illuminate\Database\Eloquent\Collection
    {
        return static::orderBy('option')->get();
    }

    /**
     * Get expense types that result in negative amounts (debits).
     * 
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getNegativeTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('amount_sign', 'negative')->orderBy('option')->get();
    }

    /**
     * Get expense types that result in positive amounts (credits/refunds).
     * 
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getPositiveTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('amount_sign', 'positive')->orderBy('option')->get();
    }

    /**
     * Convert the model instance to an array suitable for API responses.
     * Excludes internal timestamps and includes computed attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes for API responses
        $array['display_name'] = $this->getDisplayNameAttribute();
        $array['is_positive_amount'] = $this->isPositiveAmount();
        $array['is_negative_amount'] = $this->isNegativeAmount();
        
        return $array;
    }

    /**
     * Convert the model to its string representation.
     * Returns the expense type option name.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->option;
    }
}