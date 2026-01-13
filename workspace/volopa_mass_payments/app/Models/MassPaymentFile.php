<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class MassPaymentFile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'mass_payment_files';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'tcc_account_id',
        'filename',
        'original_filename',
        'file_size',
        'total_amount',
        'currency',
        'status',
        'validation_errors',
        'uploaded_by',
        'approved_by',
        'approved_at',
    ];

    /**
     * The attributes that should be guarded from mass assignment.
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'integer',
        'tcc_account_id' => 'integer',
        'file_size' => 'integer',
        'total_amount' => 'decimal:2',
        'validation_errors' => 'array',
        'uploaded_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
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
     * Status enum constants
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get all available status values
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_VALIDATING,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_AWAITING_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Get the client that owns the mass payment file.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the TCC account associated with the mass payment file.
     */
    public function tccAccount(): BelongsTo
    {
        return $this->belongsTo(TccAccount::class, 'tcc_account_id');
    }

    /**
     * Get the user who uploaded the file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the user who approved the file.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get all payment instructions for this mass payment file.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class, 'mass_payment_file_id');
    }

    /**
     * Scope to filter by client ID (multi-tenant architecture).
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope to get files pending approval.
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_APPROVAL);
    }

    /**
     * Scope to get approved files.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Check if the file is in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if the file is currently being validated.
     */
    public function isValidating(): bool
    {
        return $this->status === self::STATUS_VALIDATING;
    }

    /**
     * Check if the file validation failed.
     */
    public function hasValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the file is awaiting approval.
     */
    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    /**
     * Check if the file is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the file is currently processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the file processing is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the file processing failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the file can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    /**
     * Check if the file can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_FAILED,
        ]);
    }

    /**
     * Mark the file as validating.
     */
    public function markAsValidating(): void
    {
        $this->update(['status' => self::STATUS_VALIDATING]);
    }

    /**
     * Mark the file as validation failed with errors.
     */
    public function markAsValidationFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_VALIDATION_FAILED,
            'validation_errors' => $errors,
        ]);
    }

    /**
     * Mark the file as awaiting approval.
     */
    public function markAsAwaitingApproval(): void
    {
        $this->update([
            'status' => self::STATUS_AWAITING_APPROVAL,
            'validation_errors' => null,
        ]);
    }

    /**
     * Mark the file as approved by a specific user.
     */
    public function markAsApproved(int $approverId): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    /**
     * Mark the file as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark the file as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    /**
     * Mark the file as failed with optional errors.
     */
    public function markAsFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'validation_errors' => $errors,
        ]);
    }

    /**
     * Get the count of payment instructions.
     */
    public function getPaymentInstructionsCountAttribute(): int
    {
        return $this->paymentInstructions()->count();
    }

    /**
     * Get the count of successful payment instructions.
     */
    public function getSuccessfulPaymentsCountAttribute(): int
    {
        return $this->paymentInstructions()
                    ->where('status', PaymentInstruction::STATUS_COMPLETED)
                    ->count();
    }

    /**
     * Get the count of failed payment instructions.
     */
    public function getFailedPaymentsCountAttribute(): int
    {
        return $this->paymentInstructions()
                    ->where('status', PaymentInstruction::STATUS_FAILED)
                    ->count();
    }

    /**
     * Get processing progress as percentage.
     */
    public function getProgressPercentageAttribute(): float
    {
        $total = $this->getPaymentInstructionsCountAttribute();
        
        if ($total === 0) {
            return 0.0;
        }

        $completed = $this->paymentInstructions()
                          ->whereIn('status', [
                              PaymentInstruction::STATUS_COMPLETED,
                              PaymentInstruction::STATUS_FAILED,
                          ])
                          ->count();

        return round(($completed / $total) * 100, 2);
    }

    /**
     * Boot the model and apply global scopes.
     */
    protected static function booted(): void
    {
        // Apply client scoping globally for multi-tenant architecture
        static::addGlobalScope('client', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });
    }
}