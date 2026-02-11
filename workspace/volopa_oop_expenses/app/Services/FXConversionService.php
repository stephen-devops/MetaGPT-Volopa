<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Exception;

class FXConversionService
{
    /**
     * Cache duration for FX rates in minutes.
     */
    private const CACHE_DURATION = 60;

    /**
     * Default commission rate if not specified.
     */
    private const DEFAULT_COMMISSION_RATE = 0.0000;

    /**
     * Maximum age of FX rates in days before considering them stale.
     */
    private const MAX_RATE_AGE_DAYS = 7;

    /**
     * Get the wallet base currency for a client.
     *
     * @param int $clientId
     * @return string
     */
    public function getWalletBaseCurrency(int $clientId): string
    {
        try {
            $cacheKey = "client_base_currency_{$clientId}";
            
            return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($clientId) {
                // Try to get from account table first
                $baseCurrency = DB::table('account')
                    ->join('clients', 'account.client_id', '=', 'clients.id')
                    ->where('clients.id', $clientId)
                    ->where('account.is_primary', true)
                    ->where('account.is_active', true)
                    ->value('account.base_currency');

                if ($baseCurrency) {
                    Log::debug('Retrieved base currency from account table', [
                        'client_id' => $clientId,
                        'base_currency' => $baseCurrency
                    ]);
                    return $baseCurrency;
                }

                // Fallback to prepaid card base currency
                $cardCurrency = DB::table('prepaid_card')
                    ->join('clients', 'prepaid_card.client_id', '=', 'clients.id')
                    ->where('clients.id', $clientId)
                    ->where('prepaid_card.is_active', true)
                    ->value('prepaid_card.base_currency');

                if ($cardCurrency) {
                    Log::debug('Retrieved base currency from prepaid card table', [
                        'client_id' => $clientId,
                        'base_currency' => $cardCurrency
                    ]);
                    return $cardCurrency;
                }

                // Final fallback to client's default currency or USD
                $clientCurrency = DB::table('clients')
                    ->where('id', $clientId)
                    ->where('is_active', true)
                    ->value('default_currency');

                $finalCurrency = $clientCurrency ?: 'USD';
                
                Log::debug('Using fallback base currency', [
                    'client_id' => $clientId,
                    'base_currency' => $finalCurrency,
                    'is_fallback' => true
                ]);

                return $finalCurrency;
            });

        } catch (Exception $e) {
            Log::error('Failed to retrieve wallet base currency', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            // Return USD as final fallback
            return 'USD';
        }
    }

    /**
     * Get FX rate between two currencies for a specific date.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date
     * @return float
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, string $date): float
    {
        try {
            // Same currency conversion
            if ($fromCurrency === $toCurrency) {
                return 1.0;
            }

            $fromCurrency = strtoupper($fromCurrency);
            $toCurrency = strtoupper($toCurrency);
            $rateDate = Carbon::parse($date)->format('Y-m-d');

            $cacheKey = "fx_rate_{$fromCurrency}_{$toCurrency}_{$rateDate}";
            
            return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($fromCurrency, $toCurrency, $rateDate, $date) {
                // Try to get exact date rate first
                $rate = $this->getFXRateFromDatabase($fromCurrency, $toCurrency, $rateDate);
                
                if ($rate !== null) {
                    Log::debug('Retrieved exact FX rate from database', [
                        'from' => $fromCurrency,
                        'to' => $toCurrency,
                        'date' => $rateDate,
                        'rate' => $rate
                    ]);
                    return $rate;
                }

                // Try to get rate within reasonable date range
                $rate = $this->getFXRateWithFallback($fromCurrency, $toCurrency, $date);
                
                if ($rate !== null) {
                    Log::debug('Retrieved fallback FX rate from database', [
                        'from' => $fromCurrency,
                        'to' => $toCurrency,
                        'requested_date' => $rateDate,
                        'rate' => $rate
                    ]);
                    return $rate;
                }

                // Try inverse rate
                $inverseRate = $this->getFXRateFromDatabase($toCurrency, $fromCurrency, $rateDate);
                if ($inverseRate !== null && $inverseRate > 0) {
                    $calculatedRate = 1.0 / $inverseRate;
                    Log::debug('Calculated FX rate from inverse', [
                        'from' => $fromCurrency,
                        'to' => $toCurrency,
                        'date' => $rateDate,
                        'inverse_rate' => $inverseRate,
                        'calculated_rate' => $calculatedRate
                    ]);
                    return $calculatedRate;
                }

                // Try cross-rate calculation via USD
                if ($fromCurrency !== 'USD' && $toCurrency !== 'USD') {
                    $fromUsdRate = $this->getFXRateFromDatabase($fromCurrency, 'USD', $rateDate);
                    $toUsdRate = $this->getFXRateFromDatabase($toCurrency, 'USD', $rateDate);
                    
                    if ($fromUsdRate !== null && $toUsdRate !== null && $toUsdRate > 0) {
                        $crossRate = $fromUsdRate / $toUsdRate;
                        Log::debug('Calculated cross FX rate via USD', [
                            'from' => $fromCurrency,
                            'to' => $toCurrency,
                            'date' => $rateDate,
                            'from_usd_rate' => $fromUsdRate,
                            'to_usd_rate' => $toUsdRate,
                            'cross_rate' => $crossRate
                        ]);
                        return $crossRate;
                    }
                }

                // Log warning and return default rate
                Log::warning('No FX rate found, returning default rate', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'date' => $rateDate
                ]);

                return 1.0; // Default fallback rate
            });

        } catch (Exception $e) {
            Log::error('Failed to retrieve FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            
            return 1.0; // Default fallback rate
        }
    }

    /**
     * Calculate the converted amount with commission.
     *
     * @param float $amount
     * @param float $rate
     * @param float $commission
     * @return float
     */
    public function calculateConvertedAmount(float $amount, float $rate, float $commission = self::DEFAULT_COMMISSION_RATE): float
    {
        try {
            // Validate inputs
            if ($amount <= 0) {
                throw new Exception('Amount must be greater than zero');
            }

            if ($rate <= 0) {
                throw new Exception('FX rate must be greater than zero');
            }

            if ($commission < 0 || $commission > 1) {
                throw new Exception('Commission must be between 0 and 1 (0% to 100%)');
            }

            // Calculate: amount * rate * (1 - commission)
            $convertedAmount = $amount * $rate * (1 - $commission);

            // Round to 2 decimal places
            $convertedAmount = round($convertedAmount, 2);

            Log::debug('Calculated converted amount', [
                'original_amount' => $amount,
                'fx_rate' => $rate,
                'commission' => $commission,
                'converted_amount' => $convertedAmount
            ]);

            return $convertedAmount;

        } catch (Exception $e) {
            Log::error('Failed to calculate converted amount', [
                'amount' => $amount,
                'rate' => $rate,
                'commission' => $commission,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Conversion calculation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the client's commission rate for FX conversions.
     *
     * @param int $clientId
     * @return float
     */
    public function getClientCommission(int $clientId): float
    {
        try {
            $cacheKey = "client_fx_commission_{$clientId}";
            
            return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($clientId) {
                // Try to get from account tier settings
                $commission = DB::table('account')
                    ->join('account_tier', 'account.tier_id', '=', 'account_tier.id')
                    ->where('account.client_id', $clientId)
                    ->where('account.is_primary', true)
                    ->where('account.is_active', true)
                    ->value('account_tier.fx_commission_rate');

                if ($commission !== null) {
                    Log::debug('Retrieved FX commission from account tier', [
                        'client_id' => $clientId,
                        'commission' => $commission
                    ]);
                    return (float) $commission;
                }

                // Fallback to prepaid card tier settings
                $cardCommission = DB::table('prepaid_card')
                    ->join('prepaid_card_tier_map', 'prepaid_card.id', '=', 'prepaid_card_tier_map.card_id')
                    ->join('account_tier', 'prepaid_card_tier_map.tier_id', '=', 'account_tier.id')
                    ->where('prepaid_card.client_id', $clientId)
                    ->where('prepaid_card.is_active', true)
                    ->value('account_tier.fx_commission_rate');

                if ($cardCommission !== null) {
                    Log::debug('Retrieved FX commission from prepaid card tier', [
                        'client_id' => $clientId,
                        'commission' => $cardCommission
                    ]);
                    return (float) $cardCommission;
                }

                // Client-specific FX settings
                $clientCommission = DB::table('clients')
                    ->where('id', $clientId)
                    ->where('is_active', true)
                    ->value('fx_commission_rate');

                if ($clientCommission !== null) {
                    Log::debug('Retrieved FX commission from client settings', [
                        'client_id' => $clientId,
                        'commission' => $clientCommission
                    ]);
                    return (float) $clientCommission;
                }

                // Default commission rate
                Log::debug('Using default FX commission rate', [
                    'client_id' => $clientId,
                    'commission' => self::DEFAULT_COMMISSION_RATE
                ]);

                return self::DEFAULT_COMMISSION_RATE;
            });

        } catch (Exception $e) {
            Log::error('Failed to retrieve client FX commission', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return self::DEFAULT_COMMISSION_RATE;
        }
    }

    /**
     * Get FX rate from database for specific currencies and date.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date
     * @return float|null
     */
    private function getFXRateFromDatabase(string $fromCurrency, string $toCurrency, string $date): ?float
    {
        try {
            $rate = DB::table('fx_rates')
                ->where('from_currency', $fromCurrency)
                ->where('to_currency', $toCurrency)
                ->where('rate_date', $date)
                ->where('is_active', true)
                ->value('rate');

            return $rate ? (float) $rate : null;

        } catch (Exception $e) {
            Log::error('Database query failed for FX rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Get FX rate with fallback to nearby dates.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date
     * @return float|null
     */
    private function getFXRateWithFallback(string $fromCurrency, string $toCurrency, string $date): ?float
    {
        try {
            $targetDate = Carbon::parse($date);
            
            // Try dates within the last week
            for ($i = 0; $i <= self::MAX_RATE_AGE_DAYS; $i++) {
                // Try the same date minus $i days
                $checkDate = $targetDate->copy()->subDays($i)->format('Y-m-d');
                $rate = $this->getFXRateFromDatabase($fromCurrency, $toCurrency, $checkDate);
                
                if ($rate !== null) {
                    if ($i > 0) {
                        Log::debug('Found FX rate with date fallback', [
                            'from' => $fromCurrency,
                            'to' => $toCurrency,
                            'requested_date' => $date,
                            'found_date' => $checkDate,
                            'days_difference' => $i,
                            'rate' => $rate
                        ]);
                    }
                    return $rate;
                }

                // Also try future dates (in case of weekend/holiday)
                if ($i > 0) {
                    $futureDate = $targetDate->copy()->addDays($i)->format('Y-m-d');
                    $futureRate = $this->getFXRateFromDatabase($fromCurrency, $toCurrency, $futureDate);
                    
                    if ($futureRate !== null) {
                        Log::debug('Found FX rate with future date fallback', [
                            'from' => $fromCurrency,
                            'to' => $toCurrency,
                            'requested_date' => $date,
                            'found_date' => $futureDate,
                            'days_difference' => $i,
                            'rate' => $futureRate
                        ]);
                        return $futureRate;
                    }
                }
            }

            return null;

        } catch (Exception $e) {
            Log::error('FX rate fallback lookup failed', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Validate currency codes.
     *
     * @param string $currency
     * @return bool
     */
    private function isValidCurrency(string $currency): bool
    {
        try {
            $currency = strtoupper($currency);
            
            // Check if currency exists in the currency table
            $exists = DB::table('currency')
                ->where('code', $currency)
                ->where('is_active', true)
                ->exists();

            return $exists;

        } catch (Exception $e) {
            Log::error('Currency validation failed', [
                'currency' => $currency,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Get available currencies for FX conversion.
     *
     * @return array
     */
    public function getAvailableCurrencies(): array
    {
        try {
            $cacheKey = 'available_fx_currencies';
            
            return Cache::remember($cacheKey, self::CACHE_DURATION * 2, function () {
                $currencies = DB::table('currency')
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->pluck('name', 'code')
                    ->toArray();

                Log::debug('Retrieved available currencies for FX', [
                    'count' => count($currencies)
                ]);

                return $currencies;
            });

        } catch (Exception $e) {
            Log::error('Failed to retrieve available currencies', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Get FX rate history for currency pair.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param int $days
     * @return array
     */
    public function getFXRateHistory(string $fromCurrency, string $toCurrency, int $days = 30): array
    {
        try {
            $fromCurrency = strtoupper($fromCurrency);
            $toCurrency = strtoupper($toCurrency);
            $startDate = Carbon::now()->subDays($days)->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');

            $history = DB::table('fx_rates')
                ->where('from_currency', $fromCurrency)
                ->where('to_currency', $toCurrency)
                ->where('rate_date', '>=', $startDate)
                ->where('rate_date', '<=', $endDate)
                ->where('is_active', true)
                ->orderBy('rate_date', 'desc')
                ->get(['rate_date', 'rate'])
                ->toArray();

            Log::debug('Retrieved FX rate history', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'days' => $days,
                'records' => count($history)
            ]);

            return $history;

        } catch (Exception $e) {
            Log::error('Failed to retrieve FX rate history', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'days' => $days,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Clear FX rate cache for specific currency pair.
     *
     * @param string|null $fromCurrency
     * @param string|null $toCurrency
     * @return bool
     */
    public function clearFXRateCache(?string $fromCurrency = null, ?string $toCurrency = null): bool
    {
        try {
            if ($fromCurrency && $toCurrency) {
                // Clear specific currency pair cache
                $pattern = "fx_rate_{$fromCurrency}_{$toCurrency}_*";
                $this->clearCacheByPattern($pattern);
                
                Log::info('Cleared FX rate cache for currency pair', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency
                ]);
            } else {
                // Clear all FX rate caches
                $this->clearCacheByPattern('fx_rate_*');
                $this->clearCacheByPattern('client_base_currency_*');
                $this->clearCacheByPattern('client_fx_commission_*');
                Cache::forget('available_fx_currencies');
                
                Log::info('Cleared all FX rate caches');
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to clear FX rate cache', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Clear cache entries matching a pattern.
     *
     * @param string $pattern
     * @return void
     */
    private function clearCacheByPattern(string $pattern): void
    {
        try {
            // This is a simplified implementation
            // In production, you might want to use Redis SCAN or similar
            // For now, we'll just flush the entire cache if pattern-based clearing is needed
            if (str_contains($pattern, '*')) {
                Cache::flush();
                Log::debug('Cache flushed due to pattern matching', [
                    'pattern' => $pattern
                ]);
            } else {
                Cache::forget($pattern);
                Log::debug('Specific cache key forgotten', [
                    'key' => $pattern
                ]);
            }

        } catch (Exception $e) {
            Log::error('Failed to clear cache by pattern', [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate FX conversion request.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param float $amount
     * @param string $date
     * @return array
     */
    public function validateFXConversionRequest(string $fromCurrency, string $toCurrency, float $amount, string $date): array
    {
        $errors = [];

        try {
            // Validate currencies
            if (!$this->isValidCurrency($fromCurrency)) {
                $errors[] = "Invalid from currency: {$fromCurrency}";
            }

            if (!$this->isValidCurrency($toCurrency)) {
                $errors[] = "Invalid to currency: {$toCurrency}";
            }

            // Validate amount
            if ($amount <= 0) {
                $errors[] = "Amount must be greater than zero";
            }

            if ($amount > 999999.99) {
                $errors[] = "Amount exceeds maximum allowed value";
            }

            // Validate date
            try {
                $convertDate = Carbon::parse($date);
                $today = Carbon::today();
                $maxPastDate = $today->copy()->subYears(2);

                if ($convertDate->isFuture()) {
                    $errors[] = "Date cannot be in the future";
                }

                if ($convertDate->isBefore($maxPastDate)) {
                    $errors[] = "Date is too far in the past (maximum 2 years)";
                }
            } catch (Exception $e) {
                $errors[] = "Invalid date format";
            }

            // Check if currencies are the same
            if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
                $errors[] = "From and to currencies cannot be the same";
            }

        } catch (Exception $e) {
            $errors[] = "Validation error: " . $e->getMessage();
            Log::error('FX conversion validation failed', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'amount' => $amount,
                'date' => $date,
                'error' => $e->getMessage()
            ]);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get comprehensive FX conversion information.
     *
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param float $amount
     * @param string $date
     * @param int $clientId
     * @return array
     */
    public function getConversionInfo(string $fromCurrency, string $toCurrency, float $amount, string $date, int $clientId): array
    {
        try {
            // Validate the request
            $validation = $this->validateFXConversionRequest($fromCurrency, $toCurrency, $amount, $date);
            
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'errors' => $validation['errors']
                ];
            }

            // Get FX rate and commission
            $fxRate = $this->getFXRate($fromCurrency, $toCurrency, $date);
            $commission = $this->getClientCommission($clientId);
            
            // Calculate converted amount
            $convertedAmount = $this->calculateConvertedAmount($amount, $fxRate, $commission);
            
            // Get additional info
            $baseCurrency = $this->getWalletBaseCurrency($clientId);
            $commissionAmount = $amount * $fxRate * $commission;

            $result = [
                'success' => true,
                'conversion' => [
                    'from_currency' => strtoupper($fromCurrency),
                    'to_currency' => strtoupper($toCurrency),
                    'original_amount' => $amount,
                    'converted_amount' => $convertedAmount,
                    'fx_rate' => $fxRate,
                    'commission_rate' => $commission,
                    'commission_amount' => round($commissionAmount, 2),
                    'net_amount' => $convertedAmount,
                    'conversion_date' => $date,
                    'client_base_currency' => $baseCurrency,
                ],
                'metadata' => [
                    'rate_source' => 'database',
                    'timestamp' => now()->toISOString(),
                    'client_id' => $clientId
                ]
            ];

            Log::info('FX conversion info generated', [
                'client_id' => $clientId,
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'amount' => $amount,
                'rate' => $fxRate,
                'converted_amount' => $convertedAmount
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to generate FX conversion info', [
                'client_id' => $clientId,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'amount' => $amount,
                'date' => $date,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'errors' => ['Conversion calculation failed: ' . $e->getMessage()]
            ];
        }
    }
}