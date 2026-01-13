## Code: app/Http/Resources/BeneficiaryResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class BeneficiaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'account_number' => $this->when(
                $this->canViewSensitiveData($request),
                $this->account_number,
                $this->maskAccountNumber($this->account_number ?? '')
            ),
            'account_number_masked' => $this->maskAccountNumber($this->account_number ?? ''),
            'bank_code' => $this->bank_code,
            'bank_name' => $this->getBankName($this->bank_code),
            'country' => $this->country,
            'country_name' => $this->getCountryName($this->country),
            'address' => $this->when(
                $this->canViewSensitiveData($request),
                $this->address
            ),
            'city' => $this->city,
            'postal_code' => $this->when(
                $this->canViewSensitiveData($request),
                $this->postal_code
            ),
            'phone' => $this->when(
                $this->canViewSensitiveData($request),
                $this->phone
            ),
            'email' => $this->when(
                $this->canViewSensitiveData($request),
                $this->email
            ),
            'swift_code' => $this->swift_code,
            'iban' => $this->when(
                $this->canViewSensitiveData($request),
                $this->iban,
                $this->maskIban($this->iban ?? '')
            ),
            'sort_code' => $this->sort_code,
            'bsb' => $this->bsb,
            'routing_number' => $this->when(
                $this->canViewSensitiveData($request),
                $this->routing_number
            ),
            'intermediary_bank' => $this->intermediary_bank,
            'intermediary_swift' => $this->intermediary_swift,
            'is_active' => $this->is_active ?? true,
            'is_verified' => $this->is_verified ?? false,
            'verification_status' => $this->getVerificationStatus(),
            'risk_level' => $this->getRiskLevel(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'last_payment_at' => $this->last_payment_at?->toISOString(),

            // Status flags
            'can_receive_payments' => $this->canReceivePayments(),
            'requires_compliance_check' => $this->requiresComplianceCheck(),
            'has_sensitive_data' => $this->hasSensitiveData(),
            'is_high_risk' => $this->isHighRisk(),

            // Payment statistics - conditionally loaded
            'payment_stats' => $this->when(
                $this->shouldIncludePaymentStats($request),
                $this->getPaymentStatistics()
            ),

            // Related data - conditionally loaded
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                    'code' => $this->client->code ?? null,
                ];
            }),

            'payment_instructions' => PaymentInstructionResource::collection(
                $this->whenLoaded('paymentInstructions')
            ),

            'recent_payments' => $this->when(
                $this->relationLoaded('paymentInstructions') && $this->shouldIncludeRecentPayments($request),
                function () {
                    return PaymentInstructionResource::collection(
                        $this->paymentInstructions()
                            ->with(['massPaymentFile'])
                            ->orderBy('created_at', 'desc')
                            ->limit(5)
                            ->get()
                    );
                }
            ),

            // Banking information grouped
            'banking_info' => [
                'primary_account' => [
                    'account_number' => $this->when(
                        $this->canViewSensitiveData($request),
                        $this->account_number,
                        $this->maskAccountNumber($this->account_number ?? '')
                    ),
                    'bank_code' => $this->bank_code,
                    'bank_name' => $this->getBankName($this->bank_code),
                    'swift_code' => $this->swift_code,
                    'iban' => $this->when(
                        $this->canViewSensitiveData($request),
                        $this->iban,
                        $this->maskIban($this->iban ?? '')
                    ),
                    'sort_code' => $this->sort_code,
                    'bsb' => $this->bsb,
                    'routing_number' => $this->when(
                        $this->canViewSensitiveData($request),
                        $this->routing_number
                    ),
                ],
                'intermediary_bank' => $this->when(
                    !empty($this->intermediary_bank) || !empty($this->intermediary_swift),
                    [
                        'name' => $this->intermediary_bank,
                        'swift_code' => $this->intermediary_swift,
                    ]
                ),
                'supported_currencies' => $this->getSupportedCurrencies(),
                'payment_methods' => $this->getAvailablePaymentMethods(),
            ],

            // Contact information grouped
            'contact_info' => [
                'address' => $this->when(
                    $this->canViewSensitiveData($request),
                    [
                        'address_line' => $this->address,
                        'city' => $this->city,
                        'postal_code' => $this->postal_code,
                        'country' => $this->country,
                        'country_name' => $this->getCountryName($this->country),
                        'formatted_address' => $this->getFormattedAddress(),
                    ]
                ),
                'phone' => $this->when(
                    $this->canViewSensitiveData($request),
                    $this->phone
                ),
                'email' => $this->when(
                    $this->canViewSensitiveData($request),
                    $this->email
                ),
            ],

            // Compliance and risk information
            'compliance_info' => [
                'verification_status' => $this->getVerificationStatus(),
                'risk_level' => $this->getRiskLevel(),
                'compliance_checked_at' => $this->compliance_checked_at?->toISOString(),
                'sanctions_checked_at' => $this->sanctions_checked_at?->toISOString(),
                'aml_status' => $this->aml_status ?? 'pending',
                'requires_compliance_check' => $this->requiresComplianceCheck(),
                'compliance_notes' => $this->when(
                    $this->canViewComplianceData($request),
                    $this->compliance_notes
                ),
            ],

            // Additional metadata
            'metadata' => [
                'days_since_creation' => $this->created_at->diffInDays(now()),
                'days_since_last_update' => $this->updated_at->diffInDays(now()),
                'days_since_last_payment' => $this->last_payment_at 
                    ? $this->last_payment_at->diffInDays(now()) 
                    : null,
                'total_payments_count' => $this->getTotalPaymentsCount(),
                'total_payments_amount' => $this->getTotalPaymentsAmount(),
                'favorite_currencies' => $this->getFavoriteCurrencies(),
                'preferred_payment_purposes' => $this->getPreferredPaymentPurposes(),
            ],

            // Action URLs - conditionally included
            'actions' => $this->when(
                $this->shouldIncludeActions($request),
                $this->getAvailableActions($request)
            ),

            // Links for HATEOAS compliance
            'links' => [
                'self' => route('api.v1.beneficiaries.show', $this->id),
                'payment_instructions' => route('api.v1.payment-instructions.index', ['beneficiary_id' => $this->id]),
                'client' => route('api.v1.clients.show', $this->client_id),
            ],
        ];
    }

    /**
     * Check if user can view sensitive data
     *
     * @param Request $request
     * @return bool
     */
    protected function canViewSensitiveData(Request $request): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // User can view sensitive data for beneficiaries in their own client
        if (method_exists($user, 'client_id') && isset($user->client_id)) {
            if ($user->client_id !== $this->client_id) {
                return false;
            }
        }

        // Check specific permission if available
        if (method_exists($user, 'can')) {
            return $user->can('viewSensitiveData', $this->resource);
        }

        return true;
    }

    /**
     * Check if user can view compliance data
     *
     * @param Request $request
     * @return bool
     */
    protected function canViewComplianceData(Request $request): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Same client access required
        if (method_exists($user, 'client_id') && isset($user->client_id)) {
            if ($user->client_id !== $this->client_id) {
                return false;
            }
        }

        // Check specific permission for compliance data
        if (method_exists($user, 'can')) {
            return $user->can('viewComplianceData', $this->resource);
        }

        // Default to false for compliance data
        return false;
    }

    /**
     * Mask account number for security
     *
     * @param string $accountNumber
     * @return string
     */
    protected function maskAccountNumber(string $accountNumber): string
    {
        if (empty($accountNumber) || strlen($accountNumber) <= 4) {
            return $accountNumber;
        }

        return '****' . substr($accountNumber, -4);
    }

    /**
     * Mask IBAN for security
     *
     * @param string $iban
     * @return string
     */
    protected function maskIban(string $iban): string
    {
        if (empty($iban) || strlen($iban) <= 8) {
            return $iban;
        }

        $countryCode = substr($iban, 0, 2);
        $checkDigits = substr($iban, 2, 2);
        $lastFour = substr($iban, -4);
        $middleLength = strlen($iban) - 8;

        return $countryCode . $checkDigits . str_repeat('*', $middleLength) . $lastFour;
    }

    /**
     * Get bank name from bank code
     *
     * @param string|null $bankCode
     * @return string|null
     */
    protected function getBankName(?string $bankCode): ?string
    {
        if (!$bankCode) {
            return null;
        }

        // This would typically look up bank names from a configuration or database
        $bankNames = config('mass-payments.bank_codes', []);
        return $bankNames[$bankCode] ?? null;
    }

    /**
     * Get country name from country code
     *
     * @param string|null $countryCode
     * @return string|null
     */
    protected function getCountryName(?string $countryCode): ?string
    {
        if (!$countryCode) {
            return null;
        }

        $countries = config('mass-payments.countries', [
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'DE' => 'Germany',
            'FR' => 'France',
            'AU' => 'Australia',
            'CA' => 'Canada',
            'SG' => 'Singapore',
            'HK' => 'Hong Kong',
            'JP' => 'Japan',
            'CH' => 'Switzerland',
            'NL' => 'Netherlands',
            'IT' => 'Italy',
            'ES' => 'Spain',
        ]);

        return $countries[$countryCode] ?? $countryCode;
    }

    /**
     * Get verification status display
     *
     * @return string
     */
    protected function getVerificationStatus(): string
    {
        if (!isset($this->is_verified)) {
            return 'pending';
        }

        return $this->is_verified ? 'verified' : 'unverified';
    }

    /**
     * Get risk level assessment
     *
     * @return string
     */
    protected function getRiskLevel(): string
    {
        // Default risk level
        $riskLevel = 'low';

        // Check country risk
        $highRiskCountries = config('mass-payments.compliance.high_risk_countries', ['AF', 'IR', 'KP', 'SY']);
        if (in_array($this->country, $highRiskCountries)) {
            $riskLevel = 'high';
        }

        // Check if has large payment history
        $totalAmount = $this->getTotalPaymentsAmount();
        $highValueThreshold = config('mass-payments.compliance.high_value_threshold', 100000.00);
        
        if ($totalAmount > $highValueThreshold) {
            $riskLevel = $riskLevel === 'high' ? 'critical' : 'medium';
        }

        // Check verification status
        if (!$this->is_verified) {
            $riskLevel = $riskLevel === 'low' ? 'medium' : $riskLevel;
        }

        return $riskLevel;
    }

    /**
     * Check if beneficiary can receive payments
     *
     * @return bool
     */
    protected function canReceivePayments(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        // Check if risk level allows payments
        $riskLevel = $this->getRiskLevel();
        if ($riskLevel === 'critical') {
            return false;
        }

        // Check if required fields are present
        if (empty($this->name) || empty($this->account_number) || empty($this->bank_code)) {
            return false;
        }

        return true;
    }

    /**
     * Check if compliance check is required
     *
     * @return bool
     */
    protected function requiresComplianceCheck(): bool
    {
        // Always require compliance check for high/critical risk
        $riskLevel = $this->getRiskLevel();
        if (in_array($riskLevel, ['high', 'critical'])) {
            return true;
        }

        // Check if compliance check is overdue
        if ($this->compliance_checked_at) {
            $daysSinceCheck = $this->compliance_checked_at->diffInDays(now());
            $complianceValidityDays = config('mass-payments.compliance.validity_days', 365);
            
            if ($daysSinceCheck > $complianceValidityDays) {
                return true;
            }
        } else {
            // Never been