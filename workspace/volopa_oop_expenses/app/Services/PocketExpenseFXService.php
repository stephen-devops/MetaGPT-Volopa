```php
<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

class PocketExpenseFXService
{
    /**
     * Maximum FX rate lookback days from expense date.
     *
     * @var int
     */
    private const MAX_FX_LOOKBACK_DAYS = 30;

    /**
     * Default commission percentage for FX conversion.
     *
     * @var float
     */
    private const DEFAULT_COMMISSION_PERCENT = 2.5;

    /**
     * Cache TTL for FX rates in minutes.
     *
     * @var int
     */
    private const FX_CACHE_TTL_MINUTES = 60;

    /**
     * Default wallet base currency.
     *
     * @var string
     */
    private const DEFAULT_BASE_CURRENCY = 'USD';

    /**
     * FX API endpoint for rate lookup (platform infrastructure).
     *
     * @var string
     */
    private const FX_API_ENDPOINT = 'https://api.volopa.com/fx/rates';

    /**
     * Timeout for FX API calls in seconds.
     *
     * @var int
     */
    private const FX_API_TIMEOUT = 10;

    /**
     * Valid ISO currency codes.
     *
     * @var array<string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'INR', 'KRW', 'THB', 'BRL', 'ZAR', 'RUB',
        'PLN', 'CZK', 'HUF', 'TRY', 'ILS', 'AED', 'SAR', 'EGP', 'QAR', 'KWD'
    ];

    /**
     * Get the wallet base currency for a specific client.
     *
     * @param int $clientId
     * @return array<string, mixed>
     */
    public function getWalletBaseCurrency(int $clientId): array
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException('Client ID must be a positive integer');
        }

        try {
            // Get client configuration
            $client = Client::find($clientId);
            
            if (!$client) {
                throw new InvalidArgumentException('Client not found');
            }

            // Check if client has a specific base currency configuration
            $baseCurrency = $this->getClientBaseCurrency($clientId);
            
            if (!$baseCurrency) {
                $baseCurrency = self::DEFAULT_BASE_CURRENCY;
            }

            // Validate currency code
            if (!$this->isValidCurrency($baseCurrency)) {
                Log::warning("Invalid base currency '{$baseCurrency}' for client {$clientId}, using default");
                $baseCurrency = self::DEFAULT_BASE_CURRENCY;
            }

            return [
                'base_currency_code' => $baseCurrency,
                'client_id' => $clientId,
                'updated_at' => now()->toISOString()
            ];
        } catch (Exception $e) {
            Log::error('Failed to get wallet base currency', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            // Return default currency on error
            return [
                'base_currency_code' => self::DEFAULT_BASE_CURRENCY,
                'client_id' => $clientId,
                'updated_at' => now()->toISOString(),
                'error' => 'Failed to retrieve client currency configuration'
            ];
        }
    }

    /**
     * Get FX rate between two currencies for a specific date.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return float
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        // Validate input parameters
        if (empty($fromCurrency) || empty($toCurrency)) {
            throw new InvalidArgumentException('Currency codes cannot be empty');
        }

        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        // Validate currency codes
        if (!$this->isValidCurrency($fromCurrency) || !$this->isValidCurrency($toCurrency)) {
            throw new InvalidArgumentException('Invalid currency codes provided');
        }

        // Same currency conversion
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        // Validate date is not in the future
        if ($date->gt(Carbon::now())) {
            throw new InvalidArgumentException('FX rate date cannot be in the future');
        }

        try {
            // Try to get rate from cache first
            $cacheKey = $this->getFXRateCacheKey($fromCurrency, $toCurrency, $date);
            $cachedRate = Cache::get($cacheKey);
            
            if ($cachedRate !== null) {
                return (float) $cachedRate;
            }

            // Look for FX rate with 30-day lookback
            $rate = $this->lookupFXRateWithLookback($fromCurrency, $toCurrency, $date);
            
            if ($rate === null) {
                Log::warning('No FX rate found within lookback period', [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'date' => $date->format('Y-m-d'),
                    'lookback_days' => self::MAX_FX_LOOKBACK_DAYS
                ]);
                
                throw new Exception('No FX Available');
            }

            // Cache the rate
            Cache::put($cacheKey, $rate, now()->addMinutes(self::FX_CACHE_TTL_MINUTES));
            
            return $rate;
        } catch (Exception $e) {
            if ($e->getMessage() === 'No FX Available') {
                throw $e;
            }

            Log::error('Failed to get FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);

            throw new Exception('No FX Available');
        }
    }

    /**
     * Calculate converted amount with FX rate and commission.
     *
     * @param float $amount
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return array<string, mixed>
     */
    public function calculateConvertedAmount(float $amount, string $fromCurrency, string $toCurrency, Carbon $date): array
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative');
        }

        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        try {
            // Get base FX rate
            $baseRate = $this->getFXRate($fromCurrency, $toCurrency, $date);
            
            // Get commission percentage (could be client-specific in the future)
            $commissionPercent = $this->getCommissionPercent();
            
            // Apply commission to rate
            $adjustedRate = $this->applyCommission($baseRate, $commissionPercent);
            
            // Calculate converted amount
            $convertedAmount = round($amount * $adjustedRate, 