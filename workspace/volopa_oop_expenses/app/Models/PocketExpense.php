<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PocketExpense extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'pocket_expenses';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'client_id',
        'expense_type_id',
        'date',
        'merchant_name',
        'amount',
        'currency',
        'status',
        'description',
        'receipt_url',
        'converted_amount',
        'converted_currency',
        'fx_rate',
        'fx_commission',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'is_billable',
        'project_code',
        'cost_center',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'expense_type_id' => 'integer',
        'date' => 'date',
        'amount' => 'decimal:2',
        'converted_amount' => 'decimal:2',
        'fx_rate' => 'decimal:6',
        'fx_commission' => 'decimal:4',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'is_billable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'status' => 'pending',
        'fx_commission' => 0.0000,
        'is_billable' => true,
    ];

    /**
     * The possible status values.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PROCESSING = 'processing';

    /**
     * Get all possible status values.
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_PROCESSING,
        ];
    }

    /**
     * Get the user that owns the expense.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with the expense.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type associated with the expense.
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type_id');
    }

    /**
     * Get the user who approved this expense.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get all metadata associated with this expense.
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id');
    }

    /**
     * Scope a query to only include expenses for a specific client.
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include expenses for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include expenses with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include pending expenses.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include approved expenses.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected expenses.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to only include processing expenses.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include billable expenses.
     */
    public function scopeBillable($query)
    {
        return $query->where('is_billable', true);
    }

    /**
     * Scope a query to only include non-billable expenses.
     */
    public function scopeNonBillable($query)
    {
        return $query->where('is_billable', false);
    }

    /**
     * Scope a query to filter by expense type.
     */
    public function scopeByExpenseType($query, int $expenseTypeId)
    {
        return $query->where('expense_type_id', $expenseTypeId);
    }

    /**
     * Scope a query to filter by project code.
     */
    public function scopeByProjectCode($query, string $projectCode)
    {
        return $query->where('project_code', $projectCode);
    }

    /**
     * Scope a query to filter by cost center.
     */
    public function scopeByCostCenter($query, string $costCenter)
    {
        return $query->where('cost_center', $costCenter);
    }

    /**
     * Scope a query to include expenses with metadata relationships.
     */
    public function scopeWithMetadata($query)
    {
        return $query->with(['metadata']);
    }

    /**
     * Scope a query to include all related models.
     */
    public function scopeWithAllRelations($query)
    {
        return $query->with(['user', 'client', 'expenseType', 'approver', 'metadata']);
    }

    /**
     * Check if the expense is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the expense is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the expense is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if the expense is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the expense is billable.
     */
    public function isBillable(): bool
    {
        return $this->is_billable;
    }

    /**
     * Check if the expense has FX conversion applied.
     */
    public function hasFxConversion(): bool
    {
        return !is_null($this->converted_amount) && !is_null($this->converted_currency) && !is_null($this->fx_rate);
    }

    /**
     * Mark the expense as approved.
     */
    public function approve(int $approverId): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Mark the expense as rejected.
     */
    public function reject(int $approverId, string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Mark the expense as processing.
     */
    public function markAsProcessing(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Apply FX conversion to the expense.
     */
    public function applyFxConversion(float $convertedAmount, string $convertedCurrency, float $fxRate, float $fxCommission = 0.0000): bool
    {
        return $this->update([
            'converted_amount' => $convertedAmount,
            'converted_currency' => $convertedCurrency,
            'fx_rate' => $fxRate,
            'fx_commission' => $fxCommission,
        ]);
    }

    /**
     * Get the effective amount (converted or original).
     */
    public function getEffectiveAmount(): float
    {
        return $this->converted_amount ?? $this->amount;
    }

    /**
     * Get the effective currency (converted or original).
     */
    public function getEffectiveCurrency(): string
    {
        return $this->converted_currency ?? $this->currency;
    }

    /**
     * Get formatted amount with currency.
     */
    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get formatted converted amount with currency.
     */
    public function getFormattedConvertedAmount(): string
    {
        if ($this->hasFxConversion()) {
            return number_format($this->converted_amount, 2) . ' ' . $this->converted_currency;
        }

        return $this->getFormattedAmount();
    }

    /**
     * Get metadata by type.
     */
    public function getMetadataByType(string $metadataType): HasMany
    {
        return $this->metadata()->where('metadata_type', $metadataType);
    }

    /**
     * Get category metadata.
     */
    public function getCategoryMetadata(): HasMany
    {
        return $this->getMetadataByType('category');
    }

    /**
     * Get source metadata.
     */
    public function getSourceMetadata(): HasMany
    {
        return $this->getMetadataByType('source');
    }

    /**
     * Get location metadata.
     */
    public function getLocationMetadata(): HasMany
    {
        return $this->getMetadataByType('location');
    }

    /**
     * Get tax metadata.
     */
    public function getTaxMetadata(): HasMany
    {
        return $this->getMetadataByType('tax');
    }

    /**
     * Get custom metadata.
     */
    public function getCustomMetadata(): HasMany
    {
        return $this->getMetadataByType('custom');
    }

    /**
     * Check if the expense has metadata of a specific type.
     */
    public function hasMetadataType(string $metadataType): bool
    {
        return $this->metadata()->where('metadata_type', $metadataType)->exists();
    }

    /**
     * Check if the expense has category metadata.
     */
    public function hasCategoryMetadata(): bool
    {
        return $this->hasMetadataType('category');
    }

    /**
     * Check if the expense has source metadata.
     */
    public function hasSourceMetadata(): bool
    {
        return $this->hasMetadataType('source');
    }

    /**
     * Check if the expense has location metadata.
     */
    public function hasLocationMetadata(): bool
    {
        return $this->hasMetadataType('location');
    }

    /**
     * Check if the expense has tax metadata.
     */
    public function hasTaxMetadata(): bool
    {
        return $this->hasMetadataType('tax');
    }

    /**
     * Check if the expense has custom metadata.
     */
    public function hasCustomMetadata(): bool
    {
        return $this->hasMetadataType('custom');
    }

    /**
     * Get the count of metadata records for this expense.
     */
    public function getMetadataCount(): int
    {
        return $this->metadata()->count();
    }

    /**
     * Get the count of metadata records by type.
     */
    public function getMetadataCountByType(string $metadataType): int
    {
        return $this->metadata()->where('metadata_type', $metadataType)->count();
    }

    /**
     * Check if the expense can be edited based on its status.
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REJECTED]);
    }

    /**
     * Check if the expense can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the expense can be rejected.
     */
    public function canBeRejected(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the expense can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REJECTED]);
    }

    /**
     * Get total amount for a collection of expenses.
     */
    public static function getTotalAmount($expenses): float
    {
        return $expenses->sum(function ($expense) {
            return $expense->getEffectiveAmount();
        });
    }

    /**
     * Get expenses grouped by status for a client.
     */
    public static function getExpensesByStatusForClient(int $clientId): array
    {
        $expenses = static::forClient($clientId)->get()->groupBy('status');
        
        $result = [];
        foreach (static::getStatusOptions() as $status) {
            $result[$status] = $expenses->get($status, collect());
        }
        
        return $result;
    }

    /**
     * Get expenses summary for a user and client.
     */
    public static function getExpensesSummaryForUser(int $userId, int $clientId): array
    {
        $expenses = static::forUser($userId)->forClient($clientId)->get();
        
        return [
            'total_count' => $expenses->count(),
            'total_amount' => static::getTotalAmount($expenses),
            'pending_count' => $expenses->where('status', self::STATUS_PENDING)->count(),
            'approved_count' => $expenses->where('status', self::STATUS_APPROVED)->count(),
            'rejected_count' => $expenses->where('status', self::STATUS_REJECTED)->count(),
            'processing_count' => $expenses->where('status', self::STATUS_PROCESSING)->count(),
            'billable_count' => $expenses->where('is_billable', true)->count(),
            'non_billable_count' => $expenses->where('is_billable', false)->count(),
        ];
    }

    /**
     * Search expenses by merchant name or description.
     */
    public function scopeSearch($query, string $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('merchant_name', 'LIKE', "%{$searchTerm}%")
              ->orWhere('description', 'LIKE', "%{$searchTerm}%");
        });
    }

    /**
     * Get expenses that need approval (pending status).
     */
    public function scopeNeedsApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Get expenses approved by a specific user.
     */
    public function scopeApprovedBy($query, int $approverId)
    {
        return $query->where('approved_by', $approverId);
    }

    /**
     * Get recent expenses (within last 30 days).
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Get expenses for current month.
     */
    public function scopeCurrentMonth($query)
    {
        return $query->whereMonth('date', now()->month)
                    ->whereYear('date', now()->year);
    }

    /**
     * Get expenses for previous month.
     */
    public function scopePreviousMonth($query)
    {
        $previousMonth = now()->subMonth();
        return $query->whereMonth('date', $previousMonth->month)
                    ->whereYear('date', $previousMonth->year);
    }

    /**
     * Get expenses for current year.
     */
    public function scopeCurrentYear($query)
    {
        return $query->whereYear('date', now()->year);
    }

    /**
     * Get expenses above a certain amount.
     */
    public function scopeAboveAmount($query, float $amount)
    {
        return $query->where(function ($q) use ($amount) {
            $q->where('amount', '>', $amount)
              ->orWhere('converted_amount', '>', $amount);
        });
    }

    /**
     * Get expenses below a certain amount.
     */
    public function scopeBelowAmount($query, float $amount)
    {
        return $query->where(function ($q) use ($amount) {
            $q->where('amount', '<', $amount)
              ->when(function ($query) {
                  return $query->whereNotNull('converted_amount');
              }, function ($q) use ($amount) {
                  $q->where('converted_amount', '<', $amount);
              });
        });
    }

    /**
     * Order expenses by date descending.
     */
    public function scopeLatestByDate($query)
    {
        return $query->orderBy('date', 'desc');
    }

    /**
     * Order expenses by amount descending.
     */
    public function scopeByAmountDesc($query)
    {
        return $query->orderBy('amount', 'desc');
    }

    /**
     * Order expenses by creation date descending.
     */
    public function scopeLatestCreated($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}