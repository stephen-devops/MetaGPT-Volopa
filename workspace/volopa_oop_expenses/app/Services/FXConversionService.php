## Code: app/Services/FXConversionService.php

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;
use Exception;

/**
 * FXConversionService
 * 
 * Service for foreign exchange rate conversion with caching and fallback support.
 * Integrates with external FX API and applies client-specific commissions.
 * Provides rate lookback functionality and handles conversion failures gracefully.
 */
class FXConversionService
{
    /**
     * Default cache TTL in seconds (1 hour).
     */
    private const DEFAULT_CACHE_TTL = 3600;

    /**
     * Default max lookback days for FX rates.
     */
    private const DEFAULT_LOOKBACK_DAYS = 30;

    /**
     * Default API timeout in seconds.
     */
    private const DEFAULT_API_TIMEOUT = 10;

    /**
     * Default commission rate (0.5%).
     */
    private const DEFAULT_COMMISSION_RATE = 0.005;

    /**
     * Cache key prefix for FX rates.
     */
    private const CACHE_PREFIX = 'fx_rates';

    /**
     * Cache key prefix for client commissions.
     */
    private const CLIENT_COMMISSION_CACHE_PREFIX = 'client_fx_commission';

    /**
     * FX conversion result structure.
     */
    public class ConversionResult
    {
        public float $convertedAmount;
        public float $rate;
        public string $fromCurrency;
        public string $toCurrency;
        public Carbon $rateDate;
        public bool $isEstimate;
        public ?string $error;

        public function __construct(
            float $convertedAmount = 0.0,
            float $rate = 0.0,
            string $fromCurrency = '',
            string $toCurrency = '',
            ?Carbon $rateDate = null,
            bool $isEstimate = false,
            ?string $error = null
        ) {
            $this->convertedAmount = $convertedAmount;
            $this->rate = $rate;
            $this->fromCurrency = $fromCurrency;
            $this->toCurrency = $toCurrency;
            $this->rateDate = $rateDate ?? now();
            $this->isEstimate = $isEstimate;
            $this->error = $error;
        }

        /**
         * Check if the conversion was successful.
         *
         * @return bool
         */
        public function isSuccessful(): bool
        {
            return is_null($this->error) && $this->rate > 0;
        }

        /**
         * Convert to array representation.
         *
         * @return array<string, mixed>
         */
        public function toArray(): array
        {
            return [
                'converted_amount' => $this->convertedAmount,
                'rate' => $this->rate,
                'from_currency' => $this->fromCurrency,
                'to_currency' => $this->toCurrency,
                'rate_date' => $this->rateDate->toDateString(),
                'is_estimate' => $this->isEstimate,
                'error' => $this->error,
                'successful' => $this->isSuccessful(),
            ];
        }
    }

    /**
     * The Cache instance.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * FX API configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Create a new FX conversion service instance.
     */
    public function __construct()
    {
        $this->cache = Cache::store();
        $this->config = Config::get('pocket_expense.fx_conversion', []);
    }

    /**
     * Convert amount from one currency to another with date-specific rates.
     *
     * @param float $amount
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon|null $date
     * @param int|null $clientId
     * @return ConversionResult
     */
    public function convertAmount(
        float $amount, 
        string $fromCurrency, 
        string $toCurrency, 
        ?Carbon $date = null, 
        ?int $clientId = null
    ): ConversionResult {
        // Validate inputs
        if ($amount <= 0) {
            return new ConversionResult(
                error: 'Amount must be greater than zero'
            );
        }

        if (empty($fromCurrency) || empty($toCurrency)) {
            return new ConversionResult(
                error: 'Both from and to currencies are required'
            );
        }

        // Normalize currency codes
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        $date = $date ?? now();

        // Same currency conversion
        if ($fromCurrency === $toCurrency) {
            return new ConversionResult(
                convertedAmount: $amount,
                rate: 1.0,
                fromCurrency: $fromCurrency,
                toCurrency: $toCurrency,
                rateDate: $date,
                isEstimate: false
            );
        }

        try {
            // Get FX rate with lookback
            $lookbackDays = $this->config['max_lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS;
            $rate = $this->getRateWithLookback($fromCurrency, $toCurrency, $date, $lookbackDays);

            if (!$rate) {
                return new ConversionResult(
                    error: 'No FX rate available for the specified currency pair and date range'
                );
            }

            // Apply client commission if provided
            if ($clientId) {
                $rate = $this->applyClientCommission($rate, $clientId);
            }

            // Calculate converted amount
            $convertedAmount = round($amount * $rate, 2);

            return new ConversionResult(
                convertedAmount: $convertedAmount,
                rate: $rate,
                fromCurrency: $fromCurrency,
                toCurrency: $toCurrency,
                rateDate: $date,
                isEstimate: false
            );

        } catch (Exception $e) {
            Log::error('FX conversion failed', [
                'amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->toDateString(),
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);

            return new ConversionResult(
                error: 'FX conversion service temporarily unavailable'
            );
        }
    }

    /**
     * Get exchange rate with lookback functionality.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @param int $lookbackDays
     * @return float|null
     */
    public function getRateWithLookback(
        string $fromCurrency, 
        string $toCurrency, 
        Carbon $date, 
        int $lookbackDays = 30
    ): ?float {
        $currentDate = $date->copy();
        $endDate = $date->copy()->subDays($lookbackDays);

        // Try each day within the lookback period
        while ($currentDate->gte($endDate)) {
            $rate = $this->getRate($fromCurrency, $toCurrency, $currentDate);
            
            if ($rate !== null) {
                return $rate;
            }

            $currentDate->subDay();
        }

        // If no rate found within lookback period, try latest available
        return $this->getLatestRate($fromCurrency, $toCurrency);
    }

    /**
     * Get exchange rate for specific date.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return float|null
     */
    protected function getRate(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        $cacheKey = $this->getRateCacheKey($fromCurrency, $toCurrency, $date);
        
        return $this->cache->remember($cacheKey, $this->getCacheTTL(), function () use ($fromCurrency, $toCurrency, $date) {
            return $this->fetchRateFromAPI($fromCurrency, $toCurrency, $date);
        });
    }

    /**
     * Get latest available exchange rate.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return float|null
     */
    protected function getLatestRate(string $fromCurrency, string $toCurrency): ?float
    {
        $cacheKey = $this->getLatestRateCacheKey($fromCurrency, $toCurrency);
        
        return $this->cache->remember($cacheKey, $this->getCacheTTL(), function () use ($fromCurrency, $toCurrency) {
            return $this->fetchLatestRateFromAPI($fromCurrency, $toCurrency);
        });
    }

    /**
     * Fetch exchange rate from external API for specific date.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return float|null
     */
    protected function fetchRateFromAPI(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $endpoint = $this->getApiEndpoint();
            $timeout = $this->config['timeout_seconds'] ?? self::DEFAULT_API_TIMEOUT;

            $response = Http::timeout($timeout)->get($endpoint, [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $date->toDateString(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['rate']) && is_numeric($data['rate']) && $data['rate'] > 0) {
                    return (float) $data['rate'];
                }
            }

            Log::warning('FX API returned invalid response', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->toDateString(),
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

        } catch (Exception $e) {
            Log::error('FX API request failed', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Fetch latest exchange rate from external API.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return float|null
     */
    protected function fetchLatestRateFromAPI(string $fromCurrency, string $toCurrency): ?float
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $endpoint = $this->getApiEndpoint();
            $timeout = $this->config['timeout_seconds'] ?? self::DEFAULT_API_TIMEOUT;

            $response = Http::timeout($timeout)->get($endpoint, [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'latest' => true,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['rate']) && is_numeric($data['rate']) && $data['rate'] > 0) {
                    return (float) $data['rate'];
                }
            }

            Log::warning('FX API returned invalid latest rate response', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
            ]);

        } catch (Exception $e) {
            Log::error('FX API latest rate request failed', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Apply client-specific commission to exchange rate.
     *
     * @param float $rate
     * @param int $clientId
     * @return float
     */
    public function applyClientCommission(float $rate, int $clientId): float
    {
        $commissionRate = $this->getClientCommissionRate($clientId);
        
        // Apply commission (typically reduces the rate for the client)
        return $rate * (1 - $commissionRate);
    }

    /**
     * Get commission rate for a specific client.
     *
     * @param int $clientId
     * @return float
     */
    protected function getClientCommissionRate(int $clientId): float
    {
        $cacheKey = self::CLIENT_COMMISSION_CACHE_PREFIX . ":{$clientId}";
        
        return $this->cache->remember($cacheKey, $this->getCacheTTL(), function () use ($clientId) {
            return $this->fetchClientCommissionRate($clientId);
        });
    }

    /**
     * Fetch commission rate for client from database or config.
     *
     * @param int $clientId
     * @return float
     */
    protected function fetchClientCommissionRate(int $clientId): float
    {
        // This would typically query a client_fx_commission table or similar
        // For now, return default commission rate
        // TODO: Implement database lookup for client-specific rates
        
        return self::DEFAULT_COMMISSION_RATE;
    }

    /**
     * Check if FX conversion is enabled.
     *
     * @return bool
     */
    protected function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    /**
     * Get the FX API endpoint.
     *
     * @return string
     */
    protected function getApiEndpoint(): string
    {
        $baseUrl = Config::get('app.url', 'http://localhost');
        $endpoint = $this->config['api_endpoint'] ?? '/api/wallet-ccy-value';
        
        return rtrim($baseUrl, '/') . $endpoint;
    }

    /**
     * Get cache TTL in seconds.
     *
     * @return int
     */
    protected function getCacheTTL(): int
    {
        return $this->config['cache_duration'] ?? self::DEFAULT_CACHE_TTL;
    }

    /**
     * Generate cache key for exchange rate.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return string
     */
    protected function getRateCacheKey(string $fromCurrency, string $toCurrency, Carbon $date): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            self::CACHE_PREFIX,
            $fromCurrency,
            $toCurrency,
            $date->toDateString()
        );
    }

    /**
     * Generate cache key for latest exchange rate.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return string
     */
    protected function getLatestRateCacheKey(string $fromCurrency, string $toCurrency): string
    {
        return sprintf(
            '%s:latest:%s:%s',
            self::CACHE_PREFIX,
            $fromCurrency,
            $toCurrency
        );
    }

    /**
     * Clear cache for specific currency pair.
     *
     * @param