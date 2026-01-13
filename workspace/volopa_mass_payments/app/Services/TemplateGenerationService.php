## Code: app/Services/TemplateGenerationService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\TccAccount;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Csv\Writer;
use League\Csv\Exception as CsvException;

class TemplateGenerationService
{
    /**
     * Default CSV delimiter.
     */
    private const DEFAULT_DELIMITER = ',';

    /**
     * Default CSV enclosure.
     */
    private const DEFAULT_ENCLOSURE = '"';

    /**
     * Default CSV escape character.
     */
    private const DEFAULT_ESCAPE = '\\';

    /**
     * Template cache TTL in seconds (1 hour).
     */
    private const TEMPLATE_CACHE_TTL = 3600;

    /**
     * Supported template types.
     */
    private const TEMPLATE_TYPES = [
        'basic' => 'Basic Payment Template',
        'detailed' => 'Detailed Payment Template',
        'international' => 'International Payment Template',
        'domestic' => 'Domestic Payment Template',
        'custom' => 'Custom Template'
    ];

    /**
     * Currency-specific template configurations.
     */
    private const CURRENCY_TEMPLATES = [
        'USD' => [
            'type' => 'international',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code', 'swift_code'
            ],
            'optional_fields' => [
                'beneficiary_address', 'bank_name', 'bank_address', 
                'remittance_information', 'payment_reference'
            ]
        ],
        'EUR' => [
            'type' => 'international',
            'required_fields' => [
                'beneficiary_name', 'iban', 'swift_code', 'amount', 
                'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'account_number', 'bank_code', 'bank_name', 'bank_address',
                'remittance_information', 'payment_reference'
            ]
        ],
        'GBP' => [
            'type' => 'international',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'iban', 'swift_code', 'bank_name', 'bank_address',
                'remittance_information', 'payment_reference'
            ]
        ],
        'SGD' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code'
            ],
            'optional_fields' => [
                'beneficiary_address', 'bank_name', 'remittance_information', 
                'payment_reference', 'beneficiary_email'
            ]
        ],
        'HKD' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code'
            ],
            'optional_fields' => [
                'beneficiary_address', 'bank_name', 'remittance_information', 
                'payment_reference', 'beneficiary_email'
            ]
        ],
        'AUD' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code'
            ],
            'optional_fields' => [
                'beneficiary_address', 'bank_name', 'remittance_information', 
                'payment_reference'
            ]
        ],
        'CAD' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'routing_number', 'amount', 
                'currency', 'purpose_code'
            ],
            'optional_fields' => [
                'bank_code', 'beneficiary_address', 'bank_name', 'bank_address',
                'remittance_information', 'payment_reference'
            ]
        ],
        'JPY' => [
            'type' => 'international',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'swift_code', 
                'amount', 'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'bank_name', 'bank_address', 'remittance_information', 
                'payment_reference'
            ]
        ],
        'CNY' => [
            'type' => 'international',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'swift_code', 
                'amount', 'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'bank_name', 'bank_address', 'remittance_information', 
                'payment_reference'
            ]
        ],
        'THB' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code'
            ],
            'optional_fields' => [
                'beneficiary_address', 'bank_name', 'remittance_information', 
                'payment_reference'
            ]
        ],
        'MYR' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code'
            ],
            'optional_fields' => [
                'beneficiary_address', 'bank_name', 'remittance_information', 
                'payment_reference'
            ]
        ],
        'IDR' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'bank_name', 'remittance_information', 'payment_reference'
            ]
        ],
        'PHP' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'bank_name', 'remittance_information', 'payment_reference'
            ]
        ],
        'VND' => [
            'type' => 'domestic',
            'required_fields' => [
                'beneficiary_name', 'account_number', 'bank_code', 'amount', 
                'currency', 'purpose_code', 'beneficiary_address'
            ],
            'optional_fields' => [
                'bank_name', 'remittance_information', 'payment_reference'
            ]
        ]
    ];

    /**
     * Field descriptions for template headers.
     */
    private const FIELD_DESCRIPTIONS = [
        'beneficiary_name' => 'Full name of the payment recipient (max 140 characters)',
        'account_number' => 'Bank account number of the beneficiary',
        'bank_code' => 'Bank code or sort code for the beneficiary bank',
        'amount' => 'Payment amount (numeric, up to 2 decimal places)',
        'currency' => 'ISO 3-letter currency code (e.g., USD, EUR, GBP)',
        'purpose_code' => 'Payment purpose code (SAL, DIV, INT, FEE, RFD, TRD, SVC, SUP, INV, OTH)',
        'beneficiary_address' => 'Full address of the beneficiary (required for some currencies)',
        'beneficiary_country' => 'ISO 2-letter country code of the beneficiary',
        'bank_name' => 'Name of the beneficiary bank',
        'bank_address' => 'Address of the beneficiary bank',
        'swift_code' => 'SWIFT/BIC code of the beneficiary bank (8 or 11 characters)',
        'iban' => 'International Bank Account Number (for EUR payments)',
        'routing_number' => 'Bank routing number (for CAD and USD domestic payments)',
        'remittance_information' => 'Payment description or invoice reference (max 140 characters)',
        'payment_reference' => 'Unique reference for this payment (alphanumeric)',
        'beneficiary_email' => 'Email address of the beneficiary (optional)',
        'beneficiary_phone' => 'Phone number of the beneficiary (optional)',
        'intermediate_bank_code' => 'Intermediate bank code (for correspondent banking)',
        'intermediate_bank_name' => 'Name of intermediate bank',
        'intermediate_swift_code' => 'SWIFT code of intermediate bank'
    ];

    /**
     * Sample data for template generation.
     */
    private const SAMPLE_DATA = [
        'beneficiary_name' => 'John Doe',
        'account_number' => '1234567890',
        'bank_code' => '12345678',
        'amount' => '1000.00',
        'currency' => 'USD',
        'purpose_code' => 'SAL',
        'beneficiary_address' => '123 Main Street, Anytown, NY 12345, USA',
        'beneficiary_country' => 'US',
        'bank_name' => 'Sample Bank',
        'bank_address' => '456 Bank Street, Financial District, NY 10001, USA',
        'swift_code' => 'SAMPUS33',
        'iban' => 'GB82WEST12345698765432',
        'routing_number' => '021000021',
        'remittance_information' => 'Salary payment for January 2024',
        'payment_reference' => 'PAY-2024-001',
        'beneficiary_email' => 'john.doe@example.com',
        'beneficiary_phone' => '+1-555-123-4567',
        'intermediate_bank_code' => '87654321',
        'intermediate_bank_name' => 'Correspondent Bank',
        'intermediate_swift_code' => 'CORREUS33'
    ];

    /**
     * Generate CSV template for mass payments.
     *
     * @param string $currency
     * @param string $templateType
     * @param bool $includeSampleData
     * @param bool $includeDescriptions
     * @return Response
     */
    public function generateTemplate(
        string $currency = 'USD',
        string $templateType = 'basic',
        bool $includeSampleData = true,
        bool $includeDescriptions = true
    ): Response {
        Log::info('Generating CSV template', [
            'currency' => $currency,
            'template_type' => $templateType,
            'include_sample_data' => $includeSampleData,
            'include_descriptions' => $includeDescriptions
        ]);

        try {
            // Normalize currency
            $currency = strtoupper(trim($currency));

            // Validate currency
            if (!isset(self::CURRENCY_TEMPLATES[$currency])) {
                throw new \InvalidArgumentException("Unsupported currency: {$currency}");
            }

            // Get template configuration
            $templateConfig = $this->getTemplateConfiguration($currency, $templateType);

            // Generate CSV content
            $csvContent = $this->generateCsvContent($templateConfig, $includeSampleData, $includeDescriptions);

            // Create filename
            $filename = $this->generateFilename($currency, $templateType);

            // Return response
            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Content-Length', strlen($csvContent))
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            Log::error('Template generation failed', [
                'currency' => $currency,
                'template_type' => $templateType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Generate CSV template with beneficiary data pre-populated.
     *
     * @param string $clientId
     * @param string $currency
     * @param array $beneficiaryIds
     * @param string $templateType
     * @return Response
     */
    public function generateTemplateWithBeneficiaries(
        string $clientId,
        string $currency = 'USD',
        array $beneficiaryIds = [],
        string $templateType = 'basic'
    ): Response {
        Log::info('Generating CSV template with beneficiaries', [
            'client_id' => $clientId,
            'currency' => $currency,
            'beneficiary_count' => count($beneficiaryIds),
            'template_type' => $templateType
        ]);

        try {
            // Normalize currency
            $currency = strtoupper(trim($currency));

            // Validate currency
            if (!isset(self::CURRENCY_TEMPLATES[$currency])) {
                throw new \InvalidArgumentException("Unsupported currency: {$currency}");
            }

            // Get template configuration
            $templateConfig = $this->getTemplateConfiguration($currency, $templateType);

            // Load beneficiaries
            $beneficiaries = $this->loadBeneficiaries($clientId, $beneficiaryIds, $currency);

            // Generate CSV content with beneficiary data
            $csvContent = $this->generateCsvContentWithBeneficiaries($templateConfig, $beneficiaries);

            // Create filename
            $filename = $this->generateFilename($currency, $templateType, 'beneficiaries');

            // Return response
            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Content-Length', strlen($csvContent))
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            Log::error('Template generation with beneficiaries failed', [
                'client_id' => $clientId,
                'currency' => $currency,
                'beneficiary_count' => count($beneficiaryIds),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Get available template types.
     *
     * @return array
     */
    public function getAvailableTemplateTypes(): array
    {
        return self::TEMPLATE_TYPES;
    }

    /**
     * Get supported currencies for template generation.
     *
     * @return array
     */
    public function getSupportedCurrencies(): array
    {
        return array_keys(self::CURRENCY_TEMPLATES);
    }

    /**
     * Get template configuration