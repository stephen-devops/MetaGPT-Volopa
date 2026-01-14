## Code: app/Models/Beneficiary.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Beneficiary extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'beneficiaries';

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
        'client_id',
        'name',
        'type',
        'email',
        'phone',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'bank_name',
        'bank_address',
        'account_number',
        'sort_code',
        'iban',
        'swift_code',
        'routing_number',
        'ifsc_code',
        'status',
        'verification_status',
        'incorporation_number',
        'tax_id',
        'date_of_birth',
        'nationality',
        'supported_currencies',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'string',
        'name' => 'string',
        'type' => 'string',
        'email' => 'string',
        'phone' => 'string',
        'address_line1' => 'string',
        'address_line2' => 'string',
        'city' => 'string',
        'state' => 'string',
        'postal_code' => 'string',
        'country' => 'string',
        'bank_name' => 'string',
        'bank_address' => 'string',
        'account_number' => 'string',
        'sort_code' => 'string',
        'iban' => 'string',
        'swift_code' => 'string',
        'routing_number' => 'string',
        'ifsc_code' => 'string',
        'status' => 'string',
        'verification_status' => 'string',
        'incorporation_number' => 'string',
        'tax_id' => 'string',
        'date_of_birth' => 'date',
        'nationality' => 'string',
        'supported_currencies' => 'array',
        'metadata' => 'array',
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
        'tax_id',
        'incorporation_number',
        'date_of_birth',
    ];

    /**
     * Beneficiary type constants
     */
    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_BUSINESS = 'business';

    /**
     * Valid beneficiary types
     */
    public const VALID_TYPES = [
        self::TYPE_INDIVIDUAL,
        self::TYPE_BUSINESS,
    ];

    /**
     * Status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Valid status values
     */
    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
    ];

    /**
     * Verification status constants
     */
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_FAILED = 'failed';
    public const VERIFICATION_NOT_REQUIRED = 'not_required';

    /**
     * Valid verification status values
     */
    public const VALID_VERIFICATION_STATUSES = [
        self::VERIFICATION_PENDING,
        self::VERIFICATION_VERIFIED,
        self::VERIFICATION_FAILED,
        self::VERIFICATION_NOT_REQUIRED,
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Apply client scoping globally
        static::addGlobalScope('client', function (Builder $query) {
            if (auth()->check() && auth()->user()->client_id) {
                $query->where('client_id', auth()->user()->client_id);
            }
        });

        // Set default values on creating
        static::creating(function ($beneficiary) {
            if (!$beneficiary->status) {
                $beneficiary->status = self::STATUS_ACTIVE;
            }
            if (!$beneficiary->verification_status) {
                $beneficiary->verification_status = self::VERIFICATION_PENDING;
            }
            if (!$beneficiary->type) {
                $beneficiary->type = self::TYPE_INDIVIDUAL;
            }
            if (!$beneficiary->supported_currencies) {
                $beneficiary->supported_currencies = [];
            }
            if (!$beneficiary->metadata) {
                $beneficiary->metadata = [];
            }
        });
    }

    /**
     * Get the client that owns the beneficiary.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the payment instructions for the beneficiary.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class, 'beneficiary_id', 'id');
    }

    /**
     * Scope a query to filter by client ID.
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by beneficiary type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by verification status.
     */
    public function scopeByVerificationStatus(Builder $query, string $verificationStatus): Builder
    {
        return $query->where('verification_status', $verificationStatus);
    }

    /**
     * Scope a query to filter by country.
     */
    public function scopeByCountry(Builder $query, string $country): Builder
    {
        return $query->where('country', strtoupper($country));
    }

    /**
     * Scope a query to filter by supported currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->whereJsonContains('supported_currencies', strtoupper($currency));
    }

    /**
     * Scope a query to get active beneficiaries.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope a query to get verified beneficiaries.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', self::VERIFICATION_VERIFIED);
    }

    /**
     * Scope a query to get business beneficiaries.
     */
    public function scopeBusiness(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_BUSINESS);
    }

    /**
     * Scope a query to get individual beneficiaries.
     */
    public function scopeIndividual(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_INDIVIDUAL);
    }

    /**
     * Check if the beneficiary is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the beneficiary is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /**
     * Check if the beneficiary is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Check if the beneficiary is verified.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    /**
     * Check if the beneficiary verification is pending.
     */
    public function isVerificationPending(): bool
    {
        return $this->verification_status === self::VERIFICATION_PENDING;
    }

    /**
     * Check if the beneficiary verification failed.
     */
    public function hasVerificationFailed(): bool
    {
        return $this->verification_status === self::VERIFICATION_FAILED;
    }

    /**
     * Check if the beneficiary is a business.
     */
    public function isBusiness(): bool
    {
        return $this->type === self::TYPE_BUSINESS;
    }

    /**
     * Check if the beneficiary is an individual.
     */
    public function isIndividual(): bool
    {
        return $this->type === self::TYPE_INDIVIDUAL;
    }

    /**
     * Check if the beneficiary can receive payments.
     */
    public function canReceivePayments(): bool
    {
        return $this->isActive() && ($this->isVerified() || $this->verification_status === self::VERIFICATION_NOT_REQUIRED);
    }

    /**
     * Check if the beneficiary supports a specific currency.
     */
    public function supportsCurrency(string $currency): bool
    {
        $supportedCurrencies = $this->supported_currencies ?? [];
        return in_array(strtoupper($currency), $supportedCurrencies);
    }

    /**
     * Check if the beneficiary requires incorporation number.
     */
    public function requiresIncorporationNumber(): bool
    {
        return $this->isBusiness() && in_array('TRY', $this->supported_currencies ?? []);
    }

    /**
     * Add a supported currency.
     */
    public function addSupportedCurrency(string $currency): bool
    {
        $currencies = $this->supported_currencies ?? [];
        $currency = strtoupper($currency);

        if (!in_array($currency, $currencies)) {
            $currencies[] = $currency;
            $this->supported_currencies = $currencies;
            return $this->save();
        }

        return true;
    }

    /**
     * Remove a supported currency.
     */
    public function removeSupportedCurrency(string $currency): bool
    {
        $currencies = $this->supported_currencies ?? [];
        $currency = strtoupper($currency);

        $index = array_search($currency, $currencies);
        if ($index !== false) {
            unset($currencies[$index]);
            $this->supported_currencies = array_values($currencies);
            return $this->save();
        }

        return true;
    }

    /**
     * Get the formatted address.
     */
    public function getFormattedAddress(): string
    {
        $addressParts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return implode(', ', $addressParts);
    }

    /**
     * Get the formatted bank details.
     */
    public function getFormattedBankDetails(): string
    {
        $bankParts = array_filter([
            $this->bank_name,
            $this->swift_code,
            $this->bank_address,
        ]);

        return implode(', ', $bankParts);
    }

    /**
     * Get the account identifier (IBAN or account number).
     */
    public function getAccountIdentifier(): string
    {
        return $this->iban ?: ($this->account_number ?: 'N/A');
    }

    /**
     * Get the full name with type indicator.
     */
    public function getDisplayName(): string
    {
        $typeIndicator = $this->isBusiness() ? ' (Business)' : ' (Individual)';
        return $this->name . $typeIndicator;
    }

    /**
     * Update the beneficiary status.
     */
    public function updateStatus(string $status): bool
    {
        if (!in_array($status, self::VALID_STATUSES)) {
            return false;
        }

        $this->status = $status;
        return $this->save();
    }

    /**
     * Update the verification status.
     */
    public function updateVerificationStatus(string $verificationStatus): bool
    {
        if (!in_array($verificationStatus, self::VALID_VERIFICATION_STATUSES)) {
            return false;
        }

        $this->verification_status = $verificationStatus;
        return $this->save();
    }

    /**
     * Activate the beneficiary.
     */
    public function activate(): bool
    {
        return $this->updateStatus(self::STATUS_ACTIVE);
    }

    /**
     * Deactivate the beneficiary.
     */
    public function deactivate(): bool
    {
        return $this->updateStatus(self::STATUS_INACTIVE);
    }

    /**
     * Suspend the beneficiary.
     */
    public function suspend(): bool
    {
        return $this->updateStatus(self::STATUS_SUSPENDED);
    }

    /**
     * Mark the beneficiary as verified.
     */
    public function markAsVerified(): bool
    {
        return $this->updateVerificationStatus(self::VERIFICATION_VERIFIED);
    }

    /**
     * Mark the beneficiary verification as failed.
     */
    public function markVerificationAsFailed(): bool
    {
        return $this->updateVerificationStatus(self::VERIFICATION_FAILED);
    }

    /**
     * Set verification as not required.
     */
    public function setVerificationNotRequired(): bool
    {
        return $this->updateVerificationStatus(self::VERIFICATION_NOT_REQUIRED);
    }

    /**
     * Update metadata.
     */
    public function updateMetadata(array $metadata): bool
    {
        $this->metadata = array_merge($this->metadata ?? [], $metadata);
        return $this->save();
    }

    /**
     * Get metadata value by key.
     */
    public function getMetadata(string $key, $default = null)
    {
        return ($this->metadata ?? [])[$key] ?? $default;
    }

    /**
     * Set metadata value by key.
     */
    public function setMetadata(string $key, $value): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        