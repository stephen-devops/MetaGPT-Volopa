Looking at the context and requirements, I need to implement the `PaymentInstructionController.php` file. This controller handles REST API endpoints for payment instruction operations including listing, viewing, and status management. Based on the design patterns and data structures provided, this controller should be thin and follow the established architectural patterns.

## Code: app/Http/Controllers/Api/V1/PaymentInstructionController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentInstructionResource;
use App\Models\PaymentInstruction;
use App\Models\MassPaymentFile;
use App\Services\PaymentProcessingService;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Exception;

class PaymentInstructionController extends Controller
{
    /**
     * The payment processing service.
     */
    private PaymentProcessingService $paymentProcessingService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Maximum pagination size.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Cache TTL for status summary in minutes.
     */
    private const STATUS_CACHE_TTL = 5;

    /**
     * Valid status values for filtering.
     */
    private const VALID_STATUSES = [
        'draft',
        'validated',
        'validation_failed',
        'pending',
        'processing',
        'completed',
        'failed',
        'cancelled'
    ];

    /**
     * Valid sort fields.
     */
    private const VALID_SORT_FIELDS = [
        'created_at',
        'updated_at',
        'amount',
        'row_number',
        'status',
        'beneficiary_name',
        'currency',
        'processed_at'
    ];

    /**
     * Create a new controller instance.
     */
    public function __construct(PaymentProcessingService $paymentProcessingService)
    {
        $this->paymentProcessingService = $paymentProcessingService;
        
        // Apply authentication middleware
        $this->middleware(['auth:api', 'volopa.auth'])->except([]);
        
        // Apply rate limiting
        $this->middleware('throttle:120,1')->only(['index', 'show']);
        $this->middleware('throttle:60,1')->except(['index', 'show']);
    }

    /**
     * Display a listing of payment instructions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $validated = $request->validate([
                'mass_payment_file_id' => 'nullable|string|uuid',
                'status' => 'nullable|string|in:' . implode(',', self::VALID_STATUSES),
                'currency' => 'nullable|string|size:3',
                'beneficiary_type' => 'nullable|string|in:individual,business',
                'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
                'page' => 'nullable|integer|min:1',
                'sort' => 'nullable|string|in:' . implode(',', self::VALID_SORT_FIELDS),
                'direction' => 'nullable|string|in:asc,desc',
                'search' => 'nullable|string|max:255',
                'min_amount' => 'nullable|numeric|min:0',
                'max_amount' => 'nullable|numeric|gt:min_amount',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'include_errors' => 'nullable|boolean',
                'include' => 'nullable|string',
                'export' => 'nullable|boolean',
            ]);

            // Check mass payment file access if specified
            if (!empty($validated['mass_payment_file_id'])) {
                $massPaymentFile = MassPaymentFile::findOrFail($validated['mass_payment_file_id']);
                Gate::authorize('viewInstructions', $massPaymentFile);
            } else {
                // General authorization for viewing payment instructions
                Gate::authorize('viewAny', MassPaymentFile::class);
            }

            // Build the query with client scoping
            $query = PaymentInstruction::query();

            // Apply mass payment file filter
            if (!empty($validated['mass_payment_file_id'])) {
                $query->where('mass_payment_file_id', $validated['mass_payment_file_id']);
            }

            // Apply other filters
            $this->applyInstructionFilters($query, $validated);

            // Apply sorting
            $sortField = $validated['sort'] ?? 'row_number';
            $sortDirection = $validated['direction'] ?? 'asc';
            $query->orderBy($sortField, $sortDirection);

            // Add secondary sort for consistent ordering
            if ($sortField !== 'row_number') {
                $query->orderBy('row_number', 'asc');
            }

            // Apply eager loading if requested
            $this->applyInstructionEagerLoading($query, $validated['include'] ?? '');

            // Handle export request
            if ($validated['export'] ?? false) {
                return $this->exportInstructions($query, $validated);
            }

            // Paginate results
            $perPage = min($validated['per_page'] ?? self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE);
            $instructions = $query->paginate($perPage);

            // Transform to API resources
            $resourceCollection = PaymentInstructionResource::collection($instructions);

            // Add summary statistics
            $summaryStats = $this->getInstructionsSummary($query->clone(), $validated);

            return response()->json([
                'success' => true,
                'message' => 'Payment instructions retrieved successfully',
                'data' => $resourceCollection->response()->getData(true)['data'],
                'meta' => array_merge(
                    $resourceCollection->response()->getData(true)['meta'] ?? [],
                    [
                        'summary_statistics' => $summaryStats,
                        'filters_applied' => $this->getAppliedInstructionFilters($validated),
                        'total_count' => $instructions->total(),
                        'current_page' => $instructions->currentPage(),
                        'per_page' => $instructions->perPage(),
                        'last_page' => $instructions->lastPage(),
                        'has_more_pages' => $instructions->hasMorePages(),
                    ]
                ),
                'links' => $resourceCollection->response()->getData(true)['links'],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve payment instructions', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'filters' => $validated ?? [],
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Mass payment file not found', 404);
            }

            return $this->errorResponse('Failed to retrieve payment instructions', 500);
        }
    }

    /**
     * Display the specified payment instruction.
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Find the payment instruction
            $instruction = PaymentInstruction::findOrFail($id);

            // Load the mass payment file for authorization
            $instruction->load('massPaymentFile');

            // Authorize the action
            Gate::authorize('viewInstructions', $instruction->massPaymentFile);

            // Load additional relationships
            $instruction->load(['beneficiary']);

            // Transform to API resource
            $resource = new PaymentInstructionResource($instruction);

            // Get processing details if available
            $processingDetails = $this->getProcessingDetails($instruction);

            // Get related instructions (same file)
            $relatedInstructions = $this->getRelatedInstructions($instruction);

            return response()->json([
                'success' => true,
                'message' => 'Payment instruction retrieved successfully',
                'data' => $resource,
                'meta' => [
                    'processing_details' => $processingDetails,
                    'related_instructions' => $relatedInstructions,
                    'user_capabilities' => [
                        'can_retry' => $this->canRetryInstruction($instruction),
                        'can_cancel' => $instruction->canBeCancelled(),
                        'can_view_file' => Gate::allows('view', $instruction->massPaymentFile),
                    ],
                    'status_history' => $this->getInstructionStatusHistory($instruction),
                ],
                'links' => [
                    'mass_payment_file' => route('api.v1.mass-payment-files.show', [
                        'id' => $instruction->mass_payment_file_id
                    ]),
                    'beneficiary' => $instruction->beneficiary_id 
                        ? route('api.v1.beneficiaries.show', ['id' => $instruction->beneficiary_id])
                        : null,
                    'retry' => $this->canRetryInstruction($instruction) 
                        ? route('api.v1.payment-instructions.retry', ['id' => $instruction->id])
                        : null,
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve payment instruction', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Payment instruction not found', 404);
            }

            return $this->errorResponse('Failed to retrieve payment instruction', 500);
        }
    }

    /**
     * Retry a failed payment instruction.
     */
    public function retry(string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Find the payment instruction
            $instruction = PaymentInstruction::findOrFail($id);

            // Load the mass payment file for authorization
            $instruction->load('massPaymentFile');

            // Authorize the action
            Gate::authorize('viewInstructions', $instruction->massPaymentFile);

            // Check if instruction can be retried
            if (!$this->canRetryInstruction($instruction)) {
                return $this->errorResponse(
                    'Payment instruction cannot be retried in current status: ' . $instruction->status,
                    400
                );
            }

            Log::info('Payment instruction retry started', [
                'instruction_id' => $instruction->id,
                'user_id' => Auth::id(),
                'current_status' => $instruction->status,
                'amount' => $instruction->amount,
            ]);

            // Retry the instruction using service
            $retried = $this->paymentProcessingService->retryPaymentInstruction($instruction);

            if (!$retried) {
                throw new Exception('Failed to retry payment instruction');
            }

            DB::commit();

            // Refresh the model to get updated data
            $instruction = $instruction->fresh();

            // Transform to API resource
            $resource = new PaymentInstructionResource($instruction);

            Log::info('Payment instruction retry initiated successfully', [
                'instruction_id' => $instruction->id,
                'user_id' => Auth::id(),
                'new_status' => $instruction->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment instruction retry initiated successfully',
                'data' => $resource,
                'meta' => [
                    'retry_initiated_at' => now()->toISOString(),
                    'previous_status' => $instruction->getOriginal('status'),
                    'new_status' => $instruction->status,
                    'retry_count' => $this->getRetryCount($instruction),
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Payment instruction retry failed', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Payment instruction not found', 404);
            }

            return $this->errorResponse('Failed to retry payment instruction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel a pending payment instruction.
     */
    public function cancel(string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Find the payment instruction
            $instruction = PaymentInstruction::findOrFail($id);

            // Load the mass payment file for authorization
            $instruction->load('massPaymentFile');

            // Authorize the action
            Gate::authorize('viewInstructions', $instruction->massPaymentFile);

            // Check if instruction can be cancelled
            if (!$instruction->canBeCancelled()) {
                return $this->errorResponse(
                    'Payment instruction cannot be cancelled in current status: ' . $instruction->status,
                    400
                );
            }

            Log::info('Payment instruction cancellation started', [
                'instruction_id' => $instruction->id,
                'user_id' => Auth::id(),
                'current_status' => $instruction->status,
                'amount' => $instruction->amount,
            ]);

            // Cancel the instruction using service
            $cancelled = $this->paymentProcessingService->cancelPaymentInstruction($instruction);

            if (!$cancelled) {
                throw new Exception('Failed to cancel payment instruction');
            }

            DB::commit();

            // Refresh the model to get updated data
            $instruction = $instruction->fresh();

            // Transform to API resource
            $resource = new PaymentInstructionResource($instruction);

            Log::info('Payment instruction cancelled successfully', [
                'instruction_id' => $instruction->id,
                'user_id' => Auth::id(),
                'new_status' => $instruction->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment instruction cancelled successfully',
                'data' => $resource,
                'meta' => [
                    'cancelled_at' => now()->toISOString(),
                    'cancelled_by' => Auth::user()->name,
                    'previous_status' => $instruction->getOriginal('status'),
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Payment instruction cancellation failed', [
                'instruction_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Payment instruction not found', 404);
            }

            return $this->errorResponse('Failed to cancel payment instruction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get status summary for payment instructions.
     */
    public function status(Request $request): JsonResponse
    {
        try {
            // Validate query parameters
            $validated = $request->validate([
                'mass_payment_file_id' => 'nullable|string|uuid',
                'currency' => 'nullable|string|size:3',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]);

            // Check mass payment file access if specified
            if (!empty($validated['mass_payment_file_id'])) {
                $massPaymentFile = MassPaymentFile::findOrFail($validated['mass_payment_file_id']);
                Gate::authorize('view