## Code: app/Models/Beneficiary.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class Beneficiary extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'beneficiaries';

    protected $fillable = [
        'client_id',
        'name',
        'account_number',
        'sort_code',
        'currency',
        'bank_name',
        'bank_code',
        'iban',
        'swift_code',
        'country_code',
        'address',
        'city',
        'postal_code',
        'state',
        'phone',
        'email',
        'status',
        'reference',
        'additional_data',
    ];

    protected $casts = [
        'additional_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'client_id' => 'integer',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('client', function (Builder $query) {
            if (Auth::check() && Auth::user()->client_id) {
                $query->where('client_id', Auth::user()->client_id);
            }
        });
    }

    /**
     * Get the payment instructions for the beneficiary.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class, 'beneficiary_id');
    }

    /**
     * Scope a query to only include active beneficiaries.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include inactive beneficiaries.
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', 'inactive');
    }

    /**
     * Scope a query to only include beneficiaries with a specific currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include beneficiaries with a specific country code.
     */
    public function scopeByCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope a query to only include beneficiaries with a specific bank.
     */
    public function scopeByBank(Builder $query, string $bankCode): Builder
    {
        return $query->where('bank_code', $bankCode);
    }

    /**
     * Scope a query to search beneficiaries by name.
     */
    public function scopeSearchByName(Builder $query, string $name): Builder
    {
        return $query->where('name', 'like', '%' . $name . '%');
    }

    /**
     * Scope a query to search beneficiaries by account number.
     */
    public function scopeByAccountNumber(Builder $query, string $accountNumber): Builder
    {
        return $query->where('account_number', $accountNumber);
    }

    /**
     * Scope a query to search beneficiaries by IBAN.
     */
    public function scopeByIban(Builder $query, string $iban): Builder
    {
        return $query->where('iban', $iban);
    }

    /**
     * Scope a query to search beneficiaries by SWIFT code.
     */
    public function scopeBySwiftCode(Builder $query, string $swiftCode): Builder
    {
        return $query->where('swift_code', $swiftCode);
    }

    /**
     * Scope a query to search beneficiaries by sort code.
     */
    public function scopeBySortCode(Builder $query, string $sortCode): Builder
    {
        return $query->where('sort_code', $sortCode);
    }

    /**
     * Scope a query to search beneficiaries by email.
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', $email);
    }

    /**
     * Scope a query to search beneficiaries by reference.
     */
    public function scopeByReference(Builder $query, string $reference): Builder
    {
        return $query->where('reference', $reference);
    }

    /**
     * Check if the beneficiary is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the beneficiary is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    /**
     * Activate the beneficiary.
     */
    public function activate(): bool
    {
        return $this->update(['status' => 'active']);
    }

    /**
     * Deactivate the beneficiary.
     */
    public function deactivate(): bool
    {
        return $this->update(['status' => 'inactive']);
    }

    /**
     * Get the full name with account number for display.
     */
    public function getDisplayName(): string
    {
        return $this->name . ' (' . $this->account_number . ')';
    }

    /**
     * Get the formatted account number with sort code.
     */
    public function getFormattedAccountNumber(): string
    {
        if ($this->sort_code) {
            return $this->sort_code . '-' . $this->account_number;
        }

        return $this->account_number;
    }

    /**
     * Get the full address as a formatted string.
     */
    public function getFullAddress(): string
    {
        $addressParts = array_filter([
            $this->address,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $addressParts);
    }

    /**
     * Get bank details as an array.
     */
    public function getBankDetails(): array
    {
        return [
            'bank_name' => $this->bank_name,
            'bank_code' => $this->bank_code,
            'swift_code' => $this->swift_code,
            'iban' => $this->iban,
            'sort_code' => $this->sort_code,
            'account_number' => $this->account_number,
        ];
    }

    /**
     * Check if beneficiary has IBAN.
     */
    public function hasIban(): bool
    {
        return !empty($this->iban);
    }

    /**
     * Check if beneficiary has SWIFT code.
     */
    public function hasSwiftCode(): bool
    {
        return !empty($this->swift_code);
    }

    /**
     * Check if beneficiary has sort code.
     */
    public function hasSortCode(): bool
    {
        return !empty($this->sort_code);
    }

    /**
     * Check if beneficiary supports a specific currency.
     */
    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($this->currency) === strtoupper($currency);
    }

    /**
     * Get additional data value by key.
     */
    public function getAdditionalData(string $key, mixed $default = null): mixed
    {
        if (!is_array($this->additional_data)) {
            return $default;
        }

        return $this->additional_data[$key] ?? $default;
    }

    /**
     * Set additional data value by key.
     */
    public function setAdditionalData(string $key, mixed $value): bool
    {
        $data = is_array($this->additional_data) ? $this->additional_data : [];
        $data[$key] = $value;

        return $this->update(['additional_data' => $data]);
    }

    /**
     * Get the total number of payment instructions for this beneficiary.
     */
    public function getPaymentInstructionsCount(): int
    {
        return $this->paymentInstructions()->count();
    }

    /**
     * Get the total amount of all payment instructions for this beneficiary.
     */
    public function getTotalPaymentAmount(): float
    {
        return (float) $this->paymentInstructions()
                           ->where('status', '!=', 'failed_validation')
                           ->sum('amount');
    }

    /**
     * Get the last payment instruction date for this beneficiary.
     */
    public function getLastPaymentDate(): ?string
    {
        $lastPayment = $this->paymentInstructions()
                           ->latest('created_at')
                           ->first();

        return $lastPayment ? $lastPayment->created_at->format('Y-m-d H:i:s') : null;
    }

    /**
     * Check if beneficiary has any pending payments.
     */
    public function hasPendingPayments(): bool
    {
        return $this->paymentInstructions()
                   ->whereIn('status', ['pending', 'validated', 'processing'])
                   ->exists();
    }

    /**
     * Check if beneficiary has any completed payments.
     */
    public function hasCompletedPayments(): bool
    {
        return $this->paymentInstructions()
                   ->where('status', 'completed')
                   ->exists();
    }

    /**
     * Get payment instructions by status.
     */
    public function getPaymentInstructionsByStatus(string $status): int
    {
        return $this->paymentInstructions()
                   ->where('status', $status)
                   ->count();
    }

    /**
     * Validate beneficiary account details based on currency.
     */
    public function validateAccountDetails(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'Beneficiary name is required';
        }

        if (empty($this->account_number)) {
            $errors[] = 'Account number is required';
        }

        if (empty($this->currency)) {
            $errors[] = 'Currency is required';
        }

        // Currency-specific validations
        switch (strtoupper($this->currency)) {
            case 'GBP':
                if (empty($this->sort_code)) {
                    $errors[] = 'Sort code is required for GBP payments';
                }
                if ($this->sort_code && !preg_match('/^\d{6}$/', $this->sort_code)) {
                    $errors[] = 'Invalid sort code format for GBP payments';
                }
                if ($this->account_number && !preg_match('/^\d{8}$/', $this->account_number)) {
                    $errors[] = 'Invalid account number format for GBP payments';
                }
                break;

            case 'EUR':
                if (empty($this->iban)) {
                    $errors[] = 'IBAN is required for EUR payments';
                }
                if ($this->iban && !$this->validateIban($this->iban)) {
                    $errors[] = 'Invalid IBAN format for EUR payments';
                }
                if (empty($this->swift_code)) {
                    $errors[] = 'SWIFT code is required for EUR payments';
                }
                break;

            case 'USD':
                if (empty($this->swift_code)) {
                    $errors[] = 'SWIFT code is required for USD payments';
                }
                if (empty($this->bank_code)) {
                    $errors[] = 'Bank code is required for USD payments';
                }
                break;

            case 'INR':
                if (empty($this->swift_code)) {
                    $errors[] = 'SWIFT code is required for INR payments';
                }
                if ($this->account_number && !preg_match('/^\d{9,18}$/', $this->account_number)) {
                    $errors[] = 'Invalid account number format for INR payments';
                }
                break;
        }

        if ($this->swift_code && !preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $this->swift_code)) {
            $errors[] = 'Invalid SWIFT code format';
        }

        return $errors;
    }

    /**
     * Validate IBAN format.
     */
    private function validateIban(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/[\s\-]/', '', $iban));
        
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        // Move first 4 characters to end
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        
        // Convert letters to numbers
        $numeric = '';
        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            if (ctype_alpha($char)) {
                $numeric .= (ord($char) - ord('A') + 10);
            } else {
                $numeric .= $char;
            }
        }

        // Check mod 97
        return bcmod($numeric, '97') === '1';
    }

    /**
     * Get beneficiary summary for reporting.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_number' => $this->account_number,
            'sort_code' => $this->sort_code,
            'iban' => $this->iban,
            'swift_code' => $this->swift_code,
            'currency' => $this->currency,
            'bank_name' => $this->bank_name,
            'country_code' => $this->country_code,
            'status' => $this->status,
            'total_payments' => $this->getPaymentInstructionsCount(),
            'total_amount' => $this->getTotalPaymentAmount(),
            'last_payment_date' => $this->getLastPaymentDate(),
            'has_pending_payments' => $this->hasPendingPayments(),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Search beneficiaries by multiple criteria.
     */
    public static function search(array $criteria): Builder
    {
        $query = static::query();

        if (!empty($criteria['name'])) {
            $query->searchByName($criteria['name']);
        }

        if (!empty($criteria['currency'])) {
            $query->byCurrency($criteria['currency']);
        }

        if (!empty($criteria['country_code'])) {
            $query->byCountry($criteria['country_code']);
        }

        if (!empty($criteria['account_number'])) {
            $query->byAccountNumber($criteria['account_number']);
        }

        if (!empty($criteria['iban'])) {
            $query->byIban($criteria['iban']);
        }

        if (!empty($criteria['swift_code'])) {
            $query->bySwiftCode($criteria