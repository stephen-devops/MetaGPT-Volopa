## Code: app/Services/TemplateService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\TccAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use League\Csv\Writer;
use League\Csv\Exception as CsvException;
use SplTempFileObject;

class TemplateService
{
    /**
     * Currency-specific template headers and their configurations.
     */
    private const CURRENCY_TEMPLATES = [
        'GBP' => [
            'headers' => [
                'beneficiary_name',
                'account_number',
                'sort_code',
                'amount',
                'currency',
                'reference',
                'purpose_code',
                'bank_name',
                'beneficiary_email',
                'beneficiary_phone',
                'additional_reference'
            ],
            'sample_data' => [
                'beneficiary_name' => 'John Smith Ltd',
                'account_number' => '12345678',
                'sort_code' => '123456',
                'amount' => '1000.00',
                'currency' => 'GBP',
                'reference' => 'Invoice INV-2024-001',
                'purpose_code' => 'INVOICE',
                'bank_name' => 'Barclays Bank',
                'beneficiary_email' => 'john@example.com',
                'beneficiary_phone' => '+44 20 1234 5678',
                'additional_reference' => 'Payment for services'
            ],
            'required_fields' => ['beneficiary_name', 'account_number', 'sort_code', 'amount', 'currency', 'reference'],
            'validation_notes' => [
                'Sort code must be 6 digits',
                'Account number must be 8 digits',
                'Amount minimum: £1.00, maximum: £50,000.00',
                'Purpose codes: SALARY, INVOICE, TRADE, SERVICE, DIVIDEND, OTHER'
            ]
        ],
        'EUR' => [
            'headers' => [
                'beneficiary_name',
                'iban',
                'swift_code',
                'amount',
                'currency',
                'reference',
                'purpose_code',
                'bank_name',
                'beneficiary_email',
                'beneficiary_phone',
                'bank_address',
                'additional_reference'
            ],
            'sample_data' => [
                'beneficiary_name' => 'ACME Corporation SARL',
                'iban' => 'FR1420041010050500013M02606',
                'swift_code' => 'BNPAFRPP',
                'amount' => '2500.50',
                'currency' => 'EUR',
                'reference' => 'Contract CT-2024-789',
                'purpose_code' => 'TRADE',
                'bank_name' => 'BNP Paribas',
                'beneficiary_email' => 'finance@acme.fr',
                'beneficiary_phone' => '+33 1 23 45 67 89',
                'bank_address' => '16 Boulevard des Italiens, 75009 Paris',
                'additional_reference' => 'Q1 2024 services'
            ],
            'required_fields' => ['beneficiary_name', 'iban', 'swift_code', 'amount', 'currency', 'reference'],
            'validation_notes' => [
                'IBAN must be valid format with checksum',
                'SWIFT code must be 8 or 11 characters',
                'Amount minimum: €1.00, maximum: €50,000.00',
                'Purpose codes: SALARY, INVOICE, TRADE, SERVICE, DIVIDEND, OTHER'
            ]
        ],
        'USD' => [
            'headers' => [
                'beneficiary_name',
                'account_number',
                'swift_code',
                'bank_code',
                'amount',
                'currency',
                'reference',
                'purpose_code',
                'bank_name',
                'bank_address',
                'beneficiary_email',
                'beneficiary_phone',
                'routing_number',
                'additional_reference'
            ],
            'sample_data' => [
                'beneficiary_name' => 'Global Tech Solutions Inc',
                'account_number' => '1234567890',
                'swift_code' => 'CHASUS33',
                'bank_code' => 'CHASE',
                'amount' => '5000.75',
                'currency' => 'USD',
                'reference' => 'Software License SL-2024-456',
                'purpose_code' => 'SERVICE',
                'bank_name' => 'JPMorgan Chase Bank',
                'bank_address' => '270 Park Avenue, New York, NY 10017',
                'beneficiary_email' => 'payments@globaltech.com',
                'beneficiary_phone' => '+1 212 555 1234',
                'routing_number' => '021000021',
                'additional_reference' => 'Annual license renewal'
            ],
            'required_fields' => ['beneficiary_name', 'account_number', 'swift_code', 'amount', 'currency', 'reference'],
            'validation_notes' => [
                'Account number format varies by bank',
                'SWIFT code must be valid',
                'Routing number required for US domestic transfers',
                'Amount minimum: $1.00, maximum: $50,000.00',
                'Purpose codes: SALARY, INVOICE, TRADE, SERVICE, DIVIDEND, OTHER'
            ]
        ],
        'INR' => [
            'headers' => [
                'beneficiary_name',
                'account_number',
                'swift_code',
                'ifsc_code',
                'amount',
                'currency',
                'reference',
                'purpose_code',
                'bank_name',
                'bank_branch',
                'beneficiary_email',
                'beneficiary_phone',
                'beneficiary_address',
                'pan_number',
                'additional_reference'
            ],
            'sample_data' => [
                'beneficiary_name' => 'Mumbai Software Services Pvt Ltd',
                'account_number' => '123456789012345',
                'swift_code' => 'HDFCINBB',
                'ifsc_code' => 'HDFC0001234',
                'amount' => '150000.00',
                'currency' => 'INR',
                'reference' => 'Development Services DS-2024-999',
                'purpose_code' => 'SERVICE',
                'bank_name' => 'HDFC Bank',
                'bank_branch' => 'Bandra Kurla Complex',
                'beneficiary_email' => 'accounts@mumbaisoftware.in',
                'beneficiary_phone' => '+91 22 1234 5678',
                'beneficiary_address' => 'Plot 123, Sector 5, Mumbai 400051',
                'pan_number' => 'ABCDE1234F',
                'additional_reference' => 'Monthly development milestone'
            ],
            'required_fields' => ['beneficiary_name', 'account_number', 'swift_code', 'amount', 'currency', 'reference', 'purpose_code'],
            'validation_notes' => [
                'Account number must be 9-18 digits',
                'IFSC code must be 11 characters (4 letters + 7 digits/letters)',
                'Purpose code is mandatory for INR payments',
                'Amount minimum: ₹100.00, maximum: ₹50,00,000.00',
                'Purpose codes: SALARY, INVOICE, TRADE, SERVICE, OTHER',
                'PAN number required for amounts above ₹50,000'
            ]
        ]
    ];

    /**
     * Default cache TTL for beneficiary data (1 hour).
     */
    private const CACHE_TTL = 3600;

    /**
     * Maximum number of beneficiaries to include in template examples.
     */
    private const MAX_EXAMPLE_BENEFICIARIES = 5;

    /**
     * Generate CSV template file for the specified currency.
     *
     * @param string $currency Currency code (GBP, EUR, USD, INR)
     * @param int $clientId Client ID to filter beneficiaries
     * @return string CSV content as string
     */
    public function generateTemplate(string $currency, int $clientId): string
    {
        try {
            $currency = strtoupper(trim($currency));

            if (!isset(self::CURRENCY_TEMPLATES[$currency])) {
                throw new \InvalidArgumentException("Unsupported currency: {$currency}");
            }

            Log::info('Generating CSV template', [
                'currency' => $currency,
                'client_id' => $clientId
            ]);

            // Create CSV writer
            $csv = Writer::createFromFileObject(new SplTempFileObject());

            // Set BOM for Excel compatibility
            $csv->setOutputBOM(Writer::BOM_UTF8);

            $template = self::CURRENCY_TEMPLATES[$currency];

            // Add headers
            $csv->insertOne($template['headers']);

            // Add sample data row
            $sampleRow = [];
            foreach ($template['headers'] as $header) {
                $sampleRow[] = $template['sample_data'][$header] ?? '';
            }
            $csv->insertOne($sampleRow);

            // Add real beneficiaries as examples (up to MAX_EXAMPLE_BENEFICIARIES)
            $beneficiaries = $this->getBeneficiariesByCurrency($currency, $clientId);
            $exampleCount = 0;

            foreach ($beneficiaries as $beneficiary) {
                if ($exampleCount >= self::MAX_EXAMPLE_BENEFICIARIES) {
                    break;
                }

                $beneficiaryRow = $this->formatBeneficiaryForTemplate($beneficiary, $template['headers'], $currency);
                $csv->insertOne($beneficiaryRow);
                $exampleCount++;
            }

            // Add instructional rows with validation notes
            $csv->insertOne([]); // Empty row
            $csv->insertOne(['=== VALIDATION NOTES ===']);
            
            foreach ($template['validation_notes'] as $note) {
                $csv->insertOne([$note]);
            }

            $csv->insertOne([]); // Empty row
            $csv->insertOne(['=== REQUIRED FIELDS ===']);
            $csv->insertOne(['Required fields (must not be empty): ' . implode(', ', $template['required_fields'])]);

            $content = $csv->toString();

            Log::info('CSV template generated successfully', [
                'currency' => $currency,
                'client_id' => $clientId,
                'content_length' => strlen($content),
                'example_beneficiaries' => $exampleCount
            ]);

            return $content;

        } catch (CsvException $e) {
            Log::error('CSV generation error', [
                'currency' => $currency,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to generate CSV template: ' . $e->getMessage(), 0, $e);

        } catch (\Exception $e) {
            Log::error('Unexpected error generating template', [
                'currency' => $currency,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Unexpected error while generating template: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get beneficiaries filtered by currency and client.
     *
     * @param string $currency Currency code
     * @param int $clientId Client ID
     * @return Collection Collection of beneficiaries
     */
    public function getBeneficiariesByCurrency(string $currency, int $clientId): Collection
    {
        try {
            $currency = strtoupper(trim($currency));
            $cacheKey = "beneficiaries_template_{$clientId}_{$currency}";

            // Try to get from cache first
            $cachedBeneficiaries = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($currency, $clientId) {
                return $this->fetchBeneficiariesFromDatabase($currency, $clientId);
            });

            Log::debug('Retrieved beneficiaries for template', [
                'currency' => $currency,
                'client_id' => $clientId,
                'count' => $cachedBeneficiaries->count(),
                'from_cache' => Cache::has($cacheKey)
            ]);

            return $cachedBeneficiaries;

        } catch (\Exception $e) {
            Log::error('Error retrieving beneficiaries for template', [
                'currency' => $currency,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            // Return empty collection on error to prevent template generation failure
            return collect([]);
        }
    }

    /**
     * Fetch beneficiaries from database with proper filtering.
     *
     * @param string $currency Currency code
     * @param int $clientId Client ID
     * @return Collection Collection of beneficiaries
     */
    private function fetchBeneficiariesFromDatabase(string $currency, int $clientId): Collection
    {
        return Beneficiary::where('client_id', $clientId)
            ->where('currency', $currency)
            ->where('status', 'active')
            ->select([
                'id',
                'name',
                'account_number',
                'sort_code',
                'iban',
                'swift_code',
                'bank_name',
                'bank_code',
                'country_code',
                'currency',
                'email',
                'phone',
                'address',
                'city',
                'postal_code',
                'additional_data'
            ])
            ->orderBy('name')
            ->limit(self::MAX_EXAMPLE_BENEFICIARIES * 2) // Get more than needed for variety
            ->get();
    }

    /**
     * Format beneficiary data for CSV template row.
     *
     * @param Beneficiary $beneficiary The beneficiary model
     * @param array $headers Template headers
     * @param string $currency Currency code
     * @return array Formatted row data
     */
    private function formatBeneficiaryForTemplate(Beneficiary $beneficiary, array $headers, string $currency): array
    {
        $row = [];

        foreach ($headers as $header) {
            switch ($header) {
                case 'beneficiary_name':
                    $row[] = $beneficiary->name ?? '';
                    break;
                    
                case 'account_number':
                    $row[] = $beneficiary->account_number ?? '';
                    break;
                    
                case 'sort_code':
                    $row[] = $beneficiary->sort_code ?? '';
                    break;
                    
                case 'iban':
                    $row[] = $beneficiary->iban ?? '';
                    break;
                    
                case 'swift_code':
                    $row[] = $beneficiary->swift_code ?? '';
                    break;
                    
                case 'bank_code':
                    $row[] = $beneficiary->bank_code ?? '';
                    break;
                    
                case 'bank_name':
                    $row[] = $beneficiary->bank_name ?? '';
                    break;
                    
                case 'bank_address':
                case 'bank_branch':
                    $row[] = $beneficiary->getAdditionalData('bank_address', '');
                    break;
                    
                case 'beneficiary_email':
                    $row[] = $beneficiary->email ?? '';
                    break;
                    
                case 'beneficiary_phone':
                    $row[] = $beneficiary->phone ?? '';
                    break;
                    
                case 'beneficiary_address':
                    $row[] = $beneficiary->getFullAddress();
                    break;
                