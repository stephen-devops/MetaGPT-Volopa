## Code: app/Services/PocketExpenseCSVValidator.php

```php
<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PocketExpenseCSVValidator
{
    /**
     * Target user ID for expense creation.
     */
    private int $targetUserId;

    /**
     * Client ID for validation context.
     */
    private int $clientId;

    /**
     * Admin user ID who is performing the upload.
     */
    private int $adminId;

    /**
     * Cached reference data for validation.
     */
    private array $referenceData = [];

    /**
     * Maximum date age in years.
     */
    private const MAX_DATE_AGE_YEARS = 3;

    /**
     * Supported currency codes.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR', 'KRW',
        'DKK', 'PLN', 'TWD', 'THB', 'MYR', 'HUF', 'CZK', 'ILS', 'CLP', 'PHP',
        'AED', 'COP', 'SAR', 'RON', 'BGN', 'HRK', 'ISK', 'LBP', 'EGP', 'JOD',
    ];

    /**
     * Supported country names.
     */
    private const SUPPORTED_COUNTRIES = [
        'Afghanistan', 'Albania', 'Algeria', 'Argentina', 'Australia', 'Austria',
        'Bangladesh', 'Belgium', 'Brazil', 'Bulgaria', 'Canada', 'Chile', 'China',
        'Colombia', 'Croatia', 'Czech Republic', 'Denmark', 'Egypt', 'Finland',
        'France', 'Germany', 'Greece', 'Hong Kong', 'Hungary', 'Iceland', 'India',
        'Indonesia', 'Ireland', 'Israel', 'Italy', 'Japan', 'Jordan', 'Korea',
        'Lebanon', 'Malaysia', 'Mexico', 'Netherlands', 'New Zealand', 'Norway',
        'Philippines', 'Poland', 'Portugal', 'Romania', 'Russia', 'Saudi Arabia',
        'Singapore', 'South Africa', 'Spain', 'Sweden', 'Switzerland', 'Thailand',
        'Turkey', 'United Arab Emirates', 'United Kingdom', 'United States',
    ];

    /**
     * CSV column mapping.
     */
    private const CSV_COLUMN_MAPPING = [
        'Date' => 'date',
        'Expense Type' => 'transaction_type',
        'Currency Code' => 'currency',
        'Amount' => 'amount',
        'Currency Equivalent Amount' => 'user_converted_amount',
        'VAT %' => 'vat_amount',
        'Merchant Name' => 'merchant_name',
        'Description' => 'merchant_description',
        'Merchant Address' => 'merchant_address',
        'Merchant Country' => 'merchant_country',
        'Source' => 'source',
        'Source Note' => 'source_note',
        'Notes' => 'notes',
    ];

    /**
     * Required CSV columns.
     */
    private const REQUIRED_COLUMNS = [
        'Date',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Name',
    ];

    /**
     * Constructor.
     */
    public function __construct(int $targetUserId, int $clientId, int $adminId)
    {
        $this->targetUserId = $targetUserId;
        $this->clientId = $clientId;
        $this->adminId = $adminId;
        
        $this->preloadReferenceData();
    }

    /**
     * Validate entire CSV file.
     */
    public function validateCsv(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'error_count' => 1,
                    'errors' => [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'File not found',
                            'value' => $filePath,
                        ],
                    ],
                ];
            }

            $handle = fopen($filePath, 'r');
            if (!$handle) {
                return [
                    'success' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'error_count' => 1,
                    'errors' => [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'Unable to open file for reading',
                            'value' => $filePath,
                        ],
                    ],
                ];
            }

            // Read header row
            $headers = fgetcsv($handle);
            if ($headers === false || empty($headers)) {
                fclose($handle);
                return [
                    'success' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'error_count' => 1,
                    'errors' => [
                        [
                            'line_number' => 1,
                            'field' => 'headers',
                            'error' => 'No header row found',
                            'value' => '',
                        ],
                    ],
                ];
            }

            // Trim headers
            $headers = array_map('trim', $headers);

            // Validate headers
            $headerValidation = $this->validateHeaders($headers);
            if (!$headerValidation['success']) {
                fclose($handle);
                return $headerValidation;
            }

            // Create column mapping
            $columnMapping = $this->createColumnMapping($headers);

            $totalRows = 0;
            $validRows = 0;
            $allErrors = [];
            $lineNumber = 1; // Header is line 1, data starts from line 2

            // Process each data row
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;
                
                // Skip empty rows
                if (array_filter($row, function($value) { return trim($value) !== ''; }) === []) {
                    continue;
                }

                $totalRows++;

                // Map row data to field names
                $mappedData = $this->mapRowData($row, $columnMapping);
                
                // Validate row
                $rowValidation = $this->validateRow($mappedData, $lineNumber);
                
                if ($rowValidation['success']) {
                    $validRows++;
                } else {
                    $allErrors = array_merge($allErrors, $rowValidation['errors']);
                }
            }

            fclose($handle);

            $success = count($allErrors) === 0;

            Log::info('CSV validation completed', [
                'file_path' => $filePath,
                'target_user_id' => $this->targetUserId,
                'client_id' => $this->clientId,
                'admin_id' => $this->adminId,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'error_count' => count($allErrors),
                'success' => $success,
            ]);

            return [
                'success' => $success,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'error_count' => count($allErrors),
                'errors' => $allErrors,
            ];

        } catch (\Exception $e) {
            Log::error('CSV validation error', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'error_count' => 1,
                'errors' => [
                    [
                        'line_number' => 0,
                        'field' => 'system',
                        'error' => 'System error during validation: ' . $e->getMessage(),
                        'value' => '',
                    ],
                ],
            ];
        }
    }

    /**
     * Validate a single row of data.
     */
    public function validateRow(array $rowData, int $lineNumber): array
    {
        $errors = [];

        try {
            // Validate data types first
            $dataTypeErrors = $this->validateDataTypes($rowData, $lineNumber);
            $errors = array_merge($errors, $dataTypeErrors);

            // Only proceed with cross-field validation if basic data types are valid
            if (empty($dataTypeErrors)) {
                $crossFieldErrors = $this->validateCrossFields($rowData, $lineNumber);
                $errors = array_merge($errors, $crossFieldErrors);
            }

            return [
                'success' => empty($errors),
                'errors' => $errors,
            ];

        } catch (\Exception $e) {
            Log::error('Row validation error', [
                'line_number' => $lineNumber,
                'row_data' => $rowData,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'errors' => [
                    [
                        'line_number' => $lineNumber,
                        'field' => 'system',
                        'error' => 'System error validating row: ' . $e->getMessage(),
                        'value' => '',
                    ],
                ],
            ];
        }
    }

    /**
     * Validate data types for a row.
     */
    public function validateDataTypes(array $rowData): array
    {
        $errors = [];

        // Validate Date
        if (empty($rowData['date'])) {
            $errors[] = [
                'field' => 'Date',
                'error' => 'Date is required',
                'value' => $rowData['date'] ?? '',
            ];
        } else {
            $dateError = $this->validateDate($rowData['date']);
            if ($dateError) {
                $errors[] = [
                    'field' => 'Date',
                    'error' => $dateError,
                    'value' => $rowData['date'],
                ];
            }
        }

        // Validate Expense Type
        if (empty($rowData['transaction_type'])) {
            $errors[] = [
                'field' => 'Expense Type',
                'error' => 'Expense Type is required',
                'value' => $rowData['transaction_type'] ?? '',
            ];
        } else {
            $expenseTypeError = $this->validateExpenseType($rowData['transaction_type']);
            if ($expenseTypeError) {
                $errors[] = [
                    'field' => 'Expense Type',
                    'error' => $expenseTypeError,
                    'value' => $rowData['transaction_type'],
                ];
            }
        }

        // Validate Currency Code
        if (empty($rowData['currency'])) {
            $errors[] = [
                'field' => 'Currency Code',
                'error' => 'Currency Code is required',
                'value' => $rowData['currency'] ?? '',
            ];
        } else {
            $currencyError = $this->validateCurrency($rowData['currency']);
            if ($currencyError) {
                $errors[] = [
                    'field' => 'Currency Code',
                    'error' => $currencyError,
                    'value' => $rowData['currency'],
                ];
            }
        }

        // Validate Amount
        if (empty($rowData['amount']) && $rowData['amount'] !== '0') {
            $errors[] = [
                'field' => 'Amount',
                'error' => 'Amount is required',
                'value' => $rowData['amount'] ?? '',
            ];
        } else {
            $amountError = $this->validateAmount($rowData['amount']);
            if ($amountError) {
                $errors[] = [
                    'field' => 'Amount',
                    'error' => $amountError,
                    'value' => $rowData['amount'],
                ];
            }
        }

        // Validate Merchant Name
        if (empty($rowData['merchant_name'])) {
            $errors[] = [
                'field' => 'Merchant Name',
                'error' => 'Merchant Name is required',
                'value' => $rowData['merchant_name'] ?? '',
            ];
        } else {
            $merchantError = $this->validateMerchantName($rowData['merchant_name']);
            if ($merchantError) {
                $errors[] = [
                    'field' => 'Merchant Name',
                    'error' => $merchantError,
                    'value' => $rowData['merchant_name'],
                ];
            }
        }

        // Validate optional fields
        if (!empty($rowData['user_converted_amount'])) {
            $convertedAmountError = $this->validateConvertedAmount($rowData['user_converted_amount']);
            if ($convertedAmountError) {
                $errors[] = [
                    'field' => 'Currency Equivalent Amount',
                    'error' => $convertedAmountError,
                    'value' => $rowData['user_converted_amount'],
                ];
            }
        }

        if (!empty($rowData['vat_amount'])) {
            $vatError = $this->validateVat($rowData['vat_amount']);
            if ($vatError) {
                $errors[] = [
                    'field' => 'VAT %',
                    'error' => $vatError,
                    'value' => $rowData['vat_amount'],
                ];
            }
        }

        if (!empty($rowData['merchant_country'])) {
            $countryError = $this->validateCountry($rowData['merchant_country']);
            if ($countryError) {
                $errors[] = [
                    'field' => 'Merchant Country',
                    'error' => $countryError,
                    'value' => $rowData['merchant_country'],
                ];
            }
        }

        if (!empty($rowData['source'])) {
            $sourceError = $this->validateSource($rowData['source']);
            if ($sourceError) {
                $errors[] = [
                    'field' => 'Source',
                    'error' => $sourceError,
                    'value' => $rowData['source'],
                ];
            }
        }

        // Validate field lengths
        $lengthErrors = $this->validateFieldLengths($rowData);
        $errors = array_merge($errors, $lengthErrors);

        return $errors;
    }

    /**
     * Validate cross-field relationships.
     */
    public function validateCrossFields(array $rowData): array
    {
        $errors = [];

        // Validate Source Note requirement when Source is "Other"
        if (!empty($rowData['source']) && 
            strtolower(trim($rowData['source'])) === 'other' && 
            empty($rowData['source_note'])) {
            $errors[] = [
                'field' => 'Source Note',
                'error' => 'Required when Source is "Other"',
                'value' => $rowData['source_note'] ?? '',
            ];
        }

        // Validate amount sign based on expense type
        if (!empty($rowData