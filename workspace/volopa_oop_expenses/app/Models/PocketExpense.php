<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Pocket Expense Model
 * 
 * Main expense model managing out-of-pocket expenses with multi-tenant client scoping.
 * Includes relationships to users, clients, expense types, and metadata.
 * Uses flag-based soft delete pattern per Volopa legacy convention.
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
 * @property \Illuminate\Support\Carbon $create_time
 * @property \Illuminate\Support\Carbon|null $update_time
 * @property bool $deleted
 * @property \Illuminate\Support\Carbon|null $delete_time
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
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     * Using custom timestamp columns per Volopa legacy convention.
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
     * Boot the model.
     * Auto-generate UUID on creation and manage custom timestamps.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
            if (empty($model->create_time)) {
                $model->create_time = now();
            }
            $model->update_time = now();
        });

        static::updating(function ($model) {
            $model->update_time = now();
        });
    }

    /**
     * Get the user that owns this expense.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this expense.
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
     * Get the metadata associated with this expense.
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id');
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
     * Scope a query to only include deleted expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', true);
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
        return $query->where('status', 'draft');
    }

    /**
     * Scope a query to only include submitted expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    /**
     * Scope a query to only include approved expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to include expenses within a date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to include expenses with a specific currency.
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
     * Check if this expense is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !$this->deleted;
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
     * Soft delete this expense using flag-based soft delete.
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
     * Restore a soft deleted expense.
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
     * Submit this expense (change status from draft to submitted).
     *
     * @return bool
     */
    public function submit(): bool
    {
        if ($this->isDraft()) {
            $this->status = 'submitted';
            $this->update_time = now();
            return $this->save();
        }
        
        return false;
    }

    /**
     * Approve this expense.
     *
     * @param int $approvedByUserId
     * @return bool
     */
    public function approve(int $approvedByUserId): bool
    {
        if ($this->isSubmitted()) {
            $this->status = 'approved';
            $this->approved_by_user_id = $approvedByUserId;
            $this->update_time = now();
            return $this->save();
        }
        
        return false;
    }

    /**
     * Reject this expense.
     *
     * @return bool
     */
    public function reject(): bool
    {
        if ($this->isSubmitted()) {
            $this->status = 'rejected';
            $this->approved_by_user_id = null; // Clear approver if previously set
            $this->update_time = now();
            return $this->save();
        }
        
        return false;
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
     * Get the display status with proper formatting.
     *
     * @return string
     */
    public function getDisplayStatus(): string
    {
        return ucfirst($this->status);
    }

    /**
     * Check if this expense can be edited.
     * Only draft expenses can be edited.
     *
     * @return bool
     */
    public function canEdit(): bool
    {
        return $this->isDraft() && $this->isActive();
    }

    /**
     * Check if this expense can be deleted.
     * Only draft expenses can be deleted.
     *
     * @return bool
     */
    public function canDelete(): bool
    {
        return $this->isDraft() && $this->isActive();
    }

    /**
     * Check if this expense can be submitted.
     * Only draft expenses can be submitted.
     *
     * @return bool
     */
    public function canSubmit(): bool
    {
        return $this->isDraft() && $this->isActive();
    }

    /**
     * Check if this expense can be approved.
     * Only submitted expenses can be approved.
     *
     * @return bool
     */
    public function canApprove(): bool
    {
        return $this->isSubmitted() && $this->isActive();
    }

    /**
     * Check if this expense can be rejected.
     * Only submitted expenses can be rejected.
     *
     * @return bool
     */
    public function canReject(): bool
    {
        return $this->isSubmitted() && $this->isActive();
    }
}