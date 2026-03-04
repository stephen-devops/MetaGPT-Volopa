## Code: app/Http/Controllers/Api/V1/PocketExpenseUploadController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Http\Resources\PocketExpenseFileUploadResource;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

/**
 * PocketExpenseUploadController
 * 
 * Controller for CSV file upload operations and batch expense processing.
 * Handles file uploads, validation, and background processing dispatch
 * with multi-tenant support and comprehensive error handling.
 * 
 * Endpoints:
 * - POST /api/uploads/pocket-expense/csv                    (uploadCSV)
 * - GET  /api/uploads/pocket-expense/csv/{upload_id}/status (getUploadStatus)
 */
class PocketExpenseUploadController extends Controller
{
    /**
     * CSV validator service instance.
     *
     * @var PocketExpenseCSVValidator
     */
    private PocketExpenseCSVValidator $csvValidator;

    /**
     * Maximum file size in KB for CSV uploads.
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240; // 10MB

    /**
     * Maximum number of rows allowed per CSV file.
     *
     * @var int
     */
    private const MAX_ROWS_PER_FILE = 200;

    /**
     * Storage path prefix for uploaded files.
     *
     * @var string
     */
    private const STORAGE_PATH_PREFIX = 'pocket-expense-uploads';

    /**
     * Accepted file MIME types.
     *
     * @var array<int, string>
     */
    private const ACCEPTED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/excel',
        'application/vnd.ms-excel',
        'application/vnd.msexcel',
        'text/comma-separated-values',
    ];

    /**
     * Required CSV header columns in exact order.
     *
     * @var array<int, string>
     */
    private const REQUIRED_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'VAT Amount',
        'Merchant Address',
        'Notes',
        'Source',
        'Source Note',
        'Category',
        'Tracking Code',
        'Project',
    ];

    /**
     * CSV date format expected.
     *
     * @var string
     */
    private const CSV_DATE_FORMAT = 'DD/MM/YYYY';

    /**
     * Batch size for processing upload data.
     *
     * @var int
     */
    private const BATCH_INSERT_SIZE = 100;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseCSVValidator $csvValidator
     */
    public function __construct(PocketExpenseCSVValidator $csvValidator)
    {
        $this->csvValidator = $csvValidator;
        
        // Apply OAuth2 middleware for all routes
        $this->middleware('auth:api');
        
        // Apply throttling middleware for uploads
        $this->middleware('throttle:10,1')->only(['uploadCSV']);
        $this->middleware('throttle:60,1')->only(['getUploadStatus']);
    }

    /**
     * Upload CSV file for batch expense processing.
     *
     * POST /api/uploads/pocket-expense/csv
     * 
     * @param UploadPocketExpenseCSVRequest $request
     * @return JsonResponse
     */
    public function uploadCSV(UploadPocketExpenseCSVRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                return $this->errorResponse('Invalid user context', 401);
            }

            $validatedData = $request->validated();
            $uploadedFile = $request->file('file');
            $expenseUserId = (int) $validatedData['expense_user_id'];

            if (!$uploadedFile || !$uploadedFile->isValid()) {
                return $this->errorResponse('Invalid file upload', 400);
            }

            // Check for concurrent uploads
            if ($this->hasActiveUpload($user->id, $user->client_id)) {
                return $this->errorResponse(
                    'You have an active upload in progress. Please wait for it to complete.',
                    409
                );
            }

            // Generate unique filename and store file
            $originalName = $uploadedFile->getClientOriginalName();
            $filename = $this->generateUniqueFilename($originalName);
            $filePath = $uploadedFile->storeAs(
                self::STORAGE_PATH_PREFIX . '/' . $user->client_id,
                $filename,
                'local'
            );

            if (!$filePath) {
                return $this->errorResponse('Failed to store uploaded file', 500);
            }

            // Get full storage path for validation
            $fullFilePath = Storage::disk('local')->path($filePath);

            Log::info('CSV file uploaded for validation', [
                'user_id' => $user->id,
                'client_id' => $user->client_id,
                'expense_user_id' => $expenseUserId,
                'original_filename' => $originalName,
                'stored_filename' => $filename,
                'file_size' => $uploadedFile->getSize(),
            ]);

            DB::beginTransaction();

            try {
                // Create upload record
                $upload = PocketExpenseFileUpload::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $expenseUserId,
                    'client_id' => $user->client_id,
                    'created_by_user_id' => $user->id,
                    'file_name' => $originalName,
                    'file_path' => $filePath,
                    'total_records' => 0,
                    'valid_records' => 0,
                    'validation_errors' => null,
                    'status' => 'uploaded',
                    'uploaded_at' => now(),
                    'validated_at' => null,
                    'processed_at' => null,
                ]);

                // Validate CSV file
                $validationResult = $this->csvValidator->validateCSV(
                    $fullFilePath,
                    $expenseUserId,
                    $user->client_id,
                    $user->id
                );

                // Update upload record with validation results
                $upload->update([
                    'total_records' => $validationResult['total_rows'] ?? 0,
                    'valid_records' => $validationResult['valid_rows'] ?? 0,
                    'validation_errors' => $validationResult['errors'] ?? null,
                    'status' => $validationResult['valid'] ? 'validation_passed' : 'validation_failed',
                    'validated_at' => now(),
                ]);

                if (!$validationResult['valid']) {
                    DB::commit();
                    
                    Log::warning('CSV validation failed', [
                        'upload_id' => $upload->id,
                        'user_id' => $user->id,
                        'client_id' => $user->client_id,
                        'total_errors' => count($validationResult['errors'] ?? []),
                    ]);

                