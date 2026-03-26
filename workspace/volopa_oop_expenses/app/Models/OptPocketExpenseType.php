<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Opt Pocket Expense Type Model
 * 
 * Manages expense type options with amount sign determination.
 * Used as a lookup table for expense type validation and amount sign logic.
 * 
 * @property int $id
 * @property string $option
 * @property string $amount_sign
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
     * Get the expenses that belong to this expense type.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type');
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
     * Get the amount sign for this expense type.
     *
     * @return string
     */
    public function getAmountSign(): string
    {
        return $this->amount_sign;
    }
}