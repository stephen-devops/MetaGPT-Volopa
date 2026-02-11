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
     */
    protected $table = 'opt_pocket_expense_types';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the pocket expenses that belong to this expense type.
     */
    public function pocketExpenses(): HasMany
    {
        return $this->hasMany(PocketExpense::class, 'expense_type_id');
    }

    /**
     * Scope a query to only include active expense types.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive expense types.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope a query to filter by name.
     */
    public function scopeByName($query, string $name)
    {
        return $query->where('name', $name);
    }

    /**
     * Check if the expense type is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if the expense type is inactive.
     */
    public function isInactive(): bool
    {
        return !$this->is_active;
    }

    /**
     * Activate the expense type.
     */
    public function activate(): bool
    {
        return $this->update(['is_active' => true]);
    }

    /**
     * Deactivate the expense type.
     */
    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Get the count of pocket expenses for this type.
     */
    public function getPocketExpensesCount(): int
    {
        return $this->pocketExpenses()->count();
    }

    /**
     * Get the count of active pocket expenses for this type.
     */
    public function getActivePocketExpensesCount(): int
    {
        return $this->pocketExpenses()
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Check if this expense type has any pocket expenses.
     */
    public function hasPocketExpenses(): bool
    {
        return $this->pocketExpenses()->exists();
    }

    /**
     * Check if this expense type has any active pocket expenses.
     */
    public function hasActivePocketExpenses(): bool
    {
        return $this->pocketExpenses()
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Get all active expense types as an array suitable for dropdowns.
     */
    public static function getActiveOptions(): array
    {
        return static::active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Find an expense type by name.
     */
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    /**
     * Find an active expense type by name.
     */
    public static function findActiveByName(string $name): ?self
    {
        return static::active()
            ->where('name', $name)
            ->first();
    }

    /**
     * Check if an expense type name exists.
     */
    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        $query = static::where('name', $name);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Create a new expense type with unique name validation.
     */
    public static function createUnique(array $attributes): ?self
    {
        if (static::nameExists($attributes['name'] ?? '')) {
            return null;
        }

        return static::create($attributes);
    }

    /**
     * Get the default expense type (first active one).
     */
    public static function getDefault(): ?self
    {
        return static::active()
            ->orderBy('name')
            ->first();
    }
}