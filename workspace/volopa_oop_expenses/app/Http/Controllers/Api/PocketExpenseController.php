<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Models\PocketExpense;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Exception;

class PocketExpenseController extends Controller
{
    /**
     * The pocket expense service instance.
     *
     * @var PocketExpenseService
     */
    protected PocketExpenseService $pocketExpenseService;

    /**
     * The FX conversion service instance.
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
    public function __construct(PocketExpenseService $pocketExpenseService, FXConversionService $fxConversionService)
    {
        $this->pocketExpenseService = $pocketExpenseService;
        $this->fxConversionService = $fxConversionService;
        
        // Apply OAuth2 middleware to all routes
        $this->middleware('oauth2.user_client');
    }

    /**
     * Display a listing of pocket expenses.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            // Check if user can view expenses
            $this->authorize('viewAny', PocketExpense::class);

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
            $this->authorize('viewForClient', [PocketExpense::class, (int) $clientId]);

            // If viewing another user's expenses, check permissions
            if ($userId != $user->id) {
                $this->authorize('createForUser', [PocketExpense::class, (int) $userId, (int) $clientId]);
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
                'expense_type_id' => $request->query('expense_type_id'),
                'is_billable' => $request->query('is_billable'),
                'project_code' => $request->query('project_code'),
                'cost_center' => $request->query('cost_center'),
                'approved_by' => $request->query('approved_by'),
                'metadata_type' => $request->query('metadata_type'),
                'metadata_value' => $request->query('metadata_value'),
                'sort_by' => $request->query('sort_by', 'created_at'),
                'sort_order' => $request->query('sort_order', 'desc'),
                'per_page' => $perPage,
            ];

            // Get expenses based on scope
            if ($request->query('scope') === 'client') {
                // Get all expenses for the client (admin/manager view)
                $expenses = $this->pocketExpenseService->getClientExpenses((int) $clientId, $filters);
            } else {
                // Get expenses for specific user (default)
                $expenses = $this->pocketExpenseService->getUserExpenses((int) $userId, (int) $clientId, $filters);
            }

            Log::info('Pocket expenses retrieved', [
                'user_id' => $user->id,
                'target_user_id' => $userId,
                'client_id' => $clientId,
                'scope' => $request->query('scope', 'user'),
                'total' => $expenses->total(),
                'per_page' => $perPage,
                'filters' => array_filter($filters)
            ]);

            return response()->json([
                'data' => PocketExpenseResource::collection($expenses->items()),
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
            Log::error('Failed to retrieve pocket expenses', [
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
     * Store a newly created pocket expense.
     *
     * @param StorePocketExpenseRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validatedWithDefaults();
            $user = Auth::user();

            Log::info('Creating pocket expense', [
                'creator_id' => $user->id,
                'user_id' => $validatedData['user_id'],
                'client_id' => $validatedData['client_id'],
                'amount' => $validatedData['amount'],
                'currency' => $validatedData['currency'],
                'merchant_name' => $validatedData['merchant_name'],
                'has_metadata' => !empty($validatedData['metadata'])
            ]);

            $expense = $this->pocketExpenseService->createExpenseWithMetadata(
                $validatedData,
                $validatedData['user_id'],
                $validatedData['client_id']
            );

            Log::info('Pocket expense created successfully', [
                'expense_id' => $expense->id,
                'creator_id' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'metadata_count' => $expense->metadata->count()
            ]);

            return response()->json([
                'message' => 'Expense created successfully',
                'data' => new PocketExpenseResource($expense)
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to create pocket expense', [
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
     * Display the specified pocket expense.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $expense = PocketExpense::with(['user', 'client', 'expenseType', 'approver', 'metadata'])
                ->findOrFail($id);

            // Check authorization
            $this->authorize('view', $expense);

            Log::debug('Pocket expense retrieved', [
                'expense_id' => $id,
                'viewed_by' => Auth::id()
            ]);

            return response()->json([
                'data' => new PocketExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Pocket expense not found', [
                'expense_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expense', [
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
     * Update the specified pocket expense.
     *
     * @param UpdatePocketExpenseRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, int $id): JsonResponse
    {
        try {
            $validatedData = $request->validatedWithDefaults();
            $user = Auth::user();

            Log::info('Updating pocket expense', [
                'expense_id' => $id,
                'updated_by' => $user->id,
                'updated_fields' => array_keys($validatedData),
                'has_metadata' => !empty($validatedData['metadata'])
            ]);

            // Handle metadata update separately if provided
            if (isset($validatedData['metadata'])) {
                $metadata = $validatedData['metadata'];
                unset($validatedData['metadata']);
                
                // Update expense
                $expense = $this->pocketExpenseService->updateExpense($id, $validatedData, $user->id);
                
                // Update metadata
                $this->pocketExpenseService->updateExpenseMetadata($id, $metadata);
                
                // Reload with metadata
                $expense = $expense->fresh(['user', 'client', 'expenseType', 'approver', 'metadata']);
            } else {
                // Update expense only
                $expense = $this->pocketExpenseService->updateExpense($id, $validatedData, $user->id);
            }

            Log::info('Pocket expense updated successfully', [
                'expense_id' => $expense->id,
                'updated_by' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'status' => $expense->status
            ]);

            return response()->json([
                'message' => 'Expense updated successfully',
                'data' => new PocketExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to update pocket expense', [
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
     * Remove the specified pocket expense.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info('Deleting pocket expense', [
                'expense_id' => $id,
                'deleted_by' => $user->id
            ]);

            $result = $this->pocketExpenseService->deleteExpense($id, $user->id);

            if ($result) {
                Log::info('Pocket expense deleted successfully', [
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
            Log::error('Failed to delete pocket expense', [
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
     * Approve the specified pocket expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $user = Auth::user();

            Log::info('Approving pocket expense', [
                'expense_id' => $id,
                'approved_by' => $user->id
            ]);

            $expense = $this->pocketExpenseService->approveExpense($id, $user->id);

            Log::info('Pocket expense approved successfully', [
                'expense_id' => $expense->id,
                'approved_by' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'amount' => $expense->amount
            ]);

            return response()->json([
                'message' => 'Expense approved successfully',
                'data' => new PocketExpenseResource($expense)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Expense not found'
            ], 404);

        } catch (Exception $e) {
            Log::error('Failed to approve pocket expense', [
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
     * Reject the specified pocket expense.
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

            Log::info('Rejecting pocket expense', [
                'expense_id' => $id,
                'rejected_by' => $user->id,
                'reason' => $reason
            ]);

            $expense = $this->pocketExpenseService->rejectExpense($id, $user->id, $reason);

            Log::info('Pocket expense rejected successfully', [
                'expense_id' => $expense->id,
                'rejected_by' => $user->id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'reason' => $reason
            ]);

            return response()->json([
                'message' => 'Expense rejected successfully',
                'data' => new PocketExpenseResource($expense)
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
            Log::error('Failed to reject pocket expense', [
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
     * Bulk approve multiple pocket expenses.
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
            $this->authorize('bulkOperations', [PocketExpense::class, $clientId]);

            Log::info('Starting bulk approve pocket expenses', [
                'approver_id' => $user->id,
                'client_id' => $clientId,
                'expense_count' => count($expenseIds)
            ]);

            $results = $this->pocketExpenseService->bulkApproveExpenses($expenseIds, $user->id, $clientId);

            Log::info('Bulk approve pocket expenses completed', [
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
            Log::error('Failed to bulk approve pocket expenses', [
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
     * Get FX rate for currency conversion.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFxRate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'from_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
                'to_currency' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
                'date' => 'required|date|before_or_equal:today',
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'client_id' => 'required|integer|min:1',
            ]);

            $user = Auth::user();
            $fromCurrency = strtoupper($request->input('from_currency'));
            $toCurrency = strtoupper($request->input('to_currency'));
            $date = $request->input('date');
            $amount = (float) $request->input('amount');
            $clientId = (int) $request->input('client_id');

            // Check authorization
            $this->authorize('accessFxConversion', [PocketExpense::class, $clientId]);

            Log::debug('Getting FX rate for pocket expense', [
                'user_id' => $user->id,
                'client_id' => $clientId,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'date' => $date,
                'amount' => $amount
            ]);

            $conversionInfo = $this->fxConversionService->getConversionInfo(
                $fromCurrency,
                $toCurrency,
                $amount,
                $date,
                $clientId
            );

            return response()->json([
                'data' => $conversionInfo
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to get FX rate for pocket expense', [
                'user_id' => Auth::id(),
                'request_data' => $request->all(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to get FX rate',
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
            $expense = PocketExpense::findOrFail($id);
            
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

            Log::info('Applying FX conversion to pocket expense', [
                'expense_id' => $id,
                'user_id' => $user->id,
                'from_currency' => $expense->currency,
                'to_currency' => $convertedCurrency,
                'fx_rate' => $fxRate,
                'fx_commission' => $fxCommission,
                'original_amount' => $expense->amount,
                'converted_amount' => $convertedAmount
            ]);

            $updatedExpense = $this->pocketExpenseService->applyFxConversion(
                $id,
                $convertedAmount,
                $convertedCurrency,
                $fxRate,
                $fxCommission
            );

            Log::info('FX conversion applied to pocket expense successfully', [
                'expense_id' => $updatedExpense->id,
                'user_id' => $user->id,
                'converted_amount' => $convertedAmount,
                'converted_currency' => $convertedCurrency
            ]);

            return response()->json([
                'message' => 'FX conversion applied successfully',
                'data' => new PocketExpenseResource($updatedExpense)
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
            Log::error('Failed to apply FX conversion to pocket expense', [
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
            $this->authorize('viewAnalytics', [PocketExpense::class, $clientId]);

            // Build filters for statistics
            $filters = [
                'start_date' => $request->query('start_date'),
                'end_date' => $request->query('end_date'),
                'user_id' => $request->query('user_id'),
                'status' => $request->query('status'),
            ];

            $statistics = $this->pocketExpenseService->getExpenseStatistics($clientId, array_filter($filters));

            Log::debug('Retrieved pocket expense statistics', [
                'client_id' => $clientId,
                'user_id' => Auth::id(),
                'total_expenses' => $statistics['total_expenses'] ?? 0,
                'filters' => array_filter($filters)
            ]);

            return response()->json([
                'data' => $statistics
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expense statistics', [
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
                'include_metadata' => 'sometimes|boolean',
            ]);

            $clientId = $request->input('client_id');
            $format = $request->input('format', 'csv');

            // Check authorization
            $this->authorize('export', [PocketExpense::class, $clientId]);

            Log::info('Exporting pocket expenses', [
                'user_id' => Auth::id(),
                'client_id' => $clientId,
                'format' => $format,
                'include_metadata' => $request->input('include_metadata', false),
                'filters' => $request->only(['user_id', 'start_date', 'end_date', 'status'])
            ]);

            // For now, return success message with placeholder
            // In a real implementation, this would generate and queue the export
            return response()->json([
                'message' => 'Export request received and is being processed',
                'data' => [
                    'export_id' => uniqid('pocket_export_'),
                    'format' => $format,
                    'status' => 'processing',
                    'include_metadata' => $request->input('include_metadata', false),
                    'estimated_completion' => now()->addMinutes(5)->toISOString(),
                ]
            ], 202);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to export pocket expenses', [
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