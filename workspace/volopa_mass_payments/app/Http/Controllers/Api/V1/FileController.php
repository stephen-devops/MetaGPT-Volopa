{
  "Development Plan": [
    "app/Http/Controllers/Api/V1/FileController.php"
  ],
  "Incremental Change": [
    "app/Http/Controllers/Api/V1/FileController.php: API controller for file operations with upload, show, index, destroy methods using FileProcessingService and proper validation"
  ]
}

## Code: app/Http/Controllers/Api/V1/FileController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileUploadRequest;
use App\Http\Resources\FileResource;
use App\Models\PaymentFile;
use App\Services\FileProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;
use Symfony\Http\Response;

class FileController extends Controller
{
    /**
     * File processing service instance.
     */
    private FileProcessingService $fileProcessingService;

    /**
     * Default pagination limit.
     */
    private const DEFAULT_PAGINATION_LIMIT = 15;

    /**
     * Maximum pagination limit.
     */
    private const MAX_PAGINATION_LIMIT = 100;

    /**
     * Create a new FileController instance.
     *
     * @param FileProcessingService $fileProcessingService
     */
    public function __construct(FileProcessingService $fileProcessingService)
    {
        $this->fileProcessingService = $fileProcessingService;
        
        // Apply authentication middleware
        $this->middleware('auth:sanctum');
        
        // Apply throttling middleware
        $this->middleware('throttle:uploads,10')->only(['upload']);
        $this->middleware('throttle:api,60')->except(['upload']);
    }

    /**
     * Upload a CSV payment file.
     *
     * @param FileUploadRequest $request
     * @return JsonResponse
     */
    public function upload(FileUploadRequest $request): JsonResponse
    {
        Log::info('File upload request received', [
            'user_id' => Auth::id(),
            'original_filename' => $request->getOriginalFilename(),
            'file_size' => $request->getFileSize(),
            'currency' => $request->getCurrency(),
        ]);

        try {
            // Get authenticated user
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Check if user has valid file for processing
            if (!$request->hasValidFile()) {
                return $this->errorResponse('Invalid file provided', Response::HTTP_BAD_REQUEST);
            }

            // Process file upload using service
            $paymentFile = $this->fileProcessingService->processUpload(
                $request->getFile(),
                $user
            );

            Log::info('File upload processed successfully', [
                'payment_file_id' => $paymentFile->id,
                'user_id' => $user->id,
                'status' => $paymentFile->status,
            ]);

            // Return created response with file resource
            return $this->successResponse(
                new FileResource($paymentFile),
                'File uploaded successfully and queued for processing',
                Response::HTTP_CREATED
            );

        } catch (Exception $e) {
            Log::error('File upload failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                'File upload failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Display the specified payment file.
     *
     * @param int $id Payment file ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        Log::info('File show request received', [
            'payment_file_id' => $id,
            'user_id' => Auth::id(),
        ]);

        try {
            // Find payment file with relationships
            $paymentFile = PaymentFile::with([
                'user',
                'paymentInstructions' => function ($query) {
                    $query->orderBy('row_number');
                },
                'approvals' => function ($query) {
                    $query->with('approver')->orderBy('created_at', 'desc');
                },
                'validationErrors' => function ($query) {
                    $query->orderBy('row_number')->orderBy('field_name');
                }
            ])->find($id);

            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            // Check authorization using policy
            $user = Auth::user();
            if (!$user || !$user->can('view', $paymentFile)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            Log::info('File retrieved successfully', [
                'payment_file_id' => $id,
                'user_id' => $user->id,
                'file_status' => $paymentFile->status,
            ]);

            return $this->successResponse(
                new FileResource($paymentFile),
                'Payment file retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('File show failed', [
                'payment_file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve payment file',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Display a listing of payment files.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        Log::info('File index request received', [
            'user_id' => Auth::id(),
            'query_params' => $request->query(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Validate query parameters
            $validated = $request->validate([
                'status' => 'sometimes|string|in:uploaded,processing,validated,pending_approval,approved,rejected,ready_for_processing,processing_payments,completed,failed',
                'currency' => 'sometimes|string|size:3|in:USD,EUR,GBP',
                'per_page' => 'sometimes|integer|min:1|max:' . self::MAX_PAGINATION_LIMIT,
                'sort_by' => 'sometimes|string|in:created_at,updated_at,original_name,total_amount,status',
                'sort_direction' => 'sometimes|string|in:asc,desc',
                'search' => 'sometimes|string|max:255',
            ]);

            // Build query with authorization
            $query = PaymentFile::query()
                ->with(['user', 'approvals' => function ($query) {
                    $query->with('approver')->latest();
                }])
                ->forUser($user->id); // Scope to user's files

            // Apply filters
            if (isset($validated['status'])) {
                $query->withStatus($validated['status']);
            }

            if (isset($validated['currency'])) {
                $query->where('currency', $validated['currency']);
            }

            if (isset($validated['search'])) {
                $searchTerm = $validated['search'];
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('original_name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('filename', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'created_at';
            $sortDirection = $validated['sort_direction'] ?? 'desc';
            $query->orderBy($sortBy, $sortDirection);

            // Paginate results
            $perPage = min(
                $validated['per_page'] ?? self::DEFAULT_PAGINATION_LIMIT,
                self::MAX_PAGINATION_LIMIT
            );

            $paymentFiles = $query->paginate($perPage);

            Log::info('File index completed', [
                'user_id' => $user->id,
                'total_files' => $paymentFiles->total(),
                'current_page' => $paymentFiles->currentPage(),
                'per_page' => $perPage,
            ]);

            return $this->paginatedResponse(
                FileResource::collection($paymentFiles),
                'Payment files retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('File index failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve payment files',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Remove the specified payment file from storage.
     *
     * @param int $id Payment file ID
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        Log::info('File delete request received', [
            'payment_file_id' => $id,
            'user_id' => Auth::id(),
        ]);

        try {
            // Find payment file
            $paymentFile = PaymentFile::find($id);

            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            // Check authorization using policy
            $user = Auth::user();
            if (!$user || !$user->can('delete', $paymentFile)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            // Check if file can be deleted (business logic)
            if (!$this->canBeDeleted($paymentFile)) {
                return $this->errorResponse(
                    'Payment file cannot be deleted in its current status: ' . $paymentFile->status,
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Perform deletion in transaction
            DB::transaction(function () use ($paymentFile) {
                // Delete stored file
                $this->fileProcessingService->deleteStoredFile($paymentFile);

                // Soft delete the payment file (cascades to related records)
                $paymentFile->delete();

                Log::info('Payment file deleted successfully', [
                    'payment_file_id' => $paymentFile->id,
                    'user_id' => Auth::id(),
                    'original_filename' => $paymentFile->original_name,
                ]);
            });

            return $this->successResponse(
                null,
                'Payment file deleted successfully',
                Response::HTTP_NO_CONTENT
            );

        } catch (Exception $e) {
            Log::error('File deletion failed', [
                'payment_file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to delete payment file',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get file processing statistics.
     *
     * @param int $id Payment file ID
     * @return JsonResponse
     */
    public function statistics(int $id): JsonResponse
    {
        Log::info('File statistics request received', [
            'payment_file_id' => $id,
            'user_id' => Auth::id(),
        ]);

        try {
            $paymentFile = PaymentFile::find($id);

            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            $user = Auth::user();
            if (!$user || !$user->can('view', $paymentFile)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            $statistics = $this->fileProcessingService->getProcessingStatistics($paymentFile);

            return $this->successResponse(
                $statistics,
                'File statistics retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('File statistics failed', [
                'payment_file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve file statistics',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Check if a payment file can be deleted.
     *
     * @param PaymentFile $paymentFile
     * @return bool
     */
    private function canBeDeleted(PaymentFile $paymentFile): bool
    {
        $undeletableStatuses = [
            PaymentFile::STATUS_PROCESSING_PAYMENTS,
            PaymentFile::STATUS_COMPLETED,
        ];

        return !in_array($paymentFile->status, $undeletableStatuses);
    }

    /**
     * Return a successful JSON response.
     *
     * @param mixed $data
     * @param string $message
     * @param int $statusCode
     * @return JsonResponse
     */
    private function successResponse($data, string $message, int $statusCode = Response::HTTP_OK): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        // Add metadata
        $response['meta'] = [
            'timestamp' => now()->toISOString(),
            'api_version' => '1.0.0',
        ];

        return response()->json($response, $statusCode);
    }

    /**
     * Return a paginated JSON response.
     *
     * @param mixed $data
     * @param string $message
     * @return JsonResponse
     */
    private function paginatedResponse($data, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'from' => $data->firstItem(),
                'last_page' => $data->lastPage(),
                'per_page' => $data->perPage(),
                'to' => $data->lastItem(),
                'total' => $data->total(),
                'timestamp' => now()->toISOString(),
                'api_version' => '1.0.0',
            ],
            'links' => [
                'first' => $data->url(1),
                'last' => $data->url($data->lastPage()),
                'prev' => $data->previousPageUrl(),
                'next' => $data->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Return an error JSON response.
     *
     * @param string $message
     * @param int $statusCode
     * @param array $errors
     * @return JsonResponse
     */
    private function errorResponse(string $message, int $statusCode, array $errors = []): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ];

        // Add metadata
        $response['meta'] = [
            'timestamp' => now()->toISOString(),
            'api_version' => '1.0.0',
        ];

        // Add debugging information in non-production environments
        if (config('app.debug')) {
            $response['debug'] = [
                'request_id' => request()->header('X-Request-ID') ?? uniqid(),
                'user_id' => Auth::id(),
            ];
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Handle exceptions