## Code: app/Services/CsvValidationService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;

class CsvValidationService
{
    /**
     * Required CSV headers for mass payment files.
     */
    private const REQUIRED_HEADERS = [
        'beneficiary_name',
        'amount',
        'currency',
        'account_number',
        'sort_code',
        'reference'
    ];

    /**
     * Optional CSV headers that may be present.
     */
    private const OPTIONAL_HEADERS = [
        'purpose_code',
        'bank_name',
        'swift_code',
        'iban',
        'beneficiary_email',
        'beneficiary_phone',
        'additional_reference',
        'payment_date'
    ];

    /**
     * Currency-specific validation rules.
     */
    private const CURRENCY_RULES = [
        'GBP' => [
            'min_amount' => 1.00,
            'max_amount' => 50000.00,
            'required_fields' => ['account_number', 'sort_code'],
            'purpose_codes' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'DIVIDEND', 'OTHER']
        ],
        'EUR' => [
            'min_amount' => 1.00,
            'max_amount' => 50000.00,
            'required_fields' => ['iban', 'swift_code'],
            'purpose_codes' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'DIVIDEND', 'OTHER']
        ],
        'USD' => [
            'min_amount' => 1.00,
            'max_amount' => 50000.00,
            'required_fields' => ['account_number', 'swift_code'],
            'purpose_codes' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'DIVIDEND', 'OTHER']
        ],
        'INR' => [
            'min_amount' => 100.00,
            'max_amount' => 5000000.00,
            'required_fields' => ['account_number', 'swift_code'],
            'purpose_codes' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'OTHER'],
            'required_purpose_code' => true
        ]
    ];

    /**
     * Maximum allowed file size in bytes (10MB).
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum allowed rows in CSV file.
     */
    private const MAX_ROWS = 10000;

    /**
     * Validate CSV file structure and headers.
     *
     * @param string $filePath Path to the CSV file
     * @return array Validation result with structure status and errors
     */
    public function validateCsvStructure(string $filePath): array
    {
        $errors = [];
        $warnings = [];
        
        try {
            // Check if file exists and is readable
            if (!file_exists($filePath)) {
                return [
                    'valid' => false,
                    'errors' => ['File does not exist or is not accessible'],
                    'warnings' => [],
                    'headers' => [],
                    'row_count' => 0
                ];
            }

            if (!is_readable($filePath)) {
                return [
                    'valid' => false,
                    'errors' => ['File is not readable'],
                    'warnings' => [],
                    'headers' => [],
                    'row_count' => 0
                ];
            }

            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
                $errors[] = 'File size exceeds maximum allowed size of 10MB';
            }

            if ($fileSize === 0) {
                return [
                    'valid' => false,
                    'errors' => ['File is empty'],
                    'warnings' => [],
                    'headers' => [],
                    'row_count' => 0
                ];
            }

            // Initialize CSV reader
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);

            // Get headers
            $headers = $csv->getHeader();
            
            if (empty($headers)) {
                return [
                    'valid' => false,
                    'errors' => ['No headers found in CSV file'],
                    'warnings' => [],
                    'headers' => [],
                    'row_count' => 0
                ];
            }

            // Normalize headers (lowercase, trim spaces)
            $normalizedHeaders = array_map(function($header) {
                return strtolower(trim($header));
            }, $headers);

            // Check for required headers
            $missingHeaders = array_diff(self::REQUIRED_HEADERS, $normalizedHeaders);
            if (!empty($missingHeaders)) {
                $errors[] = 'Missing required headers: ' . implode(', ', $missingHeaders);
            }

            // Check for duplicate headers
            $duplicateHeaders = array_diff_assoc($normalizedHeaders, array_unique($normalizedHeaders));
            if (!empty($duplicateHeaders)) {
                $errors[] = 'Duplicate headers found: ' . implode(', ', array_unique($duplicateHeaders));
            }

            // Check for empty headers
            $emptyHeaders = array_filter($normalizedHeaders, function($header) {
                return empty(trim($header));
            });
            if (!empty($emptyHeaders)) {
                $errors[] = 'Empty header columns found';
            }

            // Count data rows
            $rowCount = 0;
            $records = $csv->getRecords();
            foreach ($records as $record) {
                $rowCount++;
                if ($rowCount > self::MAX_ROWS) {
                    $errors[] = 'File exceeds maximum allowed rows of ' . number_format(self::MAX_ROWS);
                    break;
                }
            }

            if ($rowCount === 0) {
                $errors[] = 'No data rows found in CSV file';
            }

            // Check for suspicious patterns
            if ($rowCount > 1000) {
                $warnings[] = 'Large file detected (' . number_format($rowCount) . ' rows). Processing may take longer.';
            }

            // Warn about unrecognized headers
            $recognizedHeaders = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);
            $unrecognizedHeaders = array_diff($normalizedHeaders, $recognizedHeaders);
            if (!empty($unrecognizedHeaders)) {
                $warnings[] = 'Unrecognized headers found (will be ignored): ' . implode(', ', $unrecognizedHeaders);
            }

            Log::info('CSV structure validation completed', [
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'row_count' => $rowCount,
                'headers_count' => count($headers),
                'errors_count' => count($errors),
                'warnings_count' => count($warnings)
            ]);

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'headers' => $normalizedHeaders,
                'row_count' => $rowCount,
                'file_size' => $fileSize
            ];

        } catch (CsvException $e) {
            Log::error('CSV parsing error during structure validation', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'errors' => ['Invalid CSV format: ' . $e->getMessage()],
                'warnings' => [],
                'headers' => [],
                'row_count' => 0
            ];

        } catch (\Exception $e) {
            Log::error('Unexpected error during CSV structure validation', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'errors' => ['Unexpected error while validating file: ' . $e->getMessage()],
                'warnings' => [],
                'headers' => [],
                'row_count' => 0
            ];
        }
    }

    /**
     * Validate individual row data against business rules.
     *
     * @param array $row CSV row data
     * @param int $rowNumber Row number for error reporting
     * @return array Validation result with errors and warnings
     */
    public function validateRowData(array $row, int $rowNumber): array
    {
        $errors = [];
        $warnings = [];

        try {
            // Normalize row keys (lowercase, trim)
            $normalizedRow = [];
            foreach ($row as $key => $value) {
                $normalizedKey = strtolower(trim($key));
                $normalizedRow[$normalizedKey] = is_string($value) ? trim($value) : $value;
            }

            // Validate required fields
            $this->validateRequiredFields($normalizedRow, $rowNumber, $errors);

            // Validate amount format and value
            $this->validateAmount($normalizedRow, $rowNumber, $errors);

            // Validate currency
            $currency = $this->validateCurrency($normalizedRow, $rowNumber, $errors);

            // Currency-specific validations
            if ($currency && isset(self::CURRENCY_RULES[$currency])) {
                $this->validateCurrencySpecificFields($normalizedRow, $currency, $rowNumber, $errors);
            }

            // Validate beneficiary name
            $this->validateBeneficiaryName($normalizedRow, $rowNumber, $errors);

            // Validate account details
            $this->validateAccountDetails($normalizedRow, $rowNumber, $errors, $warnings);

            // Validate purpose code if present
            $this->validatePurposeCode($normalizedRow, $currency, $rowNumber, $errors);

            // Validate reference field
            $this->validateReference($normalizedRow, $rowNumber, $errors, $warnings);

            // Validate optional fields
            $this->validateOptionalFields($normalizedRow, $rowNumber, $warnings);

            Log::debug('Row validation completed', [
                'row_number' => $rowNumber,
                'errors_count' => count($errors),
                'warnings_count' => count($warnings)
            ]);

        } catch (\Exception $e) {
            Log::error('Unexpected error during row validation', [
                'row_number' => $rowNumber,
                'error' => $e->getMessage()
            ]);

            $errors[] = 'Unexpected error while validating row: ' . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'row_number' => $rowNumber
        ];
    }

    /**
     * Check if beneficiary exists in the database.
     *
     * @param string $identifier Beneficiary identifier (account number, IBAN, or name)
     * @return bool True if beneficiary exists
     */
    public function validateBeneficiaryExists(string $identifier): bool
    {
        try {
            if (empty(trim($identifier))) {
                return false;
            }

            $identifier = trim($identifier);

            // Search by multiple criteria
            $beneficiary = Beneficiary::where(function ($query) use ($identifier) {
                $query->where('account_number', $identifier)
                      ->orWhere('iban', $identifier)
                      ->orWhere('name', 'LIKE', '%' . $identifier . '%');
            })
            ->where('status', 'active')
            ->first();

            return $beneficiary !== null;

        } catch (\Exception $e) {
            Log::error('Error checking beneficiary existence', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Validate required fields are present and not empty.
     */
    private function validateRequiredFields(array $row, int $rowNumber, array &$errors): void
    {
        foreach (self::REQUIRED_HEADERS as $field) {
            if (!isset($row[$field]) || empty(trim($row[$field]))) {
                $errors[] = "Row {$rowNumber}: Missing required field '{$field}'";
            }
        }
    }

    /**
     * Validate amount field format and value.
     */
    private function validateAmount(array $row, int $rowNumber, array &$errors): void
    {
        if (!isset($row['amount'])) {
            return;
        }

        $amount = $row['amount'];

        // Remove currency symbols and spaces
        $cleanAmount = preg_replace('/[^\d.-]/', '', $amount);

        if (!is_numeric($cleanAmount)) {
            $errors[] = "Row {$rowNumber}: Invalid amount format '{$amount}'";
            return;
        }

        $numericAmount = (float) $cleanAmount;

        if ($numericAmount <= 0) {
            $errors[] = "Row {$rowNumber}: Amount must be greater than 0";
        }

        if ($numericAmount > 999999.99) {
            $errors[] = "Row {$rowNumber}: Amount exceeds maximum limit of 999,999.99";
        }

        // Check decimal places
        if (strpos($cleanAmount, '.') !== false) {
            $decimalPart = substr($cleanAmount, strpos($cleanAmount, '.') + 1);
            if (strlen($decimalPart) > 2) {
                $errors[] = "Row {$rowNumber}: Amount cannot have more than 2 decimal places";
            }
        }
    }

    /**
     * Validate currency field and return the currency code.
     */
    private function validateCurrency(array $row, int $rowNumber, array &$errors): ?string
    {
        if (!isset($row['currency'])) {
            return null;
        }

        $currency = strtoupper(trim($row['currency']));

        if (!isset(self::CURRENCY_RULES[$currency])) {
            $errors[] = "Row {$rowNumber}: Unsupported currency '{$currency}'. Supported: " . implode(', ', array_keys(self::CURRENCY_RULES));
            return null;
        }

        return $currency;
    }

    /**
     * Validate currency-specific required fields and amount limits.
     */
    private function validateCurrencySpecificFields(array $row, string $currency, int $rowNumber, array &$errors): void
    {
        $rules = self::CURRENCY_RULES[$currency];

        // Check required fields for currency
        foreach ($rules['required_fields'] as $field) {
            if (!isset($row[$field]) || empty(trim($row[$field]))) {
                $errors[] = "Row {$rowNumber}: Missing required field '{$field}' for {$currency} payments";
            }
        }

        // Check amount limits
        if (isset($row['amount']) && is_numeric(preg_replace('/[^\d.-]/', '', $row['amount']))) {
            $amount = (float) preg_replace('/[^\d.-]/', '', $row['amount']);
            
            if ($amount < $rules['min_amount']) {
                $errors[] = "Row {$rowNumber}: Minimum amount for {$currency} is " . number_format($rules['min_amount'], 2);
            }
            
            if ($amount > $rules['max_amount']) {
                $errors[] = "Row {$rowNumber}: Maximum amount for {$currency} is " . number_format($rules['max_amount'], 2);
            }
        }

        // Check mandatory purpose code for certain currencies
        if (isset($rules['required_purpose_code']) && $rules['required_purpose_code']) {
            if (!isset($row['purpose_code']) || empty(trim($row['purpose_code']))) {
                $errors[] = "Row {$rowNumber}: Purpose code is required for {$currency} payments";
            }