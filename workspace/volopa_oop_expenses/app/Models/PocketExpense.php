<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\PocketExpenseFactory;

/**
 * PocketExpense Model
 * 
 * Core expense record model with audit trails and soft delete support.
 * Supports multi-tenant architecture with client_id scoping and user permissions.
 * Integrates with expense types, metadata, and approval workflow.
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
 * @property int $deleted
 * @property \DateTime|null $delete_time
 * @property \DateTime $create_time
 * @property \DateTime|null $update_time
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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'uuid' => 'string',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'date' => 'date',
        'merchant_name' => 'string',
        'merchant_description' => 'string',
        'expense_type' => 'integer',
        'currency' => 'string',
        'amount' => 'decimal:2',
        'merchant_address' => 'string',
        'vat_amount' => 'decimal:2',
        'notes' => 'string',
        'status' => 'string',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'deleted' => 'integer',
        'delete_time' => 'datetime',
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
     * Get the user who owns this expense.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client this expense belongs to.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type for this expense.
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type');
    }

    /**
     * Get the user who created this expense.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this expense.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get the user who approved this expense.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Get all metadata associated with this expense.
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id');
    }

    /**
     * Scope a query to only include non-deleted expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include deleted expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', 1);
    }

    /**
     * Scope a query to only include expenses for a specific client.
     *
     * @param Builder $query
     * @param int $clientId
     * @return Builder
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include expenses for a specific user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include expenses with a specific status.
     *
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include draft expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope a query to only include submitted expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', 'submitted');
    }

    /**
     * Scope a query to only include approved expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return Builder
     */
    public function scopeDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by currency.
     *
     * @param Builder $query
     * @param string $currency
     * @return Builder
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Check if this expense is currently active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Check if this expense is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === 1;
    }

    /**
     * Check if this expense is in draft status.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if this expense is submitted.
     *
     * @return bool
     */
    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Check if this expense is approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if this expense is rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if this expense has VAT amount.
     *
     * @return bool
     */
    public function hasVat(): bool
    {
        return !is_null($this->vat_amount) && $this->vat_amount > 0;
    }

    /**
     * Check if this expense belongs to a specific client.
     *
     * @param int $clientId
     * @return bool
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Check if this expense belongs to a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Check if this expense was created by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function wasCreatedBy(int $userId): bool
    {
        return $this->created_by_user_id === $userId;
    }

    /**
     * Check if this expense can be edited (draft or submitted status).
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'submitted']);
    }

    /**
     * Check if this expense can be approved.
     *
     * @return bool
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Check if this expense can be deleted (not approved).
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return $this->status !== 'approved';
    }

    /**
     * Get the absolute amount (always positive).
     *
     * @return float
     */
    public function getAbsoluteAmount(): float
    {
        return abs($this->amount);
    }

    /**
     * Get the formatted amount with currency.
     *
     * @return string
     */
    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get the display name for this expense.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->merchant_name . ' - ' . $this->getFormattedAmount();
    }

    /**
     * Soft delete this expense by setting deleted flag and delete_time.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = 1;
        $this->delete_time = now();
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Restore this expense by clearing deleted flag and delete_time.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Change the status of this expense.
     *
     * @param string $newStatus
     * @param int|null $updatedByUserId
     * @return bool
     */
    public function changeStatus(string $newStatus, ?int $updatedByUserId = null): bool
    {
        $this->status = $newStatus;
        $this->updated_by_user_id = $updatedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Approve this expense.
     *
     * @param int $approvedByUserId
     * @return bool
     */
    public function approve(int $approvedByUserId): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = 'approved';
        $this->approved_by_user_id = $approvedByUserId;
        $this->updated_by_user_id = $approvedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Reject this expense.
     *
     * @param int $rejectedByUserId
     * @return bool
     */
    public function reject(int $rejectedByUserId): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = 'rejected';
        $this->approved_by_user_id = $rejectedByUserId;
        $this->updated_by_user_id = $rejectedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Submit this expense for approval.
     *
     * @param int|null $updatedByUserId
     * @return bool
     */
    public function submit(?int $updatedByUserId = null): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        $this->status = 'submitted';
        $this->updated_by_user_id = $updatedByUserId;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return PocketExpenseFactory
     */
    protected static function newFactory(): PocketExpenseFactory
    {
        return PocketExpenseFactory::new();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
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
            
            // Generate UUID if not set
            if (empty($model->uuid)) {
                $model->uuid = \Illuminate\Support\Str::uuid()->toString();
            }

            // Set default status if not set
            if (empty($model->status)) {
                $model->status = 'draft';
            }

            // Set default deleted flag
            if (is_null($model->deleted)) {
                $model->deleted = 0;
            }
        });

        // Update update_time on updating
        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get validation rules for expense data.
     *
     * @param int|null $excludeId
     * @return array
     */
    public static function getValidationRules(?int $excludeId = null): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'date' => ['required', 'date', 'before_or_equal:today', 'after:' . now()->subYears(3)->format('Y-m-d')],
            'merchant_name' => ['required', 'string', 'max:180'],
            'merchant_description' => ['nullable', 'string', 'max:500'],
            'expense_type' => ['required', 'integer', 'exists:opt_pocket_expense_type,id'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'merchant_address' => ['nullable', 'string', 'max:500'],
            'vat_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', 'in:draft,submitted,approved,rejected'],
            'created_by_user_id' => ['required', 'integer', 'exists:users,id'],
            'updated_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'approved_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}