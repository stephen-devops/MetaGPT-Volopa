## Code: app/Services/CsvValidationService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\TccAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;

class CsvValidationService
{
    /**
     * Maximum number of rows allowed in a CSV file.
     */
    private const MAX_ROWS = 10000;

    /**
     * Maximum file size allowed in bytes (50MB).
     */
    private const MAX_FILE_SIZE = 52428800;

    /**
     * Required CSV columns.
     */
    private const REQUIRED_COLUMNS = [
        'beneficiary_name',
        'account_number',
        'bank_code',
        'amount',
        'currency',
        'purpose_code'
    ];

    /**
     * Optional CSV columns.
     */
    private const OPTIONAL_COLUMNS = [
        'beneficiary_address',
        'beneficiary_country',
        'bank_name',
        'bank_address',
        'swift_code',
        'iban',
        'routing_number',
        'remittance_information',
        'payment_reference',
        'beneficiary_email',
        'beneficiary_phone',
        'intermediate_bank_code',
        'intermediate_bank_name',
        'intermediate_swift_code'
    ];

    /**
     * Supported currencies.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'CAD', 'JPY', 
        'CNY', 'THB', 'MYR', 'IDR', 'PHP', 'VND'
    ];

    /**
     * Valid purpose codes.
     */
    private const PURPOSE_CODES = [
        'SAL' => 'Salary',
        'DIV' => 'Dividend',
        'INT' => 'Interest',
        'FEE' => 'Fee',
        'RFD' => 'Refund',
        'TRD' => 'Trade',
        'SVC' => 'Service',
        'SUP' => 'Supplier',
        'INV' => 'Investment',
        'OTH' => 'Other'
    ];

    /**
     * Currency-specific validation rules.
     */
    private const CURRENCY_RULES = [
        'USD' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'EUR' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => true,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'GBP' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'SGD' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'HKD' => [
            'min_amount' => 0.01,
            'max_amount' => 9999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'AUD' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'CAD' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'JPY' => [
            'min_amount' => 1.00,
            'max_amount' => 99999999.00,
            'decimal_places' => 0,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => true,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'CNY' => [
            'min_amount' => 0.01,
            'max_amount' => 9999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => true,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['TRD', 'SVC', 'SUP']
        ],
        'THB' => [
            'min_amount' => 0.01,
            'max_amount' => 9999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'MYR' => [
            'min_amount' => 0.01,
            'max_amount' => 999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => false,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'IDR' => [
            'min_amount' => 1.00,
            'max_amount' => 999999999.00,
            'decimal_places' => 0,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'PHP' => [
            'min_amount' => 0.01,
            'max_amount' => 9999999.99,
            'decimal_places' => 2,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ],
        'VND' => [
            'min_amount' => 1.00,
            'max_amount' => 999999999.00,
            'decimal_places' => 0,
            'requires_purpose_code' => true,
            'requires_beneficiary_address' => true,
            'requires_swift_code' => false,
            'max_remittance_length' => 140,
            'allowed_purpose_codes' => ['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH']
        ]
    ];

    /**
     * Validate uploaded CSV file structure and content.
     *
     * @param UploadedFile $file
     * @return array
     */
    public function validateFile(UploadedFile $file): array
    {
        Log::info('Starting CSV file validation', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType()
        ]);

        $validationResult = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'summary' => [
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'total_amount' => 0.00,
                'currencies' => [],
                'processing_time' => 0
            ],
            'row_errors' => [],
            'header_validation' => null,
            'file_validation' => null
        ];

        $startTime = microtime(true);

        try {
            // Basic file validation
            $fileValidation = $this->validateFileBasics($file);
            $validationResult['file_validation'] = $fileValidation;

            if (!$fileValidation['valid']) {
                $validationResult['valid'] = false;
                $validationResult['errors'] = array_merge($validationResult['errors'], $fileValidation['errors']);
                return $validationResult;
            }

            // Read and validate CSV content
            $reader = Reader::createFromPath($file->getRealPath(), 'r');
            $reader->setHeaderOffset(0);

            // Validate headers
            $headerValidation = $this->validateHeaders($reader->getHeader());
            $validationResult['header_validation'] = $headerValidation;

            if (!$headerValidation['valid']) {
                $validationResult['valid'] = false;
                $validationResult['errors'] = array_merge($validationResult['errors'], $headerValidation['errors']);
                return $validationResult;
            }

            // Process data rows
            $records = $reader->getRecords();
            $rowNumber = 2; // Start from row 2 (after header)
            $totalAmount = 0.00;
            $currencies = [];
            $validRows = 0;
            $invalidRows = 0;

            foreach ($records as $record) {
                if ($rowNumber > self::MAX_ROWS + 1) { // +1 for header
                    $validationResult['errors'][] = "File exceeds maximum allowed rows ({$rowNumber})";
                    $validationResult['valid'] = false;
                    break;
                }

                $rowValidation = $this->validateRow($record, $rowNumber);
                
                if ($rowValidation['valid']) {
                    $validRows++;
                    
                    // Aggregate amounts and currencies
                    $amount = (float) $record['amount'];
                    $currency = strtoupper(trim($record['currency']));
                    
                    $totalAmount += $amount;
                    if (!isset($currencies[$currency])) {
                        $currencies[$currency] = ['count' => 0, 'amount' => 0.00];
                    }
                    $currencies[$currency]['count']++;
                    $currencies[$currency]['amount'] += $amount;
                } else {
                    $invalidRows++;
                    $validationResult['row_errors'][] = $rowValidation;
                    
                    if (count($validationResult['row_errors']) >= 100) {
                        $validationResult['warnings'][] = 'Too many row errors. Validation stopped at row ' . $rowNumber . '. Please fix the issues and try again.';
                        break;
                    }
                }

                $rowNumber++;
            }

            // Update summary
            $validationResult['summary'] = [
                'total_rows' => $rowNumber - 2, // Exclude header
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'total_amount' => round($totalAmount, 2),
                'currencies' => $currencies,
                'processing_time' => round((microtime(true) - $startTime) * 1000, 2) // milliseconds
            ];

            // Determine overall validity
            if ($invalidRows > 0) {
                $validationResult['valid'] = false;
                $validationResult['errors'][] = "File contains {$invalidRows} invalid rows";
            }

            if ($validRows === 0) {
                $validationResult['valid'] = false;
                $validationResult['errors'][] = "File contains no valid payment rows";
            }

            Log::info('CSV file validation completed', [
                'filename' =>