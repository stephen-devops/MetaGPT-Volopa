<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\OptPocketExpenseTypeFactory;

/**
 * OptPocketExpenseType Model
 * 
 * Lookup table for expense types with predefined options and amount signs.
 * Determines whether an expense amount should be positive or negative based on type.
 * 
 * @property int $id
 * @property string $option
 * @property string $amount_sign
 * @property \DateTime $create_time
 * @property \DateTime|null $update_time
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
     * Disable Laravel's default timestamps as we use Volopa legacy pattern
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
     * Get all expenses that use this expense type.
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
     * Get the sign multiplier for amount calculation.
     *
     * @return int
     */
    public function getSignMultiplier(): int
    {
        return $this->amount_sign === 'positive' ? 1 : -1;
    }

    /**
     * Apply the correct sign to an amount based on this expense type.
     *
     * @param float $amount
     * @return float
     */
    public function applySignToAmount(float $amount): float
    {
        $absoluteAmount = abs($amount);
        return $this->amount_sign === 'positive' ? $absoluteAmount : -$absoluteAmount;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\OptPocketExpenseTypeFactory
     */
    protected static function newFactory()
    {
        return OptPocketExpenseTypeFactory::new();
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Set create_time on creation
        static::creating(function ($model) {
            if (is_null($model->create_time)) {
                $model->create_time = now();
            }
            if (is_null($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Update update_time on updating
        static::updating(function ($model) {
            $model->update_time = now();
        });
    }
}