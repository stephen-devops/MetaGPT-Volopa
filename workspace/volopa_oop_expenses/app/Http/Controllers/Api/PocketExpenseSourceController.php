<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PocketExpenseSourceResource;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PocketExpenseSourceController
 * 
 * Handles CRUD operations for expense source configurations.
 * Manages client-specific and global expense sources with proper authorization and validation.
 * 
 * @package App\Http\Controllers\Api
 */
class PocketExpenseSourceController extends Controller
{
    /**
     * Constructor - Apply OAuth2 middleware for all methods.
     */
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Get expense source configurations.
     * Returns both global sources and client-specific sources for the authenticated user's client.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "uuid": "550e8400-e29b-41d4-a716-446655440000",
     *       "name": "Company Credit Card",
     *       "is_default": true,
     *       "is_global": true,
     *       "created_at": "2023-01-01T00:00:00.000000Z"
     *     }
     *   ]
     * }
     * 
     * @response 401 {
     *   "message": "Unauthenticated."
     * }
     * 
     * @response 403 {
     *   "message": "Access denied. Insufficient permissions."
     * }
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                Log::warning('User accessing expense sources without client context', [
                    'user_id' => $user->id,
                    'action' => 'index_expense_sources'
                ]);
                
                return response()->json([
                    'message' => 'Client context required for accessing expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Get all available sources for this client (global + client-specific)
            $sources = PocketExpenseSourceClientConfig::getAvailableForClient($clientId);

            // Transform using API Resource
            return response()->json([
                'data' => PocketExpenseSourceResource::collection($sources),
                'meta' => [
                    'total' => $sources->count(),
                    'client_id' => $clientId,
                    'timestamp' => now()->toISOString()
                ]
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve expense sources', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expense sources.',
                'error' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new expense source configuration.
     * Only creates client-specific sources. Global sources are managed by system administrators.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * @bodyParam name string required The name of the expense source. Must be unique within the client. Example: "Petty Cash"
     * @bodyParam is_default boolean optional Whether this should be the default source for the client. Default: false
     * 
     * @response 201 {
     *   "data": {
     *     "id": 5,
     *     "uuid": "550e8400-e29b-41d4-a716-446655440001",
     *     "name": "Petty Cash",
     *     "is_default": false,
     *     "is_global": false,
     *     "created_at": "2023-01-01T12:00:00.000000Z"
     *   }
     * }
     * 
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "name": ["The name field is required."]
     *   }
     * }
     * 
     * @response 409 {
     *   "message": "Expense source with this name already exists for your organization.",
     *   "error": "DUPLICATE_SOURCE_NAME"
     * }
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context required for creating expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Validate request data
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:100',
                    'regex:/^[\p{L}\p{N}\s\-_.,()&]+$/u', // Allow letters, numbers, spaces, and common punctuation
                    Rule::unique('pocket_expense_source_client_config', 'name')
                        ->where(function ($query) use ($clientId) {
                            return $query->where('client_id', $clientId)
                                        ->where('deleted', false);
                        })
                ],
                'is_default' => 'sometimes|boolean'
            ], [
                'name.required' => 'The expense source name is required.',
                'name.max' => 'The expense source name cannot exceed 100 characters.',
                'name.regex' => 'The expense source name contains invalid characters.',
                'name.unique' => 'An expense source with this name already exists for your organization.',
                'is_default.boolean' => 'The is_default field must be true or false.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validatedData = $validator->validated();

            // Check if client already has maximum number of sources (20 limit)
            $existingSourcesCount = PocketExpenseSourceClientConfig::forClient($clientId)
                                                                  ->active()
                                                                  ->count();

            if ($existingSourcesCount >= 20) {
                return response()->json([
                    'message' => 'Maximum number of expense sources (20) reached for your organization.',
                    'error' => 'MAX_SOURCES_EXCEEDED',
                    'current_count' => $existingSourcesCount,
                    'maximum' => 20
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Double-check for name uniqueness (race condition protection)
            $existingSource = PocketExpenseSourceClientConfig::findByNameForClient(
                $validatedData['name'], 
                $clientId
            );

            if ($existingSource && $existingSource->isActive()) {
                return response()->json([
                    'message' => 'Expense source with this name already exists for your organization.',
                    'error' => 'DUPLICATE_SOURCE_NAME'
                ], Response::HTTP_CONFLICT);
            }

            // Create the expense source
            $sourceData = [
                'uuid' => Str::uuid()->toString(),
                'client_id' => $clientId,
                'name' => trim($validatedData['name']),
                'is_default' => $validatedData['is_default'] ?? false,
                'deleted' => false,
                'delete_time' => null,
                'create_time' => now()
            ];

            $source = PocketExpenseSourceClientConfig::create($sourceData);

            // If marked as default, ensure it's the only default for this client
            if ($source->is_default) {
                $source->setAsDefault();
            }

            Log::info('Expense source created', [
                'source_id' => $source->id,
                'uuid' => $source->uuid,
                'client_id' => $clientId,
                'name' => $source->name,
                'is_default' => $source->is_default,
                'created_by' => $user->id,
                'created_at' => $source->create_time
            ]);

            return response()->json([
                'data' => new PocketExpenseSourceResource($source),
                'message' => 'Expense source created successfully.'
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            Log::error('Failed to create expense source', [
                'user_id' => Auth::id(),
                'client_id' => $clientId ?? null,
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to create expense source.',
                'error' => 'CREATION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update an existing expense source configuration.
     * Only client-specific sources can be updated. Global sources are system-managed.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return \Illuminate\Http\JsonResponse
     * 
     * @urlParam source integer required The ID of the expense source to update. Example: 5
     * @bodyParam name string optional The name of the expense source. Example: "Updated Petty Cash"
     * @bodyParam is_default boolean optional Whether this should be the default source for the client.
     * 
     * @response 200 {
     *   "data": {
     *     "id": 5,
     *     "uuid": "550e8400-e29b-41d4-a716-446655440001",
     *     "name": "Updated Petty Cash",
     *     "is_default": true,
     *     "is_global": false,
     *     "updated_at": "2023-01-01T12:30:00.000000Z"
     *   }
     * }
     * 
     * @response 404 {
     *   "message": "Expense source not found or not accessible."
     * }
     * 
     * @response 403 {
     *   "message": "Cannot modify global expense sources.",
     *   "error": "GLOBAL_SOURCE_READONLY"
     * }
     */
    public function update(Request $request, PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context required for updating expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if source exists and is accessible
            if ($source->isDeleted()) {
                return response()->json([
                    'message' => 'Expense source not found or has been deleted.',
                    'error' => 'SOURCE_NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if user has access to this source (same client or global)
            if ($source->client_id !== null && $source->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense source not found or not accessible.',
                    'error' => 'ACCESS_DENIED'
                ], Response::HTTP_NOT_FOUND);
            }

            // Prevent modification of global sources
            if ($source->isGlobal()) {
                return response()->json([
                    'message' => 'Cannot modify global expense sources.',
                    'error' => 'GLOBAL_SOURCE_READONLY'
                ], Response::HTTP_FORBIDDEN);
            }

            // Validate request data
            $validator = Validator::make($request->all(), [
                'name' => [
                    'sometimes',
                    'string',
                    'max:100',
                    'regex:/^[\p{L}\p{N}\s\-_.,()&]+$/u',
                    Rule::unique('pocket_expense_source_client_config', 'name')
                        ->where(function ($query) use ($clientId) {
                            return $query->where('client_id', $clientId)
                                        ->where('deleted', false);
                        })
                        ->ignore($source->id)
                ],
                'is_default' => 'sometimes|boolean'
            ], [
                'name.max' => 'The expense source name cannot exceed 100 characters.',
                'name.regex' => 'The expense source name contains invalid characters.',
                'name.unique' => 'An expense source with this name already exists for your organization.',
                'is_default.boolean' => 'The is_default field must be true or false.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validatedData = $validator->validated();

            // Track changes for logging
            $changes = [];

            // Update name if provided
            if (isset($validatedData['name']) && $validatedData['name'] !== $source->name) {
                $oldName = $source->name;
                $source->name = trim($validatedData['name']);
                $changes['name'] = ['old' => $oldName, 'new' => $source->name];
            }

            // Update default status if provided
            if (isset($validatedData['is_default'])) {
                $oldDefault = $source->is_default;
                
                if ($validatedData['is_default'] && !$source->is_default) {
                    // Setting as default - use the model method to handle uniqueness
                    $source->setAsDefault();
                    $changes['is_default'] = ['old' => false, 'new' => true];
                } elseif (!$validatedData['is_default'] && $source->is_default) {
                    // Removing default status
                    $source->removeDefault();
                    $changes['is_default'] = ['old' => true, 'new' => false];
                }
            }

            // Save changes if any
            if (!empty($changes)) {
                $source->update_time = now();
                $source->save();

                Log::info('Expense source updated', [
                    'source_id' => $source->id,
                    'uuid' => $source->uuid,
                    'client_id' => $clientId,
                    'changes' => $changes,
                    'updated_by' => $user->id,
                    'updated_at' => $source->update_time
                ]);
            }

            return response()->json([
                'data' => new PocketExpenseSourceResource($source->fresh()),
                'message' => 'Expense source updated successfully.'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to update expense source', [
                'source_id' => $source->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => $clientId ?? null,
                'request_data' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to update expense source.',
                'error' => 'UPDATE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Soft delete an expense source configuration.
     * Only client-specific sources can be deleted. Global sources are system-managed.
     * Sources with associated metadata entries cannot be deleted.
     *
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return \Illuminate\Http\JsonResponse
     * 
     * @urlParam source integer required The ID of the expense source to delete. Example: 5
     * 
     * @response 204 {
     * }
     * 
     * @response 404 {
     *   "message": "Expense source not found or not accessible."
     * }
     * 
     * @response 403 {
     *   "message": "Cannot delete global expense sources.",
     *   "error": "GLOBAL_SOURCE_READONLY"
     * }
     * 
     * @response 409 {
     *   "message": "Cannot delete expense source that is currently in use.",
     *   "error": "SOURCE_IN_USE",
     *   "metadata_count": 5
     * }
     */
    public function destroy(PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context required for deleting expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if source exists and is accessible
            if ($source->isDeleted()) {
                return response()->json([
                    'message' => 'Expense source not found or has been deleted.',
                    'error' => 'SOURCE_NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if user has access to this source (same client or global)
            if ($source->client_id !== null && $source->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense source not found or not accessible.',
                    'error' => 'ACCESS_DENIED'
                ], Response::HTTP_NOT_FOUND);
            }

            // Prevent deletion of global sources
            if ($source->isGlobal()) {
                return response()->json([
                    'message' => 'Cannot delete global expense sources.',
                    'error' => 'GLOBAL_SOURCE_READONLY'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if source can be safely deleted (no associated metadata)
            if (!$source->canDelete()) {
                $metadataCount = $source->getMetadataCountAttribute();
                
                return response()->json([
                    'message' => 'Cannot delete expense source that is currently in use.',
                    'error' => 'SOURCE_IN_USE',
                    'metadata_count' => $metadataCount,
                    'suggestion' => 'Remove all associated expenses before deleting this source.'
                ], Response::HTTP_CONFLICT);
            }

            // Store info for logging before deletion
            $sourceInfo = [
                'source_id' => $source->id,
                'uuid' => $source->uuid,
                'client_id' => $source->client_id,
                'name' => $source->name,
                'is_default' => $source->is_default,
                'deleted_by' => $user->id
            ];

            // If this was the default source, make sure another one becomes default
            $wasDefault = $source->is_default;
            
            // Perform soft delete
            $source->softDelete();

            // If deleted source was default, set another as default
            if ($wasDefault) {
                $newDefault = PocketExpenseSourceClientConfig::getAvailableForClient($clientId)
                    ->where('id', '!=', $source->id)
                    ->first();
                
                if ($newDefault) {
                    $newDefault->setAsDefault();
                    $sourceInfo['new_default_source'] = [
                        'id' => $newDefault->id,
                        'name' => $newDefault->name
                    ];
                }
            }

            Log::info('Expense source soft deleted', array_merge($sourceInfo, [
                'deleted_at' => $source->delete_time,
                'was_default' => $wasDefault
            ]));

            return response()->json(null, Response::HTTP_NO_CONTENT);

        } catch (\Exception $e) {
            Log::error('Failed to delete expense source', [
                'source_id' => $source->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to delete expense source.',
                'error' => 'DELETION_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get a specific expense source by ID.
     * Used for detailed view or edit form population.
     *
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return \Illuminate\Http\JsonResponse
     * 
     * @urlParam source integer required The ID of the expense source to retrieve. Example: 5
     * 
     * @response 200 {
     *   "data": {
     *     "id": 5,
     *     "uuid": "550e8400-e29b-41d4-a716-446655440001",
     *     "name": "Petty Cash",
     *     "is_default": false,
     *     "is_global": false,
     *     "metadata_count": 12,
     *     "created_at": "2023-01-01T12:00:00.000000Z",
     *     "updated_at": "2023-01-01T12:30:00.000000Z"
     *   }
     * }
     * 
     * @response 404 {
     *   "message": "Expense source not found or not accessible."
     * }
     */
    public function show(PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context required for accessing expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if source exists and is accessible
            if ($source->isDeleted()) {
                return response()->json([
                    'message' => 'Expense source not found or has been deleted.',
                    'error' => 'SOURCE_NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if user has access to this source (same client or global)
            if ($source->client_id !== null && $source->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense source not found or not accessible.',
                    'error' => 'ACCESS_DENIED'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'data' => new PocketExpenseSourceResource($source)
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve expense source', [
                'source_id' => $source->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve expense source.',
                'error' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get the default expense source for the authenticated user's client.
     * Used by frontend to pre-populate forms with the default selection.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * 
     * @response 200 {
     *   "data": {
     *     "id": 1,
     *     "uuid": "550e8400-e29b-41d4-a716-446655440000",
     *     "name": "Company Credit Card",
     *     "is_default": true,
     *     "is_global": true,
     *     "created_at": "2023-01-01T00:00:00.000000Z"
     *   }
     * }
     * 
     * @response 404 {
     *   "message": "No default expense source configured for your organization.",
     *   "error": "NO_DEFAULT_SOURCE"
     * }
     */
    public function getDefault(Request $request): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context required for accessing expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Get the default source for this client
            $defaultSource = PocketExpenseSourceClientConfig::getDefaultForClient($clientId);

            if (!$defaultSource) {
                return response()->json([
                    'message' => 'No default expense source configured for your organization.',
                    'error' => 'NO_DEFAULT_SOURCE',
                    'suggestion' => 'Please contact your administrator to configure a default expense source.'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'data' => new PocketExpenseSourceResource($defaultSource)
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve default expense source', [
                'user_id' => Auth::id(),
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve default expense source.',
                'error' => 'RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Set a specific expense source as the default for the client.
     * Only one source can be default per client at a time.
     *
     * @param \App\Models\PocketExpenseSourceClientConfig $source
     * @return \Illuminate\Http\JsonResponse
     * 
     * @urlParam source integer required The ID of the expense source to set as default. Example: 5
     * 
     * @response 200 {
     *   "data": {
     *     "id": 5,
     *     "uuid": "550e8400-e29b-41d4-a716-446655440001",
     *     "name": "Petty Cash",
     *     "is_default": true,
     *     "is_global": false,
     *     "updated_at": "2023-01-01T12:30:00.000000Z"
     *   },
     *   "message": "Expense source set as default successfully."
     * }
     * 
     * @response 404 {
     *   "message": "Expense source not found or not accessible."
     * }
     * 
     * @response 403 {
     *   "message": "Cannot set global expense sources as default.",
     *   "error": "GLOBAL_SOURCE_READONLY"
     * }
     */
    public function setDefault(PocketExpenseSourceClientConfig $source): JsonResponse
    {
        try {
            // Get authenticated user and client context
            $user = Auth::user();
            $clientId = $user->client_id ?? null;

            if (!$clientId) {
                return response()->json([
                    'message' => 'Client context required for managing expense sources.',
                    'error' => 'MISSING_CLIENT_CONTEXT'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if source exists and is accessible
            if ($source->isDeleted()) {
                return response()->json([
                    'message' => 'Expense source not found or has been deleted.',
                    'error' => 'SOURCE_NOT_FOUND'
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if user has access to this source (same client or global)
            if ($source->client_id !== null && $source->client_id !== $clientId) {
                return response()->json([
                    'message' => 'Expense source not found or not accessible.',
                    'error' => 'ACCESS_DENIED'
                ], Response::HTTP_NOT_FOUND);
            }

            // Prevent setting global sources as client default
            if ($source->isGlobal()) {
                return response()->json([
                    'message' => 'Cannot set global expense sources as default.',
                    'error' => 'GLOBAL_SOURCE_READONLY',
                    'suggestion' => 'Create a client-specific source or contact your administrator.'
                ], Response::HTTP_FORBIDDEN);
            }

            // Check if already default
            if ($source->is_default) {
                return response()->json([
                    'data' => new PocketExpenseSourceResource($source),
                    'message' => 'This expense source is already set as default.'
                ], Response::HTTP_OK);
            }

            // Set as default (this will automatically unset other defaults)
            $source->setAsDefault();

            Log::info('Expense source set as default', [
                'source_id' => $source->id,
                'uuid' => $source->uuid,
                'client_id' => $clientId,
                'name' => $source->name,
                'set_by' => $user->id,
                'updated_at' => now()
            ]);

            return response()->json([
                'data' => new PocketExpenseSourceResource($source->fresh()),
                'message' => 'Expense source set as default successfully.'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            Log::error('Failed to set default expense source', [
                'source_id' => $source->id ?? null,
                'user_id' => Auth::id(),
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to set default expense source.',
                'error' => 'UPDATE_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}