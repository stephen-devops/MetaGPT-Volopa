## Code: app/Http/Controllers/Api/ExpenseController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\ExpenseCollection;
use App\Models\OopExpense;
use App\Services\ExpenseService;
use App\Policies\ExpensePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ExpenseController extends Controller
{
    /**
     * The expense service instance.
     */
    private ExpenseService $expenseService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PAGE_SIZE = 15;

    /**
     * Maximum pagination size.
     */
    private const MAX_PAGE_SIZE = 100;

    /**
     * Create a new controller instance.
     */
    public function __construct(ExpenseService $expenseService)
    {
        $this->expenseService = $expenseService;
        $this->middleware('auth:api');
        $this->middleware('client.context');
    }

    /**
     * Display a listing of expenses.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Authorize the request
            Gate::authorize('viewAny', OopExpense::class);

            // Get filters from request
            $filters = $this->buildFilters($request);

            // Get expenses based on user context
            if ($request->has('client_id') && Gate::allows('viewClientExpenses', (int) $request->get('client_id'))) {
                // Get client expenses if user has permission
                $expenses = $this->expenseService->getClientExpenses((int) $request->get('client_id'), $filters);
            } else {
                // Get user's own expenses
                $expenses = $this->expenseService->getUserExpenses($request->user(), $filters);
            }

            return response()->json(
                new ExpenseCollection($expenses),
                200
            );

        } catch (\Exception $e) {
            Log::error('Error retrieving expenses', [
                'user_id' => $request->user()->id,
                'filters' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expenses',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created expense.
     */
    public function store(CreateExpenseRequest $request): JsonResponse
    {
        try {
            // Request is automatically validated and authorized via FormRequest
            $validatedData = $request->validated();

            // Create the expense
            $expense = $this->expenseService->createExpense($validatedData, $request->user());

            Log::info('Expense created successfully', [
                'expense_id' => $expense->id,
                'user_id' => $request->user()->id,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => new ExpenseResource($expense->load(['user', 'client', 'project'])),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating expense', [
                'user_id' => $request->user()->id,
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified expense.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $expense = OopExpense::with(['user', 'client', 'approvedBy', 'project'])->findOrFail($id);

            // Authorize the request
            Gate::authorize('view', $expense);

            return response()->json([
                'success' => true,
                'data' => new ExpenseResource($expense),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view this expense',
            ], 403);

        } catch (\Exception $e) {
            Log::error('Error retrieving expense', [
                'expense_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified expense.
     */
    public function update(UpdateExpenseRequest $request, int $id): JsonResponse
    {
        try {
            $expense = OopExpense::findOrFail($id);

            // Authorization is handled in UpdateExpenseRequest
            $validatedData = $request->validated();

            // Update the expense
            $updatedExpense = $this->expenseService->updateExpense($expense, $validatedData);

            Log::info('Expense updated successfully', [
                'expense_id' => $expense->id,
                'user_id' => $request->user()->id,
                'updated_fields' => array_keys($validatedData),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => new ExpenseResource($updatedExpense->load(['user', 'client', 'approvedBy', 'project'])),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found',
            ], 404);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error updating expense', [
                'expense_id' => $id,
                'user_id' => $request->user()->id,
                'data' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified expense.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $expense = OopExpense::findOrFail($id);

            // Authorize the request
            Gate::authorize('delete', $expense);

            // Delete the expense
            $deleted = $this->expenseService->deleteExpense($expense);

            if ($deleted) {
                Log::info('Expense deleted successfully', [
                    'expense_id' => $id,
                    'user_id' => $request->user()->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Expense deleted successfully',
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete expense',
                ], 400);
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this expense',
            ], 403);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error deleting expense', [
                'expense_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Approve the specified expense.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $expense = OopExpense::findOrFail($id);

            // Authorize the request
            Gate::authorize('approve', $expense);

            // Approve the expense
            $approvedExpense = $this->expenseService->approveExpense($expense, $request->user());

            Log::info('Expense approved successfully', [
                'expense_id' => $id,
                'approver_id' => $request->user()->id,
                'expense_user_id' => $expense->user_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense approved successfully',
                'data' => new ExpenseResource($approvedExpense->load(['user', 'client', 'approvedBy', 'project'])),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to approve this expense',
            ], 403);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error approving expense', [
                'expense_id' => $id,
                'approver_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Reject the specified expense.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $expense = OopExpense::findOrFail($id);

            // Authorize the request
            Gate::authorize('reject', $expense);

            // Reject the expense
            $rejectedExpense = $this->expenseService->rejectExpense($expense, $request->user());

            Log::info('Expense rejected successfully', [
                'expense_id' => $id,
                'rejector_id' => $request->user()->id,
                'expense_user_id' => $expense->user_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense rejected successfully',
                'data' => new ExpenseResource($rejectedExpense->load(['user', 'client', 'approvedBy', 'project'])),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense not found',
            ], 404);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to reject this expense',
            ], 403);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            Log::error('Error rejecting expense', [
                'expense_id' => $id,
                'rejector_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get expense statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            // Build filters for statistics
            $filters = $this->buildFilters($request);

            // Get statistics based on user context
            if ($request->has('client_id') && Gate::allows('viewClientExpenses', (int) $request->get('client_id'))) {
                $stats = $this->expenseService->getClientExpenseStats((int) $request->get('client_id'), $filters);
            } else {
                $stats = $this->expenseService->getUserExpenseStats($request->user(), $filters);
            }

            return response()->json([
                'success' => true,
                'data' => $stats,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error retrieving expense statistics', [
                'user_id' => $request->user()->id,
                'filters' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Bulk approve expenses.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'expense_ids' => 'required|array|min:1|max:100',
                'expense_ids.*' => 'required|integer|exists:oop_expenses,id',
            ]);

            $expenseIds = $request->input('expense_ids');

            // Check if user has permission to approve expenses
            Gate::authorize('bulkApprove', [OopExpense::class, $request->get('client_id', 0)]);

            $results = $this->expenseService->bulkApproveExpenses($expenseIds, $request->user());

            Log::info('Bulk approve completed', [
                'approver_id' => $request->user()->id,
                'approved_count' => count($results['approved']),
                'failed_count' => count($results['failed']),
                'skipped_count' => count($results['skipped']),
            ]);

            return response()->json([
                'success' => true,
                'message