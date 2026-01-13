## Code: app/Services/MassPaymentFileService.php

```php
<?php

namespace App\Services;

use App\Models\MassPaymentFile;
use App\Models\User;
use App\Models\TccAccount;
use App\Jobs\ValidateMassPaymentFileJob;
use App\Jobs\ProcessPaymentInstructionsJob;
use App\Notifications\ApprovalRequiredNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class MassPaymentFileService
{
    /**
     * The CSV validation service instance.
     */
    private CsvValidationService $csvValidationService;

    /**
     * Maximum file size allowed in bytes (50MB).
     */
    private const MAX_FILE_SIZE = 52428800;

    /**
     * Allowed file extensions for uploads.
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Default file storage disk.
     */
    private const STORAGE_DISK = 'local';

    /**
     * Storage path for mass payment files.
     */
    private const STORAGE_PATH = 'mass_payment_files';

    /**
     * Cache TTL for file processing status in seconds.
     */
    private const CACHE_TTL = 300;

    /**
     * Create a new mass payment file service instance.
     *
     * @param CsvValidationService $csvValidationService
     */
    public function __construct(CsvValidationService $csvValidationService)
    {
        $this->csvValidationService = $csvValidationService;
    }

    /**
     * Create a new mass payment file from uploaded data.
     *
     * @param array $data
     * @param UploadedFile $file
     * @return MassPaymentFile
     * @throws Exception
     */
    public function create(array $data, UploadedFile $file): MassPaymentFile
    {
        Log::info('Creating mass payment file', [
            'client_id' => $data['client_id'],
            'created_by' => $data['created_by'],
            'currency' => $data['currency'],
            'filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize()
        ]);

        try {
            return DB::transaction(function () use ($data, $file) {
                // Validate file basic properties
                $this->validateFileUpload($file);

                // Store the file
                $filePath = $this->storeFile($file, $data['client_id']);

                // Prepare mass payment file data
                $massPaymentFileData = $this->prepareMassPaymentFileData($data, $file, $filePath);

                // Create the mass payment file record
                $massPaymentFile = MassPaymentFile::create($massPaymentFileData);

                // Perform initial file validation
                $initialValidation = $this->performInitialValidation($file);
                
                // Update file with initial validation results
                $this->updateInitialValidationResults($massPaymentFile, $initialValidation);

                // Dispatch async validation job
                $this->dispatchValidationJob($massPaymentFile);

                // Cache the processing status
                $this->cacheProcessingStatus($massPaymentFile->id, 'processing');

                Log::info('Mass payment file created successfully', [
                    'file_id' => $massPaymentFile->id,
                    'client_id' => $massPaymentFile->client_id,
                    'status' => $massPaymentFile->status
                ]);

                return $massPaymentFile;
            });
        } catch (Exception $e) {
            Log::error('Failed to create mass payment file', [
                'client_id' => $data['client_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Clean up stored file if it exists
            if (isset($filePath)) {
                $this->cleanupStoredFile($filePath);
            }

            throw $e;
        }
    }

    /**
     * Approve a mass payment file.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param User $user
     * @return bool
     * @throws Exception
     */
    public function approve(MassPaymentFile $massPaymentFile, User $user): bool
    {
        Log::info('Approving mass payment file', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'approved_by' => $user->id,
            'current_status' => $massPaymentFile->status
        ]);

        try {
            return DB::transaction(function () use ($massPaymentFile, $user) {
                // Validate file can be approved
                $this->validateApprovalEligibility($massPaymentFile, $user);

                // Mark file as approved
                $approvedAt = Carbon::now();
                $massPaymentFile->markAsApproved($user->id, $approvedAt);

                // Create approval record
                $this->createApprovalRecord($massPaymentFile, $user, $approvedAt);

                // Dispatch payment processing job
                $this->dispatchPaymentProcessingJob($massPaymentFile);

                // Send approval notifications
                $this->sendApprovalNotifications($massPaymentFile, $user);

                // Update cache
                $this->cacheProcessingStatus($massPaymentFile->id, 'approved');

                // Clear related caches
                $this->clearRelatedCaches($massPaymentFile);

                Log::info('Mass payment file approved successfully', [
                    'file_id' => $massPaymentFile->id,
                    'approved_by' => $user->id,
                    'approved_at' => $approvedAt->toISOString()
                ]);

                return true;
            });
        } catch (Exception $e) {
            Log::error('Failed to approve mass payment file', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Process validation for a mass payment file.
     *
     * @param MassPaymentFile $massPaymentFile
     * @return void
     * @throws Exception
     */
    public function processValidation(MassPaymentFile $massPaymentFile): void
    {
        Log::info('Processing validation for mass payment file', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'status' => $massPaymentFile->status
        ]);

        try {
            // Update status to processing
            $massPaymentFile->markAsProcessing();

            // Cache processing status
            $this->cacheProcessingStatus($massPaymentFile->id, 'validating');

            // Perform detailed validation
            $validationResult = $this->performDetailedValidation($massPaymentFile);

            // Update file with validation results
            $this->updateValidationResults($massPaymentFile, $validationResult);

            // Determine next status based on validation
            $this->determinePostValidationStatus($massPaymentFile, $validationResult);

            // Send validation completion notifications
            $this->sendValidationCompletionNotifications($massPaymentFile, $validationResult);

            Log::info('Validation processing completed', [
                'file_id' => $massPaymentFile->id,
                'validation_status' => $validationResult['valid'] ? 'passed' : 'failed',
                'valid_rows' => $validationResult['summary']['valid_rows'] ?? 0,
                'invalid_rows' => $validationResult['summary']['invalid_rows'] ?? 0
            ]);

        } catch (Exception $e) {
            Log::error('Failed to process validation', [
                'file_id' => $massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark file as failed
            $massPaymentFile->markAsFailed('Validation processing failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Delete a mass payment file.
     *
     * @param MassPaymentFile $massPaymentFile
     * @return bool
     * @throws Exception
     */
    public function delete(MassPaymentFile $massPaymentFile): bool
    {
        Log::info('Deleting mass payment file', [
            'file_id' => $massPaymentFile->id,
            'client_id' => $massPaymentFile->client_id,
            'status' => $massPaymentFile->status
        ]);

        try {
            return DB::transaction(function () use ($massPaymentFile) {
                // Validate file can be deleted
                $this->validateDeletionEligibility($massPaymentFile);

                // Store file path for cleanup
                $filePath = $massPaymentFile->file_path;

                // Soft delete the file record
                $massPaymentFile->delete();

                // Clean up stored file
                if ($filePath) {
                    $this->cleanupStoredFile($filePath);
                }

                // Clear related caches
                $this->clearRelatedCaches($massPaymentFile);

                // Clear processing status cache
                $this->clearProcessingStatusCache($massPaymentFile->id);

                Log::info('Mass payment file deleted successfully', [
                    'file_id' => $massPaymentFile->id
                ]);

                return true;
            });
        } catch (Exception $e) {
            Log::error('Failed to delete mass payment file', [
                'file_id' => $massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Get processing status for a mass payment file.
     *
     * @param string $fileId
     * @return array|null
     */
    public function getProcessingStatus(string $fileId): ?array
    {
        $cacheKey = "mass_payment_file_status:{$fileId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($fileId) {
            $file = MassPaymentFile::find($fileId);
            
            if (!$file) {
                return null;
            }

            return [
                'status' => $file->status,
                'total_rows' => $file->total_rows,
                'valid_rows' => $file->valid_rows,
                'invalid_rows' => $file->invalid_rows,
                'total_amount' => $file->total_amount,
                'currency' => $file->currency,
                'validation_progress' => $this->calculateValidationProgress($file),
                'processing_progress' => $this->calculateProcessingProgress($file),
                'last_updated' => $file->updated_at->toISOString()
            ];
        });
    }

    /**
     * Validate file upload requirements.
     *
     * @param UploadedFile $file
     * @return void
     * @throws Exception
     */
    private function validateFileUpload(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new Exception('File size exceeds maximum allowed size of ' . self::MAX_FILE_SIZE . ' bytes');
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new Exception('File extension not allowed. Allowed extensions: ' . implode(', ', self::ALLOWED_EXTENSIONS));
        }

        // Check if file is valid
        if (!$file->isValid()) {
            throw new Exception('Uploaded file is not valid');
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        $allowedMimeTypes = ['text/csv', 'text/plain', 'application/csv'];
        if (!in_array($mimeType, $allowedMimeTypes)) {
            throw new Exception('File MIME type not allowed. File must be a CSV file');
        }
    }

    /**
     * Store uploaded file to disk.
     *
     * @param UploadedFile $file
     * @param string $clientId
     * @return string
     * @throws Exception
     */
    private function storeFile(UploadedFile $file, string $clientId): string
    {
        try {
            // Generate unique filename
            $filename = $this->generateUniqueFilename($file, $clientId);
            
            // Create storage path
            $storagePath = self::STORAGE_PATH . '/' . $clientId . '/' . date('Y/m/d');
            
            // Store file
            $filePath = $file->storeAs($storagePath, $filename, self::STORAGE_DISK);
            
            if (!$filePath) {
                throw new Exception('Failed to store uploaded file');
            }

            return $filePath;
        } catch (Exception $e) {
            Log::error('Failed to store file', [
                'client_id' => $clientId,
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);

            throw new Exception('Failed to store uploaded file: ' . $e->getMessage());
        }
    }

    /**
     * Generate unique filename for stored file.
     *
     * @param UploadedFile $file
     * @param string $clientId
     * @return string
     */
    private function generateUniqueFilename(UploadedFile $file, string $clientId): string
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $nameWithoutExtension = pathinfo($originalName, PATHINFO_FILENAME);
        
        // Sanitize filename
        $sanitizedName = preg_replace('/[^A-Za-z0-9\-_.]/', '_', $nameWithoutExtension);
        $sanitizedName = preg_replace('/_+/', '_', $sanitizedName);
        $sanitizedName = trim($sanitizedName, '_');
        
        // Generate unique identifier
        $uniqueId = Str::uuid()->toString();
        $timestamp = now()->format('Y-m-d_H-i-s');
        
        return "{$clientId}_{$timestamp}_{$sanitizedName}_{$uniqueId}.{$extension}";
    }

    /**
     * Prepare mass payment file data for creation.
     *
     * @param array $data
     * @param UploadedFile $file
     * @param string $filePath
     * @return array
     */
    private function prepareMassPaymentFileData(array $data, UploadedFile $file, string $filePath): array
    {
        return [
            'client_id' => $data['client_id'],
            'tcc_account_id' => $data['tcc_account_id'],
            'filename' => $this->extractFilenameFromPath($filePath),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'status' => MassPaymentFile::STATUS_UPLOADING,
            'currency' => $data['currency'],
            'total_amount' => 0.00,
            'total_rows' => 0,
            'valid_rows' => 0,
            'invalid_rows' => 0,
            'created_by' => $data['created_by'],
            'metadata' => [
                'file_size' => $file->getSize(),
                'mime_type' => $