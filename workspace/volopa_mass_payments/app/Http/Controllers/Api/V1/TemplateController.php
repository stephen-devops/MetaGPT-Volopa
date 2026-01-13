## Code: app/Http/Controllers/Api/V1/TemplateController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Exception;
use InvalidArgumentException;

class TemplateController extends Controller
{
    /**
     * Template service instance
     */
    protected TemplateService $templateService;

    /**
     * Supported currencies for templates
     */
    protected array $supportedCurrencies;

    /**
     * Template formats
     */
    protected array $templateFormats;

    /**
     * Enable recipient templates flag
     */
    protected bool $enableRecipientTemplates;

    /**
     * Constructor
     *
     * @param TemplateService $templateService
     */
    public function __construct(TemplateService $templateService)
    {
        $this->templateService = $templateService;
        $this->supportedCurrencies = array_keys(config('mass-payments.supported_currencies', [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY'
        ]));
        $this->templateFormats = config('mass-payments.templates.template_formats', ['csv', 'xlsx']);
        $this->enableRecipientTemplates = config('mass-payments.templates.enable_recipient_templates', true);

        // Apply authentication middleware
        $this->middleware('auth:api');
        
        // Apply Volopa authentication middleware
        $this->middleware('volopa.auth');

        // Apply throttling for template downloads
        $this->middleware('throttle:' . config('mass-payments.security.rate_limit_per_minute', 60) . ',1');
    }

    /**
     * Download recipient template with existing beneficiaries
     *
     * @param Request $request
     * @param string $currency
     * @return BinaryFileResponse|Response
     */
    public function downloadRecipientTemplate(Request $request, string $currency): BinaryFileResponse|Response
    {
        try {
            // Validate currency parameter
            $currency = strtoupper(trim($currency));
            
            if (!$this->isValidCurrency($currency)) {
                Log::warning('Invalid currency requested for recipient template', [
                    'currency' => $currency,
                    'user_id' => Auth::id(),
                    'supported_currencies' => $this->supportedCurrencies,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid currency specified',
                    'error' => "Currency '{$currency}' is not supported. Supported currencies: " . implode(', ', $this->supportedCurrencies),
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if recipient templates are enabled
            if (!$this->enableRecipientTemplates) {
                Log::warning('Recipient template download attempted but feature is disabled', [
                    'currency' => $currency,
                    'user_id' => Auth::id(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Recipient templates are not enabled',
                ], Response::HTTP_FORBIDDEN);
            }

            // Get authenticated user and client ID
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                Log::warning('User without client ID attempted to download recipient template', [
                    'user_id' => $user->id,
                    'currency' => $currency,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'User must be associated with a client to download recipient templates',
                ], Response::HTTP_FORBIDDEN);
            }

            Log::info('Recipient template download requested', [
                'currency' => $currency,
                'user_id' => $user->id,
                'client_id' => $clientId,
            ]);

            // Generate recipient template
            $templatePath = $this->templateService->generateRecipientTemplate($currency, $clientId);

            if (!$templatePath || !file_exists($templatePath)) {
                Log::error('Failed to generate recipient template file', [
                    'currency' => $currency,
                    'client_id' => $clientId,
                    'template_path' => $templatePath,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate recipient template',
                    'error' => 'Template generation failed. Please try again.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Generate filename for download
            $filename = $this->generateTemplateFilename('recipient', $currency, $clientId);

            Log::info('Recipient template generated successfully', [
                'currency' => $currency,
                'client_id' => $clientId,
                'filename' => $filename,
                'file_size' => filesize($templatePath),
            ]);

            // Return file as download
            return response()->download($templatePath, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])->deleteFileAfterSend(true);

        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid argument for recipient template download', [
                'currency' => $currency ?? 'unknown',
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);

        } catch (Exception $e) {
            Log::error('Failed to download recipient template', [
                'currency' => $currency ?? 'unknown',
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download recipient template',
                'error' => config('app.debug') ? $e->getMessage() : 'Template generation failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download blank template for mass payments
     *
     * @param Request $request
     * @param string $currency
     * @return BinaryFileResponse|Response
     */
    public function downloadBlankTemplate(Request $request, string $currency): BinaryFileResponse|Response
    {
        try {
            // Validate currency parameter
            $currency = strtoupper(trim($currency));
            
            if (!$this->isValidCurrency($currency)) {
                Log::warning('Invalid currency requested for blank template', [
                    'currency' => $currency,
                    'user_id' => Auth::id(),
                    'supported_currencies' => $this->supportedCurrencies,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid currency specified',
                    'error' => "Currency '{$currency}' is not supported. Supported currencies: " . implode(', ', $this->supportedCurrencies),
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get authenticated user
            $user = Auth::user();

            Log::info('Blank template download requested', [
                'currency' => $currency,
                'user_id' => $user->id,
                'client_id' => $user->client_id ?? null,
            ]);

            // Generate blank template
            $templatePath = $this->templateService->generateBlankTemplate($currency);

            if (!$templatePath || !file_exists($templatePath)) {
                Log::error('Failed to generate blank template file', [
                    'currency' => $currency,
                    'template_path' => $templatePath,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate blank template',
                    'error' => 'Template generation failed. Please try again.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Generate filename for download
            $filename = $this->generateTemplateFilename('blank', $currency);

            Log::info('Blank template generated successfully', [
                'currency' => $currency,
                'filename' => $filename,
                'file_size' => filesize($templatePath),
            ]);

            // Return file as download
            return response()->download($templatePath, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ])->deleteFileAfterSend(true);

        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid argument for blank template download', [
                'currency' => $currency ?? 'unknown',
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);

        } catch (Exception $e) {
            Log::error('Failed to download blank template', [
                'currency' => $currency ?? 'unknown',
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download blank template',
                'error' => config('app.debug') ? $e->getMessage() : 'Template generation failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get available template information
     *
     * @param Request $request
     * @return Response
     */
    public function getTemplateInfo(Request $request): Response
    {
        try {
            Log::info('Template information requested', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
            ]);

            $templateInfo = [
                'supported_currencies' => $this->getSupportedCurrenciesInfo(),
                'template_formats' => $this->templateFormats,
                'features' => [
                    'recipient_templates_enabled' => $this->enableRecipientTemplates,
                    'blank_templates_enabled' => true,
                    'sample_data_included' => config('mass-payments.templates.include_sample_data', true),
                    'max_recipients_in_template' => config('mass-payments.templates.max_recipients_in_template', 1000),
                ],
                'csv_format' => [
                    'required_headers' => config('mass-payments.validation.required_csv_headers', [
                        'amount', 'currency', 'beneficiary_name', 'beneficiary_account', 'bank_code'
                    ]),
                    'optional_headers' => config('mass-payments.validation.optional_csv_headers', [
                        'reference', 'purpose_code', 'beneficiary_address', 'beneficiary_country', 'beneficiary_city'
                    ]),
                    'encoding' => 'UTF-8',
                    'delimiter' => ',',
                    'enclosure' => '"',
                ],
                'validation_rules' => [
                    'max_file_size_mb' => config('mass-payments.max_file_size_mb', 10),
                    'max_rows_per_file' => config('mass-payments.max_rows_per_file', 10000),
                    'min_rows_per_file' => config('mass-payments.min_rows_per_file', 1),
                    'max_amount_per_instruction' => config('mass-payments.validation.max_amount_per_instruction', 999999.99),
                    'min_amount_per_instruction' => config('mass-payments.validation.min_amount_per_instruction', 0.01),
                ],
                'purpose_codes' => config('mass-payments.purpose_codes', []),
                'download_urls' => [
                    'recipient_template' => $this->enableRecipientTemplates ? '/api/v1/templates/recipients/{currency}' : null,
                    'blank_template' => '/api/v1/templates/blank/{currency}',
                ],
            ];

            return response()->json([
                'success' => true,
                'message' => 'Template information retrieved successfully',
                'data' => $templateInfo,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Failed to retrieve template information', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve template information',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get template preview (headers and sample data)
     *
     * @param Request $request
     * @param string $currency
     * @return Response
     */
    public function getTemplatePreview(Request $request, string $currency): Response
    {
        try {
            // Validate request
            $request->validate([
                'type' => [
                    'sometimes',
                    'string',
                    Rule::in(['blank', 'recipient']),
                ],
                'sample_rows' => [
                    'sometimes',
                    'integer',
                    'min:0',
                    'max:10',
                ],
            ]);

            // Validate currency parameter
            $currency = strtoupper(trim($currency));
            
            if (!$this->isValidCurrency($currency)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid currency specified',
                    'error' => "Currency '{$currency}' is not supported.",
                ], Response::HTTP_BAD_REQUEST);
            }

            $type = $request->get('type', 'blank');
            $sampleRows = $request->get('sample_rows', 3);

            // Check recipient template availability
            if ($type === 'recipient' && !$this->enableRecipientTemplates) {
                return response()->json([
                    'success' => false,
                    'message' => 'Recipient templates are not enabled',
                ], Response::HTTP_FORBIDDEN);
            }

            Log::info('Template preview requested', [
                'currency' => $currency,
                'type' => $type,
                'sample_rows' => $sampleRows,
                'user_id' => Auth::id(),
            ]);

            // Get template headers
            $headers = $this->getTemplateHeaders($currency);

            // Get sample data
            $sampleData = [];
            if ($sampleRows > 0) {
                for ($i = 1; $i <= $sampleRows; $i++) {
                    $sampleData[] = $this->generateSampleRow($currency, $headers, $i);
                }
            