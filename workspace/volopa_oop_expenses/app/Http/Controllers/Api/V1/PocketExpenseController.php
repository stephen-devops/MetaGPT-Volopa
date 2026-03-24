<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Models\PocketExpense;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * PocketExpenseController
 * 
 * Thin controller for pocket expense API endpoints following Laravel best practices.
 * Handles CRUD operations for out-of-pocket expenses with proper authorization,
 * validation, real-time FX conversion, and standardized JSON responses.
 * 
 * All operations are scoped by client_id for multi-tenancy and require
 * Oauth2UserClient middleware authentication.
 */
class PocketExpenseController extends Controller
{
    /**
     * Pocket expense service for business logic
     *
     * @var PocketExpenseService
     */
    protected PocketExpenseService $pocketExpenseService;

    /**
     * FX conversion service for currency calculations
     *
     * @var FXConversionService
     */
    protected FXConversionService $fxConversionService;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $pocketExpenseService
     * @param FXConversionService $fxConversionService
     */
    public function __construct(
        PocketExpenseService $pocketExpenseService,
        FXConversionService $fxConversionService
    ) {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->fxConversionService = $fxConversionService;
        
        // Apply middleware for authentication and authorization
        $this->middleware('auth:oauth2');
        $this->middleware('can:viewAny,App\Models\PocketExpense')->only(['index']);
        $this->middleware('can:view,expense')->only(['show']);
        $this->middleware('can:create,App\Models\PocketExpense')->only(['store']);
        $this->middleware('can:update,expense')->only(['update']);
        $this->middleware('can:delete,expense')->only(['destroy']);
    }

    /**
     * Display a listing of pocket expenses.
     * 
     * Retrieves paginated pocket expenses for the authenticated user with optional
     * filtering by status. Results are scoped by client_id for multi-tenancy.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id;

            // Validate query parameters
            $request->validate([
                'status' => 'nullable|string|in:draft,submitted,approved,rejected',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'sort_by' => 'nullable|string|in:date,merchant_name,amount,status,create_time',
                'sort_direction' => 'nullable|string|in:asc,desc',
                'date_from' => 'nullable|date|date_format:Y-m-d',
                'date_to' => 'nullable|date|date_format:Y-m-d|after_or_equal:date_from',
            ]);

            // Build filters array
            $filters = [
                'status' => $request->input('status'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'sort_by' => $request->input('sort_by', 'create_time'),
                'sort_direction' => $request->input('sort_direction', 'desc'),
            ];

            // Get expenses through service layer
            $expenses = $this->pocketExpenseService->getUserExpenses(
                $user->id,
                $clientId,
                $filters
            );

            // Apply pagination
            $perPage = (int) $request->input('per_page', 15);
            $paginatedExpenses = $expenses->paginate($perPage);

            // Return paginated resource collection
            return PocketExpenseResource::collection($paginatedExpenses);
            
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expenses', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expenses',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }

    /**
     * Store a newly created pocket expense.
     * 
     * Creates a new pocket expense with real-time FX conversion and proper
     * audit trail. Handles all validation, authorization, and business logic
     * through service layers.
     *
     * @param StorePocketExpenseRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id;

            // Get validated data from form request
            $validatedData = $request->validated();

            // Perform FX conversion if expense currency differs from wallet base currency
            $fxData = null;
            if (isset($validatedData['currency'])) {
                try {
                    // Get wallet base currency for the client
                    $walletCurrency = $this->fxConversionService->getWalletBaseCurrency($clientId);
                    
                    // Perform FX conversion if currencies differ
                    if ($walletCurrency && $validatedData['currency'] !== $walletCurrency['currency']) {
                        $fxRate = $this->fxConversionService->getFXRate(
                            $validatedData['currency'],
                            $walletCurrency['currency'],
                            \Carbon\Carbon::parse($validatedData['date'])
                        );

                        if ($fxRate !== null) {
                            $convertedAmount = $this->fxConversionService->convertAmount(
                                (float) $validatedData['amount'],
                                $fxRate
                            );

                            $fxData = [
                                'base_currency' => $walletCurrency['currency'],
                                'fx_rate' => $fxRate,
                                'converted_amount' => $convertedAmount,
                                'conversion_date' => now()->toISOString(),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('FX conversion failed during expense creation', [
                        'user_id' => $user->id,
                        'client_id' => $clientId,
                        'currency' => $validatedData['currency'],
                        'error' => $e->getMessage(),
                    ]);
                    // Continue without FX data - not a blocking error
                }
            }

            // Add FX data to validated data if available
            if ($fxData) {
                $validatedData['fx_data'] = $fxData;
            }

            // Create expense through service layer with transaction
            DB::beginTransaction();
            
            $expense = $this->pocketExpenseService->createExpense(
                $validatedData,
                $user->id,
                $clientId
            );
            
            DB::commit();

            // Log successful creation
            Log::info('Pocket expense created successfully', [
                'expense_id' => $expense->id,
                'user_id' => $user->id,
                'client_id' => $clientId,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
            ]);

            // Return created expense with 201 status
            return (new PocketExpenseResource($expense))
                ->response()
                ->setStatusCode(201);
                
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create pocket expense', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'request_data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to create expense',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }

    /**
     * Display the specified pocket expense.
     * 
     * Retrieves a single pocket expense by ID with proper authorization
     * checks and client scoping. Returns 404 if not found or not accessible.
     *
     * @param PocketExpense $expense
     * @return PocketExpenseResource|JsonResponse
     */
    public function show(PocketExpense $expense): PocketExpenseResource|JsonResponse
    {
        try {
            // Authorization is handled by middleware, but verify client scoping
            $user = Auth::user();
            $clientId = $user->client_id;
            
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                ], 404);
            }

            // Load relationships for complete expense data
            $expense->load([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.fileStore',
                'metadata.expenseSource',
                'metadata.additionalField',
            ]);

            return new PocketExpenseResource($expense);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expense', [
                'expense_id' => $expense->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expense',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }

    /**
     * Update the specified pocket expense.
     * 
     * Updates an existing pocket expense with validation, authorization,
     * and real-time FX recalculation. Maintains proper audit trail.
     *
     * @param UpdatePocketExpenseRequest $request
     * @param PocketExpense $expense
     * @return PocketExpenseResource|JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, PocketExpense $expense): PocketExpenseResource|JsonResponse
    {
        try {
            // Get authenticated user and verify client scoping
            $user = Auth::user();
            $clientId = $user->client_id;
            
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                ], 404);
            }

            // Get validated data from form request
            $validatedData = $request->validated();

            // Perform FX conversion if currency or date changed
            $fxData = null;
            if (isset($validatedData['currency']) || isset($validatedData['date']) || isset($validatedData['amount'])) {
                try {
                    // Get currency from validated data or existing expense
                    $currency = $validatedData['currency'] ?? $expense->currency;
                    $date = isset($validatedData['date']) 
                        ? \Carbon\Carbon::parse($validatedData['date'])
                        : \Carbon\Carbon::parse($expense->date);
                    $amount = $validatedData['amount'] ?? (float) $expense->amount;

                    // Get wallet base currency for the client
                    $walletCurrency = $this->fxConversionService->getWalletBaseCurrency($clientId);
                    
                    // Perform FX conversion if currencies differ
                    if ($walletCurrency && $currency !== $walletCurrency['currency']) {
                        $fxRate = $this->fxConversionService->getFXRate(
                            $currency,
                            $walletCurrency['currency'],
                            $date
                        );

                        if ($fxRate !== null) {
                            $convertedAmount = $this->fxConversionService->convertAmount($amount, $fxRate);

                            $fxData = [
                                'base_currency' => $walletCurrency['currency'],
                                'fx_rate' => $fxRate,
                                'converted_amount' => $convertedAmount,
                                'conversion_date' => now()->toISOString(),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('FX conversion failed during expense update', [
                        'expense_id' => $expense->id,
                        'user_id' => $user->id,
                        'client_id' => $clientId,
                        'currency' => $currency,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue without FX data - not a blocking error
                }
            }

            // Add FX data to validated data if available
            if ($fxData) {
                $validatedData['fx_data'] = $fxData;
            }

            // Update expense through service layer with transaction
            DB::beginTransaction();
            
            $updatedExpense = $this->pocketExpenseService->updateExpense($expense, $validatedData);
            
            DB::commit();

            // Log successful update
            Log::info('Pocket expense updated successfully', [
                'expense_id' => $updatedExpense->id,
                'user_id' => $user->id,
                'client_id' => $clientId,
                'updated_fields' => array_keys($validatedData),
            ]);

            // Return updated expense with relationships
            $updatedExpense->load([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.fileStore',
                'metadata.expenseSource',
                'metadata.additionalField',
            ]);

            return new PocketExpenseResource($updatedExpense);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update pocket expense', [
                'expense_id' => $expense->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'request_data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to update expense',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }

    /**
     * Remove the specified pocket expense.
     * 
     * Soft deletes a pocket expense using Volopa legacy soft delete pattern.
     * Maintains data integrity and audit trail.
     *
     * @param PocketExpense $expense
     * @return JsonResponse
     */
    public function destroy(PocketExpense $expense): JsonResponse
    {
        try {
            // Verify client scoping
            $user = Auth::user();
            $clientId = $user->client_id;
            
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                ], 404);
            }

            // Delete expense through service layer with transaction
            DB::beginTransaction();
            
            $deleted = $this->pocketExpenseService->deleteExpense($expense);
            
            if (!$deleted) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Failed to delete expense',
                    'error' => 'Expense could not be deleted',
                ], 400);
            }
            
            DB::commit();

            // Log successful deletion
            Log::info('Pocket expense deleted successfully', [
                'expense_id' => $expense->id,
                'user_id' => $user->id,
                'client_id' => $clientId,
            ]);

            // Return 204 No Content for successful deletion
            return response()->json([], 204);
            
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete pocket expense', [
                'expense_id' => $expense->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to delete expense',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }

    /**
     * Approve the specified pocket expense.
     * 
     * Approves an expense with proper authorization checks. Only users with
     * approval permissions can approve expenses.
     *
     * @param Request $request
     * @param PocketExpense $expense
     * @return PocketExpenseResource|JsonResponse
     */
    public function approve(Request $request, PocketExpense $expense): PocketExpenseResource|JsonResponse
    {
        try {
            // Check approval authorization through policy
            $this->authorize('approve', $expense);

            // Get authenticated user and verify client scoping
            $user = Auth::user();
            $clientId = $user->client_id;
            
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                ], 404);
            }

            // Validate request - optional approval notes
            $request->validate([
                'approval_notes' => 'nullable|string|max:1000',
            ]);

            // Approve expense through service layer with transaction
            DB::beginTransaction();
            
            $approvedExpense = $this->pocketExpenseService->approveExpense(
                $expense,
                $user->id,
                $request->input('approval_notes')
            );
            
            DB::commit();

            // Log successful approval
            Log::info('Pocket expense approved successfully', [
                'expense_id' => $approvedExpense->id,
                'approved_by' => $user->id,
                'client_id' => $clientId,
                'previous_status' => $expense->status,
            ]);

            // Return approved expense with relationships
            $approvedExpense->load([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.fileStore',
                'metadata.expenseSource',
                'metadata.additionalField',
            ]);

            return new PocketExpenseResource($approvedExpense);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Unauthorized to approve this expense',
            ], 403);
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve pocket expense', [
                'expense_id' => $expense->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to approve expense',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }

    /**
     * Reject the specified pocket expense.
     * 
     * Rejects an expense with proper authorization checks and rejection reason.
     * Only users with approval permissions can reject expenses.
     *
     * @param Request $request
     * @param PocketExpense $expense
     * @return PocketExpenseResource|JsonResponse
     */
    public function reject(Request $request, PocketExpense $expense): PocketExpenseResource|JsonResponse
    {
        try {
            // Check approval authorization through policy (same as approve)
            $this->authorize('approve', $expense);

            // Get authenticated user and verify client scoping
            $user = Auth::user();
            $clientId = $user->client_id;
            
            if ($expense->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense not found',
                ], 404);
            }

            // Validate request - rejection reason is required
            $request->validate([
                'rejection_reason' => 'required|string|max:1000',
            ]);

            // Reject expense through service layer with transaction
            DB::beginTransaction();
            
            $rejectedExpense = $this->pocketExpenseService->rejectExpense(
                $expense,
                $user->id,
                $request->input('rejection_reason')
            );
            
            DB::commit();

            // Log successful rejection
            Log::info('Pocket expense rejected successfully', [
                'expense_id' => $rejectedExpense->id,
                'rejected_by' => $user->id,
                'client_id' => $clientId,
                'previous_status' => $expense->status,
                'rejection_reason' => $request->input('rejection_reason'),
            ]);

            // Return rejected expense with relationships
            $rejectedExpense->load([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.fileStore',
                'metadata.expenseSource',
                'metadata.additionalField',
            ]);

            return new PocketExpenseResource($rejectedExpense);
            
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Expense not found',
            ], 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Unauthorized to reject this expense',
            ], 403);
        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject pocket expense', [
                'expense_id' => $expense->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to reject expense',
                'error' => 'An internal error occurred',
            ], 500);
        }
    }
}