{
  "Development Plan": [
    "app/Services/FileProcessingService.php"
  ],
  "Incremental Change": [
    "app/Services/FileProcessingService.php: Business logic service for file upload processing, CSV validation, queue job dispatching, and status management"
  ]
}

## Code: app/Services/FileProcessingService.php

```php
<?php

namespace App\Services;

use App\Models\PaymentFile;
use App\Models\User;
use App\Jobs\ProcessPaymentFileJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Csv\Reader;
use League\Csv\Statement;
use Exception;
use Carbon\Carbon;

class FileProcessingService
{
    /**
     * ValidationService instance for CSV validation.
     */
    private ValidationService $validationService;

    /**
     * Maximum file size allowed (10MB in bytes).
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum number of records allowed per file.
     */
    private const MAX_RECORDS = 10000;

    /**
     * Supported file MIME types.
     */
    private const SUPPORTED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel'
    ];

    /**
     * Required CSV headers.
     */
    private const REQUIRED_HEADERS = [
        'beneficiary_name',
        'beneficiary_account',
        'amount',
        'currency',
        'settlement_method'
    ];

    /**
     * Optional CSV headers.
     */
    private const OPTIONAL_HEADERS = [
        'payment_purpose',
        'reference'
    ];

    /**
     * File storage disk name.
     */
    private const STORAGE_DISK = 'local';

    /**
     * File storage directory.
     */
    private const STORAGE_DIRECTORY = 'payment-files';

    /**
     * Create a new FileProcessingService instance.
     *
     * @param ValidationService $validationService
     */
    public function __construct(ValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    /**
     * Process file upload and create PaymentFile record.
     *
     * @param UploadedFile $file Uploaded CSV file
     * @param User $user User who uploaded the file
     * @return PaymentFile Created PaymentFile instance
     * @throws Exception If file processing fails
     */
    public function processUpload(UploadedFile $file, User $user): PaymentFile
    {
        Log::info('Starting file upload processing', [
            'user_id' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        try {
            // Validate file before processing
            $this->validateFile($file);

            // Generate unique filename
            $filename = $this->generateUniqueFilename($file);
            
            // Store file
            $storedPath = $this->storeFile($file, $filename);
            
            // Validate CSV structure
            if (!$this->validateCsvStructure($storedPath)) {
                // Clean up stored file on validation failure
                Storage::disk(self::STORAGE_DISK)->delete($storedPath);
                throw new Exception('Invalid CSV file structure');
            }

            // Create PaymentFile record in database transaction
            $paymentFile = DB::transaction(function () use ($file, $user, $filename, $storedPath) {
                $paymentFile = PaymentFile::create([
                    'user_id' => $user->id,
                    'filename' => $filename,
                    'original_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'status' => PaymentFile::STATUS_UPLOADED,
                    'total_records' => 0,
                    'valid_records' => 0,
                    'invalid_records' => 0,
                    'total_amount' => 0.00,
                    'currency' => PaymentFile::CURRENCY_USD, // Default, will be updated during processing
                ]);

                Log::info('PaymentFile record created', [
                    'payment_file_id' => $paymentFile->id,
                    'filename' => $filename,
                    'stored_path' => $storedPath,
                ]);

                return $paymentFile;
            });

            // Queue processing job
            $this->queueProcessing($paymentFile);

            Log::info('File upload processing completed', [
                'payment_file_id' => $paymentFile->id,
                'user_id' => $user->id,
            ]);

            return $paymentFile;

        } catch (Exception $e) {
            Log::error('File upload processing failed', [
                'user_id' => $user->id,
                'original_filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Validate CSV file structure and headers.
     *
     * @param string $filePath Path to the stored CSV file
     * @return bool True if structure is valid
     * @throws Exception If file cannot be read or parsed
     */
    public function validateCsvStructure(string $filePath): bool
    {
        Log::info('Validating CSV structure', ['file_path' => $filePath]);

        try {
            // Get full storage path
            $fullPath = Storage::disk(self::STORAGE_DISK)->path($filePath);
            
            if (!file_exists($fullPath)) {
                throw new Exception("File not found: {$filePath}");
            }

            // Create CSV reader
            $csv = Reader::createFromPath($fullPath, 'r');
            $csv->setHeaderOffset(0);

            // Get headers
            $headers = $csv->getHeader();
            
            if (empty($headers)) {
                Log::error('CSV file has no headers', ['file_path' => $filePath]);
                return false;
            }

            // Normalize headers for comparison
            $normalizedHeaders = array_map(function($header) {
                return strtolower(trim($header));
            }, $headers);

            $normalizedRequired = array_map('strtolower', self::REQUIRED_HEADERS);

            // Check for required headers
            $missingHeaders = array_diff($normalizedRequired, $normalizedHeaders);
            if (!empty($missingHeaders)) {
                Log::error('CSV file missing required headers', [
                    'file_path' => $filePath,
                    'missing_headers' => $missingHeaders,
                    'found_headers' => $normalizedHeaders,
                ]);
                return false;
            }

            // Check if we have at least one data row
            $statement = Statement::create()->limit(1);
            $records = $statement->process($csv);
            
            if (count($records) === 0) {
                Log::error('CSV file has no data rows', ['file_path' => $filePath]);
                return false;
            }

            // Count total records to check limits
            $totalRecords = count($csv);
            if ($totalRecords > self::MAX_RECORDS) {
                Log::error('CSV file exceeds maximum record limit', [
                    'file_path' => $filePath,
                    'total_records' => $totalRecords,
                    'max_records' => self::MAX_RECORDS,
                ]);
                return false;
            }

            Log::info('CSV structure validation passed', [
                'file_path' => $filePath,
                'headers' => $headers,
                'total_records' => $totalRecords,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Error validating CSV structure', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Queue the payment file for processing.
     *
     * @param PaymentFile $file PaymentFile to process
     * @return void
     */
    public function queueProcessing(PaymentFile $file): void
    {
        Log::info('Queuing payment file for processing', [
            'payment_file_id' => $file->id,
        ]);

        try {
            // Update status to processing
            $this->updateFileStatus($file, PaymentFile::STATUS_PROCESSING);

            // Dispatch processing job
            ProcessPaymentFileJob::dispatch($file)->onQueue('payment-processing');

            Log::info('Payment file queued successfully', [
                'payment_file_id' => $file->id,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to queue payment file processing', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            // Update status to failed
            $this->updateFileStatus($file, PaymentFile::STATUS_FAILED);
            throw $e;
        }
    }

    /**
     * Update payment file status.
     *
     * @param PaymentFile $file PaymentFile to update
     * @param string $status New status
     * @return void
     * @throws Exception If status update fails
     */
    public function updateFileStatus(PaymentFile $file, string $status): void
    {
        Log::info('Updating payment file status', [
            'payment_file_id' => $file->id,
            'old_status' => $file->status,
            'new_status' => $status,
        ]);

        try {
            $file->updateStatus($status);

            Log::info('Payment file status updated', [
                'payment_file_id' => $file->id,
                'status' => $status,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to update payment file status', [
                'payment_file_id' => $file->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the stored file path for a PaymentFile.
     *
     * @param PaymentFile $file PaymentFile instance
     * @return string Full path to stored file
     */
    public function getStoredFilePath(PaymentFile $file): string
    {
        $relativePath = self::STORAGE_DIRECTORY . '/' . $file->filename;
        return Storage::disk(self::STORAGE_DISK)->path($relativePath);
    }

    /**
     * Read CSV data from stored file.
     *
     * @param PaymentFile $file PaymentFile instance
     * @return array Array of CSV records
     * @throws Exception If file cannot be read
     */
    public function readCsvData(PaymentFile $file): array
    {
        Log::info('Reading CSV data', ['payment_file_id' => $file->id]);

        try {
            $filePath = $this->getStoredFilePath($file);
            
            if (!file_exists($filePath)) {
                throw new Exception("Stored file not found for payment file ID {$file->id}");
            }

            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);

            $records = [];
            foreach ($csv as $record) {
                $records[] = $record;
            }

            Log::info('CSV data read successfully', [
                'payment_file_id' => $file->id,
                'record_count' => count($records),
            ]);

            return $records;

        } catch (Exception $e) {
            Log::error('Failed to read CSV data', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete stored file for a PaymentFile.
     *
     * @param PaymentFile $file PaymentFile instance
     * @return bool True if file was deleted or didn't exist
     */
    public function deleteStoredFile(PaymentFile $file): bool
    {
        Log::info('Deleting stored file', ['payment_file_id' => $file->id]);

        try {
            $relativePath = self::STORAGE_DIRECTORY . '/' . $file->filename;
            
            if (Storage::disk(self::STORAGE_DISK)->exists($relativePath)) {
                $deleted = Storage::disk(self::STORAGE_DISK)->delete($relativePath);
                
                Log::info('Stored file deletion result', [
                    'payment_file_id' => $file->id,
                    'deleted' => $deleted,
                ]);
                
                return $deleted;
            } else {
                Log::info('Stored file does not exist', [
                    'payment_file_id' => $file->id,
                    'path' => $relativePath,
                ]);
                return true; // File doesn't exist, consider it "deleted"
            }

        } catch (Exception $e) {
            Log::error('Failed to delete stored file', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get file processing statistics.
     *
     * @param PaymentFile $file PaymentFile instance
     * @return array Statistics array
     */
    public function getProcessingStatistics(PaymentFile $file): array
    {
        return [
            'id' => $file->id,
            'filename' => $file->original_name,
            'status' => $file->status,
            'file_size' => $file->file_size,
            'formatted_file_size' => $file->formatted_file_size,
            'total_records' => $file->total_records,
            'valid_records' => $file->valid_records,
            'invalid_records' => $file->invalid_records,
            'success_rate' => $file->success_rate,
            'total_amount' => $file->total_amount,
            'currency' => $file->currency,
            'created_at' => $file->created_at,
            'updated_at' => $file->updated_at,
            'has_validation_errors' => $file->validationErrors()->exists(),
            'requires_approval' => $file->requiresApproval(),
            'is_processing' => $file->isProcessing(),
            'is_completed' => $file->isCompleted(),
            'has_failed' => $file->hasFailed(),
        ];
    }

    /**
     * Validate uploaded file before processing.
     *
     * @param UploadedFile $file Uploaded file
     * @return void
     * @throws Exception If validation fails
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check if file is valid
        if (!$file->isValid()) {
            throw new Exception('Uploaded file is not valid');
        }

        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new Exception('File size exceeds maximum allowed size');
        }

        // Check MIME type
        if (!in_array($file->getMimeType(), self::SUPPORTED_MIME_TYPES)) {
            throw new Exception('Unsupported file type. Only CSV files are allowed');
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt'])) {
            throw new Exception('Invalid file extension. Only .csv and .txt files are allowed');
        }
    }

    /**
     * Generate unique filename for storage.
     *
     * @param UploadedFile $file Uploaded file
     * @return string Unique filename
     */
    private function generateUniqueFilename(UploadedFile $file): string