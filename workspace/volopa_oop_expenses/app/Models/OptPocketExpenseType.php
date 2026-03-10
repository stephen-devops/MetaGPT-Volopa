<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * OptPocketExpenseType Model
 * 
 * Manages expense type categories with their corresponding amount signs.
 * This is a lookup table that categorizes different types of out-of-pocket expenses
 * and determines whether they result in positive (refund) or negative (expense) amounts.
 * 
 * @property int $id Primary key for expense type
 * @property string $option The expense type name/option
 * @property string $amount_sign Whether this expense type results in positive (refund) or negative (expense) amounts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PocketExpense> $expenses
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
     * Indicates if the model should be timestamped.
     * This lookup table doesn't use Laravel timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'option',
        'amount_sign',
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
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount_sign' => 'negative',
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
    protected static array $defaults = [
        'amount_sign' => 'negative',
    ];

    /**
     * Valid values for amount_sign enum.
     *
     * @var array<int, string>
     */
    public const AMOUNT_SIGN_POSITIVE = 'positive';
    public const AMOUNT_SIGN_NEGATIVE = 'negative';

    /**
     * All valid amount sign values.
     *
     * @var array<int, string>
     */
    public const VALID_AMOUNT_SIGNS = [
        self::AMOUNT_SIGN_POSITIVE,
        self::AMOUNT_SIGN_NEGATIVE,
    ];

    /**
     * Common expense type options.
     *
     * @var array<string, string>
     */
    public const EXPENSE_TYPES = [
        'Travel' => self::AMOUNT_SIGN_NEGATIVE,
        'Meals' => self::AMOUNT_SIGN_NEGATIVE,
        'Entertainment' => self::AMOUNT_SIGN_NEGATIVE,
        'Office Supplies' => self::AMOUNT_SIGN_NEGATIVE,
        'Transportation' => self::AMOUNT_SIGN_NEGATIVE,
        'Accommodation' => self::AMOUNT_SIGN_NEGATIVE,
        'Communications' => self::AMOUNT_SIGN_NEGATIVE,
        'Training' => self::AMOUNT_SIGN_NEGATIVE,
        'Equipment' => self::AMOUNT_SIGN_NEGATIVE,
        'Other' => self::AMOUNT_SIGN_NEGATIVE,
        'Refund' => self::AMOUNT_SIGN_POSITIVE,
    ];

    /**
     * Get the expenses that belong to this expense type.
     *
     * @return HasMany<PocketExpense>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type', 'id');
    }

    /**
     * Scope a query to only include expense types with positive amount sign.
     *
     * @param Builder<OptPocketExpenseType> $query
     * @return Builder<OptPocketExpenseType>
     */
    public function scopePositive(Builder $query): Builder
    {
        return $query->where('amount_sign', self::AMOUNT_SIGN_POSITIVE);
    }

    /**
     * Scope a query to only include expense types with negative amount sign.
     *
     * @param Builder<OptPocketExpenseType> $query
     * @return Builder<OptPocketExpenseType>
     */
    public function scopeNegative(Builder $query): Builder
    {
        return $query->where('amount_sign', self::AMOUNT_SIGN_NEGATIVE);
    }

    /**
     * Scope a query to filter by specific option name.
     *
     * @param Builder<OptPocketExpenseType> $query
     * @param string $option
     * @return Builder<OptPocketExpenseType>
     */
    public function scopeByOption(Builder $query, string $option): Builder
    {
        return $query->where('option', $option);
    }

    /**
     * Scope a query to filter by amount sign type.
     *
     * @param Builder<OptPocketExpenseType> $query
     * @param string $amountSign
     * @return Builder<OptPocketExpenseType>
     */
    public function scopeByAmountSign(Builder $query, string $amountSign): Builder
    {
        return $query->where('amount_sign', $amountSign);
    }

    /**
     * Check if this expense type has a positive amount sign.
     *
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->amount_sign === self::AMOUNT_SIGN_POSITIVE;
    }

    /**
     * Check if this expense type has a negative amount sign.
     *
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->amount_sign === self::AMOUNT_SIGN_NEGATIVE;
    }

    /**
     * Check if this is a refund type expense.
     *
     * @return bool
     */
    public function isRefund(): bool
    {
        return $this->option === 'Refund' && $this->isPositive();
    }

    /**
     * Check if this is a regular expense type.
     *
     * @return bool
     */
    public function isRegularExpense(): bool
    {
        return !$this->isRefund();
    }

    /**
     * Get a human-readable description of this expense type.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $sign = $this->isPositive() ? 'Credit' : 'Debit';
        return "{$this->option} ({$sign})";
    }

    /**
     * Get the multiplier for calculating amounts based on amount sign.
     * Returns 1 for positive amounts, -1 for negative amounts.
     *
     * @return int
     */
    public function getAmountMultiplier(): int
    {
        return $this->isPositive() ? 1 : -1;
    }

    /**
     * Apply the amount sign to a given amount.
     *
     * @param float $amount
     * @return float
     */
    public function applyAmountSign(float $amount): float
    {
        $absoluteAmount = abs($amount);
        return $this->isPositive() ? $absoluteAmount : -$absoluteAmount;
    }

    /**
     * Find an expense type by option name.
     *
     * @param string $option
     * @return OptPocketExpenseType|null
     */
    public static function findByOption(string $option): ?OptPocketExpenseType
    {
        return static::where('option', $option)->first();
    }

    /**
     * Get all expense types with positive amount signs (refunds).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, OptPocketExpenseType>
     */
    public static function getRefundTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return static::positive()->get();
    }

    /**
     * Get all expense types with negative amount signs (regular expenses).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, OptPocketExpenseType>
     */
    public static function getExpenseTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return static::negative()->get();
    }

    /**
     * Get all expense type options as a key-value array.
     *
     * @return array<int, string>
     */
    public static function getOptionsArray(): array
    {
        return static::orderBy('option')->pluck('option', 'id')->toArray();
    }

    /**
     * Get expense types grouped by amount sign.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, OptPocketExpenseType>>
     */
    public static function getGroupedByAmountSign(): array
    {
        $types = static::orderBy('option')->get();

        return [
            'positive' => $types->filter(fn($type) => $type->isPositive()),
            'negative' => $types->filter(fn($type) => $type->isNegative()),
        ];
    }

    /**
     * Validate if an amount sign is valid.
     *
     * @param string $amountSign
     * @return bool
     */
    public static function isValidAmountSign(string $amountSign): bool
    {
        return in_array($amountSign, self::VALID_AMOUNT_SIGNS, true);
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Ensure amount_sign is valid before saving
        static::saving(function (OptPocketExpenseType $model) {
            if (!self::isValidAmountSign($model->amount_sign)) {
                throw new \InvalidArgumentException(
                    "Invalid amount_sign value: {$model->amount_sign}. Must be one of: " . 
                    implode(', ', self::VALID_AMOUNT_SIGNS)
                );
            }
        });
    }

    /**
     * Get the count of associated expenses for this type.
     *
     * @return int
     */
    public function getExpenseCount(): int
    {
        return $this->expenses()->count();
    }

    /**
     * Check if this expense type is being used by any expenses.
     *
     * @return bool
     */
    public function hasExpenses(): bool
    {
        return $this->getExpenseCount() > 0;
    }

    /**
     * Get the most commonly used expense types.
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, OptPocketExpenseType>
     */
    public static function getMostUsed(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return static::withCount('expenses')
                    ->orderBy('expenses_count', 'desc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes
        $array['is_positive'] = $this->isPositive();
        $array['is_negative'] = $this->isNegative();
        $array['is_refund'] = $this->isRefund();
        $array['description'] = $this->getDescription();
        $array['amount_multiplier'] = $this->getAmountMultiplier();
        
        return $array;
    }
}