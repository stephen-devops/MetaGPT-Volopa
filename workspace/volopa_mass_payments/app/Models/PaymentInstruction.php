<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class PaymentInstruction extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'payment_instructions';

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
        'mass_payment_file_id',
        'beneficiary_id',
        'amount',
        'currency',
        'purpose_code',
        'reference',
        'status',
        'validation_errors',
        'row_number',
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
        'mass_payment_file_id' => 'string',
        'beneficiary_id' => 'integer',
        'amount' => 'decimal:2',
        'row_number' => 'integer',
        'validation_errors' => 'array',
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
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Get all available status values
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_VALIDATED,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Get the mass payment file that owns the payment instruction.
     */
    public function massPaymentFile(): BelongsTo
    {
        return $this->belongsTo(MassPaymentFile::class, 'mass_payment_file_id');
    }

    /**
     * Get the beneficiary associated with the payment instruction.
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id');
    }

    /**
     * Scope to filter by mass payment file ID.
     */
    public function scopeForFile(Builder $query, string $fileId): Builder
    {
        return $query->where('mass_payment_file_id', $fileId);
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
     * Scope to filter by beneficiary ID.
     */
    public function scopeForBeneficiary(Builder $query, int $beneficiaryId): Builder
    {
        return $query->where('beneficiary_id', $beneficiaryId);
    }

    /**
     * Scope to get pending instructions.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get validated instructions.
     */
    public function scopeValidated(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    /**
     * Scope to get failed validation instructions.
     */
    public function scopeValidationFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope to get processing instructions.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope to get completed instructions.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get failed instructions.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to get cancelled instructions.
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope to get instructions with validation errors.
     */
    public function scopeWithValidationErrors(Builder $query): Builder
    {
        return $query->whereNotNull('validation_errors');
    }

    /**
     * Scope to order by row number.
     */
    public function scopeOrderByRowNumber(Builder $query): Builder
    {
        return $query->orderBy('row_number');
    }

    /**
     * Scope for client scoping through mass payment file relationship.
     */
    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->whereHas('massPaymentFile', function ($q) use ($clientId) {
            $q->where('client_id', $clientId);
        });
    }

    /**
     * Check if the instruction is in pending status.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the instruction is validated.
     */
    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    /**
     * Check if the instruction validation failed.
     */
    public function hasValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the instruction is currently processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the instruction is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the instruction has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the instruction is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the instruction can be processed.
     */
    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    /**
     * Check if the instruction can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_VALIDATED,
            self::STATUS_VALIDATION_FAILED,
        ]);
    }

    /**
     * Check if the instruction has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    /**
     * Mark the instruction as validated.
     */
    public function markAsValidated(): void
    {
        $this->update([
            'status' => self::STATUS_VALIDATED,
            'validation_errors' => null,
        ]);
    }

    /**
     * Mark the instruction as validation failed with errors.
     */
    public function markAsValidationFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_VALIDATION_FAILED,
            'validation_errors' => $errors,
        ]);
    }

    /**
     * Mark the instruction as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark the instruction as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
    }

    /**
     * Mark the instruction as failed with optional errors.
     */
    public function markAsFailed(array $errors = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'validation_errors' => $errors,
        ]);
    }

    /**
     * Mark the instruction as cancelled.
     */
    public function markAsCancelled(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Get formatted amount with currency.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2) . ' ' . $this->currency;
    }

    /**
     * Get validation error count.
     */
    public function getValidationErrorCountAttribute(): int
    {
        return is_array($this->validation_errors) ? count($this->validation_errors) : 0;
    }

    /**
     * Get the first validation error message.
     */
    public function getFirstValidationErrorAttribute(): ?string
    {
        if (is_array($this->validation_errors) && !empty($this->validation_errors)) {
            return $this->validation_errors[0] ?? null;
        }
        
        return null;
    }

    /**
     * Get display-friendly status name.
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_VALIDATED => 'Validated',
            self::STATUS_VALIDATION_FAILED => 'Validation Failed',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }

    /**
     * Check if instruction is in final state.
     */
    public function isInFinalState(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Check if instruction is processable (not in final state or validation failed).
     */
    public function isProcessable(): bool
    {
        return !$this->isInFinalState() && !$this->hasValidationFailed();
    }

    /**
     * Scope to get instructions in final states.
     */
    public function scopeInFinalState(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Scope to get processable instructions.
     */
    public function scopeProcessable(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_VALIDATION_FAILED,
        ]);
    }

    /**
     * Boot the model and apply global scopes.
     */
    protected static function booted(): void
    {
        // Apply client scoping globally for multi-tenant architecture
        static::addGlobalScope('client', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->whereHas('massPaymentFile', function ($q) {
                    $q->where('client_id', auth()->user()->client_id);
                });
            }
        });
    }
}