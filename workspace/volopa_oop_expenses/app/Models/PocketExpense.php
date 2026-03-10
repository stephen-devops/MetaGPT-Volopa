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
 * Core model for storing out-of-pocket expenses in the OOP expense management system.
 * This model stores individual expense records with relationships to users, clients, and expense types.
 * Includes soft delete pattern, audit fields for tracking changes, and comprehensive workflow status management.
 * Uses custom timestamp pattern (create_time/update_time) and soft delete pattern (deleted/delete_time).
 * 
 * @property int $id Primary key for pocket expense
 * @property string $uuid Unique identifier for external references
 * @property int $user_id The user who owns this expense
 * @property int $client_id The client context for this expense
 * @property Carbon $date The date when the expense occurred
 * @property string $merchant_name The name of the merchant/vendor
 * @property string|null $merchant_description Additional description of the merchant/transaction
 * @property int $expense_type Foreign key to opt_pocket_expense_type table
 * @property string $currency ISO 3-letter currency code
 * @property float $amount The expense amount in the specified currency
 * @property string|null $merchant_address Address of the merchant
 * @property float|null $vat_amount VAT/tax amount if applicable
 * @property string|null $notes Additional notes or comments about the expense
 * @property string $status Current status of the expense in the approval workflow
 * @property int $created_by_user_id User who created this expense record
 * @property int|null $updated_by_user_id User who last updated this expense record
 * @property int|null $approved_by_user_id User who approved this expense (if applicable)
 * @property Carbon $create_time Timestamp when the record was created
 * @property Carbon $update_time Timestamp when the record was last updated
 * @property bool $deleted Soft delete flag
 * @property Carbon|null $delete_time Timestamp when the record was soft deleted
 * @property-read User $user
 * @property-read Client $client
 * @property-read OptPocketExpenseType $expenseType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata> $metadata
 * @property-read User $createdBy
 * @property-read User|null $updatedBy
 * @property-read User|null $approvedBy
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
     * Indicates if the model should be timestamped.
     * We use custom timestamps (create_time/update_time).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

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
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'deleted' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'deleted',
        'delete_time',
    ];

    /**
     * Valid status values for expense workflow.
     *
     * @var array<int, string>
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * All valid status values.
     *
     * @var array<int, string>
     */
    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /**
     * Status transitions allowed in the workflow.
     *
     * @var array<string, array<int, string>>
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_DRAFT],
        self::STATUS_APPROVED => [],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_SUBMITTED],
    ];

    /**
     * Maximum length for merchant name.
     *
     * @var int
     */
    public const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Maximum length for merchant description.
     *
     * @var int
     */
    public const MAX_MERCHANT_DESCRIPTION_LENGTH = 255;

    /**
     * Maximum length for merchant address.
     *
     * @var int
     */
    public const MAX_MERCHANT_ADDRESS_LENGTH = 500;

    /**
     * Maximum length for currency code.
     *
     * @var int
     */
    public const MAX_CURRENCY_LENGTH = 3;

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID when creating new records
        static::creating(function (PocketExpense $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            
            if (empty($model->update_time)) {
                $model->update_time = now();
            }

            // Set created_by_user_id if not already set
            if (empty($model->created_by_user_id) && auth()->check()) {
                $model->created_by_user_id = auth()->user()->id;
            }

            // Set user_id if not already set and user is authenticated
            if (empty($model->user_id) && auth()->check()) {
                $model->user_id = auth()->user()->id;
            }

            // Set client_id if not already set and user is authenticated
            if (empty($model->client_id) && auth()->check() && auth()->user()->client_id) {
                $model->client_id = auth()->user()->client_id;
            }
        });

        // Update the update_time when updating records
        static::updating(function (PocketExpense $model) {
            $model->update_time = now();

            // Set updated_by_user_id
            if (auth()->check()) {
                $model->updated_by_user_id = auth()->user()->id;
            }
        });

        // Apply soft delete scope by default
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('deleted', false);
        });

        // Automatically scope all queries by client_id for multi-tenancy
        static::addGlobalScope('client_scope', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });

        // Validate status transitions
        static::updating(function (PocketExpense $model) {
            if ($model->isDirty('status')) {
                $originalStatus = $model->getOriginal('status');
                $newStatus = $model->status;
                
                if (!$model->isValidStatusTransition($originalStatus, $newStatus)) {
                    throw new \InvalidArgumentException(
                        "Invalid status transition from '{$originalStatus}' to '{$newStatus}'"
                    );
                }
            }
        });

        // Validate status values
        static::saving(function (PocketExpense $model) {
            if (!self::isValidStatus($model->status)) {
                throw new \InvalidArgumentException(
                    "Invalid status value: {$model->status}. Must be one of: " . 
                    implode(', ', self::VALID_STATUSES)
                );
            }
        });
    }

    /**
     * Get the user who owns this expense.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this expense.
     *
     * @return BelongsTo<Client, PocketExpense>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type for this expense.
     *
     * @return BelongsTo<OptPocketExpenseType, PocketExpense>
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type', 'id');
    }

    /**
     * Get the metadata records associated with this expense.
     *
     * @return HasMany<PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id');
    }

    /**
     * Get the user who created this expense record.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this expense record.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get the user who approved this expense.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Scope a query to only include draft expenses.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope a query to only include submitted expenses.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * Scope a query to only include approved expenses.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected expenses.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to filter by specific user.
     *
     * @param Builder<PocketExpense> $query
     * @param int $userId
     * @return Builder<PocketExpense>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by specific client.
     *
     * @param Builder<PocketExpense> $query
     * @param int $clientId
     * @return Builder<PocketExpense>
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by specific expense type.
     *
     * @param Builder<PocketExpense> $query
     * @param int $expenseTypeId
     * @return Builder<PocketExpense>
     */
    public function scopeByExpenseType(Builder $query, int $expenseTypeId): Builder
    {
        return $query->where('expense_type', $expenseTypeId);
    }

    /**
     * Scope a query to filter by currency.
     *
     * @param Builder<PocketExpense> $query
     * @param string $currency
     * @return Builder<PocketExpense>
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param Builder<PocketExpense> $query
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Builder<PocketExpense>
     */
    public function scopeDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by amount range.
     *
     * @param Builder<PocketExpense> $query
     * @param float $minAmount
     * @param float $maxAmount
     * @return Builder<PocketExpense>
     */
    public function scopeAmountRange(Builder $query, float $minAmount, float $maxAmount): Builder
    {
        return $query->whereBetween('amount', [$minAmount, $maxAmount]);
    }

    /**
     * Scope a query to search by merchant name.
     *
     * @param Builder<PocketExpense> $query
     * @param string $search
     * @return Builder<PocketExpense>
     */
    public function scopeSearchMerchant(Builder $query, string $search): Builder
    {
        return $query->where('merchant_name', 'like', "%{$search}%");
    }

    /**
     * Scope a query to include deleted records.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted');
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted')->where('deleted', true);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param Builder<PocketExpense> $query
     * @param string $status
     * @return Builder<PocketExpense>
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to get expenses pending approval.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * Scope a query to get expenses that can be edited.
     *
     * @param Builder<PocketExpense> $query
     * @return Builder<PocketExpense>
     */
    public function scopeEditable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_REJECTED]);
    }

    /**
     * Scope a query to get expenses created by specific user.
     *
     * @param Builder<PocketExpense> $query
     * @param int $userId
     * @return Builder<PocketExpense>
     */
    public function scopeCreatedBy(Builder $query, int $userId): Builder
    {
        return $query->where('created_by_user_id', $userId);
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
     * Check if the expense is submitted.
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
     * Check if the expense can be edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED]) && !$this->isDeleted();
    }

    /**
     * Check if the expense can be deleted.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return !$this->isApproved() && !$this->isDeleted();
    }

    /**
     * Check if the expense can be submitted.
     *
     * @return bool
     */
    public function canBeSubmitted(): bool
    {
        return $this->isDraft();
    }

    /**
     * Check if the expense can be approved.
     *
     * @return bool
     */
    public function canBeApproved(): bool
    {
        return $this->isSubmitted();
    }

    /**
     * Check if the expense can be rejected.
     *
     * @return bool
     */
    public function canBeRejected(): bool
    {
        return $this->isSubmitted();
    }

    /**
     * Check if the expense is currently deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Check if the expense is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === false;
    }

    /**
     * Submit the expense for approval.
     *
     * @return bool
     */
    public function submit(): bool
    {
        if (!$this->canBeSubmitted()) {
            return false;
        }

        $this->status = self::STATUS_SUBMITTED;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Approve the expense.
     *
     * @param int|null $approvedByUserId
     * @return bool
     */
    public function approve(?int $approvedByUserId = null): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $approvedByUserId ?? auth()->user()->id ?? null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Reject the expense.
     *
     * @return bool
     */
    public function reject(): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Return the expense to draft status.
     *
     * @return bool
     */
    public function returnToDraft(): bool
    {
        if (!in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_REJECTED])) {
            return false;
        }

        $this->status = self::STATUS_DRAFT;
        $this->approved_by_user_id = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Soft delete this expense.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        if (!$this->canBeDeleted()) {
            return false;
        }

        $this->deleted = true;
        $this->delete_time = now();
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Restore a soft deleted expense.
     *
     * @return bool
     */
    public function restore(): bool
    {
        if (!$this->isDeleted()) {
            return false;
        }

        $this->deleted = false;
        $this->delete_time = null;
        $this->update_time = now();

        return $this->save();
    }

    /**
     * Force delete this expense permanently.
     * Should only be used for maintenance operations.
     *
     * @return bool|null
     */
    public function forceDelete(): ?bool
    {
        // First delete related metadata
        $this->metadata()->delete();
        
        return parent::delete();
    }

    /**
     * Get the signed amount based on expense type.
     *
     * @return float
     */
    public function getSignedAmount(): float
    {
        if ($this->expenseType && $this->expenseType->isPositive()) {
            return abs($this->amount);
        }
        
        return -abs($this->amount);
    }

    /**
     * Get the absolute amount.
     *
     * @return float
     */
    public function getAbsoluteAmount(): float
    {
        return abs($this->amount);
    }

    /**
     * Get a human-readable description of this expense.
     *
     * @return string
     */
    public function getDescription(): string
    {
        $userName = $this->user->name ?? 'Unknown User';
        $expenseTypeName = $this->expenseType->option ?? 'Unknown Type';
        $statusLabel = ucfirst($this->status);
        
        return "{$userName} - {$this->merchant_name} ({$expenseTypeName}) - {$statusLabel}";
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
     * Get the formatted VAT amount with currency.
     *
     * @return string|null
     */
    public function getFormattedVatAmount(): ?string
    {
        if ($this->vat_amount === null) {
            return null;
        }
        
        return number_format($this->vat_amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get the total amount including VAT.
     *
     * @return float
     */
    public function getTotalAmount(): float
    {
        return $this->amount + ($this->vat_amount ?? 0);
    }

    /**
     * Get the formatted total amount with currency.
     *
     * @return string
     */
    public function getFormattedTotalAmount(): string
    {
        return number_format($this->getTotalAmount(), 2) . ' ' . $this->currency;
    }

    /**
     * Get metadata by type.
     *
     * @param string $metadataType
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata>
     */
    public function getMetadataByType(string $metadataType): \Illuminate\Database\Eloquent\Collection
    {
        return $this->metadata()->where('metadata_type', $metadataType)->get();
    }

    /**
     * Check if the expense has metadata of a specific type.
     *
     * @param string $metadataType
     * @return bool
     */
    public function hasMetadataType(string $metadataType): bool
    {
        return $this->metadata()->where('metadata_type', $metadataType)->exists();
    }

    /**
     * Get the expense source from metadata.
     *
     * @return PocketExpenseMetadata|null
     */
    public function getExpenseSource(): ?PocketExpenseMetadata
    {
        return $this->metadata()->where('metadata_type', 'expense_source')->first();
    }

    /**
     * Get attached files from metadata.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpenseMetadata>
     */
    public function getAttachedFiles(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->getMetadataByType('file_attachment');
    }

    /**
     * Check if the expense belongs to a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /**
     * Check if the expense belongs to a specific client.
     *
     * @param int $clientId
     * @return bool
     */
    public function belongsToClient(int $clientId): bool
    {
        return $this->client_id === $clientId;
    }

    /**
     * Check if the expense was created by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function wasCreatedBy(int $userId): bool
    {
        return $this->created_by_user_id === $userId;
    }

    /**
     * Check if the expense was approved by a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function wasApprovedBy(int $userId): bool
    {
        return $this->approved_by_user_id === $userId;
    }

    /**
     * Find an expense by UUID.
     *
     * @param string $uuid
     * @return PocketExpense|null
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
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpense>
     */
    public static function getForUserAndClient(int $userId, int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUser($userId)->forClient($clientId)->orderBy('date', 'desc')->get();
    }

    /**
     * Get expenses by status for a client.
     *
     * @param int $clientId
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpense>
     */
    public static function getByStatusForClient(int $clientId, string $status): \Illuminate\Database\Eloquent\Collection
    {
        return static::forClient($clientId)->byStatus($status)->orderBy('date', 'desc')->get();
    }

    /**
     * Get total expenses amount for a client in a date range.
     *
     * @param int $clientId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string|null $status
     * @return float
     */
    public static function getTotalForClientDateRange(int $clientId, Carbon $startDate, Carbon $endDate, ?string $status = null): float
    {
        $query = static::forClient($clientId)->dateRange($startDate, $endDate);
        
        if ($status !== null) {
            $query->byStatus($status);
        }
        
        return $query->sum('amount') ?? 0.0;
    }

    /**
     * Get expenses grouped by status for a client.
     *
     * @param int $clientId
     * @return array<string, \Illuminate\Database\Eloquent\Collection<int, PocketExpense>>
     */
    public static function getGroupedByStatusForClient(int $clientId): array
    {
        $expenses = static::forClient($clientId)->orderBy('date', 'desc')->get();

        return [
            'draft' => $expenses->filter(fn($expense) => $expense->isDraft()),
            'submitted' => $expenses->filter(fn($expense) => $expense->isSubmitted()),
            'approved' => $expenses->filter(fn($expense) => $expense->isApproved()),
            'rejected' => $expenses->filter(fn($expense) => $expense->isRejected()),
        ];
    }

    /**
     * Get recent expenses for a user.
     *
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection<int, PocketExpense>
     */
    public static function getRecentForUser(int $userId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUser($userId)
                    ->orderBy('create_time', 'desc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Get expenses summary for a client.
     *
     * @param int $clientId
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return array<string, mixed>
     */
    public static function getSummaryForClient(int $clientId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = static::forClient($clientId);
        
        if ($startDate && $endDate) {
            $query->dateRange($startDate, $endDate);
        }
        
        $expenses = $query->get();
        
        return [
            'total_count' => $expenses->count(),
            'total_amount' => $expenses->sum('amount'),
            'draft_count' => $expenses->where('status', self::STATUS_DRAFT)->count(),
            'submitted_count' => $expenses->where('status', self::STATUS_SUBMITTED)->count(),
            'approved_count' => $expenses->where('status', self::STATUS_APPROVED)->count(),
            'rejected_count' => $expenses->where('status', self::STATUS_REJECTED)->count(),
            'average_amount' => $expenses->count() > 0 ? $expenses->avg('amount') : 0,
            'currencies' => $expenses->pluck('currency')->unique()->values()->toArray(),
        ];
    }

    /**
     * Validate if a status value is valid.
     *
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES, true);
    }

    /**
     * Check if a status transition is valid.
     *
     * @param string $fromStatus
     * @param string $toStatus
     * @return bool
     */
    public function isValidStatusTransition(string $fromStatus, string $toStatus): bool
    {
        if (!self::isValidStatus($fromStatus) || !self::isValidStatus($toStatus)) {
            return false;
        }
        
        if ($fromStatus === $toStatus) {
            return true; // Same status is always valid
        }
        
        return in_array($toStatus, self::STATUS_TRANSITIONS[$fromStatus] ?? [], true);
    }

    /**
     * Get available status transitions from current status.
     *
     * @return array<int, string>
     */
    public function getAvailableStatusTransitions(): array
    {
        return self::STATUS_TRANSITIONS[$this->status] ?? [];
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
        $array['is_draft'] = $this->isDraft();
        $array['is_submitted'] = $this->isSubmitted();
        $array['is_approved'] = $this->isApproved();
        $array['is_rejected'] = $this->isRejected();
        $array['can_be_edited'] = $this->canBeEdited();
        $array['can_be_deleted'] = $this->canBeDeleted();
        $array['can_be_submitted'] = $this->canBeSubmitted();
        $array['can_be_approved'] = $this->canBeApproved();
        $array['can_be_rejected'] = $this->canBeRejected();
        $array['is_active'] = $this->isActive();
        $array['signed_amount'] = $this->getSignedAmount();
        $array['absolute_amount'] = $this->getAbsoluteAmount();
        $array['formatted_amount'] = $this->getFormattedAmount();
        $array['formatted_vat_amount'] = $this->getFormattedVatAmount();
        $array['total_amount'] = $this->getTotalAmount();
        $array['formatted_total_amount'] = $this->getFormattedTotalAmount();
        $array['description'] = $this->getDescription();
        $array['available_status_transitions'] = $this->getAvailableStatusTransitions();
        
        return $array;
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     * @param string|null $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}