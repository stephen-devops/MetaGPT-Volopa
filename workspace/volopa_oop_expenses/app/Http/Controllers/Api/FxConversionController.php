## Code: app/Http/Controllers/Api/FxConversionController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FxConversionResource;
use App\Services\FxConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class FxConversionController extends Controller
{
    /**
     * The FX conversion service instance.
     */
    private FxConversionService $fxConversionService;

    /**
     * Supported currency codes for validation.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR', 'KRW',
        'DKK', 'PLN', 'TWD', 'THB', 'MYR', 'HUF', 'CZK', 'ILS', 'CLP', 'PHP',
        'AED', 'COP', 'SAR', 'RON', 'BGN', 'HRK', 'ISK', 'LBP', 'EGP', 'JOD',
    ];

    /**
     * Maximum conversion amount allowed.
     */
    private const MAX_CONVERSION_AMOUNT = 999999.99;

    /**
     * Minimum conversion amount allowed.
     */
    private const MIN_CONVERSION_AMOUNT = 0.01;

    /**
     * Maximum days in the past for historical rates.
     */
    private const MAX_HISTORICAL_DAYS = 30;

    /**
     * Create a new controller instance.
     */
    public function __construct(FxConversionService $fxConversionService)
    {
        $this->fxConversionService = $fxConversionService;
        $this->middleware('auth:api');
        $this->middleware('client.context');
    }

    /**
     * Convert amount from one currency to another.
     */
    public function convert(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validator = Validator::make($request->all(), [
                'amount' => [
                    'required',
                    'numeric',
                    'min:' . self::MIN_CONVERSION_AMOUNT,
                    'max:' . self::MAX_CONVERSION_AMOUNT,
                    'decimal:0,2'
                ],
                'from' => [
                    'required',
                    'string',
                    'size:3',
                    'regex:/^[A-Z]{3}$/',
                    'in:' . implode(',', self::SUPPORTED_CURRENCIES)
                ],
                'to' => [
                    'required',
                    'string',
                    'size:3',
                    'regex:/^[A-Z]{3}$/',
                    'in:' . implode(',', self::SUPPORTED_CURRENCIES)
                ],
                'date' => [
                    'sometimes',
                    'date',
                    'date_format:Y-m-d',
                    'before_or_equal:' . now()->format('Y-m-d'),
                    'after_or_equal:' . now()->subDays(self::MAX_HISTORICAL_DAYS)->format('Y-m-d'),
                ],
            ], [
                'amount.required' => 'Amount is required for currency conversion',
                'amount.numeric' => 'Amount must be a valid number',
                'amount.min' => 'Amount must be at least ' . self::MIN_CONVERSION_AMOUNT,
                'amount.max' => 'Amount cannot exceed ' . number_format(self::MAX_CONVERSION_AMOUNT, 2),
                'amount.decimal' => 'Amount can have at most 2 decimal places',
                'from.required' => 'Source currency is required',
                'from.size' => 'Currency code must be exactly 3 characters',
                'from.regex' => 'Currency code must contain only uppercase letters',
                'from.in' => 'Source currency is not supported',
                'to.required' => 'Target currency is required',
                'to.size' => 'Currency code must be exactly 3 characters',
                'to.regex' => 'Currency code must contain only uppercase letters',
                'to.in' => 'Target currency is not supported',
                'date.date' => 'Date must be a valid date',
                'date.date_format' => 'Date must be in YYYY-MM-DD format',
                'date.before_or_equal' => 'Date cannot be in the future',
                'date.after_or_equal' => 'Date cannot be older than ' . self::MAX_HISTORICAL_DAYS . ' days',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Get validated data
            $amount = (float) $request->get('amount');
            $fromCurrency = strtoupper($request->get('from'));
            $toCurrency = strtoupper($request->get('to'));
            $date = $request->get('date', now()->format('Y-m-d'));

            Log::info('FX conversion requested', [
                'user_id' => $request->user()->id,
                'amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
            ]);

            // Perform currency conversion
            $conversionResult = $this->fxConversionService->convertAmount(
                $amount,
                $fromCurrency,
                $toCurrency,
                $date
            );

            if (!$conversionResult['success']) {
                Log::warning('FX conversion failed', [
                    'user_id' => $request->user()->id,
                    'amount' => $amount,
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'date' => $date,
                    'error' => $conversionResult['message'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $conversionResult['message'],
                    'error_code' => 'CONVERSION_FAILED',
                ], 400);
            }

            Log::info('FX conversion successful', [
                'user_id' => $request->user()->id,
                'amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'converted_amount' => $conversionResult['data']['converted_amount'],
                'exchange_rate' => $conversionResult['data']['exchange_rate'],
                'source' => $conversionResult['data']['source'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Currency conversion completed successfully',
                'data' => new FxConversionResource($conversionResult['data']),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error processing FX conversion request', [
                'user_id' => $request->user()->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process currency conversion',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'error_code' => 'CONVERSION_ERROR',
            ], 500);
        }
    }

    /**
     * Get exchange rates for a specific date and base currency.
     */
    public function getRates(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validator = Validator::make($request->all(), [
                'base' => [
                    'sometimes',
                    'string',
                    'size:3',
                    'regex:/^[A-Z]{3}$/',
                    'in:' . implode(',', self::SUPPORTED_CURRENCIES)
                ],
                'symbols' => [
                    'sometimes',
                    'string',
                    function ($attribute, $value, $fail) {
                        $currencies = array_map('trim', explode(',', strtoupper($value)));
                        foreach ($currencies as $currency) {
                            if (!in_array($currency, self::SUPPORTED_CURRENCIES)) {
                                $fail("Currency {$currency} is not supported");
                            }
                            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                                $fail("Currency {$currency} must be a 3-letter code");
                            }
                        }
                    }
                ],
                'date' => [
                    'sometimes',
                    'date',
                    'date_format:Y-m-d',
                    'before_or_equal:' . now()->format('Y-m-d'),
                    'after_or_equal:' . now()->subDays(self::MAX_HISTORICAL_DAYS)->format('Y-m-d'),
                ],
            ], [
                'base.size' => 'Base currency must be exactly 3 characters',
                'base.regex' => 'Base currency must contain only uppercase letters',
                'base.in' => 'Base currency is not supported',
                'symbols.string' => 'Symbols must be a comma-separated list of currency codes',
                'date.date' => 'Date must be a valid date',
                'date.date_format' => 'Date must be in YYYY-MM-DD format',
                'date.before_or_equal' => 'Date cannot be in the future',
                'date.after_or_equal' => 'Date cannot be older than ' . self::MAX_HISTORICAL_DAYS . ' days',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Get validated data with defaults
            $baseCurrency = strtoupper($request->get('base', 'USD'));
            $date = $request->get('date', now()->format('Y-m-d'));
            $requestedSymbols = $request->get('symbols');

            Log::info('FX rates requested', [
                'user_id' => $request->user()->id,
                'base_currency' => $baseCurrency,
                'date' => $date,
                'symbols' => $requestedSymbols,
            ]);

            // Get exchange rates
            $ratesResult = $this->fxConversionService->getRatesForDate($date, $baseCurrency);

            if (!$ratesResult['success']) {
                Log::warning('FX rates retrieval failed', [
                    'user_id' => $request->user()->id,
                    'base_currency' => $baseCurrency,
                    'date' => $date,
                    'error' => $ratesResult['message'],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $ratesResult['message'],
                    'error_code' => 'RATES_FAILED',
                ], 400);
            }

            $responseData = $ratesResult['data'];

            // Filter rates by requested symbols if provided
            if ($requestedSymbols) {
                $symbolsArray = array_map('trim', explode(',', strtoupper($requestedSymbols)));
                $filteredRates = [];
                
                foreach ($symbolsArray as $symbol) {
                    if (isset($responseData['rates'][$symbol])) {
                        $filteredRates[$symbol] = $responseData['rates'][$symbol];
                    }
                }
                
                $responseData['rates'] = $filteredRates;
                $responseData['requested_symbols'] = $symbolsArray;
                $responseData['filtered'] = true;
            } else {
                $responseData['filtered'] = false;
            }

            // Add metadata
            $responseData['total_rates'] = count($responseData['rates']);
            $responseData['request_timestamp'] = now()->toISOString();

            Log::info('FX rates retrieved successfully', [
                'user_id' => $request->user()->id,
                'base_currency' => $baseCurrency,
                'date' => $date,
                'total_rates' => $responseData['total_rates'],
                'source' => $responseData['source'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Exchange rates retrieved successfully',
                'data' => $responseData,
                'meta' => [
                    'base_currency' => $baseCurrency,
                    'rate_date' => $responseData['rate_date'],
                    'source' => $responseData['source'],
                    'total_rates' => $responseData['total_rates'],
                    'filtered' => $responseData['filtered'],
                    'cache_status' => $responseData['source'] === 'cache' ? 'cached' : 'fresh',
                    'retrieved_at' => now()->toISOString(),
                    'supported_currencies' => self::SUPPORTED_CURRENCIES,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error processing FX rates request', [
                'user_id' => $request->user()->id,
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve exchange rates',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'error_code' => 'RATES_ERROR',
            ], 500);
        }
    }

    /**
     * Get supported currencies list.
     */
    public function getSupportedCurrencies(Request $request): JsonResponse
    {
        try {
            Log::info('Supported currencies requested', [
                'user_id' => $request->user()->id,
            ]);

            $currencies = [];
            foreach (self::SUPPORTED_CURRENCIES as $code) {
                $currencies[] = [
                    'code' => $code,
                    'symbol' => $this->getCurrencySymbol($code),
                    'name' => $this->getCurrencyName($code),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Supported currencies retrieved successfully',
                'data' => [
                    'currencies' => $currencies,
                    'total_count' => count($currencies),
                ],
                'meta' => [
                    'last_updated' => now()->toISOString(),
                    'version' => '1.0',
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error retrieving supported currencies', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve supported currencies',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get currency conversion history for the current user.
     */
    public function get