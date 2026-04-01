<?php

namespace App\Services;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

/**
 * PocketExpenseCSVValidator Service
 * 
 * Validates CSV files for batch expense upload with all-or-nothing validation approach.
 * Preloads reference data for efficient validation and provides detailed error reporting.
 */
class PocketExpenseCSVValidator
{
    /**
     * Expected CSV column headers in exact order
     *
     * @var array<string>
     */
    private array $expectedHeaders = [
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
     * Preloaded reference data for validation
     *
     * @var array
     */
    private array $referenceData = [
        'expense_types' => [],
        'currencies' => [],
        'countries' => [],
        'sources' => []
    ];

    /**
     * Validation errors collected during processing
     *
     * @var array
     */
    private array $validationErrors = [];

    /**
     * Maximum date age in years as per system constraints
     *
     * @var int
     */
    private int $maxDateAgeYears = 3;

    /**
     * Maximum VAT percentage as per system constraints
     *
     * @var int
     */
    private int $maxVatPercentage = 100;

    /**
     * Maximum merchant name length as per system constraints
     *
     * @var int
     */
    private int $maxMerchantNameLength = 180;

    /**
     * Validate CSV file for batch expense upload
     *
     * @param string $filePath
     * @param int $targetUserId
     * @param int $clientId
     * @param int $adminId
     * @return array
     */
    public function validate(string $filePath, int $targetUserId, int $clientId, int $adminId): array
    {
        $this->validationErrors = [];
        
        try {
            // Check if file exists
            if (!Storage::exists($filePath)) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'File not found',
                            'value' => $filePath
                        ]
                    ],
                    'total_rows' => 0
                ];
            }

            // Read file content
            $fileContent = Storage::get($filePath);
            $lines = str_getcsv($fileContent, "\n");
            
            if (empty($lines)) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'File is empty',
                            'value' => ''
                        ]
                    ],
                    'total_rows' => 0
                ];
            }

            // Check maximum row constraint (200 rows max + header)
            if (count($lines) > 201) {
                return [
                    'valid' => false,
                    'errors' => [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'CSV file exceeds maximum of 200 data rows',
                            'value' => (count($lines) - 1) . ' rows found'
                        ]
                    ],
                    'total_rows' => count($lines) - 1
                ];
            }

            // Parse header row
            $headers = str_getcsv($lines[0]);
            
            // Validate headers
            if (!$this->validateHeaders($headers)) {
                return [
                    'valid' => false,
                    'errors' => $this->validationErrors,
                    'total_rows' => count($lines) - 1
                ];
            }

            // Preload reference data for validation
            $this->preloadReferenceData($clientId);

            // Validate each data row
            for ($i = 1; $i < count($lines); $i++) {
                $row = str_getcsv($lines[$i]);
                
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                $this->validateRow($row, $i + 1);
            }

            return [
                'valid' => empty($this->validationErrors),
                'errors' => $this->validationErrors,
                'total_rows' => count($lines) - 1
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => [
                    [
                        'line_number' => 0,
                        'field' => 'file',
                        'error' => 'File processing error: ' . $e->getMessage(),
                        'value' => ''
                    ]
                ],
                'total_rows' => 0
            ];
        }
    }

    /**
     * Validate CSV headers against expected format
     *
     * @param array $headers
     * @return bool
     */
    public function validateHeaders(array $headers): bool
    {
        // Check if headers match exactly (case-sensitive)
        if (count($headers) !== count($this->expectedHeaders)) {
            $this->validationErrors[] = [
                'line_number' => 1,
                'field' => 'headers',
                'error' => 'Invalid number of columns. Expected ' . count($this->expectedHeaders) . ', got ' . count($headers),
                'value' => implode(', ', $headers)
            ];
            return false;
        }

        for ($i = 0; $i < count($this->expectedHeaders); $i++) {
            if (trim($headers[$i]) !== $this->expectedHeaders[$i]) {
                $this->validationErrors[] = [
                    'line_number' => 1,
                    'field' => 'headers',
                    'error' => 'Invalid header at column ' . ($i + 1) . '. Expected "' . $this->expectedHeaders[$i] . '", got "' . trim($headers[$i]) . '"',
                    'value' => trim($headers[$i])
                ];
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a single CSV row
     *
     * @param array $row
     * @param int $lineNumber
     * @return array
     */
    public function validateRow(array $row, int $lineNumber): array
    {
        $rowErrors = [];

        // Ensure row has correct number of columns
        if (count($row) !== count($this->expectedHeaders)) {
            $rowErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'row',
                'error' => 'Invalid number of columns. Expected ' . count($this->expectedHeaders) . ', got ' . count($row),
                'value' => implode(', ', $row)
            ];
            $this->validationErrors = array_merge($this->validationErrors, $rowErrors);
            return $rowErrors;
        }

        // Map row values to column names
        $data = array_combine($this->expectedHeaders, $row);

        // Validate Date (required)
        $this->validateDate($data['Date'], $lineNumber);

        // Validate Expense Type (required)
        $this->validateExpenseType($data['Expense Type'], $lineNumber);

        // Validate Currency Code (required)
        $this->validateCurrencyCode($data['Currency Code'], $lineNumber);

        // Validate Amount (required)
        $this->validateAmount($data['Amount'], $lineNumber);

        // Validate Currency Equivalent Amount (optional)
        if (!empty(trim($data['Currency Equivalent Amount']))) {
            $this->validateCurrencyEquivalentAmount($data['Currency Equivalent Amount'], $lineNumber);
        }

        // Validate VAT % (optional)
        if (!empty(trim($data['VAT %']))) {
            $this->validateVatPercentage($data['VAT %'], $lineNumber);
        }

        // Validate Merchant Name (required)
        $this->validateMerchantName($data['Merchant Name'], $lineNumber);

        // Validate Description (optional)
        // No specific validation needed for description

        // Validate Merchant Address (optional)
        // No specific validation needed for merchant address

        // Validate Merchant Country (optional)
        if (!empty(trim($data['Merchant Country']))) {
            $this->validateMerchantCountry($data['Merchant Country'], $lineNumber);
        }

        // Validate Source (optional)
        $this->validateSource($data['Source'], $data['Source Note'], $lineNumber);

        // Validate Notes (optional)
        $this->validateNotes($data['Notes'], $lineNumber);

        return $rowErrors;
    }

    /**
     * Preload reference data for efficient validation
     *
     * @param int $clientId
     * @return void
     */
    public function preloadReferenceData(int $clientId): void
    {
        // Load expense types
        $this->referenceData['expense_types'] = OptPocketExpenseType::pluck('option', 'id')->toArray();

        // Load available currencies
        // TODO: Replace with actual currency lookup when platform currency service is confirmed
        $this->referenceData['currencies'] = [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'CNY', 'SEK', 'NOK', 'DKK', 'NZD'
        ];

        // Load available countries
        // TODO: Replace with actual country lookup when platform country service is confirmed
        $this->referenceData['countries'] = [
            'United States', 'United Kingdom', 'Canada', 'Australia', 'Germany', 'France', 
            'Netherlands', 'Sweden', 'Norway', 'Denmark', 'Switzerland', 'Japan'
        ];

        // Load available sources for the client (including global 'Other')
        $this->referenceData['sources'] = PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->pluck('name')
            ->toArray();
    }

    /**
     * Validate date field
     *
     * @param string $date
     * @param int $lineNumber
     * @return void
     */
    private function validateDate(string $date, int $lineNumber): void
    {
        $trimmedDate = trim($date);

        if (empty($trimmedDate)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Date',
                'error' => 'Date is required',
                'value' => $date
            ];
            return;
        }

        // Parse date in DD/MM/YYYY format
        try {
            $parsedDate = Carbon::createFromFormat('d/m/Y', $trimmedDate);
            
            // Check if date is not older than 3 years
            $minDate = Carbon::now()->subYears($this->maxDateAgeYears);
            
            if ($parsedDate->lt($minDate)) {
                $this->validationErrors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'error' => 'Date must not be older than ' . $this->maxDateAgeYears . ' years',
                    'value' => $date
                ];
            }

            // Check if date is not in the future
            if ($parsedDate->gt(Carbon::now())) {
                $this->validationErrors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Date',
                    'error' => 'Date cannot be in the future',
                    'value' => $date
                ];
            }

        } catch (\Exception $e) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Date',
                'error' => 'Invalid date format. Expected DD/MM/YYYY',
                'value' => $date
            ];
        }
    }

    /**
     * Validate expense type field
     *
     * @param string $expenseType
     * @param int $lineNumber
     * @return void
     */
    private function validateExpenseType(string $expenseType, int $lineNumber): void
    {
        $trimmedExpenseType = trim($expenseType);

        if (empty($trimmedExpenseType)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Expense Type',
                'error' => 'Expense Type is required',
                'value' => $expenseType
            ];
            return;
        }

        if (!in_array($trimmedExpenseType, $this->referenceData['expense_types'])) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Expense Type',
                'error' => 'Invalid expense type. Allowed values: ' . implode(', ', $this->referenceData['expense_types']),
                'value' => $expenseType
            ];
        }
    }

    /**
     * Validate currency code field
     *
     * @param string $currencyCode
     * @param int $lineNumber
     * @return void
     */
    private function validateCurrencyCode(string $currencyCode, int $lineNumber): void
    {
        $trimmedCurrencyCode = trim($currencyCode);

        if (empty($trimmedCurrencyCode)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Code',
                'error' => 'Currency Code is required',
                'value' => $currencyCode
            ];
            return;
        }

        if (strlen($trimmedCurrencyCode) !== 3) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Code',
                'error' => 'Currency Code must be exactly 3 characters',
                'value' => $currencyCode
            ];
            return;
        }

        if (!in_array(strtoupper($trimmedCurrencyCode), $this->referenceData['currencies'])) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Code',
                'error' => 'Invalid currency code. Allowed values: ' . implode(', ', $this->referenceData['currencies']),
                'value' => $currencyCode
            ];
        }
    }

    /**
     * Validate amount field
     *
     * @param string $amount
     * @param int $lineNumber
     * @return void
     */
    private function validateAmount(string $amount, int $lineNumber): void
    {
        $trimmedAmount = trim($amount);

        if (empty($trimmedAmount)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount is required',
                'value' => $amount
            ];
            return;
        }

        if (!is_numeric($trimmedAmount)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount must be numeric',
                'value' => $amount
            ];
            return;
        }

        $numericAmount = (float) $trimmedAmount;

        if ($numericAmount == 0) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Amount',
                'error' => 'Amount cannot be zero',
                'value' => $amount
            ];
        }
    }

    /**
     * Validate currency equivalent amount field
     *
     * @param string $currencyEquivalentAmount
     * @param int $lineNumber
     * @return void
     */
    private function validateCurrencyEquivalentAmount(string $currencyEquivalentAmount, int $lineNumber): void
    {
        $trimmedAmount = trim($currencyEquivalentAmount);

        if (!is_numeric($trimmedAmount)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Currency Equivalent Amount',
                'error' => 'Currency Equivalent Amount must be numeric',
                'value' => $currencyEquivalentAmount
            ];
        }
    }

    /**
     * Validate VAT percentage field
     *
     * @param string $vatPercentage
     * @param int $lineNumber
     * @return void
     */
    private function validateVatPercentage(string $vatPercentage, int $lineNumber): void
    {
        $trimmedVat = trim($vatPercentage);

        // Strip % sign if present
        $cleanVat = str_replace('%', '', $trimmedVat);

        if (!is_numeric($cleanVat)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT %',
                'error' => 'VAT % must be numeric',
                'value' => $vatPercentage
            ];
            return;
        }

        $numericVat = (float) $cleanVat;

        if ($numericVat < 0 || $numericVat > $this->maxVatPercentage) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'VAT %',
                'error' => 'VAT % must be between 0 and ' . $this->maxVatPercentage,
                'value' => $vatPercentage
            ];
        }
    }

    /**
     * Validate merchant name field
     *
     * @param string $merchantName
     * @param int $lineNumber
     * @return void
     */
    private function validateMerchantName(string $merchantName, int $lineNumber): void
    {
        $trimmedMerchantName = trim($merchantName);

        if (empty($trimmedMerchantName)) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name is required',
                'value' => $merchantName
            ];
            return;
        }

        if (strlen($trimmedMerchantName) > $this->maxMerchantNameLength) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Name',
                'error' => 'Merchant Name cannot exceed ' . $this->maxMerchantNameLength . ' characters',
                'value' => $merchantName
            ];
        }
    }

    /**
     * Validate merchant country field
     *
     * @param string $merchantCountry
     * @param int $lineNumber
     * @return void
     */
    private function validateMerchantCountry(string $merchantCountry, int $lineNumber): void
    {
        $trimmedCountry = trim($merchantCountry);

        if (!in_array($trimmedCountry, $this->referenceData['countries'])) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Merchant Country',
                'error' => 'Invalid country. Please use a valid country name',
                'value' => $merchantCountry
            ];
        }
    }

    /**
     * Validate source and source note fields
     *
     * @param string $source
     * @param string $sourceNote
     * @param int $lineNumber
     * @return void
     */
    private function validateSource(string $source, string $sourceNote, int $lineNumber): void
    {
        $trimmedSource = trim($source);
        $trimmedSourceNote = trim($sourceNote);

        if (!empty($trimmedSource)) {
            if (!in_array($trimmedSource, $this->referenceData['sources'])) {
                $this->validationErrors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Source',
                    'error' => 'Invalid source. Allowed values: ' . implode(', ', $this->referenceData['sources']),
                    'value' => $source
                ];
            }

            // Check if Source Note is required when Source = Other
            if ($trimmedSource === 'Other' && empty($trimmedSourceNote)) {
                $this->validationErrors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Source Note',
                    'error' => 'Source Note is required when Source is "Other"',
                    'value' => $sourceNote
                ];
            }
        }
    }

    /**
     * Validate notes field
     *
     * @param string $notes
     * @param int $lineNumber
     * @return void
     */
    private function validateNotes(string $notes, int $lineNumber): void
    {
        $trimmedNotes = trim($notes);

        // TODO: Implement actual DB limit checking when notes field constraints are confirmed
        // For now, using a reasonable default limit
        $maxNotesLength = 1000;

        if (strlen($trimmedNotes) > $maxNotesLength) {
            $this->validationErrors[] = [
                'line_number' => $lineNumber,
                'field' => 'Notes',
                'error' => 'Notes cannot exceed ' . $maxNotesLength . ' characters',
                'value' => $notes
            ];
        }

        // TODO: Implement SQL injection prevention when specific requirements are confirmed
        // Basic check for potential SQL injection patterns
        $suspiciousPatterns = [
            'DROP TABLE', 'DELETE FROM', 'INSERT INTO', 'UPDATE ', 'SELECT ', '--', ';--', '/*', '*/'
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (stripos($trimmedNotes, $pattern) !== false) {
                $this->validationErrors[] = [
                    'line_number' => $lineNumber,
                    'field' => 'Notes',
                    'error' => 'Notes contain invalid characters or patterns',
                    'value' => $notes
                ];
                break;
            }
        }
    }
}