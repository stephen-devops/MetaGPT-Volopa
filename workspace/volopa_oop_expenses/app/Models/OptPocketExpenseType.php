<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OptPocketExpenseType Model
 * 
 * Represents expense type lookup table with amount sign configuration.
 * Defines whether expense amounts should be positive or negative.
 * 
 * @property int $id
 * @property string $option Expense type option name
 * @property string $amount_sign Sign applied to expense amount (positive/negative)
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
     * Indicates if the model should be timestamped.
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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount_sign' => 'negative',
    ];

    /**
     * The possible values for amount_sign enum.
     *
     * @var array<int, string>
     */
    public const AMOUNT_SIGN_VALUES = [
        'positive',
        'negative',
    ];

    /**
     * Get the pocket expenses that belong to this expense type.
     *
     * @return HasMany<\App\Models\PocketExpense>
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
     * Scope a query to filter by specific option name.
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
     * Check if this expense type applies positive amount sign.
     *
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->amount_sign === 'positive';
    }

    /**
     * Check if this expense type applies negative amount sign.
     *
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->amount_sign === 'negative';
    }

    /**
     * Get the numeric multiplier based on amount sign.
     * Returns 1 for positive, -1 for negative.
     *
     * @return int
     */
    public function getAmountMultiplier(): int
    {
        return $this->amount_sign === 'positive' ? 1 : -1;
    }

    /**
     * Apply the amount sign to a given amount value.
     *
     * @param float|int $amount
     * @return float
     */
    public function applyAmountSign(float|int $amount): float
    {
        $absoluteAmount = abs($amount);
        return $this->amount_sign === 'positive' ? $absoluteAmount : -$absoluteAmount;
    }

    /**
     * Get all available expense type options as key-value pairs.
     *
     * @return array<int, string>
     */
    public static function getOptionsArray(): array
    {
        return static::pluck('option', 'id')->toArray();
    }

    /**
     * Get expense types grouped by amount sign.
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
     * Find expense type by option name.
     *
     * @param string $option
     * @return static|null
     */
    public static function findByOption(string $option): ?static
    {
        return static::where('option', $option)->first();
    }
}