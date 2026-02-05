## Code: app/Services/PocketExpenseCSVValidator.php

```php
<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;
use Carbon\Carbon;
use Exception;

/**
 * PocketExpenseCSVValidator
 * 
 * Service for validating CSV files for pocket expense uploads.
 * Performs synchronous all-or-nothing validation with preloaded reference data.
 * Caches reference data for performance and provides detailed error reporting.
 */
class PocketExpenseCSVValidator
{
    /**
     * Maximum number of rows allowed in CSV file.
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Cache TTL for reference data (30 minutes).
     */
    private const REFERENCE_DATA_CACHE_TTL = 1800;

    /**
     * Cache key prefix for reference data.
     */
    private const CACHE_PREFIX = 'csv_validator_reference';

    /**
     * Validation result class.
     */
    public class ValidationResult
    {
        public bool $isValid;
        public int $totalRows;
        public int $errorCount;
        public array $errors;
        public array $validatedRows;
        public array $summary;

        public function __construct(
            bool $isValid = false,
            int $totalRows = 0,
            int $errorCount = 0,
            array $errors = [],
            array $validatedRows = [],
            array $summary = []
        ) {
            $this->isValid = $isValid;
            $this->totalRows = $totalRows;
            $this->errorCount = $errorCount;
            $this->errors = $errors;
            $this->validatedRows = $validatedRows;
            $this->summary = $summary;
        }

        /**
         * Convert to array representation.
         *
         * @return array<string, mixed>
         */
        public function toArray(): array
        {
            return [
                'is_valid' => $this->isValid,
                'total_rows' => $this->totalRows,
                'error_count' => $this->errorCount,
                'errors' => $this->errors,
                'validated_rows' => $this->validatedRows,
                'summary' => $this->summary,
            ];
        }
    }

    /**
     * Cached reference data.
     *
     * @var array<string, mixed>
     */
    private array $referenceDataCache = [];

    /**
     * Target user ID for expense creation.
     *
     * @var int
     */
    private int $targetUserId;

    /**
     * Client ID for validation context.
     *
     * @var int
     */
    private int $clientId;

    /**
     * CSV column mappings from configuration.
     *
     * @var array<string, mixed>
     */
    private array $columnMappings = [];

    /**
     * Supported currencies.
     *
     * @var array<string>
     */
    private array $supportedCurrencies = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK'
    ];

    /**
     * Supported countries (ISO 2-letter codes).
     *
     * @var array<string>
     */
    private array $supportedCountries = [
        'US', 'GB', 'CA', 'AU', 'FR', 'DE', 'IT', 'ES', 'NL', 'BE', 
        'CH', 'AT', 'SE', 'NO', 'DK', 'FI', 'IE', 'PT', 'LU', 'JP'
    ];

    /**
     * Required CSV columns.
     *
     * @var array<string>
     */
    private array $requiredColumns = [
        'Date',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Name'
    ];

    /**
     * Create a new CSV validator instance.
     *
     * @param int $targetUserId
     * @param int $clientId
     */
    public function __construct(int $targetUserId, int $clientId)
    {
        $this->targetUserId = $targetUserId;
        $this->clientId = $clientId;
        
        // Load configuration
        $this->loadConfiguration();
        
        // Cache reference data
        $this->cacheReferenceData();
    }

    /**
     * Validate CSV file and return validation result.
     *
     * @param string $csvFilePath
     * @return ValidationResult
     */
    public function validate(string $csvFilePath): ValidationResult
    {
        try {
            // Validate file exists and is readable
            if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
                return new ValidationResult(
                    isValid: false,
                    errorCount: 1,
                    errors: [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'CSV file is not accessible or does not exist',
                            'value' => $csvFilePath
                        ]
                    ]
                );
            }

            // Parse CSV file
            $reader = Reader::createFromPath($csvFilePath, 'r');
            $reader->setHeaderOffset(0);
            
            $header = $reader->getHeader();
            $records = iterator_to_array($reader->getRecords());

            // Validate structure
            $structureErrors = $this->validateStructure($header, $records);
            if (!empty($structureErrors)) {
                return new ValidationResult(
                    isValid: false,
                    totalRows: count($records),
                    errorCount: count($structureErrors),
                    errors: $structureErrors
                );
            }

            // Validate each row
            $errors = [];
            $validatedRows = [];
            $lineNumber = 2; // Start from 2 (1 is header)

            foreach ($records as $record) {
                $rowErrors = $this->validateRow($record, $lineNumber);
                
                if (!empty($rowErrors)) {
                    $errors = array_merge($errors, $rowErrors);
                } else {
                    $validatedRows[] = $this->normalizeRow($record, $lineNumber);
                }
                
                $lineNumber++;
            }

            // Create summary
            $summary = $this->createValidationSummary(count($records), $errors, $validatedRows);

            return new ValidationResult(
                isValid: empty($errors),
                totalRows: count($records),
                errorCount: count($errors),
                errors: $errors,
                validatedRows: $validatedRows,
                summary: $summary
            );

        } catch (CsvException $e) {
            Log::error('CSV parsing error', [
                'file_path' => $csvFilePath,
                'error' => $e->getMessage(),
                'target_user_id' => $this->targetUserId,
                'client_id' => $this->clientId,
            ]);

            return new ValidationResult(
                isValid: false,
                errorCount: 1,
                errors: [
                    [
                        'line_number' => 0,
                        'field' => 'file',
                        'error' => 'Invalid CSV file format: ' . $e->getMessage(),
                        'value' => ''
                    ]
                ]
            );
        } catch (Exception $e) {
            Log::error('CSV validation error', [
                'file_path' => $csvFilePath,
                'error' => $e->getMessage(),
                'target_user_id' => $this->targetUserId,
                'client_id' => $this->clientId,
            ]);

            return new ValidationResult(
                isValid: false,
                errorCount: 1,
                errors: [
                    [
                        'line_number' => 0,
                        'field' => 'file',
                        'error' => 'CSV validation failed: ' . $e->getMessage(),
                        'value' => ''
                    ]
                ]
            );
        }
    }

    /**
     * Validate individual row data.
     *
     * @param array<string, string> $rowData
     * @param int $lineNumber
     * @return array<array<string, mixed>>
     */
    public function validateRow(array $rowData, int $lineNumber): array
    {
        $errors = [];

        // Validate required fields
        foreach ($this->requiredColumns as $column) {
            $value = trim($rowData[$column] ?? '');
            
            if (empty($value)) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => $column,
                    'error' => 'Required field is empty',
                    'value' => $value
                ];
                continue;
            }

            // Validate specific field types
            $fieldErrors = $this->validateField($column, $value, $lineNumber, $rowData);
            $errors = array_merge($errors, $fieldErrors);
        }

        // Validate optional fields if present
        $optionalFields = [
            'Currency Equivalent Amount',
            'VAT %',
            'Description',
            'Merchant Address',
            'Merchant Country',
            'Source',
            'Source Note',
            'Notes'
        ];

        foreach ($optionalFields as $column) {
            $value = trim($rowData[$column] ?? '');
            
            if (!empty($value)) {
                $fieldErrors = $this->validateField($column, $value, $lineNumber, $rowData);
                $errors = array_merge($errors, $fieldErrors);
            }
        }

        // Cross-field validation
        $crossValidationErrors = $this->validateCrossFieldRules($rowData, $lineNumber);
        $errors = array_merge($errors, $crossValidationErrors);

        return $errors;
    }

    /**
     * Cache reference data for validation.
     *
     * @return void
     */
    public function cacheReferenceData(): void
    {
        $cacheKey = $this->getReferenceDataCacheKey();
        
        $this->referenceDataCache = Cache::remember($cacheKey, self::REFERENCE_DATA_CACHE_TTL, function () {
            return [
                'expense_types' => $this->loadExpenseTypes(),
                'expense_sources' => $this->loadExpenseSources(),
                'currencies' => $this->supportedCurrencies,
                'countries' => $this->supportedCountries,
            ];
        });
    }

    /**
     * Validate CSV structure (headers and row count).
     *
     * @param array<string> $header
     * @param array<array<string, string>> $records
     * @return array<array<string, mixed>>
     */
    private function validateStructure(array $header, array $records): array
    {
        $errors = [];

        // Check for required columns
        $normalizedHeader = array_map('strtolower', array_map('trim', $header));
        $missingColumns = [];

        foreach ($this->requiredColumns as $required) {
            $normalizedRequired = strtolower($required);
            if (!in_array($normalizedRequired, $normalizedHeader)) {
                $missingColumns[] = $required;
            }
        }

        if (!empty($missingColumns)) {
            $errors[] = [
                'line_number' => 1,
                'field' => 'header',
                'error' => 'Missing required columns: ' . implode(', ', $missingColumns),
                'value' => implode(', ', $header)
            ];
        }

        // Check row count limit
        if (count($records) > self::MAX_CSV_ROWS) {
            $errors[] = [
                'line_number' => 0,
                'field' => 'file',
                'error' => 'CSV file contains ' . count($records) . ' rows, but maximum allowed is ' . self::MAX_CSV_ROWS,
                'value' => (string) count($records)
            ];
        }

        // Check minimum rows
        if (count($records) === 0) {
            $errors[] = [
                'line_number' => 0,
                'field' => 'file',
                'error' => 'CSV file must contain at least one data row',
                'value' => '0'
            ];
        }

        return $errors;
    }

    /**
     * Validate individual field based on type and rules.
     *
     * @param string $fieldName
     * @param string $value
     * @param int $lineNumber
     * @param array<string, string> $rowData
     * @return array<array<string, mixed>>
     */
    private function validateField(string $fieldName, string $value, int $lineNumber, array $rowData): array
    {
        $errors = [];

        switch ($fieldName) {
            case 'Date':
                $errors = array_merge($errors, $this->validateDate($value, $lineNumber));
                break;

            case 'Expense Type':
                $errors = array_merge($errors, $this->validateExpenseType($value, $lineNumber));
                break;

            case 'Currency Code':
                $errors = array_merge($errors, $this->validateCurrency($value, $lineNumber));
                break;

            case 'Amount':
            case 'Currency Equivalent Amount':
                $errors = array_merge($errors, $this->validateAmount($value, $lineNumber, $fieldName));
                break;

            case 'VAT %':
                $errors = array_merge($errors, $this->validateVatPercentage($value, $lineNumber));
                break;

            case 'Merchant Name':
                $errors = array_merge($errors, $this->validateMerchantName($value, $lineNumber));
                break;

            case 'Description':
                $errors = array_merge($errors, $this->validateDescription($value, $lineNumber));
                break;

            case 'Merchant Address':
                $errors = array_merge($errors, $this->validateMerchantAddress($value, $lineNumber));
                break;

            case 'Merchant Country':
                $errors = array_merge($errors, $this->validateCountry($value, $lineNumber));
                break;

            case 'Source':
                $errors = array_merge($errors, $this->validateSource($value, $lineNumber));
                break;

            case 'Source Note':
                $errors = array_merge($errors, $this->validateSourceNote($value, $lineNumber));
                break;

            case 'Notes':
                $errors = array_merge($errors, $this->validateNotes($value, $lineNumber));
                break;
        }

        return $errors;
    }

    /**
     * Validate date field.
     *
     * @param string $value
     * @param int $lineNumber
     * @return array<array<string, mixed>>
     */
    private function validateDate(string $value, int $lineNumber): array
    {
        $errors = [];

        try {
            // Try multiple date formats
            $date = null;
            $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y'];
            
            foreach ($formats as $format) {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed && $parsed->format($format) === $value) {
                    $date = $parsed;
                    break;
                }
            }

            if (!$date) {
                $errors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'error' => 'Invalid date format. Use DD/MM/YYYY, DD-MM-YYYY, or YYYY-MM-DD',
                    'value' => $value
                ];
                return $errors