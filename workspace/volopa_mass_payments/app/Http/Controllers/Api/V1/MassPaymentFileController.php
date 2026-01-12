## Code: app/Http/Controllers/Api/V1/MassPaymentFileController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMassPaymentFileRequest;
use App\Http\Requests\ApproveMassPaymentFileRequest;
use App\Http\Resources\MassPaymentFileResource;
use App\Services\MassPaymentFileService;
use App\Models\MassPaymentFile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

class MassPaymentFileController extends Controller
{
    /**
     * The mass payment file service instance.
     */
    private MassPaymentFileService $massPaymentFileService;

    /**
     * Default pagination size for mass payment file lists.
     */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Maximum pagination size allowed.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Allowed sort fields for mass payment file queries.
     */
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'filename',
        'status',
        'total_amount',
        'total_rows',
        'valid_rows',
        'error_rows',
        'currency',
        'created_at',
        'updated_at',
        'approved_at'
    ];

    /**
     * Allowed filter fields for mass payment file queries.
     */
    private const ALLOWED_FILTER_FIELDS = [
        'status',
        'currency',
        'tcc_account_id',
        'uploaded_by',
        'approved_by'
    ];

    /**
     * Allowed status values for filtering.
     */
    private const ALLOWED_STATUS_VALUES = [
        'uploading',
        'uploaded',
        'validating',
        'validation_failed',
        'validated',
        'pending_approval',
        'approved',
        'rejected',
        'processing',
        'processed',
        'completed',
        'failed'
    ];

    /**
     * Create a new controller instance.
     */
    public function __construct(MassPaymentFileService $massPaymentFileService)
    {
        $this->massPaymentFileService = $massPaymentFileService;
        $this->middleware('auth:api');
        $this->middleware('throttle:60,1');
    }

    /**
     * Upload a mass payment file.
     *
     * @param UploadMassPaymentFileRequest $request
     * @return JsonResponse
     */
    public function store(UploadMassPaymentFileRequest $request): JsonResponse
    {
        try {
            Log::info('Mass payment file upload initiated', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id,
                'tcc_account_id' => $request->validated('tcc_account_id'),
                'filename' => $request->file('file')->getClientOriginalName()
            ]);

            // Get validated data and uploaded file
            $validatedData = $request->validated();
            $uploadedFile = $request->file('file');

            // Upload file using service
            $massPaymentFile = $this->massPaymentFileService->uploadFile($validatedData, $uploadedFile);

            // Load relationships for complete resource response
            $massPaymentFile->load(['tccAccount', 'uploader']);

            Log::info('Mass payment file uploaded successfully', [
                'file_id' => $massPaymentFile->id,
                'filename' => $massPaymentFile->filename,
                'status' => $massPaymentFile->status,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'data' => new MassPaymentFileResource($massPaymentFile),
                'message' => 'Mass payment file uploaded successfully'
            ], 201);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Mass payment file upload validation failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Validation failed',
                'error' => 'validation_error',
                'details' => $e->getMessage()
            ], 422);

        } catch (\RuntimeException $e) {
            Log::error('Mass payment file upload runtime error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'File upload failed',
                'error' => 'upload_error'
            ], 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during mass payment file upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred',
                'error' => 'internal_server_error'
            ], 500);
        }
    }

    /**
     * Get a specific mass payment file by ID.
     *
     * @param int $id Mass payment file ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            Log::info('Mass payment file details requested', [
                'file_id' => $id,
                'user_id' => Auth::id()
            ]);

            // Find mass payment file with relationships
            $massPaymentFile = MassPaymentFile::with([
                'tccAccount',
                'uploader',
                'approver',
                'paymentInstructions'
            ])->findOrFail($id);

            Log::info('Mass payment file retrieved successfully', [
                'file_id' => $id,
                'filename' => $massPaymentFile->filename,
                'status' => $massPaymentFile->status,
                'total_amount' => $massPaymentFile->total_amount,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'data' => new MassPaymentFileResource($massPaymentFile)
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::warning('Mass payment file not found', [
                'file_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Mass payment file not found',
                'error' => 'not_found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error retrieving mass payment file', [
                'file_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve mass payment file',
                'error' => 'retrieval_error'
            ], 500);
        }
    }

    /**
     * Get a paginated list of mass payment files.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('Mass payment files list requested', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id,
                'filters' => $request->only(self::ALLOWED_FILTER_FIELDS)
            ]);

            // Validate query parameters
            $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:' . self::MAX_PER_PAGE,
                'sort_by' => 'sometimes|string|in:' . implode(',', self::ALLOWED_SORT_FIELDS),
                'sort_order' => 'sometimes|string|in:asc,desc',
                'status' => 'sometimes|string|in:' . implode(',', self::ALLOWED_STATUS_VALUES),
                'currency' => 'sometimes|string|size:3',
                'tcc_account_id' => 'sometimes|integer|exists:tcc_accounts,id',
                'search' => 'sometimes|string|max:255'
            ]);

            // Build query with relationships
            $query = MassPaymentFile::with(['tccAccount', 'uploader', 'approver']);

            // Apply filters
            $this->applyFilters($query, $request);

            // Apply search
            $this->applySearch($query, $request);

            // Apply sorting
            $this->applySorting($query, $request);

            // Get pagination parameters
            $perPage = $this->getPerPageParameter($request);

            // Execute query with pagination
            $files = $query->paginate($perPage);

            Log::info('Mass payment files retrieved successfully', [
                'total' => $files->total(),
                'per_page' => $files->perPage(),
                'current_page' => $files->currentPage(),
                'user_id' => Auth::id()
            ]);

            // Transform to resource collection
            return response()->json([
                'data' => MassPaymentFileResource::collection($files->items()),
                'meta' => [
                    'current_page' => $files->currentPage(),
                    'from' => $files->firstItem(),
                    'last_page' => $files->lastPage(),
                    'per_page' => $files->perPage(),
                    'to' => $files->lastItem(),
                    'total' => $files->total(),
                    'has_more' => $files->hasMorePages(),
                    'links' => [
                        'first' => $files->url(1),
                        'last' => $files->url($files->lastPage()),
                        'prev' => $files->previousPageUrl(),
                        'next' => $files->nextPageUrl(),
                    ]
                ],
                'links' => [
                    'first' => $files->url(1),
                    'last' => $files->url($files->lastPage()),
                    'prev' => $files->previousPageUrl(),
                    'next' => $files->nextPageUrl(),
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Invalid query parameters for mass payment files list', [
                'errors' => $e->errors(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Invalid query parameters',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error retrieving mass payment files list', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Failed to retrieve mass payment files',
                'error' => 'retrieval_error'
            ], 500);
        }
    }

    /**
     * Approve a mass payment file.
     *
     * @param int $id Mass payment file ID
     * @param ApproveMassPaymentFileRequest $request
     * @return JsonResponse
     */
    public function approve(int $id, ApproveMassPaymentFileRequest $request): JsonResponse
    {
        try {
            Log::info('Mass payment file approval initiated', [
                'file_id' => $id,
                'user_id' => Auth::id(),
                'force_approve' => $request->input('force_approve', false),
                'override_validation_errors' => $request->input('override_validation_errors', false)
            ]);

            // Find the mass payment file
            $massPaymentFile = MassPaymentFile::with(['tccAccount', 'uploader'])->findOrFail($id);

            // Get validated data
            $validatedData = $request->validated();

            // Approve file using service
            $approvedFile = $this->massPaymentFileService->approveFile($massPaymentFile, Auth::id());

            // Load all relationships for complete resource response
            $approvedFile->load(['tccAccount', 'uploader', 'approver']);

            Log::info('Mass payment file approved successfully', [
                'file_id' => $id,
                'filename' => $approvedFile->filename,
                'total_amount' => $approvedFile->total_amount,
                'currency' => $approvedFile->currency,
                'approver_id' => Auth::id()
            ]);

            return response()->json([
                'data' => new MassPaymentFileResource($approvedFile),
                'message' => 'Mass payment file approved successfully'
            ], 200);

        } catch (ModelNotFoundException $e) {
            Log::warning('Mass payment file not found for approval', [
                'file_id' => $id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Mass payment file not found',
                'error' => 'not_found'
            ], 404);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Mass payment file approval validation failed', [
                'file_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Approval validation failed',
                'error' => 'validation_error',
                'details' => $e->getMessage()
            ], 422);

        } catch (\RuntimeException $e) {
            Log::error('Mass payment file approval runtime error', [
                'file_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'Approval failed',
                'error' => 'approval_error',
                'details' => $e->getMessage()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Unexpected error during mass payment file approval', [
                'file_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred during approval',
                'error' => 'internal_server_error'
            ], 500);
        }
    }

    /**
     * Delete a mass payment file.
     *
     * @param int $id Mass payment file ID
     * @return JsonResponse
     */
    public function delete(int $id): JsonResponse
    {
        try {
            Log::info('Mass payment file deletion initiated', [
                'file_id' => $id,
                'user_id' => Auth::id()
            ]);

            // Find the mass payment file
            $massPaymentFile = MassPaymentFile::findOrFail($id);

            // Check user authorization (additional check beyond policy)
            if ($massPaymentFile->client_id !== Auth::user()->client_id) {
                Log::warning('Unauthorized mass payment file deletion attempt', [
                    'file_id' => $id,
                    'user_id' => Auth::id(),
                    'file_client_id' => $massPaymentFile->client_id,
                    'user_client_id' => Auth::user()->client_id
                ]);

                return response()->json([
                    'message' => 'You do not have permission to delete this file',
                    'error' => 'unauthorized'
                ], 403);
            }

            // Delete file using service
            $deleted = $this->massPaymentFileService->deleteFile($massPaymentFile);

            if (!$deleted) {
                Log::warning('Mass payment file deletion failed', [
                    'file_id' => $id,
                    'user_id' => Auth::id()
                ]);

                return response()->json([
                    'message' => 'Failed to delete mass payment file',
                    