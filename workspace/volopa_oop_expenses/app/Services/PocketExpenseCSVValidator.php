<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\User;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PocketExpenseCSVValidator Service
 * 
 * Handles comprehensive validation of CSV files for pocket expense batch uploads.
 * Implements all-or-nothing validation strategy with reference data preloading
 * for performance optimization.
 * 
 * Validation includes:
 * - Header validation with exact column matching
 * - Row-level validation with business rules
 * - Reference data validation (users, expense types, sources)
 * - Date format and range validation
 * - Currency and amount validation
 * - Merchant name length constraints
 * - VAT percentage validation
 */
class PocketExpenseCSVValidator
{
    /**
     * Maximum number of rows allowed per CSV file.
     */
    private const MAX_ROWS = 200;

    /**
     * Maximum age in years for expense dates.
     */
    private const MAX_DATE_AGE_YEARS = 3;

    /**
     * Maximum length for merchant name field.
     */
    private const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Maximum length for notes field.
     */
    private const MAX_NOTES_LENGTH = 1000;

    /**
     * Expected CSV headers in exact order.
     */
    private const EXPECTED_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'Merchant Address',
        'VAT %',
        'Notes',
        'Source',
        'Source Note'
    ];

    /**
     * Valid currency codes (ISO 3-letter format).
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'KRW', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR'
    ];

    /**
     * Preloaded reference data for validation.
     */
    private array $referenceData = [];

    /**
     * Validation errors collected during processing.
     */
    private array $validationErrors = [];

    /**
     * Statistics tracking.
     */
    private int $totalRows = 0;
    private int $validRows = 0;
    private int $invalidRows = 0;

    /**
     * Validate a CSV file for pocket expense import.
     *
     * @param string $csvFilePath Path to the CSV file
     * @param int $targetUserId ID of the user for whom expenses will be created
     * @param int $clientId Client ID for multi-tenant scoping
     * @param int $adminId ID of the admin user performing the import
     * @return array Validation result with success status, errors, and statistics
     */
    public function validate(string $csvFilePath, int $targetUserId, int $clientId, int $adminId): array
    {
        Log::info('Starting CSV validation', [
            'file_path' => $csvFilePath,
            'target_user_id' => $targetUserId,
            'client_id' => $clientId,
            'admin_id' => $adminId
        ]);

        // Initialize validation state
        $this->resetValidationState();

        try {
            // Check if file exists and is readable
            if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
                throw new \InvalidArgumentException('CSV file not found or not readable: ' . $csvFilePath);
            }

            // Preload reference data for performance
            $this->preloadReferenceData($clientId);

            // Validate target user exists and belongs to client
            $this->validateTargetUser($targetUserId, $clientId);

            // Open and parse CSV file
            $handle = fopen($csvFilePath, 'r');
            if (!$handle) {
                throw new \RuntimeException('Failed to open CSV file: ' . $csvFilePath);
            }

            // Read and validate headers
            $headers = fgetcsv($handle);
            if (!$headers) {
                throw new \InvalidArgumentException('CSV file is empty or invalid');
            }

            $headerValidation = $this->validateHeaders($headers);
            if (!$headerValidation['valid']) {
                fclose($handle);
                return [
                    'success' => false,
                    'errors' => $headerValidation['errors'],
                    'statistics' => $this->getValidationStatistics()
                ];
            }

            // Process data rows
            $lineNumber = 2; // Start from line 2 (after header)
            while (($row = fgetcsv($handle)) !== false && $lineNumber <= self::MAX_ROWS + 1) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    $lineNumber++;
                    continue;
                }

                $this->totalRows++;
                $rowValidation = $this->validateRow($row, $lineNumber, $targetUserId, $clientId);
                
                if ($rowValidation['valid']) {
                    $this->validRows++;
                } else {
                    $this->invalidRows++;
                    $this->validationErrors = array_merge($this->validationErrors, $rowValidation['errors']);
                }

                $lineNumber++;
            }

            fclose($handle);

            // Check for too many rows
            if ($lineNumber > self::MAX_ROWS + 1) {
                $this->validationErrors[] = [
                    'line' => $lineNumber,
                    'field' => 'file',
                    'error' => 'CSV file exceeds maximum allowed rows (' . self::MAX_ROWS . ')'
                ];
                return [
                    'success' => false,
                    'errors' => $this->validationErrors,
                    'statistics' => $this->getValidationStatistics()
                ];
            }

            // Check if any data rows were found
            if ($this->totalRows === 0) {
                $this->validationErrors[] = [
                    'line' => 2,
                    'field' => 'file',
                    'error' => 'No data rows found in CSV file'
                ];
                return [
                    'success' => false,
                    'errors' => $this->validationErrors,
                    'statistics' => $this->getValidationStatistics()
                ];
            }

            // All-or-nothing validation: if any row fails, entire file fails
            $success = empty($this->validationErrors);

            Log::info('CSV validation completed', [
                'success' => $success,
                'total_rows' => $this->totalRows,
                'valid_rows' => $this->validRows,
                'invalid_rows' => $this->invalidRows,
                'error_count' => count($this->validationErrors)
            ]);

            return [
                'success' => $success,
                'errors' => $this->validationErrors,
                'statistics' => $this->getValidationStatistics()
            ];

        } catch (\Exception $e) {
            Log::error('CSV validation failed with exception', [
                'file_path' => $csvFilePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'errors' => [
                    [
                        'line' => 1,
                        'field' => 'file',
                        'error' => 'Validation failed: ' . $e->getMessage()
                    ]
                ],
                'statistics' => $this->getValidationStatistics()
            ];
        }
    }

    /**
     * Validate CSV headers against expected format.
     *
     * @param array $headers Headers from CSV file
     * @return array Validation result with errors if any
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];

        // Trim whitespace from headers
        $headers = array_map('trim', $headers);

        // Check header count
        if (count($headers) !== count(self::EXPECTED_HEADERS)) {
            $errors[] = [
                'line' => 1,
                'field' => 'headers',
                'error' => 'Invalid header count. Expected ' . count(self::EXPECTED_HEADERS) . ' columns, got ' . count($headers)
            ];
        }

        // Check each header matches expected value
        foreach (self::EXPECTED_HEADERS as $index => $expectedHeader) {
            if (!isset($headers[$index])) {
                $errors[] = [
                    'line' => 1,
                    'field' => 'header_' . $index,
                    'error' => 'Missing header at position ' . ($index + 1) . ': expected "' . $expectedHeader . '"'
                ];
            } elseif ($headers[$index] !== $expectedHeader) {
                $errors[] = [
                    'line' => 1,
                    'field' => 'header_' . $index,
                    'error' => 'Invalid header at position ' . ($index + 1) . ': expected "' . $expectedHeader . '", got "' . $headers[$index] . '"'
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate a single CSV row.
     *
     * @param array $row CSV row data
     * @param int $lineNumber Line number in the file
     * @param int $targetUserId Target user ID for the expense
     * @param int $clientId Client ID for scoping
     * @return array Validation result with errors if any
     */
    public function validateRow(array $row, int $lineNumber, int $targetUserId, int $clientId): array
    {
        $errors = [];

        // Ensure row has correct number of columns
        if (count($row) !== count(self::EXPECTED_HEADERS)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'row',
                'error' => 'Row has ' . count($row) . ' columns, expected ' . count(self::EXPECTED_HEADERS)
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Map row data to named fields
        $data = array_combine(self::EXPECTED_HEADERS, $row);
        $data = array_map('trim', $data);

        // Validate individual fields
        $errors = array_merge($errors, $this->validateDateField($data['Date'], $lineNumber));
        $errors = array_merge($errors, $this->validateMerchantName($data['Merchant Name'], $lineNumber));
        $errors = array_merge($errors, $this->validateExpenseType($data['Expense Type'], $lineNumber));
        $errors = array_merge($errors, $this->validateCurrency($data['Currency'], $lineNumber));
        $errors = array_merge($errors, $this->validateAmount($data['Amount'], $lineNumber));
        $errors = array_merge($errors, $this->validateVatPercentage($data['VAT %'], $lineNumber));
        $errors = array_merge($errors, $this->validateNotes($data['Notes'], $lineNumber));
        $errors = array_merge($errors, $this->validateSource($data['Source'], $data['Source Note'], $lineNumber, $clientId));

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Preload reference data for validation performance.
     *
     * @param int $clientId Client ID for scoping
     * @return void
     */
    public function preloadReferenceData(int $clientId): void
    {
        Log::debug('Preloading reference data', ['client_id' => $clientId]);

        // Load expense types
        $this->referenceData['expense_types'] = OptPocketExpenseType::all()
            ->keyBy('option')
            ->toArray();

        // Load expense sources for client (including global sources)
        $this->referenceData['expense_sources'] = PocketExpenseSourceClientConfig::getAvailableForClient($clientId)
            ->keyBy('name')
            ->toArray();

        // Load valid users for this client
        $this->referenceData['client_users'] = User::where('client_id', $clientId)
            ->orWhereHas('clients', function ($query) use ($clientId) {
                $query->where('client_id', $clientId);
            })
            ->pluck('id', 'email')
            ->toArray();

        Log::debug('Reference data preloaded', [
            'expense_types_count' => count($this->referenceData['expense_types']),
            'expense_sources_count' => count($this->referenceData['expense_sources']),
            'client_users_count' => count($this->referenceData['client_users'])
        ]);
    }

    /**
     * Validate target user exists and belongs to client.
     *
     * @param int $targetUserId Target user ID
     * @param int $clientId Client ID
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateTargetUser(int $targetUserId, int $clientId): void
    {
        $user = User::find($targetUserId);
        if (!$user) {
            throw new \InvalidArgumentException('Target user not found: ' . $targetUserId);
        }

        // Check if user belongs to client (either directly or through association)
        $belongsToClient = $user->client_id === $clientId || 
                          $user->clients()->where('client_id', $clientId)->exists();

        if (!$belongsToClient) {
            throw new \InvalidArgumentException('Target user does not belong to specified client');
        }
    }

    /**
     * Validate date field format and range.
     *
     * @param string $date Date string from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateDateField(string $date, int $lineNumber): array
    {
        $errors = [];

        if (empty($date)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Date',
                'error' => 'Date is required'
            ];
            return $errors;
        }

        // Try to parse DD/MM/YYYY format
        try {
            $parsedDate = Carbon::createFromFormat('d/m/Y', $date);
            
            // Validate date is not too old
            $maxAgeDate = now()->subYears(self::MAX_DATE_AGE_YEARS);
            if ($parsedDate->lt($maxAgeDate)) {
                $errors[] = [
                    'line' => $lineNumber,
                    'field' => 'Date',
                    'error' => 'Date cannot be older than ' . self::MAX_DATE_AGE_YEARS . ' years'
                ];
            }

            // Validate date is not in the future
            if ($parsedDate->gt(now())) {
                $errors[] = [
                    'line' => $lineNumber,
                    'field' => 'Date',
                    'error' => 'Date cannot be in the future'
                ];
            }

        } catch (\Exception $e) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Date',
                'error' => 'Invalid date format. Expected DD/MM/YYYY, got: ' . $date
            ];
        }

        return $errors;
    }

    /**
     * Validate merchant name field.
     *
     * @param string $merchantName Merchant name from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateMerchantName(string $merchantName, int $lineNumber): array
    {
        $errors = [];

        if (empty($merchantName)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name is required'
            ];
            return $errors;
        }

        if (strlen($merchantName) > self::MAX_MERCHANT_NAME_LENGTH) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name cannot exceed ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters'
            ];
        }

        return $errors;
    }

    /**
     * Validate expense type field.
     *
     * @param string $expenseType Expense type from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateExpenseType(string $expenseType, int $lineNumber): array
    {
        $errors = [];

        // Expense type is optional
        if (empty($expenseType)) {
            return $errors;
        }

        if (!isset($this->referenceData['expense_types'][$expenseType])) {
            $availableTypes = implode(', ', array_keys($this->referenceData['expense_types']));
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Expense Type',
                'error' => 'Invalid expense type "' . $expenseType . '". Available types: ' . $availableTypes
            ];
        }

        return $errors;
    }

    /**
     * Validate currency field.
     *
     * @param string $currency Currency code from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateCurrency(string $currency, int $lineNumber): array
    {
        $errors = [];

        if (empty($currency)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Currency',
                'error' => 'Currency is required'
            ];
            return $errors;
        }

        // Convert to uppercase for validation
        $currency = strtoupper($currency);

        // Check 3-letter ISO format
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Currency',
                'error' => 'Currency must be a 3-letter ISO code'
            ];
            return $errors;
        }

        // Check against valid currency list
        if (!in_array($currency, self::VALID_CURRENCIES)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Currency',
                'error' => 'Unsupported currency code: ' . $currency
            ];
        }

        return $errors;
    }

    /**
     * Validate amount field.
     *
     * @param string $amount Amount value from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateAmount(string $amount, int $lineNumber): array
    {
        $errors = [];

        if (empty($amount)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount is required'
            ];
            return $errors;
        }

        // Remove any currency symbols and commas
        $cleanAmount = preg_replace('/[^\d.-]/', '', $amount);

        if (!is_numeric($cleanAmount)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount must be a valid numeric value'
            ];
            return $errors;
        }

        $numericAmount = (float) $cleanAmount;

        if ($numericAmount <= 0) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount must be greater than zero'
            ];
        }

        // Check for reasonable maximum (prevent data entry errors)
        if ($numericAmount > 999999.99) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount exceeds maximum allowed value'
            ];
        }

        return $errors;
    }

    /**
     * Validate VAT percentage field.
     *
     * @param string $vatPercentage VAT percentage from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateVatPercentage(string $vatPercentage, int $lineNumber): array
    {
        $errors = [];

        // VAT is optional
        if (empty($vatPercentage)) {
            return $errors;
        }

        // Remove % sign if present
        $cleanVat = str_replace('%', '', trim($vatPercentage));

        if (!is_numeric($cleanVat)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'VAT %',
                'error' => 'VAT percentage must be a valid numeric value'
            ];
            return $errors;
        }

        $numericVat = (float) $cleanVat;

        if ($numericVat < 0 || $numericVat > 100) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'VAT %',
                'error' => 'VAT percentage must be between 0 and 100'
            ];
        }

        return $errors;
    }

    /**
     * Validate notes field.
     *
     * @param string $notes Notes from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation errors
     */
    private function validateNotes(string $notes, int $lineNumber): array
    {
        $errors = [];

        // Notes are optional
        if (empty($notes)) {
            return $errors;
        }

        if (strlen($notes) > self::MAX_NOTES_LENGTH) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Notes',
                'error' => 'Notes cannot exceed ' . self::MAX_NOTES_LENGTH . ' characters'
            ];
        }

        return $errors;
    }

    /**
     * Validate source and source note fields.
     *
     * @param string $source Source name from CSV
     * @param string $sourceNote Source note from CSV
     * @param int $lineNumber Line number for error reporting
     * @param int $clientId Client ID for source validation
     * @return array Validation errors
     */
    private function validateSource(string $source, string $sourceNote, int $lineNumber, int $clientId): array
    {
        $errors = [];

        // Source is optional
        if (empty($source)) {
            return $errors;
        }

        // Check if source exists in reference data
        if (!isset($this->referenceData['expense_sources'][$source])) {
            $availableSources = implode(', ', array_keys($this->referenceData['expense_sources']));
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Source',
                'error' => 'Invalid source "' . $source . '". Available sources: ' . $availableSources
            ];
        }

        // If source is "Other", source note is required
        if (strtolower($source) === 'other' && empty($sourceNote)) {
            $errors[] = [
                'line' => $lineNumber,
                'field' => 'Source Note',
                'error' => 'Source Note is required when Source is "Other"'
            ];
        }

        return $errors;
    }

    /**
     * Reset validation state for new validation run.
     *
     * @return void
     */
    private function resetValidationState(): void
    {
        $this->validationErrors = [];
        $this->referenceData = [];
        $this->totalRows = 0;
        $this->validRows = 0;
        $this->invalidRows = 0;
    }

    /**
     * Get validation statistics.
     *
     * @return array Statistics summary
     */
    private function getValidationStatistics(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'valid_rows' => $this->validRows,
            'invalid_rows' => $this->invalidRows,
            'error_count' => count($this->validationErrors)
        ];
    }

    /**
     * Get expected CSV headers for reference.
     *
     * @return array Expected headers
     */
    public static function getExpectedHeaders(): array
    {
        return self::EXPECTED_HEADERS;
    }

    /**
     * Get valid currency codes for reference.
     *
     * @return array Valid currency codes
     */
    public static function getValidCurrencies(): array
    {
        return self::VALID_CURRENCIES;
    }

    /**
     * Get maximum allowed rows per CSV file.
     *
     * @return int Maximum rows
     */
    public static function getMaxRows(): int
    {
        return self::MAX_ROWS;
    }

    /**
     * Create validation error array in standardized format.
     *
     * @param int $line Line number
     * @param string $field Field name
     * @param string $error Error message
     * @return array Formatted error
     */
    private function createError(int $line, string $field, string $error): array
    {
        return [
            'line' => $line,
            'field' => $field,
            'error' => $error
        ];
    }

    /**
     * Parse and clean amount value from CSV.
     *
     * @param string $amount Raw amount value
     * @return float|null Parsed amount or null if invalid
     */
    private function parseAmount(string $amount): ?float
    {
        if (empty($amount)) {
            return null;
        }

        // Remove currency symbols, commas, and extra whitespace
        $cleanAmount = preg_replace('/[^\d.-]/', '', trim($amount));

        if (!is_numeric($cleanAmount)) {
            return null;
        }

        return (float) $cleanAmount;
    }

    /**
     * Parse and clean VAT percentage from CSV.
     *
     * @param string $vatPercentage Raw VAT percentage
     * @return float|null Parsed VAT percentage or null if invalid/empty
     */
    private function parseVatPercentage(string $vatPercentage): ?float
    {
        if (empty($vatPercentage)) {
            return null;
        }

        // Remove % sign and whitespace
        $cleanVat = str_replace('%', '', trim($vatPercentage));

        if (!is_numeric($cleanVat)) {
            return null;
        }

        return (float) $cleanVat;
    }

    /**
     * Parse date from DD/MM/YYYY format.
     *
     * @param string $date Date string
     * @return Carbon|null Parsed date or null if invalid
     */
    private function parseDate(string $date): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', trim($date));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if a string represents a valid expense type.
     *
     * @param string $expenseType Expense type name
     * @return bool True if valid
     */
    private function isValidExpenseType(string $expenseType): bool
    {
        return isset($this->referenceData['expense_types'][$expenseType]);
    }

    /**
     * Check if a string represents a valid expense source.
     *
     * @param string $source Source name
     * @return bool True if valid
     */
    private function isValidExpenseSource(string $source): bool
    {
        return isset($this->referenceData['expense_sources'][$source]);
    }

    /**
     * Get expense type ID by name.
     *
     * @param string $expenseTypeName Expense type name
     * @return int|null Expense type ID or null if not found
     */
    private function getExpenseTypeId(string $expenseTypeName): ?int
    {
        return $this->referenceData['expense_types'][$expenseTypeName]['id'] ?? null;
    }

    /**
     * Get expense source ID by name.
     *
     * @param string $sourceName Source name
     * @return int|null Source ID or null if not found
     */
    private function getExpenseSourceId(string $sourceName): ?int
    {
        return $this->referenceData['expense_sources'][$sourceName]['id'] ?? null;
    }
}