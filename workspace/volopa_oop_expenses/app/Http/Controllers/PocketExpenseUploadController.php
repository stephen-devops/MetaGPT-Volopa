## Code: app/Http/Controllers/PocketExpenseUploadController.php

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Http\Resources\PocketExpenseFileUploadResource;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadData;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;
use Carbon\Carbon;

class PocketExpenseUploadController extends Controller
{
    /**
     * The CSV validator service instance.
     *
     * @var PocketExpenseCSVValidator
     */
    private PocketExpenseCSVValidator $csvValidator;

    /**
     * Maximum file size in KB (10MB).
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum CSV rows allowed per file.
     *
     * @var int
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Allowed file extensions.
     *
     * @var array<string>
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * CSV template headers for download.
     *
     * @var array<string>
     */
    private const CSV_TEMPLATE_HEADERS = [
        'Date',
        'Merchant Name', 
        'Merchant Description',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Address',
        'VAT %',
        'Source',
        'Source Note',
        'Notes'
    ];

    /**
     * CSV template sample data.
     *
     * @var array<array<string>>
     */
    private const CSV_TEMPLATE_SAMPLE_DATA = [
        [
            '01-01-2024',
            'Sample Restaurant',
            'Business lunch meeting',
            'Meal & Entertainment',
            'USD',
            '45.50',
            '123 Main St, New York, NY',
            '8.5',
            'Corporate Card',
            '',
            'Client meeting expenses'
        ],
        [
            '02-01-2024',
            'Office Supplies Store',
            'Monthly stationery purchase',
            'Office Supplies',
            'USD',
            '125.75',
            '456 Business Ave, New York, NY',
            '10',
            'Cash',
            '',
            'Stationery and office materials'
        ]
    ];

    /**
     * Upload storage disk name.
     *
     * @var string
     */
    private const UPLOAD_DISK = 'local';

    /**
     * Upload directory path.
     *
     * @var string
     */
    private const UPLOAD_PATH = 'pocket-expense-uploads';

    /**
     * Default pagination limit for upload status queries.
     *
     * @var int
     */
    private const DEFAULT_PAGINATION_LIMIT = 50;

    /**
     * Maximum pagination limit allowed.
     *
     * @var int
     */
    private const MAX_PAGINATION_LIMIT = 200;

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseCSVValidator $csvValidator
     */
    public function __construct(PocketExpenseCSVValidator $csvValidator)
    {
        $this->csvValidator = $csvValidator;
        
        // Apply auth middleware to all routes
        $this->middleware('auth:api');
        
        // Apply throttle middleware for API rate limiting
        $this->middleware('throttle:api')->only(['uploadPocketExpenseCSV']);
    }

    /**
     * Upload CSV file for batch pocket expense creation.
     *
     * @param UploadPocketExpenseCSVRequest $request
     * @return JsonResponse
     */
    public function uploadPocketExpenseCSV(UploadPocketExpenseCSVRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access',
                    'error_code' => 'AUTH_REQUIRED'
                ], 401);
            }

            // Get validated data
            $validated = $request->validated();
            $file = $request->file('file');
            $clientId = $validated['client_id'];
            $expenseUserId = $validated['expense_user_id'];

            Log::info('CSV upload initiated', [
                'user_id' => $user->id,
                'client_id' => $clientId,
                'expense_user_id' => $expenseUserId,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize()
            ]);

            // Validate file constraints
            $fileValidation = $this->validateFile($file, $user->id, $expenseUserId, $clientId);
            if (!$fileValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'File validation failed',
                    'errors' => $fileValidation['errors'],
                    'error_code' => 'FILE_VALIDATION_ERROR'
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Store the file
                $fileName = $this->generateUniqueFileName($file);
                $filePath = $file->storeAs(
                    self::UPLOAD_PATH . '/' . $clientId,
                    $fileName,
                    self::UPLOAD_DISK
                );

                if (!$filePath) {
                    throw new Exception('Failed to store uploaded file');
                }

                $fullFilePath = Storage::disk(self::UPLOAD_DISK)->path($filePath);

                // Create upload record
                $upload = PocketExpenseFileUpload::create([
                    'uuid' => Str::uuid()->toString(),
                    'user_id' => $expenseUserId,
                    'client_id' => $clientId,
                    'created_by_user_id' => $user->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $filePath,
                    'total_records' => 0,
                    'valid_records' => 0,
                    'validation_errors' => null,
                    'status' => 'uploaded',
                    'uploaded_at' => now(),
                ]);

                Log::info('Upload record created', [
                    'upload_id' => $upload->id,
                    'file_path' => $filePath
                ]);

                // Validate CSV content
                $validationResult = $this->csvValidator->validate(
                    $fullFilePath,
                    $expenseUserId,
                    $clientId,
                    $user->id
                );

                // Update upload record with validation results
                $upload->update([
                    'total_records' => $validationResult['total_rows'],
                    'valid_records' => $validationResult['valid_rows'],
                    'validation_errors' => $validationResult['errors'] ?: null,
                    'validated_at' => now(),
                ]);

                if (!$validationResult['valid']) {
                    // Validation failed
                    $upload->update(['status' => 'validation_failed']);

                    DB::commit();

                    Log::warning('CSV validation failed', [
                        'upload_id' => $upload->id,
                        'total_rows' => $validationResult['total_rows'],
                        'error_count