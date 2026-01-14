## Code: app/Http/Resources/PaymentInstructionResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class PaymentInstructionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mass_payment_file_id' => $this->mass_payment_file_id,
            'row_number' => $this->row_number,
            'status' => $this->status,
            'formatted_status' => $this->getFormattedStatus(),
            'status_color' => $this->getStatusColor(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted_amount' => $this->getFormattedAmount(),
            'purpose_code' => $this->purpose_code,
            'reference' => $this->reference,
            'beneficiary_type' => $this->beneficiary_type,
            'beneficiary_info' => [
                'id' => $this->beneficiary_id,
                'name' => $this->beneficiary_name,
                'email' => $this->beneficiary_email,
                'phone' => $this->beneficiary_phone,
                'country' => $this->beneficiary_country,
                'type' => $this->beneficiary_type,
                'formatted_address' => $this->getFormattedBeneficiaryAddress(),
            ],
            'bank_details' => [
                'account_number' => $this->when(
                    !empty($this->beneficiary_account_number),
                    $this->maskAccountNumber($this->beneficiary_account_number)
                ),
                'sort_code' => $this->beneficiary_sort_code,
                'iban' => $this->when(
                    !empty($this->beneficiary_iban),
                    $this->maskIban($this->beneficiary_iban)
                ),
                'swift_code' => $this->beneficiary_swift_code,
                'bank_name' => $this->beneficiary_bank_name,
                'bank_address' => $this->beneficiary_bank_address,
                'account_identifier' => $this->getAccountIdentifier(),
                'formatted_bank_details' => $this->getFormattedBankDetails(),
            ],
            'currency_specific_data' => $this->getCurrencySpecificData(),
            'validation' => [
                'is_valid' => !$this->hasValidationErrors(),
                'errors' => $this->when(
                    $this->hasValidationErrors(),
                    $this->formatValidationErrors($this->validation_errors ?? [])
                ),
                'error_count' => $this->getValidationErrorCount(),
            ],
            'processing' => [
                'external_transaction_id' => $this->when(
                    !empty($this->external_transaction_id),
                    $this->external_transaction_id
                ),
                'fx_rate' => $this->fx_rate,
                'fee_amount' => $this->fee_amount,
                'fee_currency' => $this->fee_currency,
                'formatted_fee' => $this->getFormattedFee(),
                'processed_at' => $this->processed_at?->toISOString(),
                'processed_at_formatted' => $this->processed_at?->format('M j, Y g:i A'),
                'processing_notes' => $this->when(
                    !empty($this->processing_notes),
                    $this->processing_notes
                ),
            ],
            'capabilities' => [
                'can_be_processed' => $this->canBeProcessed(),
                'can_be_cancelled' => $this->canBeCancelled(),
                'is_processing' => $this->isProcessing(),
                'is_completed' => $this->isCompleted(),
                'has_failed' => $this->hasFailed(),
                'is_cancelled' => $this->isCancelled(),
            ],
            'timestamps' => [
                'created_at' => $this->created_at->toISOString(),
                'updated_at' => $this->updated_at->toISOString(),
                'created_at_formatted' => $this->created_at->format('M j, Y g:i A'),
                'updated_at_formatted' => $this->updated_at->format('M j, Y g:i A'),
                'time_since_creation' => $this->created_at->diffForHumans(),
                'time_since_update' => $this->updated_at->diffForHumans(),
            ],
            'links' => [
                'self' => route('api.v1.payment-instructions.show', ['id' => $this->id]),
                'mass_payment_file' => route('api.v1.mass-payment-files.show', [
                    'id' => $this->mass_payment_file_id
                ]),
                'beneficiary' => $this->when(
                    !empty($this->beneficiary_id),
                    route('api.v1.beneficiaries.show', ['id' => $this->beneficiary_id])
                ),
            ],
        ];
    }

    /**
     * Get formatted amount with currency symbol.
     */
    private function getFormattedAmount(): string
    {
        return number_format($this->amount, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Get formatted status for display.
     */
    private function getFormattedStatus(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }

    /**
     * Get status color for UI display.
     */
    private function getStatusColor(): string
    {
        return match ($this->status) {
            'draft' => 'gray',
            'validated' => 'green',
            'validation_failed' => 'red',
            'pending' => 'yellow',
            'processing' => 'blue',
            'completed' => 'green',
            'failed' => 'red',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get formatted beneficiary address.
     */
    private function getFormattedBeneficiaryAddress(): string
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
     * Get formatted bank details.
     */
    private function getFormattedBankDetails(): string
    {
        $bankParts = array_filter([
            $this->beneficiary_bank_name,
            $this->beneficiary_swift_code,
            $this->beneficiary_bank_address,
        ]);

        return implode(', ', $bankParts);
    }

    /**
     * Get account identifier (IBAN or account number).
     */
    private function getAccountIdentifier(): string
    {
        if (!empty($this->beneficiary_iban)) {
            return $this->maskIban($this->beneficiary_iban);
        }

        if (!empty($this->beneficiary_account_number)) {
            return $this->maskAccountNumber($this->beneficiary_account_number);
        }

        return 'N/A';
    }

    /**
     * Get formatted fee amount with currency.
     */
    private function getFormattedFee(): string
    {
        if (!$this->fee_amount || $this->fee_amount == 0) {
            return '0.00';
        }

        $currency = $this->fee_currency ?: $this->currency;
        return number_format($this->fee_amount, 2) . ' ' . strtoupper($currency);
    }

    /**
     * Get currency-specific data based on requirements.
     */
    private function getCurrencySpecificData(): array
    {
        $data = [];
        $currency = strtoupper($this->currency);

        // INR specific fields
        if ($currency === 'INR' && $this->requiresInvoiceDetails()) {
            $data['invoice_details'] = [
                'invoice_number' => $this->invoice_number,
                'invoice_date' => $this->invoice_date?->toDateString(),
                'invoice_date_formatted' => $this->invoice_date?->format('M j, Y'),
            ];
        }

        // TRY specific fields for business recipients
        if ($currency === 'TRY' && $this->requiresIncorporationNumber()) {
            $data['incorporation_details'] = [
                'incorporation_number' => $this->incorporation_number,
                'is_business_recipient' => $this->isBusiness(),
            ];
        }

        // High-value transaction indicators
        if ($this->isHighValueTransaction()) {
            $data['high_value_indicators'] = [
                'requires_additional_documentation' => true,
                'enhanced_due_diligence' => true,
                'processing_priority' => 'high',
            ];
        }

        return $data;
    }

    /**
     * Format validation errors for API response.
     */
    private function formatValidationErrors(array $errors): array
    {
        if (empty($errors)) {
            return [];
        }

        $formattedErrors = [];

        foreach ($errors as $index => $error) {
            if (is_array($error)) {
                // Structured error format
                $formattedErrors[] = [
                    'index' => $index,
                    'field' => $error['field'] ?? null,
                    'message' => $error['message'] ?? 'Unknown validation error',
                    'value' => $error['value'] ?? null,
                    'severity' => $error['severity'] ?? 'error',
                    'rule' => $error['rule'] ?? null,
                ];
            } else {
                // Simple string error format
                $formattedErrors[] = [
                    'index' => $index,
                    'field' => null,
                    'message' => (string) $error,
                    'value' => null,
                    'severity' => 'error',
                    'rule' => null,
                ];
            }
        }

        return [
            'count' => count($formattedErrors),
            'errors' => $formattedErrors,
            'summary' => $this->generateValidationErrorSummary($formattedErrors),
        ];
    }

    /**
     * Generate validation error summary.
     */
    private function generateValidationErrorSummary(array $formattedErrors): array
    {
        $errorsByField = [];
        $errorsBySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
        $errorsByRule = [];

        foreach ($formattedErrors as $error) {
            // Group by field
            $field = $error['field'] ?? 'unknown';
            $errorsByField[$field] = ($errorsByField[$field] ?? 0) + 1;

            // Group by severity
            $severity = $error['severity'] ?? 'error';
            $errorsBySeverity[$severity] = ($errorsBySeverity[$severity] ?? 0) + 1;

            // Group by rule
            $rule = $error['rule'] ?? 'unknown';
            $errorsByRule[$rule] = ($errorsByRule[$rule] ?? 0) + 1;
        }

        return [
            'total_errors' => count($formattedErrors),
            'by_field' => $errorsByField,
            'by_severity' => $errorsBySeverity,
            'by_rule' => $errorsByRule,
            'critical_errors' => $errorsBySeverity['error'] ?? 0,
        ];
    }

    /**
     * Mask account number for security.
     */
    private function maskAccountNumber(?string $accountNumber): string
    {
        if (empty($accountNumber)) {
            return '';
        }

        $length = strlen($accountNumber);
        
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        // Show last 4 digits
        $masked = str_repeat('*', $length - 4) . substr($accountNumber, -4);
        return $masked;
    }

    /**
     * Mask IBAN for security.
     */
    private function maskIban(?string $iban): string
    {
        if (empty($iban)) {
            return '';
        }

        $cleanIban = str_replace(' ', '', strtoupper($iban));
        $length = strlen($cleanIban);

        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        // Show first 4 and last 4 characters
        $masked = substr($cleanIban, 0, 4) . str_repeat('*', $length - 8) . substr($cleanIban, -4);
        
        // Add spaces for readability
        return chunk_split($masked, 4, ' ');
    }

    /**
     * Check if this is a high-value transaction.
     */
    private function isHighValueTransaction(): bool
    {
        $highValueThresholds = [
            'USD' => 10000.00,
            'EUR' => 9000.00,
            'GBP' => 8000.00,
            'JPY' => 1200000.00,
            'INR' => 750000.00,
            'TRY' => 300000.00,
        ];

        $threshold = $highValueThresholds[strtoupper($this->currency)] ?? 10000.00;
        
        return $this->amount >= $threshold;
    }

    /**
     * Get processing stage information.
     */
    private function getProcessingStage(): array
    {
        $stages = [
            'draft' => ['stage' => 1, 'name' => 'Draft', 'description' => 'Instruction created from CSV'],
            'validated' => ['stage' => 2, 'name' => 'Validated', 'description' => 'Data validation completed'],
            'validation_failed' => ['stage' => 2, 'name' => 'Validation Failed', 'description' => 'Data validation failed'],
            'pending' => ['stage' => 3, 'name' => 'Pending', 'description' => 'Awaiting processing'],
            'processing' => ['stage' => 4, 'name' => 'Processing', 'description' => 'Payment being executed'],
            'completed' => ['stage' => 5, 'name' => 'Completed', 'description' => 'Payment successfully completed'],
            'failed' => ['stage' => 4, 'name' => 'Failed', 'description' => 'Payment execution failed'],
            'cancelled' => ['stage' => 3, 'name' => 'Cancelled', 'description' => 'Payment instruction cancelled'],
        ];

        return $stages[$this->status] ?? [
            'stage' => 0,
            'name' => 'Unknown',
            'description' => 'Unknown status'
        ];
    }

    /**
     * Get risk indicators for the payment instruction.
     */
    private function getRiskIndicators(): array
    {
        $riskFactors = [];

        // High value risk
        if ($this->isHighValueTransaction()) {
            $riskFactors[] = 'high_value';
        }

        // Currency risk
        $highRiskCurrencies = ['INR', 'TRY', 'CNY', 'RUB'];
        if (in_array(strtoupper($this->currency), $highRiskCurrencies)) {
            $riskFactors[] = 'high_risk_currency';
        }

        // Business recipient risk
        if ($this->isBusiness()) {
            $riskFactors[] = 'business_recipient';
        }

        // Missing documentation risk
        if ($