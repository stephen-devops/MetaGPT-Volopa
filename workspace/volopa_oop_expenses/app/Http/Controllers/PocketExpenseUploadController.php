<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Services\PocketExpenseCSVValidator;
use App\Models\PocketExpenseFileUpload;
use App\Jobs\ProcessExpenseUpload;
use App\Http\Resources\PocketExpenseUploadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * PocketExpenseUploadController
 * 
 * Handles CSV file uploads for pocket expenses with synchronous validation
 * and asynchronous processing via Laravel queues.
 */
class PocketExpenseUploadController extends Controller
{
    /**
     * The CSV validator service.
     *
     * @var PocketExpenseCSVValidator
     */
    protected PocketExpenseCSVValidator $csvValidator;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseCSVValidator $csvValidator
     */
    public function __construct(PocketExpenseCSVValidator $csvValidator)
    {
        $this->csvValidator = $csvValidator;
    }

    /**
     * Upload and validate CSV file for pocket expenses.
     * 
     * Route: POST /api/uploads/pocket-expense/csv
     * Content-Type: multipart/form-data
     * 
     * @param UploadPocketExpenseCSVRequest $request
     * @return JsonResponse
     */
    public function uploadPocketExpenseCSV(UploadPocketExpenseCSVRequest $request): JsonResponse
    {
        try {
            // Get validated input data
            $file = $request->file('file');
            $userId = $request->integer('user_id'); // Admin user
            $expenseUserId = $request->integer('expense_user_id'); // Target user for expenses
            $clientId = $request->integer('client_id');

            // Store the uploaded file
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('pocket-expense-uploads', 'local');
            $fullFilePath = Storage::path($filePath);

            // Create upload record with 'uploaded' status
            $upload = PocketExpenseFileUpload::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $expenseUserId,
                'client_id' => $clientId,
                'created_by_user_id' => $userId,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'total_records' => 0, // Will be updated after validation
                'valid_records' => 0, // Will be updated after validation
                'validation_errors' => null,
                'status' => 'uploaded',
                'uploaded_at' => now(),
                'validated_at' => null,
                'processed_at' => null,
            ]);

            // Perform synchronous CSV validation
            $validationResult = $this->csvValidator->validate(
                $fullFilePath,
                $expenseUserId,
                $clientId,
                $userId
            );

            // Update upload record with validation results
            $upload->update([
                'total_records' => $validationResult['total_rows'],
                'valid_records' => $validationResult['valid_rows'],
                'validated_at' => now(),
            ]);

            // Check if validation failed
            if (!$validationResult['success']) {
                $upload->update([
                    'status' => 'validation_failed',
                    'validation_errors' => json_encode($validationResult['errors']),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'upload_id' => $upload->id,
                    'total_rows' => $validationResult['total_rows'],
                    'error_count' => $validationResult['error_count'],
                    'errors' => $validationResult['errors'],
                ], 422);
            }

            // Validation passed - bulk insert to PocketExpenseUploadsData table
            DB::transaction(function () use ($upload, $validationResult) {
                $validRows = $validationResult['valid_data'];
                $uploadsData = [];

                foreach ($validRows as $lineNumber => $rowData) {
                    $uploadsData[] = [
                        'upload_id' => $upload->id,
                        'line_number' => $lineNumber,
                        'status' => 'pending',
                        'expense_data' => json_encode($rowData),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Bulk insert upload data records
                DB::table('pocket_expense_uploads_data')->insert($uploadsData);

                // Update upload status to processing
                $upload->update([
                    'status' => 'processing',
                ]);
            });

            // Dispatch background job for processing expenses
            ProcessExpenseUpload::dispatch($upload->id)->onQueue('expense-processing');

            return response()->json([
                'success' => true,
                'message' => 'File validated successfully. Expenses are being created.',
                'upload_id' => $upload->id,
                'total_rows' => $validationResult['total_rows'],
            ], 200);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('CSV Upload Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return generic error response without exposing internal details
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the upload. Please try again.',
                'upload_id' => null,
                'total_rows' => 0,
                'error_count' => 1,
                'errors' => [],
            ], 500);
        }
    }

    /**
     * Get the status of a specific upload.
     * 
     * @param int $uploadId
     * @return JsonResponse
     */
    public function getUploadStatus(int $uploadId): JsonResponse
    {
        try {
            // TODO: Add authorization check to ensure user can view this upload
            // This should check if the authenticated user is the admin who uploaded
            // or has permission to manage the target user's expenses

            $upload = PocketExpenseFileUpload::findOrFail($uploadId);

            return response()->json([
                'success' => true,
                'data' => new PocketExpenseUploadResource($upload),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload not found.',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Get Upload Status Error', [
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving upload status.',
            ], 500);
        }
    }
}