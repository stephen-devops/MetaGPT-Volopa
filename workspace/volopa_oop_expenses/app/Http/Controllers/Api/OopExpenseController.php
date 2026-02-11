<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOopExpenseRequest;
use App\Http\Requests\UpdateOopExpenseRequest;
use App\Http\Resources\OopExpenseResource;
use App\Models\OopExpense;
use App\Services\OopExpenseService;
use App\Services\FXConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class OopExpenseController extends Controller
{
    /**
     * The OOP expense service instance.
     *
     * @var OopExpenseService
     */
    protected OopExpenseService $oopExpenseService;

    /**
     * The FX conversion service instance.
     *
     * @var FXConversionService
     */
    protected FXConversionService $fxConversionService;

    /**
     * Create a new controller instance.
     *
     * @param OopExpenseService $oopExpenseService
     * @param FXConversionService $fxConversionService
     */
    public function __construct(OopExpenseService $oopExpenseService, FXConversionService $fxConversionService)
    {
        $this->oopExpenseService = $oopExpenseService;
        $this->fxConversionService = $fxConversionService;
        
        // Apply OAuth2 middleware to all routes
        $this->middleware('oauth2.user_client');
    }

    /**
     * Display a listing of OOP expenses.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Check if user can view expenses
            $this->authorize('viewAny', OopExpense::class);

            // Get query parameters
            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
            $userId = $request->query('user_id', $user->id);
            $clientId = $request->query('client_id');
            
            // Validate required parameters
            if (!$clientId) {
                return response()->json([
                    'message' => 'Client ID is required'
                ], 400);
            }

            // Check if user can view expenses for this client
            $this->authorize('viewForClient', [OopExpense::class, (int) $clientId]);

            // If viewing another user's expenses, check permissions
            if ($userId != $user->id) {
                $this->authorize('createForUser', [OopExpense::class, (int) $userId, (int) $clientId]);
            }

            // Build filters array
            $filters = [
                'status' => $request->query('status'),
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
                'min_amount' => $request->query('min_amount'),
                'max_amount' => $request->query('max_amount'),
                'currency' => $request->query('currency'),
                'search' => $request->query('search'),
                'category' => $request->query('category'),
                'is_reimbursable' => $request->query('is_reimbursable'),
                'project_code' => $request->query('project_code'),
                'cost_center' => $request->query('cost_center'),
                'approved_by' => $request->query('approved_by'),
                'sort_by' => $request->query('sort_by', 'created_at'),
                'sort_order' => $request->query('sort_order', 'desc'),
                'per_page' => $perPage,
            ];

            // Get expenses based on scope
            if ($request->query('scope') === 'client') {
                // Get all expenses for the client (admin/manager view)
                $expenses = $this->oopExpenseService->getClientExpenses((int) $clientId, $filters);
            } else {
                // Get expenses for specific user (default)
                $expenses = $this->oopExpenseService->getUserExpenses((int) $userId, (int) $clientId, $filters);
            }

            Log::info('OOP expenses retrieved', [
                'user_id' => $user->id,
                'target_user_id' => $userId,
                'client_id' => $clientId,
                'scope' => $request->query('scope', 'user'),
                'total' => $expenses->total(),
                'per_page' => $perPage,
                'filters' => array_filter($filters)
            ]);

            return response()->json([
                'data' => OopExpenseResource::collection($expenses->items()),
                'meta' => [
                    'current_page' => $expenses->currentPage(),
                    'from' => $expenses->firstItem(),
                    'last_page' => $expenses->lastPage(),
                    'path' => $expenses->path(),
                    'per_page' => $expenses->perPage(),
                    'to' => $expenses->lastItem(),
                    'total' => $expenses->total(),
                ],
                'links' => [
                    'first' => $expenses->url(1),
                    'last' => $expenses->url($expenses->lastPage()),
                    'prev' => $expenses->previousPageUrl(),
                    'next' => $expenses->nextPageUrl(),
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve OOP expenses', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expenses',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Store a newly created OOP expense.
     *
     * @param StoreOopExpenseRequest $request
     * @return JsonResponse
     */
    public function store(StoreOopExpenseRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validatedWithDefaults();
            $user = Auth::user();

            Log::info('Creating OOP expense', [
                'creator_id' => $user->id,
                'user_id' => $validatedData['user_id'],
                'client_id' => $validatedData['client_id'],
                'amount' => $validatedData['amount'],
                'currency' => $validatedData['currency'],
                'merchant_name' => $validatedData['merchant_name']
            ]);

            $expense = $this->oopExpenseService->createExpense(
                $validatedData,
                $validatedData['user_id'],
                $validatedData['client_id']
            );

            Log::info('OOP expense created successfully', [
                'expense_id' => $expense->id,
                'creator_id' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'amount' => $expense->amount,
                'currency' => $expense->currency
            ]);

            return response()->json([
                'message' => 'Expense created successfully',
                'data' => new OopExpenseResource($expense)
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to create OOP expense', [
                'creator_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to create expense',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Display the specified OOP expense.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $expense = OopExpense::with(['user', 'client', 'approver'])->findOrFail($id);

            // Check authorization
            $this->authorize('view', $expense);

            Log::debug('OOP expense retrieved', [
                'expense_id' => $id,
                'viewed_by' => Auth::id()
            ]);

            return response()->json([
                'data' => new OopExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('OOP expense not found', [
                'expense_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to retrieve OOP expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expense',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Update the specified OOP expense.
     *
     * @param UpdateOopExpenseRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateOopExpenseRequest $request, int $id): JsonResponse
    {
        try {
            $validatedData = $request->validatedWithDefaults();
            $user = Auth::user();

            Log::info('Updating OOP expense', [
                'expense_id' => $id,
                'updated_by' => $user->id,
                'updated_fields' => array_keys($validatedData)
            ]);

            $expense = $this->oopExpenseService->updateExpense($id, $validatedData, $user->id);

            Log::info('OOP expense updated successfully', [
                'expense_id' => $expense->id,
                'updated_by' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'status' => $expense->status
            ]);

            return response()->json([
                'message' => 'Expense updated successfully',
                'data' => new OopExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to update OOP expense', [
                'expense_id' => $id,
                'updated_by' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to update expense',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Remove the specified OOP expense.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info('Deleting OOP expense', [
                'expense_id' => $id,
                'deleted_by' => $user->id
            ]);

            $result = $this->oopExpenseService->deleteExpense($id, $user->id);

            if ($result) {
                Log::info('OOP expense deleted successfully', [
                    'expense_id' => $id,
                    'deleted_by' => $user->id
                ]);

                return response()->json([
                    'message' => 'Expense deleted successfully'
                ], 200);
            }

            return response()->json([
                'message' => 'Failed to delete expense'
            ], 500);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to delete OOP expense', [
                'expense_id' => $id,
                'deleted_by' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to delete expense',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Approve the specified OOP expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info('Approving OOP expense', [
                'expense_id' => $id,
                'approved_by' => $user->id
            ]);

            $expense = $this->oopExpenseService->approveExpense($id, $user->id);

            Log::info('OOP expense approved successfully', [
                'expense_id' => $expense->id,
                'approved_by' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'amount' => $expense->amount
            ]);

            return response()->json([
                'message' => 'Expense approved successfully',
                'data' => new OopExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to approve OOP expense', [
                'expense_id' => $id,
                'approved_by' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to approve expense',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Reject the specified OOP expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'reason' => 'required|string|min:10|max:1000',
            ]);

            $user = Auth::user();
            $reason = $request->input('reason');

            Log::info('Rejecting OOP expense', [
                'expense_id' => $id,
                'rejected_by' => $user->id,
                'reason' => $reason
            ]);

            $expense = $this->oopExpenseService->rejectExpense($id, $user->id, $reason);

            Log::info('OOP expense rejected successfully', [
                'expense_id' => $expense->id,
                'rejected_by' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'reason' => $reason
            ]);

            return response()->json([
                'message' => 'Expense rejected successfully',
                'data' => new OopExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to reject OOP expense', [
                'expense_id' => $id,
                'rejected_by' => Auth::id(),
                'reason' => $request->input('reason'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to reject expense',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Bulk approve multiple OOP expenses.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'expense_ids' => 'required|array|min:1|max:100',
                'expense_ids.*' => 'required|integer|min:1',
                'client_id' => 'required|integer|min:1',
            ]);

            $user = Auth::user();
            $expenseIds = $request->input('expense_ids');
            $clientId = $request->input('client_id');

            // Check authorization for bulk operations
            $this->authorize('bulkOperations', [OopExpense::class, $clientId]);

            Log::info('Starting bulk approve OOP expenses', [
                'approver_id' => $user->id,
                'client_id' => $clientId,
                'expense_count' => count($expenseIds)
            ]);

            $results = $this->oopExpenseService->bulkApproveExpenses($expenseIds, $user->id, $clientId);

            Log::info('Bulk approve OOP expenses completed', [
                'approver_id' => $user->id,
                'results' => $results
            ]);

            return response()->json([
                'message' => 'Bulk approve completed',
                'data' => $results
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to bulk approve OOP expenses', [
                'approver_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to bulk approve expenses',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Get expense statistics for a client.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatistics(Request $request): JsonResponse
    {
        try {
            $clientId = (int) $request->query('client_id');

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client ID is required'
                ], 400);
            }

            // Check authorization
            $this->authorize('viewAnalytics', [OopExpense::class, $clientId]);

            // Build filters for statistics
            $filters = [
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
                'user_id' => $request->query('user_id'),
                'status' => $request->query('status'),
            ];

            $statistics = $this->oopExpenseService->getExpenseStatistics($clientId, array_filter($filters));

            Log::debug('Retrieved OOP expense statistics', [
                'client_id' => $clientId,
                'user_id' => Auth::id(),
                'total_expenses' => $statistics['total_expenses'] ?? 0,
                'filters' => array_filter($filters)
            ]);

            return response()->json([
                'data' => $statistics
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve OOP expense statistics', [
                'client_id' => $request->query('client_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Apply FX conversion to an expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function applyFxConversion(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'converted_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
                'fx_rate' => 'sometimes|numeric|min:0.000001|max:999999.999999',
                'fx_commission' => 'sometimes|numeric|min:0|max:99.9999',
            ]);

            $user = Auth::user();
            $convertedCurrency = strtoupper($request->input('converted_currency'));
            $fxRate = $request->input('fx_rate');
            $fxCommission = $request->input('fx_commission', 0.0000);

            // Get the expense
            $expense = OopExpense::findOrFail($id);
            
            // Check authorization
            $this->authorize('update', $expense);

            // Get FX rate if not provided
            if (!$fxRate) {
                $fxRate = $this->fxConversionService->getFXRate(
                    $expense->currency,
                    $convertedCurrency,
                    $expense->date
                );

                // Get client commission if not provided
                if ($request->missing('fx_commission')) {
                    $fxCommission = $this->fxConversionService->getClientCommission($expense->client_id);
                }
            }

            // Calculate converted amount
            $convertedAmount = $this->fxConversionService->calculateConvertedAmount(
                $expense->amount,
                $fxRate,
                $fxCommission
            );

            Log::info('Applying FX conversion to OOP expense', [
                'expense_id' => $id,
                'user_id' => $user->id,
                'from_currency' => $expense->currency,
                'to_currency' => $convertedCurrency,
                'fx_rate' => $fxRate,
                'fx_commission' => $fxCommission,
                'original_amount' => $expense->amount,
                'converted_amount' => $convertedAmount
            ]);

            $updatedExpense = $this->oopExpenseService->applyFxConversion(
                $id,
                $convertedAmount,
                $convertedCurrency,
                $fxRate,
                $fxCommission
            );

            Log::info('FX conversion applied to OOP expense successfully', [
                'expense_id' => $updatedExpense->id,
                'user_id' => $user->id,
                'converted_amount' => $convertedAmount,
                'converted_currency' => $convertedCurrency
            ]);

            return response()->json([
                'message' => 'FX conversion applied successfully',
                'data' => new OopExpenseResource($updatedExpense)
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to apply FX conversion to OOP expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to apply FX conversion',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Get FX conversion information for an expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function getFxConversionInfo(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'target_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            ]);

            $targetCurrency = strtoupper($request->input('target_currency'));

            // Get the expense
            $expense = OopExpense::findOrFail($id);
            
            // Check authorization
            $this->authorize('view', $expense);

            Log::debug('Getting FX conversion info for OOP expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'from_currency' => $expense->currency,
                'to_currency' => $targetCurrency
            ]);

            $conversionInfo = $this->fxConversionService->getConversionInfo(
                $expense->currency,
                $targetCurrency,
                $expense->amount,
                $expense->date,
                $expense->client_id
            );

            return response()->json([
                'data' => $conversionInfo
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to get FX conversion info for OOP expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to get FX conversion info',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Export expenses to various formats.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'client_id' => 'required|integer|min:1',
                'format' => 'sometimes|string|in:csv,xlsx,pdf',
                'user_id' => 'sometimes|integer|min:1',
                'start_date' => 'sometimes|date',
                'end_date' => 'sometimes|date|after_or_equal:start_date',
                'status' => 'sometimes|string|in:pending,approved,rejected,processing',
            ]);

            $clientId = $request->input('client_id');
            $format = $request->input('format', 'csv');

            // Check authorization
            $this->authorize('export', [OopExpense::class, $clientId]);

            Log::info('Exporting OOP expenses', [
                'user_id' => Auth::id(),
                'client_id' => $clientId,
                'format' => $format,
                'filters' => $request->only(['user_id', 'start_date', 'end_date', 'status'])
            ]);

            // For now, return success message with placeholder
            // In a real implementation, this would generate and queue the export
            return response()->json([
                'message' => 'Export request received and is being processed',
                'data' => [
                    'export_id' => uniqid('export_'),
                    'format' => $format,
                    'status' => 'processing',
                    'estimated_completion' => now()->addMinutes(5)->toISOString(),
                ]
            ], 202);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to export OOP expenses', [
                'user_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to export expenses',
                'error' => $e->getMessage()
            ], $this->getErrorStatusCode($e));
        }
    }

    /**
     * Get the appropriate HTTP status code for an exception.
     *
     * @param Exception $exception
     * @return int
     */
    private function getErrorStatusCode(Exception $exception): int
    {
        if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return 403;
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 404;
        }

        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return 422;
        }

        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        return 500;
    }
}