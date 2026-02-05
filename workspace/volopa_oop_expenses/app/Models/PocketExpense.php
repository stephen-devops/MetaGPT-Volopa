## Code: app/Models/PocketExpense.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpense Model
 * 
 * Manages pocket expenses with comprehensive metadata support.
 * Includes relationships to users, clients, expense types, and metadata.
 * Supports soft delete functionality with custom delete_time field.
 * 
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $client_id
 * @property Carbon $date
 * @property string $merchant_name
 * @property string|null $merchant_description
 * @property int $expense_type
 * @property string $currency
 * @property float $amount
 * @property string|null $merchant_address
 * @property string|null $merchant_country
 * @property float|null $vat_amount
 * @property float|null $user_converted_amount
 * @property string|null $notes
 * @property string $status
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $approved_by_user_id
 * @property Carbon|null $approved_at
 * @property Carbon $create_time
 * @property Carbon $update_time
 * @property bool $deleted
 * @property Carbon|null $delete_time
 * 
 * @property-read User $user
 * @property-read Client $client
 * @property-read OptPocketExpenseType $expenseType
 * @property-read User $creator
 * @property-read User|null $updater
 * @property-read User|null $approver
 * @property-read Collection|PocketExpenseMetadata[] $metadata
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
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'create_time';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = 'update_time';

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
        'merchant_country',
        'vat_amount',
        'user_converted_amount',
        'notes',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
        'approved_by_user_id',
        'approved_at',
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
        'merchant_country' => 'string',
        'vat_amount' => 'decimal:2',
        'user_converted_amount' => 'decimal:2',
        'notes' => 'string',
        'status' => 'string',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
        'approved_at' => 'datetime',
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
    protected $hidden = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'deleted' => false,
    ];

    /**
     * Status constants.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /**
     * All available statuses.
     */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    /**
     * Currency code constants.
     */
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_GBP = 'GBP';

    /**
     * Bootstrap the model and its traits.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically generate UUID when creating
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            
            if (empty($model->update_time)) {
                $model->update_time = now();
            }
        });

        // Update the update_time when saving
        static::updating(function (self $model) {
            $model->update_time = now();
        });
    }

    /**
     * Get the user that owns this pocket expense.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client associated with this pocket expense.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type for this pocket expense.
     *
     * @return BelongsTo
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type', 'id');
    }

    /**
     * Get the user who created this pocket expense.
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this pocket expense.
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get the user who approved this pocket expense.
     *
     * @return BelongsTo
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Get all metadata associated with this pocket expense.
     *
     * @return HasMany
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id');
    }

    /**
     * Scope a query to only include non-deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted records.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to filter by user.
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
     * Scope a query to filter by client.
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
     * Scope a query to filter by status.
     *
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by multiple statuses.
     *
     * @param Builder $query
     * @param array $statuses
     * @return Builder
     */
    public function scopeByStatuses(Builder $query, array $statuses): Builder
    {
        return $query->whereIn('status', $statuses);
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
     * Scope a query to filter by expense type.
     *
     * @param Builder $query
     * @param int $expenseType
     * @return Builder
     */
    public function scopeByExpenseType(Builder $query, int $expenseType): Builder
    {
        return $query->where('expense_type', $expenseType);
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param Builder $query
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Builder
     */
    public function scopeByDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by date from.
     *
     * @param Builder $query
     * @param Carbon $date
     * @return Builder
     */
    public function scopeFromDate(Builder $query, Carbon $date): Builder
    {
        return $query->where('date', '>=', $date);
    }

    /**
     * Scope a query to filter by date to.
     *
     * @param Builder $query
     * @param Carbon $date
     * @return Builder
     */
    public function scopeToDate(Builder $query, Carbon $date): Builder
    {
        return $query->where('date', '<=', $date);
    }

    /**
     * Scope a query to filter by amount range.
     *
     * @param Builder $query
     * @param float $minAmount
     * @param float $maxAmount
     * @return Builder
     */
    public function scopeByAmountRange(Builder $query, float $minAmount, float $maxAmount): Builder
    {
        return $query->whereBetween('amount', [$minAmount, $maxAmount]);
    }

    /**
     * Scope a query to search by merchant name.
     *
     * @param Builder $query
     * @param string $merchantName
     * @return Builder
     */
    public function scopeByMerchantName(Builder $query, string $merchantName): Builder
    {
        return $query->where('merchant_name', 'LIKE', '%' . $merchantName . '%');
    }

    /**
     * Scope a query to search by UUID.
     *
     * @param Builder $query
     * @param string $uuid
     * @return Builder
     */
    public function scopeByUuid(Builder $query, string $uuid): Builder
    {
        return $query->where('uuid', $uuid);
    }

    /**
     * Scope a query to only include draft expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_DRAFT);
    }

    /**
     * Scope a query to only include submitted expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_SUBMITTED);
    }

    /**
     * Scope a query to only include approved expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_REJECTED);
    }

    /**
     * Scope a query to only include pending expenses (submitted but not approved/rejected).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->byStatus(self::STATUS_SUBMITTED);
    }

    /**
     * Scope a query to only include processed expenses (approved or rejected).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->byStatuses([self::STATUS_APPROVED, self::STATUS_REJECTED]);
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
     * Check if this expense is submitted.
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