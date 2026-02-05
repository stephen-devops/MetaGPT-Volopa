## Code: app/Http/Controllers/Api/PocketExpenseController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Http\Resources\PocketExpenseCollection;
use App\Models\PocketExpense;
use App\Services\PocketExpenseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

/**
 * PocketExpenseController
 * 
 * Main controller for pocket expense CRUD operations.
 * Handles expense creation, retrieval, updates, and deletion.
 * Implements proper authorization checks using policies and comprehensive error handling.
 */
class PocketExpenseController extends Controller
{
    /**
     * The pocket expense service instance.
     *
     * @var PocketExpenseService
     */
    private PocketExpenseService $service;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $service
     */
    public function __construct(PocketExpenseService $service)
    {
        $this->service = $service;
        
        // Apply middleware
        $this->middleware('auth:api');
        $this->middleware('oauth2.user_client');
    }

    /**
     * Get paginated list of pocket expenses with filters.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
                'user_id' => 'nullable|integer|exists:users,id',
                'status' => 'nullable|string|in:draft,submitted,approved,rejected',
                'currency' => 'nullable|string|size:3',
                'expense_type' => 'nullable|integer|exists:opt_pocket_expense_type,id',
                'date_from' => 'nullable|date|date_format:Y-m-d',
                'date_to' => 'nullable|date|date_format:Y-m-d|after_or_equal:date_from',
                'amount_min' => 'nullable|numeric|min:0',
                'amount_max' => 'nullable|numeric|gte:amount_min',
                'merchant_name' => 'nullable|string|max:255',
                'has_receipts' => 'nullable|boolean',
                'sort_by' => 'nullable|string|in:create_time,date,amount,merchant_name,currency,status',
                'sort_order' => 'nullable|string|in:asc,desc',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);

            $clientId = (int) $request->input('client_id');
            $userId = (int) ($request->input('user_id') ?: $request->user()->id);

            // Check authorization
            $user = $request->user();
            if (!$user->can('viewAny', [PocketExpense::class, $clientId])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view pocket expenses for this client.',
                    'error_code' => 'UNAUTHORIZED_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }

            // Prepare filters
            $filters = array_filter([
                'status' => $request->input('status'),
                'currency' => $request->input('currency'),
                'expense_type' => $request->input('expense_type'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'amount_min' => $request->input('amount_min'),
                'amount_max' => $request->input('amount_max'),
                'merchant_name' => $request->input('merchant_name'),
                'has_receipts' => $request->input('has_receipts'),
                'sort_by' => $request->input('sort_by', 'create_time'),
                'sort_order' => $request->input('sort_order', 'desc'),
                'per_page' => $request->input('per_page', 15),
            ], function ($value) {
                return $value !== null && $value !== '';
            });

            // Get expenses using service
            $expenses = $this->service->list($userId, $clientId, $filters);

            // Add metadata for collection
            $metadata = [
                'client_id' => $clientId,
                'user_id' => $userId,
                'filters_applied' => count($filters),
                'query_time' => microtime(true) - LARAVEL_START,
            ];

            return (new PocketExpenseCollection($expenses, $metadata))
                ->response()
                ->setStatusCode(Response::HTTP_OK);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expenses', [
                'client_id' => $request->input('client_id'),
                'user_id' => $request->input('user_id'),
                'request_user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pocket expenses.',
                'error_code' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new pocket expense.
     *
     * @param CreatePocketExpenseRequest $request
     * @return JsonResponse
     */
    public function store(CreatePocketExpenseRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $userId = (int) $validatedData['user_id'];
            $clientId = (int) $validatedData['client_id'];

            // Create the expense using service
            $expense = $this->service->create($validatedData, $userId, $clientId);

            Log::info('Pocket expense created successfully', [
                'expense_id' => $expense->id,
                'user_id' => $userId,
                'client_id' => $clientId,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'status' => $expense->status,
                'created_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseResource($expense),
                'message' => 'Pocket expense created successfully.'
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Failed to create pocket expense', [
                'user_id' => $request->input('user_id'),
                'client_id' => $request->input('client_id'),
                'request_user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create pocket expense.',
                'error_code' => 'CREATION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show a specific pocket expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Find the expense with relationships
            $expense = PocketExpense::active()
                ->with([
                    'expenseType',
                    'user',
                    'client',
                    'creator',
                    'updater',
                    'approver',
                    'metadata.expenseSource',
                    'metadata.transactionCategory',
                    'metadata.trackingCode',
                    'metadata.project',
                    'metadata.fileStore',
                    'metadata.additionalField'
                ])
                ->findOrFail($id);

            // Check authorization
            $user = $request->user();
            if (!$user->can('view', $expense)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this pocket expense.',
                    'error_code' => 'UNAUTHORIZED_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseResource($expense),
                'message' => 'Pocket expense retrieved successfully.'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pocket expense not found.',
                'error_code' => 'EXPENSE_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (Exception $e) {
            Log::error('Failed to retrieve pocket expense', [
                'expense_id' => $id,
                'request_user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pocket expense.',
                'error_code' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing pocket expense.
     *
     * @param UpdatePocketExpenseRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, int $id): JsonResponse
    {
        try {
            // Find the expense
            $expense = PocketExpense::active()->findOrFail($id);

            $validatedData = $request->validated();

            // Update the expense using service
            $updatedExpense = $this->service->update($expense, $validatedData);

            Log::info('Pocket expense updated successfully', [
                'expense_id' => $id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'updated_fields' => array_keys($validatedData),
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseResource($updatedExpense),
                'message' => 'Pocket expense updated successfully.'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pocket expense not found.',
                'error_code' => 'EXPENSE_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Failed to update pocket expense', [
                'expense_id' => $id,
                'request_user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update pocket expense.',
                'error_code' => 'UPDATE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete (soft delete) a pocket expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            // Find the expense
            $expense = PocketExpense::active()->findOrFail($id);

            // Check authorization
            $user = $request->user();
            if (!$user->can('delete', $expense)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this pocket expense.',
                    'error_code' => 'UNAUTHORIZED_DELETE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Delete the expense using service
            $deleted = $this->service->delete($expense);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete pocket expense. It may be in an invalid state.',
                    'error_code' => 'DELETE_FAILED'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            Log::info('Pocket expense deleted successfully', [
                'expense_id' => $id,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'deleted_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pocket expense deleted successfully.'
            ], Response::HTTP_NO_CONTENT);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pocket expense not found.',
                'error_code' => 'EXPENSE_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (Exception $e) {
            Log::error('Failed to delete pocket expense', [
                'expense_id' => $id,
                'request_user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete pocket expense.',
                'error_code' => 'DELETE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Approve a pocket expense.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            // Validate request
            $request->validate([
                'notes' => 'nullable|string|max:2000',
            ]);

            // Find the expense
            $expense = PocketExpense::active()->findOrFail($id);

            // Check authorization
            $user = $request->user();
            if (!$user->can('approve', $expense)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to approve this pocket expense.',
                    'error_code' => 'UNAUTHORIZED_APPROVE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if expense can be approved
            if (!$expense->isSubmitted()) {
                return response()->json([
                    'success' => false,
                    'message