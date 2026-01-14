Looking at the context and requirements, I need to implement the `TemplateController.php` file. This controller handles the download of CSV templates for mass payment uploads. Based on the design patterns and data structures provided, this controller should integrate with the TemplateService and follow the established architectural patterns.

## Code: app/Http/Controllers/Api/V1/TemplateController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DownloadTemplateRequest;
use App\Services\TemplateService;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Exception;

class TemplateController extends Controller
{
    /**
     * The template service.
     */
    private TemplateService $templateService;

    /**
     * Default template format.
     */
    private const DEFAULT_FORMAT = 'csv';

    /**
     * Default template type.
     */
    private const DEFAULT_TEMPLATE_TYPE = 'basic';

    /**
     * Cache TTL for templates in minutes.
     */
    private const TEMPLATE_CACHE_TTL = 30;

    /**
     * Supported currencies for templates.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
        'SEK', 'NZD', 'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'ZAR',
        'INR', 'BRL', 'PLN', 'CZK', 'HUF', 'ILS', 'KRW', 'TWD',
        'THB', 'MYR', 'PHP', 'IDR', 'VND', 'AED', 'SAR', 'EGP',
    ];

    /**
     * Supported template formats.
     */
    private const SUPPORTED_FORMATS = ['csv', 'xlsx', 'json'];

    /**
     * Supported template types.
     */
    private const SUPPORTED_TEMPLATE_TYPES = ['basic', 'detailed', 'with_beneficiaries'];

    /**
     * Maximum download requests per hour per user.
     */
    private const MAX_DOWNLOADS_PER_HOUR = 50;

    /**
     * Create a new controller instance.
     */
    public function __construct(TemplateService $templateService)
    {
        $this->templateService = $templateService;
        
        // Apply authentication middleware
        $this->middleware(['auth:api', 'volopa.auth']);
        
        // Apply rate limiting - more generous for template downloads
        $this->middleware('throttle:' . self::MAX_DOWNLOADS_PER_HOUR . ',60')->only(['download']);
        $this->middleware('throttle:120,1')->only(['preview', 'headers']);
    }

    /**
     * Download a mass payment template.
     */
    public function download(DownloadTemplateRequest $request): Response|JsonResponse
    {
        try {
            $validated = $request->validated();

            Log::info('Template download started', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'currency' => $validated['currency'],
                'format' => $validated['format'],
                'template_type' => $validated['template_type'],
                'include_beneficiaries' => $validated['include_beneficiaries'] ?? false,
            ]);

            // Check download rate limiting
            if (!$this->checkDownloadRateLimit()) {
                return $this->errorResponse('Download rate limit exceeded. Please try again later.', 429);
            }

            // Generate cache key for template
            $cacheKey = $this->generateTemplateCacheKey($validated);

            // Try to get template from cache first
            $templateData = Cache::remember($cacheKey, now()->addMinutes(self::TEMPLATE_CACHE_TTL), function () use ($validated) {
                return $this->templateService->generateTemplate($validated);
            });

            // Increment download counter
            $this->incrementDownloadCounter();

            // Log successful download
            Log::info('Template download completed', [
                'user_id' => Auth::id(),
                'currency' => $validated['currency'],
                'format' => $validated['format'],
                'filename' => $templateData['filename'],
                'size' => $templateData['size'],
            ]);

            // Return file download response
            return response($templateData['content'])
                ->header('Content-Type', $templateData['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . $templateData['filename'] . '"')
                ->header('Content-Length', $templateData['size'])
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0')
                ->header('X-Template-Version', $templateData['metadata']['version'] ?? '1.0')
                ->header('X-Generated-At', $templateData['generated_at']);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Template download failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_params' => $validated ?? [],
            ]);

            return $this->errorResponse('Failed to generate template: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Preview template structure without downloading.
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $validated = $request->validate([
                'currency' => 'required|string|size:3|in:' . implode(',', self::SUPPORTED_CURRENCIES),
                'template_type' => 'nullable|string|in:' . implode(',', self::SUPPORTED_TEMPLATE_TYPES),
                'include_sample_data' => 'nullable|boolean',
                'sample_rows_count' => 'nullable|integer|min:1|max:10',
            ]);

            // Check authorization
            $policy = new MassPaymentFilePolicy();
            if (!$policy->downloadTemplate(Auth::user())) {
                return $this->errorResponse('You do not have permission to preview templates', 403);
            }

            // Set defaults
            $templateType = $validated['template_type'] ?? self::DEFAULT_TEMPLATE_TYPE;
            $currency = strtoupper($validated['currency']);

            // Get template headers
            $headers = $this->templateService->getTemplateHeaders($currency, $templateType);

            // Get sample data if requested
            $sampleData = [];
            if ($validated['include_sample_data'] ?? false) {
                $sampleParams = array_merge($validated, [
                    'format' => 'csv',
                    'template_type' => $templateType,
                    'sample_rows_count' => $validated['sample_rows_count'] ?? 3,
                ]);
                $sampleData = $this->templateService->getSampleData($sampleParams);
            }

            // Get currency requirements
            $currencyRequirements = $this->getCurrencyRequirements($currency);

            return response()->json([
                'success' => true,
                'message' => 'Template preview generated successfully',
                'data' => [
                    'currency' => $currency,
                    'template_type' => $templateType,
                    'headers' => $headers,
                    'sample_data' => $sampleData,
                    'currency_requirements' => $currencyRequirements,
                    'field_descriptions' => $this->getFieldDescriptions($headers, $currency),
                    'validation_rules' => $this->getValidationRules($currency),
                ],
                'meta' => [
                    'total_fields' => count($headers),
                    'required_fields' => $this->countRequiredFields($headers),
                    'optional_fields' => $this->countOptionalFields($headers),
                    'currency_specific_fields' => $this->countCurrencySpecificFields($headers, $currency),
                    'estimated_file_size' => $this->estimateFileSize($headers, 1000), // For 1000 rows
                ],
                'links' => [
                    'download_csv' => route('api.v1.templates.download', array_merge($validated, ['format' => 'csv'])),
                    'download_xlsx' => route('api.v1.templates.download', array_merge($validated, ['format' => 'xlsx'])),
                    'download_json' => route('api.v1.templates.download', array_merge($validated, ['format' => 'json'])),
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Template preview failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_params' => $validated ?? [],
            ]);

            return $this->errorResponse('Failed to generate template preview', 500);
        }
    }

    /**
     * Get template headers for a specific currency.
     */
    public function headers(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $validated = $request->validate([
                'currency' => 'required|string|size:3|in:' . implode(',', self::SUPPORTED_CURRENCIES),
                'template_type' => 'nullable|string|in:' . implode(',', self::SUPPORTED_TEMPLATE_TYPES),
                'include_descriptions' => 'nullable|boolean',
                'include_examples' => 'nullable|boolean',
            ]);

            // Check authorization
            $policy = new MassPaymentFilePolicy();
            if (!$policy->downloadTemplate(Auth::user())) {
                return $this->errorResponse('You do not have permission to access template headers', 403);
            }

            $currency = strtoupper($validated['currency']);
            $templateType = $validated['template_type'] ?? self::DEFAULT_TEMPLATE_TYPE;

            // Get headers from service
            $headers = $this->templateService->getTemplateHeaders($currency, $templateType);

            // Add descriptions if requested
            $headerData = [];
            foreach ($headers as $field => $description) {
                $headerData[$field] = [
                    'name' => $field,
                    'display_name' => $this->getDisplayName($field),
                    'description' => $description,
                    'required' => $this->isRequiredField($field, $currency),
                    'data_type' => $this->getFieldDataType($field),
                    'max_length' => $this->getFieldMaxLength($field),
                    'format' => $this->getFieldFormat($field),
                ];

                // Add descriptions if requested
                if ($validated['include_descriptions'] ?? true) {
                    $headerData[$field]['detailed_description'] = $this->getDetailedDescription($field, $currency);
                }

                // Add examples if requested
                if ($validated['include_examples'] ?? false) {
                    $headerData[$field]['examples'] = $this->getFieldExamples($field, $currency);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Template headers retrieved successfully',
                'data' => [
                    'currency' => $currency,
                    'template_type' => $templateType,
                    'headers' => $headerData,
                    'header_order' => array_keys($headers),
                ],
                'meta' => [
                    'total_headers' => count($headers),
                    'required_headers' => count(array_filter($headerData, fn($h) => $h['required'])),
                    'optional_headers' => count(array_filter($headerData, fn($h) => !$h['required'])),
                    'currency_specific_count' => $this->countCurrencySpecificFields($headers, $currency),
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Template headers request failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_params' => $validated ?? [],
            ]);

            return $this->errorResponse('Failed to retrieve template headers', 500);
        }
    }

    /**
     * Get supported currencies and their requirements.
     */
    public function currencies(): JsonResponse
    {
        try {
            // Check authorization
            $policy = new MassPaymentFilePolicy();
            if (!$policy->downloadTemplate(Auth::user())) {
                return $this->errorResponse('You do not have permission to access currency information', 403);
            }

            $currencyData = [];
            
            foreach (self::SUPPORTED_CURRENCIES as $currency) {
                $currencyData[$currency] = [
                    'code' => $currency,
                    'name' => $this->getCurrencyName($currency),
                    'requirements' => $this->getCurrencyRequirements($currency),
                    'template_types' => self::SUPPORTED_TEMPLATE_TYPES,
                    'supported_formats' => self::SUPPORTED_FORMATS,
                    'regulatory_notes' => $this->getRegulatoryNotes($currency),
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Supported currencies retrieved successfully',
                'data' => [
                    'currencies' => $currencyData,
                    'total_supported' => count(self::SUPPORTED_CURRENCIES),
                    'special_requirements' => [
                        'invoice_required' => ['INR'],
                        'incorporation_required' => ['TRY'],
                        'high_value_currencies' => ['USD', 'EUR', 'GBP'],
                    ],
                ],
                'meta' => [
                    'last_updated' => Cache::get('currency_list_updated', now()->subDays(7))->toISOString(),
                    'user_permissions' => [
                        'can_download_templates' => true,
                        'allowed_currencies' => $this->getUserAllowedCurrencies(),
                    ],
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Currencies request failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to retrieve currency information', 500);
        }
    }

    /**
     * Generate cache key for template.
     */
    private function generateTemplateCacheKey(array $params): string
    {
        $keyParts = [
            'template',
            $params['currency'],
            $params['format'] ?? self::DEFAULT_FORMAT,
            $params['template_type'] ?? self::DEFAULT_TEMPLATE_TYPE,
            $params['include_sample_data'] ?? false ? 'samples' : 'no-samples',
            $params['include_beneficiaries'] ?? false ? 'beneficiaries' : 'no-beneficiaries',
            $params['locale'] ?? 'en',
        ];

        return 'volopa:' . implode(':', $keyParts);
    }

    /**
     * Check download rate limiting.
     */
    