<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PocketExpense Model
 * 
 * Manages out-of-pocket expenses with full audit trail, status workflow,
 * and metadata relationships. Uses Volopa legacy timestamp pattern and
 * soft delete conventions. Supports multi-tenant client scoping and
 * comprehensive expense tracking with FX conversion capabilities.
 * 
 * @property int $id Primary key
 * @property string|null $uuid External UUID reference
 * @property int $user_id User who owns this expense
 * @property int $client_id Client context for multi-tenancy
 * @property string $date Expense date (YYYY-MM-DD format)
 * @property string $merchant_name Merchant/vendor name (max 180 chars)
 * @property string|null $merchant_description Additional merchant details
 * @property int $expense_type Foreign key to opt_pocket_expense_type table
 * @property string $currency 3-letter ISO currency code
 * @property float $amount Expense amount with decimal precision
 * @property string|null $merchant_address Merchant address information
 * @property float|null $vat_amount VAT amount if applicable
 * @property string|null $notes Additional notes (trimmed, SQL injection safe)
 * @property string $status Expense approval status workflow (draft, submitted, approved, rejected)
 * @property int $created_by_user_id User who created this expense record
 * @property int|null $updated_by_user_id User who last updated this expense
 * @property int|null $approved_by_user_id User who approved this expense (if status=approved)
 * @property Carbon|null $create_time Record creation timestamp
 * @property Carbon|null $update_time Record update timestamp
 * @property int $deleted Soft delete flag (0=active, 1=deleted)
 * @property Carbon|null $delete_time Soft delete timestamp
 * 
 * @property-read User $user Expense owner relationship
 * @property-read User $client Client relationship
 * @property-read OptPocketExpenseType $expenseType Expense type relationship
 * @property-read User $createdBy User who created this expense
 * @property-read User|null $updatedBy User who last updated this expense
 * @property-read User|null $approvedBy User who approved this expense
 * @property-read \Illuminate\Database\Eloquent\Collection|PocketExpenseMetadata[] $metadata Related metadata records
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
     * Indicates if the model should be timestamped using Laravel conventions.
     * We use Volopa legacy timestamp pattern instead.
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
        'delete_time',
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
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'delete_time' => 'datetime',
        'deleted' => 'integer',
        'date' => 'date',
    ];

    /**
     * The attributes that should be mutated to dates.
     * Using Volopa legacy timestamp fields.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'create_time',
        'update_time',
        'delete_time',
        'date',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'deleted' => 0,
        'delete_time' => null,
        'merchant_description' => null,
        'merchant_address' => null,
        'vat_amount' => null,
        'notes' => null,
        'updated_by_user_id' => null,
        'approved_by_user_id' => null,
        'uuid' => null,
    ];

    /**
     * The expense status enumeration values.
     *
     * @var array<string>
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Valid expense status values.
     *
     * @var array<string>
     */
    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /**
     * Boot the model and set up event listeners.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Generate UUID on creation if not provided
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }

            // Set create_time if not provided
            if (empty($model->create_time)) {
                $model->create_time = Carbon::now();
            }

            // Set update_time to match create_time on creation
            if (empty($model->update_time)) {
                $model->update_time = $model->create_time;
            }
        });

        // Update the update_time on model updates
        static::updating(function (self $model) {
            $model->update_time = Carbon::now();
        });

        // Add global scope to exclude soft deleted records
        static::addGlobalScope('notDeleted', function ($builder) {
            $builder->where('deleted', 0);
        });
    }

    /**
     * Get the user who owns this expense.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client this expense belongs to.
     *
     * @return BelongsTo<Client, PocketExpense>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
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
     * Get the user who created this expense.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'id');
    }

    /**
     * Get the user who last updated this expense.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id', 'id');
    }

    /**
     * Get the user who approved this expense.
     *
     * @return BelongsTo<User, PocketExpense>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id', 'id');
    }

    /**
     * Get all metadata records associated with this expense.
     *
     * @return HasMany<PocketExpenseMetadata>
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id');
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
     * Scope a query to only include expenses within a date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|\DateTimeInterface $startDate
     * @param string|\DateTimeInterface $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithinDateRange($query, $startDate, $endDate)
    {
        $start = $startDate instanceof \DateTimeInterface ? $startDate->format('Y-m-d') : $startDate;
        $end = $endDate instanceof \DateTimeInterface ? $endDate->format('Y-m-d') : $endDate;
        
        return $query->whereBetween('date', [$start, $end]);
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
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Scope a query to only include expenses above a certain amount.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $amount
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAboveAmount($query, float $amount)
    {
        return $query->where('amount', '>', $amount);
    }

    /**
     * Scope a query to only include expenses below a certain amount.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float $amount
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBelowAmount($query, float $amount)
    {
        return $query->where('amount', '<', $amount);
    }

    /**
     * Scope a query to include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDeleted($query)
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * Scope a query to only include soft deleted records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOnlyDeleted($query)
    {
        return $query->withoutGlobalScope('notDeleted')->where('deleted', 1);
    }

    /**
     * Check if this expense is in draft status.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if this expense is submitted for approval.
     *
     * @return bool
     */
    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Check if this expense is approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if this expense is rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if this expense can be edited.
     * Only draft and rejected expenses can be edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED]);
    }

    /**
     * Check if this expense can be submitted for approval.
     * Only draft and rejected expenses can be submitted.
     *
     * @return bool
     */
    public function canBeSubmitted(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED]);
    }

    /**
     * Check if this expense can be approved.
     * Only submitted expenses can be approved.
     *
     * @return bool
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Check if this expense can be rejected.
     * Only submitted expenses can be rejected.
     *
     * @return bool
     */
    public function canBeRejected(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * Check if this expense is soft deleted.
     *
     * @return bool
     */
    public function isSoftDeleted(): bool
    {
        return $this->deleted === 1;
    }

    /**
     * Soft delete this expense.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = 1;
        $this->delete_time = Carbon::now();
        $this->update_time = Carbon::now();
        
        return $this->save();
    }

    /**
     * Restore this soft deleted expense.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        $this->update_time = Carbon::now();
        
        return $this->save();
    }

    /**
     * Submit this expense for approval.
     * Changes status from draft or rejected to submitted.
     *
     * @return bool
     */
    public function submit(): bool
    {
        if (!$this->canBeSubmitted()) {
            return false;
        }

        $this->status = self::STATUS_SUBMITTED;
        $this->approved_by_user_id = null; // Clear any previous approval
        
        return $this->save();
    }

    /**
     * Approve this expense.
     * Changes status to approved and sets the approver.
     *
     * @param int $approverUserId
     * @return bool
     */
    public function approve(int $approverUserId): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $approverUserId;
        
        return $this->save();
    }

    /**
     * Reject this expense.
     * Changes status to rejected and clears the approver.
     *
     * @return bool
     */
    public function reject(): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->approved_by_user_id = null;
        
        return $this->save();
    }

    /**
     * Get the formatted amount with currency symbol.
     *
     * @return string
     */
    public function getFormattedAmountAttribute(): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
        ];
        
        $symbol = $symbols[$this->currency] ?? $this->currency;
        return $symbol . number_format($this->amount, 2);
    }

    /**
     * Get the formatted VAT amount with currency symbol.
     *
     * @return string|null
     */
    public function getFormattedVatAmountAttribute(): ?string
    {
        if ($this->vat_amount === null) {
            return null;
        }
        
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
        ];
        
        $symbol = $symbols[$this->currency] ?? $this->currency;
        return $symbol . number_format($this->vat_amount, 2);
    }

    /**
     * Get the human readable status.
     *
     * @return string
     */
    public function getStatusDisplayAttribute(): string
    {
        $statusDisplayMap = [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
        ];
        
        return $statusDisplayMap[$this->status] ?? 'Unknown';
    }

    /**
     * Get the expense age in days from the expense date.
     *
     * @return int
     */
    public function getAgeInDaysAttribute(): int
    {
        return Carbon::parse($this->date)->diffInDays(Carbon::now());
    }

    /**
     * Check if the expense date is within the allowed age limit (3 years).
     *
     * @return bool
     */
    public function isWithinAgeLimit(): bool
    {
        $threeYearsAgo = Carbon::now()->subYears(3);
        return Carbon::parse($this->date)->isAfter($threeYearsAgo);
    }

    /**
     * Get metadata of a specific type.
     *
     * @param string $metadataType
     * @return PocketExpenseMetadata|null
     */
    public function getMetadataByType(string $metadataType): ?PocketExpenseMetadata
    {
        return $this->metadata()->where('metadata_type', $metadataType)->first();
    }

    /**
     * Check if this expense has metadata of a specific type.
     *
     * @param string $metadataType
     * @return bool
     */
    public function hasMetadataType(string $metadataType): bool
    {
        return $this->metadata()->where('metadata_type', $metadataType)->exists();
    }

    /**
     * Set the merchant name with proper trimming and validation.
     *
     * @param string $value
     * @return void
     */
    public function setMerchantNameAttribute(string $value): void
    {
        // Trim whitespace and limit to 180 characters as per DB constraint
        $this->attributes['merchant_name'] = substr(trim($value), 0, 180);
    }

    /**
     * Set the notes with proper trimming to prevent SQL injection and follow DB limit.
     *
     * @param string|null $value
     * @return void
     */
    public function setNotesAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['notes'] = null;
        } else {
            // Trim whitespace and HTML tags for security
            $this->attributes['notes'] = trim(strip_tags($value));
        }
    }

    /**
     * Set the currency code to uppercase.
     *
     * @param string $value
     * @return void
     */
    public function setCurrencyAttribute(string $value): void
    {
        $this->attributes['currency'] = strtoupper($value);
    }

    /**
     * Set the status with validation.
     *
     * @param string $value
     * @return void
     */
    public function setStatusAttribute(string $value): void
    {
        if (!in_array($value, self::VALID_STATUSES)) {
            throw new \InvalidArgumentException("Invalid status: {$value}");
        }
        
        $this->attributes['status'] = $value;
    }

    /**
     * Get the route key name for model binding.
     *
     * @return string
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Convert the model instance to an array for API responses.
     * Excludes sensitive internal fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        // Add computed attributes
        $array['formatted_amount'] = $this->formatted_amount;
        $array['formatted_vat_amount'] = $this->formatted_vat_amount;
        $array['status_display'] = $this->status_display;
        $array['age_in_days'] = $this->age_in_days;
        $array['can_be_edited'] = $this->canBeEdited();
        $array['can_be_submitted'] = $this->canBeSubmitted();
        $array['can_be_approved'] = $this->canBeApproved();
        $array['can_be_rejected'] = $this->canBeRejected();
        $array['is_within_age_limit'] = $this->isWithinAgeLimit();
        
        return $array;
    }

    /**
     * Determine if the model should be searchable.
     * Used for search functionality if implemented.
     *
     * @return bool
     */
    public function shouldBeSearchable(): bool
    {
        return !$this->isSoftDeleted();
    }

    /**
     * Get the searchable data array for the model.
     * Used for search functionality if implemented.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'status' => $this->status,
            'notes' => $this->notes,
            'date' => $this->date->format('Y-m-d'),
        ];
    }
}