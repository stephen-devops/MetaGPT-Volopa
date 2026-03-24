<?php

namespace App\Http\Controllers;

use App\Http\Requests\PocketExpenseUploadRequest;
use App\Http\Resources\PocketExpenseFileUploadResource;
use App\Jobs\ProcessExpenseUpload;
use App\Models\PocketExpenseFileUpload;
use App\Services\PocketExpenseCSVValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Controller for handling pocket expense CSV batch upload operations
 * 
 * Handles file upload, synchronous validation, and queuing of asynchronous
 * processing for batch expense creation. Follows all-or-nothing validation
 * principle and platform constraints for file size, format, and permissions.
 */
class PocketExpenseUploadController extends Controller
{
    /**
     * CSV validator service
     *
     * @var PocketExpenseCSVValidator
     */
    protected PocketExpenseCSVValidator $csvValidator;

    /**
     * Constructor - inject dependencies
     *
     * @param PocketExpenseCSVValidator $csvValidator
     */
    public function __construct(PocketExpenseCSVValidator $csvValidator)
    {
        $this->csvValidator = $csvValidator;
        
        // Apply OAuth2 middleware to all routes in this controller
        $this->middleware('Oauth2UserClient');
    }

    /**
     * Upload CSV file for batch pocket expense processing
     * 
     * Handles multipart file upload, performs synchronous validation,
     * and queues asynchronous processing if validation passes.
     * Follows all-or-nothing validation principle.
     * 
     * @param PocketExpenseUploadRequest $request Validated upload request
     * @return JsonResponse Upload result with upload_id or validation errors
     */
    public function uploadPocketExpenseCSV(PocketExpenseUploadRequest $request): JsonResponse
    {
        try {
            // Get authenticated user and client from OAuth2 middleware
            $authUser = Auth::user();
            $clientId = $request->input('client_id', $authUser->client_id ?? 1);
            
            // Get target user and admin user IDs from validated request
            $targetUserId = $request->input('target_user_id');
            $adminUserId = $authUser->id;
            
            Log::info('Starting CSV upload process', [
                'admin_user_id' => $adminUserId,
                'target_user_id' => $targetUserId,
                'client_id' => $clientId,
                'original_filename' => $request->file('file')->getClientOriginalName()
            ]);

            // Start database transaction for upload record creation
            return DB::transaction(function () use ($request, $adminUserId, $targetUserId, $clientId) {
                
                // Store uploaded file
                $uploadedFile = $request->file('file');
                $originalFileName = $uploadedFile->getClientOriginalName();
                $fileExtension = $uploadedFile->getClientOriginalExtension();
                
                // Generate unique file name to prevent conflicts
                $storedFileName = sprintf(
                    '%s_%s_%s.%s',
                    Carbon::now()->format('Y-m-d_H-i-s'),
                    $targetUserId,
                    Str::random(8),
                    $fileExtension
                );
                
                // Store file in dedicated pocket expense uploads directory
                $filePath = $uploadedFile->storeAs(
                    'pocket-expense-uploads',
                    $storedFileName,
                    'local'
                );
                
                if (!$filePath) {
                    Log::error('Failed to store uploaded CSV file', [
                        'admin_user_id' => $adminUserId,
                        'target_user_id' => $targetUserId,
                        'original_filename' => $originalFileName
                    ]);
                    
                    return response()->json([
                        'error' => 'File upload failed',
                        'message' => 'Unable to store uploaded file. Please try again.',
                        'code' => 'UPLOAD_STORAGE_FAILED'
                    ], 500);
                }

                // Create upload record with initial status
                $upload = PocketExpenseFileUpload::create([
                    'uuid' => Str::uuid()->toString(),
                    'user_id' => $targetUserId, // Target user for expenses
                    'client_id' => $clientId,
                    'created_by_user_id' => $adminUserId, // Admin who uploaded
                    'file_name' => $originalFileName,
                    'file_path' => $filePath,
                    'total_records' => 0, // Will be updated after validation
                    'valid_records' => 0, // Will be updated after validation
                    'validation_errors' => null,
                    'status' => 'uploaded',
                    'uploaded_at' => Carbon::now(),
                    'validated_at' => null,
                    'processed_at' => null
                ]);

                Log::info('Created upload record', [
                    'upload_id' => $upload->id,
                    'upload_uuid' => $upload->uuid,
                    'file_path' => $filePath
                ]);

                // Perform synchronous CSV validation
                $fullFilePath = Storage::disk('local')->path($filePath);
                
                Log::info('Starting CSV validation', [
                    'upload_id' => $upload->id,
                    'full_file_path' => $fullFilePath
                ]);

                $validationResult = $this->csvValidator->validate(
                    $fullFilePath,
                    $targetUserId,
                    $clientId,
                    $adminUserId
                );

                // Update upload record with validation results
                $upload->update([
                    'total_records' => $validationResult['total_records'],
                    'valid_records' => $validationResult['valid_records'],
                    'validation_errors' => !empty($validationResult['errors']) ? json_encode($validationResult['errors']) : null,
                    'validated_at' => Carbon::now()
                ]);

                // Check validation result - all-or-nothing principle
                if (!empty($validationResult['errors'])) {
                    // Validation failed - update status and return errors
                    $upload->update(['status' => 'validation_failed']);
                    
                    Log::warning('CSV validation failed', [
                        'upload_id' => $upload->id,
                        'error_count' => count($validationResult['errors']),
                        'total_records' => $validationResult['total_records']
                    ]);

                    return response()->json([
                        'error' => 'Validation failed',
                        'message' => 'CSV file contains validation errors. No expenses have been created.',
                        'code' => 'VALIDATION_FAILED',
                        'upload_id' => $upload->id,
                        'upload_uuid' => $upload->uuid,
                        'validation_summary' => [
                            'total_records' => $validationResult['total_records'],
                            'valid_records' => $validationResult['valid_records'],
                            'error_count' => count($validationResult['errors'])
                        ],
                        'validation_errors' => $validationResult['errors']
                    ], 422);
                }

                // Validation passed - store validated data for processing
                $this->storeValidatedData($upload, $validationResult['validated_data']);

                // Queue asynchronous processing job
                ProcessExpenseUpload::dispatch($upload->id)
                    ->onQueue('expense-processing')
                    ->delay(now()->addSeconds(2)); // Small delay to ensure transaction commit

                // Update status to processing
                $upload->update(['status' => 'processing']);

                Log::info('CSV validation passed, queued for processing', [
                    'upload_id' => $upload->id,
                    'valid_records' => $validationResult['valid_records'],
                    'job_queued' => true
                ]);

                // Return success response with upload details
                return response()->json([
                    'message' => 'File uploaded and queued for processing successfully',
                    'upload_id' => $upload->id,
                    'upload_uuid' => $upload->uuid,
                    'processing_summary' => [
                        'total_records' => $validationResult['total_records'],
                        'valid_records' => $validationResult['valid_records'],
                        'status' => 'processing'
                    ],
                    'data' => new PocketExpenseFileUploadResource($upload)
                ], 200);
            });

        } catch (\Exception $e) {
            Log::error('CSV upload process failed', [
                'admin_user_id' => $adminUserId ?? null,
                'target_user_id' => $targetUserId ?? null,
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Clean up stored file if it exists
            if (isset($filePath) && Storage::disk('local')->exists($filePath)) {
                Storage::disk('local')->delete($filePath);
            }

            return response()->json([
                'error' => 'Upload processing failed',
                'message' => 'An error occurred while processing the upload. Please try again.',
                'code' => 'UPLOAD_PROCESSING_ERROR'
            ], 500);
        }
    }

    /**
     * Store validated CSV data for batch processing
     * 
     * Bulk inserts validated expense data into pocket_expense_uploads_data
     * table for asynchronous processing by the queue job.
     * 
     * @param PocketExpenseFileUpload $upload Upload record
     * @param array $validatedData Array of validated expense data rows
     * @return void
     */
    protected function storeValidatedData(PocketExpenseFileUpload $upload, array $validatedData): void
    {
        $uploadDataRecords = [];
        $now = Carbon::now();
        
        foreach ($validatedData as $lineNumber => $expenseData) {
            $uploadDataRecords[] = [
                'upload_id' => $upload->id,
                'line_number' => $lineNumber,
                'status' => 'pending',
                'expense_data' => json_encode($expenseData),
                'processing_errors' => null,
                'created_expense_id' => null,
                'created_at' => $now,
                'updated_at' => $now
            ];
        }
        
        // Bulk insert validated data in chunks to handle large files efficiently
        $chunks = array_chunk($uploadDataRecords, 100);
        foreach ($chunks as $chunk) {
            DB::table('pocket_expense_uploads_data')->insert($chunk);
        }
        
        Log::info('Stored validated CSV data for processing', [
            'upload_id' => $upload->id,
            'data_records_count' => count($uploadDataRecords),
            'chunks_count' => count($chunks)
        ]);
    }

    /**
     * Get upload status and processing details
     * 
     * Allows checking the status of a CSV upload and its processing progress.
     * Useful for frontend polling to show upload progress.
     * 
     * @param Request $request Request with upload_id or upload_uuid
     * @return JsonResponse Upload status and details
     */
    public function getUploadStatus(Request $request): JsonResponse
    {
        try {
            $uploadId = $request->input('upload_id');
            $uploadUuid = $request->input('upload_uuid');
            
            if (!$uploadId && !$uploadUuid) {
                return response()->json([
                    'error' => 'Missing identifier',
                    'message' => 'Either upload_id or upload_uuid is required',
                    'code' => 'MISSING_UPLOAD_IDENTIFIER'
                ], 400);
            }
            
            // Find upload record by ID or UUID
            $upload = null;
            if ($uploadId) {
                $upload = PocketExpenseFileUpload::find($uploadId);
            } elseif ($uploadUuid) {
                $upload = PocketExpenseFileUpload::where('uuid', $uploadUuid)->first();
            }
            
            if (!$upload) {
                return response()->json([
                    'error' => 'Upload not found',
                    'message' => 'The specified upload record could not be found',
                    'code' => 'UPLOAD_NOT_FOUND'
                ], 404);
            }
            
            // Check if user has permission to view this upload
            $authUser = Auth::user();
            $clientId = $authUser->client_id ?? 1;
            
            if ($upload->client_id !== $clientId) {
                return response()->json([
                    'error' => 'Access denied',
                    'message' => 'You do not have permission to view this upload',
                    'code' => 'UPLOAD_ACCESS_DENIED'
                ], 403);
            }
            
            return response()->json([
                'message' => 'Upload status retrieved successfully',
                'data' => new PocketExpenseFileUploadResource($upload)
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Failed to retrieve upload status', [
                'upload_id' => $uploadId ?? null,
                'upload_uuid' => $uploadUuid ?? null,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Status retrieval failed',
                'message' => 'Unable to retrieve upload status. Please try again.',
                'code' => 'STATUS_RETRIEVAL_ERROR'
            ], 500);
        }
    }

    /**
     * List user's upload history
     * 
     * Returns paginated list of uploads for the authenticated user's client,
     * with optional filtering by status and target user.
     * 
     * @param Request $request Request with optional filters
     * @return JsonResponse Paginated upload list
     */
    public function listUploads(Request $request): JsonResponse
    {
        try {
            $authUser = Auth::user();
            $clientId = $authUser->client_id ?? 1;
            
            // Build query with client scoping
            $query = PocketExpenseFileUpload::where('client_id', $clientId);
            
            // Optional filters
            if ($request->has('status')) {
                $status = $request->input('status');
                if (in_array($status, ['uploaded', 'validating', 'validation_failed', 'processing', 'completed', 'failed'])) {
                    $query->where('status', $status);
                }
            }
            
            if ($request->has('target_user_id')) {
                $query->where('user_id', $request->input('target_user_id'));
            }
            
            if ($request->has('created_by_user_id')) {
                $query->where('created_by_user_id', $request->input('created_by_user_id'));
            }
            
            // Order by most recent first
            $query->orderBy('uploaded_at', 'desc');
            
            // Paginate results
            $uploads = $query->paginate(20);
            
            return response()->json([
                'message' => 'Uploads retrieved successfully',
                'data' => PocketExpenseFileUploadResource::collection($uploads),
                'pagination' => [
                    'current_page' => $uploads->currentPage(),
                    'last_page' => $uploads->lastPage(),
                    'per_page' => $uploads->perPage(),
                    'total' => $uploads->total()
                ]
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Failed to retrieve uploads list', [
                'user_id' => $authUser->id ?? null,
                'client_id' => $clientId ?? null,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'List retrieval failed',
                'message' => 'Unable to retrieve uploads list. Please try again.',
                'code' => 'LIST_RETRIEVAL_ERROR'
            ], 500);
        }
    }

    /**
     * Cancel a pending or processing upload
     * 
     * Allows cancellation of uploads that are still in queue or processing.
     * Cannot cancel completed or failed uploads.
     * 
     * @param int $uploadId Upload ID to cancel
     * @return JsonResponse Cancellation result
     */
    public function cancelUpload(int $uploadId): JsonResponse
    {
        try {
            $authUser = Auth::user();
            $clientId = $authUser->client_id ?? 1;
            
            $upload = PocketExpenseFileUpload::where('id', $uploadId)
                ->where('client_id', $clientId)
                ->first();
            
            if (!$upload) {
                return response()->json([
                    'error' => 'Upload not found',
                    'message' => 'The specified upload record could not be found',
                    'code' => 'UPLOAD_NOT_FOUND'
                ], 404);
            }
            
            // Check if upload can be cancelled
            if (!in_array($upload->status, ['uploaded', 'validating', 'processing'])) {
                return response()->json([
                    'error' => 'Cannot cancel upload',
                    'message' => 'This upload cannot be cancelled in its current status',
                    'code' => 'UPLOAD_CANCELLATION_NOT_ALLOWED',
                    'current_status' => $upload->status
                ], 422);
            }
            
            // Update status to failed (cancelled)
            $upload->update([
                'status' => 'failed',
                'processed_at' => Carbon::now()
            ]);
            
            // Clean up stored file
            if (Storage::disk('local')->exists($upload->file_path)) {
                Storage::disk('local')->delete($upload->file_path);
            }
            
            // Clean up any pending upload data
            DB::table('pocket_expense_uploads_data')
                ->where('upload_id', $upload->id)
                ->where('status', 'pending')
                ->delete();
            
            Log::info('Upload cancelled successfully', [
                'upload_id' => $uploadId,
                'cancelled_by' => $authUser->id
            ]);
            
            return response()->json([
                'message' => 'Upload cancelled successfully',
                'data' => new PocketExpenseFileUploadResource($upload)
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Failed to cancel upload', [
                'upload_id' => $uploadId,
                'user_id' => $authUser->id ?? null,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'error' => 'Cancellation failed',
                'message' => 'Unable to cancel upload. Please try again.',
                'code' => 'UPLOAD_CANCELLATION_ERROR'
            ], 500);
        }
    }

    /**
     * Download CSV template for expense uploads
     * 
     * Returns a CSV template file with proper headers and example data
     * to help users format their expense data correctly.
     * 
     * @return \Illuminate\Http\Response CSV template file download
     */
    public function downloadTemplate(): \Illuminate\Http\Response
    {
        try {
            // CSV headers as per validation constraints
            $headers = [
                'Date',                    // DD-MM-YYYY format
                'Merchant Name',           // VARCHAR(180) max
                'Merchant Description',    // Optional
                'Expense Type',           // ATM Withdrawal, Point of Sale, Fee & Charges, Refund from Merchant
                'Currency',               // 3-letter ISO code
                'Amount',                 // Numeric, sign determined by expense type
                'Merchant Address',       // Optional
                'VAT %',                  // 0-100 numeric (% sign will be stripped)
                'Notes',                  // Optional, will be trimmed
                'Category',               // Optional
                'Source',                 // Cash, Corporate Card, Personal Card, Other, or client-specific
                'Source Note',            // Required when Source = Other
                'Tracking Code Type 1',   // Optional
                'Tracking Code Type 2',   // Optional
                'Project',                // Optional
                'Additional Field'        // Optional
            ];

            // Example data row
            $exampleData = [
                '15-12-2023',
                'Coffee Shop Ltd',
                'Client meeting refreshments',
                'Point of Sale',
                'GBP',
                '25.50',
                '123 High Street, London',
                '20',
                'Meeting with potential client',
                'Business Entertainment',
                'Corporate Card',
                '',
                'Marketing',
                '',
                'Client Acquisition Project',
                'Meeting Purpose: New Business'
            ];

            // Create CSV content
            $csvContent = '';
            
            // Add header row
            $csvContent .= '"' . implode('","', $headers) . '"' . "\n";
            
            // Add example data row
            $csvContent .= '"' . implode('","', $exampleData) . '"' . "\n";
            
            // Add instruction comments (will be ignored by validation)
            $csvContent .= '# Instructions:' . "\n";
            $csvContent .= '# - Date format must be DD-MM-YYYY' . "\n";
            $csvContent .= '# - Currency must be 3-letter code (GBP, EUR, USD, etc.)' . "\n";
            $csvContent .= '# - Expense Type determines amount sign (Refund = positive, others = negative)' . "\n";
            $csvContent .= '# - VAT % should be numeric only (20, not 20%)' . "\n";
            $csvContent .= '# - Source Note is required when Source = Other' . "\n";
            $csvContent .= '# - Maximum 200 rows per file, 10MB file size limit' . "\n";
            $csvContent .= '# - Delete this example row and instruction comments before uploading' . "\n";

            $fileName = 'pocket_expense_upload_template_' . Carbon::now()->format('Y-m-d') . '.csv';

            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            Log::error('Failed to generate CSV template', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Template generation failed',
                'message' => 'Unable to generate CSV template. Please try again.',
                'code' => 'TEMPLATE_GENERATION_ERROR'
            ], 500);
        }
    }
}