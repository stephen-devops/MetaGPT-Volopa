## Code: app/Http/Controllers/Api/V1/TemplateController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentInstruction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Exception;
use League\Csv\Writer;
use SplTempFileObject;

class TemplateController extends Controller
{
    /**
     * CSV template headers in the correct order.
     */
    private const TEMPLATE_HEADERS = [
        'beneficiary_name',
        'beneficiary_account',
        'amount',
        'currency',
        'settlement_method',
        'payment_purpose',
        'reference',
    ];

    /**
     * Sample data rows for the CSV template.
     */
    private const SAMPLE_DATA = [
        [
            'beneficiary_name' => 'John Smith Ltd',
            'beneficiary_account' => 'GB33BUKB20201555555555',
            'amount' => '1500.00',
            'currency' => 'GBP',
            'settlement_method' => 'FASTER_PAYMENTS',
            'payment_purpose' => 'Invoice payment for services',
            'reference' => 'INV-2024-001',
        ],
        [
            'beneficiary_name' => 'ACME Corporation',
            'beneficiary_account' => 'DE89370400440532013000',
            'amount' => '2500.50',
            'currency' => 'EUR',
            'settlement_method' => 'SEPA',
            'payment_purpose' => 'Monthly supplier payment',
            'reference' => 'SUPP-202401-ACME',
        ],
        [
            'beneficiary_name' => 'Global Tech Solutions Inc',
            'beneficiary_account' => '1234567890',
            'amount' => '5000.00',
            'currency' => 'USD',
            'settlement_method' => 'WIRE',
            'payment_purpose' => 'Software license fee Q1 2024',
            'reference' => 'LIC-Q1-2024-GTS',
        ],
    ];

    /**
     * Field descriptions for documentation.
     */
    private const FIELD_DESCRIPTIONS = [
        'beneficiary_name' => [
            'description' => 'Full legal name of the payment recipient',
            'required' => true,
            'max_length' => 255,
            'example' => 'John Smith Ltd',
            'validation_rules' => 'Letters, numbers, spaces, hyphens, dots, and apostrophes only',
        ],
        'beneficiary_account' => [
            'description' => 'Account number or IBAN of the recipient',
            'required' => true,
            'max_length' => 255,
            'example' => 'GB33BUKB20201555555555 (for IBAN) or 1234567890 (for account number)',
            'validation_rules' => 'Format depends on settlement method (IBAN for SEPA, Sort code + account for UK, etc.)',
        ],
        'amount' => [
            'description' => 'Payment amount in decimal format',
            'required' => true,
            'format' => 'Decimal number with up to 2 decimal places',
            'example' => '1500.00',
            'validation_rules' => 'Must be greater than 0.01 and less than 999999.99',
        ],
        'currency' => [
            'description' => 'Three-letter ISO currency code',
            'required' => true,
            'format' => 'ISO 4217 currency code',
            'example' => 'USD, EUR, GBP',
            'validation_rules' => 'Must be one of the supported currencies: USD, EUR, GBP',
        ],
        'settlement_method' => [
            'description' => 'Payment processing method',
            'required' => true,
            'example' => 'SEPA, SWIFT, FASTER_PAYMENTS, ACH, WIRE',
            'validation_rules' => 'Must be compatible with the specified currency',
        ],
        'payment_purpose' => [
            'description' => 'Description of the payment purpose (optional)',
            'required' => false,
            'max_length' => 500,
            'example' => 'Invoice payment for services',
            'validation_rules' => 'Optional field for payment description',
        ],
        'reference' => [
            'description' => 'Payment reference or identifier (optional)',
            'required' => false,
            'max_length' => 255,
            'example' => 'INV-2024-001',
            'validation_rules' => 'Optional unique reference for tracking purposes',
        ],
    ];

    /**
     * Settlement method compatibility matrix.
     */
    private const SETTLEMENT_COMPATIBILITY = [
        'USD' => ['ACH', 'WIRE', 'SWIFT'],
        'EUR' => ['SEPA', 'SWIFT', 'WIRE'],
        'GBP' => ['FASTER_PAYMENTS', 'SWIFT', 'WIRE'],
    ];

    /**
     * Create a new TemplateController instance.
     */
    public function __construct()
    {
        // Apply authentication middleware
        $this->middleware('auth:sanctum');
        
        // Apply throttling middleware
        $this->middleware('throttle:api,60');
    }

    /**
     * Download CSV template for payment file uploads.
     *
     * @param Request $request
     * @return Response|JsonResponse
     */
    public function downloadCsv(Request $request): Response|JsonResponse
    {
        Log::info('CSV template download request received', [
            'user_id' => Auth::id(),
            'query_params' => $request->query(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Validate query parameters
            $validated = $request->validate([
                'include_samples' => 'sometimes|boolean',
                'currency' => 'sometimes|string|size:3|in:USD,EUR,GBP',
                'settlement_method' => 'sometimes|string|in:SEPA,SWIFT,FASTER_PAYMENTS,ACH,WIRE',
                'format' => 'sometimes|string|in:csv,xlsx',
            ]);

            $includeSamples = $validated['include_samples'] ?? true;
            $currency = $validated['currency'] ?? null;
            $settlementMethod = $validated['settlement_method'] ?? null;
            $format = $validated['format'] ?? 'csv';

            // Only support CSV format for now
            if ($format !== 'csv') {
                return $this->errorResponse('Only CSV format is currently supported', Response::HTTP_BAD_REQUEST);
            }

            // Validate settlement method and currency compatibility
            if ($currency && $settlementMethod) {
                if (!$this->isSettlementMethodCompatible($currency, $settlementMethod)) {
                    return $this->errorResponse(
                        "Settlement method '{$settlementMethod}' is not compatible with currency '{$currency}'",
                        Response::HTTP_BAD_REQUEST
                    );
                }
            }

            // Generate CSV content
            $csvContent = $this->generateCsvTemplate($includeSamples, $currency, $settlementMethod);

            // Generate filename
            $filename = $this->generateTemplateFilename($currency, $settlementMethod);

            Log::info('CSV template generated successfully', [
                'user_id' => $user->id,
                'filename' => $filename,
                'include_samples' => $includeSamples,
                'currency' => $currency,
                'settlement_method' => $settlementMethod,
            ]);

            // Return CSV response with appropriate headers
            return response($csvContent)
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"")
                ->header('Cache-Control', 'no-cache, must-revalidate')
                ->header('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT')
                ->header('X-Template-Version', '1.0.0')
                ->header('X-Generated-At', now()->toISOString());

        } catch (Exception $e) {
            Log::error('CSV template download failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'Failed to generate CSV template',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get template metadata and field specifications.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMetadata(Request $request): JsonResponse
    {
        Log::info('Template metadata request received', [
            'user_id' => Auth::id(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Build metadata response
            $metadata = [
                'template_version' => '1.0.0',
                'supported_formats' => ['csv'],
                'required_headers' => array_filter(self::TEMPLATE_HEADERS, function ($header) {
                    return self::FIELD_DESCRIPTIONS[$header]['required'] ?? false;
                }),
                'optional_headers' => array_filter(self::TEMPLATE_HEADERS, function ($header) {
                    return !(self::FIELD_DESCRIPTIONS[$header]['required'] ?? false);
                }),
                'all_headers' => self::TEMPLATE_HEADERS,
                'field_specifications' => self::FIELD_DESCRIPTIONS,
                'supported_currencies' => PaymentInstruction::getValidCurrencies(),
                'supported_settlement_methods' => PaymentInstruction::getValidSettlementMethods(),
                'settlement_compatibility' => self::SETTLEMENT_COMPATIBILITY,
                'validation_rules' => $this->getValidationRules(),
                'limits' => [
                    'max_records_per_file' => 10000,
                    'max_file_size_mb' => 10,
                    'max_amount_per_instruction' => 999999.99,
                    'min_amount_per_instruction' => 0.01,
                ],
                'examples' => [
                    'valid_records' => self::SAMPLE_DATA,
                    'invalid_records' => $this->getInvalidExamples(),
                ],
            ];

            Log::info('Template metadata retrieved successfully', [
                'user_id' => $user->id,
            ]);

            return $this->successResponse(
                $metadata,
                'Template metadata retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Template metadata request failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve template metadata',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get available settlement methods for a specific currency.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSettlementMethods(Request $request): JsonResponse
    {
        Log::info('Settlement methods request received', [
            'user_id' => Auth::id(),
            'query_params' => $request->query(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Validate query parameters
            $validated = $request->validate([
                'currency' => 'required|string|size:3|in:USD,EUR,GBP',
            ]);

            $currency = $validated['currency'];
            $settlementMethods = PaymentInstruction::getSettlementMethodsForCurrency($currency);

            // Build detailed response
            $detailedMethods = [];
            foreach ($settlementMethods as $method) {
                $detailedMethods[] = [
                    'code' => $method,
                    'name' => $this->getSettlementMethodDisplayName($method),
                    'description' => $this->getSettlementMethodDescription($method),
                    'processing_time' => $this->getProcessingTimeInfo($method),
                    'account_format' => $this->getAccountFormatInfo($method),
                    'is_instant' => $this->isInstantSettlement($method),
                ];
            }

            $response = [
                'currency' => $currency,
                'available_methods' => $detailedMethods,
                'default_method' => $this->getDefaultSettlementMethod($currency),
                'total_count' => count($settlementMethods),
            ];

            Log::info('Settlement methods retrieved successfully', [
                'user_id' => $user->id,
                'currency' => $currency,
                'method_count' => count($settlementMethods),
            ]);

            return $this->successResponse(
                $response,
                "Settlement methods for {$currency} retrieved successfully"
            );

        } catch (Exception $e) {
            Log::error('Settlement methods request failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve settlement methods',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Validate a CSV row format without processing.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateRow(Request $request): JsonResponse
    {
        Log::info('Row validation request received', [
            'user_id' => Auth::id(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Validate request data
            $validated = $request->validate([
                'row_data' => 'required|array',
                'row_data.beneficiary_name' => 'required|string|max:255',
                'row_data.beneficiary_account' => 'required|string|max:255',
                'row_data.amount' => 'required|numeric|min:0.01|max:999999.99',
                'row_data.currency' => 'required|string|size:3|in:USD,EUR,GBP',
                'row_data.settlement_method' => 'required|string|in:SEPA,SWIFT,FASTER_PAYMENTS,ACH,WIRE',
                'row_data.payment_purpose' => 'sometimes|nullable|string|max:500',
                'row_data.reference' => 'sometimes|nullable|string|max:255',
            ]);

            $rowData = $validated['row_data'];

            // Perform validation
            $validationResults = $this->performRowValidation($rowData);

            Log::info('Row validation completed', [
                'user_id' => $user->id,
                'is_valid' => $validationResults['is_valid'],
                'error_count' => count($validationResults['errors']),
                'warning_count' => count($validationResults['warnings']),
            ]);

            return $this->successResponse(
                $validationResults,
                'Row validation completed'
            );

        } catch (Exception $e) {
            Log::error('Row validation failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Row validation failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Generate CSV template