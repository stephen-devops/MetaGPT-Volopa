<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Services\PocketExpenseService;
use App\Services\PocketExpenseFXService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pocket Expense Controller
 * 
 * Handles CRUD operations for out-of-pocket expenses.
 * Integrates with PocketExpenseService for business logic and PocketExpenseFXService for FX conversion.
 * Follows thin controller pattern - delegates to services for business logic.
 * 
 * @package App\Http\Controllers\Api\V1
 */
class PocketExpenseController extends Controller
{
    /**
     * Pocket Expense Service instance.
     *
     * @var PocketExpenseService
     */
    protected PocketExpenseService $pocketExpenseService;

    /**
     * Pocket Expense FX Service instance.
     *
     * @var PocketExpenseFXService
     */
    protected PocketExpenseFXService $pocketExpenseFXService;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $pocketExpenseService
     * @param PocketExpenseFXService $pocketExpenseFXService
     */
    public function __construct(
        PocketExpenseService $pocketExpenseService,
        PocketExpenseFXService $pocketExpenseFXService
    ) {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->pocketExpenseFXService = $pocketExpenseFXService;
        
        // Apply OAuth2 middleware for authentication
        $this->middleware('auth:api');
    }

    /**
     * Display a listing of pocket expenses.
     * 
     * Supports filtering by user_id, status, client context, and pagination.
     * Returns paginated list using API Resource for consistent formatting.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context from request
            $userId = $request->query('user_id');
            $status = $request->query('status');
            $clientId = $request->user()->client_id ?? $request->query('client_id');
            
            // Validate client context is provided
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client context required'
                ], 400);
            }

            // Delegate to service for business logic
            $expenses = $this->pocketExpenseService->getByUser($userId ?: $request->user()->id, $clientId);
            
            // Apply status filter if provided
            if ($status) {
                $expenses = $expenses->where('status', $status);
            }

            // Paginate results as per system constraints
            $paginatedExpenses = $expenses->paginate(15);

            return response()->json([
                'success' => true,
                'data' => PocketExpenseResource::collection($paginatedExpenses),
                'pagination' => [
                    'current_page' => $paginatedExpenses->currentPage(),
                    'total_pages' => $paginatedExpenses->lastPage(),
                    'total_items' => $paginatedExpenses->total(),
                    'per_page' => $paginatedExpenses->perPage(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expenses',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Store a newly created pocket expense.
     * 
     * Creates expense with FX conversion if applicable.
     * Returns 201 Created status with expense resource.
     *
     * @param StorePocketExpenseRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            // Get validated data from form request
            $validatedData = $request->validated();
            
            // Add authenticated user context
            $validatedData['created_by_user_id'] = $request->user()->id;
            
            // Delegate expense creation to service
            $expense = $this->pocketExpenseService->create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => new PocketExpenseResource($expense)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Display the specified pocket expense.
     * 
     * Returns expense with metadata and FX information.
     * Includes related data via API Resource relationships.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            // Delegate to service to find expense by ID
            $expense = $this->pocketExpenseService->findById($id);

            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseResource($expense)
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update the specified pocket expense.
     * 
     * Updates expense with recalculated FX conversion.
     * Returns updated expense resource.
     *
     * @param UpdatePocketExpenseRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, int $id): JsonResponse
    {
        try {
            // Get validated data from form request
            $validatedData = $request->validated();
            
            // Add updated by user context
            $validatedData['updated_by_user_id'] = $request->user()->id;
            
            // Delegate expense update to service
            $expense = $this->pocketExpenseService->update($id, $validatedData);

            if (!$expense) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found or cannot be updated'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => new PocketExpenseResource($expense)
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Remove the specified pocket expense.
     * 
     * Soft deletes the expense using flag-based soft delete.
     * Returns 204 No Content on successful deletion.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Delegate expense deletion to service
            $deleted = $this->pocketExpenseService->delete($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense not found or cannot be deleted'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ], 204);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}