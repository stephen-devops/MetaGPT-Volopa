<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;

/**
 * PocketExpenseCSVValidator Service
 * 
 * Handles CSV validation for pocket expense batch uploads with all-or-nothing
 * validation principle. Performs synchronous validation against platform
 * constraints and reference data before allowing async processing.
 * 
 * Key validation rules:
 * - File format: CSV or TXT with CSV content, max 10MB, max 200 rows
 * - Header row mandatory and must exactly match column names
 * - Date format: DD/MM/YYYY, not older than 3 years
 * - Currency: 3-letter ISO code from allowed list
 * - Amount sign determined by expense type (Refund = positive, others = negative)
 * - VAT % numeric between 0-100 (strip % sign)
 * - Source must match configured sources for client (including global Other)
 * - Source Note required when Source = Other
 * - Merchant Name max 180 chars per DB definition
 * - All-or-nothing: if any row fails validation, no expenses are created
 */
class PocketExpenseCSVValidator
{
    /**
     * Maximum file size in bytes (10MB = 10240 KB)
     */
    private const MAX_FILE_SIZE = 10485760; // 10MB in bytes

    /**
     * Maximum number of data rows allowed per CSV file
     */
    private const MAX_ROWS = 200;

    /**
     * Required CSV header columns in exact order and case
     */
    private const REQUIRED_HEADERS = [
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
     * Supported currencies (3-letter ISO codes)
     * This should be loaded from platform currency master data
     */
    private const ALLOWED_CURRENCIES = [
        'GBP', 'EUR', 'USD', 'CAD', 'AUD', 'CHF', 'JPY', 'SEK', 'NOK', 'DKK'
    ];

    /**
     * Maximum age for expense dates in years
     */
    private const MAX_DATE_AGE_YEARS = 3;

    /**
     * Maximum length for merchant name field (VARCHAR 180 per DB definition)
     */
    private const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Preloaded reference data for validation
     */
    private Collection $expenseTypes;
    private Collection $expenseSources;
    private array $validationErrors = [];

    /**
     * Initialize the validator
     */
    public function __construct()
    {
        $this->expenseTypes = collect();
        $this->expenseSources = collect();
    }

    /**
     * Main validation method for CSV file upload
     *
     * @param string $filePath Full path to uploaded CSV file
     * @param int $targetUserId User ID for whom expenses will be created
     * @param int $clientId Client context for multi-tenancy
     * @param int $adminId Admin user ID who performed the upload
     * @return array Validation result with success status, errors, and valid rows
     */
    public function validate(string $filePath, int $targetUserId, int $clientId, int $adminId): array
    {
        $this->validationErrors = [];

        try {
            // Basic file validation
            $fileValidation = $this->validateFile($filePath);
            if (!$fileValidation['valid']) {
                return [
                    'valid' => false,
                    'errors' => $fileValidation['errors'],
                    'valid_rows' => [],
                    'total_rows' => 0
                ];
            }

            // Preload reference data for validation
            $this->preloadReferenceData($clientId);

            // Parse and validate CSV content
            $reader = Reader::createFromPath($filePath, 'r');
            $reader->setHeaderOffset(0);

            // Validate headers
            $headers = $reader->getHeader();
            $headerValidation = $this->validateHeaders($headers);
            if (!$headerValidation['valid']) {
                return [
                    'valid' => false,
                    'errors' => $headerValidation['errors'],
                    'valid_rows' => [],
                    'total_rows' => 0
                ];
            }

            // Get all data rows
            $records = iterator_to_array($reader->getRecords());
            $totalRows = count($records);

            // Validate row count constraint
            if ($totalRows > self::MAX_ROWS) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line_number' => null,
                            'field' => 'file',
                            'error' => 'File contains ' . $totalRows . ' rows, maximum allowed is ' . self::MAX_ROWS,
                            'provided_value' => $totalRows
                        ]
                    ],
                    'valid_rows' => [],
                    'total_rows' => $totalRows
                ];
            }

            // Validate each data row
            $validRows = [];
            $lineNumber = 2; // Start from 2 (after header row)

            foreach ($records as $record) {
                $rowValidation = $this->validateRow($record, $lineNumber, $targetUserId, $clientId);
                
                if ($rowValidation['valid']) {
                    $validRows[] = [
                        'line_number' => $lineNumber,
                        'data' => $rowValidation['data']
                    ];
                } else {
                    // Add row-specific errors to global error list
                    foreach ($rowValidation['errors'] as $error) {
                        $this->validationErrors[] = $error;
                    }
                }

                $lineNumber++;
            }

            // All-or-nothing validation: if any row has errors, fail entire batch
            if (!empty($this->validationErrors)) {
                return [
                    'valid' => false,
                    'errors' => $this->validationErrors,
                    'valid_rows' => [],
                    'total_rows' => $totalRows
                ];
            }

            // All validation passed
            return [
                'valid' => true,
                'errors' => [],
                'valid_rows' => $validRows,
                'total_rows' => $totalRows
            ];

        } catch (CsvException $e) {
            Log::error('CSV parsing error during validation', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'client_id' => $clientId,
                'admin_id' => $adminId
            ]);

            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => null,
                        'field' => 'file',
                        'error' => 'Invalid CSV format: ' . $e->getMessage(),
                        'provided_value' => null
                    ]
                ],
                'valid_rows' => [],
                'total_rows' => 0
            ];
        } catch (\Exception $e) {
            Log::error('Unexpected error during CSV validation', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'client_id' => $clientId,
                'admin_id' => $adminId
            ]);

            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => null,
                        'field' => 'system',
                        'error' => 'System error during validation. Please try again.',
                        'provided_value' => null
                    ]
                ],
                'valid_rows' => [],
                'total_rows' => 0
            ];
        }
    }

    /**
     * Validate basic file properties (size, format, readability)
     *
     * @param string $filePath Full path to uploaded file
     * @return array Validation result with valid flag and errors
     */
    private function validateFile(string $filePath): array
    {
        $errors = [];

        // Check if file exists and is readable
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $errors[] = [
                'line_number' => null,
                'field' => 'file',
                'error' => 'File not found or not readable',
                'provided_value' => $filePath
            ];
            return ['valid' => false, 'errors' => $errors];
        }

        // Check file size constraint (max 10MB)
        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            $errors[] = [
                'line_number' => null,
                'field' => 'file',
                'error' => 'File size ' . round($fileSize / 1024 / 1024, 2) . 'MB exceeds maximum allowed size of 10MB',
                'provided_value' => $fileSize
            ];
        }

        // Check file extension (CSV or TXT)
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'])) {
            $errors[] = [
                'line_number' => null,
                'field' => 'file',
                'error' => 'Invalid file format. Only CSV and TXT files are allowed',
                'provided_value' => $extension
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate CSV header row against required column names
     *
     * @param array $headers Header row from CSV file
     * @return array Validation result with valid flag and errors
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];

        // Check if header count matches expected
        if (count($headers) !== count(self::REQUIRED_HEADERS)) {
            $errors[] = [
                'line_number' => 1,
                'field' => 'headers',
                'error' => 'Expected ' . count(self::REQUIRED_HEADERS) . ' columns, found ' . count($headers),
                'provided_value' => implode(', ', $headers)
            ];
        }

        // Validate each header column name and order
        foreach (self::REQUIRED_HEADERS as $index => $requiredHeader) {
            if (!isset($headers[$index]) || trim($headers[$index]) !== $requiredHeader) {
                $providedHeader = isset($headers[$index]) ? trim($headers[$index]) : 'MISSING';
                $errors[] = [
                    'line_number' => 1,
                    'field' => 'header_column_' . ($index + 1),
                    'error' => 'Expected column "' . $requiredHeader . '" at position ' . ($index + 1) . ', found "' . $providedHeader . '"',
                    'provided_value' => $providedHeader
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validate individual CSV row data
     *
     * @param array $row CSV row data as associative array
     * @param int $lineNumber Line number in CSV file (starting from 2)
     * @param int $targetUserId Target user ID for expense creation
     * @param int $clientId Client context for validation
     * @return array Validation result with valid flag, errors, and processed data
     */
    public function validateRow(array $row, int $lineNumber, int $targetUserId, int $clientId): array
    {
        $errors = [];
        $processedData = [];

        // Validate Date field
        $dateValidation = $this->validateDate($row['Date'] ?? '', $lineNumber);
        if (!$dateValidation['valid']) {
            $errors = array_merge($errors, $dateValidation['errors']);
        } else {
            $processedData['date'] = $dateValidation['value'];
        }

        // Validate Merchant Name field
        $merchantValidation = $this->validateMerchantName($row['Merchant Name'] ?? '', $lineNumber);
        if (!$merchantValidation['valid']) {
            $errors = array_merge($errors, $merchantValidation['errors']);
        } else {
            $processedData['merchant_name'] = $merchantValidation['value'];
        }

        // Validate Merchant Description (optional)
        $processedData['merchant_description'] = $this->sanitizeText($row['Merchant Description'] ?? '');

        // Validate Expense Type field
        $expenseTypeValidation = $this->validateExpenseType($row['Expense Type'] ?? '', $lineNumber);
        if (!$expenseTypeValidation['valid']) {
            $errors = array_merge($errors, $expenseTypeValidation['errors']);
        } else {
            $processedData['expense_type'] = $expenseTypeValidation['value'];
        }

        // Validate Currency field
        $currencyValidation = $this->validateCurrency($row['Currency'] ?? '', $lineNumber);
        if (!$currencyValidation['valid']) {
            $errors = array_merge($errors, $currencyValidation['errors']);
        } else {
            $processedData['currency'] = $currencyValidation['value'];
        }

        // Validate Amount field (depends on expense type for sign)
        $amountValidation = $this->validateAmount(
            $row['Amount'] ?? '', 
            $expenseTypeValidation['value'] ?? null, 
            $lineNumber
        );
        if (!$amountValidation['valid']) {
            $errors = array_merge($errors, $amountValidation['errors']);
        } else {
            $processedData['amount'] = $amountValidation['value'];
        }

        // Validate Merchant Address (optional)
        $processedData['merchant_address'] = $this->sanitizeText($row['Merchant Address'] ?? '');

        // Validate VAT % field (optional)
        $vatValidation = $this->validateVatPercentage($row['VAT %'] ?? '', $lineNumber);
        if (!$vatValidation['valid']) {
            $errors = array_merge($errors, $vatValidation['errors']);
        } else {
            $processedData['vat_amount'] = $vatValidation['value'];
        }

        // Validate Notes (optional)
        $processedData['notes'] = $this->sanitizeText($row['Notes'] ?? '');

        // Validate Source field
        $sourceValidation = $this->validateSource($row['Source'] ?? '', $clientId, $lineNumber);
        if (!$sourceValidation['valid']) {
            $errors = array_merge($errors, $sourceValidation['errors']);
        } else {
            $processedData['expense_source_id'] = $sourceValidation['value'];
        }

        // Validate Source Note field (required when Source = Other)
        $sourceNoteValidation = $this->validateSourceNote(
            $row['Source Note'] ?? '', 
            $row['Source'] ?? '', 
            $lineNumber
        );
        if (!$sourceNoteValidation['valid']) {
            $errors = array_merge($errors, $sourceNoteValidation['errors']);
        } else {
            $processedData['source_note'] = $sourceNoteValidation['value'];
        }

        // Add audit fields for expense creation
        $processedData['user_id'] = $targetUserId;
        $processedData['client_id'] = $clientId;
        $processedData['status'] = 'draft'; // Default status for uploaded expenses
        $processedData['created_by_user_id'] = $targetUserId; // Will be overridden with admin ID in service

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $processedData
        ];
    }

    /**
     * Preload reference data for validation
     *
     * @param int $clientId Client ID for scoped data loading
     * @return void
     */
    public function preloadReferenceData(int $clientId): void
    {
        try {
            // Load expense types
            $this->expenseTypes = OptPocketExpenseType::all();

            // Load expense sources for client (including global 'Other')
            $this->expenseSources = PocketExpenseSourceClientConfig::where(function ($query) use ($clientId) {
                $query->where('client_id', $clientId)
                      ->orWhereNull('client_id'); // Include global 'Other' source
            })
            ->where('deleted', 0) // Only active sources
            ->get();

            Log::info('Reference data preloaded for CSV validation', [
                'client_id' => $clientId,
                'expense_types_count' => $this->expenseTypes->count(),
                'expense_sources_count' => $this->expenseSources->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to preload reference data for CSV validation', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            // Initialize empty collections to prevent further errors
            $this->expenseTypes = collect();
            $this->expenseSources = collect();
        }
    }

    /**
     * Validate date field (DD/MM/YYYY format, not older than 3 years)
     *
     * @param string $date Date string from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateDate(string $date, int $lineNumber): array
    {
        $date = trim($date);
        
        if (empty($date)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Date',
                        'error' => 'Date is required',
                        'provided_value' => $date
                    ]
                ]
            ];
        }

        // Parse DD/MM/YYYY format
        try {
            $parsedDate = Carbon::createFromFormat('d/m/Y', $date);
            
            // Check if date is not older than 3 years
            $threeYearsAgo = Carbon::now()->subYears(self::MAX_DATE_AGE_YEARS);
            if ($parsedDate->lt($threeYearsAgo)) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line_number' => $lineNumber,
                            'field' => 'Date',
                            'error' => 'Date cannot be older than ' . self::MAX_DATE_AGE_YEARS . ' years',
                            'provided_value' => $date
                        ]
                    ]
                ];
            }

            // Check if date is not in the future
            if ($parsedDate->gt(Carbon::now())) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line_number' => $lineNumber,
                            'field' => 'Date',
                            'error' => 'Date cannot be in the future',
                            'provided_value' => $date
                        ]
                    ]
                ];
            }

            return [
                'valid' => true,
                'value' => $parsedDate->format('Y-m-d'), // Convert to DB format
                'errors' => []
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Date',
                        'error' => 'Invalid date format. Expected DD/MM/YYYY',
                        'provided_value' => $date
                    ]
                ]
            ];
        }
    }

    /**
     * Validate merchant name field (required, max 180 characters)
     *
     * @param string $merchantName Merchant name from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateMerchantName(string $merchantName, int $lineNumber): array
    {
        $merchantName = trim($merchantName);

        if (empty($merchantName)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Merchant Name',
                        'error' => 'Merchant Name is required',
                        'provided_value' => $merchantName
                    ]
                ]
            ];
        }

        if (strlen($merchantName) > self::MAX_MERCHANT_NAME_LENGTH) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Merchant Name',
                        'error' => 'Merchant Name cannot exceed ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters',
                        'provided_value' => $merchantName
                    ]
                ]
            ];
        }

        return [
            'valid' => true,
            'value' => $this->sanitizeText($merchantName),
            'errors' => []
        ];
    }

    /**
     * Validate expense type field against preloaded options
     *
     * @param string $expenseType Expense type from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateExpenseType(string $expenseType, int $lineNumber): array
    {
        $expenseType = trim($expenseType);

        if (empty($expenseType)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Expense Type',
                        'error' => 'Expense Type is required',
                        'provided_value' => $expenseType
                    ]
                ]
            ];
        }

        // Find matching expense type from preloaded data
        $matchingType = $this->expenseTypes->firstWhere('option', $expenseType);

        if (!$matchingType) {
            $availableTypes = $this->expenseTypes->pluck('option')->join(', ');
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Expense Type',
                        'error' => 'Invalid expense type. Available options: ' . $availableTypes,
                        'provided_value' => $expenseType
                    ]
                ]
            ];
        }

        return [
            'valid' => true,
            'value' => $matchingType->id,
            'errors' => []
        ];
    }

    /**
     * Validate currency field (3-letter ISO code)
     *
     * @param string $currency Currency code from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateCurrency(string $currency, int $lineNumber): array
    {
        $currency = strtoupper(trim($currency));

        if (empty($currency)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Currency',
                        'error' => 'Currency is required',
                        'provided_value' => $currency
                    ]
                ]
            ];
        }

        if (strlen($currency) !== 3) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Currency',
                        'error' => 'Currency must be exactly 3 characters (ISO format)',
                        'provided_value' => $currency
                    ]
                ]
            ];
        }

        if (!in_array($currency, self::ALLOWED_CURRENCIES)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Currency',
                        'error' => 'Unsupported currency. Allowed currencies: ' . implode(', ', self::ALLOWED_CURRENCIES),
                        'provided_value' => $currency
                    ]
                ]
            ];
        }

        return [
            'valid' => true,
            'value' => $currency,
            'errors' => []
        ];
    }

    /**
     * Validate amount field with sign based on expense type
     *
     * @param string $amount Amount from CSV
     * @param int|null $expenseTypeId Expense type ID for sign determination
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateAmount(string $amount, ?int $expenseTypeId, int $lineNumber): array
    {
        $amount = trim($amount);

        if (empty($amount)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Amount',
                        'error' => 'Amount is required',
                        'provided_value' => $amount
                    ]
                ]
            ];
        }

        // Remove currency symbols and whitespace
        $cleanAmount = preg_replace('/[^\d.-]/', '', $amount);

        if (!is_numeric($cleanAmount)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Amount',
                        'error' => 'Amount must be a valid number',
                        'provided_value' => $amount
                    ]
                ]
            ];
        }

        $numericAmount = floatval($cleanAmount);

        // Check for zero or negative absolute values
        if ($numericAmount <= 0) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Amount',
                        'error' => 'Amount must be greater than zero',
                        'provided_value' => $amount
                    ]
                ]
            ];
        }

        // Apply sign based on expense type
        if ($expenseTypeId) {
            $expenseType = $this->expenseTypes->find($expenseTypeId);
            if ($expenseType) {
                // Apply sign convention: positive for refunds, negative for others
                if ($expenseType->amount_sign === 'positive') {
                    $finalAmount = abs($numericAmount);
                } else {
                    $finalAmount = -abs($numericAmount);
                }
            } else {
                $finalAmount = -abs($numericAmount); // Default to negative
            }
        } else {
            $finalAmount = -abs($numericAmount); // Default to negative if type unknown
        }

        return [
            'valid' => true,
            'value' => round($finalAmount, 2), // Ensure 2 decimal places
            'errors' => []
        ];
    }

    /**
     * Validate VAT percentage field (0-100, strip % sign)
     *
     * @param string $vatPercent VAT percentage from CSV
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateVatPercentage(string $vatPercent, int $lineNumber): array
    {
        $vatPercent = trim($vatPercent);

        // VAT is optional, return null if empty
        if (empty($vatPercent)) {
            return [
                'valid' => true,
                'value' => null,
                'errors' => []
            ];
        }

        // Strip % sign if present
        $cleanVat = str_replace('%', '', $vatPercent);
        $cleanVat = trim($cleanVat);

        if (!is_numeric($cleanVat)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'VAT %',
                        'error' => 'VAT % must be a valid number between 0 and 100',
                        'provided_value' => $vatPercent
                    ]
                ]
            ];
        }

        $vatValue = floatval($cleanVat);

        if ($vatValue < 0 || $vatValue > 100) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'VAT %',
                        'error' => 'VAT % must be between 0 and 100',
                        'provided_value' => $vatPercent
                    ]
                ]
            ];
        }

        return [
            'valid' => true,
            'value' => round($vatValue, 2),
            'errors' => []
        ];
    }

    /**
     * Validate expense source field against client-configured sources
     *
     * @param string $source Expense source from CSV
     * @param int $clientId Client ID for scoped validation
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateSource(string $source, int $clientId, int $lineNumber): array
    {
        $source = trim($source);

        if (empty($source)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Source',
                        'error' => 'Source is required',
                        'provided_value' => $source
                    ]
                ]
            ];
        }

        // Find matching expense source from preloaded data
        $matchingSource = $this->expenseSources->firstWhere('name', $source);

        if (!$matchingSource) {
            $availableSources = $this->expenseSources->pluck('name')->join(', ');
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Source',
                        'error' => 'Invalid expense source. Available options: ' . $availableSources,
                        'provided_value' => $source
                    ]
                ]
            ];
        }

        return [
            'valid' => true,
            'value' => $matchingSource->id,
            'errors' => []
        ];
    }

    /**
     * Validate source note field (required when Source = Other)
     *
     * @param string $sourceNote Source note from CSV
     * @param string $source Source field value for conditional validation
     * @param int $lineNumber Line number for error reporting
     * @return array Validation result
     */
    private function validateSourceNote(string $sourceNote, string $source, int $lineNumber): array
    {
        $sourceNote = trim($sourceNote);
        $source = trim($source);

        // Source note is required when source is "Other"
        if ($source === 'Other' && empty($sourceNote)) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'Source Note',
                        'error' => 'Source Note is required when Source is "Other"',
                        'provided_value' => $sourceNote
                    ]
                ]
            ];
        }

        return [
            'valid' => true,
            'value' => empty($sourceNote) ? null : $this->sanitizeText($sourceNote),
            'errors' => []
        ];
    }

    /**
     * Sanitize text input to prevent SQL injection and trim whitespace
     *
     * @param string $text Raw text input
     * @return string|null Sanitized text or null if empty
     */
    private function sanitizeText(string $text): ?string
    {
        $text = trim($text);
        
        if (empty($text)) {
            return null;
        }

        // Basic HTML encoding to prevent XSS
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        return $text;
    }

    /**
     * Get validation errors from last validation run
     *
     * @return array Array of validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get loaded expense types for testing/debugging
     *
     * @return Collection Collection of expense types
     */
    public function getLoadedExpenseTypes(): Collection
    {
        return $this->expenseTypes;
    }

    /**
     * Get loaded expense sources for testing/debugging
     *
     * @return Collection Collection of expense sources
     */
    public function getLoadedExpenseSources(): Collection
    {
        return $this->expenseSources;
    }
}