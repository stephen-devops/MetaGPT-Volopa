## Code: app/Services/PocketExpenseCSVValidator.php

```php
<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\TransactionCategory;
use App\Models\TrackingCode;
use App\Models\ConfigurableProject;
use App\Models\ExpenseAdditionalField;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

class PocketExpenseCSVValidator
{
    /**
     * Maximum CSV rows allowed per file.
     *
     * @var int
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Maximum date lookback in years for expense dates.
     *
     * @var int
     */
    private const MAX_DATE_LOOKBACK_YEARS = 3;

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    private const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Maximum length for notes field.
     *
     * @var int
     */
    private const NOTES_MAX_LENGTH = 2000;

    /**
     * Maximum length for source note field.
     *
     * @var int
     */
    private const SOURCE_NOTE_MAX_LENGTH = 500;

    /**
     * Valid currency codes (ISO 3-letter format).
     *
     * @var array<string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'INR', 'KRW', 'THB', 'BRL', 'ZAR', 'RUB',
        'PLN', 'CZK', 'HUF', 'TRY', 'ILS', 'AED', 'SAR', 'EGP', 'QAR', 'KWD'
    ];

    /**
     * Required CSV headers (must match exactly).
     *
     * @var array<string>
     */
    private const REQUIRED_CSV_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Address',
        'VAT %',
        'Source',
        'Source Note',
        'Notes'
    ];

    /**
     * Global "Other" source name.
     *
     * @var string
     */
    private const GLOBAL_OTHER_SOURCE = 'Other';

    /**
     * Cached reference data for validation.
     *
     * @var array<string, mixed>
     */
    private array $referenceData = [];

    /**
     * Validate CSV file and return validation results.
     *
     * @param string $filePath
     * @param int $targetUserId
     * @param int $clientId
     * @param int $adminId
     * @return array<string, mixed>
     */
    public function validate(string $filePath, int $targetUserId, int $clientId, int $adminId): array
    {
        // Validate input parameters
        if (empty($filePath) || !file_exists($filePath)) {
            throw new InvalidArgumentException('File path is invalid or file does not exist');
        }

        if ($targetUserId <= 0 || $clientId <= 0 || $adminId <= 0) {
            throw new InvalidArgumentException('User IDs and Client ID must be positive integers');
        }

        try {
            // Preload reference data
            $this->preloadReferenceData($clientId);

            // Read CSV file
            $csvData = $this->readCSVFile($filePath);
            
            if (empty($csvData)) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line' => 0,
                            'field' => 'file',
                            'message' => 'CSV file is empty or could not be read'
                        ]
                    ],
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'validated_rows' => []
                ];
            }

            // Validate headers
            if (!$this->validateHeaders(array_keys($csvData[0]))) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line' => 1,
                            'field' => 'headers',
                            'message' => 'CSV headers do not match required format. Expected: ' . implode(', ', self::REQUIRED_CSV_HEADERS)
                        ]
                    ],
                    'total_rows' => count($csvData),
                    'valid_rows' => 0,
                    'validated_rows' => []
                ];
            }

            // Check row count limit
            if (count($csvData) > self::MAX_CSV_ROWS) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line' => 0,
                            'field' => 'file',
                            'message' => "CSV file contains " . count($csvData) . " rows, maximum allowed is " . self::MAX_CSV_ROWS
                        ]
                    ],
                    'total_rows' => count($csvData),
                    'valid_rows' => 0,
                    'validated_rows' => []
                ];
            }

            // Validate each row
            $errors = [];
            $validRows = [];
            $lineNumber = 2; // Start from line 2 (after header)

            foreach ($csvData as $row) {
                $rowErrors = $this->validateRow($row, $lineNumber);
                
                if (empty($rowErrors)) {
                    // Row is valid, prepare for processing
                    $validRows[] = $this->prepareValidatedRow($row, $targetUserId, $clientId, $adminId, $lineNumber);
                } else {
                    $errors = array_merge($errors, $rowErrors);
                }
                
                $lineNumber++;
            }

            // All-or-nothing validation: if any row fails, no expenses are created
            $isValid = empty($errors);

            return [
                'valid' => $isValid,
                'errors' => $errors,
                'total_rows' => count($csvData),
                'valid_rows' => count($validRows),
                'validated_rows' => $isValid ? $validRows : []
            ];

        } catch (Exception $e) {
            Log::error('CSV validation failed', [
                'file_path' => $filePath,
                'target_user_id' => $targetUserId,
                'client_id' => $clientId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'errors' => [
                    [
                        'line' => 0,
                        'field' => 'file',
                        'message' => 'Failed to validate CSV file: ' . $e->getMessage()
                    ]
                ],
                'total_rows' => 0,
                'valid_rows' => 0,
                'validated_rows' => []
            ];
        }
    }

    /**
     * Validate CSV headers.
     *
     * @param array<string> $headers
     * @return bool
     */
    public function validateHeaders(array $headers): bool
    {
        // Trim headers and check exact match
        $trimmedHeaders = array_map('trim', $headers);
        
        return $trimmedHeaders === self::REQUIRED_CSV_HEADERS;
    }

    /**
     * Validate a single CSV row.
     *
     * @param array<string, mixed> $row
     * @