## Code: app/Services/FxConversionService.php

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FxConversionService
{
    /**
     * Default cache TTL for FX rates (24 hours).
     */
    private const CACHE_TTL = 86400;

    /**
     * Maximum age for FX rates in days (30 days lookback).
     */
    private const MAX_RATE_AGE_DAYS = 30;

    /**
     * HTTP timeout for external API calls in seconds.
     */
    private const HTTP_TIMEOUT = 10;

    /**
     * Maximum retry attempts for API calls.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Default base currency.
     */
    private const DEFAULT_BASE_CURRENCY = 'USD';

    /**
     * External FX API configuration.
     */
    private array $apiConfig;

    /**
     * Cache key prefix for FX rates.
     */
    private const CACHE_PREFIX = 'fx_rates';

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->apiConfig = [
            'base_url' => config('services.fx_api.base_url', 'https://api.exchangerate-api.com/v4'),
            'api_key' => config('services.fx_api.key', ''),
            'timeout' => self::HTTP_TIMEOUT,
            'retry_attempts' => self::MAX_RETRY_ATTEMPTS,
        ];
    }

    /**
     * Convert amount from one currency to another for a specific date.
     */
    public function convertAmount(float $amount, string $fromCurrency, string $toCurrency, string $date): array
    {
        try {
            // Validate inputs
            if ($amount <= 0) {
                return $this->errorResponse('Amount must be greater than zero');
            }

            if (empty($fromCurrency) || empty($toCurrency)) {
                return $this->errorResponse('From and to currencies are required');
            }

            if (!$this->isValidDate($date)) {
                return $this->errorResponse('Invalid date format. Use YYYY-MM-DD');
            }

            $fromCurrency = strtoupper(trim($fromCurrency));
            $toCurrency = strtoupper(trim($toCurrency));

            // If same currency, return original amount
            if ($fromCurrency === $toCurrency) {
                return $this->successResponse([
                    'original_amount' => $amount,
                    'converted_amount' => $amount,
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'exchange_rate' => 1.0,
                    'conversion_date' => $date,
                    'rate_date' => $date,
                    'source' => 'direct',
                ]);
            }

            // Get exchange rate for the date
            $rateData = $this->getRatesForDate($date, $fromCurrency);

            if (!$rateData['success']) {
                return $rateData;
            }

            $rates = $rateData['data']['rates'];
            
            if (!isset($rates[$toCurrency])) {
                return $this->errorResponse("Exchange rate not available for {$toCurrency}");
            }

            $exchangeRate = $rates[$toCurrency];
            $convertedAmount = round($amount * $exchangeRate, 2);

            return $this->successResponse([
                'original_amount' => $amount,
                'converted_amount' => $convertedAmount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'exchange_rate' => $exchangeRate,
                'conversion_date' => $date,
                'rate_date' => $rateData['data']['rate_date'],
                'source' => $rateData['data']['source'],
            ]);

        } catch (\Exception $e) {
            Log::error('FX conversion error', [
                'amount' => $amount,
                'from_currency' => $fromCurrency ?? '',
                'to_currency' => $toCurrency ?? '',
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Currency conversion failed: ' . $e->getMessage());
        }
    }

    /**
     * Get exchange rates for a specific date and base currency.
     */
    public function getRatesForDate(string $date, string $baseCurrency = self::DEFAULT_BASE_CURRENCY): array
    {
        try {
            if (!$this->isValidDate($date)) {
                return $this->errorResponse('Invalid date format. Use YYYY-MM-DD');
            }

            $baseCurrency = strtoupper(trim($baseCurrency));
            $requestDate = Carbon::createFromFormat('Y-m-d', $date);
            $now = Carbon::now();

            // Check if date is too old
            if ($requestDate->diffInDays($now) > self::MAX_RATE_AGE_DAYS) {
                return $this->errorResponse('Exchange rates not available for dates older than 30 days');
            }

            // Check if date is in the future
            if ($requestDate->isAfter($now)) {
                return $this->errorResponse('Exchange rates not available for future dates');
            }

            // Try to get cached rates first
            $cachedRates = $this->getCachedRates($date, $baseCurrency);
            if ($cachedRates['success']) {
                return $cachedRates;
            }

            // Fetch from API
            $apiRates = $this->fetchRatesFromApi($date, $baseCurrency);
            if ($apiRates['success']) {
                // Cache successful API response
                $this->cacheRates($date, $baseCurrency, $apiRates['data']);
                return $apiRates;
            }

            return $apiRates;

        } catch (\Exception $e) {
            Log::error('Error getting FX rates', [
                'date' => $date,
                'base_currency' => $baseCurrency ?? '',
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to retrieve exchange rates: ' . $e->getMessage());
        }
    }

    /**
     * Get cached exchange rates for a specific date and base currency.
     */
    public function getCachedRates(string $date, string $baseCurrency): array
    {
        try {
            $cacheKey = $this->buildCacheKey($date, $baseCurrency);
            $cachedData = Cache::get($cacheKey);

            if ($cachedData) {
                Log::info('FX rates retrieved from cache', [
                    'date' => $date,
                    'base_currency' => $baseCurrency,
                    'cache_key' => $cacheKey,
                ]);

                return $this->successResponse([
                    'rates' => $cachedData['rates'],
                    'base_currency' => $baseCurrency,
                    'rate_date' => $cachedData['rate_date'],
                    'source' => 'cache',
                    'cached_at' => $cachedData['cached_at'],
                ]);
            }

            return $this->errorResponse('No cached rates available');

        } catch (\Exception $e) {
            Log::error('Error retrieving cached FX rates', [
                'date' => $date,
                'base_currency' => $baseCurrency,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to retrieve cached rates: ' . $e->getMessage());
        }
    }

    /**
     * Fetch exchange rates from external API.
     */
    public function fetchRatesFromApi(string $date, string $baseCurrency): array
    {
        try {
            $requestDate = Carbon::createFromFormat('Y-m-d', $date);
            $isHistorical = $requestDate->isYesterday() || $requestDate->isPast();
            
            // Build API URL
            if ($isHistorical) {
                $url = $this->buildHistoricalApiUrl($date, $baseCurrency);
            } else {
                $url = $this->buildLatestApiUrl($baseCurrency);
            }

            // Make HTTP request with retry logic
            $response = $this->makeApiRequest($url);

            if (!$response['success']) {
                return $response;
            }

            $data = $response['data'];

            // Validate API response structure
            if (!isset($data['rates']) || !is_array($data['rates'])) {
                return $this->errorResponse('Invalid API response format');
            }

            $actualRateDate = $data['date'] ?? $date;

            return $this->successResponse([
                'rates' => $data['rates'],
                'base_currency' => $baseCurrency,
                'rate_date' => $actualRateDate,
                'source' => 'api',
                'api_provider' => $this->getApiProvider(),
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching FX rates from API', [
                'date' => $date,
                'base_currency' => $baseCurrency,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to fetch rates from API: ' . $e->getMessage());
        }
    }

    /**
     * Make HTTP request to external API with retry logic.
     */
    private function makeApiRequest(string $url): array
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->apiConfig['retry_attempts']) {
            try {
                $attempts++;

                Log::info('Making FX API request', [
                    'url' => $url,
                    'attempt' => $attempts,
                    'max_attempts' => $this->apiConfig['retry_attempts'],
                ]);

                $response = Http::timeout($this->apiConfig['timeout'])
                    ->get($url);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (isset($data['error'])) {
                        return $this->errorResponse('API Error: ' . $data['error']);
                    }

                    return $this->successResponse($data);
                }

                $errorMessage = "HTTP {$response->status()}: " . $response->body();
                Log::warning('FX API request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'attempt' => $attempts,
                ]);

                if ($attempts >= $this->apiConfig['retry_attempts']) {
                    return $this->errorResponse($errorMessage);
                }

                // Wait before retry (exponential backoff)
                sleep(pow(2, $attempts - 1));

            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning('FX API request exception', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempts,
                ]);

                if ($attempts >= $this->apiConfig['retry_attempts']) {
                    return $this->errorResponse('API request failed after ' . $attempts . ' attempts: ' . $e->getMessage());
                }

                sleep(pow(2, $attempts - 1));
            }
        }

        return $this->errorResponse('API request failed: ' . ($lastException ? $lastException->getMessage() : 'Unknown error'));
    }

    /**
     * Build cache key for FX rates.
     */
    private function buildCacheKey(string $date, string $baseCurrency): string
    {
        return self::CACHE_PREFIX . ':' . $date . ':' . strtoupper($baseCurrency);
    }

    /**
     * Cache exchange rates.
     */
    private function cacheRates(string $date, string $baseCurrency, array $rateData): void
    {
        try {
            $cacheKey = $this->buildCacheKey($date, $baseCurrency);
            
            $cacheData = [
                'rates' => $rateData['rates'],
                'rate_date' => $rateData['rate_date'],
                'cached_at' => now()->toISOString(),
                'base_currency' => $baseCurrency,
            ];

            Cache::put($cacheKey, $cacheData, self::CACHE_TTL);

            Log::info('FX rates cached successfully', [
                'date' => $date,
                'base_currency' => $baseCurrency,
                'cache_key' => $cacheKey,
                'ttl' => self::CACHE_TTL,
            ]);

        } catch (\Exception $e) {
            Log::error('Error caching FX rates', [
                'date' => $date,
                'base_currency' => $baseCurrency,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build API URL for historical rates.
     */
    private function buildHistoricalApiUrl(string $date, string $baseCurrency): string
    {
        $baseUrl = rtrim($this->apiConfig['base_url'], '/');
        $url = "{$baseUrl}/historical/{$date}";
        
        if (!empty($this->apiConfig['api_key'])) {
            $url .= "?access_key=" . $this->apiConfig['api_key'] . "&base={$baseCurrency}";
        } else {
            $url .= "?base={$baseCurrency}";
        }

        return $url;
    }

    /**
     * Build API URL for latest rates.
     */
    private function buildLatestApiUrl(string $baseCurrency): string
    {
        $baseUrl = rtrim($this->apiConfig['base_url'], '/');
        $url = "{$baseUrl}/latest/{$baseCurrency}";
        
        if (!empty($this->apiConfig['api_key'])) {
            $url .= "?access_key=" . $this->apiConfig['api_key'];
        }

        return $url;
    }

    /**
     * Get API provider name.
     */
    private function getApiProvider(): string
    {
        return config('services.fx_api.provider', 'exchangerate-api');
    }

    /**
     * Validate date format.
     */
    private function isValidDate(string $date): bool
    {
        try {
            $parsedDate = Carbon::createFromFormat('Y-m-d', $date);
            return $parsedDate && $parsedDate->format('Y-m-d') === $date;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create success response.
     */
    private function successResponse(array $data): array
    {
        return [
            'success' => true,
            'data' => $data,
            'message' => 'Operation completed successfully',
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Create error response.
     */
    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'data' => null,
            'message' => $message,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Clear cached rates for a specific date and currency.
     */
    public function clearCachedRates(string $date, string $baseCurrency): bool
    {
        try {
            $cacheKey = $this->buildCacheKey($date, $baseCurrency);
            $result = Cache::forget($cacheKey);

            Log::info('FX rates cache cleared', [
                'date' => $date,
                'base_currency' => $baseCurrency,
                'cache_key' => $cacheKey,
                'result' => $result,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Error clearing