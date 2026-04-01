<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessExpenseUpload;
use App\Models\PocketExpenseFileUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PocketExpenseUploadController
 * 
 * Handles CSV file upload for batch expense processing.
 * Performs synchronous validation with all-or-nothing approach,
 * then dispatches background job for main service sync.
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
     * Upload and validate CSV file for pocket expense batch processing.
     * 
     * Route: POST /api/uploads/pocket-expense/csv
     * Content-Type: multipart/form-data
     * 
     * @param UploadPocketExpenseCSVRequest $request
     * @return JsonResponse
     */
    public function uploadPocketExpenseCSV(UploadPocketExpenseCSVRequest $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            // Get validated request data
            $validatedData = $request->validated();
            $file = $request->file('file');
            $userId = (int) $validatedData['user_id'];
            $expenseUserId = (int) $validatedData['expense_user_id'];
            $clientId = (int) $validatedData['client_id'];

            // Generate unique file path for storage
            $fileName = $file->getClientOriginalName();
            $uniqueFileName = Str::uuid() . '_' . $fileName;
            $storagePath = 'pocket-expense-uploads/' . $uniqueFileName;

            // Store file to configured storage path
            $filePath = $file->storeAs('pocket-expense-uploads', $uniqueFileName);

            if (!$filePath) {
                throw new \Exception('Failed to store uploaded file');
            }

            // Create upload record with initial status
            $upload = PocketExpenseFileUpload::create([
                'uuid' => Str::uuid()->toString(),
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
            ]);

            // Get absolute file path for validation
            $absoluteFilePath = Storage::path($filePath);

            // Perform synchronous validation
            $validationResult = $this->csvValidator->validate(
                $absoluteFilePath,
                $expenseUserId,
                $clientId,
                $userId
            );

            // Update upload record with validation results
            $upload->update([
                'total_records' => $validationResult['total_rows'],
                'valid_records' => $validationResult['valid_rows'],
                'validation_errors' => $validationResult['errors'] ?? null,
                'validated_at' => now(),
            ]);

            // Check if validation failed (all-or-nothing approach)
            if (!$validationResult['success']) {
                $upload->update(['status' => 'validation_failed']);
                
                DB::commit();

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'upload_id' => $upload->id,
                    'total_rows' => $validationResult['total_rows'],
                    'error_count' => count($validationResult['errors']),
                    'errors' => $validationResult['errors'],
                ], 422);
            }

            // Validation passed - update status and prepare for processing
            $upload->update([
                'status' => 'validation_passed',
            ]);

            // Store validated expense data for processing
            $this->storeValidatedExpenseData($upload->id, $validationResult['validated_data']);

            // Update upload status to processing
            $upload->update(['status' => 'processing']);

            // Dispatch background job for main service sync
            ProcessExpenseUpload::dispatch($upload->id)->onQueue('expense-processing');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'File validated successfully. Expenses are being processed.',
                'upload_id' => $upload->id,
                'total_rows' => $validationResult['total_rows'],
            ], 200);

        } catch (\Exception $e) {
            DB::rollback();

            // Clean up uploaded file if processing failed
            if (isset($filePath) && Storage::exists($filePath)) {
                Storage::delete($filePath);
            }

            // Log error for debugging
            \Log::error('CSV upload failed', [
                'error' => $e->getMessage(),
                'user_id' => $validatedData['user_id'] ?? null,
                'expense_user_id' => $validatedData['expense_user_id'] ?? null,
                'client_id' => $validatedData['client_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'File upload and validation failed. Please try again.',
                'upload_id' => $upload->id ?? null,
                'total_rows' => 0,
                'error_count' => 1,
                'errors' => [
                    [
                        'line_number' => 0,
                        'field' => 'file',
                        'error' => 'System error during processing',
                        'value' => 'N/A'
                    ]
                ],
            ], 422);
        }
    }

    /**
     * Store validated expense data for background processing.
     * 
     * @param int $uploadId
     * @param array $validatedData
     * @return void
     */
    private function storeValidatedExpenseData(int $uploadId, array $validatedData): void
    {
        $batchData = [];
        $now = now();

        foreach ($validatedData as $lineNumber => $expenseData) {
            $batchData[] = [
                'upload_id' => $uploadId,
                'line_number' => $lineNumber,
                'status' => 'pending',
                'expense_data' => json_encode($expenseData),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk insert for performance
        if (!empty($batchData)) {
            DB::table('pocket_expense_uploads_data')->insert($batchData);
        }
    }
}