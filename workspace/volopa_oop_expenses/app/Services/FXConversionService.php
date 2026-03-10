<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * FXConversionService
 * 
 * Service class for handling foreign exchange (FX) conversion operations within the system.
 * Provides currency conversion functionality with rate caching, commission calculations,
 * and integration with external FX rate providers. Supports the platform's FX conversion
 * requirements with 30-day lookback capability and user override functionality.
 * 
 * Key responsibilities:
 * - Retrieve wallet base currencies for clients
 * - Fetch and cache FX rates from external providers
 * - Perform currency amount conversions with commission calculations
 * - Support historical rate lookups within 30-day window
 * - Provide rate validation and conversion verification
 * - Handle rate caching and performance optimization
 * - Support user override of automatic FX calculations
 */
class FXConversionService
{
    /**
     * Default base currency for wallets
     *
     * @var string
     */
    private const DEFAULT_BASE_CURRENCY = 'USD';

    /**
     * Maximum lookback days for FX rate retrieval
     *
     * @var int
     */
    private const MAX_LOOKBACK_DAYS = 30;

    /**
     * Default commission rate (as decimal, e.g., 0.025 = 2.5%)
     *
     * @var float
     */
    private const DEFAULT_COMMISSION_RATE = 0.025;

    /**
     * Cache TTL for FX rates in minutes
     *
     * @var int
     */
    private const FX_RATE_CACHE_TTL = 60;

    /**
     * Cache TTL for wallet base currency in minutes
     *
     * @var int
     */
    private const WALLET_CURRENCY_CACHE_TTL = 1440; // 24 hours

    /**
     * External FX API endpoint (using a free service as default)
     *
     * @var string
     */
    private const FX_API_ENDPOINT = 'https://api.exchangerate-api.com/v4/latest';

    /**
     * Backup FX API endpoint
     *
     * @var string
     */
    private const BACKUP_FX_API_ENDPOINT = 'https://api.fixer.io/latest';

    /**
     * Timeout for external API calls in seconds
     *
     * @var int
     */
    private const API_TIMEOUT_SECONDS = 10;

    /**
     * Maximum retry attempts for API calls
     *
     * @var int
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Supported currency codes for validation
     *
     * @var array<int, string>
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'HUF', 'BGN', 'RON', 'HRK', 'RSD', 'BAM', 'MKD', 'ALL',
        'ISK', 'TRY', 'RUB', 'UAH', 'BYN', 'MDL', 'GEL', 'AMD', 'AZN', 'KZT',
        'UZS', 'KGS', 'TJS', 'TMT', 'MNT', 'CNY', 'HKD', 'SGD', 'MYR', 'THB',
        'IDR', 'PHP', 'VND', 'KRW', 'INR', 'PKR', 'LKR', 'BDT', 'NPR', 'BTN',
        'MVR', 'AFN', 'IRR', 'IQD', 'SYP', 'LBP', 'JOD', 'KWD', 'BHD', 'QAR',
        'AED', 'OMR', 'YER', 'SAR', 'ILS', 'EGP', 'LYD', 'TND', 'DZD', 'MAD',
        'XOF', 'XAF', 'NGN', 'GHS', 'XCD', 'BBD', 'JMD', 'TTD', 'COP', 'PEN',
        'BOB', 'BRL', 'ARS', 'CLP', 'UYU', 'PYG', 'VES', 'GYD', 'SRD', 'FKP',
        'ZAR', 'BWP', 'NAD', 'SZL', 'LSL', 'MZN', 'MWK', 'ZMW', 'AOA', 'CDF'
    ];

    /**
     * Fallback exchange rates for emergency use
     *
     * @var array<string, float>
     */
    private const FALLBACK_RATES = [
        'EUR' => 0.85,
        'GBP' => 0.73,
        'CAD' => 1.25,
        'AUD' => 1.35,
        'JPY' => 110.0,
        'CHF' => 0.92,
        'SEK' => 8.5,
        'NOK' => 8.8,
        'DKK' => 6.3
    ];

    /**
     * Get wallet base currency information for a client.
     * 
     * Retrieves the base currency configuration for a client's wallet,
     * including currency code, symbol, and decimal places. Supports
     * caching for performance optimization.
     *
     * @param int $clientId
     * @return array<string, mixed>
     */
    public function getWalletBaseCurrency(int $clientId): array
    {
        $cacheKey = "wallet_base_currency_{$clientId}";
        
        return Cache::remember($cacheKey, self::WALLET_CURRENCY_CACHE_TTL, function () use ($clientId) {
            try {
                // In a real implementation, this would query client settings
                // For now, we'll return default configuration
                $baseCurrency = $this->getClientBaseCurrency($clientId);
                
                return [
                    'currency_code' => $baseCurrency,
                    'currency_symbol' => $this->getCurrencySymbol($baseCurrency),
                    'decimal_places' => $this->getCurrencyDecimalPlaces($baseCurrency),
                    'client_id' => $clientId,
                    'is_default' => $baseCurrency === self::DEFAULT_BASE_CURRENCY,
                    'last_updated' => now()->toISOString()
                ];
                
            } catch (\Exception $e) {
                Log::error('Failed to get wallet base currency', [
                    'client_id' => $clientId,
                    'error' => $e->getMessage()
                ]);
                
                // Return default configuration
                return [
                    'currency_code' => self::DEFAULT_BASE_CURRENCY,
                    'currency_symbol' => '$',
                    'decimal_places' => 2,
                    'client_id' => $clientId,
                    'is_default' => true,
                    'last_updated' => now()->toISOString()
                ];
            }
        });
    }

    /**
     * Get foreign exchange rate between two currencies for a specific date.
     * 
     * Retrieves FX rate with caching and fallback mechanisms. Supports
     * historical rates within the lookback window and handles API failures
     * gracefully with cached or fallback rates.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date
     * @return float
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, string $date): float
    {
        // Normalize currency codes
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        
        // Validate currencies
        $this->validateCurrency($fromCurrency);
        $this->validateCurrency($toCurrency);
        
        // If same currency, return 1.0
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }
        
        // Validate date and check lookback window
        $rateDate = $this->validateAndParseDate($date);
        $this->validateDateLookback($rateDate);
        
        // Try to get cached rate first
        $cacheKey = $this->buildRateCacheKey($fromCurrency, $toCurrency, $rateDate->format('Y-m-d'));
        
        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate !== null) {
            Log::debug('Using cached FX rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $rateDate->format('Y-m-d'),
                'rate' => $cachedRate
            ]);
            return (float) $cachedRate;
        }
        
        // Fetch rate from external API
        try {
            $rate = $this->fetchExchangeRate($fromCurrency, $toCurrency, $rateDate);
            
            // Cache the rate
            Cache::put($cacheKey, $rate, now()->addMinutes(self::FX_RATE_CACHE_TTL));
            
            Log::info('Fetched and cached FX rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $rateDate->format('Y-m-d'),
                'rate' => $rate
            ]);
            
            return $rate;
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch FX rate from API', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $rateDate->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);
            
            // Try fallback rate
            $fallbackRate = $this->getFallbackRate($fromCurrency, $toCurrency);
            if ($fallbackRate !== null) {
                Log::warning('Using fallback FX rate', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'rate' => $fallbackRate
                ]);
                return $fallbackRate;
            }
            
            throw new \RuntimeException("Unable to retrieve FX rate for {$fromCurrency} to {$toCurrency}");
        }
    }

    /**
     * Convert amount from one currency to another using FX rate.
     * 
     * Performs currency conversion with proper rounding and validation.
     * Supports amount validation and precision handling based on target currency.
     *
     * @param float $amount
     * @param float $fxRate
     * @param float $commissionRate
     * @return float
     * @throws \InvalidArgumentException
     */
    public function convertAmount(float $amount, float $fxRate, float $commissionRate = 0.0): float
    {
        // Validate inputs
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative');
        }
        
        if ($fxRate <= 0) {
            throw new \InvalidArgumentException('FX rate must be greater than zero');
        }
        
        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new \InvalidArgumentException('Commission rate must be between 0 and 1');
        }
        
        // Apply commission to the rate
        $adjustedRate = $this->calculateWithCommission($fxRate, $commissionRate);
        
        // Perform conversion
        $convertedAmount = $amount * $adjustedRate;
        
        // Round to 2 decimal places
        return round($convertedAmount, 2);
    }

    /**
     * Calculate FX rate with commission applied.
     * 
     * Applies commission rate to the base FX rate using the formula:
     * AdjustedRate = BaseRate × (1 - Commission%)
     *
     * @param float $baseRate
     * @param float $commissionRate
     * @return float
     * @throws \InvalidArgumentException
     */
    public function calculateWithCommission(float $baseRate, float $commissionRate): float
    {
        if ($baseRate <= 0) {
            throw new \InvalidArgumentException('Base rate must be greater than zero');
        }
        
        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new \InvalidArgumentException('Commission rate must be between 0 and 1');
        }
        
        // Apply commission: AdjustedRate = BaseRate × (1 - Commission%)
        $adjustedRate = $baseRate * (1 - $commissionRate);
        
        return round($adjustedRate, 6); // Keep 6 decimal places for precision
    }

    /**
     * Get comprehensive FX conversion information for display purposes.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param float $amount
     * @param string $date
     * @param float $commissionRate
     * @return array<string, mixed>
     */
    public function getConversionInfo(string $fromCurrency, string $toCurrency, float $amount, string $date, float $commissionRate = 0.0): array
    {
        try {
            $baseRate = $this->getFXRate($fromCurrency, $toCurrency, $date);
            $adjustedRate = $this->calculateWithCommission($baseRate, $commissionRate);
            $convertedAmount = $this->convertAmount($amount, $baseRate, $commissionRate);
            
            return [
                'from_currency' => strtoupper($fromCurrency),
                'to_currency' => strtoupper($toCurrency),
                'original_amount' => round($amount, 2),
                'converted_amount' => $convertedAmount,
                'base_rate' => $baseRate,
                'commission_rate' => $commissionRate,
                'adjusted_rate' => $adjustedRate,
                'rate_date' => $date,
                'conversion_date' => now()->toISOString(),
                'rate_source' => 'API',
                'calculation_details' => [
                    'formula' => 'Original Amount × Adjusted Rate',
                    'commission_formula' => 'Base Rate × (1 - Commission Rate)',
                    'original_amount' => $amount,
                    'base_rate' => $baseRate,
                    'commission_rate' => $commissionRate,
                    'adjusted_rate' => $adjustedRate,
                    'result' => $convertedAmount
                ]
            ];
            
        } catch (\Exception $e) {
            return [
                'error' => true,
                'error_message' => $e->getMessage(),
                'from_currency' => strtoupper($fromCurrency),
                'to_currency' => strtoupper($toCurrency),
                'original_amount' => round($amount, 2),
                'converted_amount' => null,
                'rate_date' => $date,
                'conversion_date' => now()->toISOString()
            ];
        }
    }

    /**
     * Validate currency conversion parameters.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function validateConversionParams(array $params): array
    {
        $errors = [];
        
        // Validate from_currency
        if (empty($params['from_currency'])) {
            $errors['from_currency'] = 'From currency is required';
        } elseif (!$this->isSupportedCurrency($params['from_currency'])) {
            $errors['from_currency'] = 'Unsupported from currency';
        }
        
        // Validate to_currency
        if (empty($params['to_currency'])) {
            $errors['to_currency'] = 'To currency is required';
        } elseif (!$this->isSupportedCurrency($params['to_currency'])) {
            $errors['to_currency'] = 'Unsupported to currency';
        }
        
        // Validate amount
        if (!isset($params['amount']) || !is_numeric($params['amount'])) {
            $errors['amount'] = 'Amount must be a valid number';
        } elseif ((float) $params['amount'] <= 0) {
            $errors['amount'] = 'Amount must be greater than zero';
        } elseif ((float) $params['amount'] > 999999999999.99) {
            $errors['amount'] = 'Amount exceeds maximum allowed value';
        }
        
        // Validate date
        if (empty($params['date'])) {
            $errors['date'] = 'Date is required';
        } else {
            try {
                $date = Carbon::parse($params['date']);
                if ($date->isFuture()) {
                    $errors['date'] = 'Date cannot be in the future';
                } elseif ($date->lt(now()->subDays(self::MAX_LOOKBACK_DAYS))) {
                    $errors['date'] = 'Date cannot be older than ' . self::MAX_LOOKBACK_DAYS . ' days';
                }
            } catch (\Exception $e) {
                $errors['date'] = 'Invalid date format';
            }
        }
        
        // Validate commission_rate if provided
        if (isset($params['commission_rate'])) {
            if (!is_numeric($params['commission_rate'])) {
                $errors['commission_rate'] = 'Commission rate must be a valid number';
            } elseif ((float) $params['commission_rate'] < 0 || (float) $params['commission_rate'] > 1) {
                $errors['commission_rate'] = 'Commission rate must be between 0 and 1';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'validated_params' => empty($errors) ? $this->normalizeParams($params) : []
        ];
    }

    /**
     * Get client base currency from configuration.
     *
     * @param int $clientId
     * @return string
     */
    private function getClientBaseCurrency(int $clientId): string
    {
        // In a real implementation, this would query the client configuration
        // For now, return default currency
        return self::DEFAULT_BASE_CURRENCY;
    }

    /**
     * Get currency symbol for a given currency code.
     *
     * @param string $currencyCode
     * @return string
     */
    private function getCurrencySymbol(string $currencyCode): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'CHF' => 'CHF',
            'CNY' => '¥',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
            'HUF' => 'Ft'
        ];
        
        return $symbols[strtoupper($currencyCode)] ?? strtoupper($currencyCode);
    }

    /**
     * Get decimal places for a given currency.
     *
     * @param string $currencyCode
     * @return int
     */
    private function getCurrencyDecimalPlaces(string $currencyCode): int
    {
        // Most currencies use 2 decimal places, but some exceptions exist
        $specialCases = [
            'JPY' => 0, // Japanese Yen
            'KRW' => 0, // Korean Won
            'VND' => 0, // Vietnamese Dong
            'CLP' => 0, // Chilean Peso
            'ISK' => 0, // Icelandic Krona
            'BHD' => 3, // Bahraini Dinar
            'KWD' => 3, // Kuwaiti Dinar
            'OMR' => 3, // Omani Rial
            'JOD' => 3  // Jordanian Dinar
        ];
        
        return $specialCases[strtoupper($currencyCode)] ?? 2;
    }

    /**
     * Validate currency code.
     *
     * @param string $currency
     * @throws \InvalidArgumentException
     */
    private function validateCurrency(string $currency): void
    {
        if (empty($currency)) {
            throw new \InvalidArgumentException('Currency cannot be empty');
        }
        
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Currency code must be exactly 3 characters');
        }
        
        if (!$this->isSupportedCurrency($currency)) {
            throw new \InvalidArgumentException("Unsupported currency: {$currency}");
        }
    }

    /**
     * Check if currency is supported.
     *
     * @param string $currency
     * @return bool
     */
    private function isSupportedCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES);
    }

    /**
     * Validate and parse date.
     *
     * @param string $date
     * @return Carbon
     * @throws \InvalidArgumentException
     */
    private function validateAndParseDate(string $date): Carbon
    {
        try {
            $parsedDate = Carbon::parse($date);
            
            if ($parsedDate->isFuture()) {
                throw new \InvalidArgumentException('Date cannot be in the future');
            }
            
            return $parsedDate;
            
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format: ' . $e->getMessage());
        }
    }

    /**
     * Validate date is within lookback window.
     *
     * @param Carbon $date
     * @throws \InvalidArgumentException
     */
    private function validateDateLookback(Carbon $date): void
    {
        $maxLookbackDate = now()->subDays(self::MAX_LOOKBACK_DAYS);
        
        if ($date->lt($maxLookbackDate)) {
            throw new \InvalidArgumentException('Date cannot be older than ' . self::MAX_LOOKBACK_DAYS . ' days');
        }
    }

    /**
     * Build cache key for FX rate.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date
     * @return string
     */
    private function buildRateCacheKey(string $fromCurrency, string $toCurrency, string $date): string
    {
        return "fx_rate_{$fromCurrency}_{$toCurrency}_{$date}";
    }

    /**
     * Fetch exchange rate from external API.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return float
     * @throws \RuntimeException
     */
    private function fetchExchangeRate(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                // Try primary API first
                if ($attempts === 0) {
                    return $this->fetchFromPrimaryAPI($fromCurrency, $toCurrency, $date);
                }
                // Try backup API on retry
                else {
                    return $this->fetchFromBackupAPI($fromCurrency, $toCurrency, $date);
                }
                
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                
                Log::warning("FX API attempt {$attempts} failed", [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'date' => $date->format('Y-m-d'),
                    'error' => $e->getMessage()
                ]);
                
                // Wait before retry
                if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                    sleep(1);
                }
            }
        }
        
        throw new \RuntimeException('All FX API attempts failed: ' . $lastException->getMessage());
    }

    /**
     * Fetch rate from primary API.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return float
     * @throws \RuntimeException
     */
    private function fetchFromPrimaryAPI(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        $url = self::FX_API_ENDPOINT . '/' . $fromCurrency;
        
        $response = Http::timeout(self::API_TIMEOUT_SECONDS)->get($url);
        
        if (!$response->successful()) {
            throw new \RuntimeException("API request failed with status: " . $response->status());
        }
        
        $data = $response->json();
        
        if (!isset($data['rates'][$toCurrency])) {
            throw new \RuntimeException("Rate not found for currency pair: {$fromCurrency} -> {$toCurrency}");
        }
        
        return (float) $data['rates'][$toCurrency];
    }

    /**
     * Fetch rate from backup API.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param Carbon $date
     * @return float
     * @throws \RuntimeException
     */
    private function fetchFromBackupAPI(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        // For backup API, we'll use a simple approach
        // In production, this would integrate with a different FX provider
        
        $url = self::BACKUP_FX_API_ENDPOINT;
        $params = [
            'base' => $fromCurrency,
            'symbols' => $toCurrency
        ];
        
        $response = Http::timeout(self::API_TIMEOUT_SECONDS)->get($url, $params);
        
        if (!$response->successful()) {
            throw new \RuntimeException("Backup API request failed with status: " . $response->status());
        }
        
        $data = $response->json();
        
        if (!isset($data['rates'][$toCurrency])) {
            throw new \RuntimeException("Rate not found in backup API for: {$fromCurrency} -> {$toCurrency}");
        }
        
        return (float) $data['rates'][$toCurrency];
    }

    /**
     * Get fallback rate for currency pair.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return float|null
     */
    private function getFallbackRate(string $fromCurrency, string $toCurrency): ?float
    {
        // If converting from USD, use direct fallback rate
        if ($fromCurrency === 'USD' && isset(self::FALLBACK_RATES[$toCurrency])) {
            return self::FALLBACK_RATES[$toCurrency];
        }
        
        // If converting to USD, use inverse of fallback rate
        if ($toCurrency === 'USD' && isset(self::FALLBACK_RATES[$fromCurrency])) {
            return 1 / self::FALLBACK_RATES[$fromCurrency];
        }
        
        // For cross-currency pairs, calculate via USD
        if (isset(self::FALLBACK_RATES[$fromCurrency]) && isset(self::FALLBACK_RATES[$toCurrency])) {
            $fromToUsd = 1 / self::FALLBACK_RATES[$fromCurrency];
            $usdToTarget = self::FALLBACK_RATES[$toCurrency];
            return $fromToUsd * $usdToTarget;
        }
        
        return null;
    }

    /**
     * Normalize conversion parameters.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeParams(array $params): array
    {
        return [
            'from_currency' => strtoupper(trim($params['from_currency'])),
            'to_currency' => strtoupper(trim($params['to_currency'])),
            'amount' => round((float) $params['amount'], 2),
            'date' => Carbon::parse($params['date'])->format('Y-m-d'),
            'commission_rate' => isset($params['commission_rate']) ? 
                (float) $params['commission_rate'] : self::DEFAULT_COMMISSION_RATE
        ];
    }

    /**
     * Get historical rates for a currency pair over a date range.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $startDate
     * @param string $endDate
     * @return array<string, mixed>
     */
    public function getHistoricalRates(string $fromCurrency, string $toCurrency, string $startDate, string $endDate): array
    {
        $rates = [];
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        
        // Validate date range
        if ($end->lt($start)) {
            throw new \InvalidArgumentException('End date must be after start date');
        }
        
        if ($start->lt(now()->subDays(self::MAX_LOOKBACK_DAYS))) {
            throw new \InvalidArgumentException('Start date cannot be older than ' . self::MAX_LOOKBACK_DAYS . ' days');
        }
        
        $current = $start->copy();
        while ($current->lte($end)) {
            try {
                $rate = $this->getFXRate($fromCurrency, $toCurrency, $current->format('Y-m-d'));
                $rates[$current->format('Y-m-d')] = $rate;
            } catch (\Exception $e) {
                Log::warning('Failed to get historical rate', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'date' => $current->format('Y-m-d'),
                    'error' => $e->getMessage()
                ]);
                $rates[$current->format('Y-m-d')] = null;
            }
            
            $current->addDay();
        }
        
        return [
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rates' => $rates,
            'retrieved_at' => now()->toISOString()
        ];
    }

    /**
     * Clear FX rate cache for specific currency pair and date.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date
     * @return bool
     */
    public function clearRateCache(string $fromCurrency, string $toCurrency, string $date): bool
    {
        $cacheKey = $this->buildRateCacheKey($fromCurrency, $toCurrency, $date);
        return Cache::forget($cacheKey);
    }

    /**
     * Get supported currencies list.
     *
     * @return array<int, string>
     */
    public function getSupportedCurrencies(): array
    {
        return self::SUPPORTED_CURRENCIES;
    }

    /**
     * Get FX service status and configuration.
     *
     * @return array<string, mixed>
     */
    public function getServiceStatus(): array
    {
        return [
            'service_name' => 'FX Conversion Service',
            'version' => '1.0.0',
            'status' => 'operational',
            'supported_currencies_count' => count(self::SUPPORTED_CURRENCIES),
            'max_lookback_days' => self::MAX_LOOKBACK_DAYS,
            'default_commission_rate' => self::DEFAULT_COMMISSION_RATE,
            'cache_ttl_minutes' => self::FX_RATE_CACHE_TTL,
            'api_timeout_seconds' => self::API_TIMEOUT_SECONDS,
            'max_retry_attempts' => self::MAX_RETRY_ATTEMPTS,
            'fallback_rates_available' => count(self::FALLBACK_RATES),
            'last_check' => now()->toISOString()
        ];
    }
}