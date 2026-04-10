<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePocketExpenseSourceRequest;
use App\Http\Requests\UpdatePocketExpenseSourceRequest;
use App\Http\Resources\PocketExpenseSourceResource;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\PocketExpenseSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PocketExpenseSourceController
 * 
 * Handles CRUD operations for expense sources within client context.
 * Manages client-specific expense sources with proper authorization.
 * All operations are scoped by client_id for multi-tenancy.
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
    }

    /**
     * Display a listing of expense sources for the specified client.
     * Returns active sources only, excluding soft-deleted sources.
     * Includes the global 'Other' source for all clients.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Validate that client_id is provided in query parameters
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id'
        ]);

        $clientId = (int) $request->query('client_id');

        try {
            // Authorize the request - user must have permission to view sources for this client
            $this->authorize('viewAny', [PocketExpenseSourceClientConfig::class, $clientId]);

            // Get sources for the client through the service
            $sources = $this->expenseSourceService->getSourcesForClient($clientId);

            // Return paginated collection of sources
            return response()->json([
                'success' => true,
                'data' => PocketExpenseSourceResource::collection($sources),
                'message' => 'Expense sources retrieved successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense sources: ' . $e->getMessage(),
                'errors' => []
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created expense source in storage.
     * Creates a new expense source for the specified client.
     * Validates unique source names per client and enforces 20 source limit.
     *
     * @param StorePocketExpenseSourceRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseSourceRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $clientId = (int) $validatedData['client_id'];

            // Authorize the request - user must have permission to create sources for this client
            $this->authorize('create', [PocketExpenseSourceClientConfig::class, $clientId]);

            // Create the expense source through the service
            $source = $this->expenseSourceService->createSource($validatedData, $clientId);

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseSourceResource($source),
                'message' => 'Expense source created successfully'
            ], Response::HTTP_CREATED);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense source: ' . $e->getMessage(),
                'errors' => []
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified expense source.
     * Returns details for a specific expense source by ID.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @return JsonResponse
     */
    public function show(PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Authorize the request - user must have permission to view this source
            $this->authorize('view', $source);

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseSourceResource($source),
                'message' => 'Expense source retrieved successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve expense source: ' . $e->getMessage(),
                'errors' => []
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified expense source in storage.
     * Updates an existing expense source with new data.
     * Prevents updates to the global 'Other' source.
     *
     * @param UpdatePocketExpenseSourceRequest $request
     * @param PocketExpenseSourceClientConfig $source
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseSourceRequest $request, PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Authorize the request - user must have permission to update this source
            $this->authorize('update', $source);

            // Prevent editing the global 'Other' source as per constraints
            if ($source->isGlobalOther()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The global Other source cannot be edited',
                    'errors' => []
                ], Response::HTTP_FORBIDDEN);
            }

            $validatedData = $request->validated();

            // Update the expense source through the service
            $updatedSource = $this->expenseSourceService->updateSource($source, $validatedData);

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseSourceResource($updatedSource),
                'message' => 'Expense source updated successfully'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense source: ' . $e->getMessage(),
                'errors' => []
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified expense source from storage.
     * Performs soft delete by setting deleted flag and timestamp.
     * Prevents deletion of the global 'Other' source and default sources.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @return JsonResponse
     */
    public function destroy(PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Authorize the request - user must have permission to delete this source
            $this->authorize('delete', $source);

            // Prevent deletion of the global 'Other' source as per constraints
            if ($source->isGlobalOther()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The global Other source cannot be deleted',
                    'errors' => []
                ], Response::HTTP_FORBIDDEN);
            }

            // Delete the expense source through the service
            $deleted = $this->expenseSourceService->deleteSource($source);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Expense source deleted successfully'
                ], Response::HTTP_NO_CONTENT);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete expense source',
                    'errors' => []
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense source: ' . $e->getMessage(),
                'errors' => []
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}