<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseCollection;
use App\Http\Resources\PocketExpenseResource;
use App\Models\PocketExpense;
use App\Models\User;
use App\Services\PocketExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PocketExpenseController
 * 
 * Thin controller for pocket expense CRUD operations following Laravel best practices.
 * Delegates business logic to PocketExpenseService and uses API Resources for response shaping.
 * Enforces OAuth2 authentication, client scoping, and proper authorization policies.
 */
class PocketExpenseController extends Controller
{
    /**
     * The pocket expense service instance.
     *
     * @var \App\Services\PocketExpenseService
     */
    protected PocketExpenseService $pocketExpenseService;

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\PocketExpenseService $pocketExpenseService
     */
    public function __construct(PocketExpenseService $pocketExpenseService)
    {
        $this->pocketExpenseService = $pocketExpenseService;
        
        // Apply OAuth2 middleware for all routes
        $this->middleware(['auth:api', 'oauth2_user_client']);
        
        // Apply authorization middleware for model-specific routes
        $this->middleware('can:viewAny,App\Models\PocketExpense')->only(['index']);
        $this->middleware('can:view,expense')->only(['show']);
        $this->middleware('can:create,App\Models\PocketExpense')->only(['store']);
        $this->middleware('can:update,expense')->only(['update']);
        $this->middleware('can:delete,expense')->only(['destroy']);
    }

    /**
     * Get paginated list of pocket expenses for the authenticated user.
     * 
     * Applies client scoping and user filtering automatically.
     * Supports pagination with configurable per_page limit.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = $request->header('X-Client-ID') ?? $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context is required',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate pagination parameters
            $perPage = min((int) $request->get('per_page', 15), 100); // Max 100 per page
            $page = max((int) $request->get('page', 1), 1);

            // Build query with filters
            $query = PocketExpense::active()
                                 ->forUser($user->id)
                                 ->forClient($clientId)
                                 ->with(['expenseType', 'user', 'createdBy', 'approvedBy', 'metadata']);

            // Apply optional filters from request
            if ($request->filled('status')) {
                $status = $request->get('status');
                if (PocketExpense::isValidStatus($status)) {
                    $query->withStatus($status);
                }
            }

            if ($request->filled('currency')) {
                $currency = strtoupper($request->get('currency'));
                if (preg_match('/^[A-Z]{3}$/', $currency)) {
                    $query->withCurrency($currency);
                }
            }

            if ($request->filled('expense_type')) {
                $expenseTypeId = (int) $request->get('expense_type');
                if ($expenseTypeId > 0) {
                    $query->withExpenseType($expenseTypeId);
                }
            }

            // Apply date range filter if provided
            if ($request->filled('date_from') && $request->filled('date_to')) {
                try {
                    $dateFrom = \Carbon\Carbon::createFromFormat('Y-m-d', $request->get('date_from'));
                    $dateTo = \Carbon\Carbon::createFromFormat('Y-m-d', $request->get('date_to'));
                    $query->dateRange($dateFrom, $dateTo);
                } catch (\Exception $e) {
                    // Invalid date format - skip date filter
                    Log::warning('Invalid date range in expense listing', [
                        'date_from' => $request->get('date_from'),
                        'date_to' => $request->get('date_to'),
                        'user_id' => $user->id,
                        'client_id' => $clientId
                    ]);
                }
            }

            // Apply sorting - default to newest first
            $sortBy = $request->get('sort_by', 'create_time');
            $sortDirection = $request->get('sort_direction', 'desc');
            
            $allowedSortFields = ['create_time', 'date', 'amount', 'status', 'merchant_name'];
            if (in_array($sortBy, $allowedSortFields)) {
                $sortDirection = in_array($sortDirection, ['asc', 'desc']) ? $sortDirection : 'desc';
                $query->orderBy($sortBy, $sortDirection);
            } else {
                $query->orderBy('create_time', 'desc');
            }

            // Execute paginated query
            $expenses = $query->paginate($perPage, ['*'], 'page', $page);

            // Log successful expense listing for audit
            Log::info('Expenses listed', [
                'user_id' => $user->id,
                'client_id' => $clientId,
                'total_count' => $expenses->total(),
                'page' => $page,
                'per_page' => $perPage,
                'filters' => $request->only(['status', 'currency', 'expense_type', 'date_from', 'date_to'])
            ]);

            return response()->json(new PocketExpenseCollection($expenses), Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to list expenses', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expenses',
                'error' => 'LISTING_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new pocket expense.
     * 
     * Validates input via StorePocketExpenseRequest and applies FX conversion.
     * Enforces user and client scoping for security.
     *
     * @param \App\Http\Requests\StorePocketExpenseRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = $request->header('X-Client-ID') ?? $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context is required',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get validated data from the form request
            $validatedData = $request->validated();

            // Ensure server-side user and client scoping (security)
            $validatedData['user_id'] = $user->id;
            $validatedData['client_id'] = $clientId;
            $validatedData['created_by_user_id'] = $user->id;

            // Create the expense through the service layer
            $expense = $this->pocketExpenseService->createExpense($validatedData);

            DB::commit();

            // Log successful expense creation
            Log::info('Expense created', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'user_id' => $user->id,
                'client_id' => $clientId,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'merchant_name' => $expense->merchant_name,
                'status' => $expense->status
            ]);

            // Load relationships for the response
            $expense->load(['expenseType', 'user', 'createdBy', 'metadata']);

            return response()->json(new PocketExpenseResource($expense), Response::HTTP_CREATED);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create expense', [
                'user_id' => Auth::id(),
                'client_id' => $request->header('X-Client-ID'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['file', 'receipt'])
            ]);

            return response()->json([
                'message' => 'Failed to create expense',
                'error' => 'CREATION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific pocket expense by ID.
     * 
     * Enforces authorization policy and loads relationships for complete data.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(PocketExpense $expense): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = request()->header('X-Client-ID') ?? $user->client_id ?? null;

            // Ensure the expense belongs to the correct client (additional security layer)
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if expense is active (not soft deleted)
            if (!$expense->isActive()) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Load relationships for complete response
            $expense->load([
                'expenseType', 
                'user', 
                'createdBy', 
                'updatedBy', 
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.expenseSource'
            ]);

            // Log expense viewing for audit trail
            Log::info('Expense viewed', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'viewed_by' => $user->id,
                'client_id' => $clientId
            ]);

            return response()->json(new PocketExpenseResource($expense), Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to show expense', [
                'expense_id' => $expense->id ?? null,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expense',
                'error' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing pocket expense.
     * 
     * Validates input and enforces business rules about what can be updated.
     * Only draft expenses can be modified.
     *
     * @param \App\Http\Requests\UpdatePocketExpenseRequest $request
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, PocketExpense $expense): JsonResponse
    {
        DB::beginTransaction();

        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = $request->header('X-Client-ID') ?? $user->client_id ?? null;

            // Ensure the expense belongs to the correct client
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if expense is active and can be edited
            if (!$expense->isActive() || !$expense->canEdit()) {
                return response()->json([
                    'message' => 'Expense cannot be modified',
                    'error' => 'NOT_EDITABLE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Get validated data from the form request
            $validatedData = $request->validated();

            // Add audit fields
            $validatedData['updated_by_user_id'] = $user->id;

            // Update the expense through the service layer
            $updatedExpense = $this->pocketExpenseService->updateExpense($expense, $validatedData);

            DB::commit();

            // Log successful expense update
            Log::info('Expense updated', [
                'expense_id' => $updatedExpense->id,
                'uuid' => $updatedExpense->uuid,
                'updated_by' => $user->id,
                'client_id' => $clientId,
                'changed_fields' => array_keys($validatedData)
            ]);

            // Load relationships for the response
            $updatedExpense->load(['expenseType', 'user', 'createdBy', 'updatedBy', 'metadata']);

            return response()->json(new PocketExpenseResource($updatedExpense), Response::HTTP_OK);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to update expense', [
                'expense_id' => $expense->id,
                'user_id' => Auth::id(),
                'client_id' => $request->header('X-Client-ID'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['file', 'receipt'])
            ]);

            return response()->json([
                'message' => 'Failed to update expense',
                'error' => 'UPDATE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft delete a pocket expense.
     * 
     * Only draft expenses can be deleted. Uses soft delete to maintain audit trail.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(PocketExpense $expense): JsonResponse
    {
        DB::beginTransaction();

        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = request()->header('X-Client-ID') ?? $user->client_id ?? null;

            // Ensure the expense belongs to the correct client
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if expense is active and can be deleted
            if (!$expense->isActive() || !$expense->canDelete()) {
                return response()->json([
                    'message' => 'Expense cannot be deleted',
                    'error' => 'NOT_DELETABLE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Perform soft delete through the service layer
            $deleted = $this->pocketExpenseService->deleteExpense($expense);

            if (!$deleted) {
                return response()->json([
                    'message' => 'Failed to delete expense',
                    'error' => 'DELETION_FAILED'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            DB::commit();

            // Log successful expense deletion
            Log::info('Expense deleted', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'deleted_by' => $user->id,
                'client_id' => $clientId,
                'amount' => $expense->amount,
                'currency' => $expense->currency
            ]);

            return response()->json(null, Response::HTTP_NO_CONTENT);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete expense', [
                'expense_id' => $expense->id,
                'user_id' => Auth::id(),
                'client_id' => request()->header('X-Client-ID'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete expense',
                'error' => 'DELETION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Submit an expense for approval.
     * 
     * Changes status from draft to submitted.
     * Separate endpoint for explicit workflow control.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit(PocketExpense $expense): JsonResponse
    {
        DB::beginTransaction();

        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = request()->header('X-Client-ID') ?? $user->client_id ?? null;

            // Ensure the expense belongs to the correct client
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if expense can be submitted
            if (!$expense->canSubmit()) {
                return response()->json([
                    'message' => 'Expense cannot be submitted',
                    'error' => 'NOT_SUBMITTABLE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Submit the expense
            $submitted = $expense->submit($user->id);

            if (!$submitted) {
                return response()->json([
                    'message' => 'Failed to submit expense',
                    'error' => 'SUBMISSION_FAILED'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            DB::commit();

            // Log successful expense submission
            Log::info('Expense submitted for approval', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'submitted_by' => $user->id,
                'client_id' => $clientId
            ]);

            // Load relationships for the response
            $expense->load(['expenseType', 'user', 'createdBy', 'updatedBy']);

            return response()->json(new PocketExpenseResource($expense), Response::HTTP_OK);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to submit expense', [
                'expense_id' => $expense->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to submit expense',
                'error' => 'SUBMISSION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Approve an expense.
     * 
     * Requires appropriate permissions (admin/approver role).
     * Changes status from submitted to approved.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve(PocketExpense $expense): JsonResponse
    {
        DB::beginTransaction();

        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = request()->header('X-Client-ID') ?? $user->client_id ?? null;

            // Check approval authorization (handled by policy)
            $this->authorize('approve', $expense);

            // Ensure the expense belongs to the correct client
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if expense can be approved
            if (!$expense->canApprove()) {
                return response()->json([
                    'message' => 'Expense cannot be approved',
                    'error' => 'NOT_APPROVABLE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Approve the expense
            $approved = $expense->approve($user->id);

            if (!$approved) {
                return response()->json([
                    'message' => 'Failed to approve expense',
                    'error' => 'APPROVAL_FAILED'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            DB::commit();

            // Log successful expense approval
            Log::info('Expense approved', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'approved_by' => $user->id,
                'client_id' => $clientId,
                'amount' => $expense->amount,
                'currency' => $expense->currency
            ]);

            // Load relationships for the response
            $expense->load(['expenseType', 'user', 'createdBy', 'updatedBy', 'approvedBy']);

            return response()->json(new PocketExpenseResource($expense), Response::HTTP_OK);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Unauthorized to approve expenses',
                'error' => 'UNAUTHORIZED'
            ], Response::HTTP_FORBIDDEN);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to approve expense', [
                'expense_id' => $expense->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to approve expense',
                'error' => 'APPROVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reject an expense.
     * 
     * Requires appropriate permissions (admin/approver role).
     * Changes status from submitted to rejected.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject(PocketExpense $expense): JsonResponse
    {
        DB::beginTransaction();

        try {
            /** @var \App\Models\User $user */
            $user = Auth::user();
            $clientId = request()->header('X-Client-ID') ?? $user->client_id ?? null;

            // Check rejection authorization (handled by policy)
            $this->authorize('approve', $expense); // Same permission as approve

            // Ensure the expense belongs to the correct client
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                    'error' => 'NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if expense can be rejected
            if (!$expense->canReject()) {
                return response()->json([
                    'message' => 'Expense cannot be rejected',
                    'error' => 'NOT_REJECTABLE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Reject the expense
            $rejected = $expense->reject($user->id);

            if (!$rejected) {
                return response()->json([
                    'message' => 'Failed to reject expense',
                    'error' => 'REJECTION_FAILED'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            DB::commit();

            // Log successful expense rejection
            Log::info('Expense rejected', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'rejected_by' => $user->id,
                'client_id' => $clientId
            ]);

            // Load relationships for the response
            $expense->load(['expenseType', 'user', 'createdBy', 'updatedBy']);

            return response()->json(new PocketExpenseResource($expense), Response::HTTP_OK);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Unauthorized to reject expenses',
                'error' => 'UNAUTHORIZED'
            ], Response::HTTP_FORBIDDEN);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to reject expense', [
                'expense_id' => $expense->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to reject expense',
                'error' => 'REJECTION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}