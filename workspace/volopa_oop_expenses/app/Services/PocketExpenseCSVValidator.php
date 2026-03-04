## Code: app/Services/PocketExpenseCSVValidator.php

```php
<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\TransactionCategory;
use App\Models\TrackingCode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

/**
 * PocketExpenseCSVValidator
 * 
 * Service class for validating CSV files for pocket expense batch processing.
 * Handles CSV structure validation, data validation, and reference data preloading
 * with comprehensive error reporting and multi-tenant support.
 * 
 * Business Rules:
 * - Header row mandatory in CSV and must exactly match required column names
 * - Date format for CSV: DD/MM/YYYY (DD-MM-YYYY in template)
 * - Currency Code must be 3-letter ISO format and validated against platform list
 * - VAT percentage must be numeric between 0-100 with % sign stripped
 * - Expense source must match configured sources for client including global 'Other'
 * - Source Note required when expense source equals 'Other'
 * - Maximum 200 rows per CSV file for batch upload processing
 * - All-or-nothing validation: if any CSV row fails validation, no expense records are created
 * - Date validation: expenses cannot be older than 3 years from current date
 * - Amount sign determined by expense type: Refund = positive, others = negative
 * - Merchant Name maximum length 180 characters per database VARCHAR definition
 * - Trim notes field and prevent SQL injection, respect database limits
 */
class PocketExpenseCSVValidator
{
    /**
     * Required CSV header columns in exact order.
     *
     * @var array<int, string>
     */
    private const REQUIRED_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'VAT Amount',
        'Merchant Address',
        'Notes',
        'Source',
        'Source Note',
        'Category',
        'Tracking Code',
        'Project',
    ];

    /**
     * Maximum number of rows allowed per CSV file.
     *
     * @var int
     */
    private const MAX_ROWS_PER_FILE = 200;

    /**
     * Date format expected in CSV.
     *
     * @var string
     */
    private const CSV_DATE_FORMAT = 'DD/MM/YYYY';

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    private const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Maximum age in years for expense date validation.
     *
     * @var int
     */
    private const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Valid 3-letter ISO currency codes.
     *
     * @var array<int, string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'KRW', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR',
        'PLN', 'DKK', 'CZK', 'HUF', 'ILS', 'AED', 'SAR', 'THB', 'MYR', 'PHP'
    ];

    /**
     * Global 'Other' source name.
     *
     * @var string
     */
    private const GLOBAL_OTHER_SOURCE = 'Other';

    /**
     * Preloaded reference data cache.
     *
     * @var array<string, mixed>
     */
    private array $referenceData = [];

    /**
     * Current client ID for validation context.
     *
     * @var int|null
     */
    private ?int $clientId = null;

    /**
     * Validation errors collected during processing.
     *
     * @var array<int, array>
     */
    private array $validationErrors = [];

    /**
     * Valid expense data rows after validation.
     *
     * @var array<int, array>
     */
    private array $validRows = [];

    /**
     * Total number of data rows processed (excluding header).
     *
     * @var int
     */
    private int $totalDataRows = 0;

    /**
     * Validate CSV file and return validation results.
     *
     * @param string $filePath Path to the uploaded CSV file
     * @param int $targetUserId User ID for whom expenses will be created
     * @param int $clientId Client context for multi-tenancy
     * @param int $adminId User ID of the administrator uploading the file
     * @return array Validation result with errors or valid data
     * 
     * @throws InvalidArgumentException If file path or parameters are invalid
     * @throws Exception If file processing fails
     */
    public function validateCSV(string $filePath, int $targetUserId, int $clientId, int $adminId): array
    {
        // Validate input parameters
        if (empty($filePath) || !file_exists($filePath)) {
            throw new InvalidArgumentException('Invalid file path or file does not exist.');
        }

        if ($targetUserId <= 0 || $clientId <= 0 || $adminId <= 0) {
            throw new InvalidArgumentException('User ID and Client ID must be positive integers.');
        }

        // Validate that target user exists and belongs to client
        $targetUser = User::where('id', $targetUserId)
            ->where('client_id', $clientId)
            ->first();

        if (!$targetUser) {
            throw new InvalidArgumentException('Target user does not exist or does not belong to the specified client.');
        }

        // Validate that admin user exists and belongs to client
        $adminUser = User::where('id', $adminId)
            ->where('client_id', $clientId)
            ->first();

        if (!$adminUser) {
            throw new InvalidArgumentException('Admin user does not exist or does not belong to the specified client.');
        }

        // Reset validation state
        $this->clientId = $clientId;
        $this->validationErrors = [];
        $this->validRows = [];
        $this->totalDataRows = 0;

        try {
            // Preload reference data for validation
            $this->preloadReferenceData($clientId);

            // Open and process CSV file
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new Exception('Unable to open CSV file for reading.');
            }

            // Read and validate headers
            $headers = fgetcsv($handle);
            if ($headers === false) {
                fclose($handle);
                throw new Exception('Unable to read CSV headers.');
            }

            $headerValidation = $this->validateHeaders($headers);
            if (!empty($headerValidation)) {
                fclose($handle);
                return [
                    'valid' => false,
                    'errors' => $headerValidation,
                    'total_rows' => 0,
                    'valid_rows' => 0
                ];
            }

            // Process data rows
            $lineNumber = 2; // Start from line 2 (after header)
            while (($row = fgetcsv($handle)) !== false && $lineNumber <= (self::MAX_ROWS_PER_FILE + 1)) {
                // Skip empty rows
                if (empty(array_filter($row, 'strlen'))) {
                    $lineNumber++;
                    continue;
                }

                $this->totalDataRows++;

                // Validate individual row
                $rowValidation = $this->validateRow($row, $lineNumber);
                if (!empty($rowValidation['errors'])) {
                    $this->validationErrors[]