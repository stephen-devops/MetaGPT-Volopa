<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * PocketExpense Model
 * 
 * Represents out-of-pocket expenses with comprehensive tracking and approval workflow.
 * Supports multi-tenant client scoping, FX conversion, and audit trail capabilities.
 * Uses Volopa's timestamp and soft delete patterns.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property \Carbon\Carbon $date
 * @property string $merchant_name
 * @property string|null $merchant_description
 * @property int|null $expense_type
 * @property string $currency
 * @property float $amount
 * @property string|null $merchant_address
 * @property float|null $vat_amount
 * @property string|null $notes
 * @property string $status
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $approved_by_user_id
 * @property \Carbon\Carbon $create_time
 * @property \Carbon\Carbon|null $update_time
 * @property bool $deleted
 * @property \Carbon\Carbon|null $delete_time
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\OptPocketExpenseType|null $expenseType
 * @property-read \App\Models\User $createdBy
 * @property-read \App\Models\User|null $updatedBy
 * @property-read \App\Models\User|null $approvedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpenseMetadata> $metadata
 * @property-read int|null $metadata_count
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
     * Indicates if the model should be timestamped using Volopa pattern.
     * We override Laravel timestamps to use Volopa's create_time/update_time pattern.
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
        'deleted',
        'delete_time',
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
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'deleted' => 'boolean',
        'delete_time' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'deleted',
        'delete_time',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'deleted' => false,
        'amount' => 0.00,
    ];

    /**
     * The attributes that should be validated as dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'date',
        'create_time',
        'update_time',
        'delete_time',
    ];

    /**
     * Status enum values.
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    /**
     * Valid status values.
     *
     * @var array<string>
     */
    public static array $validStatuses = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /**
     * Get the user that owns the expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpense>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client that this expense is scoped to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Client, \App\Models\PocketExpense>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type configuration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\OptPocketExpenseType, \App\Models\PocketExpense>
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type', 'id');
    }

    /**
     * Get the user who created this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpense>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpense>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get the user who approved this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, \App\Models\PocketExpense>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Get the metadata entries associated with this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id')
                    ->where('deleted', false);
    }

    /**
     * Scope a query to only include active (non-deleted) expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include soft deleted expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to only include expenses for a specific client.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to only include expenses for a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include expenses with a specific status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include draft expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope a query to only include submitted expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * Scope a query to only include approved expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to only include expenses within a date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Carbon\Carbon $startDate
     * @param \Carbon\Carbon $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, \Carbon\Carbon $startDate, \Carbon\Carbon $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to only include expenses with a specific currency.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $currency
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include expenses of a specific type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $expenseTypeId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithExpenseType($query, int $expenseTypeId)
    {
        return $query->where('expense_type', $expenseTypeId);
    }

    /**
     * Check if the expense is currently active (not soft deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Check if the expense is soft deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if the expense is in draft status.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if the expense is submitted for approval.
     *
     * @return bool
     */
    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Check if the expense is approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the expense is rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if the expense can be edited (only drafts can be edited).
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return $this->isDraft() && $this->isActive();
    }

    /**
     * Check if the expense can be submitted for approval.
     *
     * @return bool
     */
    public function canSubmit(): bool
    {
        return $this->isDraft() && $this->isActive();
    }

    /**
     * Check if the expense can be approved.
     *
     * @return bool
     */
    public function canApprove(): bool
    {
        return $this->isSubmitted() && $this->isActive();
    }

    /**
     * Check if the expense can be rejected.
     *
     * @return bool
     */
    public function canReject(): bool
    {
        return $this->isSubmitted() && $this->isActive();
    }

    /**
     * Check if the expense can be deleted (only drafts can be deleted).
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return $this->isDraft() && $this->isActive();
    }

    /**
     * Submit the expense for approval.
     *
     * @param int $submittedByUserId
     * @return bool
     */
    public function submit(int $submittedByUserId): bool
    {
        if (!$this->canSubmit()) {
            return false;
        }

        $this->status = self::STATUS_SUBMITTED;
        $this->updated_by_user_id = $submittedByUserId;
        return $this->save();
    }

    /**
     * Approve the expense.
     *
     * @param int $approvedByUserId
     * @return bool
     */
    public function approve(int $approvedByUserId): bool
    {
        if (!$this->canApprove()) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $approvedByUserId;
        $this->updated_by_user_id = $approvedByUserId;
        return $this->save();
    }

    /**
     * Reject the expense.
     *
     * @param int $rejectedByUserId
     * @return bool
     */
    public function reject(int $rejectedByUserId): bool
    {
        if (!$this->canReject()) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->updated_by_user_id = $rejectedByUserId;
        return $this->save();
    }

    /**
     * Revert the expense back to draft status.
     *
     * @param int $revertedByUserId
     * @return bool
     */
    public function revertToDraft(int $revertedByUserId): bool
    {
        if (!in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_REJECTED])) {
            return false;
        }

        $this->status = self::STATUS_DRAFT;
        $this->updated_by_user_id = $revertedByUserId;
        $this->approved_by_user_id = null;
        return $this->save();
    }

    /**
     * Soft delete the expense.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        if (!$this->canDelete()) {
            return false;
        }

        $this->deleted = true;
        $this->delete_time = now();
        return $this->save();
    }

    /**
     * Restore the soft deleted expense.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = false;
        $this->delete_time = null;
        return $this->save();
    }

    /**
     * Get the formatted amount with currency.
     *
     * @return string
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get the status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Awaiting Approval',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => ucfirst($this->status),
        };
    }

    /**
     * Get the count of associated metadata entries.
     *
     * @return int
     */
    public function getMetadataCountAttribute(): int
    {
        return $this->metadata()->count();
    }

    /**
     * Check if the expense has VAT information.
     *
     * @return bool
     */
    public function hasVat(): bool
    {
        return !is_null($this->vat_amount) && $this->vat_amount > 0;
    }

    /**
     * Get the net amount (amount minus VAT).
     *
     * @return float
     */
    public function getNetAmount(): float
    {
        return $this->hasVat() ? $this->amount - $this->vat_amount : $this->amount;
    }

    /**
     * Calculate VAT percentage if VAT amount is provided.
     *
     * @return float|null
     */
    public function getVatPercentage(): ?float
    {
        if (!$this->hasVat() || $this->getNetAmount() == 0) {
            return null;
        }

        return ($this->vat_amount / $this->getNetAmount()) * 100;
    }

    /**
     * Find expense by UUID.
     *
     * @param string $uuid
     * @return \App\Models\PocketExpense|null
     */
    public static function findByUuid(string $uuid): ?PocketExpense
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * Get expenses for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function forUserAndClient(int $userId, int $clientId): \Illuminate\Database\Eloquent\Builder
    {
        return static::active()
                     ->forUser($userId)
                     ->forClient($clientId);
    }

    /**
     * Get pending expenses (submitted but not yet approved/rejected) for a client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getPendingForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()
                     ->forClient($clientId)
                     ->submitted()
                     ->orderBy('create_time', 'asc')
                     ->get();
    }

    /**
     * Create a new expense with proper defaults.
     *
     * @param array $attributes
     * @return static
     */
    public static function createExpense(array $attributes): static
    {
        $attributes['uuid'] = $attributes['uuid'] ?? Str::uuid()->toString();
        $attributes['create_time'] = now();
        $attributes['status'] = $attributes['status'] ?? self::STATUS_DRAFT;
        $attributes['deleted'] = false;
        
        return static::create($attributes);
    }

    /**
     * Validate if a status value is valid.
     *
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::$validStatuses);
    }

    /**
     * Boot method for model events.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        // Generate UUID on creation if not provided
        static::creating(function (PocketExpense $expense) {
            if (empty($expense->uuid)) {
                $expense->uuid = Str::uuid()->toString();
            }
            
            if (is_null($expense->create_time)) {
                $expense->create_time = now();
            }
            
            // Ensure defaults
            if (is_null($expense->deleted)) {
                $expense->deleted = false;
            }
            
            if (empty($expense->status)) {
                $expense->status = self::STATUS_DRAFT;
            }

            // Validate status
            if (!self::isValidStatus($expense->status)) {
                throw new \InvalidArgumentException('Invalid expense status: ' . $expense->status);
            }

            // Ensure amount is positive
            if (isset($expense->amount)) {
                $expense->amount = abs($expense->amount);
            }

            // Trim merchant name to fit database constraint
            if (isset($expense->merchant_name)) {
                $expense->merchant_name = substr(trim($expense->merchant_name), 0, 180);
            }

            // Validate currency format (3-letter ISO)
            if (isset($expense->currency) && !preg_match('/^[A-Z]{3}$/', $expense->currency)) {
                throw new \InvalidArgumentException('Currency must be a 3-letter ISO code: ' . $expense->currency);
            }

            // Validate expense date is not too old (3 years maximum)
            if (isset($expense->date)) {
                $threeYearsAgo = now()->subYears(3);
                if ($expense->date < $threeYearsAgo) {
                    throw new \InvalidArgumentException('Expense date cannot be older than 3 years.');
                }
            }
        });

        // Handle updates
        static::updating(function (PocketExpense $expense) {
            // Set update_time (handled by database trigger, but set here for consistency)
            $expense->update_time = now();

            // Validate status changes
            if ($expense->isDirty('status') && !self::isValidStatus($expense->status)) {
                throw new \InvalidArgumentException('Invalid expense status: ' . $expense->status);
            }

            // Ensure amount is positive when updated
            if ($expense->isDirty('amount') && isset($expense->amount)) {
                $expense->amount = abs($expense->amount);
            }

            // Trim merchant name to fit database constraint
            if ($expense->isDirty('merchant_name') && isset($expense->merchant_name)) {
                $expense->merchant_name = substr(trim($expense->merchant_name), 0, 180);
            }

            // Validate currency format on updates
            if ($expense->isDirty('currency') && isset($expense->currency)) {
                if (!preg_match('/^[A-Z]{3}$/', $expense->currency)) {
                    throw new \InvalidArgumentException('Currency must be a 3-letter ISO code: ' . $expense->currency);
                }
            }

            // Validate expense date is not too old on updates
            if ($expense->isDirty('date') && isset($expense->date)) {
                $threeYearsAgo = now()->subYears(3);
                if ($expense->date < $threeYearsAgo) {
                    throw new \InvalidArgumentException('Expense date cannot be older than 3 years.');
                }
            }
        });

        // Log expense status changes for audit purposes
        static::updated(function (PocketExpense $expense) {
            if ($expense->isDirty('status')) {
                \Log::info('Expense status changed', [
                    'expense_id' => $expense->id,
                    'uuid' => $expense->uuid,
                    'user_id' => $expense->user_id,
                    'client_id' => $expense->client_id,
                    'old_status' => $expense->getOriginal('status'),
                    'new_status' => $expense->status,
                    'updated_by' => $expense->updated_by_user_id,
                    'approved_by' => $expense->approved_by_user_id,
                    'changed_at' => now(),
                ]);
            }
        });

        // Log soft deletion events
        static::updated(function (PocketExpense $expense) {
            if ($expense->isDirty('deleted') && $expense->deleted === true) {
                \Log::info('Expense soft deleted', [
                    'expense_id' => $expense->id,
                    'uuid' => $expense->uuid,
                    'user_id' => $expense->user_id,
                    'client_id' => $expense->client_id,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                    'deleted_at' => $expense->delete_time,
                ]);
            }
        });

        // Log restoration events
        static::updated(function (PocketExpense $expense) {
            if ($expense->isDirty('deleted') && $expense->deleted === false) {
                \Log::info('Expense restored', [
                    'expense_id' => $expense->id,
                    'uuid' => $expense->uuid,
                    'user_id' => $expense->user_id,
                    'client_id' => $expense->client_id,
                    'restored_at' => now(),
                ]);
            }
        });

        // Prevent hard deletion if not in draft status
        static::deleting(function (PocketExpense $expense) {
            if (!$expense->isDraft()) {
                throw new \RuntimeException('Cannot delete expense that is not in draft status. Use soft delete instead.');
            }
        });
    }
}