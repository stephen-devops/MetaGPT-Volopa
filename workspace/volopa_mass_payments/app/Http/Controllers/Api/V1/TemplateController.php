## Code: app/Http/Controllers/Api/V1/TemplateController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TemplateGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Exception;

class TemplateController extends Controller
{
    /**
     * The template generation service instance.
     */
    private TemplateGenerationService $templateGenerationService;

    /**
     * Supported currencies for template generation.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'CAD', 'JPY',
        'CNY', 'THB', 'MYR', 'IDR', 'PHP', 'VND'
    ];

    /**
     * Supported template types.
     */
    private const TEMPLATE_TYPES = [
        'basic', 'detailed', 'international', 'domestic', 'custom'
    ];

    /**
     * Create a new controller instance.
     *
     * @param TemplateGenerationService $templateGenerationService
     */
    public function __construct(TemplateGenerationService $templateGenerationService)
    {
        $this->templateGenerationService = $templateGenerationService;
        
        // Apply auth middleware to all methods
        $this->middleware('auth:api');
        
        // Apply throttle middleware to prevent abuse
        $this->middleware('throttle:60,1');
        
        // Apply client scoping middleware
        $this->middleware('client.scope');
        
        // Apply permission middleware
        $this->middleware('permission:mass_payments.download_template');
    }

    /**
     * Download CSV template for mass payments.
     *
     * @param Request $request
     * @return Response|JsonResponse
     */
    public function download(Request $request): Response|JsonResponse
    {
        Log::info('Template download requested', [
            'user_id' => $request->user()?->id,
            'client_id' => $request->user()?->client_id,
            'request_params' => $request->query()
        ]);

        try {
            // Validate request parameters
            $validator = $this->validateDownloadRequest($request);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            // Extract parameters with defaults
            $currency = $validated['currency'] ?? 'USD';
            $templateType = $validated['template_type'] ?? 'basic';
            $includeSampleData = $validated['include_sample_data'] ?? true;
            $includeDescriptions = $validated['include_descriptions'] ?? true;
            $beneficiaryIds = $validated['beneficiary_ids'] ?? [];

            // Check if user wants pre-populated beneficiary data
            if (!empty($beneficiaryIds)) {
                return $this->downloadWithBeneficiaries(
                    $request,
                    $currency,
                    $templateType,
                    $beneficiaryIds
                );
            }

            // Generate standard template
            $response = $this->templateGenerationService->generateTemplate(
                $currency,
                $templateType,
                $includeSampleData,
                $includeDescriptions
            );

            Log::info('Template downloaded successfully', [
                'user_id' => $request->user()?->id,
                'client_id' => $request->user()?->client_id,
                'currency' => $currency,
                'template_type' => $templateType,
                'include_sample_data' => $includeSampleData,
                'include_descriptions' => $includeDescriptions
            ]);

            return $response;

        } catch (Exception $e) {
            Log::error('Template download failed', [
                'user_id' => $request->user()?->id,
                'client_id' => $request->user()?->client_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available template types.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTemplateTypes(Request $request): JsonResponse
    {
        Log::info('Template types requested', [
            'user_id' => $request->user()?->id,
            'client_id' => $request->user()?->client_id
        ]);

        try {
            $templateTypes = $this->templateGenerationService->getAvailableTemplateTypes();

            return response()->json([
                'success' => true,
                'data' => [
                    'template_types' => $templateTypes,
                    'default_type' => 'basic'
                ],
                'meta' => [
                    'total_count' => count($templateTypes),
                    'timestamp' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get template types', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve template types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get supported currencies for template generation.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSupportedCurrencies(Request $request): JsonResponse
    {
        Log::info('Supported currencies requested', [
            'user_id' => $request->user()?->id,
            'client_id' => $request->user()?->client_id
        ]);

        try {
            $supportedCurrencies = $this->templateGenerationService->getSupportedCurrencies();

            // Add currency information
            $currencyData = [];
            foreach ($supportedCurrencies as $currency) {
                $currencyData[$currency] = [
                    'code' => $currency,
                    'name' => $this->getCurrencyName($currency),
                    'symbol' => $this->getCurrencySymbol($currency),
                    'decimal_places' => $this->getCurrencyDecimalPlaces($currency),
                    'template_type' => $this->getDefaultTemplateType($currency)
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'currencies' => $currencyData,
                    'default_currency' => 'USD'
                ],
                'meta' => [
                    'total_count' => count($supportedCurrencies),
                    'timestamp' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get supported currencies', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve supported currencies',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get template field definitions for a specific currency.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTemplateFields(Request $request): JsonResponse
    {
        Log::info('Template fields requested', [
            'user_id' => $request->user()?->id,
            'client_id' => $request->user()?->client_id,
            'currency' => $request->query('currency', 'USD')
        ]);

        try {
            // Validate currency parameter
            $currency = strtoupper($request->query('currency', 'USD'));
            
            if (!in_array($currency, self::SUPPORTED_CURRENCIES)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unsupported currency',
                    'error' => "Currency {$currency} is not supported"
                ], 400);
            }

            // Get template field definitions
            $fields = $this->getTemplateFieldDefinitions($currency);

            return response()->json([
                'success' => true,
                'data' => [
                    'currency' => $currency,
                    'fields' => $fields,
                    'field_count' => count($fields)
                ],
                'meta' => [
                    'timestamp' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Failed to get template fields', [
                'user_id' => $request->user()?->id,
                'currency' => $request->query('currency'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve template fields',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download template with pre-populated beneficiary data.
     *
     * @param Request $request
     * @param string $currency
     * @param string $templateType
     * @param array $beneficiaryIds
     * @return Response|JsonResponse
     */
    private function downloadWithBeneficiaries(
        Request $request,
        string $currency,
        string $templateType,
        array $beneficiaryIds
    ): Response|JsonResponse {
        try {
            $clientId = $request->user()->client_id;

            $response = $this->templateGenerationService->generateTemplateWithBeneficiaries(
                $clientId,
                $currency,
                $beneficiaryIds,
                $templateType
            );

            Log::info('Template with beneficiaries downloaded successfully', [
                'user_id' => $request->user()->id,
                'client_id' => $clientId,
                'currency' => $currency,
                'template_type' => $templateType,
                'beneficiary_count' => count($beneficiaryIds)
            ]);

            return $response;

        } catch (Exception $e) {
            Log::error('Template with beneficiaries download failed', [
                'user_id' => $request->user()->id,
                'currency' => $currency,
                'template_type' => $templateType,
                'beneficiary_count' => count($beneficiaryIds),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Validate download request parameters.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Validation\Validator
     */
    private function validateDownloadRequest(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->query(), [
            'currency' => [
                'nullable',
                'string',
                'size:3',
                Rule::in(self::SUPPORTED_CURRENCIES)
            ],
            'template_type' => [
                'nullable',
                'string',
                Rule::in(self::TEMPLATE_TYPES)
            ],
            'include_sample_data' => [
                'nullable',
                'boolean'
            ],
            'include_descriptions' => [
                'nullable',
                'boolean'
            ],
            'beneficiary_ids' => [
                'nullable',
                'array',
                'max:100' // Limit to 100 beneficiaries per template
            ],
            'beneficiary_ids.*' => [
                'required_with:beneficiary_ids',
                'uuid',
                'exists:beneficiaries,id,client_id,' . $request->user()?->client_id . ',deleted_at,NULL'
            ]
        ], [
            'currency.size' => 'Currency code must be exactly 3 characters',
            'currency.in' => 'Unsupported currency. Supported currencies: ' . implode(', ', self::SUPPORTED_CURRENCIES),
            'template_type.in' => 'Invalid template type. Supported types: ' . implode(', ', self::TEMPLATE_TYPES),
            'beneficiary_ids.max' => 'Maximum 100 beneficiaries allowed per template',
            'beneficiary_ids.*.uuid' => 'Invalid beneficiary ID format',
            'beneficiary_ids.*.exists' => 'Beneficiary not found or does not belong to your organization'
        ]);
    }

    /**
     * Get currency name for display.
     *
     * @param string $currency
     * @return string
     */
    private function getCurrencyName(string $currency): string
    {
        $currencyNames = [
            'USD' => 'US Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            'SGD' => 'Singapore Dollar',
            'HKD' => 'Hong Kong Dollar',
            'AUD' => 'Australian Dollar',
            'CAD' => 'Canadian Dollar',
            'JPY' => 'Japanese Yen',
            'CNY' => 'Chinese Yuan',
            'THB' => 'Thai Baht',
            'MYR' => 'Malaysian Ringgit',
            'IDR' => 'Indonesian Rupiah',
            'PHP' => 'Philippine Peso',
            'VND' => 'Vietnamese Dong'
        ];

        return $currencyNames[$currency] ?? $currency;
    }

    /**
     * Get currency symbol for display.
     *
     * @param string $currency
     * @return string
     */
    private function getCurrencySymbol(string $currency): string
    {
        $currencySymbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'SGD' => 'S$',
            'HKD' => 'HK$',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'JPY' => '¥',
            'CNY' => '¥',
            'THB' => '฿',
            'MYR' => 'RM',
            'IDR' => 'Rp',
            'PHP' => '₱',
            'VND' => '₫'
        ];

        return $currencySymbols[$currency] ?? $currency;
    }

    /**
     * Get decimal places for currency formatting.
     *
     * @param string $currency
     * @return int
     */
    private function getCurrencyDecimalPlaces(string $currency): int
    {
        $zeroDecimalCurrencies = ['JPY', 'IDR', 'VND'];
        return in_array($currency, $zeroDecimalCurrencies) ? 0 : 2;
    }

    /**
     * Get default template type for currency.
     *
     * @param string $currency
     * @return string
     */
    private function getDefaultTemplateType(string $currency): string
    {
        $internationalCurrencies = ['USD', 'EUR', 'JPY', 'CNY'];
        return in_array($currency, $internationalCurrencies) ? 'international' : 'domestic';
    }

    /**
     * Get template field definitions for a specific currency.
     *
     * @param string $currency
     * @return array
     */
    private function getTemplateFieldDefinitions(string $currency): array
    {
        $baseFields = [
            'beneficiary_name' => [
                'type' => 'string',
                'required' => true,
                'max_length