<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PocketExpenseFXService
 * 
 * Handles foreign exchange conversion for pocket expenses with rate lookup,
 * commission calculation, and wallet base currency management.
 * 
 * Integrates with existing platform FX infrastructure using 30-day lookback
 * and commission calculation as per system constraints.
 */
class PocketExpenseFXService
{
    /**
     * Maximum lookback days for FX rate lookup as per system constraints.
     */
    private const MAX_LOOKBACK_DAYS = 30;

    /**
     * Default commission percentage if not configured.
     */
    private const DEFAULT_COMMISSION_PERCENT = 0.0;

    /**
     * Default base currency if wallet currency cannot be determined.
     */
    private const DEFAULT_BASE_CURRENCY = 'USD';

    /**
     * Get the wallet base currency for a client.
     * 
     * TODO: Implement actual client wallet base currency lookup from platform infrastructure.
     * This should query the client's wallet configuration to determine their base currency.
     * 
     * @param int $clientId
     * @return array ['currency' => string, 'symbol' => string]
     */
    public function getWalletBaseCurrency(int $clientId): array
    {
        try {
            // TODO: Replace with actual platform service call
            // Example: $walletService->getClientBaseCurrency($clientId)
            // For now, return default USD
            
            Log::info('Getting wallet base currency for client', ['client_id' => $clientId]);
            
            return [
                'currency' => self::DEFAULT_BASE_CURRENCY,
                'symbol' => '$'
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get wallet base currency', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'currency' => self::DEFAULT_BASE_CURRENCY,
                'symbol' => '$'
            ];
        }
    }

    /**
     * Get FX rate between two currencies for a specific date with 30-day lookback.
     * 
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param string $date Date in Y-m-d format
     * @return float|null Returns null if 'No FX Available' as per system constraints
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, string $date): ?float
    {
        try {
            // If currencies are the same, return 1.0
            if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
                return 1.0;
            }

            $targetDate = Carbon::parse($date);
            $maxLookbackDate = $targetDate->copy()->subDays(self::MAX_LOOKBACK_DAYS);

            // TODO: Replace with actual platform FX service call
            // Example: $fxService->getRate($fromCurrency, $toCurrency, $targetDate, $maxLookbackDate)
            
            Log::info('Looking up FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'max_lookback_date' => $maxLookbackDate->format('Y-m-d')
            ]);

            // Placeholder logic for demonstration
            // In real implementation, this would query the FX rate service
            // with lookback capability within the 30-day window
            
            // Simulate rate lookup with common currency pairs
            $mockRates = [
                'USD_EUR' => 0.85,
                'EUR_USD' => 1.18,
                'USD_GBP' => 0.73,
                'GBP_USD' => 1.37,
                'EUR_GBP' => 0.86,
                'GBP_EUR' => 1.16,
            ];

            $rateKey = strtoupper($fromCurrency) . '_' . strtoupper($toCurrency);
            
            if (isset($mockRates[$rateKey])) {
                return $mockRates[$rateKey];
            }

            // If no rate found within 30-day lookback, return null ('No FX Available')
            Log::warning('No FX rate found within lookback period', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'lookback_days' => self::MAX_LOOKBACK_DAYS
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error looking up FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Calculate converted amount with commission applied.
     * 
     * Commission formula: AdjustedRate = BaseRate x (1 - Comm%) as per system constraints.
     * 
     * @param float $amount
     * @param float $rate
     * @param float $commission Commission percentage (0-100)
     * @return float
     */
    public function calculateConvertedAmount(float $amount, float $rate, float $commission = 0.0): float
    {
        try {
            // Ensure commission is between 0 and 100
            $commission = max(0.0, min(100.0, $commission));
            
            // Convert commission percentage to decimal (e.g., 2.5% -> 0.025)
            $commissionDecimal = $commission / 100.0;
            
            // Apply commission formula: AdjustedRate = BaseRate x (1 - Comm%)
            $adjustedRate = $rate * (1 - $commissionDecimal);
            
            // Calculate converted amount
            $convertedAmount = $amount * $adjustedRate;
            
            Log::info('Calculated converted amount with commission', [
                'amount' => $amount,
                'base_rate' => $rate,
                'commission_percent' => $commission,
                'commission_decimal' => $commissionDecimal,
                'adjusted_rate' => $adjustedRate,
                'converted_amount' => $convertedAmount
            ]);
            
            return round($convertedAmount, 2);

        } catch (\Exception $e) {
            Log::error('Error calculating converted amount', [
                'amount' => $amount,
                'rate' => $rate,
                'commission' => $commission,
                'error' => $e->getMessage()
            ]);

            // Return original amount if calculation fails
            return $amount;
        }
    }

    /**
     * Convert expense amount with FX rate lookup and commission calculation.
     * Backend recalculation - do not trust frontend-only values as per system constraints.
     * 
     * @param array $expenseData
     * @param int $clientId
     * @return array Enhanced expense data with FX conversion details
     */
    public function convertExpenseAmount(array $expenseData, int $clientId): array
    {
        try {
            $walletCurrency = $this->getWalletBaseCurrency($clientId);
            $expenseCurrency = $expenseData['currency'] ?? '';
            $expenseAmount = (float) ($expenseData['amount'] ?? 0.0);
            $expenseDate = $expenseData['date'] ?? now()->format('Y-m-d');

            // Initialize conversion result
            $conversionResult = [
                'original_amount' => $expenseAmount,
                'original_currency' => $expenseCurrency,
                'wallet_currency' => $walletCurrency['currency'],
                'conversion_rate' => null,
                'converted_amount' => null,
                'commission_rate' => self::DEFAULT_COMMISSION_PERCENT,
                'fx_status' => 'no_conversion_needed'
            ];

            // If currencies are the same, no conversion needed
            if (strtoupper($expenseCurrency) === strtoupper($walletCurrency['currency'])) {
                $conversionResult['converted_amount'] = $expenseAmount;
                $conversionResult['conversion_rate'] = 1.0;
                $conversionResult['fx_status'] = 'same_currency';
                
                Log::info('No FX conversion needed - same currency', [
                    'expense_currency' => $expenseCurrency,
                    'wallet_currency' => $walletCurrency['currency']
                ]);
            } else {
                // Look up FX rate with 30-day lookback
                $fxRate = $this->getFXRate($expenseCurrency, $walletCurrency['currency'], $expenseDate);
                
                if ($fxRate === null) {
                    $conversionResult['fx_status'] = 'no_fx_available';
                    $conversionResult['converted_amount'] = null;
                    
                    Log::warning('No FX rate available for conversion', [
                        'from_currency' => $expenseCurrency,
                        'to_currency' => $walletCurrency['currency'],
                        'date' => $expenseDate
                    ]);
                } else {
                    // TODO: Get client-specific commission rate from platform configuration
                    $commissionRate = self::DEFAULT_COMMISSION_PERCENT;
                    
                    $convertedAmount = $this->calculateConvertedAmount($expenseAmount, $fxRate, $commissionRate);
                    
                    $conversionResult['conversion_rate'] = $fxRate;
                    $conversionResult['converted_amount'] = $convertedAmount;
                    $conversionResult['commission_rate'] = $commissionRate;
                    $conversionResult['fx_status'] = 'converted';
                    
                    Log::info('FX conversion completed', [
                        'original_amount' => $expenseAmount,
                        'original_currency' => $expenseCurrency,
                        'converted_amount' => $convertedAmount,
                        'target_currency' => $walletCurrency['currency'],
                        'fx_rate' => $fxRate,
                        'commission_rate' => $commissionRate
                    ]);
                }
            }

            // Merge conversion result with original expense data
            return array_merge($expenseData, [
                'fx_conversion' => $conversionResult
            ]);

        } catch (\Exception $e) {
            Log::error('Error converting expense amount', [
                'expense_data' => $expenseData,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            // Return original data with error status
            return array_merge($expenseData, [
                'fx_conversion' => [
                    'original_amount' => $expenseData['amount'] ?? 0.0,
                    'original_currency' => $expenseData['currency'] ?? '',
                    'wallet_currency' => $walletCurrency['currency'] ?? self::DEFAULT_BASE_CURRENCY,
                    'conversion_rate' => null,
                    'converted_amount' => null,
                    'commission_rate' => self::DEFAULT_COMMISSION_PERCENT,
                    'fx_status' => 'conversion_error'
                ]
            ]);
        }
    }
}