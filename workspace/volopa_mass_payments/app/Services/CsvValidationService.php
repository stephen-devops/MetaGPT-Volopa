## Code: app/Services/CsvValidationService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\PaymentInstruction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

class CsvValidationService
{
    /**
     * Maximum allowed rows per CSV file
     */
    protected int $maxRows;

    /**
     * Minimum allowed rows per CSV file
     */
    protected int $minRows;

    /**
     * Maximum amount per payment instruction
     */
    protected float $maxAmountPerInstruction;

    /**
     * Minimum amount per payment instruction
     */
    protected float $minAmountPerInstruction;

    /**
     * Maximum total file amount
     */
    protected float $maxTotalFileAmount;

    /**
     * Required CSV headers
     */
    protected array $requiredHeaders;

    /**
     * Optional CSV headers
     */
    protected array $optionalHeaders;

    /**
     * Supported currencies
     */
    protected array $supportedCurrencies;

    /**
     * Purpose codes configuration
     */
    protected array $purposeCodes;

    /**
     * Country-specific purpose codes
     */
    protected array $countryPurposeCodes;

    /**
     * Currency-specific settings
     */
    protected array $currencySettings;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->maxRows = config('mass-payments.max_rows_per_file', 10000);
        $this->minRows = config('mass-payments.min_rows_per_file', 1);
        $this->maxAmountPerInstruction = config('mass-payments.validation.max_amount_per_instruction', 999999.99);
        $this->minAmountPerInstruction = config('mass-payments.validation.min_amount_per_instruction', 0.01);
        $this->maxTotalFileAmount = config('mass-payments.validation.max_total_file_amount', 10000000.00);
        $this->requiredHeaders = config('mass-payments.validation.required_csv_headers', [
            'amount', 'currency', 'beneficiary_name', 'beneficiary_account', 'bank_code'
        ]);
        $this->optionalHeaders = config('mass-payments.validation.optional_csv_headers', [
            'reference', 'purpose_code', 'beneficiary_address', 'beneficiary_country', 'beneficiary_city', 'intermediary_bank', 'special_instructions'
        ]);
        $this->supportedCurrencies = array_keys(config('mass-payments.supported_currencies', ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY']));
        $this->purposeCodes = array_keys(config('mass-payments.purpose_codes', []));
        $this->countryPurposeCodes = config('mass-payments.country_purpose_codes', []);
        $this->currencySettings = config('mass-payments.currency_settings', []);
    }

    /**
     * Validate CSV file structure and headers
     *
     * @param string $filePath
     * @return array
     * @throws Exception
     */
    public function validateCsvStructure(string $filePath): array
    {
        if (empty($filePath) || !file_exists($filePath)) {
            throw new InvalidArgumentException('Invalid file path provided');
        }

        $errors = [];
        $warnings = [];

        try {
            // Create CSV reader instance
            $csv = Reader::createFromPath($filePath, 'r');
            
            // Handle BOM if present
            $csv->setHeaderOffset(0);

            // Get headers
            $headers = $csv->getHeader();
            
            if (empty($headers)) {
                $errors[] = 'CSV file contains no headers';
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'headers' => [],
                    'row_count' => 0,
                    'estimated_total_amount' => 0.0,
                    'detected_currency' => null,
                ];
            }

            // Normalize headers (trim and lowercase for comparison)
            $normalizedHeaders = array_map(function($header) {
                return strtolower(trim($header));
            }, $headers);

            // Validate required headers are present
            $missingHeaders = [];
            foreach ($this->requiredHeaders as $requiredHeader) {
                if (!in_array(strtolower($requiredHeader), $normalizedHeaders)) {
                    $missingHeaders[] = $requiredHeader;
                }
            }

            if (!empty($missingHeaders)) {
                $errors[] = 'Missing required headers: ' . implode(', ', $missingHeaders);
            }

            // Check for duplicate headers
            $duplicateHeaders = array_diff_assoc($normalizedHeaders, array_unique($normalizedHeaders));
            if (!empty($duplicateHeaders)) {
                $warnings[] = 'Duplicate headers found: ' . implode(', ', $duplicateHeaders);
            }

            // Validate header format
            foreach ($headers as $index => $header) {
                $trimmedHeader = trim($header);
                if (empty($trimmedHeader)) {
                    $errors[] = "Empty header found at column " . ($index + 1);
                }
                if (strlen($trimmedHeader) > 50) {
                    $warnings[] = "Header too long at column " . ($index + 1) . ": " . substr($trimmedHeader, 0, 20) . "...";
                }
            }

            // Count data rows and validate file size
            $records = $csv->getRecords();
            $rowCount = 0;
            $estimatedTotalAmount = 0.0;
            $detectedCurrency = null;
            $amountColumnIndex = $this->findColumnIndex($headers, 'amount');
            $currencyColumnIndex = $this->findColumnIndex($headers, 'currency');

            foreach ($records as $rowIndex => $record) {
                $rowCount++;
                
                // Check row count limits
                if ($rowCount > $this->maxRows) {
                    $errors[] = "Too many rows in file. Maximum allowed: {$this->maxRows}, found: {$rowCount}";
                    break;
                }

                // Estimate total amount and detect currency from first few rows
                if ($rowCount <= 10 && $amountColumnIndex !== null && isset($record[$amountColumnIndex])) {
                    $amount = $this->parseAmount($record[$amountColumnIndex]);
                    if ($amount !== null) {
                        $estimatedTotalAmount += $amount;
                    }
                }

                // Detect currency from first valid row
                if ($detectedCurrency === null && $currencyColumnIndex !== null && isset($record[$currencyColumnIndex])) {
                    $currency = strtoupper(trim($record[$currencyColumnIndex]));
                    if (in_array($currency, $this->supportedCurrencies)) {
                        $detectedCurrency = $currency;
                    }
                }

                // Validate row has correct number of columns
                if (count($record) !== count($headers)) {
                    $warnings[] = "Row " . ($rowIndex + 2) . " has incorrect number of columns. Expected: " . count($headers) . ", found: " . count($record);
                }
            }

            // Check minimum row count
            if ($rowCount < $this->minRows) {
                $errors[] = "Too few data rows in file. Minimum required: {$this->minRows}, found: {$rowCount}";
            }

            // Validate estimated total amount
            if ($estimatedTotalAmount > $this->maxTotalFileAmount) {
                $warnings[] = "Estimated total file amount ({$estimatedTotalAmount}) exceeds maximum allowed ({$this->maxTotalFileAmount})";
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'headers' => $headers,
                'row_count' => $rowCount,
                'estimated_total_amount' => $estimatedTotalAmount,
                'detected_currency' => $detectedCurrency,
                'header_mapping' => $this->createHeaderMapping($headers),
            ];

        } catch (CsvException $e) {
            Log::error('CSV parsing error during structure validation', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            $errors[] = 'Invalid CSV format: ' . $e->getMessage();
            
            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'headers' => [],
                'row_count' => 0,
                'estimated_total_amount' => 0.0,
                'detected_currency' => null,
            ];
        } catch (Exception $e) {
            Log::error('Unexpected error during CSV structure validation', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to validate CSV structure: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate payment instructions data
     *
     * @param array $instructions
     * @return array
     */
    public function validatePaymentInstructions(array $instructions): array
    {
        if (empty($instructions)) {
            return [
                'valid' => false,
                'errors' => ['No payment instructions provided'],
                'instruction_errors' => [],
                'statistics' => $this->getEmptyStatistics(),
            ];
        }

        $globalErrors = [];
        $instructionErrors = [];
        $statistics = [
            'total_instructions' => count($instructions),
            'valid_instructions' => 0,
            'invalid_instructions' => 0,
            'total_amount' => 0.0,
            'currency_breakdown' => [],
            'error_summary' => [],
        ];

        // Track currencies and amounts for cross-validation
        $currencies = [];
        $totalAmount = 0.0;
        $references = [];
        $beneficiaryAccounts = [];

        foreach ($instructions as $index => $instruction) {
            $rowNumber = $index + 1;
            $instructionErrors[$rowNumber] = [];

            // Validate individual instruction
            $this->validateSingleInstruction($instruction, $rowNumber, $instructionErrors[$rowNumber]);

            // Collect data for cross-validation
            if (isset($instruction['currency']) && !empty($instruction['currency'])) {
                $currency = strtoupper(trim($instruction['currency']));
                $currencies[$currency] = ($currencies[$currency] ?? 0) + 1;
            }

            if (isset($instruction['amount']) && is_numeric($instruction['amount'])) {
                $amount = (float) $instruction['amount'];
                $totalAmount += $amount;
            }

            if (isset($instruction['reference']) && !empty($instruction['reference'])) {
                $reference = trim($instruction['reference']);
                if (isset($references[$reference])) {
                    $references[$reference][] = $rowNumber;
                } else {
                    $references[$reference] = [$rowNumber];
                }
            }

            if (isset($instruction['beneficiary_account']) && !empty($instruction['beneficiary_account'])) {
                $account = trim($instruction['beneficiary_account']);
                if (isset($beneficiaryAccounts[$account])) {
                    $beneficiaryAccounts[$account][] = $rowNumber;
                } else {
                    $beneficiaryAccounts[$account] = [$rowNumber];
                }
            }

            // Update statistics
            if (empty($instructionErrors[$rowNumber])) {
                $statistics['valid_instructions']++;
            } else {
                $statistics['invalid_instructions']++;
                
                // Collect error types for summary
                foreach ($instructionErrors[$rowNumber] as $error) {
                    $errorType = $this->categorizeError($error);
                    $statistics['error_summary'][$errorType] = ($statistics['error_summary'][$errorType] ?? 0) + 1;
                }
            }
        }

        // Cross-validation checks
        $this->performCrossValidation($globalErrors, $currencies, $totalAmount, $references, $beneficiaryAccounts);

        // Update statistics
        $statistics['total_amount'] = $totalAmount;
        $statistics['currency_breakdown'] = $currencies;

        // Remove empty instruction errors
        $instructionErrors = array_filter($instructionErrors, function($errors) {
            return !empty($errors);
        });

        return [
            'valid' => empty($globalErrors) && empty($instructionErrors),
            'errors' => $globalErrors,
            'instruction_errors' => $instructionErrors,
            'statistics' => $statistics,
        ];
    }

    /**
     * Validate currency-specific fields for a payment instruction
     *
     * @param array $instruction
     * @param string $currency
     * @return array
     */
    public function validateCurrencySpecificFields(array $instruction, string $currency): array
    {
        $errors = [];
        
        if (empty($currency) || !in_array($currency, $this->supportedCurrencies)) {
            $errors[] = 'Invalid or unsupported currency';
            return $errors;
        }

        $currencyConfig = $this->currencySettings[$currency] ?? [];
        
        if (empty($currencyConfig)) {
            // If no specific config, use defaults
            return $errors;
        }

        // Validate amount precision based on currency
        if (isset($instruction['amount']) && is_numeric($instruction['amount'])) {
            $amount = (float) $instruction['amount'];
            $decimalPlaces = $currencyConfig['decimal_places'] ?? 2;
            
            // Check decimal places
            $amountStr = (string) $amount;
            if (strpos($amountStr, '.') !== false) {
                $actualDecimalPlaces = strlen(substr(strrchr($amountStr, '.'), 1));
                if ($actualDecimalPlaces > $decimalPlaces) {
                    $errors[] = "Amount has too many decimal places for {$currency}. Maximum allowed: {$decimalPlaces}";
                }
            }

            // Check currency-specific amount limits
            $maxAmount = $currencyConfig['max_amount'] ?? $this->maxAmountPerInstruction;
            $minAmount = $currencyConfig['min_amount'] ?? $this->minAmountPerInstruction;
            
            if ($amount > $maxAmount) {
                $errors[] = "Amount exceeds maximum allowed for {$currency}: {$maxAmount}";
            }
            
            if ($amount < $minAmount) {
                $errors[] = "Amount below minimum allowed for {$currency}: {$minAmount}";
            }
        }

        // Validate currency-specific required fields
        if ($currencyConfig['requires_purpose_code'] ?? false) {
            if (empty($instruction['purpose_code'])) {
                $errors[] = "Purpose code is required for {$currency} payments";
            }
        }

        if ($currencyConfig['requires_swift_code'] ?? false) {
            if (empty($instruction['swift_code']) && empty($instruction['bank_code'])) {
                $errors[] = "SWIFT code is required for {$currency} payments";
            }
        }

        if ($currencyConfig['requires_iban'] ?? false) {
            if (empty($instruction['iban']) && empty($instruction['beneficiary_account'])) {
                $errors[] = "IBAN is required for {$currency} payments";
            } elseif (!empty($instruction['iban'])) {
                $this->validateIban($instruction['iban'], $errors);
            }
        }

        if ($currencyConfig['requires_sort_code'] ?? false) {
            if (empty($instruction['sort_code']) && empty($instruction['bank_code'])) {
                $errors[] = "Sort code is required for {$currency} payments";
            