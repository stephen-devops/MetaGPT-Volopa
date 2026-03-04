## Code: app/Services/FXConversionService.php

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

/**
 * FXConversionService
 * 
 * Service class for foreign exchange conversion integration with platform FX services.
 * Handles currency conversion, rate lookup, commission calculation, and 30-day lookback logic
 * for pocket expense FX operations with multi-tenant support.
 * 
 * Business Rules:
 * - FX rate lookup: maximum 30-day lookback from expense date, return 'No FX Available' if not found
 * - Commission formula: AdjustedRate = BaseRate × (1 - Commission%)
 * - Backend must recalculate FX on save, do not trust frontend-only values
 * - Currency Code must be 3-letter ISO format and validated against platform list
 * - Integration with existing platform FX infrastructure via /wallet-ccy-value endpoint
 * - Debouncing and commission calculation with expense-specific features
 */
class FXConversionService
{
    /**
     * Maximum number of days to look back for FX rates.
     *
     * @var int
     */
    private const MAX_LOOKBACK_DAYS = 30;

    /**
     * Default commission percentage (0.5%).
     *
     * @var float
     */
    private const DEFAULT_COMMISSION_PERCENT = 0.5;

    /**
     * Cache TTL for FX rates in minutes.
     *
     * @var int
     */
    private const CACHE_TTL_MINUTES = 60;

    /**
     * Valid 3-letter ISO currency codes supported by platform.
     *
     * @var array<int, string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'KRW', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR',
        'PLN', 'DKK', 'CZK', 'HUF', 'ILS', 'AED', 'SAR', 'THB', 'MYR', 'PHP'
    ];

    /**
     * Platform FX service endpoint.
     *
     * @var string
     */
    private const FX_SERVICE_ENDPOINT = '/api/v1/wallet-ccy-value';

    /**
     * HTTP timeout for FX service calls in seconds.
     *
     * @var int
     */
    private const HTTP_TIMEOUT_SECONDS = 10;

    /**
     * Get the base currency for a specific client.
     *
     * @param int $clientId Client ID to get base currency for
     * @return string 3-letter ISO currency code
     * 
     * @throws InvalidArgumentException If client ID is invalid
     * @throws Exception If base currency cannot be determined
     */
    public function getBaseCurrency(int $clientId): string
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException('Client ID must be a positive integer.');
        }

        try {
            // Query client's base currency through wallet configuration
            $baseCurrency = DB::table('clients')
                ->join('wallets', 'clients.id', '=', 'wallets.client_id')
                ->join('currencies', 'wallets.currency_id', '=', 'currencies.id')
                ->where('clients.id', $clientId)
                ->where('wallets.is_primary', true)
                ->value('currencies.code');

            if (!$baseCurrency) {
                // Fallback to client's default currency if no primary wallet found
                $baseCurrency = DB::table('clients')
                    ->join('currencies', 'clients.default_currency_id', '=', 'currencies.id')
                    ->where('clients.id', $clientId)
                    ->value('currencies.code');
            }

            if (!$baseCurrency) {
                throw new Exception('No base currency found for client ID: ' . $clientId);
            }

            $baseCurrency = strtoupper(trim($baseCurrency));

            if (!$this->validateCurrency($baseCurrency)) {
                throw new Exception('Invalid base currency format: ' . $baseCurrency);
            }

            Log::debug('Base currency retrieved for client', [
                'client_id' => $clientId,
                'base_currency' => $baseCurrency
            ]);

            return $baseCurrency;

        } catch (Exception $e) {
            Log::error('Failed to get base currency for client', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            throw new Exception('Unable to determine base currency for client: ' . $e->getMessage());
        }
    }

    /**
     * Convert amount from one currency to another with commission calculation.
     *
     * @param float $amount Amount to convert
     * @param string $fromCurrency Source currency (3-letter ISO code)
     * @param string $toCurrency Target currency (3-letter ISO code)
     * @param Carbon $expenseDate Date of the expense for rate lookup
     * @return array Conversion result with converted amount, rate, and metadata
     * 
     * @throws InvalidArgumentException If parameters are invalid
     * @throws Exception If conversion fails
     */
    public function convertAmount(float $amount, string $fromCurrency, string $toCurrency, Carbon $expenseDate): array
    {
        // Validate input parameters
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must be non-negative.');
        }

        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        if (!$this->validateCurrency($fromCurrency)) {
            throw new InvalidArgumentException('Invalid source currency format: ' . $fromCurrency);
        }

        if (!$this->validateCurrency($toCurrency)) {
            throw new InvalidArgumentException('Invalid target currency format: ' . $toCurrency);
        }

        if (!$expenseDate instanceof Carbon) {
            throw new InvalidArgumentException('Expense date must be a Carbon instance.');
        }

        // Same currency conversion
        if ($fromCurrency === $toCurrency) {
            return [
                'converted_amount' => $amount,
                'fx_rate' => 1.0,
                'commission_rate' => 0.0,
                'base_rate' => 1.0,
                'conversion_date' => $expenseDate->toDateString(),
                'rate_date' => $expenseDate->toDateString(),
                'source' => 'same_currency'
            ];
        }

        try {
            // Get FX rate with 30-day lookback
            $rateData = $this->getFXRateWithLookback($fromCurrency, $toCurrency, $expenseDate);

            if (!$rateData) {
                throw new Exception('No FX rate available for currency pair within 30-day lookback period.');
            }

            // Apply commission to the rate
            $commissionPercent = $this->getCommissionPercent($fromCurrency, $toCurrency);
            $adjustedRate = $this->applyCommission($rateData['rate'], $commissionPercent);

            // Calculate converted amount
            $convertedAmount = $amount * $adjustedRate;

            $result = [
                'converted_amount' => round($convertedAmount, 4),
                'fx_rate' => $adjustedRate,
                'commission_rate' => $commissionPercent,
                'base_rate' => $rateData['rate'],
                'conversion_date' => now()->toDateString(),
                'rate_date' => $rateData['rate_date'],
                'source' => 'fx_service',