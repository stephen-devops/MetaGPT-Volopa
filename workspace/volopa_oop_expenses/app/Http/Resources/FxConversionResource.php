## Code: app/Http/Resources/FxConversionResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class FxConversionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // Basic conversion data
            'original_amount' => $this->resource['original_amount'] ?? 0.0,
            'converted_amount' => $this->resource['converted_amount'] ?? 0.0,
            'from_currency' => $this->resource['from_currency'] ?? 'USD',
            'to_currency' => $this->resource['to_currency'] ?? 'USD',
            'exchange_rate' => $this->resource['exchange_rate'] ?? 1.0,
            
            // Date information
            'conversion_date' => $this->resource['conversion_date'] ?? now()->format('Y-m-d'),
            'rate_date' => $this->resource['rate_date'] ?? now()->format('Y-m-d'),
            'conversion_date_formatted' => $this->getFormattedDate($this->resource['conversion_date'] ?? now()->format('Y-m-d')),
            'rate_date_formatted' => $this->getFormattedDate($this->resource['rate_date'] ?? now()->format('Y-m-d')),
            
            // Rate source information
            'source' => $this->resource['source'] ?? 'api',
            'source_label' => $this->getSourceLabel($this->resource['source'] ?? 'api'),
            'source_description' => $this->getSourceDescription($this->resource['source'] ?? 'api'),
            
            // Formatted amounts for display
            'original_amount_formatted' => $this->getFormattedAmount(
                $this->resource['original_amount'] ?? 0.0,
                $this->resource['from_currency'] ?? 'USD'
            ),
            'converted_amount_formatted' => $this->getFormattedAmount(
                $this->resource['converted_amount'] ?? 0.0,
                $this->resource['to_currency'] ?? 'USD'
            ),
            
            // Currency symbols
            'from_currency_symbol' => $this->getCurrencySymbol($this->resource['from_currency'] ?? 'USD'),
            'to_currency_symbol' => $this->getCurrencySymbol($this->resource['to_currency'] ?? 'USD'),
            
            // Rate analysis
            'rate_analysis' => [
                'rate_direction' => $this->getRateDirection(),
                'rate_strength' => $this->getRateStrength(),
                'is_favorable' => $this->isFavorableRate(),
                'rate_quality' => $this->getRateQuality(),
                'rate_age' => $this->getRateAge(),
                'rate_age_formatted' => $this->getRateAgeFormatted(),
            ],
            
            // Conversion metadata
            'conversion_summary' => $this->getConversionSummary(),
            'conversion_accuracy' => $this->getConversionAccuracy(),
            'conversion_confidence' => $this->getConversionConfidence(),
            'is_same_currency' => $this->isSameCurrency(),
            'is_cross_rate' => $this->isCrossRate(),
            
            // Business logic flags
            'is_realtime_rate' => $this->isRealtimeRate(),
            'is_cached_rate' => $this->isCachedRate(),
            'is_historical_rate' => $this->isHistoricalRate(),
            'is_estimated_rate' => $this->isEstimatedRate(),
            'requires_approval' => $this->requiresApproval(),
            'has_commission' => $this->hasCommission(),
            
            // Commission and fees (if applicable)
            'commission' => $this->when(
                $this->hasCommission(),
                $this->getCommissionData()
            ),
            
            // Rate comparison (if available)
            'rate_comparison' => $this->when(
                $request->boolean('include_comparison', false),
                $this->getRateComparison()
            ),
            
            // Historical context (if requested)
            'historical_context' => $this->when(
                $request->boolean('include_history', false),
                $this->getHistoricalContext()
            ),
            
            // API provider information
            'api_provider' => $this->resource['api_provider'] ?? 'unknown',
            'api_provider_label' => $this->getApiProviderLabel(),
            'data_freshness' => $this->getDataFreshness(),
            'data_reliability' => $this->getDataReliability(),
            
            // Timestamps
            'cached_at' => $this->resource['cached_at'] ?? null,
            'cached_at_formatted' => $this->when(
                isset($this->resource['cached_at']),
                $this->getFormattedTimestamp($this->resource['cached_at'])
            ),
            'expires_at' => $this->getExpirationTime(),
            'expires_at_formatted' => $this->when(
                $this->getExpirationTime(),
                $this->getFormattedTimestamp($this->getExpirationTime())
            ),
            'retrieved_at' => now()->toISOString(),
            'retrieved_at_formatted' => now()->format('M j, Y g:i A T'),
            
            // Validation and quality checks
            'is_valid_conversion' => $this->isValidConversion(),
            'validation_warnings' => $this->getValidationWarnings(),
            'quality_score' => $this->getQualityScore(),
            'confidence_level' => $this->getConfidenceLevel(),
            
            // Display helpers
            'display_conversion' => $this->getDisplayConversion(),
            'display_rate' => $this->getDisplayRate(),
            'display_summary' => $this->getDisplaySummary(),
            'conversion_formula' => $this->getConversionFormula(),
            
            // Additional metadata
            'meta' => [
                'conversion_type' => $this->getConversionType(),
                'market_session' => $this->getMarketSession(),
                'trading_day' => $this->getTradingDay(),
                'weekend_adjustment' => $this->hasWeekendAdjustment(),
                'holiday_adjustment' => $this->hasHolidayAdjustment(),
                'volatility_warning' => $this->hasVolatilityWarning(),
                'rate_spread' => $this->getRateSpread(),
                'mid_market_rate' => $this->getMidMarketRate(),
                'is_major_pair' => $this->isMajorCurrencyPair(),
                'is_exotic_pair' => $this->isExoticCurrencyPair(),
                'liquidity_level' => $this->getLiquidityLevel(),
            ],
            
            // Links for HATEOAS
            'links' => [
                'refresh' => route('api.fx.convert') . '?' . http_build_query([
                    'from' => $this->resource['from_currency'] ?? 'USD',
                    'to' => $this->resource['to_currency'] ?? 'USD',
                    'amount' => $this->resource['original_amount'] ?? 0,
                    'date' => $this->resource['conversion_date'] ?? now()->format('Y-m-d'),
                ]),
                'historical' => route('api.fx.rates') . '?' . http_build_query([
                    'base' => $this->resource['from_currency'] ?? 'USD',
                    'symbols' => $this->resource['to_currency'] ?? 'USD',
                    'start_date' => now()->subDays(30)->format('Y-m-d'),
                    'end_date' => $this->resource['conversion_date'] ?? now()->format('Y-m-d'),
                ]),
                'reverse' => route('api.fx.convert') . '?' . http_build_query([
                    'from' => $this->resource['to_currency'] ?? 'USD',
                    'to' => $this->resource['from_currency'] ?? 'USD',
                    'amount' => $this->resource['converted_amount'] ?? 0,
                    'date' => $this->resource['conversion_date'] ?? now()->format('Y-m-d'),
                ]),
            ],
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'timestamp' => now()->toISOString(),
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => app()->getLocale(),
                'resource_type' => 'fx_conversion',
                'version' => '1.0',
                'disclaimer' => 'Exchange rates are for informational purposes only and may not reflect actual trading rates.',
                'terms' => 'Rates subject to change without notice. Not guaranteed for accuracy.',
            ],
        ];
    }

    /**
     * Customize the response for a request.
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'fx_conversion');
        $response->header('X-Resource-Version', '1.0');
        $response->header('X-Rate-Source', $this->resource['source'] ?? 'unknown');
        $response->header('X-Rate-Date', $this->resource['rate_date'] ?? now()->format('Y-m-d'));
        
        // Cache headers for rate data
        $cacheTime = $this->getCacheTime();
        if ($cacheTime > 0) {
            $response->header('Cache-Control', "public, max-age={$cacheTime}");
            $response->header('Expires', now()->addSeconds($cacheTime)->toRfc7231String());
        }
    }

    /**
     * Get formatted date for display.
     */
    private function getFormattedDate(string $date): string
    {
        try {
            return Carbon::parse($date)->format('M j, Y');
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Get formatted timestamp for display.
     */
    private function getFormattedTimestamp(string $timestamp): string
    {
        try {
            return Carbon::parse($timestamp)->format('M j, Y g:i A T');
        } catch (\Exception $e) {
            return $timestamp;
        }
    }

    /**
     * Get formatted amount with currency symbol.
     */
    private function getFormattedAmount(float $amount, string $currency): string
    {
        $symbol = $this->getCurrencySymbol($currency);
        return $symbol . ' ' . number_format(abs($amount), 2);
    }

    /**
     * Get currency symbol for display purposes.
     */
    private function getCurrencySymbol(string $currency): string
    {
        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
            'CAD' => 'C$', 'AUD' => 'A$', 'CHF' => 'Fr', 'CNY' => '¥',
            'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr', 'PLN' => 'zł',
            'CZK' => 'Kč', 'HUF' => 'Ft', 'RUB' => '₽', 'INR' => '₹',
            'BRL' => 'R$', 'MXN' => '$', 'ZAR' => 'R', 'KRW' => '₩',
            'SGD' => 'S$', 'HKD' => 'HK$', 'NZD' => 'NZ$', 'TRY' => '₺',
            'THB' => '฿', 'MYR' => 'RM', 'PHP' => '₱', 'AED' => 'د.إ',
            'SAR' => '﷼', 'JOD' => 'د.ا', 'LBP' => '£', 'EGP' => '£',
        ];

        return $symbols[$currency] ?? $currency;
    }

    /**
     * Get source label for display.
     */
    private function getSourceLabel(string $source): string
    {
        return match ($source) {
            'api' => 'Live API',
            'cache' => 'Cached Rate',
            'direct' => 'Direct Rate',
            'fallback' => 'Fallback Rate',
            'manual' => 'Manual Rate',
            default => ucfirst($source),
        };
    }

    /**
     * Get source description.
     */
    private function getSourceDescription(string $source): string
    {
        return match ($source) {
            'api' => 'Real-time rate from external API',
            'cache' => 'Previously retrieved and cached rate',
            'direct' => 'No conversion needed (same currency)',
            'fallback' => 'Backup rate when primary source unavailable',
            'manual' => 'Manually configured rate',
            default => 'Rate source not specified',
        };
    }

    /**
     * Get rate direction (strengthening/weakening).
     */
    private function getRateDirection(): string
    {
        $rate = $this->resource['exchange_rate'] ?? 1.0;
        
        if ($rate > 1.0) {
            return 'strengthening';
        } elseif ($rate < 1.0) {
            return 'weakening';
        } else {
            return 'neutral';
        }
    }

    /**
     * Get rate strength assessment.
     */
    private function getRateStrength(): string
    {
        $rate = $this->resource['exchange_rate'] ?? 1.0;
        
        if ($rate >= 1.5) {
            return 'very_strong';
        } elseif ($rate >= 1.2) {
            return 'strong';
        } elseif ($rate >= 0.8) {
            return 'moderate';
        } elseif ($rate >= 0.5) {
            return 'weak';
        } else {
            return 'very_weak';
        }
    }

    /**
     * Check if the rate is favorable.
     */
    private function isFavorableRate(): bool
    {
        // This is a simplified assessment - in reality, this would depend on business context
        $rate = $this->resource['exchange_rate'] ?? 1.0;
        return $rate >= 1.0;
    }

    /**
     * Get rate quality assessment.
     */
    private function getRateQuality(): string
    {
        $source = $this->resource['source'] ?? 'unknown';
        
        return match ($source) {
            'api' => 'high',
            'cache' => 'good',
            'direct' => 'perfect',
            'fallback' => 'fair',
            'manual' => 'variable',
            default => 'unknown',
        };
    }

    /**
     * Get rate age in minutes.
     */
    private function getRateAge(): int
    {
        $rateDate = $this->resource['rate_date'] ?? now()->format('Y-m-d');
        $cachedAt = $this->resource['cached_at'] ?? null;
        
        if ($cachedAt) {
            return Carbon::parse($cachedAt)->diffInMinutes(now());
        }
        
        return Carbon::parse($rateDate)->diffInMinutes(now());
    }

    /**
     * Get formatted rate age.
     */
    private function getRateAgeFormatted(): string
    {
        $ageMinutes = $this->getRateAge();
        
        if ($ageMinutes < 60) {
            return $ageMinutes . '