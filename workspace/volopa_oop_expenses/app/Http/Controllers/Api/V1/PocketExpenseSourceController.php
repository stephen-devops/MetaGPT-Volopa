<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseSourceRequest;
use App\Http\Requests\UpdatePocketExpenseSourceRequest;
use App\Http\Resources\PocketExpenseSourceResource;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\PocketExpenseSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pocket Expense Source Controller
 * 
 * Handles CRUD operations for client expense source configurations.
 * Manages up to 20 active expense sources per client with unique names.
 * Protects global 'Other' record from deletion/editing.
 */
class PocketExpenseSourceController extends Controller
{
    /**
     * The expense source service instance.
     *
     * @var PocketExpenseSourceService
     */
    protected PocketExpenseSourceService $expenseSourceService;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseSourceService $expenseSourceService
     */
    public function __construct(PocketExpenseSourceService $expenseSourceService)
    {
        $this->expenseSourceService = $expenseSourceService;
        
        // Apply OAuth2 middleware to all routes
        $this->middleware('oauth2');
    }

    /**
     * Display a paginated listing of expense sources for the client.
     * Returns active sources + global sources available to the client.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // TODO: Extract client_id from authenticated user context
            $clientId = (int) $request->input('client_id');
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                ], 400);
            }

            // Get available expense sources for the client (active + global)
            $sources = PocketExpenseSourceClientConfig::availableForClient($clientId)
                ->orderBy('is_default', 'desc')
                ->orderBy('name', 'asc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => PocketExpenseSourceResource::collection($sources),
                'meta' => [
                    'current_page' => $sources->currentPage(),
                    'last_page' => $sources->lastPage(),
                    'per_page' => $sources->perPage(),
                    'total' => $sources->total(),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense sources',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Store a newly created expense source.
     * Enforces 20 active sources per client limit and unique name constraint.
     *
     * @param StorePocketExpenseSourceRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseSourceRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            // Create the expense source via service
            $source = $this->expenseSourceService->create($validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Expense source created successfully',
                'data' => new PocketExpenseSourceResource($source),
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
                'message' => 'Failed to create expense source',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Display the specified expense source.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $source = $this->expenseSourceService->findById($id);

            if (!$source) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense source not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseSourceResource($source),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense source',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the specified expense source.
     * Prevents editing of global 'Other' record.
     *
     * @param UpdatePocketExpenseSourceRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseSourceRequest $request, int $id): JsonResponse
    {
        try {
            $validatedData = $request->validated();

            // Update the expense source via service
            $source = $this->expenseSourceService->update($id, $validatedData);

            if (!$source) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense source not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Expense source updated successfully',
                'data' => new PocketExpenseSourceResource($source),
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
            
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense source',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Remove the specified expense source (soft delete).
     * Prevents deletion of global 'Other' record.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->expenseSourceService->delete($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense source not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Expense source deleted successfully',
            ], 204);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense source',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get the count of active expense sources for a client.
     * Used to enforce the 20 active sources limit.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function count(Request $request): JsonResponse
    {
        try {
            // TODO: Extract client_id from authenticated user context
            $clientId = (int) $request->input('client_id');
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                ], 400);
            }

            $count = PocketExpenseSourceClientConfig::forClient($clientId)
                ->active()
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'client_id' => $clientId,
                    'active_sources_count' => $count,
                    'max_allowed' => 20,
                    'can_create_more' => $count < 20,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to count expense sources',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get default expense sources for a client.
     * Returns the 3 auto-created defaults: Cash, Corporate Card, Personal Card.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function defaults(Request $request): JsonResponse
    {
        try {
            // TODO: Extract client_id from authenticated user context
            $clientId = (int) $request->input('client_id');
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client ID is required',
                ], 400);
            }

            $defaultSources = PocketExpenseSourceClientConfig::forClient($clientId)
                ->active()
                ->default()
                ->orderBy('name', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => PocketExpenseSourceResource::collection($defaultSources),
                'meta' => [
                    'total' => $defaultSources->count(),
                    'expected_defaults' => ['Cash', 'Corporate Card', 'Personal Card'],
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve default expense sources',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Restore a soft-deleted expense source.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function restore(int $id): JsonResponse
    {
        try {
            $source = $this->expenseSourceService->restore($id);

            if (!$source) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense source not found or not deleted',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Expense source restored successfully',
                'data' => new PocketExpenseSourceResource($source),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore expense source',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}