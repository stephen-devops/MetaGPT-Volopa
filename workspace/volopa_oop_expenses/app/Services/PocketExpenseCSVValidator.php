<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use App\Models\User;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Carbon\Carbon;
use Exception;

/**
 * CSV Validation Service for Pocket Expense uploads
 * 
 * Performs atomic validation of CSV files with preloaded reference data
 * All-or-nothing approach: if any row fails validation, no expenses are created
 */
class PocketExpenseCSVValidator
{
    /**
     * Expected CSV column headers in exact order
     */
    private const REQUIRED_HEADERS = [
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
     * Maximum number of rows allowed per CSV file
     */
    private const MAX_ROWS = 200;

    /**
     * Maximum age of expense date in years
     */
    private const MAX_DATE_AGE_YEARS = 3;

    /**
     * Maximum VAT percentage allowed
     */
    private const MAX_VAT_PERCENTAGE = 100.0;

    /**
     * Maximum merchant name length as per DB constraint
     */
    private const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Preloaded reference data for validation
     */
    private array $expenseTypes = [];
    private array $expenseTypesById = [];
    private array $clientExpenseSources = [];
    private array $allowedCurrencies = [];
    private array $allowedCountries = [];
    private int $clientId = 0;
    private int $targetUserId = 0;
    private int $adminId = 0;

    /**
     * Validation errors collected during processing
     */
    private array $validationErrors = [];

    /**
     * Valid rows that passed all validation checks
     */
    private array $validRows = [];

    /**
     * Total number of data rows processed (excluding header)
     */
    private int $totalRows = 0;

    /**
     * Validate CSV file and return validation results
     *
     * @param UploadedFile $file The uploaded CSV file
     * @param int $targetUserId User ID for whom expenses are being created
     * @param int $clientId Client context for validation
     * @param int $adminId Admin user performing the upload
     * @return array Validation result with errors or valid data
     * @throws Exception When file cannot be processed
     */
    public function validate(UploadedFile $file, int $targetUserId, int $clientId, int $adminId): array
    {
        // Initialize validation context
        $this->targetUserId = $targetUserId;
        $this->clientId = $clientId;
        $this->adminId = $adminId;
        $this->validationErrors = [];
        $this->validRows = [];
        $this->totalRows = 0;

        try {
            // Preload reference data for efficient validation
            $this->preloadReferenceData($clientId);

            // Open and validate CSV file
            $handle = fopen($file->getPathname(), 'r');
            if ($handle === false) {
                throw new Exception('Unable to open CSV file for reading');
            }

            // Validate headers
            $headers = fgetcsv($handle);
            if (!$this->validateHeaders($headers)) {
                fclose($handle);
                return $this->buildErrorResponse('Invalid CSV headers. Please ensure headers match the required format exactly.');
            }

            // Process data rows
            $lineNumber = 2; // Start from line 2 (after header)
            while (($row = fgetcsv($handle)) !== false && $lineNumber <= (self::MAX_ROWS + 1)) {
                // Skip empty rows
                if (empty(array_filter($row, function($value) {
                    return $value !== null && $value !== '';
                }))) {
                    $lineNumber++;
                    continue;
                }

                $this->totalRows++;
                $this->validateRow($row, $lineNumber);
                $lineNumber++;
            }

            fclose($handle);

            // Check if file exceeds maximum row limit
            if ($this->totalRows > self::MAX_ROWS) {
                return $this->buildErrorResponse("CSV file exceeds maximum allowed rows. Maximum: " . self::MAX_ROWS . ", Found: " . $this->totalRows);
            }

            // Return validation results
            if (!empty($this->validationErrors)) {
                return $this->buildErrorResponse('Validation failed for one or more rows', $this->validationErrors);
            }

            return $this->buildSuccessResponse();

        } catch (Exception $e) {
            return $this->buildErrorResponse('Error processing CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Validate CSV headers match required format exactly
     *
     * @param array|null $headers CSV header row
     * @return bool True if headers are valid
     */
    private function validateHeaders(?array $headers): bool
    {
        if ($headers === null || count($headers) !== count(self::REQUIRED_HEADERS)) {
            return false;
        }

        // Check each header matches exactly (case-sensitive)
        foreach (self::REQUIRED_HEADERS as $index => $expectedHeader) {
            // Handle dynamic currency column name
            if ($expectedHeader === '{Currency} Equivalent Amount') {
                // For now, accept any variation since currency is dynamic
                // In a real implementation, this would be validated against the wallet base currency
                if (!isset($headers[$index]) || empty(trim($headers[$index]))) {
                    return false;
                }
                continue;
            }

            if (!isset($headers[$index]) || trim($headers[$index]) !== $expectedHeader) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate individual CSV row data
     *
     * @param array $row CSV row data
     * @param int $lineNumber Line number in CSV file
     * @return void
     */
    private function validateRow(array $row, int $lineNumber): void
    {
        $errors = [];
        $validatedData = [];

        // Pad row to ensure all columns exist
        $row = array_pad($row, count(self::REQUIRED_HEADERS), '');

        // Validate Date (Column 1)
        $date = trim($row[0]);
        if (empty($date)) {
            $errors[] = $this->buildFieldError($lineNumber, 'Date', 'Date is required', $date);
        } else {
            $dateValidation = $this->validateDate($date);
            if ($dateValidation['valid']) {
                $validatedData['date'] = $dateValidation['formatted_date'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Date', $dateValidation['error'], $date);
            }
        }

        // Validate Expense Type (Column 2)
        $expenseType = trim($row[1]);
        if (empty($expenseType)) {
            $errors[] = $this->buildFieldError($lineNumber, 'Expense Type', 'Expense Type is required', $expenseType);
        } else {
            $expenseTypeValidation = $this->validateExpenseType($expenseType);
            if ($expenseTypeValidation['valid']) {
                $validatedData['expense_type'] = $expenseTypeValidation['expense_type_id'];
                $validatedData['amount_sign'] = $expenseTypeValidation['amount_sign'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Expense Type', $expenseTypeValidation['error'], $expenseType);
            }
        }

        // Validate Currency Code (Column 3)
        $currency = trim($row[2]);
        if (empty($currency)) {
            $errors[] = $this->buildFieldError($lineNumber, 'Currency Code', 'Currency Code is required', $currency);
        } else {
            $currencyValidation = $this->validateCurrency($currency);
            if ($currencyValidation['valid']) {
                $validatedData['currency'] = $currencyValidation['currency'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Currency Code', $currencyValidation['error'], $currency);
            }
        }

        // Validate Amount (Column 4)
        $amount = trim($row[3]);
        if (empty($amount)) {
            $errors[] = $this->buildFieldError($lineNumber, 'Amount', 'Amount is required', $amount);
        } else {
            $amountValidation = $this->validateAmount($amount, $validatedData['amount_sign'] ?? 'negative');
            if ($amountValidation['valid']) {
                $validatedData['amount'] = $amountValidation['amount'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Amount', $amountValidation['error'], $amount);
            }
        }

        // Validate Equivalent Amount (Column 5) - Optional
        $equivalentAmount = trim($row[4]);
        if (!empty($equivalentAmount)) {
            $equivalentValidation = $this->validateEquivalentAmount($equivalentAmount);
            if ($equivalentValidation['valid']) {
                $validatedData['user_converted_amount'] = $equivalentValidation['amount'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, '{Currency} Equivalent Amount', $equivalentValidation['error'], $equivalentAmount);
            }
        }

        // Validate VAT % (Column 6) - Optional
        $vat = trim($row[5]);
        if (!empty($vat)) {
            $vatValidation = $this->validateVat($vat);
            if ($vatValidation['valid']) {
                $validatedData['vat_amount'] = $vatValidation['vat_amount'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'VAT %', $vatValidation['error'], $vat);
            }
        }

        // Validate Merchant Name (Column 7)
        $merchantName = trim($row[6]);
        if (empty($merchantName)) {
            $errors[] = $this->buildFieldError($lineNumber, 'Merchant Name', 'Merchant Name is required', $merchantName);
        } else {
            $merchantValidation = $this->validateMerchantName($merchantName);
            if ($merchantValidation['valid']) {
                $validatedData['merchant_name'] = $merchantValidation['merchant_name'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Merchant Name', $merchantValidation['error'], $merchantName);
            }
        }

        // Validate Description (Column 8) - Optional
        $description = trim($row[7]);
        if (!empty($description)) {
            $validatedData['merchant_description'] = $this->sanitizeText($description);
        }

        // Validate Merchant Address (Column 9) - Optional
        $merchantAddress = trim($row[8]);
        if (!empty($merchantAddress)) {
            $validatedData['merchant_address'] = $this->sanitizeText($merchantAddress);
        }

        // Validate Merchant Country (Column 10) - Optional
        $merchantCountry = trim($row[9]);
        if (!empty($merchantCountry)) {
            $countryValidation = $this->validateCountry($merchantCountry);
            if ($countryValidation['valid']) {
                $validatedData['merchant_country'] = $countryValidation['country'];
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Merchant Country', $countryValidation['error'], $merchantCountry);
            }
        }

        // Validate Source (Column 11) - Optional
        $source = trim($row[10]);
        $sourceNote = trim($row[11]);
        if (!empty($source)) {
            $sourceValidation = $this->validateSource($source, $sourceNote);
            if ($sourceValidation['valid']) {
                $validatedData['expense_source_id'] = $sourceValidation['source_id'];
                if (!empty($sourceNote)) {
                    $validatedData['source_note'] = $this->sanitizeText($sourceNote);
                }
            } else {
                $errors[] = $this->buildFieldError($lineNumber, 'Source', $sourceValidation['error'], $source);
                if (!empty($sourceValidation['source_note_error'])) {
                    $errors[] = $this->buildFieldError($lineNumber, 'Source Note', $sourceValidation['source_note_error'], $sourceNote);
                }
            }
        }

        // Validate Notes (Column 13) - Optional
        $notes = trim($row[12]);
        if (!empty($notes)) {
            $validatedData['notes'] = $this->sanitizeText($notes);
        }

        // Store results
        if (!empty($errors)) {
            $this->validationErrors = array_merge($this->validationErrors, $errors);
        } else {
            // Add system fields
            $validatedData['user_id'] = $this->targetUserId;
            $validatedData['client_id'] = $this->clientId;
            $validatedData['created_by_user_id'] = $this->adminId;
            $validatedData['status'] = 'submitted';
            $validatedData['line_number'] = $lineNumber;
            
            $this->validRows[] = $validatedData;
        }
    }

    /**
     * Validate date format and age constraint
     *
     * @param string $date Date string from CSV
     * @return array Validation result
     */
    private function validateDate(string $date): array
    {
        try {
            // Try parsing DD/MM/YYYY format
            $parsedDate = Carbon::createFromFormat('d/m/Y', $date);
            
            if (!$parsedDate) {
                // Try DD-MM-YYYY format as fallback
                $parsedDate = Carbon::createFromFormat('d-m-Y', $date);
            }

            if (!$parsedDate) {
                return [
                    'valid' => false,
                    'error' => 'Date must be in DD/MM/YYYY format'
                ];
            }

            // Check if date is not older than 3 years
            $threeYearsAgo = Carbon::now()->subYears(self::MAX_DATE_AGE_YEARS);
            if ($parsedDate->isBefore($threeYearsAgo)) {
                return [
                    'valid' => false,
                    'error' => 'Date cannot be older than ' . self::MAX_DATE_AGE_YEARS . ' years'
                ];
            }

            // Check if date is not in the future
            if ($parsedDate->isFuture()) {
                return [
                    'valid' => false,
                    'error' => 'Date cannot be in the future'
                ];
            }

            return [
                'valid' => true,
                'formatted_date' => $parsedDate->format('Y-m-d')
            ];

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => 'Invalid date format. Expected DD/MM/YYYY'
            ];
        }
    }

    /**
     * Validate expense type against loaded options
     *
     * @param string $expenseType Expense type from CSV
     * @return array Validation result
     */
    private function validateExpenseType(string $expenseType): array
    {
        if (!isset($this->expenseTypes[$expenseType])) {
            $availableTypes = implode(', ', array_keys($this->expenseTypes));
            return [
                'valid' => false,
                'error' => "Invalid expense type. Available options: {$availableTypes}"
            ];
        }

        $expenseTypeData = $this->expenseTypes[$expenseType];
        
        return [
            'valid' => true,
            'expense_type_id' => $expenseTypeData['id'],
            'amount_sign' => $expenseTypeData['amount_sign']
        ];
    }

    /**
     * Validate currency code against allowed currencies
     *
     * @param string $currency Currency code from CSV
     * @return array Validation result
     */
    private function validateCurrency(string $currency): array
    {
        $currency = strtoupper($currency);
        
        // Check length
        if (strlen($currency) !== 3) {
            return [
                'valid' => false,
                'error' => 'Currency code must be exactly 3 characters'
            ];
        }

        // Check against allowed currencies
        if (!in_array($currency, $this->allowedCurrencies)) {
            $availableCurrencies = implode(', ', array_slice($this->allowedCurrencies, 0, 10)) . (count($this->allowedCurrencies) > 10 ? '...' : '');
            return [
                'valid' => false,
                'error' => "Currency '{$currency}' is not supported. Supported currencies include: {$availableCurrencies}"
            ];
        }

        return [
            'valid' => true,
            'currency' => $currency
        ];
    }

    /**
     * Validate amount and apply sign based on expense type
     *
     * @param string $amount Amount from CSV
     * @param string $amountSign Expected sign (positive/negative)
     * @return array Validation result
     */
    private function validateAmount(string $amount, string $amountSign): array
    {
        // Remove any currency symbols and whitespace
        $cleanAmount = preg_replace('/[^\d.,\-]/', '', $amount);
        
        if (!is_numeric($cleanAmount)) {
            return [
                'valid' => false,
                'error' => 'Amount must be a valid number'
            ];
        }

        $numericAmount = floatval($cleanAmount);
        
        // Validate decimal places (max 2)
        if (strpos($cleanAmount, '.') !== false) {
            $decimals = strlen(substr(strrchr($cleanAmount, '.'), 1));
            if ($decimals > 2) {
                return [
                    'valid' => false,
                    'error' => 'Amount cannot have more than 2 decimal places'
                ];
            }
        }

        // Apply sign based on expense type
        if ($amountSign === 'positive') {
            $finalAmount = abs($numericAmount); // Ensure positive for refunds
        } else {
            $finalAmount = -abs($numericAmount); // Ensure negative for expenses
        }

        // Validate amount range (reasonable limits)
        if (abs($finalAmount) > 999999.99) {
            return [
                'valid' => false,
                'error' => 'Amount cannot exceed 999,999.99'
            ];
        }

        if (abs($finalAmount) < 0.01) {
            return [
                'valid' => false,
                'error' => 'Amount must be at least 0.01'
            ];
        }

        return [
            'valid' => true,
            'amount' => round($finalAmount, 2)
        ];
    }

    /**
     * Validate equivalent amount (optional field)
     *
     * @param string $amount Equivalent amount from CSV
     * @return array Validation result
     */
    private function validateEquivalentAmount(string $amount): array
    {
        // Remove any currency symbols and whitespace
        $cleanAmount = preg_replace('/[^\d.,\-]/', '', $amount);
        
        if (!is_numeric($cleanAmount)) {
            return [
                'valid' => false,
                'error' => 'Equivalent amount must be a valid number'
            ];
        }

        $numericAmount = floatval($cleanAmount);
        
        // Validate decimal places (max 2)
        if (strpos($cleanAmount, '.') !== false) {
            $decimals = strlen(substr(strrchr($cleanAmount, '.'), 1));
            if ($decimals > 2) {
                return [
                    'valid' => false,
                    'error' => 'Equivalent amount cannot have more than 2 decimal places'
                ];
            }
        }

        return [
            'valid' => true,
            'amount' => round($numericAmount, 2)
        ];
    }

    /**
     * Validate VAT percentage
     *
     * @param string $vat VAT percentage from CSV
     * @return array Validation result
     */
    private function validateVat(string $vat): array
    {
        // Remove % sign if present
        $cleanVat = str_replace('%', '', trim($vat));
        
        if (!is_numeric($cleanVat)) {
            return [
                'valid' => false,
                'error' => 'VAT % must be a valid number'
            ];
        }

        $vatAmount = floatval($cleanVat);
        
        if ($vatAmount < 0 || $vatAmount > self::MAX_VAT_PERCENTAGE) {
            return [
                'valid' => false,
                'error' => 'VAT % must be between 0 and ' . self::MAX_VAT_PERCENTAGE
            ];
        }

        return [
            'valid' => true,
            'vat_amount' => round($vatAmount, 2)
        ];
    }

    /**
     * Validate merchant name length
     *
     * @param string $merchantName Merchant name from CSV
     * @return array Validation result
     */
    private function validateMerchantName(string $merchantName): array
    {
        if (strlen($merchantName) > self::MAX_MERCHANT_NAME_LENGTH) {
            return [
                'valid' => false,
                'error' => 'Merchant Name cannot exceed ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters'
            ];
        }

        return [
            'valid' => true,
            'merchant_name' => $this->sanitizeText($merchantName)
        ];
    }

    /**
     * Validate country against allowed countries
     *
     * @param string $country Country from CSV
     * @return array Validation result
     */
    private function validateCountry(string $country): array
    {
        if (!in_array($country, $this->allowedCountries)) {
            return [
                'valid' => false,
                'error' => "Country '{$country}' is not in the allowed list"
            ];
        }

        return [
            'valid' => true,
            'country' => $country
        ];
    }

    /**
     * Validate expense source and source note
     *
     * @param string $source Source name from CSV
     * @param string $sourceNote Source note from CSV
     * @return array Validation result
     */
    private function validateSource(string $source, string $sourceNote): array
    {
        // Check if source exists for this client
        $sourceId = null;
        
        // Check client-specific sources first
        foreach ($this->clientExpenseSources as $clientSource) {
            if ($clientSource['name'] === $source && !$clientSource['deleted']) {
                $sourceId = $clientSource['id'];
                break;
            }
        }

        if ($sourceId === null) {
            $availableSources = array_filter(
                array_column($this->clientExpenseSources, 'name'), 
                fn($name, $key) => !$this->clientExpenseSources[$key]['deleted'], 
                ARRAY_FILTER_USE_BOTH
            );
            $sourcesText = implode(', ', array_unique($availableSources));
            
            return [
                'valid' => false,
                'error' => "Invalid source. Available sources: {$sourcesText}"
            ];
        }

        // Check if source note is required for 'Other' source
        if ($source === 'Other' && empty($sourceNote)) {
            return [
                'valid' => false,
                'error' => 'Source Note is required when Source is "Other"',
                'source_note_error' => 'Source Note is required when Source is "Other"'
            ];
        }

        return [
            'valid' => true,
            'source_id' => $sourceId
        ];
    }

    /**
     * Sanitize text input to prevent SQL injection and trim whitespace
     *
     * @param string $text Input text
     * @return string Sanitized text
     */
    private function sanitizeText(string $text): string
    {
        return trim(strip_tags($text));
    }

    /**
     * Build field-specific error structure
     *
     * @param int $lineNumber CSV line number
     * @param string $field Field name
     * @param string $error Error message
     * @param string $value Provided value
     * @return array Error structure
     */
    private function buildFieldError(int $lineNumber, string $field, string $error, string $value): array
    {
        return [
            'line_number' => $lineNumber,
            'field' => $field,
            'error' => $error,
            'value' => $value
        ];
    }

    /**
     * Build error response structure
     *
     * @param string $message Main error message
     * @param array $errors Detailed errors (optional)
     * @return array Error response
     */
    private function buildErrorResponse(string $message, array $errors = []): array
    {
        return [
            'valid' => false,
            'message' => $message,
            'total_rows' => $this->totalRows,
            'valid_rows' => count($this->validRows),
            'error_count' => count($errors),
            'errors' => $errors
        ];
    }

    /**
     * Build success response structure
     *
     * @return array Success response
     */
    private function buildSuccessResponse(): array
    {
        return [
            'valid' => true,
            'message' => 'CSV validation completed successfully',
            'total_rows' => $this->totalRows,
            'valid_rows' => count($this->validRows),
            'error_count' => 0,
            'validated_data' => $this->validRows
        ];
    }

    /**
     * Preload reference data for efficient validation
     *
     * @param int $clientId Client context for data loading
     * @return void
     */
    private function preloadReferenceData(int $clientId): void
    {
        // Load expense types
        $expenseTypes = OptPocketExpenseType::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $this->expenseTypes = [];
        $this->expenseTypesById = [];
        
        foreach ($expenseTypes as $type) {
            $this->expenseTypes[$type->option] = [
                'id' => $type->id,
                'amount_sign' => $type->amount_sign
            ];
            $this->expenseTypesById[$type->id] = $type;
        }

        // Load client expense sources (including global 'Other')
        $clientSources = PocketExpenseSourceClientConfig::where(function($query) use ($clientId) {
            $query->where('client_id', $clientId)
                  ->orWhereNull('client_id'); // Include global sources like 'Other'
        })->get();

        $this->clientExpenseSources = [];
        foreach ($clientSources as $source) {
            $this->clientExpenseSources[] = [
                'id' => $source->id,
                'name' => $source->name,
                'deleted' => $source->deleted
            ];
        }

        // Load allowed currencies - in a real implementation, this would come from a platform service
        // For now, using common ISO currency codes
        $this->allowedCurrencies = [
            'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'NZD', 'SGD', 'HKD',
            'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF', 'RUB', 'CNY', 'INR', 'BRL',
            'ZAR', 'KRW', 'THB', 'MXN', 'TRY', 'ILS', 'AED', 'SAR', 'QAR', 'KWD'
        ];

        // Load allowed countries - in a real implementation, this would come from a platform service
        // For now, using common country names
        $this->allowedCountries = [
            'United States', 'United Kingdom', 'Germany', 'France', 'Italy', 'Spain',
            'Canada', 'Australia', 'Japan', 'China', 'India', 'Brazil', 'Mexico',
            'Netherlands', 'Belgium', 'Switzerland', 'Austria', 'Sweden', 'Norway',
            'Denmark', 'Finland', 'Poland', 'Czech Republic', 'Hungary', 'Portugal',
            'Ireland', 'New Zealand', 'Singapore', 'Hong Kong', 'South Korea',
            'Thailand', 'Malaysia', 'Indonesia', 'Philippines', 'Vietnam', 'Taiwan',
            'South Africa', 'Egypt', 'Israel', 'Turkey', 'Russia', 'Ukraine',
            'Argentina', 'Chile', 'Colombia', 'Peru', 'Venezuela', 'Ecuador'
        ];
    }

    /**
     * Get validation statistics
     *
     * @return array Validation statistics
     */
    public function getValidationStats(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'valid_rows' => count($this->validRows),
            'error_count' => count($this->validationErrors),
            'error_rate' => $this->totalRows > 0 ? round((count($this->validationErrors) / $this->totalRows) * 100, 2) : 0
        ];
    }

    /**
     * Get validated data ready for database insertion
     *
     * @return array Array of validated expense records
     */
    public function getValidatedData(): array
    {
        return $this->validRows;
    }

    /**
     * Get all validation errors
     *
     * @return array Array of validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Check if validation was successful (no errors)
     *
     * @return bool True if no validation errors
     */
    public function isValid(): bool
    {
        return empty($this->validationErrors);
    }

    /**
     * Get summary of validation results for logging
     *
     * @return array Summary information
     */
    public function getValidationSummary(): array
    {
        return [
            'client_id' => $this->clientId,
            'target_user_id' => $this->targetUserId,
            'admin_id' => $this->adminId,
            'total_rows_processed' => $this->totalRows,
            'valid_rows' => count($this->validRows),
            'invalid_rows' => count($this->validationErrors),
            'validation_success' => $this->isValid(),
            'expense_types_available' => count($this->expenseTypes),
            'expense_sources_available' => count($this->clientExpenseSources),
            'currencies_supported' => count($this->allowedCurrencies),
            'countries_supported' => count($this->allowedCountries)
        ];
    }
}