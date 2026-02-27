<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'option',
        'amount_sign',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'option' => 'string',
        'amount_sign' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
    protected $attributes = [
        'amount_sign' => 'negative',
    ];

    /**
     * Define the valid amount signs.
     *
     * @var array<string>
     */
    public const AMOUNT_SIGNS = [
        'positive',
        'negative',
    ];

    /**
     * Get all pocket expenses that use this expense type.
     *
     * @return HasMany
     */
    public function pocketExpenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type');
    }

    /**
     * Scope a query to only include positive amount sign types.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePositive($query)
    {
        return $query->where('amount_sign', 'positive');
    }

    /**
     * Scope a query to only include negative amount sign types.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNegative($query)
    {
        return $query->where('amount_sign', 'negative');
    }

    /**
     * Scope a query to filter by option name.
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
     * Scope a query to filter by amount sign.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $amountSign
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByAmountSign($query, string $amountSign)
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
        return $this->amount_sign === 'positive';
    }

    /**
     * Check if this expense type has a negative amount sign.
     *
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->amount_sign === 'negative';
    }

    /**
     * Get the amount sign multiplier for calculations.
     * Returns 1 for positive, -1 for negative.
     *
     * @return int
     */
    public function getAmountMultiplier(): int
    {
        return $this->amount_sign === 'positive' ? 1 : -1;
    }

    /**
     * Get a formatted display name for the expense type.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->option;
    }

    /**
     * Get the expense type with amount sign information.
     *
     * @return string
     */
    public function getTypeWithSign(): string
    {
        $sign = $this->amount_sign === 'positive' ? '+' : '-';
        return sprintf('%s (%s)', $this->option, $sign);
    }

    /**
     * Determine if the given amount sign is valid.
     *
     * @param string $amountSign
     * @return bool
     */
    public static function isValidAmountSign(string $amountSign): bool
    {
        return in_array($amountSign, self::AMOUNT_SIGNS, true);
    }

    /**
     * Get all available amount signs.
     *
     * @return array<string>
     */
    public static function getAmountSigns(): array
    {
        return self::AMOUNT_SIGNS;
    }

    /**
     * Find an expense type by option name.
     *
     * @param string $option
     * @return static|null
     */
    public static function findByOption(string $option): ?static
    {
        return static::where('option', $option)->first();
    }

    /**
     * Get all expense types grouped by amount sign.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Collection>
     */
    public static function getGroupedByAmountSign(): array
    {
        $types = static::all();
        
        return [
            'positive' => $types->where('amount_sign', 'positive'),
            'negative' => $types->where('amount_sign', 'negative'),
        ];
    }

    /**
     * Create a new expense type with validation.
     *
     * @param string $option
     * @param string $amountSign
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function createType(string $option, string $amountSign = 'negative'): static
    {
        if (!self::isValidAmountSign($amountSign)) {
            throw new \InvalidArgumentException('Invalid amount sign provided');
        }

        return static::create([
            'option' => $option,
            'amount_sign' => $amountSign,
        ]);
    }

    /**
     * Get the count of pocket expenses using this type.
     *
     * @return int
     */
    public function getExpenseCount(): int
    {
        return $this->pocketExpenses()->count();
    }

    /**
     * Check if this expense type can be deleted (has no associated expenses).
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return $this->getExpenseCount() === 0;
    }

    /**
     * Get a string representation for logging purposes.
     *
     * @return string
     */
    public function getLogDescription(): string
    {
        return sprintf(
            'Expense Type ID %d: %s (amount_sign: %s)',
            $this->id,
            $this->option,
            $this->amount_sign
        );
    }
}