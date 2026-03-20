<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OptPocketExpenseType Model
 * 
 * Represents expense type options with amount sign configuration.
 * Used as a lookup table for categorizing pocket expenses.
 * 
 * @property int $id
 * @property string $option
 * @property string $amount_sign
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpense> $expenses
 * @property-read int|null $expenses_count
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
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount_sign' => 'negative',
    ];

    /**
     * Get the expenses that belong to this expense type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PocketExpense>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type', 'id');
    }

    /**
     * Scope a query to only include positive amount types (refunds).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePositiveAmount($query)
    {
        return $query->where('amount_sign', 'positive');
    }

    /**
     * Scope a query to only include negative amount types (expenses).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNegativeAmount($query)
    {
        return $query->where('amount_sign', 'negative');
    }

    /**
     * Scope a query to find expense type by option name.
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
     * Check if this expense type represents a refund (positive amount).
     *
     * @return bool
     */
    public function isRefund(): bool
    {
        return $this->amount_sign === 'positive';
    }

    /**
     * Check if this expense type represents an expense (negative amount).
     *
     * @return bool
     */
    public function isExpense(): bool
    {
        return $this->amount_sign === 'negative';
    }

    /**
     * Get the multiplier for amount calculations based on amount sign.
     * Returns 1 for positive (refund), -1 for negative (expense).
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
     * @param float $amount
     * @return float
     */
    public function applyAmountSign(float $amount): float
    {
        return abs($amount) * $this->getAmountMultiplier();
    }

    /**
     * Get a display-friendly name for the amount sign.
     *
     * @return string
     */
    public function getAmountSignDisplayAttribute(): string
    {
        return $this->amount_sign === 'positive' ? 'Refund' : 'Expense';
    }

    /**
     * Get the count of associated expenses.
     *
     * @return int
     */
    public function getExpensesCountAttribute(): int
    {
        return $this->expenses()->count();
    }

    /**
     * Check if this expense type can be safely deleted.
     * Cannot delete if there are associated expenses.
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return $this->expenses()->count() === 0;
    }

    /**
     * Get all expense types formatted for dropdown/select options.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getSelectOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return static::orderBy('option', 'asc')->get();
    }

    /**
     * Get all refund types formatted for dropdown/select options.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getRefundOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return static::positiveAmount()->orderBy('option', 'asc')->get();
    }

    /**
     * Get all expense types (non-refund) formatted for dropdown/select options.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getExpenseOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return static::negativeAmount()->orderBy('option', 'asc')->get();
    }

    /**
     * Find expense type by option name with caching.
     *
     * @param string $option
     * @return \App\Models\OptPocketExpenseType|null
     */
    public static function findByOption(string $option): ?OptPocketExpenseType
    {
        return static::byOption($option)->first();
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Ensure amount_sign has a valid value on creation
        static::creating(function (OptPocketExpenseType $expenseType) {
            if (empty($expenseType->amount_sign)) {
                $expenseType->amount_sign = 'negative';
            }
            
            // Validate amount_sign enum values
            if (!in_array($expenseType->amount_sign, ['positive', 'negative'])) {
                throw new \InvalidArgumentException('Invalid amount_sign value. Must be "positive" or "negative".');
            }
        });

        // Validate amount_sign on updates
        static::updating(function (OptPocketExpenseType $expenseType) {
            if ($expenseType->isDirty('amount_sign')) {
                if (!in_array($expenseType->amount_sign, ['positive', 'negative'])) {
                    throw new \InvalidArgumentException('Invalid amount_sign value. Must be "positive" or "negative".');
                }
            }
        });

        // Prevent deletion if there are associated expenses
        static::deleting(function (OptPocketExpenseType $expenseType) {
            if (!$expenseType->canDelete()) {
                throw new \RuntimeException('Cannot delete expense type that has associated expenses.');
            }
        });

        // Log expense type changes for audit purposes
        static::updated(function (OptPocketExpenseType $expenseType) {
            if ($expenseType->isDirty(['option', 'amount_sign'])) {
                \Log::info('Expense type updated', [
                    'expense_type_id' => $expenseType->id,
                    'option' => $expenseType->option,
                    'amount_sign' => $expenseType->amount_sign,
                    'changed_fields' => array_keys($expenseType->getDirty()),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}