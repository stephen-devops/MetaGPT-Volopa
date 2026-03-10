<?php

namespace App\Services;

use App\Models\User;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * PocketExpenseCSVValidator
 * 
 * Service class for validating CSV files containing expense data in the batch upload system.
 * Handles comprehensive CSV validation including header validation, row-by-row data validation,
 * reference data validation, and business rule enforcement. Implements all-or-nothing validation
 * approach where any validation failure prevents expense creation.
 * 
 * Key responsibilities:
 * - Validate CSV file structure and headers
 * - Perform row-by-row data validation with detailed error reporting
 * - Validate against reference data (expense types, sources, users)
 * - Enforce business rules and constraints
 * - Preload reference data for performance optimization
 * - Support multi-tenant validation scoping
 * - Provide detailed validation error reporting
 */
class PocketExpenseCSVValidator
{
    /**
     * Expected CSV column headers (exact match required)
     *
     * @var array<int, string>
     */
    private const EXPECTED_CSV_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'Merchant Address',
        'VAT Amount',
        'VAT %',
        'Notes',
        'Source',
        'Source Note'
    ];

    /**
     * Required CSV columns that must have values
     *
     * @var array<int, string>
     */
    private const REQUIRED_CSV_COLUMNS = [
        'Date',
        'Merchant Name',
        'Expense Type',
        'Currency',
        'Amount'
    ];

    /**
     * Maximum length constraints for fields
     *
     * @var array<string, int>
     */
    private const FIELD_MAX_LENGTHS = [
        'Merchant Name' => 180,
        'Merchant Description' => 255,
        'Merchant Address' => 500,
        'Notes' => 65535,
        'Source Note' => 500
    ];

    /**
     * Supported currencies (ISO 3-letter codes)
     *
     * @var array<int, string>
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'HUF', 'BGN', 'RON', 'HRK', 'RSD', 'BAM', 'MKD', 'ALL',
        'ISK', 'TRY', 'RUB', 'UAH', 'BYN', 'MDL', 'GEL', 'AMD', 'AZN', 'KZT',
        'UZS', 'KGS', 'TJS', 'TMT', 'MNT', 'CNY', 'HKD', 'SGD', 'MYR', 'THB',
        'IDR', 'PHP', 'VND', 'KRW', 'INR', 'PKR', 'LKR', 'BDT', 'NPR', 'BTN',
        'MVR', 'AFN', 'IRR', 'IQD', 'SYP', 'LBP', 'JOD', 'KWD', 'BHD', 'QAR',
        'AED', 'OMR', 'YER', 'SAR', 'ILS', 'EGP', 'LYD', 'TND', 'DZD', 'MAD',
        'XOF', 'XAF', 'NGN', 'GHS', 'XCD', 'BBD', 'JMD', 'TTD', 'COP', 'PEN',
        'BOB', 'BRL', 'ARS', 'CLP', 'UYU', 'PYG', 'VES', 'GYD', 'SRD', 'FKP',
        'ZAR', 'BWP', 'NAD', 'SZL', 'LSL', 'MZN', 'MWK', 'ZMW', 'AOA', 'CDF'
    ];

    /**
     * Maximum amount value (15 digits, 2 decimal places)
     *
     * @var float
     */
    private const MAX_AMOUNT = 999999999999.99;

    /**
     * Minimum amount value
     *
     * @var float
     */
    private const MIN_AMOUNT = 0.01;

    /**
     * Maximum date age in years
     *
     * @var int
     */
    private const MAX_DATE_YEARS_AGO = 3;

    /**
     * Date format expected in CSV
     *
     * @var string
     */
    private const DATE_FORMAT = 'd/m/Y';

    /**
     * Maximum number of validation errors to collect per file
     *
     * @var int
     */
    private const MAX_VALIDATION_ERRORS = 500;

    /**
     * Preloaded reference data cache
     *
     * @var array<string, mixed>
     */
    private array $referenceData = [];

    /**
     * Current validation context
     *
     * @var array<string, mixed>
     */
    private array $validationContext = [];

    /**
     * Validation errors collection
     *
     * @var array<int, array<string, mixed>>
     */
    private array $validationErrors = [];

    /**
     * Header column mapping (column name => index)
     *
     * @var array<string, int>
     */
    private array $headerMap = [];

    /**
     * Validate CSV file and return validation results.
     * 
     * Performs comprehensive validation including header validation,
     * row-by-row data validation, and reference data validation.
     * Returns validation results with detailed error information.
     *
     * @param string $csvFilePath
     * @param int $userId
     * @param int $clientId
     * @param int $uploadId
     * @return array<string, mixed>
     */
    public function validate(string $csvFilePath, int $userId, int $clientId, int $uploadId): array
    {
        $startTime = microtime(true);
        
        // Initialize validation context
        $this->initializeValidationContext($userId, $clientId, $uploadId);
        
        // Reset validation state
        $this->resetValidationState();

        // Validate file exists and is readable
        if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
            return $this->buildValidationResult(false, ['File not found or not readable'], 0, 0, $startTime);
        }

        try {
            // Preload reference data for validation
            $this->preloadReferenceData();

            // Open and validate CSV file
            $handle = fopen($csvFilePath, 'r');
            if ($handle === false) {
                return $this->buildValidationResult(false, ['Unable to open CSV file'], 0, 0, $startTime);
            }

            // Validate headers
            $headerRow = fgetcsv($handle);
            if ($headerRow === false || empty($headerRow)) {
                fclose($handle);
                return $this->buildValidationResult(false, ['CSV file is empty or has no header row'], 0, 0, $startTime);
            }

            $headerValidation = $this->validateHeaders($headerRow);
            if (!$headerValidation['valid']) {
                fclose($handle);
                return $this->buildValidationResult(false, $headerValidation['errors'], 0, 0, $startTime);
            }

            // Validate data rows
            $totalRows = 0;
            $validRows = 0;
            $lineNumber = 2; // Starting from line 2 (after header)

            while (($row = fgetcsv($handle)) !== false) {
                $totalRows++;
                
                if (empty(array_filter($row))) {
                    // Skip empty rows
                    $lineNumber++;
                    continue;
                }

                $rowValidation = $this->validateRow($row, $lineNumber);
                
                if ($rowValidation['valid']) {
                    $validRows++;
                } else {
                    $this->addValidationErrors($rowValidation['errors']);
                }

                $lineNumber++;

                // Stop if we have too many errors to prevent memory issues
                if (count($this->validationErrors) >= self::MAX_VALIDATION_ERRORS) {
                    $this->addValidationError('system', 'Too many validation errors. Processing stopped.', null, [
                        'max_errors' => self::MAX_VALIDATION_ERRORS,
                        'rows_processed' => $totalRows
                    ]);
                    break;
                }
            }

            fclose($handle);

            // Determine overall validation result
            $isValid = empty($this->validationErrors) && $totalRows > 0;
            
            return $this->buildValidationResult($isValid, $this->validationErrors, $totalRows, $validRows, $startTime);

        } catch (\Exception $e) {
            Log::error('CSV validation failed with exception', [
                'file_path' => $csvFilePath,
                'user_id' => $userId,
                'client_id' => $clientId,
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->buildValidationResult(false, [
                'Validation failed due to system error: ' . $e->getMessage()
            ], 0, 0, $startTime);
        }
    }

    /**
     * Validate CSV headers against expected format.
     *
     * @param array<int, string> $headerRow
     * @return array<string, mixed>
     */
    private function validateHeaders(array $headerRow): array
    {
        $errors = [];
        $headerRow = array_map('trim', $headerRow);

        // Create header mapping
        $this->headerMap = array_flip($headerRow);

        // Check if all expected headers are present
        $missingHeaders = array_diff(self::EXPECTED_CSV_HEADERS, $headerRow);
        foreach ($missingHeaders as $missingHeader) {
            $errors[] = "Missing required column: '{$missingHeader}'";
        }

        // Check for unexpected headers
        $extraHeaders = array_diff($headerRow, self::EXPECTED_CSV_HEADERS);
        foreach ($extraHeaders as $extraHeader) {
            if (!empty(trim($extraHeader))) {
                $errors[] = "Unexpected column: '{$extraHeader}'";
            }
        }

        // Check for duplicate headers
        $duplicateHeaders = array_diff_assoc($headerRow, array_unique($headerRow));
        foreach (array_unique($duplicateHeaders) as $duplicateHeader) {
            if (!empty(trim($duplicateHeader))) {
                $errors[] = "Duplicate column: '{$duplicateHeader}'";
            }
        }

        // Check column count
        if (count($headerRow) !== count(self::EXPECTED_CSV_HEADERS)) {
            $errors[] = "Expected " . count(self::EXPECTED_CSV_HEADERS) . " columns, found " . count($headerRow);
        }

        // Check exact order if no other errors
        if (empty($errors)) {
            for ($i = 0; $i < count(self::EXPECTED_CSV_HEADERS); $i++) {
                if (!isset($headerRow[$i]) || $headerRow[$i] !== self::EXPECTED_CSV_HEADERS[$i]) {
                    $expected = self::EXPECTED_CSV_HEADERS[$i] ?? 'N/A';
                    $actual = $headerRow[$i] ?? 'Missing';
                    $errors[] = "Column " . ($i + 1) . ": expected '{$expected}', found '{$actual}'";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'header_map' => $this->headerMap
        ];
    }

    /**
     * Validate individual CSV row data.
     *
     * @param array<int, string> $row
     * @param int $lineNumber
     * @return array<string, mixed>
     */
    private function validateRow(array $row, int $lineNumber): array
    {
        $errors = [];
        $rowData = [];

        // Check column count
        if (count($row) !== count(self::EXPECTED_CSV_HEADERS)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'row_structure',
                'message' => "Expected " . count(self::EXPECTED_CSV_HEADERS) . " columns, found " . count($row),
                'value' => null,
                'code' => 'invalid_column_count'
            ];
            return ['valid' => false, 'errors' => $errors, 'data' => []];
        }

        // Map row data to column names
        foreach (self::EXPECTED_CSV_HEADERS as $index => $columnName) {
            $rowData[$columnName] = isset($row[$index]) ? trim($row[$index]) : '';
        }

        // Validate required fields
        foreach (self::REQUIRED_CSV_COLUMNS as $requiredColumn) {
            $value = $rowData[$requiredColumn] ?? '';
            if (empty($value)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => $requiredColumn,
                    'message' => "Required field '{$requiredColumn}' is empty",
                    'value' => $value,
                    'code' => 'required_field_empty'
                ];
            }
        }

        // Validate individual fields
        $fieldValidations = [
            'Date' => [$this, 'validateDateField'],
            'Merchant Name' => [$this, 'validateMerchantNameField'],
            'Merchant Description' => [$this, 'validateMerchantDescriptionField'],
            'Expense Type' => [$this, 'validateExpenseTypeField'],
            'Currency' => [$this, 'validateCurrencyField'],
            'Amount' => [$this, 'validateAmountField'],
            'Merchant Address' => [$this, 'validateMerchantAddressField'],
            'VAT Amount' => [$this, 'validateVATAmountField'],
            'VAT %' => [$this, 'validateVATPercentageField'],
            'Notes' => [$this, 'validateNotesField'],
            'Source' => [$this, 'validateSourceField'],
            'Source Note' => [$this, 'validateSourceNoteField']
        ];

        foreach ($fieldValidations as $fieldName => $validator) {
            $value = $rowData[$fieldName] ?? '';
            $fieldErrors = call_user_func($validator, $value, $lineNumber, $rowData);
            if (!empty($fieldErrors)) {
                $errors = array_merge($errors, $fieldErrors);
            }
        }

        // Cross-field validations
        $crossFieldErrors = $this->validateCrossFieldRules($rowData, $lineNumber);
        if (!empty($crossFieldErrors)) {
            $errors = array_merge($errors, $crossFieldErrors);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $rowData
        ];
    }

    /**
     * Validate date field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateDateField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Required field validation handled separately
        }

        try {
            $date = Carbon::createFromFormat(self::DATE_FORMAT, $value);
            
            // Check if the date was parsed correctly
            if (!$date || $date->format(self::DATE_FORMAT) !== $value) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'message' => 'Date must be in DD/MM/YYYY format',
                    'value' => $value,
                    'code' => 'invalid_date_format'
                ];
                return $errors;
            }

            // Check if date is not in the future
            if ($date->isFuture()) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'message' => 'Date cannot be in the future',
                    'value' => $value,
                    'code' => 'future_date'
                ];
            }

            // Check if date is not too old
            $maxAgeDate = now()->subYears(self::MAX_DATE_YEARS_AGO);
            if ($date->lt($maxAgeDate)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'message' => 'Date cannot be older than ' . self::MAX_DATE_YEARS_AGO . ' years',
                    'value' => $value,
                    'code' => 'date_too_old'
                ];
            }

        } catch (\Exception $e) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Date',
                'message' => 'Invalid date format. Use DD/MM/YYYY',
                'value' => $value,
                'code' => 'invalid_date_format'
            ];
        }

        return $errors;
    }

    /**
     * Validate merchant name field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateMerchantNameField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Required field validation handled separately
        }

        // Check length
        if (strlen($value) > self::FIELD_MAX_LENGTHS['Merchant Name']) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'message' => 'Merchant name cannot exceed ' . self::FIELD_MAX_LENGTHS['Merchant Name'] . ' characters',
                'value' => $value,
                'code' => 'field_too_long'
            ];
        }

        // Check for valid characters (basic sanitization check)
        $sanitized = trim(strip_tags($value));
        if (empty($sanitized)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'message' => 'Merchant name must contain valid characters',
                'value' => $value,
                'code' => 'invalid_characters'
            ];
        }

        return $errors;
    }

    /**
     * Validate merchant description field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateMerchantDescriptionField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Optional field
        }

        // Check length
        if (strlen($value) > self::FIELD_MAX_LENGTHS['Merchant Description']) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Description',
                'message' => 'Merchant description cannot exceed ' . self::FIELD_MAX_LENGTHS['Merchant Description'] . ' characters',
                'value' => $value,
                'code' => 'field_too_long'
            ];
        }

        return $errors;
    }

    /**
     * Validate expense type field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateExpenseTypeField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Required field validation handled separately
        }

        // Check if expense type exists in reference data
        $expenseTypes = $this->referenceData['expense_types'] ?? [];
        $validExpenseType = false;

        foreach ($expenseTypes as $expenseType) {
            if (strcasecmp($expenseType['option'], $value) === 0) {
                $validExpenseType = true;
                break;
            }
        }

        if (!$validExpenseType) {
            $availableTypes = array_column($expenseTypes, 'option');
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Expense Type',
                'message' => "Unknown expense type: '{$value}'. Available types: " . implode(', ', $availableTypes),
                'value' => $value,
                'code' => 'unknown_expense_type'
            ];
        }

        return $errors;
    }

    /**
     * Validate currency field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateCurrencyField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Required field validation handled separately
        }

        // Check format (3 letters, uppercase)
        if (strlen($value) !== 3) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency',
                'message' => 'Currency must be exactly 3 characters long',
                'value' => $value,
                'code' => 'invalid_currency_length'
            ];
            return $errors;
        }

        $currency = strtoupper($value);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency',
                'message' => "Unsupported currency: '{$value}'. Must be a valid 3-letter ISO currency code",
                'value' => $value,
                'code' => 'unsupported_currency'
            ];
        }

        return $errors;
    }

    /**
     * Validate amount field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateAmountField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Required field validation handled separately
        }

        // Parse numeric value
        $numericValue = $this->parseNumericValue($value);

        if ($numericValue === null) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'message' => 'Amount must be a valid number',
                'value' => $value,
                'code' => 'invalid_numeric_format'
            ];
            return $errors;
        }

        // Check range
        if ($numericValue < self::MIN_AMOUNT) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'message' => 'Amount must be at least ' . self::MIN_AMOUNT,
                'value' => $value,
                'code' => 'amount_too_small'
            ];
        }

        if ($numericValue > self::MAX_AMOUNT) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'message' => 'Amount cannot exceed ' . number_format(self::MAX_AMOUNT, 2),
                'value' => $value,
                'code' => 'amount_too_large'
            ];
        }

        // Check decimal places (max 2)
        if (strpos($value, '.') !== false) {
            $decimalPart = substr($value, strpos($value, '.') + 1);
            if (strlen($decimalPart) > 2) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Amount',
                    'message' => 'Amount can have at most 2 decimal places',
                    'value' => $value,
                    'code' => 'too_many_decimal_places'
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate merchant address field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateMerchantAddressField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Optional field
        }

        // Check length
        if (strlen($value) > self::FIELD_MAX_LENGTHS['Merchant Address']) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Address',
                'message' => 'Merchant address cannot exceed ' . self::FIELD_MAX_LENGTHS['Merchant Address'] . ' characters',
                'value' => $value,
                'code' => 'field_too_long'
            ];
        }

        return $errors;
    }

    /**
     * Validate VAT amount field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateVATAmountField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Optional field
        }

        // Parse numeric value
        $numericValue = $this->parseNumericValue($value);

        if ($numericValue === null) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT Amount',
                'message' => 'VAT amount must be a valid number',
                'value' => $value,
                'code' => 'invalid_numeric_format'
            ];
            return $errors;
        }

        // Check if non-negative
        if ($numericValue < 0) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT Amount',
                'message' => 'VAT amount cannot be negative',
                'value' => $value,
                'code' => 'negative_value'
            ];
        }

        // Check range
        if ($numericValue > self::MAX_AMOUNT) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT Amount',
                'message' => 'VAT amount cannot exceed ' . number_format(self::MAX_AMOUNT, 2),
                'value' => $value,
                'code' => 'amount_too_large'
            ];
        }

        return $errors;
    }

    /**
     * Validate VAT percentage field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateVATPercentageField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Optional field
        }

        // Remove % sign if present
        $cleanValue = str_replace('%', '', $value);
        $numericValue = $this->parseNumericValue($cleanValue);

        if ($numericValue === null) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT %',
                'message' => 'VAT percentage must be a valid number',
                'value' => $value,
                'code' => 'invalid_numeric_format'
            ];
            return $errors;
        }

        // Check range (0-100%)
        if ($numericValue < 0 || $numericValue > 100) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT %',
                'message' => 'VAT percentage must be between 0 and 100',
                'value' => $value,
                'code' => 'percentage_out_of_range'
            ];
        }

        return $errors;
    }

    /**
     * Validate notes field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateNotesField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Optional field
        }

        // Check length
        if (strlen($value) > self::FIELD_MAX_LENGTHS['Notes']) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Notes',
                'message' => 'Notes cannot exceed ' . self::FIELD_MAX_LENGTHS['Notes'] . ' characters',
                'value' => $value,
                'code' => 'field_too_long'
            ];
        }

        return $errors;
    }

    /**
     * Validate source field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateSourceField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        if (empty($value)) {
            return $errors; // Optional field
        }

        // Check if source exists in reference data
        $sources = $this->referenceData['expense_sources'] ?? [];
        $validSource = false;

        foreach ($sources as $source) {
            if (strcasecmp($source['name'], $value) === 0) {
                $validSource = true;
                break;
            }
        }

        if (!$validSource) {
            $availableSources = array_column($sources, 'name');
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Source',
                'message' => "Unknown expense source: '{$value}'. Available sources: " . implode(', ', $availableSources),
                'value' => $value,
                'code' => 'unknown_expense_source'
            ];
        }

        return $errors;
    }

    /**
     * Validate source note field.
     *
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<int, array<string, mixed>>
     */
    private function validateSourceNoteField(string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        // Check length if present
        if (!empty($value) && strlen($value) > self::FIELD_MAX_LENGTHS['Source Note']) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Source Note',
                'message' => 'Source note cannot exceed ' . self::FIELD_MAX_LENGTHS['Source Note'] . ' characters',
                'value' => $value,
                'code' => 'field_too_long'
            ];
        }

        return $errors;
    }

    /**
     * Validate cross-field business rules.
     *
     * @param array<string, string> $rowData
     * @param int $lineNumber
     * @return array<int, array<string, mixed>>
     */
    private function validateCrossFieldRules(array $rowData, int $lineNumber): array
    {
        $errors = [];

        // Rule: Source note is required when source is "Other"
        $source = $rowData['Source'] ?? '';
        $sourceNote = $rowData['Source Note'] ?? '';
        
        if (strcasecmp($source, 'Other') === 0 && empty($sourceNote)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Source Note',
                'message' => 'Source note is required when source is "Other"',
                'value' => $sourceNote,
                'code' => 'source_note_required_for_other'
            ];
        }

        // Rule: VAT amount cannot be greater than main amount
        $amount = $this->parseNumericValue($rowData['Amount'] ?? '');
        $vatAmount = $this->parseNumericValue($rowData['VAT Amount'] ?? '');
        
        if ($amount !== null && $vatAmount !== null && $vatAmount > $amount) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT Amount',
                'message' => 'VAT amount cannot be greater than the expense amount',
                'value' => $rowData['VAT Amount'],
                'code' => 'vat_exceeds_amount'
            ];
        }

        // Rule: VAT percentage and VAT amount consistency check
        $vatPercentage = $this->parseNumericValue(str_replace('%', '', $rowData['VAT %'] ?? ''));
        
        if ($amount !== null && $vatAmount !== null && $vatPercentage !== null) {
            $expectedVatAmount = ($amount * $vatPercentage) / 100;
            $tolerance = 0.01; // 1 cent tolerance for rounding
            
            if (abs($vatAmount - $expectedVatAmount) > $tolerance) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'VAT Amount',
                    'message' => 'VAT amount does not match VAT percentage calculation (expected: ' . number_format($expectedVatAmount, 2) . ')',
                    'value' => $rowData['VAT Amount'],
                    'code' => 'vat_calculation_mismatch'
                ];
            }
        }

        return $errors;
    }

    /**
     * Preload reference data for validation.
     *
     * @return void
     */
    private function preloadReferenceData(): void
    {
        $clientId = $this->validationContext['client_id'];

        // Load expense types
        $this->referenceData['expense_types'] = OptPocketExpenseType::select('id', 'option', 'amount_sign')
            ->get()
            ->toArray();

        // Load expense sources (client-specific and global)
        $this->referenceData['expense_sources'] = PocketExpenseSourceClientConfig::where(function ($query) use ($clientId) {
                $query->where('client_id', $clientId)
                      ->orWhereNull('client_id');
            })
            ->where('deleted', false)
            ->select('id', 'name', 'client_id')
            ->get()
            ->toArray();

        // Load client information
        $this->referenceData['client'] = Client::find($clientId);

        // Load user information
        $this->referenceData['user'] = User::find($this->validationContext['user_id']);

        Log::info('Reference data preloaded for CSV validation', [
            'client_id' => $clientId,
            'expense_types_count' => count($this->referenceData['expense_types']),
            'expense_sources_count' => count($this->referenceData['expense_sources']),
            'upload_id' => $this->validationContext['upload_id']
        ]);
    }

    /**
     * Initialize validation context.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $uploadId
     * @return void
     */
    private function initializeValidationContext(int $userId, int $clientId, int $uploadId): void
    {
        $this->validationContext = [
            'user_id' => $userId,
            'client_id' => $clientId,
            'upload_id' => $uploadId,
            'validation_started_at' => now(),
            'validation_id' => uniqid('csv_validation_', true)
        ];
    }

    /**
     * Reset validation state for new validation.
     *
     * @return void
     */
    private function resetValidationState(): void
    {
        $this->validationErrors = [];
        $this->headerMap = [];
    }

    /**
     * Add validation errors to collection.
     *
     * @param array<int, array<string, mixed>> $errors
     * @return void
     */
    private function addValidationErrors(array $errors): void
    {
        foreach ($errors as $error) {
            if (count($this->validationErrors) >= self::MAX_VALIDATION_ERRORS) {
                break;
            }
            $this->validationErrors[] = $error;
        }
    }

    /**
     * Add a single validation error.
     *
     * @param string $type
     * @param string $message
     * @param int|null $lineNumber
     * @param array<string, mixed> $context
     * @return void
     */
    private function addValidationError(string $type, string $message, ?int $lineNumber = null, array $context = []): void
    {
        if (count($this->validationErrors) >= self::MAX_VALIDATION_ERRORS) {
            return;
        }

        $error = [
            'type' => $type,
            'message' => $message,
            'timestamp' => now()->toISOString()
        ];

        if ($lineNumber !== null) {
            $error['line_number'] = $lineNumber;
        }

        if (!empty($context)) {
            $error['context'] = $context;
        }

        $this->validationErrors[] = $error;
    }

    /**
     * Build validation result array.
     *
     * @param bool $isValid
     * @param array<int, mixed> $errors
     * @param int $totalRows
     * @param int $validRows
     * @param float $startTime
     * @return array<string, mixed>
     */
    private function buildValidationResult(bool $isValid, array $errors, int $totalRows, int $validRows, float $startTime): array
    {
        $processingTime = microtime(true) - $startTime;

        $result = [
            'valid' => $isValid,
            'total_records' => $totalRows,
            'valid_records' => $validRows,
            'invalid_records' => $totalRows - $validRows,
            'errors' => $errors,
            'error_count' => count($errors),
            'validation_context' => $this->validationContext,
            'processing_time_seconds' => round($processingTime, 3),
            'summary' => [
                'validation_passed' => $isValid,
                'records_processed' => $totalRows,
                'success_rate' => $totalRows > 0 ? round(($validRows / $totalRows) * 100, 2) : 0,
                'error_rate' => $totalRows > 0 ? round((($totalRows - $validRows) / $totalRows) * 100, 2) : 0
            ]
        ];

        // Add performance metrics
        $result['performance'] = [
            'processing_time_ms' => round($processingTime * 1000, 2),
            'records_per_second' => $processingTime > 0 ? round($totalRows / $processingTime, 2) : 0,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ];

        // Log validation completion
        Log::info('CSV validation completed', [
            'upload_id' => $this->validationContext['upload_id'],
            'client_id' => $this->validationContext['client_id'],
            'user_id' => $this->validationContext['user_id'],
            'is_valid' => $isValid,
            'total_records' => $totalRows,
            'valid_records' => $validRows,
            'error_count' => count($errors),
            'processing_time' => $processingTime
        ]);

        return $result;
    }

    /**
     * Parse numeric value from string, handling various formats.
     *
     * @param string $value
     * @return float|null
     */
    private function parseNumericValue(string $value): ?float
    {
        if (empty($value)) {
            return null;
        }

        // Remove currency symbols and extra spaces
        $cleaned = preg_replace('/[^\d.,\-]/', '', trim($value));
        
        if (empty($cleaned)) {
            return null;
        }

        // Handle different decimal separators
        if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') === false) {
            // Only comma present, treat as decimal separator
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) {
            // Both present, assume comma is thousands separator
            $cleaned = str_replace(',', '', $cleaned);
        }

        // Validate the result is numeric
        if (!is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /**
     * Get validation statistics for reporting.
     *
     * @return array<string, mixed>
     */
    public function getValidationStatistics(): array
    {
        $errorsByType = [];
        $errorsByField = [];
        $errorsByLine = [];

        foreach ($this->validationErrors as $error) {
            // Group by type
            $type = $error['type'] ?? 'unknown';
            $errorsByType[$type] = ($errorsByType[$type] ?? 0) + 1;

            // Group by field
            $field = $error['field'] ?? 'unknown';
            $errorsByField[$field] = ($errorsByField[$field] ?? 0) + 1;

            // Group by line
            $line = $error['line_number'] ?? 'unknown';
            $errorsByLine[$line] = ($errorsByLine[$line] ?? 0) + 1;
        }

        return [
            'total_errors' => count($this->validationErrors),
            'errors_by_type' => $errorsByType,
            'errors_by_field' => $errorsByField,
            'errors_by_line' => $errorsByLine,
            'most_common_error_type' => $this->getMostCommonValue($errorsByType),
            'most_problematic_field' => $this->getMostCommonValue($errorsByField),
            'validation_context' => $this->validationContext
        ];
    }

    /**
     * Get the most common value from an array.
     *
     * @param array<string, int> $values
     * @return string|null
     */
    private function getMostCommonValue(array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        arsort($values);
        return array_key_first($values);
    }

    /**
     * Get expected CSV headers.
     *
     * @return array<int, string>
     */
    public static function getExpectedHeaders(): array
    {
        return self::EXPECTED_CSV_HEADERS;
    }

    /**
     * Get required CSV columns.
     *
     * @return array<int, string>
     */
    public static function getRequiredColumns(): array
    {
        return self::REQUIRED_CSV_COLUMNS;
    }

    /**
     * Get field maximum lengths.
     *
     * @return array<string, int>
     */
    public static function getFieldMaxLengths(): array
    {
        return self::FIELD_MAX_LENGTHS;
    }

    /**
     * Get supported currencies.
     *
     * @return array<int, string>
     */
    public static function getSupportedCurrencies(): array
    {
        return self::SUPPORTED_CURRENCIES;
    }

    /**
     * Get validation constraints.
     *
     * @return array<string, mixed>
     */
    public static function getValidationConstraints(): array
    {
        return [
            'max_amount' => self::MAX_AMOUNT,
            'min_amount' => self::MIN_AMOUNT,
            'max_date_years_ago' => self::MAX_DATE_YEARS_AGO,
            'date_format' => self::DATE_FORMAT,
            'max_validation_errors' => self::MAX_VALIDATION_ERRORS,
            'expected_headers' => self::EXPECTED_CSV_HEADERS,
            'required_columns' => self::REQUIRED_CSV_COLUMNS,
            'field_max_lengths' => self::FIELD_MAX_LENGTHS,
            'supported_currencies' => self::SUPPORTED_CURRENCIES
        ];
    }
}