<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpense Model
 * 
 * Main expense model for Out-of-Pocket expenses with relationships to User, Client, 
 * ExpenseType and hasMany Metadata. Uses Volopa legacy timestamps and flag-based soft delete.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property string $date
 * @property string $merchant_name
 * @property string|null $merchant_description
 * @property int $expense_type
 * @property string $currency
 * @property float $amount
 * @property string|null $merchant_address
 * @property float|null $vat_amount
 * @property string|null $notes
 * @property string $status
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $approved_by_user_id
 * @property \Carbon\Carbon|null $create_time
 * @property \Carbon\Carbon|null $update_time
 * @property bool $deleted
 * @property \Carbon\Carbon|null $delete_time
 */
class PocketExpense extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     * Using Volopa legacy timestamps instead of Laravel defaults.
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
        'uuid',
        'user_id',
        'client_id',
        'date',
        'merchant_name',
        'merchant_description',
        'expense_type',
        'currency',
        'amount',
        'merchant_address',
        'vat_amount',
        'notes',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
        'approved_by_user_id',
        'create_time',
        'update_time',
        'deleted',
        'delete_time'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'deleted',
        'delete_time'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'expense_type' => 'integer',
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'deleted' => 'boolean',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'date' => 'date',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'delete_time' => 'datetime'
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'date',
        'create_time',
        'update_time',
        'delete_time'
    ];

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        // Generate UUID on creating
        static::creating(function (PocketExpense $expense) {
            if (empty($expense->uuid)) {
                $expense->uuid = Str::uuid()->toString();
            }
            
            // Set Volopa legacy timestamps
            $expense->create_time = now();
            $expense->update_time = now();
            
            // Set default status if not provided
            if (empty($expense->status)) {
                $expense->status = 'draft';
            }
            
            // Set default deleted flag
            if (is_null($expense->deleted)) {
                $expense->deleted = false;
            }
        });

        // Update Volopa legacy timestamp on updating
        static::updating(function (PocketExpense $expense) {
            $expense->update_time = now();
        });

        // Global scope to exclude soft deleted records by default
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->where('deleted', false);
        });
    }

    /**
     * Get the user who owns this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client this expense belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the expense type for this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type', 'id');
    }

    /**
     * Get the user who created this expense record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    /**
     * Get the user who last updated this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id', 'id');
    }

    /**
     * Get the user who approved this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'id');
    }

    /**
     * Get the metadata for this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id')
                    ->where('deleted', false);
    }

    /**
     * Get all metadata including soft deleted records.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function allMetadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id');
    }

    /**
     * Scope to include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * Scope to get only soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')->where('deleted', true);
    }

    /**
     * Scope to filter by client.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to filter by user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateBetween(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by currency.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $currency
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Scope to filter by amount range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $minAmount
     * @param float $maxAmount
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAmountBetween(Builder $query, float $minAmount, float $maxAmount): Builder
    {
        return $query->whereBetween('amount', [$minAmount, $maxAmount]);
    }

    /**
     * Scope to get recent expenses within specified days.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('date', '>=', now()->subDays($days));
    }

    /**
     * Perform a soft delete on the model.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = true;
        $this->delete_time = now();
        $this->update_time = now();
        
        return $this->save();
    }

    /**
     * Restore a soft-deleted model instance.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->delete_time = null;
        $this->update_time = now();
        
        return $this->save();
    }

    /**
     * Determine if the instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if expense is in draft status.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if expense is submitted.
     *
     * @return bool
     */
    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Check if expense is approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if expense is rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if expense can be edited.
     * Only draft and rejected expenses can be edited.
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    /**
     * Check if expense can be approved.
     * Only submitted expenses can be approved.
     *
     * @return bool
     */
    public function canApprove(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Check if expense can be submitted.
     * Only draft and rejected expenses can be submitted.
     *
     * @return bool
     */
    public function canSubmit(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    /**
     * Get the absolute value of the amount.
     *
     * @return float
     */
    public function getAbsoluteAmountAttribute(): float
    {
        return abs($this->amount);
    }

    /**
     * Get formatted amount with currency.
     *
     * @return string
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get the expense age in days.
     *
     * @return int
     */
    public function getAgeInDaysAttribute(): int
    {
        return Carbon::parse($this->date)->diffInDays(now());
    }

    /**
     * Check if expense is older than specified years.
     * Used for validation against 3-year constraint.
     *
     * @param int $years
     * @return bool
     */
    public function isOlderThan(int $years = 3): bool
    {
        return Carbon::parse($this->date)->diffInYears(now()) > $years;
    }

    /**
     * Get expense source metadata if exists.
     *
     * @return \App\Models\PocketExpenseMetadata|null
     */
    public function getExpenseSourceMetadata(): ?PocketExpenseMetadata
    {
        return $this->metadata()
                    ->where('metadata_type', 'expense_source')
                    ->first();
    }

    /**
     * Get category metadata if exists.
     *
     * @return \App\Models\PocketExpenseMetadata|null
     */
    public function getCategoryMetadata(): ?PocketExpenseMetadata
    {
        return $this->metadata()
                    ->where('metadata_type', 'category')
                    ->first();
    }

    /**
     * Get file attachments metadata.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFileAttachments(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->metadata()
                    ->where('metadata_type', 'file')
                    ->get();
    }

    /**
     * Check if expense has receipt attached.
     *
     * @return bool
     */
    public function hasReceipt(): bool
    {
        return $this->getFileAttachments()->isNotEmpty();
    }

    /**
     * Get display name for the expense (merchant name truncated).
     *
     * @param int $maxLength
     * @return string
     */
    public function getDisplayName(int $maxLength = 50): string
    {
        return Str::limit($this->merchant_name, $maxLength);
    }

    /**
     * Convert expense to array suitable for CSV export.
     *
     * @return array
     */
    public function toCsvArray(): array
    {
        return [
            'Date' => $this->date,
            'Expense Type' => $this->expenseType->option ?? '',
            'Currency Code' => $this->currency,
            'Amount' => $this->amount,
            'VAT %' => $this->vat_amount,
            'Merchant Name' => $this->merchant_name,
            'Description' => $this->merchant_description,
            'Merchant Address' => $this->merchant_address,
            'Notes' => $this->notes,
            'Status' => ucfirst($this->status),
        ];
    }

    /**
     * Static method to get valid statuses.
     *
     * @return array
     */
    public static function getValidStatuses(): array
    {
        return ['draft', 'submitted', 'approved', 'rejected'];
    }

    /**
     * Static method to get valid currencies.
     * Should be integrated with platform currency service.
     *
     * @return array
     */
    public static function getValidCurrencies(): array
    {
        // This should ideally come from platform currency service
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'SGD'];
    }

    /**
     * Validate if expense date is within allowed range (3 years).
     *
     * @return bool
     */
    public function isDateValid(): bool
    {
        if (empty($this->date)) {
            return false;
        }

        $expenseDate = Carbon::parse($this->date);
        $threeYearsAgo = now()->subYears(3);
        $today = now();

        return $expenseDate->between($threeYearsAgo, $today);
    }

    /**
     * Get expense with eager loaded relationships for API responses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithRelations(Builder $query): Builder
    {
        return $query->with([
            'user:id,name',
            'client:id,name',
            'expenseType:id,option,amount_sign',
            'createdBy:id,name',
            'updatedBy:id,name',
            'approvedBy:id,name',
            'metadata' => function ($query) {
                $query->select('id', 'pocket_expense_id', 'metadata_type', 'details_json')
                      ->where('deleted', false);
            }
        ]);
    }

    /**
     * Submit expense for approval.
     *
     * @param int $updatedByUserId
     * @return bool
     */
    public function submit(int $updatedByUserId): bool
    {
        if (!$this->canSubmit()) {
            return false;
        }

        $this->status = 'submitted';
        $this->updated_by_user_id = $updatedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Approve expense.
     *
     * @param int $approvedByUserId
     * @return bool
     */
    public function approve(int $approvedByUserId): bool
    {
        if (!$this->canApprove()) {
            return false;
        }

        $this->status = 'approved';
        $this->approved_by_user_id = $approvedByUserId;
        $this->updated_by_user_id = $approvedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Reject expense.
     *
     * @param int $rejectedByUserId
     * @return bool
     */
    public function reject(int $rejectedByUserId): bool
    {
        if (!$this->canApprove()) {
            return false;
        }

        $this->status = 'rejected';
        $this->approved_by_user_id = $rejectedByUserId;
        $this->updated_by_user_id = $rejectedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Reset expense back to draft status.
     *
     * @param int $updatedByUserId
     * @return bool
     */
    public function resetToDraft(int $updatedByUserId): bool
    {
        $this->status = 'draft';
        $this->updated_by_user_id = $updatedByUserId;
        $this->approved_by_user_id = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Get route key for model binding.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the validation rules for the model.
     *
     * @param bool $isUpdate
     * @return array
     */
    public static function getValidationRules(bool $isUpdate = false): array
    {
        $rules = [
            'user_id' => 'required|integer|exists:users,id',
            'client_id' => 'required|integer|exists:clients,id',
            'date' => 'required|date|date_format:Y-m-d|before_or_equal:today|after_or_equal:' . now()->subYears(3)->format('Y-m-d'),
            'merchant_name' => 'required|string|max:180',
            'merchant_description' => 'nullable|string|max:1000',
            'expense_type' => 'required|integer|exists:opt_pocket_expense_type,id',
            'currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            'amount' => 'required|numeric|not_in:0',
            'merchant_address' => 'nullable|string|max:500',
            'vat_amount' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:2000',
            'status' => 'sometimes|string|in:draft,submitted,approved,rejected',
            'created_by_user_id' => 'required|integer|exists:users,id',
            'updated_by_user_id' => 'nullable|integer|exists:users,id',
            'approved_by_user_id' => 'nullable|integer|exists:users,id',
        ];

        if ($isUpdate) {
            // Make fields optional for updates
            $rules['user_id'] = 'sometimes|integer|exists:users,id';
            $rules['client_id'] = 'sometimes|integer|exists:clients,id';
            $rules['date'] = 'sometimes|date|date_format:Y-m-d|before_or_equal:today|after_or_equal:' . now()->subYears(3)->format('Y-m-d');
            $rules['merchant_name'] = 'sometimes|string|max:180';
            $rules['expense_type'] = 'sometimes|integer|exists:opt_pocket_expense_type,id';
            $rules['currency'] = 'sometimes|string|size:3|regex:/^[A-Z]{3}$/';
            $rules['amount'] = 'sometimes|numeric|not_in:0';
            $rules['created_by_user_id'] = 'sometimes|integer|exists:users,id';
        }

        return $rules;
    }

    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'status' => 'draft',
        'deleted' => false,
    ];
}