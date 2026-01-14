## Code: app/Services/CsvValidationService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\PaymentInstruction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use League\Csv\Reader;
use League\Csv\Statement;
use League\Csv\Exception as CsvException;
use Carbon\Carbon;
use Exception;

class CsvValidationService
{
    /**
     * Maximum rows to process in a single validation.
     */
    private const MAX_ROWS = 10000;

    /**
     * Minimum required amount for payment instructions.
     */
    private const MIN_AMOUNT = 0.01;

    /**
     * Maximum amount for a single payment instruction.
     */
    private const MAX_AMOUNT = 1000000.00;

    /**
     * Cache TTL for validation rules in minutes.
     */
    private const CACHE_TTL = 60;

    /**
     * Supported currencies for validation.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
        'SEK', 'NZD', 'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'ZAR',
        'INR', 'BRL', 'PLN', 'CZK', 'HUF', 'ILS', 'KRW', 'TWD',
        'THB', 'MYR', 'PHP', 'IDR', 'VND', 'AED', 'SAR', 'EGP',
    ];

    /**
     * Currencies that require invoice details.
     */
    private const INVOICE_REQUIRED_CURRENCIES = ['INR'];

    /**
     * Currencies that require incorporation number for business recipients.
     */
    private const INCORPORATION_REQUIRED_CURRENCIES = ['TRY'];

    /**
     * Valid beneficiary types.
     */
    private const VALID_BENEFICIARY_TYPES = ['individual', 'business'];

    /**
     * Valid purpose codes (simplified list).
     */
    private const VALID_PURPOSE_CODES = [
        'P0101', 'P0102', 'P0103', 'P0104', 'P0105', 'P0106', 'P0107', 'P0108', 'P0109',
        'P0201', 'P0202', 'P0203', 'P0204', 'P0205', 'P0206', 'P0207', 'P0208', 'P0209',
        'P0301', 'P0302', 'P0303', 'P0304', 'P0305', 'P0306', 'P0307', 'P0308', 'P0309',
        'P0401', 'P0402', 'P0403', 'P0404', 'P0405', 'P0406', 'P0407', 'P0408', 'P0409',
        'P0501', 'P0502', 'P0503', 'P0504', 'P0505', 'P0506', 'P0507', 'P0508', 'P0509',
    ];

    /**
     * ISO 3166-1 alpha-2 country codes.
     */
    private const VALID_COUNTRY_CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
        'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
        'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC',
        'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
        'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG',
        'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
        'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
        'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW'
    ];

    /**
     * Required CSV headers for basic validation.
     */
    private const REQUIRED_HEADERS = [
        'beneficiary_name',
        'amount',
        'reference',
        'purpose_code',
        'beneficiary_email',
        'beneficiary_country',
        'beneficiary_type',
    ];

    /**
     * Optional headers that can be included.
     */
    private const OPTIONAL_HEADERS = [
        'beneficiary_account_number',
        'beneficiary_sort_code',
        'beneficiary_iban',
        'beneficiary_swift_code',
        'beneficiary_bank_name',
        'beneficiary_bank_address',
        'beneficiary_address_line1',
        'beneficiary_address_line2',
        'beneficiary_city',
        'beneficiary_state',
        'beneficiary_postal_code',
        'beneficiary_phone',
        'invoice_number',
        'invoice_date',
        'incorporation_number',
    ];

    /**
     * Validate CSV rows and return validation results.
     */
    public function validateCsvRows(string $filePath, string $currency): array
    {
        try {
            $startTime = microtime(true);
            
            Log::info('Starting CSV validation', [
                'file_path' => $filePath,
                'currency' => $currency,
            ]);

            // Validate currency
            if (!in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES)) {
                throw new Exception("Unsupported currency: {$currency}");
            }

            // Read and validate CSV structure
            $reader = $this->createCsvReader($filePath);
            $header = $reader->getHeader();
            
            $this->validateCsvHeader($header, $currency);

            // Process CSV rows
            $validationResults = $this->processCsvRows($reader, $currency);

            $processingTime = round(microtime(true) - $startTime, 2);
            
            Log::info('CSV validation completed', [
                'file_path' => $filePath,
                'currency' => $currency,
                'total_rows' => $validationResults['total_rows'],
                'valid_rows' => $validationResults['valid_rows'],
                'invalid_rows' => $validationResults['invalid_rows'],
                'processing_time' => $processingTime,
            ]);

            return $validationResults;

        } catch (Exception $e) {
            Log::error('CSV validation failed', [
                'file_path' => $filePath,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate a single payment instruction row.
     */
    public function validatePaymentInstruction(array $row, string $currency): array
    {
        $errors = [];
        $normalizedRow = $this->normalizeRowData($row);

        try {
            // Basic field validation
            $errors = array_merge($errors, $this->validateRequiredFields($normalizedRow));
            $errors = array_merge($errors, $this->validateBeneficiaryData($normalizedRow));
            $errors = array_merge($errors, $this->validateAmountData($normalizedRow, $currency));
            $errors = array_merge($errors, $this->validateCurrencySpecificRules($normalizedRow, $currency));

            // If no errors so far, perform advanced validation
            if (empty($errors)) {
                $errors = array_merge($errors, $this->validateBusinessRules($normalizedRow, $currency));
                $errors = array_merge($errors, $this->validateBankingDetails($normalizedRow));
            }

        } catch (Exception $e) {
            $errors[] = "Validation error: " . $e->getMessage();
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'normalized_data' => $normalizedRow,
        ];
    }

    /**
     * Validate currency-specific rules for payment instruction.
     */
    private function validateCurrencySpecificRules(array $row, string $currency): array
    {
        $errors = [];
        $currency = strtoupper($currency);

        // INR specific validations
        if (in_array($currency, self::INVOICE_REQUIRED_CURRENCIES)) {
            if (empty(trim($row['invoice_number'] ?? ''))) {
                $errors[] = "Invoice number is required for {$currency} payments";
            } elseif (strlen(trim($row['invoice_number'])) > 50) {
                $errors[] = "Invoice number cannot exceed 50 characters";
            }

            if (empty(trim($row['invoice_date'] ?? ''))) {
                $errors[] = "Invoice date is required for {$currency} payments";
            } else {
                $invoiceDate = $this->parseDate($row['invoice_date']);
                if (!$invoiceDate) {
                    $errors[] = "Invalid invoice date format. Use YYYY-MM-DD";
                } elseif ($invoiceDate->isFuture()) {
                    $errors[] = "Invoice date cannot be in the future";
                } elseif ($invoiceDate->lt(Carbon::now()->subYears(2))) {
                    $errors[] = "Invoice date cannot be more than 2 years old";
                }
            }
        }

        // TRY specific validations for business recipients
        if (in_array($currency, self::INCORPORATION_REQUIRED_CURRENCIES)) {
            $beneficiaryType = strtolower(trim($row['beneficiary_type'] ?? ''));
            
            if ($beneficiaryType === 'business') {
                if (empty(trim($row['incorporation_number'] ?? ''))) {
                    $errors[] = "Incorporation number is required for business recipients in {$currency}";
                } elseif (strlen(trim($row['incorporation_number'])) > 20) {
                    $errors[] = "Incorporation number cannot exceed 20 characters";
                }
            }
        }

        // High-value transaction validations
        $amount = $this->parseAmount($row['amount'] ?? '');
        if ($amount !== null) {
            $highValueThreshold = $this->getHighValueThreshold($currency);
            
            if ($amount > $highValueThreshold) {
                // Additional documentation requirements for high-value transactions
                if (empty(trim($row['beneficiary_address_line1'] ?? ''))) {
                    $errors[] = "Beneficiary address is required for high-value {$currency} payments";
                }
                
                if (empty(trim($row['beneficiary_swift_code'] ?? '')) && empty(trim($row['beneficiary_iban'] ?? ''))) {
                    $errors[] = "SWIFT code or IBAN is required for high-value {$currency} payments";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate beneficiary data fields.
     */
    private function validateBeneficiaryData(array $row): array
    {
        $errors = [];

        // Beneficiary name validation
        if (empty(trim($row['beneficiary_name'] ?? ''))) {
            $errors[] = "Beneficiary name is required";
        } elseif (strlen(trim($row['beneficiary_name'])) < 2) {
            $errors[] = "Beneficiary name must be at least 2 characters";
        } elseif (strlen(trim($row['beneficiary_name'])) > 100) {
            $errors[] = "Beneficiary name cannot exceed 100 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9\s\.\-\'&,()]+$/u', trim($row['beneficiary_name']))) {
            $errors[] = "Beneficiary name contains invalid characters";
        }

        // Email validation
        if (!empty($row['beneficiary_email'])) {
            if (!filter_var(trim($row['beneficiary_email']), FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Invalid beneficiary email format";
            } elseif (strlen(trim($row['beneficiary_email'])) >