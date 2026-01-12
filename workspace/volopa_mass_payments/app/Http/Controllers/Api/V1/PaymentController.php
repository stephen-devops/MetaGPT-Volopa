## Code: app/Http/Controllers/Api/V1/PaymentController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\PaymentFile;
use App\Models\PaymentInstruction;
use App\Services\PaymentProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use Symfony\Http\Response;

class PaymentController extends Controller
{
    /**
     * Payment processing service instance.
     */
    private PaymentProcessingService $paymentService;

    /**
     * Default pagination limit.
     */
    private const DEFAULT_PAGINATION_LIMIT = 15;

    /**
     * Maximum pagination limit.
     */
    private const MAX_PAGINATION_LIMIT = 100;

    /**
     * Create a new PaymentController instance.
     *
     * @param PaymentProcessingService $paymentService
     */
    public function __construct(PaymentProcessingService $paymentService)
    {
        $this->paymentService = $paymentService;
        
        // Apply authentication middleware
        $this->middleware('auth:sanctum');
        
        // Apply throttling middleware
        $this->middleware('throttle:payment-processing,10')->only(['process']);
        $this->middleware('throttle:api,60')->except(['process']);
    }

    /**
     * Display a listing of payment instructions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        Log::info('Payment index request received', [
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
                'payment_file_id' => 'sometimes|integer|exists:payment_files,id',
                'status' => 'sometimes|string|in:pending,processing,completed,failed,cancelled',
                'currency' => 'sometimes|string|size:3|in:USD,EUR,GBP',
                'settlement_method' => 'sometimes|string|in:SEPA,SWIFT,FASTER_PAYMENTS,ACH,WIRE',
                'min_amount' => 'sometimes|numeric|min:0',
                'max_amount' => 'sometimes|numeric|min:0',
                'per_page' => 'sometimes|integer|min:1|max:' . self::MAX_PAGINATION_LIMIT,
                'sort_by' => 'sometimes|string|in:created_at,updated_at,amount,row_number,processed_at',
                'sort_direction' => 'sometimes|string|in:asc,desc',
                'search' => 'sometimes|string|max:255',
                'processed_after' => 'sometimes|date',
                'processed_before' => 'sometimes|date',
            ]);

            // Build query with authorization
            $query = PaymentInstruction::query()
                ->with(['paymentFile' => function ($query) {
                    $query->with('user');
                }])
                ->whereHas('paymentFile', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });

            // Apply filters
            if (isset($validated['payment_file_id'])) {
                $query->forPaymentFile($validated['payment_file_id']);
            }

            if (isset($validated['status'])) {
                $query->withStatus($validated['status']);
            }

            if (isset($validated['currency'])) {
                $query->withCurrency($validated['currency']);
            }

            if (isset($validated['settlement_method'])) {
                $query->withSettlementMethod($validated['settlement_method']);
            }

            if (isset($validated['min_amount'])) {
                $query->where('amount', '>=', $validated['min_amount']);
            }

            if (isset($validated['max_amount'])) {
                $query->where('amount', '<=', $validated['max_amount']);
            }

            if (isset($validated['processed_after'])) {
                $query->where('processed_at', '>=', $validated['processed_after']);
            }

            if (isset($validated['processed_before'])) {
                $query->where('processed_at', '<=', $validated['processed_before']);
            }

            if (isset($validated['search'])) {
                $searchTerm = $validated['search'];
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('beneficiary_name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('beneficiary_account', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('reference', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('payment_purpose', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'created_at';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $query->orderBy($sortBy, $sortDirection);

            // Paginate results
            $perPage = min(
                $validated['per_page'] ?? self::DEFAULT_PAGINATION_LIMIT,
                self::MAX_PAGINATION_LIMIT
            );

            $paymentInstructions = $query->paginate($perPage);

            Log::info('Payment index completed', [
                'user_id' => $user->id,
                'total_instructions' => $paymentInstructions->total(),
                'current_page' => $paymentInstructions->currentPage(),
                'per_page' => $perPage,
            ]);

            return $this->paginatedResponse(
                PaymentResource::collection($paymentInstructions),
                'Payment instructions retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Payment index failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve payment instructions',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Display the specified payment instruction.
     *
     * @param int $id Payment instruction ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        Log::info('Payment show request received', [
            'payment_instruction_id' => $id,
            'user_id' => Auth::id(),
        ]);

        try {
            // Find payment instruction with relationships
            $paymentInstruction = PaymentInstruction::with([
                'paymentFile' => function ($query) {
                    $query->with(['user', 'approvals' => function ($q) {
                        $q->with('approver')->orderBy('created_at', 'desc');
                    }]);
                }
            ])->find($id);

            if (!$paymentInstruction) {
                return $this->errorResponse('Payment instruction not found', Response::HTTP_NOT_FOUND);
            }

            // Check authorization through payment file
            $user = Auth::user();
            if (!$user || !$user->can('view', $paymentInstruction->paymentFile)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            Log::info('Payment instruction retrieved successfully', [
                'payment_instruction_id' => $id,
                'user_id' => $user->id,
                'payment_status' => $paymentInstruction->status,
                'payment_file_id' => $paymentInstruction->payment_file_id,
            ]);

            return $this->successResponse(
                new PaymentResource($paymentInstruction),
                'Payment instruction retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Payment show failed', [
                'payment_instruction_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve payment instruction',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Process approved payments for a payment file.
     *
     * @param Request $request
     * @param int $fileId Payment file ID
     * @return JsonResponse
     */
    public function process(Request $request, int $fileId): JsonResponse
    {
        Log::info('Payment processing request received', [
            'payment_file_id' => $fileId,
            'user_id' => Auth::id(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Validate request parameters
            $validated = $request->validate([
                'force_process' => 'sometimes|boolean',
                'batch_size' => 'sometimes|integer|min:1|max:500',
                'dry_run' => 'sometimes|boolean',
            ]);

            // Find payment file
            $paymentFile = PaymentFile::with(['paymentInstructions', 'approvals'])
                ->find($fileId);

            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            // Check authorization
            if (!$user->can('process', $paymentFile)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            // Validate payment file can be processed
            $canProcess = $this->canProcessPaymentFile($paymentFile, $validated['force_process'] ?? false);
            if (!$canProcess['allowed']) {
                return $this->errorResponse(
                    $canProcess['reason'],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Handle dry run
            if ($validated['dry_run'] ?? false) {
                $statistics = $this->paymentService->getProcessingStatistics($paymentFile);
                
                return $this->successResponse([
                    'dry_run' => true,
                    'would_process' => $statistics['pending_instructions'],
                    'estimated_processing_time' => $this->estimateProcessingTime($paymentFile),
                    'statistics' => $statistics,
                ], 'Dry run completed - no payments were processed');
            }

            // Process payments using service
            $processingResults = $this->paymentService->processPayments($paymentFile);

            Log::info('Payment processing completed', [
                'payment_file_id' => $fileId,
                'user_id' => $user->id,
                'processed_count' => $processingResults['processed_count'],
                'successful_count' => $processingResults['successful_count'],
                'failed_count' => $processingResults['failed_count'],
            ]);

            // Prepare response data
            $responseData = [
                'processing_results' => $processingResults,
                'payment_file' => [
                    'id' => $paymentFile->id,
                    'status' => $paymentFile->fresh()->status,
                    'filename' => $paymentFile->original_name,
                ],
                'processed_payments' => PaymentResource::collection(
                    $paymentFile->paymentInstructions()
                        ->whereIn('status', [
                            PaymentInstruction::STATUS_COMPLETED,
                            PaymentInstruction::STATUS_FAILED
                        ])
                        ->orderBy('row_number')
                        ->limit(100) // Limit to first 100 for response size
                        ->get()
                ),
            ];

            return $this->successResponse(
                $responseData,
                'Payment processing completed successfully'
            );

        } catch (Exception $e) {
            Log::error('Payment processing failed', [
                'payment_file_id' => $fileId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'Payment processing failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get payment processing statistics for a payment file.
     *
     * @param int $fileId Payment file ID
     * @return JsonResponse
     */
    public function statistics(int $fileId): JsonResponse
    {
        Log::info('Payment statistics request received', [
            'payment_file_id' => $fileId,
            'user_id' => Auth::id(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Find payment file
            $paymentFile = PaymentFile::find($fileId);

            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            // Check authorization
            if (!$user->can('view', $paymentFile)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            // Get processing statistics
            $statistics = $this->paymentService->getProcessingStatistics($paymentFile);

            // Add additional breakdown by currency and settlement method
            $additionalStats = [
                'by_currency' => PaymentInstruction::forPaymentFile($fileId)
                    ->selectRaw('currency, COUNT(*) as count, SUM(amount) as total_amount, status')
                    ->groupBy('currency', 'status')
                    ->get()
                    ->groupBy('currency'),
                
                'by_settlement_method' => PaymentInstruction::forPaymentFile($fileId)
                    ->selectRaw('settlement_method, COUNT(*) as count, SUM(amount) as total_amount, status')
                    ->groupBy('settlement_method', 'status')
                    ->get()
                    ->groupBy('settlement_method'),
                
                'by_status' => PaymentInstruction::forPaymentFile($fileId)
                    ->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                    ->groupBy('status')
                    ->get()
                    ->keyBy('status'),

                'processing_timeline' => PaymentInstruction::forPaymentFile($fileId)
                    ->whereNotNull('processed_at')
                    ->selectRaw('DATE(processed_at) as date, COUNT(*) as count, status')
                    ->groupBy('date', 'status')
                    ->orderBy('date')
                    ->get()
                    ->groupBy('date'),
            ];

            $fullStatistics = array_merge($statistics, [
                'breakdown' => $additionalStats,
                'generated_at' => now()->toISOString(),
            ]);

            Log::info('Payment statistics retrieved', [
                'payment_file_id' => $fileId,
                'user_id' => $user->id,
            ]);

            return $this->successResponse(
                $fullStatistics,
                'Payment statistics retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Payment statistics failed', [
                'payment_file_id' => $fileId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve payment statistics',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Retry failed payments for a payment file.
     *
     * @param Request $request
     * @param int $fileId Payment file ID
     * @return JsonResponse
     */
    public function retry(Request $request,