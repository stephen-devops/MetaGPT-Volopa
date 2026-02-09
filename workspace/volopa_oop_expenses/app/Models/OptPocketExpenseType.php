## Development Plan

The file to be created for the OptPocketExpenseType model:

- app/Models/OptPocketExpenseType.php

## Incremental Change

- app/Models/OptPocketExpenseType.php: Eloquent model for expense type lookup with amount sign validation, active status, and sort ordering

## Code: app/Models/OptPocketExpenseType.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class OptPocketExpenseType extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'opt_pocket_expense_type';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'option',
        'amount_sign',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        // No hidden attributes for this model
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'amount_sign' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that are not mass assignable.
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * The model's default values for attributes.
     */
    protected $attributes = [
        'amount_sign' => 1,
        'is_active' => true,
        'sort_order' => 100,
    ];

    /**
     * Amount sign constants.
     */
    const AMOUNT_SIGN_POSITIVE = 1;
    const AMOUNT_SIGN_NEGATIVE = -1;

    /**
     * Default expense type options with their configurations.
     */
    const DEFAULT_EXPENSE_TYPES = [
        [
            'option' => 'Point of Sale',
            'amount_sign' => self::AMOUNT_SIGN_NEGATIVE,
            'sort_order' => 10,
        ],
        [
            'option' => 'ATM Withdrawal',
            'amount_sign' => self::AMOUNT_SIGN_NEGATIVE,
            'sort_order' => 20,
        ],
        [
            'option' => 'Fee & Charges',
            'amount_sign' => self::AMOUNT_SIGN_NEGATIVE,
            'sort_order' => 30,
        ],
        [
            'option' => 'Refund from Merchant',
            'amount_sign' => self::AMOUNT_SIGN_POSITIVE,
            'sort_order' => 40,
        ],
    ];

    /**
     * Scope a query to only include active expense types.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive expense types.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope a query to order expense types by sort order.
     */
    public function scopeOrderBySortOrder(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('sort_order', $direction);
    }

    /**
     * Scope a query to order expense types by option name.
     */
    public function scopeOrderByOption(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('option', $direction);
    }

    /**
     * Scope a query to search expense types by option name.
     */
    public function scopeSearchByOption(Builder $query, string $search): Builder
    {
        return $query->where('option', 'LIKE', '%' . $search . '%');
    }

    /**
     * Scope a query to include expense types with positive amount sign.
     */
    public function scopePositiveAmount(Builder $query): Builder
    {
        return $query->where('amount_sign', self::AMOUNT_SIGN_POSITIVE);
    }

    /**
     * Scope a query to include expense types with negative amount sign.
     */
    public function scopeNegativeAmount(Builder $query): Builder
    {
        return $query->where('amount_sign', self::AMOUNT_SIGN_NEGATIVE);
    }

    /**
     * Scope a query to find expense type by exact option name.
     */
    public function scopeByOption(Builder $query, string $option): Builder
    {
        return $query->where('option', $option);
    }

    /**
     * Scope a query to get default ordered active expense types.
     */
    public function scopeActiveOrdered(Builder $query): Builder
    {
        return $query->active()->orderBySortOrder();
    }

    /**
     * Check if the expense type is active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Check if the expense type is inactive.
     */
    public function isInactive(): bool
    {
        return $this->is_active === false;
    }

    /**
     * Check if the expense type has positive amount sign.
     */
    public function hasPositiveAmountSign(): bool
    {
        return $this->amount_sign === self::AMOUNT_SIGN_POSITIVE;
    }

    /**
     * Check if the expense type has negative amount sign.
     */
    public function hasNegativeAmountSign(): bool
    {
        return $this->amount_sign === self::AMOUNT_SIGN_NEGATIVE;
    }

    /**
     * Activate the expense type.
     */
    public function activate(): bool
    {
        $this->is_active = true;
        return $this->save();
    }

    /**
     * Deactivate the expense type.
     */
    public function deactivate(): bool
    {
        $this->is_active = false;
        return $this->save();
    }

    /**
     * Toggle the active status of the expense type.
     */
    public function toggleActive(): bool
    {
        $this->is_active = !$this->is_active;
        return $this->save();
    }

    /**
     * Update the sort order.
     */
    public function updateSortOrder(int $sortOrder): bool
    {
        $this->sort_order = $sortOrder;
        return $this->save();
    }

    /**
     * Set amount sign to positive.
     */
    public function setPositiveAmountSign(): bool
    {
        $this->amount_sign = self::AMOUNT_SIGN_POSITIVE;
        return $this->save();
    }

    /**
     * Set amount sign to negative.
     */
    public function setNegativeAmountSign(): bool
    {
        $this->amount_sign = self::AMOUNT_SIGN_NEGATIVE;
        return $this->save();
    }

    /**
     * Get the signed amount based on the expense type and original amount.
     */
    public function getSignedAmount(float $amount): float
    {
        return $amount * $this->amount_sign;
    }

    /**
     * Get the absolute amount (without sign consideration).
     */
    public function getAbsoluteAmount(float $amount): float
    {
        return abs($amount);
    }

    /**
     * Get the amount sign label for UI display.
     */
    public function getAmountSignLabelAttribute(): string
    {
        return $this->hasPositiveAmountSign() ? 'Positive' : 'Negative';
    }

    /**
     * Get the amount sign symbol for UI display.
     */
    public function getAmountSignSymbolAttribute(): string
    {
        return $this->hasPositiveAmountSign() ? '+' : '-';
    }

    /**
     * Get the status label for UI display.
     */
    public function getStatusLabelAttribute(): string
    {
        return $this->isActive() ? 'Active' : 'Inactive';
    }

    /**
     * Get the status color for UI display.
     */
    public function getStatusColorAttribute(): string
    {
        return $this->isActive() ? 'success' : 'secondary';
    }

    /**
     * Get all active expense types ordered by sort order.
     */
    public static function getActiveTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return static::activeOrdered()->get();
    }

    /**
     * Get all active expense type options as an array.
     */
    public static function getActiveOptions(): array
    {
        return static::active()
                    ->orderBySortOrder()
                    ->pluck('option')
                    ->toArray();
    }

    /**
     * Find an expense type by option name.
     */
    public static function findByOption(string $option): ?self
    {
        return static::byOption($option)->first();
    }

    /**
     * Find an active expense type by option name.
     */
    public static function findActiveByOption(string $option): ?self
    {
        return static::byOption($option)->active()->first();
    }

    /**
     * Check if an expense type option exists.
     */
    public static function optionExists(string $option): bool
    {
        return static::byOption($option)->exists();
    }

    /**
     * Check if an active expense type option exists.
     */
    public static function activeOptionExists(string $option): bool
    {
        return static::byOption($option)->active()->exists();
    }

    /**
     * Get the amount sign for a specific expense type option.
     */
    public static function getAmountSignForOption(string $option): int
    {
        $expenseType = static::findActiveByOption($option);
        
        if (!$expenseType) {
            return self::AMOUNT_SIGN_NEGATIVE; // Default to negative
        }
        
        return $expenseType->amount_sign;
    }

    /**
     * Validate and get the signed amount for an expense type option.
     */
    public static function validateAndGetSignedAmount(string $option, float $amount): float
    {
        $expenseType = static::findActiveByOption($option);
        
        if (!$expenseType) {
            throw new \InvalidArgumentException("Invalid expense type option: {$option}");
        }
        
        return $expenseType->getSignedAmount($amount);
    }

    /**
     * Create or update expense types from the default configuration.
     */
    public static function seedDefaultTypes(): void
    {
        foreach (static::DEFAULT_EXPENSE_TYPES as $typeData) {
            static::updateOrCreate(
                ['option' => $typeData['option']],
                [
                    'amount_sign' => $typeData['amount_sign'],
                    'is_active' => true,
                    'sort_order' => $typeData['sort_order'],
                ]
            );
        }
    }

    /**
     * Get expense types with their usage statistics.
     */
    public static function getTypesWithStats(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
                    ->withCount(['expenses'])
                    ->orderBySortOrder()
                    ->get();
    }

    /**
     * Get the most used expense types.
     */
    public static function getMostUsedTypes(int $limit = 5): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
                    ->withCount(['expenses'])
                    ->orderBy('expenses_count', 'desc')
                    ->orderBySortOrder()
                    ->limit($limit)
                    ->get();
    }

    /**
     * Get expense types that haven't been used.
     */
    public static function getUnusedTypes(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
                    ->doesntHave('expenses')
                    ->orderBySortOrder()
                    ->get();
    }

    /**
     * Reorder expense types.
     */
    public static function reorderTypes(array $typeIds): bool
    {
        $sortOrder = 10;
        
        foreach ($typeIds as $typeId) {
            $expenseType = static::find($typeId);
            
            if ($expenseType) {
                $expenseType->updateSortOrder($sortOrder);
                $sortOrder += 10;
            }
        }

        return true;
    }

    /**
     * Get the next available sort order.
     */
    public static function getNextSortOrder(): int
    {
        $maxSortOrder = static::max('sort_order');
        return ($maxSortOrder ?? 0) + 10;
    }

    /**
     * Bulk activate expense types.
     */
    public static function bulkActivate(array $typeIds): int
    {
        return static::whereIn('id', $typeIds)
                    ->update(['is_active' => true]);
    }

    /**
     * Bulk deactivate expense types.
     */
    public static function bulkDeactivate(array $typeIds): int
    {
        return static::whereIn('id', $typeIds)
                    ->update(['is_active' => false]);
    }

    /**
     * Get expense type statistics.
     */
    public static function getStatistics(): array
    {
        $totalTypes = static::count();
        $activeTypes = static::active()->count();
        $inactiveTypes = static::inactive()->count();
        $positiveTypes = static::positiveAmount()->active()->count();
        $negativeTypes = static::negativeAmount()->active()->count();
        
        return [
            'total_types' => $totalTypes,
            'active_types' => $activeTypes,
            'inactive_types' => $inactiveTypes,
            'positive_amount_types' => $positiveTypes,
            'negative_amount_types' => $negativeTypes,
            'active_percentage' => $totalTypes > 0 ? round(($activeTypes / $totalTypes) * 100, 2) : 0,
        ];
    }

    /**
     * Validate expense type configuration.
     */
    public function validate(): array
    {
        $errors = [];
        
        // Validate option name
        if (empty($this->option)) {
            $errors[] = 'Option name is required';
        } elseif (strlen($this->option) > 100) {
            $errors[] = 'Option name must not exceed 100 characters';
        }
        
        // Validate amount sign
        if (!in_array($this->amount_sign, [self::AMOUNT_SIGN_POSITIVE, self::AMOUNT_SIGN_NEGATIVE])) {
            $errors[] = 'Amount sign must be either 1 (positive) or -1 (negative)';
        }
        
        // Validate sort order
        if ($this->sort_order < 0) {
            $errors[] = 'Sort order must be a positive integer';
        }
        
        // Check for duplicate option names
        $duplicate = static::where('option', $this->option)
                           ->where('id', '!=', $this->id ?? 0)
                           ->exists();
        
        if ($duplicate) {
            $errors[] = 'Option name must be unique';
        }
        
        return $errors;
    }

    /**
     * Check if the expense type configuration is valid.
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    /**
     * Get the age of the expense type in days.
     */
    public function getAgeInDaysAttribute(): int
    {
        return now()->diffInDays($this->created_at);
    }

    /**
     * Check if the expense type is older than the specified number of days.
     */
    public function isOlderT