<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * FX Conversion Service
 * 
 * Handles foreign exchange rate lookups and currency conversions for pocket expenses.
 * Integrates with existing Volopa platform FX infrastructure and provides 30-day
 * lookback functionality as per system constraints.
 */
class FXConversionService
{
    /**
     * Maximum number of days to look back for FX rates.
     * As per system constraints: "FX rate lookup has maximum 30-day lookback from expense date"
     */
    private const MAX_LOOKBACK_DAYS = 30;

    /**
     * Default wallet base currency when client configuration is not available.
     */
    private const DEFAULT_BASE_CURRENCY = 'USD';

    /**
     * Cache TTL for FX rates in seconds (24 hours).
     */
    private const FX_RATE_CACHE_TTL = 86400;

    /**
     * Cache TTL for wallet base currency in seconds (1 hour).
     */
    private const WALLET_CURRENCY_CACHE_TTL = 3600;

    /**
     * Commission percentage default value (as decimal).
     * Formula: AdjustedRate = BaseRate x (1 - Comm%)
     */
    private const DEFAULT_COMMISSION_PERCENTAGE = 0.025; // 2.5%

    /**
     * Convert amount from expense currency to wallet base currency.
     *
     * @param string $currency The currency code of the expense
     * @param float $amount The amount to convert
     * @param string|\Carbon\Carbon $date The expense date for rate lookup
     * @param int $clientId The client ID for wallet currency lookup
     * @return array Array containing converted amount and rate information
     * @throws Exception When conversion fails
     */
    public function convertAmount(string $currency, float $amount, $date, int $clientId): array
    {
        try {
            // Normalize date input
            $expenseDate = $this->normalizeDate($date);
            
            // Get wallet base currency for the client
            $walletBaseCurrency = $this->getWalletBaseCurrency($clientId);
            
            // If same currency, no conversion needed
            if (strtoupper($currency) === strtoupper($walletBaseCurrency)) {
                return [
                    'original_amount' => $amount,
                    'original_currency' => strtoupper($currency),
                    'converted_amount' => $amount,
                    'wallet_currency' => strtoupper($walletBaseCurrency),
                    'fx_rate' => 1.0000,
                    'commission_rate' => 0.0000,
                    'adjusted_rate' => 1.0000,
                    'conversion_date' => $expenseDate->format('Y-m-d'),
                    'rate_source' => 'no_conversion_required',
                    'is_converted' => false,
                ];
            }
            
            // Get FX rate with lookback
            $fxRateData = $this->getFXRateWithLookback(
                strtoupper($currency), 
                strtoupper($walletBaseCurrency), 
                $expenseDate
            );
            
            if ($fxRateData['rate'] === null) {
                return [
                    'original_amount' => $amount,
                    'original_currency' => strtoupper($currency),
                    'converted_amount' => null,
                    'wallet_currency' => strtoupper($walletBaseCurrency),
                    'fx_rate' => null,
                    'commission_rate' => null,
                    'adjusted_rate' => null,
                    'conversion_date' => $expenseDate->format('Y-m-d'),
                    'rate_source' => 'no_fx_available',
                    'is_converted' => false,
                    'error' => 'No FX Available',
                ];
            }
            
            // Calculate converted amount using adjusted rate
            $convertedAmount = $amount * $fxRateData['adjusted_rate'];
            
            return [
                'original_amount' => $amount,
                'original_currency' => strtoupper($currency),
                'converted_amount' => round($convertedAmount, 2),
                'wallet_currency' => strtoupper($walletBaseCurrency),
                'fx_rate' => $fxRateData['rate'],
                'commission_rate' => $fxRateData['commission_rate'],
                'adjusted_rate' => $fxRateData['adjusted_rate'],
                'conversion_date' => $fxRateData['rate_date'],
                'rate_source' => $fxRateData['source'],
                'is_converted' => true,
                'lookback_days' => $fxRateData['lookback_days'],
            ];
            
        } catch (Exception $e) {
            Log::error('FX conversion failed', [
                'currency' => $currency,
                'amount' => $amount,
                'date' => $date,
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'original_amount' => $amount,
                'original_currency' => strtoupper($currency),
                'converted_amount' => null,
                'wallet_currency' => $this->getWalletBaseCurrency($clientId),
                'fx_rate' => null,
                'commission_rate' => null,
                'adjusted_rate' => null,
                'conversion_date' => $this->normalizeDate($date)->format('Y-m-d'),
                'rate_source' => 'conversion_error',
                'is_converted' => false,
                'error' => 'Conversion failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get the wallet base currency for a specific client.
     * 
     * @param int $clientId The client ID
     * @return string The base currency code (defaults to USD)
     */
    public function getWalletBaseCurrency(int $clientId): string
    {
        $cacheKey = "wallet_base_currency_client_{$clientId}";
        
        return Cache::remember($cacheKey, self::WALLET_CURRENCY_CACHE_TTL, function () use ($clientId) {
            try {
                // Integration point with existing Volopa platform
                // This would typically call an existing service or API endpoint
                $response = Http::timeout(10)->get("/api/internal/wallet-ccy-value", [
                    'client_id' => $clientId,
                ]);
                
                if ($response->successful()) {
                    $data = $response->json();
                    return $data['base_currency'] ?? self::DEFAULT_BASE_CURRENCY;
                }
                
                Log::warning('Failed to retrieve wallet base currency', [
                    'client_id' => $clientId,
                    'status' => $response->status(),
                ]);
                
                return self::DEFAULT_BASE_CURRENCY;
                
            } catch (Exception $e) {
                Log::error('Error retrieving wallet base currency', [
                    'client_id' => $clientId,
                    'error' => $e->getMessage(),
                ]);
                
                return self::DEFAULT_BASE_CURRENCY;
            }
        });
    }

    /**
     * Get FX rate between two currencies for a specific date.
     * 
     * @param string $fromCurrency Source currency code
     * @param string $toCurrency Target currency code  
     * @param \Carbon\Carbon $date Rate date
     * @return float|null The exchange rate or null if not found
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        $fxData = $this->getFXRateWithLookback($fromCurrency, $toCurrency, $date);
        return $fxData['rate'];
    }

    /**
     * Get FX rate with 30-day lookback functionality.
     * 
     * @param string $fromCurrency Source currency code
     * @param string $toCurrency Target currency code
     * @param \Carbon\Carbon $date Starting date for lookup
     * @return array Rate data with metadata
     */
    private function getFXRateWithLookback(string $fromCurrency, string $toCurrency, Carbon $date): array
    {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);
        
        // Try to find a rate within the lookback period
        for ($dayOffset = 0; $dayOffset <= self::MAX_LOOKBACK_DAYS; $dayOffset++) {
            $lookupDate = $date->copy()->subDays($dayOffset);
            $cacheKey = "fx_rate_{$fromCurrency}_{$toCurrency}_{$lookupDate->format('Y-m-d')}";
            
            $rateData = Cache::remember($cacheKey, self::FX_RATE_CACHE_TTL, function () use ($fromCurrency, $toCurrency, $lookupDate) {
                return $this->fetchFXRateFromApi($fromCurrency, $toCurrency, $lookupDate);
            });
            
            if ($rateData['rate'] !== null) {
                // Calculate adjusted rate with commission
                $commissionRate = $this->getCommissionRate($fromCurrency, $toCurrency);
                $adjustedRate = $rateData['rate'] * (1 - $commissionRate);
                
                return [
                    'rate' => $rateData['rate'],
                    'commission_rate' => $commissionRate,
                    'adjusted_rate' => $adjustedRate,
                    'rate_date' => $lookupDate->format('Y-m-d'),
                    'source' => $rateData['source'],
                    'lookback_days' => $dayOffset,
                ];
            }
        }
        
        // No rate found within lookback period
        return [
            'rate' => null,
            'commission_rate' => null,
            'adjusted_rate' => null,
            'rate_date' => null,
            'source' => 'not_found',
            'lookback_days' => self::MAX_LOOKBACK_DAYS,
        ];
    }

    /**
     * Fetch FX rate from external API or existing platform service.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param \Carbon\Carbon $date Rate date
     * @return array Rate data
     */
    private function fetchFXRateFromApi(string $fromCurrency, string $toCurrency, Carbon $date): array
    {
        try {
            // Integration with existing Volopa FX service infrastructure
            // This would typically call the existing FX rate service
            $response = Http::timeout(15)->get("/api/internal/fx-rates", [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'rate_date' => $date->format('Y-m-d'),
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['rate']) && is_numeric($data['rate'])) {
                    return [
                        'rate' => (float) $data['rate'],
                        'source' => $data['source'] ?? 'platform_api',
                    ];
                }
            }
            
            // Try fallback external FX service if platform API fails
            return $this->fetchFromExternalFXService($fromCurrency, $toCurrency, $date);
            
        } catch (Exception $e) {
            Log::warning('FX API call failed', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
            ]);
            
            return [
                'rate' => null,
                'source' => 'api_error',
            ];
        }
    }

    /**
     * Fallback to external FX service if platform service is unavailable.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param \Carbon\Carbon $date Rate date
     * @return array Rate data
     */
    private function fetchFromExternalFXService(string $fromCurrency, string $toCurrency, Carbon $date): array
    {
        try {
            // This is a fallback mechanism - in production this would integrate
            // with a reliable external FX data provider (e.g., XE, Fixer.io, etc.)
            // For now, we return null to indicate no rate available
            
            Log::info('Using external FX service fallback', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
            ]);
            
            // In a real implementation, this would make an HTTP call to an external service
            // For now, return null to follow the constraint of returning "No FX Available"
            return [
                'rate' => null,
                'source' => 'external_unavailable',
            ];
            
        } catch (Exception $e) {
            Log::error('External FX service failed', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
            ]);
            
            return [
                'rate' => null,
                'source' => 'external_error',
            ];
        }
    }

    /**
     * Get commission rate for currency pair conversion.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return float Commission rate as decimal (e.g., 0.025 for 2.5%)
     */
    private function getCommissionRate(string $fromCurrency, string $toCurrency): float
    {
        // In a real implementation, this would look up client-specific commission rates
        // or currency-pair specific rates from configuration or database
        
        $cacheKey = "fx_commission_rate_{$fromCurrency}_{$toCurrency}";
        
        return Cache::remember($cacheKey, 3600, function () use ($fromCurrency, $toCurrency) {
            try {
                // This would typically query commission configuration
                // For now, return default commission rate
                return self::DEFAULT_COMMISSION_PERCENTAGE;
                
            } catch (Exception $e) {
                Log::warning('Failed to retrieve commission rate, using default', [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'error' => $e->getMessage(),
                ]);
                
                return self::DEFAULT_COMMISSION_PERCENTAGE;
            }
        });
    }

    /**
     * Normalize date input to Carbon instance.
     * 
     * @param string|\Carbon\Carbon $date Input date
     * @return \Carbon\Carbon Normalized Carbon date
     * @throws Exception If date cannot be parsed
     */
    private function normalizeDate($date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy();
        }
        
        if (is_string($date)) {
            try {
                return Carbon::parse($date);
            } catch (Exception $e) {
                Log::error('Failed to parse date string', [
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);
                throw new Exception("Invalid date format: {$date}");
            }
        }
        
        throw new Exception('Date must be a string or Carbon instance');
    }

    /**
     * Validate currency code format.
     * 
     * @param string $currency Currency code to validate
     * @return bool True if valid format
     */
    private function isValidCurrencyCode(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', strtoupper($currency)) === 1;
    }

    /**
     * Check if expense date is within allowed age constraint (3 years).
     * 
     * @param \Carbon\Carbon $expenseDate The expense date
     * @return bool True if date is within constraint
     */
    public function isDateWithinConstraint(Carbon $expenseDate): bool
    {
        $threeYearsAgo = Carbon::now()->subYears(3);
        return $expenseDate->greaterThanOrEqualTo($threeYearsAgo);
    }

    /**
     * Get supported currency codes from platform configuration.
     * 
     * @return array Array of supported 3-letter currency codes
     */
    public function getSupportedCurrencies(): array
    {
        $cacheKey = 'supported_currencies';
        
        return Cache::remember($cacheKey, 3600, function () {
            try {
                // This would typically query the platform's supported currency list
                // For now, return a default set of major currencies
                return [
                    'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY',
                    'HKD', 'SGD', 'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF',
                    'ILS', 'NZD', 'MXN', 'BRL', 'INR', 'KRW', 'ZAR', 'TRY',
                ];
                
            } catch (Exception $e) {
                Log::error('Failed to retrieve supported currencies', [
                    'error' => $e->getMessage(),
                ]);
                
                // Return minimal default set
                return ['USD', 'EUR', 'GBP'];
            }
        });
    }

    /**
     * Clear FX rate cache for specific currency pair and date.
     * Useful for testing or when rates need to be refreshed.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param \Carbon\Carbon|null $date Specific date (optional)
     * @return void
     */
    public function clearFXRateCache(string $fromCurrency, string $toCurrency, Carbon $date = null): void
    {
        if ($date) {
            $cacheKey = "fx_rate_{$fromCurrency}_{$toCurrency}_{$date->format('Y-m-d')}";
            Cache::forget($cacheKey);
        } else {
            // Clear all rates for this currency pair (requires cache tag support)
            Log::info('Clearing FX rate cache for currency pair', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
            ]);
            
            // In a production environment with cache tagging, this would clear all related entries
            // For now, log the action
        }
    }

    /**
     * Clear wallet base currency cache for specific client.
     * 
     * @param int $clientId Client ID
     * @return void
     */
    public function clearWalletCurrencyCache(int $clientId): void
    {
        $cacheKey = "wallet_base_currency_client_{$clientId}";
        Cache::forget($cacheKey);
    }

    /**
     * Batch convert multiple amounts for efficiency.
     * 
     * @param array $conversions Array of conversion requests
     * @param int $clientId Client ID
     * @return array Array of conversion results
     */
    public function batchConvertAmounts(array $conversions, int $clientId): array
    {
        $results = [];
        $walletBaseCurrency = $this->getWalletBaseCurrency($clientId);
        
        foreach ($conversions as $index => $conversion) {
            try {
                $result = $this->convertAmount(
                    $conversion['currency'],
                    $conversion['amount'], 
                    $conversion['date'],
                    $clientId
                );
                
                $results[$index] = $result;
                
            } catch (Exception $e) {
                $results[$index] = [
                    'original_amount' => $conversion['amount'] ?? null,
                    'original_currency' => $conversion['currency'] ?? null,
                    'converted_amount' => null,
                    'wallet_currency' => $walletBaseCurrency,
                    'fx_rate' => null,
                    'commission_rate' => null,
                    'adjusted_rate' => null,
                    'conversion_date' => $conversion['date'] ?? null,
                    'rate_source' => 'batch_conversion_error',
                    'is_converted' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
}