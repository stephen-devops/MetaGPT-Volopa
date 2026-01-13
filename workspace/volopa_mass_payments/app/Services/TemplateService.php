## Code: app/Services/TemplateService.php

```php
<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Writer;
use League\Csv\Exception as CsvException;
use Exception;
use InvalidArgumentException;

class TemplateService
{
    /**
     * Enable recipient templates flag
     */
    protected bool $enableRecipientTemplates;

    /**
     * Maximum recipients in template
     */
    protected int $maxRecipientsInTemplate;

    /**
     * Template cache minutes
     */
    protected int $templateCacheMinutes;

    /**
     * Include sample data flag
     */
    protected bool $includeSampleData;

    /**
     * Template formats
     */
    protected array $templateFormats;

    /**
     * Supported currencies
     */
    protected array $supportedCurrencies;

    /**
     * Currency specific settings
     */
    protected array $currencySettings;

    /**
     * Purpose codes
     */
    protected array $purposeCodes;

    /**
     * Required CSV headers
     */
    protected array $requiredHeaders;

    /**
     * Optional CSV headers
     */
    protected array $optionalHeaders;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->enableRecipientTemplates = config('mass-payments.templates.enable_recipient_templates', true);
        $this->maxRecipientsInTemplate = config('mass-payments.templates.max_recipients_in_template', 1000);
        $this->templateCacheMinutes = config('mass-payments.templates.template_cache_minutes', 60);
        $this->includeSampleData = config('mass-payments.templates.include_sample_data', true);
        $this->templateFormats = config('mass-payments.templates.template_formats', ['csv', 'xlsx']);
        $this->supportedCurrencies = array_keys(config('mass-payments.supported_currencies', ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY']));
        $this->currencySettings = config('mass-payments.currency_settings', []);
        $this->purposeCodes = array_keys(config('mass-payments.purpose_codes', []));
        $this->requiredHeaders = config('mass-payments.validation.required_csv_headers', [
            'amount', 'currency', 'beneficiary_name', 'beneficiary_account', 'bank_code'
        ]);
        $this->optionalHeaders = config('mass-payments.validation.optional_csv_headers', [
            'reference', 'purpose_code', 'beneficiary_address', 'beneficiary_country', 'beneficiary_city', 'intermediary_bank', 'special_instructions'
        ]);
    }

    /**
     * Generate recipient template with existing beneficiaries for a client and currency
     *
     * @param string $currency
     * @param int $clientId
     * @return string
     * @throws Exception
     */
    public function generateRecipientTemplate(string $currency, int $clientId): string
    {
        // Validate parameters
        $this->validateTemplateParameters($currency, $clientId);

        // Check if recipient templates are enabled
        if (!$this->enableRecipientTemplates) {
            throw new Exception('Recipient templates are not enabled');
        }

        Log::info('Generating recipient template', [
            'currency' => $currency,
            'client_id' => $clientId,
        ]);

        try {
            // Get recipients for the client and currency
            $recipients = $this->getRecipientsByClientAndCurrency($clientId, $currency);

            // Generate CSV content with recipients
            $csvContent = $this->buildRecipientCsvContent($currency, $recipients);

            // Store template temporarily and return file path
            return $this->storeTemporaryTemplate($csvContent, "recipient_template_{$currency}_{$clientId}");

        } catch (Exception $e) {
            Log::error('Failed to generate recipient template', [
                'currency' => $currency,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to generate recipient template: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate blank template for a specific currency
     *
     * @param string $currency
     * @return string
     * @throws Exception
     */
    public function generateBlankTemplate(string $currency): string
    {
        // Validate currency
        if (empty($currency) || !in_array(strtoupper($currency), $this->supportedCurrencies)) {
            throw new InvalidArgumentException('Invalid or unsupported currency provided');
        }

        $currency = strtoupper($currency);

        Log::info('Generating blank template', [
            'currency' => $currency,
        ]);

        try {
            // Check cache first
            $cacheKey = "blank_template_{$currency}";
            
            if ($this->templateCacheMinutes > 0) {
                $cachedPath = Cache::get($cacheKey);
                if ($cachedPath && Storage::exists($cachedPath)) {
                    Log::debug('Returning cached blank template', ['currency' => $currency]);
                    return Storage::path($cachedPath);
                }
            }

            // Generate CSV content
            $csvContent = $this->buildBlankCsvContent($currency);

            // Store template temporarily
            $templatePath = $this->storeTemporaryTemplate($csvContent, "blank_template_{$currency}");

            // Cache the template path if caching is enabled
            if ($this->templateCacheMinutes > 0) {
                Cache::put($cacheKey, str_replace(Storage::path(''), '', $templatePath), $this->templateCacheMinutes);
            }

            return $templatePath;

        } catch (Exception $e) {
            Log::error('Failed to generate blank template', [
                'currency' => $currency,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to generate blank template: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get recipients by client and currency
     *
     * @param int $clientId
     * @param string $currency
     * @return Collection
     */
    public function getRecipientsByClientAndCurrency(int $clientId, string $currency): Collection
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException('Invalid client ID provided');
        }

        if (empty($currency) || !in_array(strtoupper($currency), $this->supportedCurrencies)) {
            throw new InvalidArgumentException('Invalid or unsupported currency provided');
        }

        $currency = strtoupper($currency);

        Log::debug('Fetching recipients for template', [
            'client_id' => $clientId,
            'currency' => $currency,
        ]);

        try {
            // Get beneficiaries for the client that have been used with this currency
            // or all beneficiaries if no currency-specific filtering is needed
            $recipients = Beneficiary::where('client_id', $clientId)
                ->when($this->shouldFilterByCurrency($currency), function ($query) use ($currency) {
                    // Filter by beneficiaries who have received payments in this currency
                    $query->whereHas('paymentInstructions', function ($q) use ($currency) {
                        $q->where('currency', $currency);
                    });
                })
                ->orderBy('name')
                ->limit($this->maxRecipientsInTemplate)
                ->get(['id', 'name', 'account_number', 'bank_code', 'country', 'address', 'city']);

            Log::info('Recipients fetched for template', [
                'client_id' => $clientId,
                'currency' => $currency,
                'recipient_count' => $recipients->count(),
            ]);

            return $recipients;

        } catch (Exception $e) {
            Log::error('Failed to fetch recipients for template', [
                'client_id' => $clientId,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            // Return empty collection on error
            return new Collection();
        }
    }

    /**
     * Build CSV content for recipient template
     *
     * @param string $currency
     * @param Collection $recipients
     * @return string
     * @throws Exception
     */
    protected function buildRecipientCsvContent(string $currency, Collection $recipients): string
    {
        try {
            // Create CSV writer
            $csv = Writer::createFromString();

            // Set BOM for Excel compatibility
            $csv->insertOne(["\xEF\xBB\xBF"]);

            // Get headers for this currency
            $headers = $this->getTemplateHeaders($currency);
            $csv->insertOne($headers);

            // Add recipient data
            foreach ($recipients as $recipient) {
                $row = $this->buildRecipientRow($recipient, $currency, $headers);
                $csv->insertOne($row);
            }

            // Add sample rows if enabled and we have fewer than 3 recipients
            if ($this->includeSampleData && $recipients->count() < 3) {
                $sampleRowsToAdd = 3 - $recipients->count();
                for ($i = 0; $i < $sampleRowsToAdd; $i++) {
                    $sampleRow = $this->buildSampleRow($currency, $headers, $i + 1);
                    $csv->insertOne($sampleRow);
                }
            }

            return $csv->toString();

        } catch (CsvException $e) {
            throw new Exception('Failed to build recipient CSV content: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build CSV content for blank template
     *
     * @param string $currency
     * @return string
     * @throws Exception
     */
    protected function buildBlankCsvContent(string $currency): string
    {
        try {
            // Create CSV writer
            $csv = Writer::createFromString();

            // Set BOM for Excel compatibility
            $csv->insertOne(["\xEF\xBB\xBF"]);

            // Get headers for this currency
            $headers = $this->getTemplateHeaders($currency);
            $csv->insertOne($headers);

            // Add sample rows if enabled
            if ($this->includeSampleData) {
                for ($i = 1; $i <= 3; $i++) {
                    $sampleRow = $this->buildSampleRow($currency, $headers, $i);
                    $csv->insertOne($sampleRow);
                }
            }

            return $csv->toString();

        } catch (CsvException $e) {
            throw new Exception('Failed to build blank CSV content: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get template headers for a specific currency
     *
     * @param string $currency
     * @return array
     */
    protected function getTemplateHeaders(string $currency): array
    {
        $headers = [];

        // Add required headers
        foreach ($this->requiredHeaders as $header) {
            $headers[] = $this->formatHeaderName($header);
        }

        // Add currency-specific required headers
        $currencyConfig = $this->currencySettings[$currency] ?? [];
        
        if ($currencyConfig['requires_iban'] ?? false) {
            $headers[] = 'IBAN';
        }

        if ($currencyConfig['requires_swift_code'] ?? false) {
            $headers[] = 'SWIFT Code';
        }

        if ($currencyConfig['requires_sort_code'] ?? false) {
            $headers[] = 'Sort Code';
        }

        if ($currencyConfig['requires_bsb'] ?? false) {
            $headers[] = 'BSB';
        }

        // Add optional headers that are commonly used
        $commonOptionalHeaders = ['reference', 'purpose_code', 'beneficiary_address', 'beneficiary_country', 'beneficiary_city'];
        
        foreach ($commonOptionalHeaders as $header) {
            if (in_array($header, $this->optionalHeaders)) {
                $headers[] = $this->formatHeaderName($header);
            }
        }

        return array_unique($headers);
    }

    /**
     * Build recipient row data
     *
     * @param Beneficiary $recipient
     * @param string $currency
     * @param array $headers
     * @return array
     */
    protected function buildRecipientRow(Beneficiary $recipient, string $currency, array $headers): array
    {
        $row = [];

        foreach ($headers as $header) {
            $value = match (strtolower(str_replace(' ', '_', $header))) {
                'amount' => $this->getSampleAmount($currency),
                'currency' => $currency,
                'beneficiary_name' => $recipient->name ?? '',
                'beneficiary_account', 'account_number' => $recipient->account_number ?? '',
                'bank_code' => $recipient->bank_code ?? '',
                'beneficiary_address', 'address' => $recipient->address ?? '',
                'beneficiary_country', 'country' => $recipient->country ?? '',
                'beneficiary_city', 'city' => $recipient->city ?? '',
                'reference' => 'Payment to ' . ($recipient->name ?? 'Beneficiary'),
                'purpose_code' => $this->getDefaultPurposeCode($currency),
                'iban' => '', // Leave blank for user to fill
                'swift_code' => '', // Leave blank for user to fill
                'sort_code' => '', // Leave blank for user to fill
                'bsb' => '', // Leave blank for user to fill
                'intermediary_bank' => '',
                'special_instructions' => '',
                default => '',
            };

            $row[] = $value;
        }

        return $row;
    }

    /**
     * Build sample row data
     *
     * @param string $currency
     * @param array $headers
     * @param int $rowNumber
     * @return array
     */
    protected function buildSampleRow(string $currency, array $headers, int $rowNumber): array
    {
        $row = [];

        foreach ($headers as $header) {
            $value = match (strtolower(str_replace(' ', '_', $header))) {
                'amount' => $this->getSampleAmount($currency),
                'currency' => $currency,
                'beneficiary_name' => "Sample Beneficiary {$rowNumber}",
                'beneficiary_account', 'account_number' => $this->getSampleAccountNumber($currency, $rowNumber),
                'bank_code' => $this->getSampleBankCode($currency),
                'beneficiary_address', 'address' => "123 Sample Street {$rowNumber}",
                'beneficiary_country', 'country' => $this->getSampleCountry($currency),
                'beneficiary_city', 'city' => $this->getSampleCity($currency),
                'reference' => "Sample Payment Reference {$rowNumber}",
                'purpose_code' => $this->getDefaultPurposeCode($currency),
                'iban' => $this->getSampleIban($currency),
                'swift_code' => $this->getSampleSwiftCode($currency),
                'sort_code' => $this->getSampleSortCode($currency),
                'bsb' => $this->getSampleBsb($currency),
                'intermediary_bank' => '',
                'special_instructions' => '',
                default => '',
            };

            $row[] = $value;
        }

        return $row;
    }

    /**
     * Store template temporarily
     *
     * @param string $content
     * @param string $filename
     * @return string
     * @throws Exception
     */
    protected function storeTemporaryTemplate(