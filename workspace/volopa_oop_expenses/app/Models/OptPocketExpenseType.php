<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\OptPocketExpenseTypeFactory;

/**
 * OptPocketExpenseType Model
 * 
 * Represents the lookup table for expense types with predefined options and amount sign logic.
 * This model handles the expense type configuration that determines whether amounts should be
 * positive (for refunds) or negative (for charges/withdrawals/fees).
 * 
 * @property int $id Primary key
 * @property string $option Expense type name (e.g., ATM Withdrawal, Point of Sale)
 * @property string $amount_sign Determines if amounts should be positive or negative (enum: positive, negative)
 * @property bool $is_active Whether this expense type is available for selection
 * @property int $sort_order Display order in dropdowns (default: 0)
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PocketExpense[] $expenses
 * @property-read int|null $expenses_count
 * 
 * @method static \Database\Factories\OptPocketExpenseTypeFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType query()
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereOption($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereAmountSign($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|OptPocketExpenseType whereUpdatedAt($value)
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
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The data type of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'option',
        'amount_sign',
        'is_active',
        'sort_order',
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
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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
     * The attributes that should be visible for serialization.
     *
     * @var array<int, string>
     */
    protected $visible = [
        'id',
        'option',
        'amount_sign',
        'is_active',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * Amount sign enum values as per system constraints.
     */
    public const AMOUNT_SIGN_POSITIVE = 'positive';
    public const AMOUNT_SIGN_NEGATIVE = 'negative';

    /**
     * Available amount sign options.
     *
     * @var array<string>
     */
    public const AMOUNT_SIGN_OPTIONS = [
        self::AMOUNT_SIGN_POSITIVE,
        self::AMOUNT_SIGN_NEGATIVE,
    ];

    /**
     * System default expense types as per constraints.
     * These match the seeded data in the migration.
     */
    public const SYSTEM_DEFAULTS = [
        'ATM Withdrawal' => self::AMOUNT_SIGN_NEGATIVE,
        'Point of Sale' => self::AMOUNT_SIGN_NEGATIVE,
        'Fee & Charges' => self::AMOUNT_SIGN_NEGATIVE,
        'Refund from Merchant' => self::AMOUNT_SIGN_POSITIVE,
    ];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Database\Factories\OptPocketExpenseTypeFactory
     */
    protected static function newFactory(): OptPocketExpenseTypeFactory
    {
        return OptPocketExpenseTypeFactory::new();
    }

    /**
     * Get all pocket expenses using this expense type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type', 'id');
    }

    /**
     * Scope a query to only include active expense types.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive expense types.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope a query to order by sort order then by option name.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('option', 'asc');
    }

    /**
     * Scope a query to only include expense types with positive amount sign.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePositiveAmount($query)
    {
        return $query->where('amount_sign', self::AMOUNT_SIGN_POSITIVE);
    }

    /**
     * Scope a query to only include expense types with negative amount sign.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNegativeAmount($query)
    {
        return $query->where('amount_sign', self::AMOUNT_SIGN_NEGATIVE);
    }

    /**
     * Scope a query to only include system default expense types.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSystemDefaults($query)
    {
        return $query->whereIn('option', array_keys(self::SYSTEM_DEFAULTS));
    }

    /**
     * Check if this expense type has positive amount sign (for refunds).
     *
     * @return bool
     */
    public function isPositiveAmount(): bool
    {
        return $this->amount_sign === self::AMOUNT_SIGN_POSITIVE;
    }

    /**
     * Check if this expense type has negative amount sign (for charges/withdrawals/fees).
     *
     * @return bool
     */
    public function isNegativeAmount(): bool
    {
        return $this->amount_sign === self::AMOUNT_SIGN_NEGATIVE;
    }

    /**
     * Check if this expense type is active and available for selection.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Check if this expense type is one of the system defaults.
     *
     * @return bool
     */
    public function isSystemDefault(): bool
    {
        return array_key_exists($this->option, self::SYSTEM_DEFAULTS);
    }

    /**
     * Get the expected amount sign for this expense type.
     *
     * @return string
     */
    public function getExpectedAmountSign(): string
    {
        return $this->amount_sign;
    }

    /**
     * Get the display name for the amount sign.
     *
     * @return string
     */
    public function getAmountSignDisplayName(): string
    {
        return match ($this->amount_sign) {
            self::AMOUNT_SIGN_POSITIVE => 'Positive (Credit/Refund)',
            self::AMOUNT_SIGN_NEGATIVE => 'Negative (Debit/Charge)',
            default => 'Unknown',
        };
    }

    /**
     * Determine if the given amount matches the expected sign for this expense type.
     *
     * @param float $amount
     * @return bool
     */
    public function isAmountSignCorrect(float $amount): bool
    {
        if ($this->amount_sign === self::AMOUNT_SIGN_POSITIVE) {
            return $amount >= 0;
        }

        if ($this->amount_sign === self::AMOUNT_SIGN_NEGATIVE) {
            return $amount < 0;
        }

        return false;
    }

    /**
     * Adjust the given amount to match the expected sign for this expense type.
     *
     * @param float $amount
     * @return float
     */
    public function adjustAmountSign(float $amount): float
    {
        $absoluteAmount = abs($amount);

        if ($this->amount_sign === self::AMOUNT_SIGN_POSITIVE) {
            return $absoluteAmount;
        }

        if ($this->amount_sign === self::AMOUNT_SIGN_NEGATIVE) {
            return -$absoluteAmount;
        }

        return $amount;
    }

    /**
     * Get all active expense types ordered by sort order.
     * Commonly used for dropdown population in forms.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getActiveOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->ordered()->get();
    }

    /**
     * Get all system default expense types.
     * These are the types automatically seeded during migration.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getSystemDefaults(): \Illuminate\Database\Eloquent\Collection
    {
        return static::systemDefaults()->ordered()->get();
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
     * Get expense types suitable for dropdown display with formatted labels.
     *
     * @return array<int, array>
     */
    public static function getDropdownOptions(): array
    {
        return static::active()
            ->ordered()
            ->get()
            ->map(function (self $expenseType) {
                return [
                    'id' => $expenseType->id,
                    'option' => $expenseType->option,
                    'label' => $expenseType->option . ' (' . $expenseType->getAmountSignDisplayName() . ')',
                    'amount_sign' => $expenseType->amount_sign,
                    'is_positive' => $expenseType->isPositiveAmount(),
                    'is_negative' => $expenseType->isNegativeAmount(),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Get expense count statistics grouped by amount sign.
     *
     * @return array<string, int>
     */
    public function getExpenseStatistics(): array
    {
        $expenseCount = $this->expenses()->count();
        $activeExpenseCount = $this->expenses()->where('deleted', false)->count();

        return [
            'total_expenses' => $expenseCount,
            'active_expenses' => $activeExpenseCount,
            'deleted_expenses' => $expenseCount - $activeExpenseCount,
            'amount_sign' => $this->amount_sign,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Ensure option names are trimmed and properly formatted
        static::saving(function (self $model) {
            $model->option = trim($model->option);
            
            // Validate amount_sign enum
            if (!in_array($model->amount_sign, self::AMOUNT_SIGN_OPTIONS)) {
                throw new \InvalidArgumentException(
                    "Invalid amount_sign value. Must be one of: " . implode(', ', self::AMOUNT_SIGN_OPTIONS)
                );
            }
            
            // Ensure sort_order is not null
            if ($model->sort_order === null) {
                $model->sort_order = 0;
            }
        });

        // Prevent deletion of expense types that have associated expenses
        static::deleting(function (self $model) {
            if ($model->expenses()->exists()) {
                throw new \RuntimeException(
                    "Cannot delete expense type '{$model->option}' because it has associated expenses. " .
                    "Consider marking it as inactive instead."
                );
            }
        });
    }

    /**
     * Convert the model instance to an array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed fields for API responses
        $array['amount_sign_display'] = $this->getAmountSignDisplayName();
        $array['is_system_default'] = $this->isSystemDefault();
        $array['expenses_count'] = $this->expenses_count ?? $this->expenses()->count();
        
        return $array;
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Get the value of the model's route key.
     *
     * @return mixed
     */
    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     * @param string|null $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    /**
     * Get a string representation of the model.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->option ?? 'Unnamed Expense Type';
    }
}