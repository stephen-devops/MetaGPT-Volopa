<?php

namespace App\Services;

use App\Models\PocketExpense;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

/**
 * Pocket Expense FX Service
 * 
 * Handles foreign exchange conversion for pocket expenses.
 * Integrates with platform FX infrastructure for rate lookup and conversion calculations.
 * Implements commission-based rate adjustments and wallet base currency determination.
 */
class PocketExpenseFXService
{
    /**
     * Maximum lookback period for FX rate lookup (30 days as per system constraints).
     *
     * @var int
     */
    private const FX_RATE_MAX_LOOKBACK_DAYS = 30;

    /**
     * Default commission percentage for FX conversion.
     *
     * @var float
     */
    private const DEFAULT_COMMISSION_PERCENTAGE = 0.025; // 2.5%

    /**
     * Default base currency when wallet currency cannot be determined.
     *
     * @var string
     */
    private const DEFAULT_BASE_CURRENCY = 'USD';

    /**
     * FX rate API timeout in seconds.
     *
     * @var int
     */
    private const FX_API_TIMEOUT = 10;

    /**
     * Get the wallet base currency for a client.
     * 
     * @param int $clientId
     * @return array
     */
    public function getWalletBaseCurrency(int $clientId): array
    {
        try {
            // TODO: Integrate with wallet-ccy-value service to get client's base currency
            // This should query the platform's wallet service to determine the client's base currency
            Log::info("Getting wallet base currency for client: {$clientId}");
            
            // Placeholder implementation - should be replaced with actual service integration
            $baseCurrency = self::DEFAULT_BASE_CURRENCY;
            $currencyName = 'US Dollar';
            
            return [
                'success' => true,
                'currency' => $baseCurrency,
                'currency_name' => $currencyName,
                'client_id' => $clientId,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get wallet base currency for client {$clientId}: " . $e->getMessage());
            
            return [
                'success' => false,
                'currency' => self::DEFAULT_BASE_CURRENCY,
                'currency_name' => 'US Dollar',
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get FX rate between two currencies for a specific date.
     * Implements max 30-day lookback constraint.
     * 
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param \Carbon\Carbon $date
     * @return float|null
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        try {
            // Same currency, no conversion needed
            if ($fromCurrency === $toCurrency) {
                return 1.0;
            }

            // Validate currencies are 3-letter ISO codes
            if (strlen($fromCurrency) !== 3 || strlen($toCurrency) !== 3) {
                Log::warning("Invalid currency codes: from={$fromCurrency}, to={$toCurrency}");
                return null;
            }

            // Check if date is within allowed lookback period
            $maxLookbackDate = now()->subDays(self::FX_RATE_MAX_LOOKBACK_DAYS);
            if ($date->lt($maxLookbackDate)) {
                Log::info("Date {$date->format('Y-m-d')} is beyond {$maxLookbackDate->format('Y-m-d')} max lookback period");
                return null;
            }

            Log::info("Getting FX rate: {$fromCurrency} to {$toCurrency} for date {$date->format('Y-m-d')}");

            // TODO: Integrate with platform FX rate service
            // This should query the platform's FX service for historical rates
            // Try to get rate for the specific date, with fallback to previous days within lookback period
            
            $rate = $this->fetchFXRateFromService($fromCurrency, $toCurrency, $date);
            
            if ($rate !== null) {
                Log::info("Retrieved FX rate: {$rate} for {$fromCurrency}/{$toCurrency} on {$date->format('Y-m-d')}");
                return $rate;
            }

            // Try lookback within allowed period
            for ($i = 1; $i <= self::FX_RATE_MAX_LOOKBACK_DAYS; $i++) {
                $lookbackDate = $date->copy()->subDays($i);
                
                if ($lookbackDate->lt($maxLookbackDate)) {
                    break; // Beyond allowed lookback period
                }
                
                $rate = $this->fetchFXRateFromService($fromCurrency, $toCurrency, $lookbackDate);
                
                if ($rate !== null) {
                    Log::info("Retrieved FX rate via lookback: {$rate} for {$fromCurrency}/{$toCurrency} on {$lookbackDate->format('Y-m-d')}");
                    return $rate;
                }
            }

            Log::warning("No FX rate found for {$fromCurrency}/{$toCurrency} within lookback period from {$date->format('Y-m-d')}");
            return null;

        } catch (\Exception $e) {
            Log::error("Error getting FX rate for {$fromCurrency}/{$toCurrency} on {$date->format('Y-m-d')}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate conversion amount with commission adjustment.
     * Uses formula: AdjustedRate = BaseRate x (1 - Comm%)
     * 
     * @param float $amount
     * @param float $rate
     * @param float $commission
     * @return float
     */
    public function calculateConversion(float $amount, float $rate, float $commission = self::DEFAULT_COMMISSION_PERCENTAGE): float
    {
        try {
            // Validate inputs
            if ($amount < 0) {
                Log::warning("Negative amount provided for conversion: {$amount}");
                $amount = abs($amount); // Use absolute value for calculation
            }

            if ($rate <= 0) {
                Log::error("Invalid FX rate provided: {$rate}");
                throw new \InvalidArgumentException("FX rate must be greater than 0");
            }

            if ($commission < 0 || $commission > 1) {
                Log::warning("Invalid commission percentage: {$commission}, using default");
                $commission = self::DEFAULT_COMMISSION_PERCENTAGE;
            }

            // Apply commission formula: AdjustedRate = BaseRate x (1 - Comm%)
            $adjustedRate = $rate * (1 - $commission);
            
            // Calculate converted amount
            $convertedAmount = $amount * $adjustedRate;
            
            Log::info("FX conversion: {$amount} * {$adjustedRate} (base: {$rate}, comm: {$commission}) = {$convertedAmount}");
            
            return round($convertedAmount, 2);

        } catch (\Exception $e) {
            Log::error("Error calculating FX conversion: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convert expense amount to client's base currency with FX calculation.
     * 
     * @param PocketExpense $expense
     * @return array
     */
    public function convertExpenseAmount(PocketExpense $expense): array
    {
        try {
            Log::info("Converting expense amount for expense ID: {$expense->id}");

            // Get client's wallet base currency
            $walletCurrency = $this->getWalletBaseCurrency($expense->client_id);
            
            if (!$walletCurrency['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to determine wallet base currency',
                    'original_amount' => $expense->amount,
                    'original_currency' => $expense->currency,
                    'converted_amount' => null,
                    'base_currency' => null,
                    'fx_rate' => null,
                    'commission' => null,
                    'conversion_date' => null,
                ];
            }

            $baseCurrency = $walletCurrency['currency'];
            $expenseDate = Carbon::parse($expense->date);

            // Same currency, no conversion needed
            if ($expense->currency === $baseCurrency) {
                return [
                    'success' => true,
                    'original_amount' => $expense->amount,
                    'original_currency' => $expense->currency,
                    'converted_amount' => $expense->amount,
                    'base_currency' => $baseCurrency,
                    'fx_rate' => 1.0,
                    'commission' => 0.0,
                    'conversion_date' => $expenseDate->format('Y-m-d'),
                    'no_conversion_needed' => true,
                ];
            }

            // Get FX rate
            $fxRate = $this->getFXRate($expense->currency, $baseCurrency, $expenseDate);
            
            if ($fxRate === null) {
                return [
                    'success' => false,
                    'error' => 'No FX Available',
                    'original_amount' => $expense->amount,
                    'original_currency' => $expense->currency,
                    'converted_amount' => null,
                    'base_currency' => $baseCurrency,
                    'fx_rate' => null,
                    'commission' => null,
                    'conversion_date' => $expenseDate->format('Y-m-d'),
                ];
            }

            // Calculate conversion with commission
            $commission = self::DEFAULT_COMMISSION_PERCENTAGE;
            $convertedAmount = $this->calculateConversion($expense->amount, $fxRate, $commission);

            return [
                'success' => true,
                'original_amount' => $expense->amount,
                'original_currency' => $expense->currency,
                'converted_amount' => $convertedAmount,
                'base_currency' => $baseCurrency,
                'fx_rate' => $fxRate,
                'adjusted_rate' => $fxRate * (1 - $commission),
                'commission' => $commission,
                'conversion_date' => $expenseDate->format('Y-m-d'),
                'no_conversion_needed' => false,
            ];

        } catch (\Exception $e) {
            Log::error("Error converting expense amount for expense {$expense->id}: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'original_amount' => $expense->amount,
                'original_currency' => $expense->currency,
                'converted_amount' => null,
                'base_currency' => null,
                'fx_rate' => null,
                'commission' => null,
                'conversion_date' => null,
            ];
        }
    }

    /**
     * Fetch FX rate from external service.
     * Private helper method for actual API integration.
     * 
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param \Carbon\Carbon $date
     * @return float|null
     */
    private function fetchFXRateFromService(string $fromCurrency, string $toCurrency, Carbon $date): ?float
    {
        try {
            // TODO: Replace with actual platform FX service integration
            // This should make HTTP requests to the platform's FX rate API
            // Example implementation structure:
            
            /*
            $response = Http::timeout(self::FX_API_TIMEOUT)
                ->get(config('services.fx.base_url') . '/rates', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'date' => $date->format('Y-m-d'),
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['rate'] ?? null;
            }

            Log::warning("FX service returned non-successful response: " . $response->status());
            return null;
            */

            // Placeholder implementation for development/testing
            // Returns mock rates based on common currency pairs
            $mockRates = [
                'USDEUR' => 0.85,
                'EURUSD' => 1.18,
                'GBPUSD' => 1.30,
                'USDGBP' => 0.77,
                'USDCAD' => 1.25,
                'CADUSD' => 0.80,
                'USDAUD' => 1.35,
                'AUDUSD' => 0.74,
            ];

            $currencyPair = $fromCurrency . $toCurrency;
            $rate = $mockRates[$currencyPair] ?? null;

            if ($rate === null) {
                // Try reverse pair and calculate reciprocal
                $reversePair = $toCurrency . $fromCurrency;
                $reverseRate = $mockRates[$reversePair] ?? null;
                
                if ($reverseRate !== null && $reverseRate != 0) {
                    $rate = 1 / $reverseRate;
                }
            }

            return $rate;

        } catch (\Exception $e) {
            Log::error("Error fetching FX rate from service: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate if a currency code is supported.
     * 
     * @param string $currencyCode
     * @return bool
     */
    public function isCurrencySupported(string $currencyCode): bool
    {
        // TODO: Integrate with platform currency validation
        // This should check against the platform's allowed currency list
        
        // Placeholder implementation with common currencies
        $supportedCurrencies = [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 
            'SEK', 'NOK', 'DKK', 'NZD', 'SGD', 'HKD'
        ];
        
        return in_array(strtoupper($currencyCode), $supportedCurrencies);
    }

    /**
     * Get commission percentage for FX conversion.
     * Can be configured per client or use default.
     * 
     * @param int $clientId
     * @return float
     */
    public function getCommissionPercentage(int $clientId): float
    {
        try {
            // TODO: Implement client-specific commission rates
            // This should query client configuration for custom FX commission rates
            
            Log::info("Getting FX commission percentage for client: {$clientId}");
            
            // Placeholder - return default commission
            return self::DEFAULT_COMMISSION_PERCENTAGE;

        } catch (\Exception $e) {
            Log::error("Error getting commission percentage for client {$clientId}: " . $e->getMessage());
            return self::DEFAULT_COMMISSION_PERCENTAGE;
        }
    }

    /**
     * Recalculate FX conversion for an existing expense.
     * Used when expense amount or date is updated.
     * 
     * @param PocketExpense $expense
     * @return array
     */
    public function recalculateExpenseConversion(PocketExpense $expense): array
    {
        Log::info("Recalculating FX conversion for expense ID: {$expense->id}");
        
        // Backend must recalculate FX on save (do not trust frontend-only value)
        return $this->convertExpenseAmount($expense);
    }

    /**
     * Get FX conversion summary for multiple expenses.
     * Useful for batch operations or reporting.
     * 
     * @param \Illuminate\Support\Collection $expenses
     * @return array
     */
    public function getConversionSummary(\Illuminate\Support\Collection $expenses): array
    {
        try {
            $summary = [
                'total_expenses' => $expenses->count(),
                'conversions' => [],
                'currencies' => [],
                'total_original_amount' => 0,
                'total_converted_amount' => 0,
                'errors' => [],
            ];

            foreach ($expenses as $expense) {
                $conversion = $this->convertExpenseAmount($expense);
                
                $summary['conversions'][] = [
                    'expense_id' => $expense->id,
                    'conversion' => $conversion,
                ];

                if ($conversion['success']) {
                    $summary['total_original_amount'] += $conversion['original_amount'];
                    $summary['total_converted_amount'] += $conversion['converted_amount'] ?? 0;
                    
                    if (!in_array($conversion['original_currency'], $summary['currencies'])) {
                        $summary['currencies'][] = $conversion['original_currency'];
                    }
                } else {
                    $summary['errors'][] = [
                        'expense_id' => $expense->id,
                        'error' => $conversion['error'],
                    ];
                }
            }

            return $summary;

        } catch (\Exception $e) {
            Log::error("Error generating FX conversion summary: " . $e->getMessage());
            
            return [
                'total_expenses' => 0,
                'conversions' => [],
                'currencies' => [],
                'total_original_amount' => 0,
                'total_converted_amount' => 0,
                'errors' => [
                    ['error' => $e->getMessage()]
                ],
            ];
        }
    }
}