<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

/**
 * OptPocketExpenseType Model
 * 
 * Manages pocket expense type options with amount sign configuration.
 * Used for determining whether an expense type adds or subtracts from balance.
 * 
 * @property int $id
 * @property string $option
 * @property string $amount_sign
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * 
 * @property-read Collection|PocketExpense[] $pocketExpenses
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
        'amount_sign' => '-',
    ];

    /**
     * The expense type options that are considered positive amounts.
     */
    public const POSITIVE_TYPES = [
        'Refund from Merchant',
    ];

    /**
     * The expense type options that are considered negative amounts.
     */
    public const NEGATIVE_TYPES = [
        'ATM Withdrawal',
        'Point of Sale',
        'Fee & Charges',
    ];

    /**
     * Amount sign constants.
     */
    public const SIGN_POSITIVE = '+';
    public const SIGN_NEGATIVE = '-';

    /**
     * Get all pocket expenses using this expense type.
     *
     * @return HasMany
     */
    public function pocketExpenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type', 'id');
    }

    /**
     * Scope a query to only include positive amount types.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePositive(Builder $query): Builder
    {
        return $query->where('amount_sign', self::SIGN_POSITIVE);
    }

    /**
     * Scope a query to only include negative amount types.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeNegative(Builder $query): Builder
    {
        return $query->where('amount_sign', self::SIGN_NEGATIVE);
    }

    /**
     * Scope a query to search by option name.
     *
     * @param Builder $query
     * @param string $option
     * @return Builder
     */
    public function scopeByOption(Builder $query, string $option): Builder
    {
        return $query->where('option', $option);
    }

    /**
     * Scope a query to search by option name (case insensitive).
     *
     * @param Builder $query
     * @param string $option
     * @return Builder
     */
    public function scopeByOptionInsensitive(Builder $query, string $option): Builder
    {
        return $query->whereRaw('LOWER(option) = LOWER(?)', [$option]);
    }

    /**
     * Check if this expense type has a positive amount sign.
     *
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->amount_sign === self::SIGN_POSITIVE;
    }

    /**
     * Check if this expense type has a negative amount sign.
     *
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->amount_sign === self::SIGN_NEGATIVE;
    }

    /**
     * Get the display name for this expense type.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->option;
    }

    /**
     * Get the full description including amount sign.
     *
     * @return string
     */
    public function getFullDescription(): string
    {
        $sign = $this->isPositive() ? 'Credit' : 'Debit';
        return sprintf('%s (%s)', $this->option, $sign);
    }

    /**
     * Apply the amount sign to a given amount.
     *
     * @param float $amount
     * @return float
     */
    public function applySign(float $amount): float
    {
        $absoluteAmount = abs($amount);
        
        return $this->isPositive() ? $absoluteAmount : -$absoluteAmount;
    }

    /**
     * Get all expense types ordered by option name.
     *
     * @return Collection
     */
    public static function getAllOrdered(): Collection
    {
        return static::orderBy('option')->get();
    }

    /**
     * Get all positive expense types.
     *
     * @return Collection
     */
    public static function getPositiveTypes(): Collection
    {
        return static::positive()->orderBy('option')->get();
    }

    /**
     * Get all negative expense types.
     *
     * @return Collection
     */
    public static function getNegativeTypes(): Collection
    {
        return static::negative()->orderBy('option')->get();
    }

    /**
     * Find an expense type by option name.
     *
     * @param string $option
     * @return static|null
     */
    public static function findByOption(string $option): ?static
    {
        return static::byOption($option)->first();
    }

    /**
     * Find an expense type by option name (case insensitive).
     *
     * @param string $option
     * @return static|null
     */
    public static function findByOptionInsensitive(string $option): ?static
    {
        return static::byOptionInsensitive($option)->first();
    }

    /**
     * Get expense types as key-value pairs for dropdowns.
     *
     * @return array<int, string>
     */
    public static function getOptionsForDropdown(): array
    {
        return static::orderBy('option')
            ->pluck('option', 'id')
            ->toArray();
    }

    /**
     * Get expense types with their signs for validation.
     *
     * @return array<string, string>
     */
    public static function getOptionsWithSigns(): array
    {
        return static::orderBy('option')
            ->get()
            ->mapWithKeys(function ($type) {
                return [$type->option => $type->amount_sign];
            })
            ->toArray();
    }

    /**
     * Validate if an option exists.
     *
     * @param string $option
     * @return bool
     */
    public static function isValidOption(string $option): bool
    {
        return static::byOption($option)->exists();
    }

    /**
     * Validate if an option exists (case insensitive).
     *
     * @param string $option
     * @return bool
     */
    public static function isValidOptionInsensitive(string $option): bool
    {
        return static::byOptionInsensitive($option)->exists();
    }

    /**
     * Get the amount sign for a given option.
     *
     * @param string $option
     * @return string|null
     */
    public static function getSignForOption(string $option): ?string
    {
        $type = static::findByOption($option);
        
        return $type ? $type->amount_sign : null;
    }

    /**
     * Get cached expense types for performance.
     * 
     * @return Collection
     */
    public static function getCached(): Collection
    {
        return cache()->remember('pocket_expense_types', 3600, function () {
            return static::getAllOrdered();
        });
    }

    /**
     * Clear the expense types cache.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        cache()->forget('pocket_expense_types');
    }

    /**
     * Boot the model and set up event listeners.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Clear cache when expense types are modified
        static::saved(function () {
            static::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
        });
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
        $array['display_name'] = $this->getDisplayName();
        $array['full_description'] = $this->getFullDescription();
        
        return $array;
    }

    /**
     * Get the validation rules for this model.
     *
     * @param int|null $id
     * @return array<string, mixed>
     */
    public static function getValidationRules(?int $id = null): array
    {
        $uniqueRule = $id ? "unique:opt_pocket_expense_type,option,{$id}" : 'unique:opt_pocket_expense_type,option';
        
        return [
            'option' => ['required', 'string', 'max:100', $uniqueRule],
            'amount_sign' => ['required', 'string', 'in:+,-'],
        ];
    }

    /**
     * Get the validation messages for this model.
     *
     * @return array<string, string>
     */
    public static function getValidationMessages(): array
    {
        return [
            'option.required' => 'The expense type option is required.',
            'option.string' => 'The expense type option must be a string.',
            'option.max' => 'The expense type option may not be greater than 100 characters.',
            'option.unique' => 'This expense type option already exists.',
            'amount_sign.required' => 'The amount sign is required.',
            'amount_sign.string' => 'The amount sign must be a string.',
            'amount_sign.in' => 'The amount sign must be either + or -.',
        ];
    }
}