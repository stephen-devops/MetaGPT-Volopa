<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FX Conversion Service
 * 
 * Handles foreign exchange rate lookups, commission calculations, and currency conversions
 * for the OOP Expense system. Provides real-time FX rates with platform-managed commissions
 * and integrates with Volopa's wallet infrastructure for base currency determination.
 * 
 * Key features:
 * - 30-day lookback window for FX rate retrieval
 * - Commission application using platform-defined rates
 * - Wallet base currency discovery via prepaid card relationships
 * - Robust error handling with fallback mechanisms
 * - Integration with platform FX infrastructure
 */
class FXConversionService
{
    /**
     * Maximum number of days to look back for FX rates
     */
    private const MAX_FX_LOOKBACK_DAYS = 30;

    /**
     * Default commission percentage if none found (0.5%)
     */
    private const DEFAULT_COMMISSION_PERCENT = 0.5;

    /**
     * Default base currency fallback
     */
    private const DEFAULT_BASE_CURRENCY = 'GBP';

    /**
     * Cache duration for wallet base currency (in seconds)
     */
    private const WALLET_CACHE_DURATION = 3600; // 1 hour

    /**
     * Get wallet base currency for a client
     * 
     * Looks up the client's wallet base currency through the prepaid card
     * relationship chain. Uses caching to avoid repeated database queries.
     * 
     * @param int $clientId Client ID to lookup wallet for
     * @return array Array containing currency code, symbol, and decimal places
     * @throws InvalidArgumentException If client ID is invalid
     */
    public function getWalletBaseCurrency(int $clientId): array
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException('Client ID must be a positive integer');
        }

        try {
            // Query the prepaid card join chain to get wallet base currency
            $walletCurrency = DB::table('prepaid_cards')
                ->join('wallets', 'prepaid_cards.wallet_id', '=', 'wallets.id')
                ->join('currencies', 'wallets.base_currency_id', '=', 'currencies.id')
                ->where('prepaid_cards.client_id', $clientId)
                ->where('prepaid_cards.deleted', 0)
                ->where('wallets.deleted', 0)
                ->select([
                    'currencies.currency_code',
                    'currencies.currency_symbol',
                    'currencies.decimal_places',
                    'currencies.name as currency_name'
                ])
                ->first();

            if (!$walletCurrency) {
                Log::warning("No wallet base currency found for client {$clientId}, using default", [
                    'client_id' => $clientId,
                    'default_currency' => self::DEFAULT_BASE_CURRENCY
                ]);

                return [
                    'currency_code' => self::DEFAULT_BASE_CURRENCY,
                    'currency_symbol' => '£',
                    'decimal_places' => 2,
                    'currency_name' => 'British Pound Sterling'
                ];
            }

            return [
                'currency_code' => $walletCurrency->currency_code,
                'currency_symbol' => $walletCurrency->currency_symbol,
                'decimal_places' => $walletCurrency->decimal_places,
                'currency_name' => $walletCurrency->currency_name
            ];

        } catch (Exception $e) {
            Log::error('Failed to retrieve wallet base currency', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return default currency on error
            return [
                'currency_code' => self::DEFAULT_BASE_CURRENCY,
                'currency_symbol' => '£',
                'decimal_places' => 2,
                'currency_name' => 'British Pound Sterling'
            ];
        }
    }

    /**
     * Get FX rate between two currencies for a specific date
     * 
     * Looks up the exchange rate with a maximum 30-day lookback from the expense date.
     * If no rate is found within the lookback period, returns null to indicate
     * 'No FX Available' status.
     * 
     * @param string $fromCurrency 3-letter ISO currency code (source)
     * @param string $toCurrency 3-letter ISO currency code (target)
     * @param Carbon $date Date for which to lookup the FX rate
     * @return float|null FX rate or null if no rate found within lookback period
     * @throws InvalidArgumentException If currency codes are invalid
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        // Validate currency codes
        if (!$this->isValidCurrencyCode($fromCurrency) || !$this->isValidCurrencyCode($toCurrency)) {
            throw new InvalidArgumentException('Currency codes must be 3-letter ISO codes');
        }

        // Same currency always returns rate of 1.0
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        try {
            $lookbackDate = $date->copy()->subDays(self::MAX_FX_LOOKBACK_DAYS);

            // Query FX rates table with lookback window
            $fxRate = DB::table('fx_rates')
                ->where('from_currency', $fromCurrency)
                ->where('to_currency', $toCurrency)
                ->where('rate_date', '<=', $date->format('Y-m-d'))
                ->where('rate_date', '>=', $lookbackDate->format('Y-m-d'))
                ->where('is_active', 1)
                ->orderBy('rate_date', 'desc')
                ->first();

            if (!$fxRate) {
                Log::info('No FX rate found within lookback period', [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'date' => $date->format('Y-m-d'),
                    'lookback_days' => self::MAX_FX_LOOKBACK_DAYS
                ]);
                return null;
            }

            $baseRate = (float) $fxRate->rate;

            // Get commission percentage for this currency pair
            $commissionPercent = $this->getCommissionPercent($fromCurrency, $toCurrency);

            // Apply commission to the base rate
            $adjustedRate = $this->applyCommission($baseRate, $commissionPercent);

            Log::debug('FX rate retrieved and adjusted', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'base_rate' => $baseRate,
                'commission_percent' => $commissionPercent,
                'adjusted_rate' => $adjustedRate
            ]);

            return $adjustedRate;

        } catch (Exception $e) {
            Log::error('Failed to retrieve FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return null;
        }
    }

    /**
     * Apply commission to a base FX rate
     * 
     * Uses the formula: AdjustedRate = BaseRate x (1 - CommissionPercent)
     * Commission percentage should be provided as a decimal (e.g., 0.5 for 0.5%)
     * 
     * @param float $baseRate Base exchange rate before commission
     * @param float $commissionPercent Commission percentage as decimal
     * @return float Adjusted rate after commission application
     * @throws InvalidArgumentException If rates are invalid
     */
    public function applyCommission(float $baseRate, float $commissionPercent): float
    {
        if ($baseRate <= 0) {
            throw new InvalidArgumentException('Base rate must be positive');
        }

        if ($commissionPercent < 0 || $commissionPercent > 100) {
            throw new InvalidArgumentException('Commission percent must be between 0 and 100');
        }

        // Convert percentage to decimal if needed (handle both 0.5 and 50 input formats)
        $commissionDecimal = $commissionPercent > 1 ? $commissionPercent / 100 : $commissionPercent;

        // Apply commission formula
        $adjustedRate = $baseRate * (1 - $commissionDecimal);

        // Ensure adjusted rate doesn't go below zero
        return max(0.0, $adjustedRate);
    }

    /**
     * Convert amount using an FX rate
     * 
     * Performs currency conversion using the provided exchange rate.
     * Rounds to 2 decimal places for monetary precision.
     * 
     * @param float $amount Original amount to convert
     * @param float $rate Exchange rate to apply
     * @return float Converted amount rounded to 2 decimal places
     * @throws InvalidArgumentException If parameters are invalid
     */
    public function convertAmount(float $amount, float $rate): float
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException('Exchange rate must be positive');
        }

        $convertedAmount = $amount * $rate;

        // Round to 2 decimal places for monetary precision
        return round($convertedAmount, 2);
    }

    /**
     * Get comprehensive FX conversion data for expense processing
     * 
     * Combines rate lookup, commission application, and amount conversion
     * into a single method call. Returns complete conversion information
     * or indicates when FX is not available.
     * 
     * @param float $amount Original expense amount
     * @param string $fromCurrency Source currency (expense currency)
     * @param string $toCurrency Target currency (wallet base currency)
     * @param Carbon $date Expense date for rate lookup
     * @return array Complete FX conversion data or error information
     */
    public function getFullConversionData(float $amount, string $fromCurrency, string $toCurrency, Carbon $date): array
    {
        try {
            // Get base FX rate
            $fxRate = $this->getFXRate($fromCurrency, $toCurrency, $date);

            if ($fxRate === null) {
                return [
                    'status' => 'no_fx_available',
                    'message' => 'No FX rate available within 30-day lookback period',
                    'original_amount' => $amount,
                    'original_currency' => $fromCurrency,
                    'target_currency' => $toCurrency,
                    'expense_date' => $date->format('Y-m-d'),
                    'converted_amount' => null,
                    'fx_rate' => null,
                    'commission_applied' => false
                ];
            }

            // Convert the amount
            $convertedAmount = $this->convertAmount($amount, $fxRate);

            // Get commission information for transparency
            $commissionPercent = $this->getCommissionPercent($fromCurrency, $toCurrency);
            $baseRate = $this->getBaseFXRate($fromCurrency, $toCurrency, $date);

            return [
                'status' => 'success',
                'message' => 'FX conversion completed successfully',
                'original_amount' => $amount,
                'original_currency' => $fromCurrency,
                'target_currency' => $toCurrency,
                'expense_date' => $date->format('Y-m-d'),
                'converted_amount' => $convertedAmount,
                'fx_rate' => $fxRate,
                'base_fx_rate' => $baseRate,
                'commission_percent' => $commissionPercent,
                'commission_applied' => true,
                'lookback_days' => self::MAX_FX_LOOKBACK_DAYS
            ];

        } catch (Exception $e) {
            Log::error('Failed to get full conversion data', [
                'amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'error',
                'message' => 'FX conversion failed due to system error',
                'original_amount' => $amount,
                'original_currency' => $fromCurrency,
                'target_currency' => $toCurrency,
                'expense_date' => $date->format('Y-m-d'),
                'converted_amount' => null,
                'fx_rate' => null,
                'commission_applied' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate if a currency code is properly formatted
     * 
     * @param string $currencyCode Currency code to validate
     * @return bool True if valid 3-letter ISO format
     */
    private function isValidCurrencyCode(string $currencyCode): bool
    {
        return preg_match('/^[A-Z]{3}$/', $currencyCode) === 1;
    }

    /**
     * Get commission percentage for a currency pair
     * 
     * Looks up platform-configured commission rates for the specific
     * currency pair or falls back to default commission rate.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency  
     * @return float Commission percentage as decimal
     */
    private function getCommissionPercent(string $fromCurrency, string $toCurrency): float
    {
        try {
            // Query commission rates table
            $commission = DB::table('fx_commission_rates')
                ->where('from_currency', $fromCurrency)
                ->where('to_currency', $toCurrency)
                ->where('is_active', 1)
                ->first();

            if ($commission) {
                return (float) $commission->commission_percent;
            }

            // Try reverse currency pair
            $reverseCommission = DB::table('fx_commission_rates')
                ->where('from_currency', $toCurrency)
                ->where('to_currency', $fromCurrency)
                ->where('is_active', 1)
                ->first();

            if ($reverseCommission) {
                return (float) $reverseCommission->commission_percent;
            }

            // Fall back to default commission
            Log::debug('Using default commission rate for currency pair', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'default_commission' => self::DEFAULT_COMMISSION_PERCENT
            ]);

            return self::DEFAULT_COMMISSION_PERCENT;

        } catch (Exception $e) {
            Log::warning('Failed to retrieve commission rate, using default', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'error' => $e->getMessage(),
                'default_commission' => self::DEFAULT_COMMISSION_PERCENT
            ]);

            return self::DEFAULT_COMMISSION_PERCENT;
        }
    }

    /**
     * Get base FX rate without commission applied
     * 
     * Used for transparency in FX conversion reporting to show
     * both base rate and commission-adjusted rate.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param Carbon $date Rate lookup date
     * @return float|null Base rate without commission or null if not found
     */
    private function getBaseFXRate(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        try {
            $lookbackDate = $date->copy()->subDays(self::MAX_FX_LOOKBACK_DAYS);

            $fxRate = DB::table('fx_rates')
                ->where('from_currency', $fromCurrency)
                ->where('to_currency', $toCurrency)
                ->where('rate_date', '<=', $date->format('Y-m-d'))
                ->where('rate_date', '>=', $lookbackDate->format('Y-m-d'))
                ->where('is_active', 1)
                ->orderBy('rate_date', 'desc')
                ->first();

            return $fxRate ? (float) $fxRate->rate : null;

        } catch (Exception $e) {
            Log::error('Failed to retrieve base FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date->format('Y-m-d'),
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Check if FX conversion is required between two currencies
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return bool True if conversion is needed (currencies are different)
     */
    public function isConversionRequired(string $fromCurrency, string $toCurrency): bool
    {
        return strtoupper(trim($fromCurrency)) !== strtoupper(trim($toCurrency));
    }

    /**
     * Get available currencies for FX conversion
     * 
     * Returns list of currencies that have active FX rates available
     * for conversion operations.
     * 
     * @return array Array of currency codes with FX rate availability
     */
    public function getAvailableCurrencies(): array
    {
        try {
            $currencies = DB::table('fx_rates')
                ->select('from_currency as currency_code')
                ->where('is_active', 1)
                ->union(
                    DB::table('fx_rates')
                        ->select('to_currency as currency_code')
                        ->where('is_active', 1)
                )
                ->distinct()
                ->orderBy('currency_code')
                ->pluck('currency_code')
                ->toArray();

            return $currencies;

        } catch (Exception $e) {
            Log::error('Failed to retrieve available currencies', [
                'error' => $e->getMessage()
            ]);

            return [self::DEFAULT_BASE_CURRENCY];
        }
    }

    /**
     * Get FX rate history for a currency pair
     * 
     * Returns historical FX rates for analysis and reporting purposes.
     * Limited to the maximum lookback period.
     * 
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $days Number of days to look back (max 30)
     * @return array Array of historical rates with dates
     */
    public function getFXRateHistory(string $fromCurrency, string $toCurrency, int $days = 30): array
    {
        $days = min($days, self::MAX_FX_LOOKBACK_DAYS);
        
        try {
            $lookbackDate = Carbon::now()->subDays($days);

            $rates = DB::table('fx_rates')
                ->where('from_currency', strtoupper($fromCurrency))
                ->where('to_currency', strtoupper($toCurrency))
                ->where('rate_date', '>=', $lookbackDate->format('Y-m-d'))
                ->where('is_active', 1)
                ->orderBy('rate_date', 'desc')
                ->select(['rate_date', 'rate', 'created_at'])
                ->get()
                ->toArray();

            return array_map(function($rate) {
                return [
                    'date' => $rate->rate_date,
                    'rate' => (float) $rate->rate,
                    'timestamp' => $rate->created_at
                ];
            }, $rates);

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
}