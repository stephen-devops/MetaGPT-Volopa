Looking at the context and requirements, I need to implement the `PocketExpenseUploadController.php` that handles CSV file uploads for pocket expenses. Based on the design and existing code patterns, here's the complete implementation:

## Code: app/Http/Controllers/Api/PocketExpenseUploadController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPocketExpenseCsvRequest;
use App\Http\Resources\FileUploadResource;
use App\Models\PocketExpenseFileUpload;
use App\Services\PocketExpenseUploadService;
use App\Policies\ExpensePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PocketExpenseUploadController extends Controller
{
    /**
     * The pocket expense upload service instance.
     */
    private PocketExpenseUploadService $uploadService;

    /**
     * Create a new controller instance.
     */
    public function __construct(PocketExpenseUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
        $this->middleware('auth:api');
        $this->middleware('client.context');
    }

    /**
     * Upload CSV file for pocket expense processing.
     */
    public function uploadCsv(UploadPocketExpenseCsvRequest $request): JsonResponse
    {
        try {
            // Request is automatically validated and authorized via FormRequest
            $file = $request->getValidatedFile();
            $expenseUserId = $request->getValidatedExpenseUserId();
            $clientId = $request->getValidatedClientId();

            Log::info('Starting pocket expense CSV upload', [
                'user_id' => $request->user()->id,
                'expense_user_id' => $expenseUserId,
                'client_id' => $clientId,
                'filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'estimated_rows' => $request->getEstimatedRowCount(),
            ]);

            // Process the upload
            $uploadRecord = $this->uploadService->processUpload(
                $file,
                $request->user(),
                $expenseUserId,
                $clientId
            );

            // Determine response status based on upload status
            $statusCode = match ($uploadRecord->status) {
                PocketExpenseFileUpload::STATUS_COMPLETED => 201,
                PocketExpenseFileUpload::STATUS_FAILED => 422,
                default => 202, // Accepted for processing
            };

            // Build response message
            $message = match ($uploadRecord->status) {
                PocketExpenseFileUpload::STATUS_COMPLETED => 'File uploaded and processed successfully. All expenses have been created.',
                PocketExpenseFileUpload::STATUS_FAILED => 'File upload failed due to validation errors.',
                PocketExpenseFileUpload::STATUS_PROCESSING => 'File uploaded successfully and is being processed in the background.',
                PocketExpenseFileUpload::STATUS_VALIDATING => 'File uploaded successfully and is being validated.',
                default => 'File upload initiated successfully.',
            };

            // Handle validation errors for failed uploads
            if ($uploadRecord->status === PocketExpenseFileUpload::STATUS_FAILED) {
                $validationErrors = $uploadRecord->validation_errors ?? [];
                
                Log::warning('CSV upload failed validation', [
                    'upload_id' => $uploadRecord->id,
                    'user_id' => $request->user()->id,
                    'error_count' => count($validationErrors),
                    'total_rows' => $uploadRecord->total_records,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'upload_id' => $uploadRecord->id,
                    'total_rows' => $uploadRecord->total_records,
                    'error_count' => count($validationErrors),
                    'errors' => $validationErrors,
                ], 422);
            }

            // Success response
            Log::info('CSV upload processed successfully', [
                'upload_id' => $uploadRecord->id,
                'user_id' => $request->user()->id,
                'status' => $uploadRecord->status,
                'total_rows' => $uploadRecord->total_records,
                'valid_rows' => $uploadRecord->valid_records,
            ]);

            $responseData = [
                'success' => true,
                'message' => $message,
                'upload_id' => $uploadRecord->id,
                'total_rows' => $uploadRecord->total_records,
                'data' => new FileUploadResource($uploadRecord->load(['user', 'client', 'expenseUser'])),
            ];

            // Add additional info for completed uploads
            if ($uploadRecord->status === PocketExpenseFileUpload::STATUS_COMPLETED) {
                $responseData['processed_rows'] = $uploadRecord->processed_records;
                $responseData['success_rate'] = $uploadRecord->getSuccessRateAttribute();
            }

            return response()->json($responseData, $statusCode);

        } catch (\Exception $e) {
            Log::error('Error processing CSV upload', [
                'user_id' => $request->user()->id,
                'filename' => $request->file('file')?->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process CSV upload',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get upload status for a specific upload.
     */
    public function getUploadStatus(Request $request, int $uploadId): JsonResponse
    {
        try {
            $upload = PocketExpenseFileUpload::with(['user', 'client', 'expenseUser'])
                ->where('id', $uploadId)
                ->notDeleted()
                ->firstOrFail();

            // Authorize the request
            $expensePolicy = new ExpensePolicy();
            if (!$expensePolicy->viewUploadStatus($request->user(), $upload->user_id, $upload->client_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this upload status',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => new FileUploadResource($upload),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error retrieving upload status', [
                'upload_id' => $uploadId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve upload status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get list of uploads for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Build query with filters
            $query = PocketExpenseFileUpload::with(['user', 'client', 'expenseUser'])
                ->notDeleted()
                ->latest();

            // Filter by client if specified and authorized
            if ($request->has('client_id')) {
                $clientId = (int) $request->get('client_id');
                $expensePolicy = new ExpensePolicy();
                
                if ($expensePolicy->viewClientExpenses($request->user(), $clientId)) {
                    $query->forClient($clientId);
                } else {
                    $query->forUser($request->user()->id);
                }
            } else {
                // Default to user's own uploads
                $query->forUser($request->user()->id);
            }

            // Apply status filter
            if ($request->has('status')) {
                $status = $request->get('status');
                if (in_array($status, PocketExpenseFileUpload::getValidStatuses())) {
                    $query->withStatus($status);
                }
            }

            // Apply date range filter
            if ($request->has('start_date') && $request->has('end_date')) {
                try {
                    $startDate = \Carbon\Carbon::parse($request->get('start_date'));
                    $endDate = \Carbon\Carbon::parse($request->get('end_date'));
                    $query->inDateRange($startDate, $endDate);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid date range format',
                    ], 400);
                }
            }

            // Paginate results
            $perPage = min(50, max(10, (int) $request->get('per_page', 15)));
            $uploads = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => FileUploadResource::collection($uploads),
                'pagination' => [
                    'current_page' => $uploads->currentPage(),
                    'last_page' => $uploads->lastPage(),
                    'per_page' => $uploads->perPage(),
                    'total' => $uploads->total(),
                    'from' => $uploads->firstItem(),
                    'to' => $uploads->lastItem(),
                    'has_more_pages' => $uploads->hasMorePages(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error retrieving uploads list', [
                'user_id' => $request->user()->id,
                'filters' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve uploads',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Cancel a pending upload.
     */
    public function cancelUpload(Request $request, int $uploadId): JsonResponse
    {
        try {
            $upload = PocketExpenseFileUpload::findOrFail($uploadId);

            // Authorize the request
            if (!$this->canManageUpload($request->user(), $upload)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to cancel this upload',
                ], 403);
            }

            // Check if upload can be cancelled
            if (!$upload->isInProgress()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload cannot be cancelled in current status: ' . $upload->status,
                ], 400);
            }

            // Mark upload as failed/cancelled
            $upload->markAsFailed('Upload cancelled by user');

            Log::info('Upload cancelled successfully', [
                'upload_id' => $uploadId,
                'user_id' => $request->user()->id,
                'original_status' => $upload->getOriginal('status'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Upload cancelled successfully',
                'data' => new FileUploadResource($upload->load(['user', 'client', 'expenseUser'])),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error cancelling upload', [
                'upload_id' => $uploadId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel upload',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Download validation errors for a failed upload.
     */
    public function downloadErrors(Request $request, int $uploadId): JsonResponse
    {
        try {
            $upload = PocketExpenseFileUpload::findOrFail($uploadId);

            // Authorize the request
            if (!$this->canManageUpload($request->user(), $upload)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to download errors for this upload',
                ], 403);
            }

            // Check if upload has errors
            if (!$upload->hasValidationErrors() && !$upload->hasProcessingErrors()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No errors found for this upload',
                ], 404);
            }

            // Prepare errors data
            $errors = [];
            
            if ($upload->hasValidationErrors()) {
                $errors = array_merge($errors, $upload->validation_errors);
            }
            
            if ($upload->hasProcessingErrors()) {
                $errors = array_merge($errors, $upload->processing_errors);
            }

            // Sort errors by line number
            usort($errors, function ($a, $b) {
                return ($a['line_number'] ?? 0) <=> ($b['line_number'] ?? 0);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'upload_id' => $upload->id,
                    'filename' => $upload->original_filename,
                    'total_errors' => count($errors),
                    'validation_errors' => $upload->getValidationErrorCount(),
                    'processing_errors' => $upload->getProcessingErrorCount(),
                    'errors' => $errors,
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'format' => 'json',
                ],
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload not found',
            ], 404);

        } catch (\Exception $e) {
            Log::error('Error downloading upload errors', [
                'upload_id' => $uploadId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download errors',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete an upload record (soft delete).
     */
    public function deleteUpload(Request $request, int $uploadId): JsonResponse
    {
        try {
            $upload = PocketExpenseFileUpload::findOrFail($uploadId);

            // Authorize the request
            if (!$this->canManageUpload($request->user(), $upload)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to delete this upload',
                ], 403);
            }

            // Check if upload can be deleted
            if ($upload->isInProgress()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete upload while it is being processed',
                ], 400);
            }