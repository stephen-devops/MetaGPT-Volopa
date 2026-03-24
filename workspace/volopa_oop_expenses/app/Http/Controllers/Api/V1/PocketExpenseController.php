<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseRequest;
use App\Http\Requests\UpdatePocketExpenseRequest;
use App\Http\Resources\PocketExpenseResource;
use App\Models\PocketExpense;
use App\Services\PocketExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Controller for managing Out-of-Pocket expenses.
 * Follows thin controller pattern by delegating business logic to PocketExpenseService.
 * 
 * All endpoints require OAuth2 authentication via Oauth2UserClient middleware.
 * Data is scoped by client_id for multi-tenancy.
 * Authorization is enforced via PocketExpensePolicy.
 */
class PocketExpenseController extends Controller
{
    /**
     * The pocket expense service instance.
     */
    protected PocketExpenseService $pocketExpenseService;

    /**
     * Create a new controller instance.
     */
    public function __construct(PocketExpenseService $pocketExpenseService)
    {
        $this->pocketExpenseService = $pocketExpenseService;
        
        // Apply OAuth2 middleware for all routes
        $this->middleware('Oauth2UserClient');
        
        // Apply authorization policies
        $this->authorizeResource(PocketExpense::class, 'expense');
    }

    /**
     * Display a listing of pocket expenses for the authenticated user.
     * 
     * Supports filtering, sorting, and pagination.
     * Returns expenses scoped to the user's client and their access permissions.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            // Get authenticated user from OAuth2 middleware
            $user = $request->user();
            
            // Validate client_id is provided and user belongs to client
            $clientId = $request->input('client_id');
            if (!$clientId) {
                abort(400, 'Client ID is required');
            }
            
            // Get expenses using service layer with proper scoping
            $expenses = $this->pocketExpenseService->findByUser(
                user: $user,
                clientId: (int) $clientId,
                filters: $request->only([
                    'status',
                    'date_from', 
                    'date_to',
                    'currency',
                    'expense_type',
                    'merchant_name',
                    'amount_min',
                    'amount_max'
                ]),
                sortBy: $request->input('sort_by', 'date'),
                sortDirection: $request->input('sort_direction', 'desc'),
                perPage: min((int) $request->input('per_page', 15), 100) // Max 100 per page
            );
            
            return PocketExpenseResource::collection($expenses);
            
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
     * Creates a new expense with FX conversion and metadata.
     * Uses transactions for data integrity.
     *
     * @param \App\Http\Requests\StorePocketExpenseRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StorePocketExpenseRequest $request): JsonResponse
    {
        try {
            // Request validation and authorization already handled by FormRequest and Policy
            $validatedData = $request->validated();
            
            // Create expense using service layer
            $expense = $this->pocketExpenseService->create(
                data: $validatedData,
                userId: $request->user()->id,
                clientId: (int) $validatedData['client_id']
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => new PocketExpenseResource($expense)
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
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
     * Returns expense details with related metadata.
     * Authorization enforced via policy.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(PocketExpense $expense): JsonResponse
    {
        try {
            // Authorization already handled by authorizeResource
            // Load related metadata for complete expense details
            $expense->load([
                'expenseType',
                'metadata' => function ($query) {
                    $query->where('deleted', false);
                },
                'metadata.expenseSource',
                'user:id,name,email',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
                'approvedBy:id,name,email'
            ]);
            
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
     * Updates expense data with FX recalculation and metadata.
     * Uses transactions for data integrity.
     *
     * @param \App\Http\Requests\UpdatePocketExpenseRequest $request
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdatePocketExpenseRequest $request, PocketExpense $expense): JsonResponse
    {
        try {
            // Request validation and authorization already handled by FormRequest and Policy
            $validatedData = $request->validated();
            
            // Update expense using service layer
            $updatedExpense = $this->pocketExpenseService->update(
                expense: $expense,
                data: $validatedData,
                updatedByUserId: $request->user()->id
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => new PocketExpenseResource($updatedExpense)
            ], 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
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
     * Performs soft delete using flag-based soft delete pattern.
     * Updates delete_time and sets deleted flag to true.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(PocketExpense $expense): JsonResponse
    {
        try {
            // Authorization already handled by authorizeResource
            
            // Delete expense using service layer
            $deleted = $this->pocketExpenseService->delete(
                expense: $expense,
                deletedByUserId: request()->user()->id
            );
            
            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete expense'
                ], 500);
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

    /**
     * Approve the specified pocket expense.
     * 
     * Changes expense status to 'approved'.
     * Only users with approval rights can perform this action.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve(PocketExpense $expense): JsonResponse
    {
        try {
            // Check approval authorization
            Gate::authorize('approve', $expense);
            
            // Approve expense using service layer
            $approvedExpense = $this->pocketExpenseService->approve(
                expense: $expense,
                approvedByUserId: request()->user()->id
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Expense approved successfully',
                'data' => new PocketExpenseResource($approvedExpense)
            ], 200);
            
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to approve this expense'
            ], 403);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Reject the specified pocket expense.
     * 
     * Changes expense status to 'rejected' with optional rejection reason.
     * Only users with approval rights can perform this action.
     *
     * @param \App\Models\PocketExpense $expense
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject(PocketExpense $expense, Request $request): JsonResponse
    {
        try {
            // Check approval authorization
            Gate::authorize('approve', $expense);
            
            // Validate rejection reason if provided
            $validated = $request->validate([
                'rejection_reason' => 'nullable|string|max:500'
            ]);
            
            // Reject expense using service layer
            $rejectedExpense = $this->pocketExpenseService->reject(
                expense: $expense,
                rejectedByUserId: $request->user()->id,
                rejectionReason: $validated['rejection_reason'] ?? null
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Expense rejected successfully',
                'data' => new PocketExpenseResource($rejectedExpense)
            ], 200);
            
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to reject this expense'
            ], 403);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Submit the specified draft expense for approval.
     * 
     * Changes expense status from 'draft' to 'submitted'.
     * Only draft expenses can be submitted.
     *
     * @param \App\Models\PocketExpense $expense
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit(PocketExpense $expense): JsonResponse
    {
        try {
            // Check if expense can be submitted
            if ($expense->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only draft expenses can be submitted'
                ], 400);
            }
            
            // Check update authorization
            Gate::authorize('update', $expense);
            
            // Submit expense using service layer
            $submittedExpense = $this->pocketExpenseService->submit(
                expense: $expense,
                submittedByUserId: request()->user()->id
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Expense submitted successfully',
                'data' => new PocketExpenseResource($submittedExpense)
            ], 200);
            
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to submit this expense'
            ], 403);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit expense',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get expense summary statistics for the authenticated user.
     * 
     * Returns counts by status, total amounts, recent activity, etc.
     * Useful for dashboard displays.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            // Validate client_id is provided
            $clientId = $request->input('client_id');
            if (!$clientId) {
                abort(400, 'Client ID is required');
            }
            
            // Get summary statistics using service layer
            $summary = $this->pocketExpenseService->getSummary(
                user: $request->user(),
                clientId: (int) $clientId,
                dateFrom: $request->input('date_from'),
                dateTo: $request->input('date_to')
            );
            
            return response()->json([
                'success' => true,
                'data' => $summary
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense summary',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Bulk update multiple expenses.
     * 
     * Allows updating status, approval, or other fields for multiple expenses.
     * Uses transactions to ensure all-or-nothing updates.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            // Validate bulk update request
            $validated = $request->validate([
                'expense_ids' => 'required|array|max:50', // Limit bulk operations
                'expense_ids.*' => 'required|integer|exists:pocket_expense,id',
                'action' => 'required|string|in:approve,reject,submit,delete',
                'client_id' => 'required|integer|exists:clients,id',
                'rejection_reason' => 'nullable|string|max:500|required_if:action,reject'
            ]);
            
            // Perform bulk update using service layer
            $result = $this->pocketExpenseService->bulkUpdate(
                expenseIds: $validated['expense_ids'],
                action: $validated['action'],
                userId: $request->user()->id,
                clientId: (int) $validated['client_id'],
                rejectionReason: $validated['rejection_reason'] ?? null
            );
            
            return response()->json([
                'success' => true,
                'message' => "Bulk {$validated['action']} completed successfully",
                'data' => [
                    'processed' => $result['processed'],
                    'failed' => $result['failed'],
                    'errors' => $result['errors']
                ]
            ], 200);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk update',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}