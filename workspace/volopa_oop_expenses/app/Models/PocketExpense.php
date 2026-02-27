## Code: app/Models/PocketExpense.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
     * The attributes that should be cast to native types.
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
    protected $hidden = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'deleted' => false,
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
        'delete_time',
    ];

    /**
     * Define the timestamp column names for custom timestamp fields.
     *
     * @var string
     */
    const CREATED_AT = 'create_time';
    const UPDATED_AT = 'update_time';

    /**
     * Valid status values for pocket expenses.
     *
     * @var array<string>
     */
    const VALID_STATUSES = [
        'draft',
        'submitted',
        'approved',
        'rejected',
    ];

    /**
     * Maximum date lookback in years for expense dates.
     *
     * @var int
     */
    const MAX_DATE_LOOKBACK_YEARS = 3;

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate UUID when creating new records
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            
            // Set create_time and update_time
            $model->create_time = now();
            $model->update_time = now();
        });

        // Update the update_time when saving
        static::updating(function (self $model): void {
            $model->update_time = now();
        });
    }

    /**
     * Get the user that this expense belongs to.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client that this expense belongs to.
     *
     * @return BelongsTo
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type for this expense.
     *
     * @return BelongsTo
     */
    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(OptPocketExpenseType::class, 'expense_type');
    }

    /**
     * Get all metadata records for this expense.
     *
     * @return HasMany
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id')
                    ->where('deleted', false);
    }

    /**
     * Get the user who created this expense.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this expense.
     *
     * @return BelongsTo
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Get the user who approved this expense.
     *
     * @return BelongsTo
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Scope a query to only include active (not deleted) expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted expenses.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
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
     * Scope a query to filter by status.
     *
     * @param Builder $query
     * @param string $status
     * @return Builder
     */
    public function scopeByStatus(Builder $query, string $