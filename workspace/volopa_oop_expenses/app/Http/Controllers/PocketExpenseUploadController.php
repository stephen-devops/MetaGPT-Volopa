<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadPocketExpenseCSVRequest;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\User;
use App\Models\Client;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Controller for handling CSV batch upload of pocket expenses.
 * 
 * This controller processes CSV file uploads for batch expense creation.
 * It handles file validation, CSV content validation, and queues background
 * processing jobs for expense creation. Follows the platform constraint of
 * synchronous validation with asynchronous processing.
 *
 * @package App\Http\Controllers
 */
class PocketExpenseUploadController extends Controller
{
    /**
     * CSV validation service instance.
     *
     * @var PocketExpenseCSVValidator
     */
    protected PocketExpenseCSVValidator $csvValidator;

    /**
     * Maximum file size in KB as per system constraints.
     *
     * @var int
     */
    protected int $maxFileSizeKB = 10240; // 10MB

    /**
     * Maximum rows per CSV file as per system constraints.
     *
     * @var int
     */
    protected int $maxRowsPerFile = 200;

    /**
     * Allowed file MIME types for CSV uploads.
     *
     * @var array<string>
     */
    protected array $allowedMimeTypes = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel'
    ];

    /**
     * Create a new controller instance.
     *
     * @param PocketExpenseCSVValidator $csvValidator
     */
    public function __construct(PocketExpenseCSVValidator $csvValidator)
    {
        $this->csvValidator = $csvValidator;
        
        // Apply OAuth2 authentication middleware as per platform standards
        $this->middleware('auth:api');
    }

    /**
     * Upload and process a CSV file containing pocket expenses.
     *
     * This endpoint handles the POST /api/uploads/pocket-expense/csv route.
     * It performs synchronous validation and queues asynchronous processing
     * as per the platform mental model: Client -> route -> controller -> 
     * Form Request -> domain logic -> API Resource -> JSON response.
     *
     * @param UploadPocketExpenseCSVRequest $request
     * @return JsonResponse
     * 
     * @throws \Exception
     */
    public function uploadPocketExpenseCSV(UploadPocketExpenseCSVRequest $request): JsonResponse
    {
        // Log the upload attempt for observability
        Log::info('CSV upload initiated', [
            'user_id' => $request->input('user_id'),
            'expense_user_id' => $request->input('expense_user_id'),
            'client_id' => $request->input('client_id'),
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_size' => $request->file('file')->getSize()
        ]);

        // Use database transaction for atomic operations as per dos_and_donts
        return DB::transaction(function () use ($request) {
            try {
                // Extract validated form data
                $file = $request->file('file');
                $userId = (int) $request->input('user_id');
                $expenseUserId = (int) $request->input('expense_user_id');
                $clientId = (int) $request->input('client_id');

                // Validate authenticated user matches user_id (server-side verification)
                $authenticatedUser = $request->user();
                if ($authenticatedUser->id !== $userId) {
                    Log::warning('Authentication mismatch in CSV upload', [
                        'authenticated_user_id' => $authenticatedUser->id,
                        'request_user_id' => $userId
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized: user_id must match authenticated user',
                        'upload_id' => null,
                        'total_rows' => 0,
                        'error_count' => 1,
                        'errors' => []
                    ], 403);
                }

                // Additional permission checks - user can manage expense_user_id
                if (!$this->canManageUser($userId, $expenseUserId, $clientId)) {
                    Log::warning('Permission denied for user management in CSV upload', [
                        'user_id' => $userId,
                        'expense_user_id' => $expenseUserId,
                        'client_id' => $clientId
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Forbidden: insufficient permissions to manage target user',
                        'upload_id' => null,
                        'total_rows' => 0,
                        'error_count' => 1,
                        'errors' => []
                    ], 403);
                }

                // Verify expense_user_id belongs to client_id (multi-tenancy constraint)
                if (!$this->userBelongsToClient($expenseUserId, $clientId)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid expense_user_id: user does not belong to specified client',
                        'upload_id' => null,
                        'total_rows' => 0,
                        'error_count' => 1,
                        'errors' => []
                    ], 422);
                }

                // Verify client has OOP feature enabled
                if (!$this->clientHasOopFeature($clientId)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'OOP Expenses feature is not enabled for this client',
                        'upload_id' => null,
                        'total_rows' => 0,
                        'error_count' => 1,
                        'errors' => []
                    ], 422);
                }

                // Store the uploaded file securely
                $storagePath = $this->storeUploadedFile($file, $clientId);

                // Create upload tracking record
                $upload = $this->createUploadRecord(
                    $file,
                    $storagePath,
                    $expenseUserId,
                    $clientId,
                    $userId
                );

                // Perform synchronous CSV validation
                $validationResult = $this->csvValidator->validate(
                    $file,
                    $expenseUserId,
                    $clientId,
                    $userId
                );

                // Update upload record with validation results
                $upload->update([
                    'total_records' => $validationResult['total_rows'],
                    'valid_records' => $validationResult['valid_rows'],
                    'validation_errors' => $validationResult['errors'],
                    'status' => $validationResult['success'] ? 'validation_passed' : 'validation_failed',
                    'validated_at' => now()
                ]);

                // If validation failed, return error response
                if (!$validationResult['success']) {
                    Log::info('CSV validation failed', [
                        'upload_id' => $upload->id,
                        'total_errors' => count($validationResult['errors']),
                        'error_count' => $validationResult['error_count']
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'upload_id' => $upload->id,
                        'total_rows' => $validationResult['total_rows'],
                        'error_count' => $validationResult['error_count'],
                        'errors' => $validationResult['errors']
                    ], 422);
                }

                // Store validated CSV data for background processing
                $this->storeValidatedData($upload->id, $validationResult['validated_data']);

                // Queue background processing job
                ProcessExpenseUpload::dispatch($upload->id)->onQueue('default');

                // Update upload status
                $upload->update(['status' => 'processing']);

                Log::info('CSV upload successful, background processing queued', [
                    'upload_id' => $upload->id,
                    'total_rows' => $validationResult['total_rows'],
                    'valid_rows' => $validationResult['valid_rows']
                ]);

                // Return success response as per API specification
                return response()->json([
                    'success' => true,
                    'message' => 'File validated successfully. Expenses are being created.',
                    'upload_id' => $upload->id,
                    'total_rows' => $validationResult['total_rows']
                ], 200);

            } catch (\Exception $e) {
                Log::error('CSV upload processing failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $request->input('user_id', 'unknown'),
                    'client_id' => $request->input('client_id', 'unknown')
                ]);

                // Return generic error to avoid exposing stack traces
                return response()->json([
                    'success' => false,
                    'message' => 'An error occurred while processing the upload. Please try again.',
                    'upload_id' => null,
                    'total_rows' => 0,
                    'error_count' => 1,
                    'errors' => []
                ], 500);
            }
        });
    }

    /**
     * Check if a user can manage another user within a client context.
     *
     * This implements the permission constraint that Admin can only grant
     * access to their own managed users, and Primary Admin has full access.
     *
     * @param int $managerId
     * @param int $targetUserId
     * @param int $clientId
     * @return bool
     */
    protected function canManageUser(int $managerId, int $targetUserId, int $clientId): bool
    {
        // Allow self-management (user uploading expenses for themselves)
        if ($managerId === $targetUserId) {
            return true;
        }

        // TODO: Implement proper permission checking based on UserFeaturePermission
        // For now, allow all authenticated users (will be refined with proper RBAC)
        // This should check:
        // 1. User role (Primary Admin gets full access)
        // 2. UserFeaturePermission records for management rights
        // 3. Client context validation

        return true;
    }

    /**
     * Verify that a user belongs to the specified client.
     *
     * This enforces multi-tenancy constraints at the application level.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    protected function userBelongsToClient(int $userId, int $clientId): bool
    {
        return User::where('id', $userId)
            ->whereHas('clients', function ($query) use ($clientId) {
                $query->where('client_id', $clientId);
            })
            ->exists();
    }

    /**
     * Check if a client has the OOP Expenses feature enabled.
     *
     * According to system constraints, feature_id = 16 represents OOP Expenses.
     *
     * @param int $clientId
     * @return bool
     */
    protected function clientHasOopFeature(int $clientId): bool
    {
        // TODO: Implement proper feature enablement check
        // This should query the ClientFeatures table or equivalent
        // to verify that client has feature_id = 16 (OOP Expenses) enabled
        // For now, assume all clients have the feature enabled
        
        return true;
    }

    /**
     * Store the uploaded file securely in the platform storage.
     *
     * Uses platform storage infrastructure with appropriate security measures.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param int $clientId
     * @return string Storage path
     * 
     * @throws \Exception
     */
    protected function storeUploadedFile($file, int $clientId): string
    {
        // Generate secure file name to prevent path traversal attacks
        $fileName = sprintf(
            'pocket-expense-uploads/%s/%s/%s_%s.csv',
            $clientId,
            date('Y/m'),
            Str::uuid(),
            time()
        );

        // Store file using Laravel's secure storage system
        $storagePath = Storage::disk('local')->putFileAs(
            dirname($fileName),
            $file,
            basename($fileName)
        );

        if (!$storagePath) {
            throw new \Exception('Failed to store uploaded file');
        }

        return $storagePath;
    }

    /**
     * Create a new upload tracking record in the database.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $storagePath
     * @param int $expenseUserId
     * @param int $clientId
     * @param int $createdByUserId
     * @return PocketExpenseFileUpload
     */
    protected function createUploadRecord(
        $file,
        string $storagePath,
        int $expenseUserId,
        int $clientId,
        int $createdByUserId
    ): PocketExpenseFileUpload {
        return PocketExpenseFileUpload::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $expenseUserId, // Target user for expenses
            'client_id' => $clientId,
            'created_by_user_id' => $createdByUserId, // Admin performing upload
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storagePath,
            'total_records' => 0, // Will be updated after validation
            'valid_records' => 0, // Will be updated after validation
            'validation_errors' => null,
            'status' => 'uploaded',
            'uploaded_at' => now(),
            'validated_at' => null,
            'processed_at' => null
        ]);
    }

    /**
     * Store validated CSV row data for background processing.
     *
     * This creates staging records in pocket_expense_uploads_data table
     * that will be processed by the background job.
     *
     * @param int $uploadId
     * @param array $validatedData
     * @return void
     */
    protected function storeValidatedData(int $uploadId, array $validatedData): void
    {
        $stagingRecords = [];
        $now = Carbon::now();

        foreach ($validatedData as $lineNumber => $rowData) {
            $stagingRecords[] = [
                'upload_id' => $uploadId,
                'line_number' => $lineNumber,
                'status' => 'pending',
                'expense_data' => json_encode($rowData),
                'error_message' => null,
                'created_at' => $now,
                'updated_at' => $now
            ];
        }

        // Bulk insert for performance (respecting 200-row limit)
        PocketExpenseUploadsData::insert($stagingRecords);
    }

    /**
     * Validate uploaded file meets basic requirements.
     *
     * This method performs preliminary file validation before CSV content validation.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array Validation result with success boolean and error messages
     */
    protected function validateUploadedFile($file): array
    {
        $errors = [];

        // Check file size constraint (max 10MB)
        if ($file->getSize() > ($this->maxFileSizeKB * 1024)) {
            $errors[] = sprintf(
                'File size exceeds maximum limit of %d MB',
                $this->maxFileSizeKB / 1024
            );
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            $errors[] = sprintf(
                'Invalid file type. Allowed types: %s',
                implode(', ', $this->allowedMimeTypes)
            );
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt'])) {
            $errors[] = 'Invalid file extension. Only CSV and TXT files are allowed.';
        }

        return [
            'success' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get the appropriate HTTP status code for error responses.
     *
     * Maps different error types to proper HTTP status codes as per
     * platform standards and dos_and_donts guidelines.
     *
     * @param string $errorType
     * @return int
     */
    protected function getErrorStatusCode(string $errorType): int
    {
        return match ($errorType) {
            'authentication' => 401,
            'authorization', 'permission' => 403,
            'validation', 'client_error' => 422,
            'not_found' => 404,
            'server_error' => 500,
            default => 400, // Bad Request
        };
    }

    /**
     * Sanitize error messages to prevent information disclosure.
     *
     * Removes sensitive information from error messages while preserving
     * useful debugging information for legitimate users.
     *
     * @param array $errors
     * @return array
     */
    protected function sanitizeErrorMessages(array $errors): array
    {
        return array_map(function ($error) {
            // Remove sensitive patterns that could expose system internals
            $sanitized = preg_replace('/\/[a-zA-Z0-9\/\-_\.]+\//', '[PATH]/', $error);
            $sanitized = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP]', $sanitized);
            $sanitized = preg_replace('/password|secret|key|token/i', '[REDACTED]', $sanitized);
            
            return $sanitized;
        }, $errors);
    }

    /**
     * Check if the current request has reached rate limiting thresholds.
     *
     * Implements protection against abuse while allowing legitimate usage patterns.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    protected function isRateLimited(int $userId, int $clientId): bool
    {
        // TODO: Implement rate limiting logic
        // Consider factors like:
        // - Number of uploads per user per hour/day
        // - Total file size uploaded per client per day
        // - Failed upload attempts (possible abuse detection)
        // - Client-specific rate limits based on subscription tier

        return false;
    }

    /**
     * Clean up temporary files and resources on error.
     *
     * Ensures proper cleanup even when upload processing fails.
     *
     * @param string|null $storagePath
     * @return void
     */
    protected function cleanupOnError(?string $storagePath): void
    {
        if ($storagePath && Storage::disk('local')->exists($storagePath)) {
            try {
                Storage::disk('local')->delete($storagePath);
                Log::info('Cleaned up uploaded file after error', ['path' => $storagePath]);
            } catch (\Exception $e) {
                Log::warning('Failed to cleanup uploaded file', [
                    'path' => $storagePath,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Generate audit log entry for upload events.
     *
     * Provides comprehensive logging for compliance and debugging purposes.
     *
     * @param string $event
     * @param array $data
     * @return void
     */
    protected function logUploadEvent(string $event, array $data): void
    {
        Log::info("CSV Upload: {$event}", array_merge($data, [
            'timestamp' => now()->toISOString(),
            'user_agent' => request()->header('User-Agent'),
            'ip_address' => request()->ip(),
            'session_id' => session()->getId()
        ]));
    }
}