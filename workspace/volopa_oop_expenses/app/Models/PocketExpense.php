<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PocketExpense Model
 * 
 * Main expense model representing out-of-pocket expenses with audit trails,
 * workflow status, and soft delete capability.
 * Uses flag-based soft delete pattern and custom timestamp columns.
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
 * @property int $deleted
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
     * Indicates if the model should be timestamped.
     * This table uses custom timestamp columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'create_time';

    /**
     * The name of the "updated at" column.
     *
     * @var string
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
        'deleted' => 'integer',
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
    ];

    /**
     * Get the user that owns this expense.
     * This is the expense owner/target user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Get the client that this expense belongs to.
     * Provides multi-tenancy scoping for expenses.
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
     * Get the user who created this expense.
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
     * Get all metadata records for this expense.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function metadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'pocket_expense_id', 'id');
    }

    /**
     * Scope a query to only include active (non-deleted) expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('deleted', 0);
    }

    /**
     * Scope a query to only include deleted expenses.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDeleted($query)
    {
        return $query->where('deleted', 1);
    }

    /**
     * Scope a query to filter by client.
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
     * Scope a query to filter by user.
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
     * Scope a query to filter by status.
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
     * Scope a query to filter by currency.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $currency
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Scope a query to filter by date range.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $startDate
     * @param string $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by created by user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCreatedBy($query, int $userId)
    {
        return $query->where('created_by_user_id', $userId);
    }

    /**
     * Check if this expense is active (not deleted).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->deleted === 0;
    }

    /**
     * Check if this expense is deleted.
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
     * Check if this expense can be edited.
     * Only draft and rejected expenses can be edited.
     *
     * @return bool
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'rejected']) && $this->isActive();
    }

    /**
     * Check if this expense can be approved.
     * Only submitted expenses can be approved.
     *
     * @return bool
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'submitted' && $this->isActive();
    }

    /**
     * Check if this expense can be deleted.
     * Only draft and rejected expenses can be deleted.
     *
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        return in_array($this->status, ['draft', 'rejected']) && $this->isActive();
    }

    /**
     * Soft delete this expense by setting deleted flag and timestamp.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        $this->deleted = 1;
        $this->delete_time = now();
        
        return $this->save();
    }

    /**
     * Restore this expense by removing deleted flag and timestamp.
     *
     * @return bool
     */
    public function restore(): bool
    {
        $this->deleted = 0;
        $this->delete_time = null;
        
        return $this->save();
    }
}