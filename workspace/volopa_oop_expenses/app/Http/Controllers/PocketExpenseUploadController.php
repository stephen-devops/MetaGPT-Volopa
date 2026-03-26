<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Http\Resources\PocketExpenseUploadResource;
use App\Models\PocketExpenseFileUpload;
use App\Services\PocketExpenseCSVValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pocket Expense Upload Controller
 * 
 * Handles CSV batch upload processing for pocket expenses.
 * Implements sync validation with async background processing.
 * Route omits /v1 prefix per specification requirements.
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
        $this->middleware('auth:api');
    }

    /**
     * Upload and validate CSV file for batch pocket expense processing.
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
            DB::beginTransaction();

            // Extract validated form data
            $file = $request->file('file');
            $userId = $request->validated('user_id');
            $expenseUserId = $request->validated('expense_user_id');
            $clientId = $request->validated('client_id');

            // Store uploaded file
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('uploads/pocket-expense/csv', 'local');
            $fullFilePath = Storage::disk('local')->path($filePath);

            // Create upload record with initial status
            $uploadRecord = PocketExpenseFileUpload::create([
                'uuid' => Str::uuid()->toString(),
                'user_id' => $expenseUserId, // Target user for expenses
                'client_id' => $clientId,
                'created_by_user_id' => $userId, // Admin who uploaded
                'file_name' => $fileName,
                'file_path' => $filePath,
                'total_records' => 0,
                'valid_records' => 0,
                'validation_errors' => null,
                'status' => 'uploaded',
                'uploaded_at' => now(),
                'validated_at' => null,
                'processed_at' => null,
            ]);

            // Update upload status to validating
            $uploadRecord->update(['status' => 'validating']);

            // Perform synchronous CSV validation
            $validationResult = $this->csvValidator->validate(
                $fullFilePath, 
                $expenseUserId, 
                $clientId, 
                $userId
            );

            // Extract validation results
            $totalRows = $validationResult['total_rows'] ?? 0;
            $validRows = $validationResult['valid_rows'] ?? 0;
            $errorCount = $validationResult['error_count'] ?? 0;
            $errors = $validationResult['errors'] ?? [];
            $validatedData = $validationResult['validated_data'] ?? [];

            // Update upload record with validation results
            $uploadRecord->update([
                'total_records' => $totalRows,
                'valid_records' => $validRows,
                'validation_errors' => $errorCount > 0 ? json_encode($errors) : null,
                'validated_at' => now(),
            ]);

            // Check if validation failed (all-or-nothing constraint)
            if ($errorCount > 0) {
                $uploadRecord->update(['status' => 'failed']);
                
                DB::commit();
                
                // Return structured error response per API specification
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'upload_id' => $uploadRecord->id,
                    'total_rows' => $totalRows,
                    'error_count' => $errorCount,
                    'errors' => $errors,
                ], 422);
            }

            // Store validated data for background processing
            if (!empty($validatedData)) {
                $uploadsData = [];
                $lineNumber = 2; // Start from line 2 (after header)
                
                foreach ($validatedData as $rowData) {
                    $uploadsData[] = [
                        'upload_id' => $uploadRecord->id,
                        'line_number' => $lineNumber,
                        'status' => 'pending',
                        'expense_data' => json_encode($rowData),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $lineNumber++;
                }
                
                // Bulk insert validated data
                DB::table('pocket_expense_uploads_data')->insert($uploadsData);
            }

            // Update upload status to processing and dispatch background job
            $uploadRecord->update(['status' => 'processing']);

            // TODO: Dispatch ProcessExpenseUpload job for background processing
            // \App\Jobs\ProcessExpenseUpload::dispatch($uploadRecord->id);
            
            DB::commit();

            Log::info('CSV upload validated successfully', [
                'upload_id' => $uploadRecord->id,
                'file_name' => $fileName,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'client_id' => $clientId,
                'user_id' => $userId,
                'expense_user_id' => $expenseUserId,
            ]);

            // Return success response per API specification
            return response()->json([
                'success' => true,
                'message' => 'File validated successfully. Expenses queued for processing.',
                'upload_id' => $uploadRecord->id,
                'total_rows' => $totalRows,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('CSV upload processing failed', [
                'error' => $e->getMessage(),
                'file_name' => $request->file('file')?->getClientOriginalName(),
                'user_id' => $request->validated('user_id'),
                'expense_user_id' => $request->validated('expense_user_id'),
                'client_id' => $request->validated('client_id'),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up upload record if it was created
            if (isset($uploadRecord) && $uploadRecord->exists) {
                $uploadRecord->update([
                    'status' => 'failed',
                    'validation_errors' => json_encode([
                        [
                            'line_number' => 0,
                            'field' => 'system',
                            'error' => 'Internal processing error occurred',
                            'value' => null,
                        ]
                    ])
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'File upload processing failed',
                'upload_id' => $uploadRecord->id ?? null,
                'total_rows' => 0,
                'error_count' => 1,
                'errors' => [
                    [
                        'line_number' => 0,
                        'field' => 'system',
                        'error' => 'Internal processing error occurred',
                        'value' => null,
                    ]
                ],
            ], 422);
        }
    }

    /**
     * Get upload processing status and progress.
     * 
     * Route: GET /api/uploads/pocket-expense/{uploadId}/status
     * 
     * @param int $uploadId
     * @return JsonResponse
     */
    public function getUploadStatus(int $uploadId): JsonResponse
    {
        try {
            $upload = PocketExpenseFileUpload::with(['user', 'client', 'createdBy'])
                ->findOrFail($uploadId);

            // TODO: Add authorization check - ensure user has permission to view this upload
            // Policy check should verify:
            // - User is the creator (created_by_user_id)
            // - User is the target user (user_id) 
            // - User has management rights over the target user
            // - User is Primary Admin with full access
            
            return response()->json([
                'success' => true,
                'data' => new PocketExpenseUploadResource($upload),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload record not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve upload status', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve upload status',
            ], 500);
        }
    }
}