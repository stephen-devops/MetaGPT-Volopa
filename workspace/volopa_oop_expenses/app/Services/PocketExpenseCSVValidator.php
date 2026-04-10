<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PocketExpenseCSVValidator Service
 * 
 * Handles validation of CSV files for pocket expense uploads.
 * Implements all-or-nothing validation where if any row fails, no expenses are created.
 * Preloads reference data for performance and validates against platform constraints.
 */
class PocketExpenseCSVValidator
{
    /**
     * Expected CSV column headers in exact order
     */
    private const EXPECTED_HEADERS = [
        'Date',
        'Expense Type',
        'Currency Code',
        'Amount',
        '{Currency} Equivalent Amount',
        'VAT %',
        'Merchant Name',
        'Description',
        'Merchant Address',
        'Merchant Country',
        'Source',
        'Source Note',
        'Notes'
    ];

    /**
     * Maximum allowed CSV file rows (excluding header)
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Maximum age of expenses in years
     */
    private const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Maximum merchant name length as per DB constraint
     */
    private const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Allowed currency codes (3-letter ISO codes)
     */
    private const ALLOWED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'HUF', 'BGN', 'RON', 'HRK', 'RSD', 'BAM', 'MKD', 'ALL',
        'TRY', 'RUB', 'UAH', 'BYN', 'MDL', 'GEL', 'AZN', 'AMD', 'KZT', 'UZS',
        'KGS', 'TJS', 'TMT', 'MNT', 'CNY', 'HKD', 'TWD', 'KRW', 'THB', 'VND',
        'IDR', 'MYR', 'SGD', 'PHP', 'INR', 'PKR', 'BDT', 'LKR', 'NPR', 'BTN',
        'MVR', 'AFN', 'IRR', 'IQD', 'JOD', 'KWD', 'LBP', 'OMR', 'QAR', 'SAR',
        'AED', 'BHD', 'YER', 'SYP', 'ILS', 'EGP', 'LYD', 'TND', 'DZD', 'MAD',
        'CDF', 'AO', 'BWP', 'SZL', 'LSL', 'ZAR', 'NAD', 'MWK', 'ZMW', 'MZN',
        'MGF', 'KMF', 'SCR', 'MUR', 'XOF', 'XAF', 'GHS', 'NGN', 'SLL', 'GMD',
        'GNF', 'LRD', 'CIV', 'BIF', 'RWF', 'ETB', 'KES', 'UGX', 'TZS', 'SOS',
        'DJF', 'ERN', 'SDG', 'SSP', 'XDR'
    ];

    /**
     * Preloaded reference data for validation
     */
    private array $expenseTypes = [];
    private array $clientSources = [];
    private array $validationErrors = [];
    private array $validRows = [];
    private int $clientId;
    private int $targetUserId;
    private int $adminId;

    /**
     * Initialize validator with context
     */
    public function __construct()
    {
        $this->validationErrors = [];
        $this->validRows = [];
        $this->clientId = 0;
        $this->targetUserId = 0;
        $this->adminId = 0;
    }

    /**
     * Validate CSV file for pocket expense upload
     *
     * @param string $filePath Path to the uploaded CSV file
     * @param int $targetUserId User for whom expenses will be created
     * @param int $clientId Client context for validation
     * @param int $adminId Admin user performing the upload
     * @return array Validation result with success status and details
     */
    public function validate(string $filePath, int $targetUserId, int $clientId, int $adminId): array
    {
        $this->clientId = $clientId;
        $this->targetUserId = $targetUserId;
        $this->adminId = $adminId;
        $this->validationErrors = [];
        $this->validRows = [];

        try {
            // Check if file exists
            if (!Storage::exists($filePath)) {
                return [
                    'success' => false,
                    'message' => 'CSV file not found',
                    'errors' => [['line_number' => 0, 'field' => 'file', 'error' => 'File not found', 'value' => $filePath]]
                ];
            }

            // Preload reference data for validation
            $this->preloadReferenceData($clientId);

            // Read and parse CSV file
            $csvContent = Storage::get($filePath);
            $csvLines = array_map('str_getcsv', explode("\n", trim($csvContent)));

            // Validate file structure
            if (count($csvLines) === 0) {
                return [
                    'success' => false,
                    'message' => 'CSV file is empty',
                    'errors' => [['line_number' => 0, 'field' => 'file', 'error' => 'Empty file', 'value' => '']]
                ];
            }

            // Check maximum rows constraint (excluding header)
            if (count($csvLines) > self::MAX_CSV_ROWS + 1) {
                return [
                    'success' => false,
                    'message' => 'CSV file exceeds maximum allowed rows',
                    'errors' => [['line_number' => 0, 'field' => 'file', 'error' => 'Exceeds maximum ' . self::MAX_CSV_ROWS . ' rows', 'value' => (string)(count($csvLines) - 1)]]
                ];
            }

            // Validate headers
            $headers = $csvLines[0] ?? [];
            $headerValidation = $this->validateHeaders($headers);
            if (!$headerValidation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Invalid CSV headers',
                    'errors' => $headerValidation['errors']
                ];
            }

            // Validate each data row
            for ($i = 1; $i < count($csvLines); $i++) {
                $lineNumber = $i + 1; // Line numbers start from 1, including header
                $rowData = $csvLines[$i];
                
                // Skip empty rows
                if (empty(array_filter($rowData))) {
                    continue;
                }
                
                $rowErrors = $this->validateRow($rowData, $lineNumber);
                if (!empty($rowErrors)) {
                    $this->validationErrors = array_merge($this->validationErrors, $rowErrors);
                } else {
                    $this->validRows[] = $this->parseValidRow($rowData, $lineNumber);
                }
            }

            // All-or-nothing validation: if any row has errors, entire upload fails
            if (!empty($this->validationErrors)) {
                return [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $this->validationErrors,
                    'error_count' => count($this->validationErrors),
                    'total_rows' => count($csvLines) - 1
                ];
            }

            return [
                'success' => true,
                'message' => 'Validation successful',
                'valid_rows' => $this->validRows,
                'total_rows' => count($csvLines) - 1
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error processing CSV file: ' . $e->getMessage(),
                'errors' => [['line_number' => 0, 'field' => 'file', 'error' => $e->getMessage(), 'value' => '']]
            ];
        }
    }

    /**
     * Validate CSV headers against expected format
     *
     * @param array $headers CSV header row
     * @return array Validation result
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];
        
        // Check if we have the expected number of columns
        if (count($headers) !== count(self::EXPECTED_HEADERS)) {
            $errors[] = [
                'line_number' => 1,
                'field' => 'headers',
                'error' => 'Expected ' . count(self::EXPECTED_HEADERS) . ' columns, got ' . count($headers),
                'value' => implode(',', $headers)
            ];
        }

        // Check each header matches expected name exactly
        for ($i = 0; $i < min(count($headers), count(self::EXPECTED_HEADERS)); $i++) {
            $expectedHeader = self::EXPECTED_HEADERS[$i];
            $actualHeader = trim($headers[$i] ?? '');
            
            // Special handling for currency equivalent column which varies by currency
            if ($i === 4 && preg_match('/^[A-Z]{3} Equivalent Amount$/', $actualHeader)) {
                continue; // Valid currency equivalent column
            }
            
            if ($actualHeader !== $expectedHeader) {
                $errors[] = [
                    'line_number' => 1,
                    'field' => 'header_' . ($i + 1),
                    'error' => "Expected '$expectedHeader', got '$actualHeader'",
                    'value' => $actualHeader
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate a single CSV row
     *
     * @param array $row CSV row data
     * @param int $lineNumber Line number for error reporting
     * @return array Array of validation errors for this row
     */
    public function validateRow(array $row, int $lineNumber): array
    {
        $errors = [];
        
        // Ensure we have enough columns
        while (count($row) < count(self::EXPECTED_HEADERS)) {
            $row[] = '';
        }

        // Validate Date (column 0) - required
        $date = trim($row[0] ?? '');
        if (empty($date)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Date',
                'error' => 'Date is required',
                'value' => $date
            ];
        } else {
            $dateValidation = $this->validateDate($date);
            if (!$dateValidation['valid']) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'error' => $dateValidation['error'],
                    'value' => $date
                ];
            }
        }

        // Validate Expense Type (column 1) - required
        $expenseType = trim($row[1] ?? '');
        if (empty($expenseType)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Expense Type',
                'error' => 'Expense Type is required',
                'value' => $expenseType
            ];
        } else {
            if (!isset($this->expenseTypes[$expenseType])) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Expense Type',
                    'error' => 'Invalid expense type',
                    'value' => $expenseType
                ];
            }
        }

        // Validate Currency Code (column 2) - required
        $currency = trim($row[2] ?? '');
        if (empty($currency)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Code',
                'error' => 'Currency Code is required',
                'value' => $currency
            ];
        } else {
            if (!in_array(strtoupper($currency), self::ALLOWED_CURRENCIES)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Currency Code',
                    'error' => 'Invalid currency code',
                    'value' => $currency
                ];
            }
        }

        // Validate Amount (column 3) - required
        $amount = trim($row[3] ?? '');
        if (empty($amount)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount is required',
                'value' => $amount
            ];
        } else {
            if (!is_numeric($amount)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Amount',
                    'error' => 'Amount must be numeric',
                    'value' => $amount
                ];
            } elseif ((float)$amount <= 0) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Amount',
                    'error' => 'Amount must be greater than zero',
                    'value' => $amount
                ];
            }
        }

        // Validate VAT % (column 5) - optional
        $vat = trim($row[5] ?? '');
        if (!empty($vat)) {
            $vatValidation = $this->validateVat($vat);
            if (!$vatValidation['valid']) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'VAT %',
                    'error' => $vatValidation['error'],
                    'value' => $vat
                ];
            }
        }

        // Validate Merchant Name (column 6) - required
        $merchantName = trim($row[6] ?? '');
        if (empty($merchantName)) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name is required',
                'value' => $merchantName
            ];
        } elseif (strlen($merchantName) > self::MAX_MERCHANT_NAME_LENGTH) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name exceeds maximum length of ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters',
                'value' => $merchantName
            ];
        }

        // Validate Source (column 10) - optional but if provided must be valid
        $source = trim($row[10] ?? '');
        $sourceNote = trim($row[11] ?? '');
        
        if (!empty($source)) {
            if (!in_array($source, $this->clientSources)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Source',
                    'error' => 'Invalid expense source',
                    'value' => $source
                ];
            }
            
            // If source is "Other", Source Note is required
            if ($source === 'Other' && empty($sourceNote)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Source Note',
                    'error' => 'Source Note is required when Source is Other',
                    'value' => $sourceNote
                ];
            }
        }

        // Validate Notes (column 12) - optional, check for SQL injection patterns
        $notes = trim($row[12] ?? '');
        if (!empty($notes)) {
            $notesValidation = $this->validateNotes($notes);
            if (!$notesValidation['valid']) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Notes',
                    'error' => $notesValidation['error'],
                    'value' => $notes
                ];
            }
        }

        return $errors;
    }

    /**
     * Preload reference data for validation
     *
     * @param int $clientId Client ID for scoping reference data
     * @return void
     */
    public function preloadReferenceData(int $clientId): void
    {
        // Load expense types
        $expenseTypeRecords = OptPocketExpenseType::all();
        $this->expenseTypes = [];
        foreach ($expenseTypeRecords as $type) {
            $this->expenseTypes[$type->option] = $type;
        }

        // Load client expense sources (including global "Other")
        $clientSourceRecords = PocketExpenseSourceClientConfig::active()
            ->where(function ($query) use ($clientId) {
                $query->where('client_id', $clientId)
                      ->orWhereNull('client_id'); // Include global sources
            })
            ->get();
            
        $this->clientSources = $clientSourceRecords->pluck('name')->toArray();
    }

    /**
     * Get validation errors
     *
     * @return array Array of validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get valid rows
     *
     * @return array Array of valid parsed rows
     */
    public function getValidRows(): array
    {
        return $this->validRows;
    }

    /**
     * Validate date format and constraints
     *
     * @param string $date Date string to validate
     * @return array Validation result
     */
    private function validateDate(string $date): array
    {
        try {
            // Parse date in DD/MM/YYYY format
            $parsedDate = Carbon::createFromFormat('d/m/Y', $date);
            
            if (!$parsedDate) {
                return [
                    'valid' => false,
                    'error' => 'Date format must be DD/MM/YYYY'
                ];
            }

            // Check if date is not older than 3 years
            $threeYearsAgo = Carbon::now()->subYears(self::MAX_EXPENSE_AGE_YEARS);
            if ($parsedDate->lt($threeYearsAgo)) {
                return [
                    'valid' => false,
                    'error' => 'Date cannot be older than 3 years'
                ];
            }

            // Check if date is not in the future
            if ($parsedDate->gt(Carbon::now())) {
                return [
                    'valid' => false,
                    'error' => 'Date cannot be in the future'
                ];
            }

            return ['valid' => true];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => 'Invalid date format. Expected DD/MM/YYYY'
            ];
        }
    }

    /**
     * Validate VAT percentage
     *
     * @param string $vat VAT string to validate
     * @return array Validation result
     */
    private function validateVat(string $vat): array
    {
        // Strip % sign if present
        $vatValue = str_replace('%', '', $vat);
        
        if (!is_numeric($vatValue)) {
            return [
                'valid' => false,
                'error' => 'VAT must be numeric'
            ];
        }

        $vatNumeric = (float)$vatValue;
        if ($vatNumeric < 0 || $vatNumeric > 100) {
            return [
                'valid' => false,
                'error' => 'VAT must be between 0 and 100'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Validate notes field for SQL injection and length
     *
     * @param string $notes Notes string to validate
     * @return array Validation result
     */
    private function validateNotes(string $notes): array
    {
        // Basic SQL injection pattern detection
        $suspiciousPatterns = [
            '/(\b(select|insert|update|delete|drop|create|alter|exec|execute|union|script)\b)/i',
            '/[\'";].*(--)/',
            '/\/\*.*\*\//',
            '/\bor\b.*\d+.*=.*\d+/i',
            '/\band\b.*\d+.*=.*\d+/i'
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $notes)) {
                return [
                    'valid' => false,
                    'error' => 'Notes contain potentially harmful content'
                ];
            }
        }

        // Check reasonable length limit (65535 for TEXT field)
        if (strlen($notes) > 65535) {
            return [
                'valid' => false,
                'error' => 'Notes exceed maximum length'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Parse a valid CSV row into structured expense data
     *
     * @param array $row CSV row data
     * @param int $lineNumber Line number for reference
     * @return array Structured expense data
     */
    private function parseValidRow(array $row, int $lineNumber): array
    {
        // Ensure we have enough columns
        while (count($row) < count(self::EXPECTED_HEADERS)) {
            $row[] = '';
        }

        $expenseType = trim($row[1]);
        $currency = strtoupper(trim($row[2]));
        $amount = (float)trim($row[3]);
        
        // Apply amount sign based on expense type
        $expenseTypeRecord = $this->expenseTypes[$expenseType] ?? null;
        if ($expenseTypeRecord && $expenseTypeRecord->amount_sign === 'negative') {
            $amount = -abs($amount);
        } else {
            $amount = abs($amount);
        }

        // Parse VAT percentage (strip % sign)
        $vatPercent = trim($row[5] ?? '');
        $vatAmount = null;
        if (!empty($vatPercent)) {
            $vatAmount = (float)str_replace('%', '', $vatPercent);
        }

        return [
            'line_number' => $lineNumber,
            'date' => Carbon::createFromFormat('d/m/Y', trim($row[0]))->format('Y-m-d'),
            'expense_type' => $expenseType,
            'currency' => $currency,
            'amount' => $amount,
            'equivalent_amount' => !empty(trim($row[4])) ? (float)trim($row[4]) : null,
            'vat_amount' => $vatAmount,
            'merchant_name' => trim($row[6]),
            'merchant_description' => !empty(trim($row[7])) ? trim($row[7]) : null,
            'merchant_address' => !empty(trim($row[8])) ? trim($row[8]) : null,
            'merchant_country' => !empty(trim($row[9])) ? trim($row[9]) : null,
            'source' => !empty(trim($row[10])) ? trim($row[10]) : null,
            'source_note' => !empty(trim($row[11])) ? trim($row[11]) : null,
            'notes' => !empty(trim($row[12])) ? trim($row[12]) : null,
            'user_id' => $this->targetUserId,
            'client_id' => $this->clientId,
            'created_by_user_id' => $this->adminId,
            'status' => 'submitted'
        ];
    }
}