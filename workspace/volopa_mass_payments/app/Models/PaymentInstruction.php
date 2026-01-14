## Code: app/Models/PaymentInstruction.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

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
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

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
        'beneficiary_name',
        'beneficiary_account_number',
        'beneficiary_sort_code',
        'beneficiary_iban',
        'beneficiary_swift_code',
        'beneficiary_bank_name',
        'beneficiary_bank_address',
        'beneficiary_address_line1',
        'beneficiary_address_line2',
        'beneficiary_city',
        'beneficiary_state',
        'beneficiary_postal_code',
        'beneficiary_country',
        'beneficiary_email',
        'beneficiary_phone',
        'invoice_number',
        'invoice_date',
        'incorporation_number',
        'beneficiary_type',
        'external_transaction_id',
        'fx_rate',
        'fee_amount',
        'fee_currency',
        'processed_at',
        'processing_notes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'string',
        'mass_payment_file_id' => 'string',
        'beneficiary_id' => 'string',
        'amount' => 'decimal:2',
        'currency' => 'string',
        'purpose_code' => 'string',
        'reference' => 'string',
        'status' => 'string',
        'validation_errors' => 'array',
        'row_number' => 'integer',
        'beneficiary_name' => 'string',
        'beneficiary_account_number' => 'string',
        'beneficiary_sort_code' => 'string',
        'beneficiary_iban' => 'string',
        'beneficiary_swift_code' => 'string',
        'beneficiary_bank_name' => 'string',
        'beneficiary_bank_address' => 'string',
        'beneficiary_address_line1' => 'string',
        'beneficiary_address_line2' => 'string',
        'beneficiary_city' => 'string',
        'beneficiary_state' => 'string',
        'beneficiary_postal_code' => 'string',
        'beneficiary_country' => 'string',
        'beneficiary_email' => 'string',
        'beneficiary_phone' => 'string',
        'invoice_number' => 'string',
        'invoice_date' => 'date',
        'incorporation_number' => 'string',
        'beneficiary_type' => 'string',
        'external_transaction_id' => 'string',
        'fx_rate' => 'decimal:6',
        'fee_amount' => 'decimal:2',
        'fee_currency' => 'string',
        'processed_at' => 'datetime',
        'processing_notes' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
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
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
        'external_transaction_id',
        'processing_notes',
    ];

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid status values
     */
    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_VALIDATED,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Beneficiary type constants
     */
    public const BENEFICIARY_TYPE_INDIVIDUAL = 'individual';
    public const BENEFICIARY_TYPE_BUSINESS = 'business';

    /**
     * Valid beneficiary types
     */
    public const VALID_BENEFICIARY_TYPES = [
        self::BENEFICIARY_TYPE_INDIVIDUAL,
        self::BENEFICIARY_TYPE_BUSINESS,
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Apply client scoping through mass payment file relationship
        static::addGlobalScope('client', function (Builder $query) {
            if (auth()->check() && auth()->user()->client_id) {
                $query->whereHas('massPaymentFile', function (Builder $subQuery) {
                    $subQuery->where('client_id', auth()->user()->client_id);
                });
            }
        });
    }

    /**
     * Get the mass payment file that owns the payment instruction.
     */
    public function massPaymentFile(): BelongsTo
    {
        return $this->belongsTo(MassPaymentFile::class, 'mass_payment_file_id', 'id');
    }

    /**
     * Get the beneficiary for the payment instruction.
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_id', 'id');
    }

    /**
     * Scope a query to filter by mass payment file ID.
     */
    public function scopeForMassPaymentFile(Builder $query, string $massPaymentFileId): Builder
    {
        return $query->where('mass_payment_file_id', $massPaymentFileId);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Scope a query to filter by beneficiary type.
     */
    public function scopeByBeneficiaryType(Builder $query, string $beneficiaryType): Builder
    {
        return $query->where('beneficiary_type', $beneficiaryType);
    }

    /**
     * Scope a query to get validated instructions.
     */
    public function scopeValidated(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATED);
    }

    /**
     * Scope a query to get failed validation instructions.
     */
    public function scopeValidationFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VALIDATION_FAILED);
    }

    /**
     * Scope a query to get pending instructions.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to get processing instructions.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PROCESSING, self::STATUS_PENDING]);
    }

    /**
     * Scope a query to get completed instructions.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    /**
     * Scope a query to get successful instructions.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to get failed instructions.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope a query to order by row number.
     */
    public function scopeOrderByRow(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('row_number', $direction);
    }

    /**
     * Check if the instruction is in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
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
     * Check if the instruction is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the instruction is processing.
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
            self::STATUS_DRAFT,
            self::STATUS_VALIDATED,
            self::STATUS_PENDING,
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
     * Check if the beneficiary is a business.
     */
    public function isBusiness(): bool
    {
        return $this->beneficiary_type === self::BENEFICIARY_TYPE_BUSINESS;
    }

    /**
     * Check if the beneficiary is an individual.
     */
    public function isIndividual(): bool
    {
        return $this->beneficiary_type === self::BENEFICIARY_TYPE_INDIVIDUAL;
    }

    /**
     * Check if currency-specific fields are required.
     */
    public function requiresInvoiceDetails(): bool
    {
        return strtoupper($this->currency) === 'INR';
    }

    /**
     * Check if incorporation number is required.
     */
    public function requiresIncorporationNumber(): bool
    {
        return strtoupper($this->currency) === 'TRY' && $this->isBusiness();
    }

    /**
     * Get the validation error count.
     */
    public function getValidationErrorCount(): int
    {
        return is_array($this->validation_errors) ? count($this->validation_errors) : 0;
    }

    /**
     * Get the formatted amount with currency.
     */
    public function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get the formatted beneficiary address.
     */
    public function getFormattedBeneficiaryAddress(): string
    {
        $addressParts = array_filter([
            $this->beneficiary_address_line1,
            $this->beneficiary_address_line2,
            $this->beneficiary_city,
            $this->beneficiary_state,
            $this->beneficiary_postal_code,
            $this->beneficiary_country,
        ]);

        return implode(', ', $addressParts);
    }

    /**
     * Get the formatted bank details.
     */
    public function getFormattedBankDetails(): string
    {
        $bankParts = array_filter([
            $this->beneficiary_bank_name,
            $this->beneficiary_swift_code,
            $this->beneficiary_bank_address,
        ]);

        return implode(', ', $bankParts);
    }

    /**
     * Get the account identifier (IBAN or account number).
     */
    public function getAccountIdentifier(): string
    {
        return $this->beneficiary_iban ?: ($this->beneficiary_account_number ?: 'N/A');
    }

    /**
     * Update the instruction status.
     */
    public function updateStatus(string $status, ?array $validationErrors = null): bool
    {
        if (!in_array($status, self::VALID_STATUSES)) {
            return false;
        }

        $this->status = $status;
        
        if ($validationErrors !== null) {
            $this->validation_errors = $validationErrors;
        }

        return $this->save();
    }

    /**
     * Mark the instruction as validated.
     */
    public function markAsValidated(): bool
    {
        $this->status = self::STATUS_VALIDATED;
        $this->validation_errors = null;

        return $this->save();
    }

    /**
     * Mark the instruction as validation failed.
     */
    public function markAsValidationFailed(array $errors): bool
    {
        $this->status = self::STATUS_VALIDATION_FAILED;
        $this->validation_errors = $errors;

        return $this->save();
    }

    /**
     * Mark the instruction as processing.
     */
    public function markAsProcessing(string $externalTransactionId = null): bool
    {
        $this->status = self::STATUS_PROCESSING;
        
        if ($externalTransactionId) {
            $this->external_transaction_id = $externalTransactionId;
        }

        return $this->save();
    }

    /**
     * Mark the instruction as completed.
     */
    public