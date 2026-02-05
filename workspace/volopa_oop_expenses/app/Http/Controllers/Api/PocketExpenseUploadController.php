## Code: app/Http/Controllers/Api/PocketExpenseUploadController.php

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PocketExpenseCSVUploadRequest;
use App\Http\Resources\ExpenseUploadResource;
use App\Models\PocketExpense;
use App\Models\PocketExpenseFileUpload;
use App\Services\PocketExpenseCSVValidator;
use App\Services\PocketExpenseService;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Exception;

/**
 * PocketExpenseUploadController
 * 
 * Controller for CSV upload operations for pocket expenses.
 * Handles file upload, validation, and background processing initiation.
 * Implements synchronous all-or-nothing validation with detailed error reporting.
 */
class PocketExpenseUploadController extends Controller
{
    /**
     * The CSV validator service instance.
     *
     * @var PocketExpenseCSVValidator
     */
    private PocketExpenseCSVValidator $validator;

    /**
     * The pocket expense service instance.
     *
     * @var PocketExpenseService
     */
    private PocketExpenseService $service;

    /**
     * Storage disk for uploaded files.
     *
     * @var string
     */
    private string $storageDisk;

    /**
     * Storage path for uploaded files.
     *
     * @var string
     */
    private string $storagePath;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseService $service
     */
    public function __construct(PocketExpenseService $service)
    {
        $this->service = $service;
        
        // Apply middleware
        $this->middleware('auth:api');
        $this->middleware('oauth2.user_client');
        
        // Set storage configuration
        $this->storageDisk = config('pocket_expense.files.csv_upload.storage_disk', 'local');
        $this->storagePath = config('pocket_expense.files.csv_upload.storage_path', 'pocket-expenses/uploads');
    }

    /**
     * Upload and validate CSV file for pocket expense batch creation.
     *
     * @param PocketExpenseCSVUploadRequest $request
     * @return JsonResponse
     */
    public function uploadCSV(PocketExpenseCSVUploadRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validatedData = $request->validated();
            $user = $request->user();
            $csvFile = $request->getCSVFile();

            if (!$csvFile) {
                throw new Exception('No valid CSV file uploaded');
            }

            $userId = $user->id;
            $clientId = (int) $validatedData['client_id'];
            $targetUserId = (int) $validatedData['user_id'];

            Log::info('Starting CSV upload processing', [
                'uploader_user_id' => $userId,
                'target_user_id' => $targetUserId,
                'client_id' => $clientId,
                'filename' => $csvFile->getClientOriginalName(),
                'file_size' => $csvFile->getSize(),
            ]);

            // Store the uploaded file
            $storedFile = $this->storeUploadedFile($csvFile);

            // Create upload record
            $upload = $this->createUploadRecord($storedFile, $validatedData, $userId, $clientId, $targetUserId);

            // Initialize validator with context
            $this->validator = new PocketExpenseCSVValidator($targetUserId, $clientId);

            // Perform synchronous validation
            $validationResult = $this->validator->validate($storedFile['full_path']);

            if (!$validationResult->isValid) {
                // Validation failed - update upload status and return errors
                $this->updateUploadStatus($upload, PocketExpenseFileUpload::STATUS_VALIDATION_FAILED, [
                    'validation_errors' => $validationResult->errors,
                    'total_records' => $validationResult->totalRows,
                    'valid_records' => 0,
                ]);

                DB::commit();

                Log::warning('CSV validation failed', [
                    'upload_id' => $upload->id,
                    'total_rows' => $validationResult->totalRows,
                    'error_count' => $validationResult->errorCount,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'upload_id' => $upload->id,
                    'total_rows' => $validationResult->totalRows,
                    'error_count' => $validationResult->errorCount,
                    'errors' => $validationResult->errors,
                    'upload_status' => new ExpenseUploadResource($upload->fresh()),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Validation passed - store validated data and initiate background processing
            $this->storeValidatedRows($validationResult->validatedRows, $upload->id);

            $this->updateUploadStatus($upload, PocketExpenseFileUpload::STATUS_VALIDATION_PASSED, [
                'total_records' => $validationResult->totalRows,
                'valid_records' => count($validationResult->validatedRows),
                'validation_errors' => null,
            ]);

            // Dispatch background job for processing
            ProcessExpenseUpload::dispatch($upload->id);

            DB::commit();

            Log::info('CSV validation passed, background processing initiated', [
                'upload_id' => $upload->id,
                'total_rows' => $validationResult->totalRows,
                'valid_rows' => count($validationResult->validatedRows),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'File validated successfully. Expenses creation started.',
                'upload_id' => $upload->id,
                'total_rows' => $validationResult->totalRows,
                'valid_rows' => count($validationResult->validatedRows),
                'upload_status' => new ExpenseUploadResource($upload->fresh()),
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
                'error_code' => 'VALIDATION_FAILED'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('CSV upload processing failed', [
                'uploader_user_id' => $request->user()?->id,
                'target_user_id' => $request->input('user_id'),
                'client_id' => $request->input('client_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process CSV upload.',
                'error_code' => 'UPLOAD_PROCESSING_FAILED',
                'details' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get upload status and processing results.
     *
     * @param Request $request
     * @param int $uploadId
     * @return JsonResponse
     */
    public function getUploadStatus(Request $request, int $uploadId): JsonResponse
    {
        try {
            // Find the upload with relationships
            $upload = PocketExpenseFileUpload::with(['user', 'targetUser', 'client', 'uploadData'])
                ->findOrFail($uploadId);

            // Check authorization
            $user = $request->user();
            if (!$user->can('viewUploadStatus', [PocketExpense::class, $upload->client_id, $upload->user_id])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this upload status.',
                    'error_code' => 'UNAUTHORIZED_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }

            // Get additional status information
            $statusInfo = $this->getDetailedStatusInfo($upload);

            return response()->json([
                'success' => true,
                'data' => new ExpenseUploadResource($upload),
                'status_info' => $statusInfo,
                'message' => 'Upload status retrieved successfully.'
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload not found.',
                'error_code' => 'UPLOAD_NOT_FOUND'
            ], Response::HTTP_NOT_FOUND);

        } catch (Exception $e) {
            Log::error('Failed to retrieve upload status', [
                'upload_id' => $uploadId,
                'request_user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve upload status.',
                'error_code' => 'STATUS_RETRIEVAL_FAILED'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store uploaded CSV file to disk.
     *
     * @param \Illuminate\Http\UploadedFile $csvFile
     * @return array<string, string>
     * @throws Exception
     */
    private function storeUploadedFile($csvFile): array
    {
        try {
            $originalFilename = $csvFile->getClientOriginalName();
            $timestamp = now()->format('Y-m-d_H-i-s');
            $randomHash = substr(hash('sha256', $originalFilename . $timestamp . mt_rand()), 0, 8);
            $storedFilename = "pocket_expense_upload_{$timestamp}_{$randomHash}.csv";
            
            // Store file
            $filePath = $csvFile->storeAs($this->storagePath, $storedFilename, $this->storageDisk);
            
            if (!$filePath) {
                throw new Exception('Failed to store uploaded file');
            }

            $fullPath = Storage::disk($this->storageDisk)->path($filePath);

            return [
                'original_filename' => $originalFilename,
                'stored_filename' => $storedFilename,
                'file_path' => $filePath,
                'full_path' => $fullPath,
                'file_size' => $csvFile->getSize(),
                'mime_type' => $csvFile->getMimeType(),
            ];

        } catch (Exception $e) {
            Log::error('Failed to store uploaded CSV file', [
                'original_filename' => $csvFile->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to store uploaded file: ' . $e->getMessage());
        }
    }

    /**
     * Create upload record in database.
     *
     * @param array<string, string> $storedFile
     * @param array<string, mixed> $validatedData
     * @param int $userId
     * @param int $clientId
     * @param int $targetUserId
     * @return PocketExpenseFileUpload
     * @throws Exception
     */
    private function createUploadRecord(
        array $storedFile, 
        array $validatedData, 
        int $userId, 
        int $clientId, 
        int $targetUserId
    ): PocketExpenseFileUpload {
        try {
            return PocketExpenseFileUpload::create([
                'user_id' => $userId,
                'client_id' => $clientId,
                'target_user_id' => $targetUserId,
                'original_filename' => $storedFile['original_filename'],
                'stored_filename' => $storedFile['stored_filename'],
                'file_path' => $storedFile['file_path'],
                'mime_type' => $storedFile['mime_type'],
                'file_size' => (int) $storedFile['file_size'],
                'status' => PocketExpenseFileUpload::STATUS_UPLOADED,
                'total_records' => 0,
                'valid_records' => 0,
                'processed_records' => 0,
                'failed_records' => 0,
                'validation_errors' => null,
                'processing_errors' => null,
                'notes' => isset($validatedData['notes']) ? trim($validatedData['notes']) : null,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'deleted' => false,
                'delete_time' => null,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to create upload record', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'target_user_id' => $targetUserId,
                'filename' => $storedFile['original_filename'],
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to create upload record: ' . $e->getMessage());
        }
    }

    /**
     * Update upload status and related fields.
     *
     * @param PocketExpenseFileUpload $upload
     * @param string $status
     * @param array<string, mixed> $additionalData
     * @return void
     * @throws Exception
     */
    private function updateUploadStatus(PocketExpenseFileUpload $upload, string $status, array $additionalData = []): void
    {
        try {
            $updateData = array_merge([
                'status' => $status,
            ], $additionalData);

            // Set timestamp based on status
            switch ($status) {
                case PocketExpenseFileUpload::STATUS_PROCESSING:
                    $updateData['started_at'] = now();
                    break;
                case PocketExpenseFileUpload::STATUS_COMPLETED:
                    $updateData['completed_at'] = now();
                    break;
                case PocketExpenseFileUpload::STATUS_FAILED:
                case PocketExpenseFileUpload::STATUS_SYNC_FAILED:
                case PocketExpenseFileUpload::STATUS_VALIDATION_FAILED:
                    $updateData['failed_at'] = now();
                    break;
            }

            $upload->update($updateData);

        } catch (Exception $e) {
            Log::error('Failed to update upload status', [
                'upload_id' => $upload->id,
                'status' => $status,
                'additional_data' => $additionalData,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Failed to update upload status: ' . $e->getMessage());
        }
    }

    /**
     * Store validated CSV rows for background processing.
     *
     * @param array<array<string, mixed>> $validatedRows
     * @param int $uploadId
     * @return void
     * @throws Exception
     */
    private function storeValidatedRows(array $validatedRows, int $uploadId): void
    {
        try {
            $uploadDataRecords = [];
            
            foreach ($validatedRows as $row) {
                $uploadDataRecords[] = [
                    'upload_id' => $uploadId,
                    'line_number' => $row['line_number'],
                    'status' => 'pending',
                    'expense_data' => json_encode($row['data']),
                    'validation_errors' => null,
                    'processing_errors' => null,
                    'created_expense_id' => null,
                    'notes' => null,
                    'synced_at