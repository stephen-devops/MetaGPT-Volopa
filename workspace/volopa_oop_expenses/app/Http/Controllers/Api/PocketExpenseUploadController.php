<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Services\PocketExpenseCSVValidator;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\User;
use App\Models\Client;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseUploadController
 * 
 * Handles CSV file uploads for batch pocket expense processing.
 * Provides synchronous validation with asynchronous processing via Laravel queues.
 * Supports file storage, validation tracking, and batch processing status management.
 */
class PocketExpenseUploadController extends Controller
{
    /**
     * The CSV validator service instance.
     *
     * @var \App\Services\PocketExpenseCSVValidator
     */
    protected PocketExpenseCSVValidator $csvValidator;

    /**
     * Maximum file size in bytes (10MB).
     *
     * @var int
     */
    protected int $maxFileSize = 10485760; // 10 * 1024 * 1024

    /**
     * Maximum number of records allowed per CSV file.
     *
     * @var int
     */
    protected int $maxRecords = 200;

    /**
     * Allowed file extensions.
     *
     * @var array<string>
     */
    protected array $allowedExtensions = ['csv', 'txt'];

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\PocketExpenseCSVValidator $csvValidator
     */
    public function __construct(PocketExpenseCSVValidator $csvValidator)
    {
        $this->csvValidator = $csvValidator;
        
        // Apply OAuth2 middleware for authentication
        $this->middleware('auth:api');
        
        // Apply client scoping middleware for multi-tenancy
        $this->middleware('client.scope');
    }

    /**
     * Upload and validate CSV file for batch pocket expense processing.
     * 
     * Performs synchronous validation and initiates asynchronous processing
     * if validation passes. Uses all-or-nothing validation approach.
     *
     * @param \App\Http\Requests\UploadPocketExpenseCSVRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadPocketExpenseCSV(UploadPocketExpenseCSVRequest $request): JsonResponse
    {
        try {
            // Get authenticated user and client information
            $authenticatedUser = $request->user();
            $clientId = $request->get('client_id'); // Injected by client.scope middleware
            $targetUserId = $request->integer('target_user_id');
            $csvFile = $request->file('csv_file');

            // Validate target user exists and belongs to the same client
            $targetUser = User::where('id', $targetUserId)
                             ->where('client_id', $clientId)
                             ->first();

            if (!$targetUser) {
                return response()->json([
                    'message' => 'Target user not found or does not belong to your client.',
                    'errors' => [
                        'target_user_id' => ['The specified target user is invalid.']
                    ]
                ], 422);
            }

            // Validate file constraints
            $fileValidation = $this->validateFileConstraints($csvFile);
            if ($fileValidation !== true) {
                return response()->json([
                    'message' => 'File validation failed.',
                    'errors' => [
                        'csv_file' => [$fileValidation]
                    ]
                ], 422);
            }

            // Store the uploaded file
            $storedFilePath = $this->storeUploadedFile($csvFile, $clientId);

            // Create upload tracking record
            $uploadRecord = $this->createUploadRecord(
                $authenticatedUser->id,
                $clientId,
                $targetUserId,
                $csvFile->getClientOriginalName(),
                $storedFilePath
            );

            // Validate CSV content
            $validationResult = $this->csvValidator->validate(
                $storedFilePath,
                $targetUserId,
                $clientId,
                $authenticatedUser->id
            );

            // Update upload record with validation results
            $uploadRecord->total_records = $validationResult['total_records'];
            $uploadRecord->valid_records = $validationResult['valid_records'];
            $uploadRecord->validated_at = now();

            if (!$validationResult['is_valid']) {
                // Validation failed - update status and return errors
                $uploadRecord->status = 'validation_failed';
                $uploadRecord->validation_errors = $validationResult['errors'];
                $uploadRecord->save();

                // Clean up stored file since validation failed
                Storage::delete($storedFilePath);

                return response()->json([
                    'message' => 'CSV validation failed.',
                    'upload_id' => $uploadRecord->uuid,
                    'total_records' => $validationResult['total_records'],
                    'valid_records' => $validationResult['valid_records'],
                    'errors' => $validationResult['errors']
                ], 422);
            }

            // Validation passed - store validated data and initiate processing
            $uploadRecord->status = 'validation_passed';
            $uploadRecord->save();

            // Store validated rows in uploads_data table
            $this->storeValidatedData($uploadRecord->id, $validationResult['validated_rows']);

            // Update status to processing and dispatch background job
            $uploadRecord->status = 'processing';
            $uploadRecord->save();

            // Dispatch queue job for asynchronous processing
            ProcessExpenseUpload::dispatch($uploadRecord->id);

            Log::info('CSV upload validation completed successfully', [
                'upload_id' => $uploadRecord->uuid,
                'user_id' => $authenticatedUser->id,
                'client_id' => $clientId,
                'target_user_id' => $targetUserId,
                'total_records' => $validationResult['total_records'],
                'valid_records' => $validationResult['valid_records'],
                'file_name' => $csvFile->getClientOriginalName(),
            ]);

            return response()->json([
                'message' => 'CSV file uploaded and validation passed. Processing has been initiated.',
                'upload_id' => $uploadRecord->uuid,
                'status' => 'processing',
                'total_records' => $validationResult['total_records'],
                'valid_records' => $validationResult['valid_records'],
                'estimated_completion' => now()->addMinutes(5)->toISOString(), // Rough estimate
            ], 200);

        } catch (\Exception $e) {
            Log::error('CSV upload processing failed', [
                'user_id' => $authenticatedUser->id ?? null,
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up any created records or files on error
            if (isset($uploadRecord)) {
                $uploadRecord->update([
                    'status' => 'failed',
                    'validation_errors' => [
                        'system_error' => 'An unexpected error occurred during processing.'
                    ]
                ]);
            }

            if (isset($storedFilePath)) {
                Storage::delete($storedFilePath);
            }

            return response()->json([
                'message' => 'An error occurred while processing the upload.',
                'error' => 'Internal server error. Please try again later.'
            ], 500);
        }
    }

    /**
     * Get the status of a specific upload by UUID.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $uploadUuid
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUploadStatus(Request $request, string $uploadUuid): JsonResponse
    {
        try {
            $clientId = $request->get('client_id');
            $authenticatedUser = $request->user();

            $uploadRecord = PocketExpenseFileUpload::where('uuid', $uploadUuid)
                                                  ->where('client_id', $clientId)
                                                  ->first();

            if (!$uploadRecord) {
                return response()->json([
                    'message' => 'Upload record not found.'
                ], 404);
            }

            // Check if user has permission to view this upload
            if ($uploadRecord->user_id !== $authenticatedUser->id && 
                $uploadRecord->created_by_user_id !== $authenticatedUser->id) {
                return response()->json([
                    'message' => 'Unauthorized to view this upload.'
                ], 403);
            }

            $statusData = [
                'upload_id' => $uploadRecord->uuid,
                'status' => $uploadRecord->status,
                'file_name' => $uploadRecord->file_name,
                'total_records' => $uploadRecord->total_records,
                'valid_records' => $uploadRecord->valid_records,
                'uploaded_at' => $uploadRecord->uploaded_at?->toISOString(),
                'validated_at' => $uploadRecord->validated_at?->toISOString(),
                'processed_at' => $uploadRecord->processed_at?->toISOString(),
                'created_at' => $uploadRecord->created_at->toISOString(),
            ];

            // Include validation errors if status indicates validation failed
            if (in_array($uploadRecord->status, ['validation_failed', 'failed', 'sync_failed'])) {
                $statusData['validation_errors'] = $uploadRecord->validation_errors;
            }

            return response()->json([
                'message' => 'Upload status retrieved successfully.',
                'data' => $statusData
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve upload status', [
                'upload_uuid' => $uploadUuid,
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to retrieve upload status.',
                'error' => 'Internal server error.'
            ], 500);
        }
    }

    /**
     * Get list of recent uploads for the authenticated user and client.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserUploads(Request $request): JsonResponse
    {
        try {
            $clientId = $request->get('client_id');
            $authenticatedUser = $request->user();
            $perPage = min((int)$request->get('per_page', 15), 50); // Max 50 items per page

            $uploads = PocketExpenseFileUpload::where('client_id', $clientId)
                                             ->where(function ($query) use ($authenticatedUser) {
                                                 $query->where('user_id', $authenticatedUser->id)
                                                       ->orWhere('created_by_user_id', $authenticatedUser->id);
                                             })
                                             ->orderBy('created_at', 'desc')
                                             ->paginate($perPage);

            $uploadsData = $uploads->getCollection()->map(function ($upload) {
                return [
                    'upload_id' => $upload->uuid,
                    'status' => $upload->status,
                    'file_name' => $upload->file_name,
                    'total_records' => $upload->total_records,
                    'valid_records' => $upload->valid_records,
                    'uploaded_at' => $upload->uploaded_at?->toISOString(),
                    'validated_at' => $upload->validated_at?->toISOString(),
                    'processed_at' => $upload->processed_at?->toISOString(),
                    'created_at' => $upload->created_at->toISOString(),
                ];
            });

            return response()->json([
                'message' => 'Upload history retrieved successfully.',
                'data' => $uploadsData,
                'meta' => [
                    'current_page' => $uploads->currentPage(),
                    'per_page' => $uploads->perPage(),
                    'total' => $uploads->total(),
                    'last_page' => $uploads->lastPage(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to retrieve user uploads', [
                'user_id' => $request->user()->id ?? null,
                'client_id' => $request->get('client_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to retrieve upload history.',
                'error' => 'Internal server error.'
            ], 500);
        }
    }

    /**
     * Validate file constraints (size, extension, content).
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return string|true Returns true if valid, error message if invalid
     */
    protected function validateFileConstraints($file)
    {
        // Check file size
        if ($file->getSize() > $this->maxFileSize) {
            return 'File size exceeds maximum limit of 10MB.';
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedExtensions)) {
            return 'Invalid file type. Only CSV and TXT files are allowed.';
        }

        // Check if file is actually readable
        if (!$file->isValid() || !$file->getRealPath()) {
            return 'Uploaded file is corrupted or unreadable.';
        }

        // Basic CSV structure check - ensure it has at least a header row
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return 'Unable to read file contents.';
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if (empty(trim($firstLine))) {
            return 'File appears to be empty.';
        }

        // Check for basic CSV structure (comma-separated values)
        if (substr_count($firstLine, ',') < 3) {
            return 'File does not appear to be a valid CSV with sufficient columns.';
        }

        return true;
    }

    /**
     * Store the uploaded file in secure storage.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param int $clientId
     * @return string The stored file path
     */
    protected function storeUploadedFile($file, int $clientId): string
    {
        $timestamp = now()->format('Y/m/d');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $directory = "uploads/pocket-expenses/{$clientId}/{$timestamp}";
        
        return $file->storeAs($directory, $filename, 'local');
    }

    /**
     * Create upload tracking record.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $targetUserId
     * @param string $originalFilename
     * @param string $storedFilePath
     * @return \App\Models\PocketExpenseFileUpload
     */
    protected function createUploadRecord(
        int $userId, 
        int $clientId, 
        int $targetUserId,
        string $originalFilename, 
        string $storedFilePath
    ): PocketExpenseFileUpload {
        return PocketExpenseFileUpload::create([
            'uuid' => Str::uuid(),
            'user_id' => $targetUserId, // The user the expenses will be created for
            'client_id' => $clientId,
            'created_by_user_id' => $userId, // The user who uploaded the file
            'file_name' => $originalFilename,
            'file_path' => $storedFilePath,
            'total_records' => 0,
            'valid_records' => 0,
            'validation_errors' => null,
            'status' => 'uploaded',
            'uploaded_at' => now(),
        ]);
    }

    /**
     * Store validated CSV data in the uploads_data table.
     *
     * @param int $uploadId
     * @param array $validatedRows
     * @return void
     */
    protected function storeValidatedData(int $uploadId, array $validatedRows): void
    {
        $uploadDataRecords = [];
        $timestamp = now();

        foreach ($validatedRows as $lineNumber => $rowData) {
            $uploadDataRecords[] = [
                'upload_id' => $uploadId,
                'line_number' => $lineNumber,
                'status' => 'validated',
                'expense_data' => json_encode($rowData, JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        // Bulk insert validated data in chunks for performance
        $chunks = array_chunk($uploadDataRecords, 100);
        foreach ($chunks as $chunk) {
            PocketExpenseUploadsData::insert($chunk);
        }

        Log::info('Validated CSV data stored successfully', [
            'upload_id' => $uploadId,
            'records_count' => count($validatedRows),
            'chunks_count' => count($chunks),
        ]);
    }

    /**
     * Clean up old upload files and records based on retention policy.
     * This method can be called by a scheduled command.
     *
     * @param int $daysToRetain Number of days to retain completed uploads
     * @return array Cleanup statistics
     */
    public function cleanupOldUploads(int $daysToRetain = 30): array
    {
        $cutoffDate = now()->subDays($daysToRetain);
        
        // Find old completed uploads
        $oldUploads = PocketExpenseFileUpload::whereIn('status', ['completed', 'failed', 'sync_failed'])
                                            ->where('processed_at', '<', $cutoffDate)
                                            ->get();

        $filesDeleted = 0;
        $recordsDeleted = 0;

        foreach ($oldUploads as $upload) {
            try {
                // Delete the file from storage
                if (Storage::exists($upload->file_path)) {
                    Storage::delete($upload->file_path);
                    $filesDeleted++;
                }

                // Soft delete the upload record (using Laravel SoftDeletes)
                $upload->delete();
                $recordsDeleted++;

                Log::info('Old upload cleaned up', [
                    'upload_id' => $upload->uuid,
                    'file_path' => $upload->file_path,
                    'processed_at' => $upload->processed_at,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to cleanup old upload', [
                    'upload_id' => $upload->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'files_deleted' => $filesDeleted,
            'records_deleted' => $recordsDeleted,
            'cutoff_date' => $cutoffDate->toISOString(),
        ];
    }
}