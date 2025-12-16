## Code: app/Services/ValidationService.php

```php
<?php

namespace App\Services;

use App\Models\PaymentFile;
use App\Models\PaymentInstruction;
use App\Models\ValidationError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class ValidationService
{
    /**
     * Maximum allowed amount per instruction.
     */
    private const MAX_AMOUNT = 999999.99;

    /**
     * Minimum allowed amount per instruction.
     */
    private const MIN_AMOUNT = 0.01;

    /**
     * Maximum length for text fields.
     */
    private const MAX_TEXT_LENGTH = 255;

    /**
     * Maximum length for payment purpose field.
     */
    private const MAX_PURPOSE_LENGTH = 500;

    /**
     * Required CSV headers.
     */
    private const REQUIRED_HEADERS = [
        'beneficiary_name',
        'beneficiary_account',
        'amount',
        'currency',
        'settlement_method',
    ];

    /**
     * Optional CSV headers.
     */
    private const OPTIONAL_HEADERS = [
        'payment_purpose',
        'reference',
    ];

    /**
     * Validate an array of payment instructions.
     *
     * @param array $instructions Array of payment instruction data
     * @return array Array containing validation results and errors
     */
    public function validatePaymentInstructions(array $instructions): array
    {
        $validInstructions = [];
        $validationErrors = [];
        $references = [];

        Log::info('Starting validation of payment instructions', [
            'instruction_count' => count($instructions),
        ]);

        foreach ($instructions as $index => $instruction) {
            $rowNumber = $index + 2; // +2 because CSV starts at row 1 (header) and data at row 2

            try {
                $rowErrors = $this->validateRow($instruction, $rowNumber);
                
                if (empty($rowErrors)) {
                    // Check for duplicate references within the file
                    $reference = $instruction['reference'] ?? '';
                    if (!empty($reference)) {
                        if (in_array($reference, $references)) {
                            $rowErrors[] = [
                                'field_name' => ValidationError::FIELD_REFERENCE,
                                'error_message' => 'Duplicate payment reference within the file',
                                'error_code' => ValidationError::ERROR_DUPLICATE_REFERENCE,
                            ];
                        } else {
                            $references[] = $reference;
                        }
                    }
                }

                if (empty($rowErrors)) {
                    $validInstructions[] = [
                        'row_number' => $rowNumber,
                        'beneficiary_name' => trim($instruction['beneficiary_name']),
                        'beneficiary_account' => trim($instruction['beneficiary_account']),
                        'amount' => (float) $instruction['amount'],
                        'currency' => strtoupper(trim($instruction['currency'])),
                        'settlement_method' => strtoupper(trim($instruction['settlement_method'])),
                        'payment_purpose' => isset($instruction['payment_purpose']) ? trim($instruction['payment_purpose']) : null,
                        'reference' => isset($instruction['reference']) ? trim($instruction['reference']) : null,
                        'status' => PaymentInstruction::STATUS_PENDING,
                    ];
                } else {
                    // Add row number to each error
                    foreach ($rowErrors as &$error) {
                        $error['row_number'] = $rowNumber;
                    }
                    $validationErrors = array_merge($validationErrors, $rowErrors);
                }
            } catch (Exception $e) {
                Log::error('Error validating instruction row', [
                    'row_number' => $rowNumber,
                    'error' => $e->getMessage(),
                    'instruction' => $instruction,
                ]);

                $validationErrors[] = [
                    'row_number' => $rowNumber,
                    'field_name' => 'general',
                    'error_message' => 'Unexpected error validating this row',
                    'error_code' => ValidationError::ERROR_INVALID_FORMAT,
                ];
            }
        }

        Log::info('Completed validation of payment instructions', [
            'valid_instructions' => count($validInstructions),
            'validation_errors' => count($validationErrors),
        ]);

        return [
            'valid_instructions' => $validInstructions,
            'validation_errors' => $validationErrors,
            'total_processed' => count($instructions),
            'valid_count' => count($validInstructions),
            'invalid_count' => count($instructions) - count($validInstructions),
        ];
    }

    /**
     * Validate a single row of payment instruction data.
     *
     * @param array $row Single instruction data
     * @param int $rowNumber Row number in the CSV file
     * @return array Array of validation errors for this row
     */
    public function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];

        // Validate required fields
        foreach (self::REQUIRED_HEADERS as $field) {
            if (!isset($row[$field]) || trim($row[$field]) === '') {
                $errors[] = [
                    'field_name' => $field,
                    'error_message' => "The {$field} field is required",
                    'error_code' => ValidationError::ERROR_REQUIRED_FIELD,
                ];
            }
        }

        // If any required fields are missing, return early
        if (!empty($errors)) {
            return $errors;
        }

        // Validate beneficiary name
        $errors = array_merge($errors, $this->validateBeneficiaryName($row['beneficiary_name']));

        // Validate beneficiary account
        $errors = array_merge($errors, $this->validateBeneficiaryAccount(
            $row['beneficiary_account'],
            $row['settlement_method'] ?? ''
        ));

        // Validate amount
        $errors = array_merge($errors, $this->validateAmount($row['amount']));

        // Validate currency
        $errors = array_merge($errors, $this->validateCurrency($row['currency']));

        // Validate settlement method
        $errors = array_merge($errors, $this->validateSettlementMethod($row['settlement_method']));

        // Cross-validate settlement method and currency
        if (isset($row['currency']) && isset($row['settlement_method'])) {
            $errors = array_merge($errors, $this->validateSettlementMethodCurrency(
                $row['settlement_method'],
                $row['currency']
            ));
        }

        // Validate optional fields if present
        if (isset($row['payment_purpose']) && !empty($row['payment_purpose'])) {
            $errors = array_merge($errors, $this->validatePaymentPurpose($row['payment_purpose']));
        }

        if (isset($row['reference']) && !empty($row['reference'])) {
            $errors = array_merge($errors, $this->validateReference($row['reference']));
        }

        return $errors;
    }

    /**
     * Check if settlement method is valid for the given currency.
     *
     * @param string $method Settlement method
     * @param string $currency Currency code
     * @return bool True if valid combination
     */
    public function checkSettlementMethod(string $method, string $currency): bool
    {
        $method = strtoupper(trim($method));
        $currency = strtoupper(trim($currency));

        return PaymentInstruction::isSettlementMethodValidForCurrency($method, $currency);
    }

    /**
     * Validate beneficiary account number for the given settlement method.
     *
     * @param string $account Account number
     * @param string $method Settlement method
     * @return bool True if valid account format
     */
    public function validateBeneficiaryAccount(string $account, string $method): array
    {
        $errors = [];
        $account = trim($account);
        $method = strtoupper(trim($method));

        if (empty($account)) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                'error_message' => 'Beneficiary account is required',
                'error_code' => ValidationError::ERROR_REQUIRED_FIELD,
            ];
            return $errors;
        }

        if (strlen($account) > self::MAX_TEXT_LENGTH) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                'error_message' => 'Beneficiary account is too long',
                'error_code' => ValidationError::ERROR_FIELD_TOO_LONG,
            ];
        }

        // Validate account format based on settlement method
        switch ($method) {
            case PaymentInstruction::SETTLEMENT_SEPA:
                if (!$this->validateIban($account)) {
                    $errors[] = [
                        'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                        'error_message' => 'Invalid IBAN format for SEPA transfers',
                        'error_code' => ValidationError::ERROR_INVALID_ACCOUNT,
                    ];
                }
                break;

            case PaymentInstruction::SETTLEMENT_FASTER_PAYMENTS:
                if (!$this->validateUkSortCodeAccount($account)) {
                    $errors[] = [
                        'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                        'error_message' => 'Invalid UK sort code and account format',
                        'error_code' => ValidationError::ERROR_INVALID_ACCOUNT,
                    ];
                }
                break;

            case PaymentInstruction::SETTLEMENT_ACH:
                if (!$this->validateUsAccountNumber($account)) {
                    $errors[] = [
                        'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                        'error_message' => 'Invalid US account number format',
                        'error_code' => ValidationError::ERROR_INVALID_ACCOUNT,
                    ];
                }
                break;

            case PaymentInstruction::SETTLEMENT_SWIFT:
            case PaymentInstruction::SETTLEMENT_WIRE:
                if (!$this->validateGenericAccountNumber($account)) {
                    $errors[] = [
                        'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                        'error_message' => 'Invalid account number format',
                        'error_code' => ValidationError::ERROR_INVALID_ACCOUNT,
                    ];
                }
                break;

            default:
                // For unknown settlement methods, just check basic format
                if (!$this->validateGenericAccountNumber($account)) {
                    $errors[] = [
                        'field_name' => ValidationError::FIELD_BENEFICIARY_ACCOUNT,
                        'error_message' => 'Invalid account number format',
                        'error_code' => ValidationError::ERROR_INVALID_ACCOUNT,
                    ];
                }
                break;
        }

        return $errors;
    }

    /**
     * Validate beneficiary name.
     *
     * @param string $name Beneficiary name
     * @return array Validation errors
     */
    private function validateBeneficiaryName(string $name): array
    {
        $errors = [];
        $name = trim($name);

        if (empty($name)) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_BENEFICIARY_NAME,
                'error_message' => 'Beneficiary name is required',
                'error_code' => ValidationError::ERROR_REQUIRED_FIELD,
            ];
            return $errors;
        }

        if (strlen($name) > self::MAX_TEXT_LENGTH) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_BENEFICIARY_NAME,
                'error_message' => 'Beneficiary name is too long',
                'error_code' => ValidationError::ERROR_FIELD_TOO_LONG,
            ];
        }

        // Basic name format validation
        if (!preg_match('/^[a-zA-Z0-9\s\-\.\']+$/', $name)) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_BENEFICIARY_NAME,
                'error_message' => 'Beneficiary name contains invalid characters',
                'error_code' => ValidationError::ERROR_INVALID_FORMAT,
            ];
        }

        return $errors;
    }

    /**
     * Validate payment amount.
     *
     * @param mixed $amount Amount value
     * @return array Validation errors
     */
    private function validateAmount($amount): array
    {
        $errors = [];

        if (!is_numeric($amount)) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_AMOUNT,
                'error_message' => 'Amount must be a valid number',
                'error_code' => ValidationError::ERROR_INVALID_AMOUNT,
            ];
            return $errors;
        }

        $amount = (float) $amount;

        if ($amount <= 0) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_AMOUNT,
                'error_message' => 'Amount must be greater than zero',
                'error_code' => ValidationError::ERROR_INVALID_AMOUNT,
            ];
        } elseif ($amount < self::MIN_AMOUNT) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_AMOUNT,
                'error_message' => 'Amount is below minimum allowed value',
                'error_code' => ValidationError::ERROR_AMOUNT_TOO_SMALL,
            ];
        } elseif ($amount > self::MAX_AMOUNT) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_AMOUNT,
                'error_message' => 'Amount exceeds maximum allowed value',
                'error_code' => ValidationError::ERROR_AMOUNT_TOO_LARGE,
            ];
        }

        // Check decimal places (max 2)
        if (round($amount, 2) !== $amount) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_AMOUNT,
                'error_message' => 'Amount cannot have more than 2 decimal places',
                'error_code' => ValidationError::ERROR_INVALID_FORMAT,
            ];
        }

        return $errors;
    }

    /**
     * Validate currency code.
     *
     * @param string $currency Currency code
     * @return array Validation errors
     */
    private function validateCurrency(string $currency): array
    {
        $errors = [];
        $currency = strtoupper(trim($currency));

        if (empty($currency)) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_CURRENCY,
                'error_message' => 'Currency is required',
                'error_code' => ValidationError::ERROR_REQUIRED_FIELD,
            ];
            return $errors;
        }

        if (strlen($currency) !== 3) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_CURRENCY,
                'error_message' => 'Currency code must be exactly 3 characters',
                'error_code' => ValidationError::ERROR_INVALID_FORMAT,
            ];
        }

        if (!in_array($currency, PaymentInstruction::getValidCurrencies())) {
            $errors[] = [
                'field_name' => ValidationError::FIELD_CURRENCY,
                'error_message' => 'Unsupported currency code',
                'error_code' => ValidationError::ERROR_INVALID_CURRENCY,
            ];
        }

        return $errors;
    }

    /**
     * Validate settlement method.
     *
     * @param string $method Settlement method
     * @return array Validation errors
     */
    private function validateSettlementMethod(string $method): array
    {
        $errors = [];
        $method = strtoupper(trim($method));

        if (empty($method)) {
            $errors[] = [
                'field_name'