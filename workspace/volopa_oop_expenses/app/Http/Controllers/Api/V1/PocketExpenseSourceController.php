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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * PocketExpenseSourceController
 * 
 * REST API controller for expense source configuration management.
 * Handles CRUD operations for client-specific expense sources with proper authorization.
 * Enforces system constraints: max 20 active sources per client, unique names, soft delete.
 */
class PocketExpenseSourceController extends Controller
{
    /**
     * The expense source service instance.
     *
     * @var PocketExpenseSourceService
     */
    protected PocketExpenseSourceService $sourceService;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseSourceService $sourceService
     */
    public function __construct(PocketExpenseSourceService $sourceService)
    {
        $this->sourceService = $sourceService;
    }

    /**
     * Display a listing of expense sources.
     * 
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Authorization check
        $this->authorize('viewAny', PocketExpenseSourceClientConfig::class);

        // Validate required client_id parameter
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id'
        ]);

        $clientId = (int) $request->get('client_id');

        // Get sources for the client using service
        $sources = $this->sourceService->getClientSources($clientId);

        return PocketExpenseSourceResource::collection($sources);
    }

    /**
     * Store a newly created expense source.
     * 
     * @param StorePocketExpenseSourceRequest $request
     * @return JsonResponse
     */
    public function store(StorePocketExpenseSourceRequest $request): JsonResponse
    {
        // Authorization is handled in the FormRequest

        // Create source using service
        $source = $this->sourceService->createSource($request->validated());

        return response()->json([
            'data' => new PocketExpenseSourceResource($source)
        ], 201);
    }

    /**
     * Display the specified expense source.
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $source = PocketExpenseSourceClientConfig::findOrFail($id);

        // Authorization check
        $this->authorize('view', $source);

        return response()->json([
            'data' => new PocketExpenseSourceResource($source)
        ]);
    }

    /**
     * Update the specified expense source.
     * 
     * @param UpdatePocketExpenseSourceRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdatePocketExpenseSourceRequest $request, int $id): JsonResponse
    {
        // Authorization is handled in the FormRequest

        $source = PocketExpenseSourceClientConfig::findOrFail($id);

        // Update source using service
        $updatedSource = $this->sourceService->updateSource($source, $request->validated());

        return response()->json([
            'data' => new PocketExpenseSourceResource($updatedSource)
        ]);
    }

    /**
     * Remove the specified expense source (soft delete).
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $source = PocketExpenseSourceClientConfig::findOrFail($id);

        // Authorization check
        $this->authorize('delete', $source);

        // Delete source using service
        $this->sourceService->deleteSource($source);

        return response()->json(null, 204);
    }
}