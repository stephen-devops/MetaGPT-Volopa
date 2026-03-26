<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use League\Csv\Statement;

/**
 * Pocket Expense CSV Validator Service
 * 
 * Handles CSV file validation for pocket expense batch uploads.
 * Implements all-or-nothing validation with comprehensive error reporting.
 * Preloads reference data for performance optimization.
 */
class PocketExpenseCSVValidator
{
    /**
     * Required CSV column headers in exact order
     *
     * @var array<string>
     */
    private array $requiredHeaders = [
        'Date',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Currency Equivalent Amount',
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
     * Preloaded expense types for validation
     *
     * @var array<string, array>
     */
    private array $expenseTypes = [];

    /**
     * Preloaded currency codes for validation
     *
     * @var array<string>
     */
    private array $allowedCurrencies = [];

    /**
     * Preloaded expense sources for validation
     *
     * @var array<string>
     */
    private array $allowedSources = [];

    /**
     * Preloaded country codes for validation
     *
     * @var array<string>
     */
    private array $allowedCountries = [];

    /**
     * Current client ID for scoped validation
     *
     * @var int
     */
    private int $clientId;

    /**
     * Target user ID for expense creation
     *
     * @var int
     */
    private int $targetUserId;

    /**
     * Admin user ID performing the upload
     *
     * @var int
     */
    private int $adminId;

    /**
     * Validate CSV file and return validation results.
     *
     * @param string $filePath
     * @param int $targetUserId
     * @param int $clientId
     * @param int $adminId
     * @return array
     */
    public function validate(string $filePath, int $targetUserId, int $clientId, int $adminId): array
    {
        $this->targetUserId = $targetUserId;
        $this->clientId = $clientId;
        $this->adminId = $adminId;

        try {
            // Preload reference data for performance
            $this->preloadReferenceData($clientId);

            // Read CSV file
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);

            // Get headers and validate
            $headers = $csv->getHeader();
            if (!$this->validateHeaders($headers)) {
                return [
                    'valid' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'errors' => [
                        [
                            'line_number' => 1,
                            'field' => 'Headers',
                            'error' => 'CSV headers do not match required format',
                            'value' => implode(', ', $headers)
                        ]
                    ]
                ];
            }

            // Process rows
            $statement = Statement::create();
            $records = $statement->process($csv);
            
            $totalRows = 0;
            $errors = [];
            $validRows = [];

            foreach ($records as $offset => $record) {
                $lineNumber = $offset + 2; // +2 because offset starts at 0 and we have header row
                $totalRows++;

                // Check maximum rows constraint (200 max)
                if ($totalRows > 200) {
                    $errors[] = [
                        'line_number' => $lineNumber,
                        'field' => 'File',
                        'error' => 'Maximum 200 rows allowed per CSV file',
                        'value' => (string) $totalRows
                    ];
                    break;
                }

                $rowErrors = $this->validateRow($record, $lineNumber);
                
                if (empty($rowErrors)) {
                    $validRows[] = $record;
                } else {
                    $errors = array_merge($errors, $rowErrors);
                }
            }

            $isValid = empty($errors);
            $validRowCount = count($validRows);

            Log::info('CSV validation completed', [
                'file_path' => $filePath,
                'total_rows' => $totalRows,
                'valid_rows' => $validRowCount,
                'error_count' => count($errors),
                'client_id' => $clientId,
                'user_id' => $targetUserId
            ]);

            return [
                'valid' => $isValid,
                'total_rows' => $totalRows,
                'valid_rows' => $validRowCount,
                'errors' => $errors,
                'valid_data' => $isValid ? $validRows : []
            ];

        } catch (\Exception $e) {
            Log::error('CSV validation failed with exception', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'client_id' => $clientId,
                'user_id' => $targetUserId
            ]);

            return [
                'valid' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'errors' => [
                    [
                        'line_number' => 0,
                        'field' => 'File',
                        'error' => 'Failed to process CSV file: ' . $e->getMessage(),
                        'value' => basename($filePath)
                    ]
                ]
            ];
        }
    }

    /**
     * Validate a single CSV row.
     *
     * @param array $row
     * @param int $lineNumber
     * @return array
     */
    public function validateRow(array $row, int $lineNumber): array
    {
        $errors = [];

        // Map row data to expected fields
        $data = array_combine($this->requiredHeaders, array_values($row));
        
        // Validate Date
        if (empty($data['Date'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Date',
                'error' => 'Date is required',
                'value' => $data['Date'] ?? ''
            ];
        } elseif (!$this->validateDate($data['Date'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Date',
                'error' => 'Invalid date format (expected DD/MM/YYYY) or date is older than 3 years',
                'value' => $data['Date']
            ];
        }

        // Validate Expense Type
        if (empty($data['Expense Type'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Expense Type',
                'error' => 'Expense Type is required',
                'value' => $data['Expense Type'] ?? ''
            ];
        } elseif (!$this->validateExpenseType($data['Expense Type'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Expense Type',
                'error' => 'Invalid expense type',
                'value' => $data['Expense Type']
            ];
        }

        // Validate Currency Code
        if (empty($data['Currency Code'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Code',
                'error' => 'Currency Code is required',
                'value' => $data['Currency Code'] ?? ''
            ];
        } elseif (!$this->validateCurrency($data['Currency Code'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Code',
                'error' => 'Invalid currency code (must be 3-letter ISO code)',
                'value' => $data['Currency Code']
            ];
        }

        // Validate Amount
        if (empty($data['Amount'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount is required',
                'value' => $data['Amount'] ?? ''
            ];
        } elseif (!$this->validateAmount($data['Amount'], $data['Expense Type'] ?? '')) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Invalid amount or incorrect sign based on expense type',
                'value' => $data['Amount']
            ];
        }

        // Validate VAT % (optional)
        if (!empty($data['VAT %']) && !$this->validateVATPercent($data['VAT %'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT %',
                'error' => 'VAT % must be numeric between 0-100',
                'value' => $data['VAT %']
            ];
        }

        // Validate Merchant Name
        if (empty($data['Merchant Name'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name is required',
                'value' => $data['Merchant Name'] ?? ''
            ];
        } elseif (strlen($data['Merchant Name']) > 180) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name cannot exceed 180 characters',
                'value' => substr($data['Merchant Name'], 0, 50) . '...'
            ];
        }

        // Validate Merchant Country (optional)
        if (!empty($data['Merchant Country']) && !$this->validateCountry($data['Merchant Country'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Country',
                'error' => 'Invalid country code',
                'value' => $data['Merchant Country']
            ];
        }

        // Validate Source (optional but must be valid if provided)
        if (!empty($data['Source']) && !$this->validateSource($data['Source'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Source',
                'error' => 'Invalid expense source for client',
                'value' => $data['Source']
            ];
        }

        // Validate Source Note (required if Source = Other)
        if (!empty($data['Source']) && $data['Source'] === 'Other' && empty($data['Source Note'])) {
            $errors[] = [
                'line_number' => $lineNumber,
                'field' => 'Source Note',
                'error' => 'Source Note is required when Source is Other',
                'value' => $data['Source Note'] ?? ''
            ];
        }

        return $errors;
    }

    /**
     * Preload reference data for validation performance.
     *
     * @param int $clientId
     * @return void
     */
    public function preloadReferenceData(int $clientId): void
    {
        // Load expense types
        $this->expenseTypes = OptPocketExpenseType::all()
            ->keyBy('option')
            ->map(function ($type) {
                return [
                    'id' => $type->id,
                    'option' => $type->option,
                    'amount_sign' => $type->amount_sign
                ];
            })
            ->toArray();

        // Load allowed currencies
        // TODO: Load from platform currency master data
        $this->allowedCurrencies = [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK',
            'CNY', 'INR', 'SGD', 'HKD', 'NZD', 'ZAR', 'MXN', 'BRL', 'RUB', 'KRW'
        ];

        // Load expense sources for client
        $this->allowedSources = PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->active()
            ->pluck('name')
            ->toArray();

        // Load allowed countries
        // TODO: Load from platform country master data
        $this->allowedCountries = [
            'US', 'CA', 'GB', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'CH',
            'AU', 'NZ', 'JP', 'SG', 'HK', 'CN', 'IN', 'ZA', 'MX', 'BR'
        ];

        Log::debug('Reference data preloaded for validation', [
            'client_id' => $clientId,
            'expense_types_count' => count($this->expenseTypes),
            'currencies_count' => count($this->allowedCurrencies),
            'sources_count' => count($this->allowedSources),
            'countries_count' => count($this->allowedCountries)
        ]);
    }

    /**
     * Validate CSV headers match required format.
     *
     * @param array $headers
     * @return bool
     */
    private function validateHeaders(array $headers): bool
    {
        // Trim headers for comparison
        $headers = array_map('trim', $headers);
        
        // Check if headers match exactly
        return $headers === $this->requiredHeaders;
    }

    /**
     * Validate expense type exists and is valid.
     *
     * @param string $type
     * @return bool
     */
    private function validateExpenseType(string $type): bool
    {
        return isset($this->expenseTypes[$type]);
    }

    /**
     * Validate currency code is in allowed list.
     *
     * @param string $currency
     * @return bool
     */
    private function validateCurrency(string $currency): bool
    {
        return strlen($currency) === 3 && in_array(strtoupper($currency), $this->allowedCurrencies, true);
    }

    /**
     * Validate amount is numeric and has correct sign based on expense type.
     *
     * @param string $amount
     * @param string $type
     * @return bool
     */
    private function validateAmount(string $amount, string $type): bool
    {
        // Check if amount is numeric
        if (!is_numeric($amount)) {
            return false;
        }

        $numericAmount = (float) $amount;
        
        // Check if expense type exists
        if (!isset($this->expenseTypes[$type])) {
            return false;
        }

        $expectedSign = $this->expenseTypes[$type]['amount_sign'];
        
        // Validate amount sign based on expense type
        if ($expectedSign === 'positive' && $numericAmount <= 0) {
            return false;
        }
        
        if ($expectedSign === 'negative' && $numericAmount >= 0) {
            return false;
        }

        return true;
    }

    /**
     * Validate date format (DD/MM/YYYY) and ensure not older than 3 years.
     *
     * @param string $date
     * @return bool
     */
    private function validateDate(string $date): bool
    {
        // Validate format DD/MM/YYYY
        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return false;
        }

        // Parse date
        $dateObj = \DateTime::createFromFormat('d/m/Y', $date);
        
        // Check if date is valid
        if (!$dateObj || $dateObj->format('d/m/Y') !== $date) {
            return false;
        }

        // Check if date is not older than 3 years
        $threeYearsAgo = new \DateTime('-3 years');
        
        return $dateObj >= $threeYearsAgo;
    }

    /**
     * Validate VAT percentage (0-100, strip % sign).
     *
     * @param string $vatPercent
     * @return bool
     */
    private function validateVATPercent(string $vatPercent): bool
    {
        // Strip % sign if present
        $cleanVat = str_replace('%', '', trim($vatPercent));
        
        // Check if numeric
        if (!is_numeric($cleanVat)) {
            return false;
        }
        
        $numericVat = (float) $cleanVat;
        
        // Check range 0-100
        return $numericVat >= 0 && $numericVat <= 100;
    }

    /**
     * Validate expense source exists for client.
     *
     * @param string $source
     * @return bool
     */
    private function validateSource(string $source): bool
    {
        return in_array($source, $this->allowedSources, true);
    }

    /**
     * Validate country code exists in allowed list.
     *
     * @param string $country
     * @return bool
     */
    private function validateCountry(string $country): bool
    {
        return in_array(strtoupper($country), $this->allowedCountries, true);
    }
}