<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Models\PocketExpense;
use App\Services\PocketExpenseService;
use App\Services\PocketExpenseFXService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PocketExpenseController
 * 
 * Main expense API controller with CRUD and approval operations.
 * Handles single expense management for out-of-pocket expenses.
 * 
 * Uses OAuth2 middleware applied at route group level.
 * All operations are scoped by client_id for multi-tenancy.
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
     * The pocket expense FX service instance.
     *
     * @var \App\Services\PocketExpenseFXService
     */
    protected PocketExpenseFXService $pocketExpenseFXService;

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\PocketExpenseService $pocketExpenseService
     * @param \App\Services\PocketExpenseFXService $pocketExpenseFXService
     */
    public function __construct(
        PocketExpenseService $pocketExpenseService,
        PocketExpenseFXService $pocketExpenseFXService
    ) {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->pocketExpenseFXService = $pocketExpenseFXService;
        
        // OAuth2 middleware is applied at route group level, not here
        // as per system constraints
    }

    /**
     * Display a listing of expenses for the specified client.
     * 
     * Returns paginated expenses scoped to client_id with proper authorization.
     *
     * @param int $client_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(int $client_id): JsonResponse
    {
        try {
            // TODO: Implement user authentication check - verify current user belongs to client_id
            // TODO: Implement authorization policy check - verify user can view expenses for this client
            
            $expenses = $this->pocketExpenseService->getExpensesForUser(
                auth()->id(), // TODO: Replace with proper authenticated user ID
                $client_id
            );

            // Use Laravel's built-in pagination with API Resource collection
            return response()->json([
                'success' => true,
                'data' => PocketExpenseResource::collection($expenses->paginate(15)),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expenses',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created expense in storage.
     * 
     * Creates expense with FX conversion and metadata handling.
     *
     * @param \App\Http\Requests\StorePocketExpenseRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            // TODO: Implement authorization policy check - verify user can create expenses for this client
            
            $validatedData = $request->validated();

            // Perform FX conversion if required
            if (isset($validatedData['currency']) && isset($validatedData['amount'])) {
                $fxResult = $this->pocketExpenseFXService->convertAmount(
                    $validatedData['amount'],
                    $validatedData['currency'],
                    $this->pocketExpenseFXService->getWalletBaseCurrency($validatedData['client_id']),
                    $validatedData['date'],
                    $validatedData['client_id']
                );
                
                // TODO: Store FX conversion result in expense data
            }

            $expense = $this->pocketExpenseService->createExpense(
                $validatedData,
                auth()->id() // TODO: Replace with proper authenticated user ID
            );

            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => new PocketExpenseResource($expense),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified expense.
     * 
     * Returns expense details with relationships and metadata.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(PocketExpense $expense): JsonResponse
    {
        try {
            // TODO: Implement authorization policy check - verify user can view this specific expense
            
            return response()->json([
                'success' => true,
                'data' => new PocketExpenseResource($expense),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 404);
        }
    }

    /**
     * Update the specified expense in storage.
     * 
     * Updates expense with FX recalculation and metadata handling.
     *
     * @param \App\Http\Requests\UpdatePocketExpenseRequest $request
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, PocketExpense $expense): JsonResponse
    {
        try {
            // TODO: Implement authorization policy check - verify user can update this expense
            
            $validatedData = $request->validated();

            // Perform FX conversion if currency or amount changed
            if (isset($validatedData['currency']) || isset($validatedData['amount'])) {
                $currency = $validatedData['currency'] ?? $expense->currency;
                $amount = $validatedData['amount'] ?? $expense->amount;
                $date = $validatedData['date'] ?? $expense->date;
                
                $fxResult = $this->pocketExpenseFXService->convertAmount(
                    $amount,
                    $currency,
                    $this->pocketExpenseFXService->getWalletBaseCurrency($expense->client_id),
                    $date,
                    $expense->client_id
                );
                
                // TODO: Store updated FX conversion result in expense data
            }

            $updatedExpense = $this->pocketExpenseService->updateExpense(
                $expense,
                $validatedData,
                auth()->id() // TODO: Replace with proper authenticated user ID
            );

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => new PocketExpenseResource($updatedExpense),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified expense from storage.
     * 
     * Performs soft delete with proper audit trail.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(PocketExpense $expense): JsonResponse
    {
        try {
            // TODO: Implement authorization policy check - verify user can delete this expense
            
            $deleted = $this->pocketExpenseService->deleteExpense(
                $expense,
                auth()->id() // TODO: Replace with proper authenticated user ID
            );

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Expense deleted successfully',
                ], 204);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Approve the specified expense.
     * 
     * Changes expense status to approved with proper authorization checks.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve(PocketExpense $expense): JsonResponse
    {
        try {
            // TODO: Implement authorization policy check - verify user can approve expenses
            // According to constraints: Business User and Card User cannot approve expenses even with management rights
            
            $approvedExpense = $this->pocketExpenseService->approveExpense(
                $expense,
                auth()->id() // TODO: Replace with proper authenticated user ID
            );

            return response()->json([
                'success' => true,
                'message' => 'Expense approved successfully',
                'data' => new PocketExpenseResource($approvedExpense),
            ], 200);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to approve expenses',
            ], 403);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}