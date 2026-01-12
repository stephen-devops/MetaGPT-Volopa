## Code: app/Http/Controllers/Api/V1/PaymentInstructionController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentInstructionRequest;
use App\Http\Resources\PaymentInstructionResource;
use App\Services\PaymentProcessingService;
use App\Models\PaymentInstruction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PaymentInstructionController extends Controller
{
    /**
     * The payment processing service instance.
     */
    private PaymentProcessingService $paymentProcessingService;

    /**
     * Default pagination size for payment instruction lists.
     */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Maximum pagination size allowed.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Allowed sort fields for payment instruction queries.
     */
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'amount',
        'currency',
        'status',
        'row_number',
        'created_at',
        'updated_at',
        'processed_at'
    ];

    /**
     * Allowed filter fields for payment instruction queries.
     */
    private const ALLOWED_FILTER_FIELDS = [
        'status',
        'currency',
        'mass_payment_file_id',
        'beneficiary_id',
        'purpose_code'
    ];

    /**
     * Create a new controller instance.
     */
    public function __construct(PaymentProcessingService $paymentProcessingService)
    {
        $this->paymentProcessingService = $paymentProcessingService;
        $this->middleware('auth:api');
        $this->middleware('throttle:60,1');
    }

    /**
     * Get a paginated list of payment instructions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('Payment instructions list requested', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id,
                'filters' => $request->only(self::ALLOWED_FILTER_FIELDS)
            ]);

            // Build query with filters and relationships
            $query = PaymentInstruction::with(['beneficiary', 'massPaymentFile']);

            // Apply filters
            $this->applyFilters($query, $request);

            // Apply sorting
            $this->applySorting($query, $request);

            // Get pagination parameters
            $perPage = $this->getPerPageParameter($request);

            // Execute query with pagination
            $instructions = $query->paginate($perPage);

            Log::info('Payment instructions retrieved successfully', [
                'total' => $instructions->total(),
                'per_page' => $instructions->perPage(),
                'current_page' => $instructions->currentPage(),
                'user_id' => Auth::id()
            ]);

            // Transform to resource collection
            return response()->json([
                'data' => PaymentInstructionResource::collection($instructions->items()),
                'meta' => [
                    'current_page' => $instructions->currentPage(),
                    'from' => $instructions->firstItem(),
                    'last_page' => $instructions->lastPage(),
                    'per_page' => $instructions->perPage(),
                    'to' => $instructions->lastItem(),
                    'total' => $instructions->total(),
                    'links' => [
                        'first' => $instructions->url(1),
                        'last' => $instructions->url($instructions->lastPage()),
                        'prev' => $instructions->previousPageUrl(),
                        'next' => $instructions->nextPageUrl(),
                    ]
                ],
                'links' => [
                    'first' => $instructions->url(1),
                    'last' => $instructions->url($instructions->lastPage()),
                    'prev' => $instructions->previousPageUrl(),
                    'next' => $instructions->nextPageUrl(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error retrieving payment instructions', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'filters' => $request->only(self::ALLOWED_FILTER_FIELDS)
            ]);

            return response()->json([
                'message' => 'Failed to retrieve payment instructions',
                'error' => 'retrieval_error'
            ], 500);
        }
    }

    /**
     * Create a new payment instruction.
     *
     * @param CreatePaymentInstructionRequest $request
     * @return JsonResponse
     */
    public function store(CreatePaymentInstructionRequest $request): JsonResponse
    {
        try {
            Log::info('Creating payment instruction', [
                'user_id' => Auth::id(),
                'mass_payment_file_id' => $request->validated('mass_payment_file_id'),
                'beneficiary_id' => $request->validated('beneficiary_id'),
                'amount' => $request->validated('amount'),
                'currency' => $request->validated('currency')
            ]);

            // Get validated data
            $validatedData = $request->validated();

            // Create payment instruction using the service
            $instruction = $this->paymentProcessingService->createSinglePaymentInstruction($validatedData);

            if (!$instruction) {
                Log::warning('Payment instruction creation returned null', [
                    'user_id' => Auth::id(),
                    'data' => $validatedData
                ]);

                return response()->json([
                    'message' => 'Failed to create payment instruction',
                    'error' => 'creation_failed'
                ], 500);
            }

            // Load relationships for complete resource response
            $instruction->load(['beneficiary', 'massPaymentFile']);

            Log::info('Payment instruction created successfully', [
                'instruction_id' => $instruction->id,
                'user_id' => Auth::id(),
                'amount' => $instruction->amount,
                'currency' => $instruction->currency
            ]);

            return response()->json([
                'data' => new PaymentInstructionResource($instruction),
                'message' => 'Payment instruction created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating payment instruction', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'data' => $request->validated()
            ]);

            return response()->json([
                'message' => 'Failed to create payment instruction',
                'error' => 'creation_error'
            ], 500);
        }
    }

    /**
     * Get a specific payment instruction by ID.
     *
     * @param int $id Payment instruction ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            Log::info('Payment instruction details requested', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            // Find payment instruction with relationships
            $instruction = PaymentInstruction::with(['beneficiary', 'massPaymentFile'])
                                           ->findOrFail($id);

            Log::info('Payment instruction retrieved successfully', [
                'instruction_id' => $id,
                'status' => $instruction->status,
                'amount' => $instruction->amount,
                'currency' => $instruction->currency,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'data' => new PaymentInstructionResource($instruction)
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::warning('Payment instruction not found', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Payment instruction not found',
                'error' => 'not_found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error retrieving payment instruction', [
                'instruction_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve payment instruction',
                'error' => 'retrieval_error'
            ], 500);
        }
    }

    /**
     * Update a payment instruction.
     *
     * @param CreatePaymentInstructionRequest $request
     * @param int $id Payment instruction ID
     * @return JsonResponse
     */
    public function update(CreatePaymentInstructionRequest $request, int $id): JsonResponse
    {
        try {
            Log::info('Updating payment instruction', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            // Find the payment instruction
            $instruction = PaymentInstruction::findOrFail($id);

            // Check if instruction can be updated
            if (!$instruction->canBeUpdated()) {
                Log::warning('Attempted to update non-updatable payment instruction', [
                    'instruction_id' => $id,
                    'current_status' => $instruction->status,
                    'user_id' => Auth::id()
                ]);

                return response()->json([
                    'message' => 'Payment instruction cannot be updated in its current status',
                    'error' => 'update_not_allowed',
                    'current_status' => $instruction->status
                ], 422);
            }

            // Get validated data
            $validatedData = $request->validated();

            // Remove fields that shouldn't be updated
            unset($validatedData['mass_payment_file_id']); // Don't allow changing parent file
            unset($validatedData['row_number']); // Don't allow changing row number

            // Update the instruction
            $instruction->update($validatedData);

            // Reload with relationships
            $instruction->load(['beneficiary', 'massPaymentFile']);

            Log::info('Payment instruction updated successfully', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'data' => new PaymentInstructionResource($instruction),
                'message' => 'Payment instruction updated successfully'
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::warning('Payment instruction not found for update', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Payment instruction not found',
                'error' => 'not_found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error updating payment instruction', [
                'instruction_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to update payment instruction',
                'error' => 'update_error'
            ], 500);
        }
    }

    /**
     * Delete a payment instruction.
     *
     * @param int $id Payment instruction ID
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            Log::info('Deleting payment instruction', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            // Find the payment instruction
            $instruction = PaymentInstruction::findOrFail($id);

            // Check if instruction can be deleted
            if (!$instruction->canBeDeleted()) {
                Log::warning('Attempted to delete non-deletable payment instruction', [
                    'instruction_id' => $id,
                    'current_status' => $instruction->status,
                    'user_id' => Auth::id()
                ]);

                return response()->json([
                    'message' => 'Payment instruction cannot be deleted in its current status',
                    'error' => 'delete_not_allowed',
                    'current_status' => $instruction->status
                ], 422);
            }

            // Soft delete the instruction
            $instruction->delete();

            Log::info('Payment instruction deleted successfully', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Payment instruction deleted successfully'
            ], 204);

        } catch (ModelNotFoundException $e) {
            Log::warning('Payment instruction not found for deletion', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Payment instruction not found',
                'error' => 'not_found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error deleting payment instruction', [
                'instruction_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to delete payment instruction',
                'error' => 'delete_error'
            ], 500);
        }
    }

    /**
     * Cancel a payment instruction.
     *
     * @param int $id Payment instruction ID
     * @return JsonResponse
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            Log::info('Cancelling payment instruction', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            // Find the payment instruction
            $instruction = PaymentInstruction::findOrFail($id);

            // Check if instruction can be cancelled
            if (!$instruction->canBeCancelled()) {
                Log::warning('Attempted to cancel non-cancellable payment instruction', [
                    'instruction_id' => $id,
                    'current_status' => $instruction->status,
                    'user_id' => Auth::id()
                ]);

                return response()->json([
                    'message' => 'Payment instruction cannot be cancelled in its current status',
                    'error' => 'cancel_not_allowed',
                    'current_status' => $instruction->status
                ], 422);
            }

            // Cancel the instruction
            $instruction->markAsCancelled();

            // Reload with relationships
            $instruction->load(['beneficiary', 'massPaymentFile']);

            Log::info('Payment instruction cancelled successfully', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'data' => new PaymentInstructionResource($instruction),
                'message' => 'Payment instruction cancelled successfully'
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::warning('Payment instruction not found for cancellation', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Payment instruction not found',
                'error' => 'not_found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error cancelling payment instruction', [
                'instruction_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to cancel payment instruction',
                'error' => 'cancel_error'
            ], 500);
        }
    }

    /**
     * Retry processing of a failed payment instruction.
     *
     * @param int $id Payment instruction ID
     * @return JsonResponse
     */
    public function retry(int $id): JsonResponse
    {
        try {
            Log::info('Retrying payment instruction processing', [
                'instruction_id' => $id,
                'user_id' => Auth::id()
            