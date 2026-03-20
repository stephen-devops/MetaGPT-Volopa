<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * FXConversionService
 * 
 * Service for currency conversion with commission calculation using Volopa's existing FX infrastructure.
 * Provides FX rate lookup, conversion calculations, and client-specific commission handling.
 * Integrates with platform's existing /wallet-ccy-value endpoint and FX services.
 * 
 * @package App\Services
 */
class FXConversionService
{
    /**
     * Maximum lookback days for FX rate retrieval.
     */
    const MAX_LOOKBACK_DAYS = 30;

    /**
     * Cache TTL for FX rates in seconds (1 hour).
     */
    const FX_RATE_CACHE_TTL = 3600;

    /**
     * Default commission rate as decimal (e.g., 0.02 = 2%).
     */
    const DEFAULT_COMMISSION_RATE = 0.00;

    /**
     * Base URL for Volopa FX API endpoints.
     *
     * @var string
     */
    private string $fxApiBaseUrl;

    /**
     * API timeout in seconds.
     *
     * @var int
     */
    private int $apiTimeout;

    /**
     * Constructor.
     *
     * @param string $fxApiBaseUrl Base URL for FX API endpoints
     * @param int $apiTimeout API timeout in seconds
     */
    public function __construct(
        string $fxApiBaseUrl = '',
        int $apiTimeout = 30
    ) {
        $this->fxApiBaseUrl = $fxApiBaseUrl ?: config('volopa.fx_api_base_url', 'https://api.volopa.com');
        $this->apiTimeout = $apiTimeout;
    }

    /**
     * Get the base currency information for a client's wallet.
     * 
     * Integrates with Volopa's existing /wallet-ccy-value endpoint to retrieve
     * the client's base currency configuration and commission settings.
     *
     * @param int $clientId Client ID for wallet lookup
     * @return array Array containing currency info and commission settings
     * @throws \Exception When API call fails or client not found
     */
    public function getWalletBaseCurrency(int $clientId): array
    {
        $cacheKey = "wallet_base_currency_{$clientId}";
        
        return Cache::remember($cacheKey, self::FX_RATE_CACHE_TTL, function () use ($clientId) {
            try {
                $response = Http::timeout($this->apiTimeout)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->get("{$this->fxApiBaseUrl}/wallet-ccy-value", [
                        'client_id' => $clientId,
                    ]);

                if (!$response->successful()) {
                    Log::error('Failed to retrieve wallet base currency', [
                        'client_id' => $clientId,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                    
                    throw new Exception("Failed to retrieve wallet base currency for client {$clientId}");
                }

                $data = $response->json();
                
                return [
                    'base_currency' => $data['base_currency'] ?? 'USD',
                    'commission_rate' => (float) ($data['fx_commission_rate'] ?? self::DEFAULT_COMMISSION_RATE),
                    'client_id' => $clientId,
                    'retrieved_at' => now()->toISOString(),
                ];

            } catch (Exception $e) {
                Log::error('Error retrieving wallet base currency', [
                    'client_id' => $clientId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Return fallback values for resilience
                return [
                    'base_currency' => 'USD',
                    'commission_rate' => self::DEFAULT_COMMISSION_RATE,
                    'client_id' => $clientId,
                    'retrieved_at' => now()->toISOString(),
                    'fallback' => true,
                ];
            }
        });
    }

    /**
     * Get FX rate for currency conversion on a specific date.
     * 
     * Retrieves historical FX rates with maximum 30-day lookback constraint.
     * Uses caching to optimize performance for repeated rate requests.
     *
     * @param string $fromCurrency 3-letter ISO source currency code
     * @param string $toCurrency 3-letter ISO target currency code
     * @param Carbon $date Date for historical rate lookup
     * @return float|null FX rate or null if not available
     * @throws \Exception When date is beyond lookback limit
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        // Validate currencies are 3-letter ISO codes
        if (!preg_match('/^[A-Z]{3}$/', $fromCurrency) || !preg_match('/^[A-Z]{3}$/', $toCurrency)) {
            throw new Exception('Currency codes must be 3-letter ISO format');
        }

        // If same currency, rate is 1.0
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        // Validate date is within lookback limit
        $maxLookbackDate = now()->subDays(self::MAX_LOOKBACK_DAYS);
        if ($date < $maxLookbackDate) {
            throw new Exception("FX rate lookup limited to maximum {self::MAX_LOOKBACK_DAYS} days lookback from expense date");
        }

        $dateString = $date->format('Y-m-d');
        $cacheKey = "fx_rate_{$fromCurrency}_{$toCurrency}_{$dateString}";
        
        return Cache::remember($cacheKey, self::FX_RATE_CACHE_TTL, function () use ($fromCurrency, $toCurrency, $dateString) {
            try {
                $response = Http::timeout($this->apiTimeout)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->get("{$this->fxApiBaseUrl}/fx-rates", [
                        'from' => $fromCurrency,
                        'to' => $toCurrency,
                        'date' => $dateString,
                    ]);

                if (!$response->successful()) {
                    Log::warning('Failed to retrieve FX rate', [
                        'from_currency' => $fromCurrency,
                        'to_currency' => $toCurrency,
                        'date' => $dateString,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                    
                    return null;
                }

                $data = $response->json();
                $rate = (float) ($data['rate'] ?? 0.0);
                
                if ($rate <= 0) {
                    Log::warning('Invalid FX rate received', [
                        'from_currency' => $fromCurrency,
                        'to_currency' => $toCurrency,
                        'date' => $dateString,
                        'rate' => $rate,
                    ]);
                    return null;
                }

                Log::info('FX rate retrieved successfully', [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'date' => $dateString,
                    'rate' => $rate,
                ]);

                return $rate;

            } catch (Exception $e) {
                Log::error('Error retrieving FX rate', [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'date' => $dateString,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                return null;
            }
        });
    }

    /**
     * Calculate converted amount using FX rate and commission.
     * 
     * Applies the commission formula: AdjustedRate = BaseRate × (1 - Commission%).
     * Returns the converted amount in the target currency.
     *
     * @param float $amount Original amount to convert
     * @param float $fxRate Base FX rate from API
     * @param float $commissionRate Commission rate as decimal (e.g., 0.02 for 2%)
     * @return float Converted amount with commission applied
     * @throws \Exception When invalid parameters provided
     */
    public function calculateConvertedAmount(float $amount, float $fxRate, float $commissionRate): float
    {
        // Validate input parameters
        if ($amount < 0) {
            throw new Exception('Amount must be non-negative');
        }
        
        if ($fxRate <= 0) {
            throw new Exception('FX rate must be positive');
        }
        
        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new Exception('Commission rate must be between 0 and 1');
        }

        // Apply commission to FX rate: AdjustedRate = BaseRate × (1 - Commission%)
        $adjustedRate = $fxRate * (1 - $commissionRate);
        
        // Calculate converted amount
        $convertedAmount = $amount * $adjustedRate;
        
        Log::debug('FX conversion calculation', [
            'original_amount' => $amount,
            'base_fx_rate' => $fxRate,
            'commission_rate' => $commissionRate,
            'adjusted_rate' => $adjustedRate,
            'converted_amount' => $convertedAmount,
        ]);
        
        return round($convertedAmount, 2);
    }

    /**
     * Apply commission to an FX rate.
     * 
     * Helper method to apply commission using the formula:
     * AdjustedRate = BaseRate × (1 - Commission%).
     *
     * @param float $baseRate Original FX rate
     * @param float $commissionRate Commission rate as decimal (e.g., 0.02 for 2%)
     * @return float Adjusted FX rate with commission applied
     * @throws \Exception When invalid parameters provided
     */
    public function applyCommission(float $baseRate, float $commissionRate): float
    {
        if ($baseRate <= 0) {
            throw new Exception('Base rate must be positive');
        }
        
        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new Exception('Commission rate must be between 0 and 1');
        }

        $adjustedRate = $baseRate * (1 - $commissionRate);
        
        Log::debug('Commission applied to FX rate', [
            'base_rate' => $baseRate,
            'commission_rate' => $commissionRate,
            'adjusted_rate' => $adjustedRate,
        ]);
        
        return round($adjustedRate, 6); // Higher precision for rates
    }

    /**
     * Perform complete FX conversion with client context.
     * 
     * Comprehensive method that handles the full FX conversion flow:
     * 1. Get client's base currency and commission settings
     * 2. Retrieve FX rate for the specified date
     * 3. Calculate converted amount with commission applied
     *
     * @param int $clientId Client ID for currency and commission settings
     * @param float $amount Amount to convert
     * @param string $fromCurrency Source currency (3-letter ISO)
     * @param Carbon $expenseDate Date for FX rate lookup
     * @return array Conversion result with all details
     * @throws \Exception When conversion fails
     */
    public function convertExpenseAmount(int $clientId, float $amount, string $fromCurrency, Carbon $expenseDate): array
    {
        // Get client's wallet base currency and commission settings
        $walletInfo = $this->getWalletBaseCurrency($clientId);
        $baseCurrency = $walletInfo['base_currency'];
        $commissionRate = $walletInfo['commission_rate'];

        // If already in base currency, no conversion needed
        if ($fromCurrency === $baseCurrency) {
            return [
                'original_amount' => $amount,
                'original_currency' => $fromCurrency,
                'converted_amount' => $amount,
                'base_currency' => $baseCurrency,
                'fx_rate' => 1.0,
                'adjusted_fx_rate' => 1.0,
                'commission_rate' => $commissionRate,
                'conversion_date' => $expenseDate->toDateString(),
                'conversion_needed' => false,
            ];
        }

        // Get FX rate for conversion
        $fxRate = $this->getFXRate($fromCurrency, $baseCurrency, $expenseDate);
        
        if (is_null($fxRate)) {
            throw new Exception("Unable to retrieve FX rate for {$fromCurrency} to {$baseCurrency} on {$expenseDate->toDateString()}");
        }

        // Calculate converted amount with commission
        $adjustedRate = $this->applyCommission($fxRate, $commissionRate);
        $convertedAmount = $this->calculateConvertedAmount($amount, $fxRate, $commissionRate);

        return [
            'original_amount' => $amount,
            'original_currency' => $fromCurrency,
            'converted_amount' => $convertedAmount,
            'base_currency' => $baseCurrency,
            'fx_rate' => $fxRate,
            'adjusted_fx_rate' => $adjustedRate,
            'commission_rate' => $commissionRate,
            'conversion_date' => $expenseDate->toDateString(),
            'conversion_needed' => true,
        ];
    }

    /**
     * Batch convert multiple amounts using the same FX parameters.
     * 
     * Optimizes multiple conversions by reusing FX rate and client settings.
     * Useful for CSV batch processing scenarios.
     *
     * @param int $clientId Client ID for currency and commission settings
     * @param array $amounts Array of [amount, currency, date] arrays to convert
     * @return array Array of conversion results
     */
    public function batchConvertAmounts(int $clientId, array $amounts): array
    {
        $results = [];
        $walletInfo = $this->getWalletBaseCurrency($clientId);
        $baseCurrency = $walletInfo['base_currency'];
        $commissionRate = $walletInfo['commission_rate'];

        foreach ($amounts as $index => $amountData) {
            if (!is_array($amountData) || count($amountData) < 3) {
                $results[$index] = [
                    'error' => 'Invalid amount data format. Expected [amount, currency, date]',
                    'index' => $index,
                ];
                continue;
            }

            [$amount, $currency, $date] = $amountData;

            try {
                $expenseDate = is_string($date) ? Carbon::parse($date) : $date;
                $result = $this->convertExpenseAmount($clientId, $amount, $currency, $expenseDate);
                $result['batch_index'] = $index;
                $results[$index] = $result;
                
            } catch (Exception $e) {
                $results[$index] = [
                    'error' => $e->getMessage(),
                    'index' => $index,
                    'original_amount' => $amount,
                    'original_currency' => $currency,
                    'date' => $date,
                ];
                
                Log::error('Batch FX conversion failed', [
                    'client_id' => $clientId,
                    'index' => $index,
                    'amount' => $amount,
                    'currency' => $currency,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Validate currency code format.
     *
     * @param string $currency Currency code to validate
     * @return bool True if valid 3-letter ISO format
     */
    public function isValidCurrencyCode(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    /**
     * Get supported currencies from the FX API.
     * 
     * Retrieves the list of supported currencies for FX conversion.
     * Results are cached to reduce API calls.
     *
     * @return array Array of supported currency codes
     */
    public function getSupportedCurrencies(): array
    {
        $cacheKey = 'supported_currencies';
        
        return Cache::remember($cacheKey, 24 * 3600, function () { // Cache for 24 hours
            try {
                $response = Http::timeout($this->apiTimeout)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->get("{$this->fxApiBaseUrl}/fx-currencies");

                if (!$response->successful()) {
                    Log::warning('Failed to retrieve supported currencies', [
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                    
                    // Return common currencies as fallback
                    return ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD'];
                }

                $data = $response->json();
                return $data['currencies'] ?? ['USD', 'EUR', 'GBP'];

            } catch (Exception $e) {
                Log::error('Error retrieving supported currencies', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Return common currencies as fallback
                return ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD'];
            }
        });
    }

    /**
     * Clear FX rate cache for specific currency pair and date.
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param Carbon $date Date for cache clearing
     * @return bool True if cache was cleared
     */
    public function clearFXRateCache(string $fromCurrency, string $toCurrency, Carbon $date): bool
    {
        $dateString = $date->format('Y-m-d');
        $cacheKey = "fx_rate_{$fromCurrency}_{$toCurrency}_{$dateString}";
        
        return Cache::forget($cacheKey);
    }

    /**
     * Clear all FX-related cache entries.
     *
     * @return bool True if cache was cleared
     */
    public function clearAllFXCache(): bool
    {
        $keys = [
            'supported_currencies',
        ];
        
        $cleared = true;
        foreach ($keys as $key) {
            $cleared = $cleared && Cache::forget($key);
        }
        
        // Clear wallet base currency cache (pattern-based)
        // Note: This is a simplified approach. In production, you might want to use cache tags
        // or maintain a list of cached client IDs for more efficient cache management.
        
        return $cleared;
    }

    /**
     * Get FX conversion statistics for reporting.
     *
     * @param int $clientId Client ID for statistics
     * @param Carbon $startDate Start date for statistics
     * @param Carbon $endDate End date for statistics
     * @return array Conversion statistics
     */
    public function getFXConversionStats(int $clientId, Carbon $startDate, Carbon $endDate): array
    {
        $walletInfo = $this->getWalletBaseCurrency($clientId);
        
        return [
            'client_id' => $clientId,
            'base_currency' => $walletInfo['base_currency'],
            'commission_rate' => $walletInfo['commission_rate'],
            'period_start' => $startDate->toDateString(),
            'period_end' => $endDate->toDateString(),
            'max_lookback_days' => self::MAX_LOOKBACK_DAYS,
            'cache_ttl_seconds' => self::FX_RATE_CACHE_TTL,
            'supported_currencies_count' => count($this->getSupportedCurrencies()),
        ];
    }
}