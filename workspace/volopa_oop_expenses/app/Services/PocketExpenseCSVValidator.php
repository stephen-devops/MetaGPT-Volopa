## Code: app/Services/PocketExpenseCSVValidator.php
```php
<?php

namespace App\Services;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use League\Csv\Reader;
use Exception;

class PocketExpenseCSVValidator
{
    /**
     * Reference data cached for validation.
     */
    private array $referenceData = [];

    /**
     * Target user ID for whom expenses are being created.
     */
    private int $targetUserId;

    /**
     * Client ID for validation context.
     */
    private int $clientId;

    /**
     * Cache duration for reference data in minutes.
     */
    private const CACHE_DURATION = 30;

    /**
     * Maximum allowed file size in bytes (10MB).
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum number of rows to process.
     */
    private const MAX_ROWS = 10000;

    /**
     * Required CSV columns.
     */
    private const REQUIRED_COLUMNS = ['date', 'merchant_name', 'amount', 'currency'];

    /**
     * Optional CSV columns.
     */
    private const OPTIONAL_COLUMNS = [
        'description', 'expense_type', 'category', 'source', 'project_code',
        'cost_center', 'location', 'tax_amount', 'tax_rate', 'receipt_reference'
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
        $this->loadReferenceData();
    }

    /**
     * Validate a CSV file and return validation results.
     *
     * @param string $filePath
     * @return array
     * @throws Exception
     */
    public function validateCsv(string $filePath): array
    {
        try {
            Log::info('Starting CSV validation', [
                'file_path' => $filePath,
                'target_user_id' => $this->targetUserId,
                'client_id' => $this->clientId
            ]);

            // Initial file validation
            $fileValidation = $this->validateFile($filePath);
            if (!$fileValidation['valid']) {
                return [
                    'valid' => false,
                    'errors' => $fileValidation['errors'],
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'data' => []
                ];
            }

            // Parse CSV and validate structure
            $parseResult = $this->parseCsvFile($filePath);
            if (!$parseResult['valid']) {
                return [
                    'valid' => false,
                    'errors' => $parseResult['errors'],
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'data' => []
                ];
            }

            $rows = $parseResult['rows'];
            $validRows = [];
            $invalidRows = [];
            $globalErrors = [];

            // Validate each row
            foreach ($rows as $lineNumber => $row) {
                $rowValidation = $this->validateRow($row, $lineNumber);
                
                if ($rowValidation['valid']) {
                    $validRows[] = $rowValidation['data'];
                } else {
                    $invalidRows[] = [
                        'row_number' => $lineNumber,
                        'data' => $row,
                        'errors' => $rowValidation['errors']
                    ];
                }
            }

            // Check for global issues
            if (empty($validRows) && !empty($rows)) {
                $globalErrors[] = 'No valid expense rows found in the CSV file';
            }

            $isValid = empty($globalErrors) && !empty($validRows);

            $result = [
                'valid' => $isValid,
                'errors' => $globalErrors,
                'total_rows' => count($rows),
                'valid_rows' => count($validRows),
                'invalid_rows' => count($invalidRows),
                'data' => [
                    'valid_rows' => $validRows,
                    'invalid_rows' => $invalidRows
                ]
            ];

            Log::info('CSV validation completed', [
                'file_path' => $filePath,
                'total_rows' => $result['total_rows'],
                'valid_rows' => $result['valid_rows'],
                'invalid_rows' => $result['invalid_rows'],
                'is_valid' => $isValid
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('CSV validation failed', [
                'file_path' => $filePath,
                'target_user_id' => $this->targetUserId,
                'client_id' => $this->clientId,
                'error' => $e->getMessage()
            ]);

            throw new Exception('CSV validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a single row from the CSV.
     *
     * @param array $row
     * @param int $lineNumber
     * @return array
     */
    public function validateRow(array $row, int $lineNumber): array
    {
        $errors = [];
        $processedData = [];

        try {
            // Validate required fields
            foreach (self::REQUIRED_COLUMNS as $column) {
                if (empty($row[$column])) {
                    $errors[] = [
                        'field' => $column,
                        'message' => "Required field '{$column}' is missing or empty",
                        'code' => 'REQUIRED_FIELD_MISSING'
                    ];
                }
            }

            // If required fields are missing, skip further validation
            if (!empty($errors)) {
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'data' => []
                ];
            }

            // Validate and process each field
            $processedData['date'] = $this->validateAndProcessDate($row['date'], $errors);
            $processedData['merchant_name'] = $this->validateAndProcessMerchantName($row['merchant_name'], $errors);
            $processedData['amount'] = $this->validateAndProcessAmount($row['amount'], $errors);
            $processedData['currency'] = $this->validateAndProcessCurrency($row['currency'], $errors);

            // Process optional fields
            $processedData['description'] = $this->validateAndProcessDescription($row['description'] ?? '', $errors);
            $processedData['expense_type'] = $this->validateAndProcessExpenseType($row['expense_type'] ?? '', $errors);
            $processedData['category'] = $this->validateAndProcessCategory($row['category'] ?? '', $errors);
            $processedData['source'] = $this->validateAndProcessSource($row['source'] ?? '', $errors);
            $processedData['project_code'] = $this->validateAndProcessProjectCode($row['project_code'] ?? '', $errors);
            $processedData['cost_center'] = $this->validateAndProcessCostCenter($row['cost_center'] ?? '', $errors);
            $processedData['location'] = $this->validateAndProcessLocation($row['location'] ?? '', $errors);
            $processedData['tax_amount'] = $this->validateAndProcessTaxAmount($row['tax_amount'] ?? '', $errors);
            $processedData['tax_rate'] = $this->validateAndProcessTaxRate($row['tax_rate'] ?? '', $errors);
            $processedData['receipt_reference'] = $this->validateAndProcessReceiptReference($row['receipt_reference'] ?? '', $errors);

            // Add metadata
            $processedData['row_number'] = $lineNumber;
            $processedData['raw_row_data'] = $row;

            $isValid = empty($errors);

            return [
                'valid' => $isValid,
                'errors' => $errors,
                'data' => $processedData
            ];

        } catch (Exception $e) {
            $errors[] = [
                'field' => 'general',
                'message' => 'Row validation failed: ' . $e->getMessage(),
                'code' => 'VALIDATION_ERROR'
            ];

            return [
                'valid' => false,
                'errors' => $errors,
                'data' => []
            ];
        }
    }

    /**
     * Load reference data for validation.
     */
    public function loadReferenceData(): void
    {
        try {
            $cacheKey = "csv_reference_data_client_{$this->clientId}";

            $this->referenceData = Cache::remember($cacheKey, self::CACHE_DURATION, function () {
                $data = [
                    'currencies' => $this->loadCurrencies(),
                    'expense_types' => $this->loadExpenseTypes(),
                    'sources' => $this->loadSources(),
                    'countries' => $this->loadCountries(),
                    'categories' => $this->loadCategories(),
                ];

                Log::debug('Loaded reference data for CSV validation', [
                    'client_id' => $this->clientId,
                    'currencies_count' => count($data['currencies']),
                    'expense_types_count' => count($data['expense_types']),
                    'sources_count' => count($data['sources']),
                    'countries_count' => count($data['countries']),
                    'categories_count' => count($data['categories'])
                ]);

                return $data;
            });

        } catch (Exception $e) {
            Log::error('Failed to load reference data', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage()
            ]);

            // Set empty reference data as fallback
            $this->referenceData = [
                'currencies' => [],
                'expense_types' => [],
                'sources' => [],
                'countries' => [],
                'categories' => []
            ];
        }
    }

    /**
     * Validate file before processing.
     *
     * @param string $filePath
     * @return array
     */
    private function validateFile(string $filePath): array
    {
        $errors = [];

        try {
            // Check if file exists
            if (!file_exists($filePath)) {
                $errors[] = 'File does not exist';
                return ['valid' => false, 'errors' => $errors];
            }

            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                $errors[] = 'Unable to determine file size';
                return ['valid' => false, 'errors' => $errors];
            }

            if ($fileSize > self::MAX_FILE_SIZE) {
                $errors[] = 'File size exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB';
            }

            if ($fileSize === 0) {
                $errors[] = 'File is empty';
            }

            // Check file readability
            if (!is_readable($filePath)) {
                $errors[] = 'File is not readable';
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'file_size' => $fileSize
            ];

        } catch (Exception $e) {
            $errors[] = 'File validation error: ' . $e->getMessage();
            return ['valid' => false, 'errors' => $errors];
        }
    }

    /**
     * Parse CSV file and return rows.
     *
     * @param string $filePath
     * @return array
     */
    private function parseCsvFile(string $filePath): array
    {
        try {
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0); // Assume first row contains headers

            $headers = $csv->getHeader();
            $records = [];
            $errors = [];

            // Validate headers contain required columns
            $missingColumns = array_diff(self::REQUIRED_COLUMNS, $headers);
            if (!empty($missingColumns)) {
                $errors[] = 'Missing required columns: ' . implode(', ', $missingColumns);
                return ['valid' => false, 'errors' => $errors, 'rows' => []];
            }

            // Read and process rows
            $rowCount = 0;
            foreach ($csv->getRecords() as $lineNumber => $record) {
                $rowCount++;
                
                if ($rowCount > self::MAX_ROWS) {
                    $errors[] = 'File contains too many rows. Maximum allowed: ' . self::MAX_ROWS;
                    break;
                }

                // Ensure all columns are present with empty string defaults
                $processedRecord = [];
                foreach (array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS) as $column) {
                    $processedRecord[$column] = isset($record[$column]) ? trim($record[$column]) : '';
                }

                $records[$lineNumber + 2] = $processedRecord; // +2 because CSV lib uses 0-based index and we skip header
            }

            if (empty($records)) {
                $errors[] = 'No data rows found in the CSV file';
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'rows' => $records,
                'headers' => $headers
            ];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'errors' => ['Failed to parse CSV file: ' . $e->getMessage()],
                'rows' => []
            ];
        }
    }

    /**
     * Validate and process date field.
     *
     * @param string $date
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessDate(string $date, array &$errors): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            $formats = [
                'Y-m-d',
                'd/m/Y',
                'm/d/Y',
                'd-m-Y',
                'm-d-Y',
                'Y/m/d',
                'd.m.Y',
                'm.d.Y',
                'Y.m.d'
            ];

            $parsedDate = null;
            foreach ($formats as $format) {
                $parsed = Carbon::createFromFormat($format, $date);
                if ($parsed && $parsed->format($format) === $date) {
                    $parsedDate = $parsed;
                    break;
                }
            }

            if (!$parsedDate) {
                $errors[] = [
                    'field' => 'date',
                    'message' => "Invalid date format: {$date}. Expected formats: Y-m-d, d/m/Y, etc.",
                    'code' => 'INVALID_DATE_FORMAT'
                ];
                return null;
            }

            // Validate date range
            $today = Carbon::today();
            $maxPastDate = $today->copy()->subYear();

            if ($parsedDate->isFuture()) {
                $errors[] = [
                    'field' => 'date',
                    'message' => "Date cannot be in the future: {$date}",
                    'code' => 'FUTURE_DATE'
                ];
                return null;
            }

            if ($parsedDate->isBefore($maxPastDate)) {
                $errors[] = [
                    'field' => 'date',
                    'message' => "Date is too far in the past (max 1 year): {$date}",
                    'code' => 'DATE_TOO_OLD'
                ];
                return null;
            }

            return $parsedDate->format('Y-m-d');

        } catch (Exception $e) {
            $errors[] = [
                'field' => 'date',
                'message' => "Date validation error: {$e->getMessage()}",
                'code' => 'DATE_VALIDATION_ERROR'
            ];
            return null;
        }
    }

    /**
     * Validate and process merchant name field.
     *
     * @param string $merchantName
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessMerchantName(string $merchantName, array &$errors): ?string
    {
        if (empty($merchantName)) {
            return null;
        }

        $merchantName = trim($merchantName);

        if (strlen($merchantName) < 1) {
            $errors[] = [
                'field' => 'merchant_name',
                'message' => 'Merchant name cannot be empty',
                'code' => 'MERCHANT_NAME_EMPTY'
            ];
            return null;
        }

        if (strlen($merchantName) > 255) {
            $errors[] = [
                'field' => 'merchant_name',
                'message' => 'Merchant name is too long (max 255 characters)',
                'code' => 'MERCHANT_NAME_TOO_LONG'
            ];
            return null;
        }

        return $merchantName;
    }

    /**
     * Validate and process amount field.
     *
     * @param string $amount
     * @param array &$errors
     * @return float|null
     */
    private function validateAndProcessAmount(string $amount, array &$errors): ?float
    {
        if (empty($amount)) {
            return null;
        }

        // Clean the amount string
        $cleanAmount = preg_replace('/[^\d.-]/', '', $amount);

        if (!is_numeric($cleanAmount)) {
            $errors[] = [
                'field' => 'amount',
                'message' => "Invalid amount format: {$amount}",
                'code' => 'INVALID_AMOUNT_FORMAT'
            ];
            return null;
        }

        $numericAmount = (float) $cleanAmount;

        if ($numericAmount <= 0) {
            $errors[] = [
                'field' => 'amount',
                'message' => "Amount must be greater than zero: {$amount}",
                'code' => 'AMOUNT_NOT_POSITIVE'
            ];
            return null;
        }

        if ($numericAmount > 999999.99) {
            $errors[] = [
                'field' => 'amount',
                'message' => "Amount exceeds maximum allowed value: {$amount}",
                'code' => 'AMOUNT_TOO_LARGE'
            ];
            return null;
        }

        return round($numericAmount, 2);
    }

    /**
     * Validate and process currency field.
     *
     * @param string $currency
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessCurrency(string $currency, array &$errors): ?string
    {
        if (empty($currency)) {
            return null;
        }

        $currency = strtoupper(trim($currency));

        if (strlen($currency) !== 3) {
            $errors[] = [
                'field' => 'currency',
                'message' => "Currency code must be exactly 3 characters: {$currency}",
                'code' => 'INVALID_CURRENCY_LENGTH'
            ];
            return null;
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = [
                'field' => 'currency',
                'message' => "Invalid currency format: {$currency}",
                'code' => 'INVALID_CURRENCY_FORMAT'
            ];
            return null;
        }

        // Validate against reference data
        if (!in_array($currency, $this->referenceData['currencies'])) {
            $errors[] = [
                'field' => 'currency',
                'message' => "Unknown or inactive currency: {$currency}",
                'code' => 'UNKNOWN_CURRENCY'
            ];
            return null;
        }

        return $currency;
    }

    /**
     * Validate and process description field.
     *
     * @param string $description
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessDescription(string $description, array &$errors): ?string
    {
        if (empty($description)) {
            return null;
        }

        $description = trim($description);

        if (strlen($description) > 1000) {
            $errors[] = [
                'field' => 'description',
                'message' => 'Description is too long (max 1000 characters)',
                'code' => 'DESCRIPTION_TOO_LONG'
            ];
            return substr($description, 0, 1000);
        }

        return $description;
    }

    /**
     * Validate and process expense type field.
     *
     * @param string $expenseType
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessExpenseType(string $expenseType, array &$errors): ?string
    {
        if (empty($expenseType)) {
            return null;
        }

        $expenseType = trim($expenseType);

        if (!in_array($expenseType, $this->referenceData['expense_types'])) {
            $errors[] = [
                'field' => 'expense_type',
                'message' => "Unknown expense type: {$expenseType}",
                'code' => 'UNKNOWN_EXPENSE_TYPE'
            ];
            return null;
        }

        return $expenseType;
    }

    /**
     * Validate and process category field.
     *
     * @param string $category
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessCategory(string $category, array &$errors): ?string
    {
        if (empty($category)) {
            return null;
        }

        $category = trim($category);

        if (strlen($category) > 200) {
            $errors[] = [
                'field' => 'category',
                'message' => 'Category name is too long (max 200 characters)',
                'code' => 'CATEGORY_TOO_LONG'
            ];
            return substr($category, 0, 200);
        }

        return $category;
    }

    /**
     * Validate and process source field.
     *
     * @param string $source
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessSource(string $source, array &$errors): ?string
    {
        if (empty($source)) {
            return null;
        }

        $source = trim($source);

        if (!in_array($source, $this->referenceData['sources'])) {
            $errors[] = [
                'field' => 'source',
                'message' => "Unknown source: {$source}",
                'code' => 'UNKNOWN_SOURCE'
            ];
            return null;
        }

        return $source;
    }

    /**
     * Validate and process project code field.
     *
     * @param string $projectCode
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessProjectCode(string $projectCode, array &$errors): ?string
    {
        if (empty($projectCode)) {
            return null;
        }

        $projectCode = trim($projectCode);

        if (!preg_match('/^[A-Z0-9_-]+$/i', $projectCode)) {
            $errors[] = [
                'field' => 'project_code',
                'message' => "Invalid project code format: {$projectCode}",
                'code' => 'INVALID_PROJECT_CODE_FORMAT'
            ];
            return null;
        }

        if (strlen($projectCode) > 50) {
            $errors[] = [
                'field' => 'project_code',
                'message' => 'Project code is too long (max 50 characters)',
                'code' => 'PROJECT_CODE_TOO_LONG'
            ];
            return substr($projectCode, 0, 50);
        }

        return $projectCode;
    }

    /**
     * Validate and process cost center field.
     *
     * @param string $costCenter
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessCostCenter(string $costCenter, array &$errors): ?string
    {
        if (empty($costCenter)) {
            return null;
        }

        $costCenter = trim($costCenter);

        if (!preg_match('/^[A-Z0-9_-]+$/i', $costCenter)) {
            $errors[] = [
                'field' => 'cost_center',
                'message' => "Invalid cost center format: {$costCenter}",
                'code' => 'INVALID_COST_CENTER_FORMAT'
            ];
            return null;
        }

        if (strlen($costCenter) > 50) {
            $errors[] = [
                'field' => 'cost_center',
                'message' => 'Cost center is too long (max 50 characters)',
                'code' => 'COST_CENTER_TOO_LONG'
            ];
            return substr($costCenter, 0, 50);
        }

        return $costCenter;
    }

    /**
     * Validate and process location field.
     *
     * @param string $location
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessLocation(string $location, array &$errors): ?string
    {
        if (empty($location)) {
            return null;
        }

        $location = trim($location);

        if (strlen($location) > 200) {
            $errors[] = [
                'field' => 'location',
                'message' => 'Location is too long (max 200 characters)',
                'code' => 'LOCATION_TOO_LONG'
            ];
            return substr($location, 0, 200);
        }

        return $location;
    }

    /**
     * Validate and process tax amount field.
     *
     * @param string $taxAmount
     * @param array &$errors
     * @return float|null
     */
    private function validateAndProcessTaxAmount(string $taxAmount, array &$errors): ?float
    {
        if (empty($taxAmount)) {
            return null;
        }

        $cleanAmount = preg_replace('/[^\d.-]/', '', $taxAmount);

        if (!is_numeric($cleanAmount)) {
            $errors[] = [
                'field' => 'tax_amount',
                'message' => "Invalid tax amount format: {$taxAmount}",
                'code' => 'INVALID_TAX_AMOUNT_FORMAT'
            ];
            return null;
        }

        $numericAmount = (float) $cleanAmount;

        if ($numericAmount < 0) {
            $errors[] = [
                'field' => 'tax_amount',
                'message' => "Tax amount cannot be negative: {$taxAmount}",
                'code' => 'NEGATIVE_TAX_AMOUNT'
            ];
            return null;
        }

        return round($numericAmount, 2);
    }

    /**
     * Validate and process tax rate field.
     *
     * @param string $taxRate
     * @param array &$errors
     * @return float|null
     */
    private function validateAndProcessTaxRate(string $taxRate, array &$errors): ?float
    {
        if (empty($taxRate)) {
            return null;
        }

        $cleanRate = preg_replace('/[^\d.-]/', '', $taxRate);

        if (!is_numeric($cleanRate)) {
            $errors[] = [
                'field' => 'tax_rate',
                'message' => "Invalid tax rate format: {$taxRate}",
                'code' => 'INVALID_TAX_RATE_FORMAT'
            ];
            return null;
        }

        $numericRate = (float) $cleanRate;

        // Convert percentage to decimal if > 1
        if ($numericRate > 1 && $numericRate <= 100) {
            $numericRate = $numericRate / 100;
        }

        if ($numericRate < 0 || $numericRate > 1) {
            $errors[] = [
                'field' => 'tax_rate',
                'message' => "Tax rate must be between 0 and 100%: {$taxRate}",
                'code' => 'INVALID_TAX_RATE_RANGE'
            ];
            return null;
        }

        return round($numericRate, 4);
    }

    /**
     * Validate and process receipt reference field.
     *
     * @param string $receiptReference
     * @param array &$errors
     * @return string|null
     */
    private function validateAndProcessReceiptReference(string $receiptReference, array &$errors): ?string
    {
        if (empty($receiptReference)) {
            return null;
        }

        $receiptReference = trim($receiptReference);

        if (strlen($receiptReference) > 200) {
            $errors[] = [
                'field' => 'receipt_reference',
                'message' => 'Receipt reference is too long (max 200 characters)',
                'code' => 'RECEIPT_REFERENCE_TOO_LONG'
            ];
            return substr($receiptReference, 0, 200);
        }

        return $receiptReference;
    }

    /**
     * Load available currencies.
     *
     * @return array
     */
    private function loadCurrencies(): array
    {
        try {
            return DB::table('currency')
                ->where('is_active', true)
                ->pluck('code')
                ->toArray();
        } catch (Exception $e) {
            Log::error('Failed to load currencies', ['error' => $e->getMessage()]);
            return ['USD', 'EUR', 'GBP']; // Fallback currencies
        }
    }

    /**
     * Load available expense types.
     *
     * @return array
     */
    private function loadExpenseTypes(): array
    {
        try {
            return OptPocketExpenseType::where('is_active', true)
                ->pluck('name')
                ->toArray();
        } catch (Exception $e) {
            Log::error('Failed to load expense types', ['error' => $e->getMessage()]);
            return ['Travel', 'Meals', 'Office Supplies', 'Business Entertainment']; // Fallback types
        }
    }

    /**
     * Load available sources for the client.
     *
     * @return array
     */
    private function loadSources(): array
    {
        try {
            return PocketExpenseSourceClientConfig::where('client_id', $this->clientId)
                ->where('is_active', true)
                ->pluck('source_name')
                ->toArray();
        } catch (Exception $e) {
            Log::error('Failed to load sources', ['error' => $e->getMessage()]);
            return []; // Empty fallback since sources are client-specific
        }
    }

    /**
     * Load available countries.
     *
     * @return array
     */
    private function loadCountries(): array
    {
        try {
            return DB::table('countries')
                ->where('is_active', true)
                ->pluck('name')
                ->toArray();
        } catch (Exception $e) {
            Log::error('Failed to load countries', ['error' => $e->getMessage()]);
            return []; // Empty fallback
        }
    }

    /**
     * Load available categories.
     *
     * @return array