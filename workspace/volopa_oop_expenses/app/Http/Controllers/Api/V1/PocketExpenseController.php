<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Services\PocketExpenseService;
use App\Services\PocketExpenseFXService;
use App\Policies\PocketExpensePolicy;
use App\Models\PocketExpense;
use App\Http\Resources\PocketExpenseResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * PocketExpenseController
 * 
 * REST API controller for pocket expense CRUD operations.
 * Handles expense management with FX conversion, validation, and authorization.
 * All operations are scoped by client_id for multi-tenancy.
 */
class PocketExpenseController extends Controller
{
    protected PocketExpenseService $pocketExpenseService;
    protected PocketExpenseFXService $fxService;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $pocketExpenseService
     * @param PocketExpenseFXService $fxService
     */
    public function __construct(
        PocketExpenseService $pocketExpenseService,
        PocketExpenseFXService $fxService
    ) {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->fxService = $fxService;
    }

    /**
     * Display a listing of pocket expenses.
     * 
     * Supports filtering by client_id (required), user_id, and status.
     * Results are paginated and scoped by authorization policy.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Authorize viewAny action
            Gate::authorize('viewAny', PocketExpense::class);

            // Validate required parameters
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
                'user_id' => 'nullable|integer|exists:users,id',
                'status' => 'nullable|string|in:draft,submitted,approved,rejected',
            ]);

            $clientId = (int) $request->input('client_id');
            $userId = $request->has('user_id') ? (int) $request->input('user_id') : null;
            $status = $request->input('status');

            // Get current authenticated user
            $currentUser = Auth::user();
            if (!$currentUser) {
                return response()->json([
                    'message' => 'Unauthorized'
                ], 401);
            }

            // Get user expenses with authorization and filtering
            $expenses = $this->pocketExpenseService->getUserExpenses(
                $currentUser->id,
                $clientId,
                $userId,
                $status
            );

            // Return paginated response
            return response()->json([
                'data' => PocketExpenseResource::collection($expenses->paginate(15))
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        } catch (\Exception $e) {
            Log::error('PocketExpenseController@index failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'message' => 'An error occurred while retrieving expenses'
            ], 500);
        }
    }

    /**
     * Store a newly created pocket expense.
     * 
     * Validates input, applies FX conversion, and creates the expense record.
     * Amount signs are applied based on expense type.
     *
     * @param StorePocketExpenseRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            // Authorization is handled in the form request
            
            // Get validated data
            $validatedData = $request->validated();
            
            // Apply FX conversion to the expense data
            $convertedData = $this->fxService->convertExpenseAmount(
                $validatedData,
                $validatedData['client_id']
            );

            // Create the expense using the service
            $expense = $this->pocketExpenseService->createExpense($convertedData);

            return response()->json([
                'data' => new PocketExpenseResource($expense)
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        } catch (\Exception $e) {
            Log::error('PocketExpenseController@store failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_data' => $request->validated()
            ]);

            return response()->json([
                'message' => 'An error occurred while creating the expense'
            ], 500);
        }
    }

    /**
     * Display the specified pocket expense.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            // Find the expense
            $expense = PocketExpense::findOrFail($id);

            // Authorize view action
            Gate::authorize('view', $expense);

            return response()->json([
                'data' => new PocketExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        } catch (\Exception $e) {
            Log::error('PocketExpenseController@show failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'expense_id' => $id
            ]);

            return response()->json([
                'message' => 'An error occurred while retrieving the expense'
            ], 500);
        }
    }

    /**
     * Update the specified pocket expense.
     *
     * @param UpdatePocketExpenseRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, int $id): JsonResponse
    {
        try {
            // Find the expense
            $expense = PocketExpense::findOrFail($id);

            // Authorization is handled in the form request
            
            // Get validated data
            $validatedData = $request->validated();

            // Apply FX conversion if currency or amount changed
            if (isset($validatedData['currency']) || isset($validatedData['amount'])) {
                $dataForConversion = array_merge([
                    'client_id' => $expense->client_id,
                    'currency' => $expense->currency,
                    'amount' => $expense->amount,
                    'date' => $expense->date,
                ], $validatedData);

                $convertedData = $this->fxService->convertExpenseAmount(
                    $dataForConversion,
                    $expense->client_id
                );

                // Merge converted data back into validated data
                $validatedData = array_merge($validatedData, $convertedData);
            }

            // Update the expense using the service
            $updatedExpense = $this->pocketExpenseService->updateExpense($expense, $validatedData);

            return response()->json([
                'data' => new PocketExpenseResource($updatedExpense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        } catch (\Exception $e) {
            Log::error('PocketExpenseController@update failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'expense_id' => $id,
                'request_data' => $request->validated()
            ]);

            return response()->json([
                'message' => 'An error occurred while updating the expense'
            ], 500);
        }
    }

    /**
     * Remove the specified pocket expense.
     * 
     * Performs soft delete by setting deleted flag and delete_time.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Find the expense
            $expense = PocketExpense::findOrFail($id);

            // Authorize delete action
            Gate::authorize('delete', $expense);

            // Delete the expense using the service (soft delete)
            $deleted = $this->pocketExpenseService->deleteExpense($expense);

            if (!$deleted) {
                return response()->json([
                    'message' => 'Failed to delete expense'
                ], 400);
            }

            return response()->json(null, 204);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        } catch (\Exception $e) {
            Log::error('PocketExpenseController@destroy failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'expense_id' => $id
            ]);

            return response()->json([
                'message' => 'An error occurred while deleting the expense'
            ], 500);
        }
    }
}