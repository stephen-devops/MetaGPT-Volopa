## Code: app/Http/Controllers/Api/ExpenseSourceController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExpenseSourceRequest;
use App\Http\Requests\UpdateExpenseSourceRequest;
use App\Http\Resources\ExpenseSourceResource;
use App\Http\Resources\ExpenseSourceCollection;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\ExpenseSourceService;
use App\Policies\PocketExpensePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;

/**
 * ExpenseSourceController
 * 
 * Controller for managing expense source configurations.
 * Handles CRUD operations for client-specific and global expense sources.
 * Implements proper authorization checks and error handling.
 */
class ExpenseSourceController extends Controller
{
    /**
     * The expense source service instance.
     *
     * @var ExpenseSourceService
     */
    private ExpenseSourceService $expenseSourceService;

    /**
     * Create a new controller instance.
     *
     * @param ExpenseSourceService $expenseSourceService
     */
    public function __construct(ExpenseSourceService $expenseSourceService)
    {
        $this->expenseSourceService = $expenseSourceService;
        
        // Apply middleware
        $this->middleware('auth:api');
        $this->middleware('oauth2.user_client');
    }

    /**
     * Get all expense sources available for a client.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate required parameters
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
                'include_global' => 'nullable|boolean',
                'include_deleted' => 'nullable|boolean',
                'only_default' => 'nullable|boolean',
            ]);

            $clientId = (int) $request->input('client_id');
            $includeGlobal = $request->boolean('include_global', true);
            $includeDeleted = $request->boolean('include_deleted', false);
            $onlyDefault = $request->boolean('only_default', false);

            // Check authorization
            $user = $request->user();
            if (!$user->can('manageExpenseSources', [PocketExpense::class, $clientId])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to access expense sources for this client.',
                    'error_code' => 'UNAUTHORIZED_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }

            // Get expense sources
            if ($includeGlobal) {
                $sources = $this->expenseSourceService->getSourcesForClient($clientId, !$includeDeleted);
            } else {
                $sources = $this->expenseSourceService->getClientSpecificSources($clientId, !$includeDeleted);
            }

            // Filter for default sources only if requested
            if ($onlyDefault) {
                $sources = $sources->where('is_default', true);
            }

            // Add metadata
            $metadata = [
                'client_id' => $clientId,
                'total_count' => $sources->count(),
                'default_count' => $sources->where('is_default', true)->count(),
                'client_specific_count' => $sources->where('client_id', $clientId)->count(),
                'global_count' => $sources->whereNull('client_id')->count(),
                'can_add_more' => $this->expenseSourceService->canAddMoreSources($clientId),
                'max_allowed' => config('pocket_expense.sources.max_active_per_client', 20),
                'current_client_count' => $this->expenseSourceService->getClientSourcesCount($clientId),
            ];

            return (new ExpenseSourceCollection($sources, $metadata))
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
            Log::error('Failed to retrieve expense sources', [
                'client_id' => $request->input('client_id'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense sources.',
                'error_code' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show a specific expense source.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Find the expense source
            $source = PocketExpenseSourceClientConfig::active()->findOrFail($id);

            // Check authorization - user must have access to the client
            $user = $request->user();
            $clientId = $source->client_id ?? $request->input('client_id');
            
            if ($clientId && !$user->can('manageExpenseSources', [PocketExpense::class, $clientId])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to access this expense source.',
                    'error_code' => 'UNAUTHORIZED_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->json([
                'success' => true,
                'data' => new ExpenseSourceResource($source),
                'message' => 'Expense source retrieved successfully.'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense source not found.',
                'error_code' => 'SOURCE_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (Exception $e) {
            Log::error('Failed to retrieve expense source', [
                'source_id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense source.',
                'error_code' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new client-specific expense source.
     *
     * @param CreateExpenseSourceRequest $request
     * @return JsonResponse
     */
    public function store(CreateExpenseSourceRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $clientId = (int) $validatedData['client_id'];

            // Check if client can add more sources
            if (!$this->expenseSourceService->canAddMoreSources($clientId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum number of expense sources reached for this client.',
                    'error_code' => 'SOURCE_LIMIT_REACHED',
                    'max_allowed' => config('pocket_expense.sources.max_active_per_client', 20),
                    'current_count' => $this->expenseSourceService->getClientSourcesCount($clientId)
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Create the expense source
            $source = $this->expenseSourceService->createClientSource(
                $clientId,
                $validatedData['name'],
                $validatedData['is_default'] ?? false
            );

            Log::info('Expense source created successfully', [
                'source_id' => $source->id,
                'client_id' => $clientId,
                'name' => $validatedData['name'],
                'created_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => new ExpenseSourceResource($source),
                'message' => 'Expense source created successfully.'
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Failed to create expense source', [
                'client_id' => $request->input('client_id'),
                'name' => $request->input('name'),
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense source.',
                'error_code' => 'CREATION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing expense source.
     *
     * @param UpdateExpenseSourceRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateExpenseSourceRequest $request, int $id): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            // Find the expense source
            $source = PocketExpenseSourceClientConfig::active()->findOrFail($id);

            // Update the source
            $updatedSource = $this->expenseSourceService->updateSource($id, $validatedData);

            Log::info('Expense source updated successfully', [
                'source_id' => $id,
                'client_id' => $source->client_id,
                'updated_fields' => array_keys($validatedData),
                'updated_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => new ExpenseSourceResource($updatedSource),
                'message' => 'Expense source updated successfully.'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense source not found.',
                'error_code' => 'SOURCE_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Failed to update expense source', [
                'source_id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense source.',
                'error_code' => 'UPDATE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete (soft delete) an expense source.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            // Find the expense source
            $source = PocketExpenseSourceClientConfig::active()->findOrFail($id);

            // Check authorization
            $user = $request->user();
            if ($source->client_id && !$user->can('manageExpenseSources', [PocketExpense::class, $source->client_id])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this expense source.',
                    'error_code' => 'UNAUTHORIZED_DELETE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Delete the source
            $deleted = $this->expenseSourceService->deleteSource($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete expense source. It may be in use or is a global source.',
                    'error_code' => 'DELETE_FAILED'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            Log::info('Expense source deleted successfully', [
                'source_id' => $id,
                'client_id' => $source->client_id,
                'name' => $source->name,
                'deleted_by' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense source deleted successfully.'
            ], Response::HTTP_NO_CONTENT);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Expense source not found.',
                'error_code' => 'SOURCE_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete expense source.',
                'errors' => $e->errors(),
                'error_code' => 'DELETE_VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Failed to delete expense source', [
                'source_id' => $id,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense source.',
                'error_code' => 'DELETE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Restore a soft-deleted expense source.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        try {
            // Validate request
            $request->validate([
                'client_id' => 'required|integer|exists:clients,id',
            ]);

            $clientId = (int) $request->input('client_id');

            // Check authorization
            $user = $request->user();
            if (!$user->can('manageExpenseSources', [PocketExpense::class, $clientId])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to restore expense sources for this client.',
                    'error_code' => 'UNAUTHORIZED_RESTORE'
                ], Response::HTTP_FORBIDDEN);
            }

            // Restore the source
            $restored = $this->expenseSourceService->restoreSource($id);

            if (!$restored) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to restore expense source. It may not exist or is not deleted.',
                    'error_code' => 'RESTORE_FAILED'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Get the restored source
            $source =