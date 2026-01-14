Looking at the context and requirements, I need to implement the `MassPaymentFileController.php` file. This controller handles the REST API endpoints for mass payment file operations including upload, listing, approval, and deletion. Based on the design patterns and data structures provided, this controller should be thin and delegate business logic to the MassPaymentFileService.

## Code: app/Http/Controllers/Api/V1/MassPaymentFileController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMassPaymentFileRequest;
use App\Http\Requests\ApproveMassPaymentFileRequest;
use App\Http\Resources\MassPaymentFileResource;
use App\Models\MassPaymentFile;
use App\Services\MassPaymentFileService;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Exception;

class MassPaymentFileController extends Controller
{
    /**
     * The mass payment file service.
     */
    private MassPaymentFileService $massPaymentFileService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Maximum pagination size.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Create a new controller instance.
     */
    public function __construct(MassPaymentFileService $massPaymentFileService)
    {
        $this->massPaymentFileService = $massPaymentFileService;
        
        // Apply authentication middleware
        $this->middleware(['auth:api', 'volopa.auth'])->except([]);
        
        // Apply rate limiting
        $this->middleware('throttle:60,1')->except(['index', 'show']);
        $this->middleware('throttle:120,1')->only(['index', 'show']);
    }

    /**
     * Display a listing of mass payment files.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Authorize the action
            Gate::authorize('viewAny', MassPaymentFile::class);

            // Validate query parameters
            $validated = $request->validate([
                'status' => 'nullable|string|in:draft,validating,validation_failed,awaiting_approval,approved,processing,completed,failed',
                'currency' => 'nullable|string|size:3',
                'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
                'page' => 'nullable|integer|min:1',
                'sort' => 'nullable|string|in:created_at,updated_at,total_amount,filename,status',
                'direction' => 'nullable|string|in:asc,desc',
                'search' => 'nullable|string|max:255',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                'min_amount' => 'nullable|numeric|min:0',
                'max_amount' => 'nullable|numeric|gt:min_amount',
                'include' => 'nullable|string',
            ]);

            // Build the query
            $query = MassPaymentFile::query()
                ->where('client_id', Auth::user()->client_id);

            // Apply filters
            $this->applyFilters($query, $validated);

            // Apply sorting
            $sortField = $validated['sort'] ?? 'created_at';
            $sortDirection = $validated['direction'] ?? 'desc';
            $query->orderBy($sortField, $sortDirection);

            // Apply eager loading if requested
            $this->applyEagerLoading($query, $validated['include'] ?? '');

            // Paginate results
            $perPage = min($validated['per_page'] ?? self::DEFAULT_PER_PAGE, self::MAX_PER_PAGE);
            $massPaymentFiles = $query->paginate($perPage);

            // Transform to API resources
            $resourceCollection = MassPaymentFileResource::collection($massPaymentFiles);

            // Add metadata
            $metadata = [
                'filters_applied' => $this->getAppliedFilters($validated),
                'total_count' => $massPaymentFiles->total(),
                'current_page' => $massPaymentFiles->currentPage(),
                'per_page' => $massPaymentFiles->perPage(),
                'last_page' => $massPaymentFiles->lastPage(),
                'has_more_pages' => $massPaymentFiles->hasMorePages(),
                'user_permissions' => [
                    'can_create' => Gate::allows('create', MassPaymentFile::class),
                    'can_upload' => Gate::allows('create', MassPaymentFile::class),
                ],
            ];

            return response()->json([
                'success' => true,
                'message' => 'Mass payment files retrieved successfully',
                'data' => $resourceCollection->response()->getData(true)['data'],
                'meta' => array_merge($resourceCollection->response()->getData(true)['meta'] ?? [], $metadata),
                'links' => $resourceCollection->response()->getData(true)['links'],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve mass payment files', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'filters' => $validated ?? [],
            ]);

            return $this->errorResponse('Failed to retrieve mass payment files', 500);
        }
    }

    /**
     * Store a newly uploaded mass payment file.
     */
    public function store(UploadMassPaymentFileRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            Log::info('Mass payment file upload started', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'filename' => $request->file('file')->getClientOriginalName(),
                'currency' => $request->input('currency'),
                'file_size' => $request->file('file')->getSize(),
            ]);

            // Create the mass payment file using service
            $massPaymentFile = $this->massPaymentFileService->create(
                $request->validated(),
                $request->file('file')
            );

            DB::commit();

            // Transform to API resource
            $resource = new MassPaymentFileResource($massPaymentFile);

            Log::info('Mass payment file uploaded successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => Auth::id(),
                'filename' => $massPaymentFile->filename,
                'status' => $massPaymentFile->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment file uploaded successfully and validation has started',
                'data' => $resource,
                'meta' => [
                    'processing_status' => 'validation_started',
                    'estimated_validation_time' => $this->estimateValidationTime($massPaymentFile),
                    'next_steps' => [
                        'Wait for validation to complete',
                        'Review validation results',
                        'Approve file if validation successful',
                    ],
                ],
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Mass payment file upload failed', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'error' => $e->getMessage(),
                'filename' => $request->file('file')?->getClientOriginalName(),
            ]);

            return $this->errorResponse('Failed to upload mass payment file: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified mass payment file.
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Find the mass payment file
            $massPaymentFile = MassPaymentFile::findOrFail($id);

            // Authorize the action
            Gate::authorize('view', $massPaymentFile);

            // Load relationships if needed
            $massPaymentFile->load(['tccAccount']);

            // Get processing statistics
            $processingStats = $this->massPaymentFileService->getStatusSummary($massPaymentFile);

            // Transform to API resource
            $resource = new MassPaymentFileResource($massPaymentFile);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment file retrieved successfully',
                'data' => $resource,
                'meta' => [
                    'processing_statistics' => $processingStats,
                    'user_capabilities' => [
                        'can_approve' => Gate::allows('approve', $massPaymentFile),
                        'can_delete' => Gate::allows('delete', $massPaymentFile),
                        'can_view_instructions' => Gate::allows('viewInstructions', $massPaymentFile),
                        'can_export' => Gate::allows('export', $massPaymentFile),
                    ],
                    'status_history' => $this->getStatusHistory($massPaymentFile),
                ],
                'links' => [
                    'payment_instructions' => route('api.v1.payment-instructions.index', [
                        'mass_payment_file_id' => $massPaymentFile->id
                    ]),
                    'approve' => Gate::allows('approve', $massPaymentFile) 
                        ? route('api.v1.mass-payment-files.approve', ['id' => $massPaymentFile->id]) 
                        : null,
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve mass payment file', [
                'file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Mass payment file not found', 404);
            }

            return $this->errorResponse('Failed to retrieve mass payment file', 500);
        }
    }

    /**
     * Approve the specified mass payment file.
     */
    public function approve(ApproveMassPaymentFileRequest $request, string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Find the mass payment file
            $massPaymentFile = MassPaymentFile::findOrFail($id);

            Log::info('Mass payment file approval started', [
                'file_id' => $massPaymentFile->id,
                'user_id' => Auth::id(),
                'action' => $request->input('action', 'approve'),
                'filename' => $massPaymentFile->filename,
                'total_amount' => $massPaymentFile->total_amount,
            ]);

            $action = $request->input('action', 'approve');

            if ($action === 'approve') {
                // Approve the file using service
                $approved = $this->massPaymentFileService->approve($massPaymentFile, Auth::user());

                if (!$approved) {
                    throw new Exception('Failed to approve mass payment file');
                }

                $message = 'Mass payment file approved successfully and processing has started';
                $status = 201;
                $processingStatus = 'processing_started';

            } else {
                // Reject the file
                $rejected = $this->massPaymentFileService->updateStatus(
                    $massPaymentFile,
                    MassPaymentFile::STATUS_FAILED,
                    [
                        'rejection_reason' => $request->input('approval_comment', 'File rejected by approver'),
                        'rejected_by' => Auth::id(),
                        'rejected_at' => now()->toISOString(),
                    ]
                );

                if (!$rejected) {
                    throw new Exception('Failed to reject mass payment file');
                }

                $message = 'Mass payment file rejected successfully';
                $status = 200;
                $processingStatus = 'rejected';
            }

            DB::commit();

            // Refresh the model to get updated data
            $massPaymentFile = $massPaymentFile->fresh();

            // Transform to API resource
            $resource = new MassPaymentFileResource($massPaymentFile);

            Log::info('Mass payment file approval completed', [
                'file_id' => $massPaymentFile->id,
                'user_id' => Auth::id(),
                'action' => $action,
                'new_status' => $massPaymentFile->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $resource,
                'meta' => [
                    'approval_action' => $action,
                    'processing_status' => $processingStatus,
                    'approved_by' => $action === 'approve' ? Auth::user()->name : null,
                    'approved_at' => $action === 'approve' ? now()->toISOString() : null,
                    'estimated_completion_time' => $action === 'approve' 
                        ? $this->estimateProcessingTime($massPaymentFile) 
                        : null,
                ],
            ], $status);

        } catch (ValidationException $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Mass payment file approval failed', [
                'file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $this->errorResponse('Mass payment file not found', 404);
            }

            return $this->errorResponse('Failed to process approval: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified mass payment file.
     */
    public function destroy(string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Find the mass payment file
            $massPaymentFile = MassPaymentFile::findOrFail($id);

            // Authorize the action
            Gate::authorize('delete', $massPaymentFile);

            Log::info('Mass payment file deletion started', [
                'file_id' => $massPaymentFile->id,
                'user_id' => Auth::id(),
                'filename' => $massPaymentFile->filename,
                'status' => $massPaymentFile->status,
            ]);

            // Delete the file using service
            $deleted = $this->massPaymentFileService->delete($massPaymentFile);

            if (!$deleted) {
                throw new Exception('Failed to delete mass payment file');
            }

            DB::commit();

            Log::info('Mass payment file deleted successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment file deleted successfully',
                'meta' => [
                    'deleted_file_id' => $massPaymentFile->id,
                    'deleted_filename' => $massPaymentFile->filename,
                    'deleted_at' => now()->toISOString(),
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('