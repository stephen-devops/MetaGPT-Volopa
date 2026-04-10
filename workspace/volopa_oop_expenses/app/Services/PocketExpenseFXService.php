<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * PocketExpenseFXService
 * 
 * Handles FX conversion for pocket expenses with wallet currency lookup,
 * rate retrieval with 30-day lookback, and commission calculation.
 */
class PocketExpenseFXService
{
    /**
     * Maximum days to look back for FX rates.
     */
    private const MAX_LOOKBACK_DAYS = 30;

    /**
     * Default commission percentage if not configured.
     */
    private const DEFAULT_COMMISSION_PERCENT = 0.0;

    /**
     * Convert amount between currencies with FX rate lookup and commission calculation.
     *
     * @param float $amount The amount to convert
     * @param string $fromCurrency 3-letter ISO currency code (source)
     * @param string $toCurrency 3-letter ISO currency code (target)
     * @param string $date Date in Y-m-d format
     * @param int $clientId Client ID for commission configuration
     * @return array Contains converted_amount, fx_rate, commission_rate, status
     */
    public function convertAmount(float $amount, string $fromCurrency, string $toCurrency, string $date, int $clientId): array
    {
        try {
            // If same currency, no conversion needed
            if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
                return [
                    'converted_amount' => $amount,
                    'fx_rate' => 1.0,
                    'commission_rate' => 1.0,
                    'status' => 'no_conversion_needed'
                ];
            }

            // Get base FX rate with lookback
            $baseRate = $this->getFXRate($fromCurrency, $toCurrency, $date);
            
            if ($baseRate === null) {
                return [
                    'converted_amount' => null,
                    'fx_rate' => null,
                    'commission_rate' => null,
                    'status' => 'No FX Available'
                ];
            }

            // Get commission percentage for client
            $commissionPercent = $this->getClientCommissionPercent($clientId);
            
            // Apply commission to rate
            $adjustedRate = $this->applyCommission($baseRate, $commissionPercent);
            
            // Calculate converted amount
            $convertedAmount = $amount * $adjustedRate;

            return [
                'converted_amount' => round($convertedAmount, 2),
                'fx_rate' => $baseRate,
                'commission_rate' => $adjustedRate,
                'status' => 'success'
            ];

        } catch (\Exception $e) {
            Log::error('FX conversion failed', [
                'amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);

            return [
                'converted_amount' => null,
                'fx_rate' => null,
                'commission_rate' => null,
                'status' => 'conversion_error'
            ];
        }
    }

    /**
     * Get wallet base currency for a client.
     * 
     * @param int $clientId Client ID
     * @return string 3-letter ISO currency code
     */
    public function getWalletBaseCurrency(int $clientId): string
    {
        try {
            // TODO: Implement wallet base currency lookup via join chain
            // This should query the client's wallet configuration to get base currency
            // Query path: clients -> client_wallets -> wallet_currencies -> base_currency
            // For now, return USD as default
            
            // Placeholder implementation - replace with actual database query
            $response = Http::timeout(5)->get("/wallet-ccy-value/client/{$clientId}/base-currency");
            
            if ($response->successful()) {
                $data = $response->json();
                return $data['base_currency'] ?? 'USD';
            }
            
            Log::warning('Failed to get wallet base currency, using default USD', [
                'client_id' => $clientId,
                'status' => $response->status()
            ]);
            
            return 'USD'; // Default fallback
            
        } catch (\Exception $e) {
            Log::error('Error getting wallet base currency', [
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            
            return 'USD'; // Default fallback
        }
    }

    /**
     * Get FX rate with maximum 30-day lookback from expense date.
     *
     * @param string $fromCurrency 3-letter ISO currency code
     * @param string $toCurrency 3-letter ISO currency code  
     * @param string $date Date in Y-m-d format
     * @return float|null FX rate or null if not found
     */
    public function getFXRate(string $fromCurrency, string $toCurrency, string $date): ?float
    {
        try {
            $expenseDate = Carbon::createFromFormat('Y-m-d', $date);
            $fromCurrency = strtoupper($fromCurrency);
            $toCurrency = strtoupper($toCurrency);
            
            // Try to get rate for each day within lookback period
            for ($i = 0; $i <= self::MAX_LOOKBACK_DAYS; $i++) {
                $lookupDate = $expenseDate->copy()->subDays($i);
                $dateString = $lookupDate->format('Y-m-d');
                
                $rate = $this->getFXRateForDate($fromCurrency, $toCurrency, $dateString);
                
                if ($rate !== null) {
                    Log::info('FX rate found', [
                        'from_currency' => $fromCurrency,
                        'to_currency' => $toCurrency,
                        'expense_date' => $date,
                        'rate_date' => $dateString,
                        'lookback_days' => $i,
                        'rate' => $rate
                    ]);
                    
                    return $rate;
                }
            }
            
            Log::warning('No FX rate found within lookback period', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'expense_date' => $date,
                'lookback_days' => self::MAX_LOOKBACK_DAYS
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error getting FX rate', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Apply commission percentage to base FX rate.
     * Formula: AdjustedRate = BaseRate x (1 - Comm%)
     *
     * @param float $rate Base FX rate
     * @param float $commissionPercent Commission percentage (e.g., 2.5 for 2.5%)
     * @return float Adjusted rate with commission applied
     */
    public function applyCommission(float $rate, float $commissionPercent): float
    {
        // Convert percentage to decimal (e.g., 2.5% -> 0.025)
        $commissionDecimal = $commissionPercent / 100.0;
        
        // Apply commission formula: AdjustedRate = BaseRate x (1 - Comm%)
        $adjustedRate = $rate * (1 - $commissionDecimal);
        
        return $adjustedRate;
    }

    /**
     * Get FX rate for a specific date from external service.
     *
     * @param string $fromCurrency 3-letter ISO currency code
     * @param string $toCurrency 3-letter ISO currency code
     * @param string $date Date in Y-m-d format
     * @return float|null FX rate or null if not found
     */
    private function getFXRateForDate(string $fromCurrency, string $toCurrency, string $date): ?float
    {
        try {
            // TODO: Implement actual FX rate lookup from platform infrastructure
            // This should integrate with existing platform FX service/API
            // The endpoint pattern suggests /wallet-ccy-value might be the integration point
            
            $response = Http::timeout(5)->get('/wallet-ccy-value/fx-rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $date
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Check if rate is available in response
                if (isset($data['rate']) && is_numeric($data['rate'])) {
                    return (float) $data['rate'];
                }
            }
            
            // Rate not found for this date
            return null;
            
        } catch (\Exception $e) {
            Log::debug('FX rate lookup failed for date', [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Get commission percentage configured for a client.
     *
     * @param int $clientId Client ID
     * @return float Commission percentage
     */
    private function getClientCommissionPercent(int $clientId): float
    {
        try {
            // TODO: Implement client-specific commission configuration lookup
            // This should query client configuration table or service to get FX commission rate
            // For now, return default commission
            
            $response = Http::timeout(5)->get("/wallet-ccy-value/client/{$clientId}/fx-commission");
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['commission_percent']) && is_numeric($data['commission_percent'])) {
                    return (float) $data['commission_percent'];
                }
            }
            
            return self::DEFAULT_COMMISSION_PERCENT;
            
        } catch (\Exception $e) {
            Log::warning('Failed to get client commission percent, using default', [
                'client_id' => $clientId,
                'default_commission' => self::DEFAULT_COMMISSION_PERCENT,
                'error' => $e->getMessage()
            ]);
            
            return self::DEFAULT_COMMISSION_PERCENT;
        }
    }
}