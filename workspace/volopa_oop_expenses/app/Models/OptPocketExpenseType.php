<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OptPocketExpenseType Model
 * 
 * Manages expense types and their associated amount signs.
 * Determines whether an expense type should have positive or negative amounts.
 * 
 * @property int $id
 * @property string $option
 * @property string $amount_sign
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
     * This table does not use Laravel's default timestamps.
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
     * Get all expenses that use this expense type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type', 'id');
    }

    /**
     * Scope a query to only include expense types with positive amount sign.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePositive($query)
    {
        return $query->where('amount_sign', 'positive');
    }

    /**
     * Scope a query to only include expense types with negative amount sign.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNegative($query)
    {
        return $query->where('amount_sign', 'negative');
    }

    /**
     * Scope a query to filter by expense type option.
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
     * Get the sign multiplier for this expense type.
     * Returns 1 for positive amounts, -1 for negative amounts.
     *
     * @return int
     */
    public function getSignMultiplier(): int
    {
        return $this->amount_sign === 'positive' ? 1 : -1;
    }
}