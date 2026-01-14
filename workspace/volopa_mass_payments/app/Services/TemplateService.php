## Code: app/Services/TemplateService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Http\Response;
use League\Csv\Writer;
use Exception;

class TemplateService
{
    /**
     * Cache TTL for template data in minutes.
     */
    private const CACHE_TTL = 30;

    /**
     * Default template format.
     */
    private const DEFAULT_FORMAT = 'csv';

    /**
     * Maximum number of beneficiaries to include in template.
     */
    private const MAX_BENEFICIARIES = 1000;

    /**
     * Supported template formats.
     */
    private const SUPPORTED_FORMATS = ['csv', 'xlsx', 'json'];

    /**
     * Template types.
     */
    private const TEMPLATE_TYPES = ['basic', 'detailed', 'with_beneficiaries'];

    /**
     * Currencies that require invoice details.
     */
    private const INVOICE_REQUIRED_CURRENCIES = ['INR'];

    /**
     * Currencies that require incorporation number for business recipients.
     */
    private const INCORPORATION_REQUIRED_CURRENCIES = ['TRY'];

    /**
     * Generate and download mass payment template.
     */
    public function generateTemplate(array $params): array
    {
        try {
            $startTime = microtime(true);

            // Validate and normalize parameters
            $params = $this->normalizeTemplateParams($params);

            Log::info('Generating mass payment template', [
                'currency' => $params['currency'],
                'format' => $params['format'],
                'template_type' => $params['template_type'],
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
            ]);

            // Generate template content based on format
            $templateData = match ($params['format']) {
                'csv' => $this->generateCsvTemplate($params),
                'xlsx' => $this->generateExcelTemplate($params),
                'json' => $this->generateJsonTemplate($params),
                default => throw new Exception('Unsupported template format: ' . $params['format'])
            };

            $processingTime = round(microtime(true) - $startTime, 2);

            Log::info('Template generation completed', [
                'currency' => $params['currency'],
                'format' => $params['format'],
                'processing_time' => $processingTime,
                'content_size' => strlen($templateData['content']),
            ]);

            return [
                'content' => $templateData['content'],
                'filename' => $templateData['filename'],
                'content_type' => $templateData['content_type'],
                'size' => strlen($templateData['content']),
                'generated_at' => now()->toISOString(),
                'metadata' => $this->getTemplateMetadata($params),
            ];

        } catch (Exception $e) {
            Log::error('Template generation failed', [
                'params' => $params,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            throw $e;
        }
    }

    /**
     * Get template headers based on currency and requirements.
     */
    public function getTemplateHeaders(string $currency, string $templateType = 'basic'): array
    {
        $cacheKey = "template_headers_{$currency}_{$templateType}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($currency, $templateType) {
            $headers = $this->getBaseHeaders();

            // Add currency-specific headers
            $headers = array_merge($headers, $this->getCurrencySpecificHeaders($currency));

            // Add template type specific headers
            $headers = array_merge($headers, $this->getTemplateTypeHeaders($templateType));

            return $headers;
        });
    }

    /**
     * Get sample data for template based on currency and filters.
     */
    public function getSampleData(array $params): array
    {
        $currency = strtoupper($params['currency']);
        $sampleCount = min($params['sample_rows_count'] ?? 5, 100);

        $sampleData = [];

        for ($i = 1; $i <= $sampleCount; $i++) {
            $sampleRow = $this->generateSampleRow($currency, $i);
            $sampleData[] = $sampleRow;
        }

        return $sampleData;
    }

    /**
     * Get beneficiaries filtered by currency and criteria.
     */
    public function getBeneficiariesForTemplate(array $filters): Collection
    {
        $query = Beneficiary::query()
            ->where('client_id', Auth::user()->client_id)
            ->active();

        // Apply filters
        if (!empty($filters['country'])) {
            $query->where('country', strtoupper($filters['country']));
        }

        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'verified') {
                $query->verified();
            } elseif ($filters['status'] === 'active') {
                $query->active();
            }
        }

        // Apply currency filter if beneficiary supports it
        $currency = $filters['currency'] ?? null;
        if ($currency) {
            $query->byCurrency(strtoupper($currency));
        }

        // Limit results
        $limit = min($filters['limit'] ?? 100, self::MAX_BENEFICIARIES);
        
        return $query->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Generate CSV template.
     */
    private function generateCsvTemplate(array $params): array
    {
        $headers = $this->getTemplateHeaders($params['currency'], $params['template_type']);
        $content = '';

        // Create CSV writer
        $csv = Writer::createFromString($content);

        // Add BOM for Excel compatibility
        $csv->insertOne(array_keys($headers));

        // Add instructions row if requested
        if ($params['include_instructions']) {
            $instructionRow = $this->generateInstructionRow($headers, $params['currency']);
            $csv->insertOne($instructionRow);
        }

        // Add sample data if requested
        if ($params['include_sample_data']) {
            $sampleData = $this->getSampleData($params);
            foreach ($sampleData as $row) {
                $csvRow = [];
                foreach (array_keys($headers) as $header) {
                    $csvRow[] = $row[$header] ?? '';
                }
                $csv->insertOne($csvRow);
            }
        }

        // Add beneficiary data if requested
        if ($params['include_beneficiaries']) {
            $beneficiaries = $this->getBeneficiariesForTemplate($params['beneficiary_filters']);
            foreach ($beneficiaries as $beneficiary) {
                $beneficiaryRow = $this->convertBeneficiaryToRow($beneficiary, $headers);
                $csv->insertOne($beneficiaryRow);
            }
        }

        // If no data rows added, add empty template rows
        if (!$params['include_sample_data'] && !$params['include_beneficiaries']) {
            for ($i = 0; $i < 3; $i++) {
                $csv->insertOne(array_fill(0, count($headers), ''));
            }
        }

        $filename = $this->generateFilename($params, 'csv');

        return [
            'content' => $csv->toString(),
            'filename' => $filename,
            'content_type' => 'text/csv',
        ];
    }

    /**
     * Generate Excel template.
     */
    private function generateExcelTemplate(array $params): array
    {
        // For now, generate CSV and return with Excel MIME type
        // In a real implementation, you would use PhpSpreadsheet
        $csvTemplate = $this->generateCsvTemplate($params);
        
        $filename = $this->generateFilename($params, 'xlsx');

        return [
            'content' => $csvTemplate['content'],
            'filename' => $filename,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /**
     * Generate JSON template.
     */
    private function generateJsonTemplate(array $params): array
    {
        $headers = $this->getTemplateHeaders($params['currency'], $params['template_type']);
        
        $templateStructure = [
            'version' => '1.0',
            'currency' => $params['currency'],
            'template_type' => $params['template_type'],
            'generated_at' => now()->toISOString(),
            'headers' => $headers,
            'instructions' => $this->getFieldInstructions($headers, $params['currency']),
            'validation_rules' => $params['include_validation_rules'] ? $this->getValidationRules($params['currency']) : null,
            'sample_data' => $params['include_sample_data'] ? $this->getSampleData($params) : [],
            'beneficiaries' => $params['include_beneficiaries'] ? $this->getBeneficiaryData($params) : [],
        ];

        $filename = $this->generateFilename($params, 'json');

        return [
            'content' => json_encode($templateStructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'filename' => $filename,
            'content_type' => 'application/json',
        ];
    }

    /**
     * Get base required headers for all templates.
     */
    private function getBaseHeaders(): array
    {
        return [
            'beneficiary_name' => 'Beneficiary Name (Required)',
            'amount' => 'Payment Amount (Required)',
            'reference' => 'Payment Reference (Required)',
            'purpose_code' => 'Purpose Code (Required)',
            'beneficiary_email' => 'Beneficiary Email (Required)',
            'beneficiary_country' => 'Country Code (Required)',
            'beneficiary_type' => 'Beneficiary Type (individual/business)',
        ];
    }

    /**
     * Get currency-specific headers.
     */
    private function getCurrencySpecificHeaders(string $currency): array
    {
        $currency = strtoupper($currency);
        $headers = [];

        // Add invoice fields for required currencies
        if (in_array($currency, self::INVOICE_REQUIRED_CURRENCIES)) {
            $headers['invoice_number'] = 'Invoice Number (Required for ' . $currency . ')';
            $headers['invoice_date'] = 'Invoice Date (YYYY-MM-DD, Required for ' . $currency . ')';
        }

        // Add incorporation number for business recipients in certain currencies
        if (in_array($currency, self::INCORPORATION_REQUIRED_CURRENCIES)) {
            $headers['incorporation_number'] = 'Incorporation Number (Required for business in ' . $currency . ')';
        }

        return $headers;
    }

    /**
     * Get template type specific headers.
     */
    private function getTemplateTypeHeaders(string $templateType): array
    {
        return match ($templateType) {
            'detailed' => [
                'beneficiary_address_line1' => 'Address Line 1',
                'beneficiary_address_line2' => 'Address Line 2',
                'beneficiary_city' => 'City',
                'beneficiary_state' => 'State/Province',
                'beneficiary_postal_code' => 'Postal Code',
                'beneficiary_phone' => 'Phone Number',
            ],
            'with_beneficiaries' => [
                'beneficiary_account_number' => 'Account Number',
                'beneficiary_sort_code' => 'Sort Code',
                'beneficiary_iban' => 'IBAN',
                'beneficiary_swift_code' => 'SWIFT/BIC Code',
                'beneficiary_bank_name' => 'Bank Name',
                'beneficiary_bank_address' => 'Bank Address',
            ],
            default => []
        };
    }

    /**
     * Generate instruction row for CSV template.
     */
    private function generateInstructionRow(array $headers, string $currency): array
    {
        $instructions = [];
        
        foreach (array_keys($headers) as $header) {
            $instructions[] = $this->getFieldInstruction($header, $currency);
        }

        return $instructions;
    }

    /**
     * Get instruction for a specific field.
     */
    private function getFieldInstruction(string $field, string $currency): string
    {
        return match ($field) {
            'beneficiary_name' => 'Full name of the recipient (2-100 characters)',
            'amount' => 'Payment amount in ' . $currency . ' (0.01 - 1,000,000.00)',
            'reference' => 'Unique payment reference (up to 255 characters)',
            'purpose_code' => 'Valid purpose code (e.g., P0101, P0201)',
            'beneficiary_email' => 'Valid email address of recipient',
            'beneficiary_country' => 'ISO 3166-1 alpha-2 country code (e.g., US, GB)',
            'beneficiary_type' => 'Either "individual" or "business"',
            'invoice_number' => 'Invoice number (required for ' . $currency . ', max 50 chars)',
            'invoice_date' => 'Invoice date in YYYY-MM-DD format',
            'incorporation_number' => 'Company incorporation number (required for business in ' . $currency . ')',
            'beneficiary_account_number' => 'Bank account number',
            'beneficiary_sort_code' => 'Bank sort code (UK banks)',
            'beneficiary_iban' => 'International Bank Account Number',
            'beneficiary_swift_code' => 'SWIFT/BIC code (8 or 11 characters)',
            'beneficiary_bank_name' => 'Name of recipient bank',
            'beneficiary_bank_address' => 'Address of recipient bank',
            'beneficiary_address_line1' => 'Primary address line',
            'beneficiary_address_line2' => 'Secondary address line (optional)',
            'beneficiary_city' => 'City name',
            'beneficiary_state' => 'State or province',
            'beneficiary_postal_code' => 'Postal or ZIP code',
            'beneficiary_phone' => 'Phone number with country code',
            default => 'Enter ' . str_replace(['_', 'beneficiary'], [' ', ''], $field)
        };
    }

    /**
     * Generate sample row data.
     */
    private function generateSampleRow(string $currency, int $index): array
    {
        $sampleData = [
            'beneficiary_name' => "Sample Recipient {$index}",
            'amount' => number_format(rand(100, 5000), 2, '.', ''),
            'reference' => "REF-" . str_pad($index, 6, '0', STR_PAD_LEFT),
            'purpose_code' => 'P0101',
            'beneficiary_email' => "recipient{$index}@example.com",
            'beneficiary_country' => $this->getSampleCountryCode($index),
            'beneficiary_type' => $index % 2 === 0 ? 'individual' : 'business',
        ];

        // Add currency-specific sample data
        if (in_array($currency, self::INVOICE_REQUIRED_CURRENCIES)) {
            $sampleData['invoice_number'] = "INV-2024-" . str_pad($index, 4, '0', STR_PAD_LEFT);
            $sampleData['invoice_date'] = now()->subDays(rand(1, 30))->format('Y-m-