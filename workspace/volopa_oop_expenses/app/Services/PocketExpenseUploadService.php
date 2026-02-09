## Code: app/Services/PocketExpenseUploadService.php

```php
<?php

namespace App\Services;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\User;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessPocketExpenseUploadJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use League\Csv\Reader;
use League\Csv\Statement;

class PocketExpenseUploadService
{
    /**
     * Default storage disk for file uploads.
     */
    private const DEFAULT_STORAGE_DISK = 'local';

    /**
     * Default storage path for CSV uploads.
     */
    private const DEFAULT_STORAGE_PATH = 'pocket-expense-uploads';

    /**
     * Batch size for processing upload data records.
     */
    private const PROCESSING_BATCH_SIZE = 100;

    /**
     * Maximum file size for synchronous processing (1MB).
     */
    private const SYNC_PROCESSING_THRESHOLD = 1048576;

    /**
     * CSV file encoding.
     */
    private const CSV_ENCODING = 'UTF-8';

    /**
     * CSV delimiter character.
     */
    private const CSV_DELIMITER = ',';

    /**
     * CSV enclosure character.
     */
    private const CSV_ENCLOSURE = '"';

    /**
     * CSV escape character.
     */
    private const CSV_ESCAPE = '\\';

    /**
     * Storage disk instance.
     */
    private string $storageDisk;

    /**
     * Storage path for uploads.
     */
    private string $storagePath;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->storageDisk = config('filesystems.pocket_expense_disk', self::DEFAULT_STORAGE_DISK);
        $this->storagePath = config('filesystems.pocket_expense_path', self::DEFAULT_STORAGE_PATH);
    }

    /**
     * Process CSV upload file.
     */
    public function processUpload(UploadedFile $file, User $user, int $expenseUserId, int $clientId): PocketExpenseFileUpload
    {
        return DB::transaction(function () use ($file, $user, $expenseUserId, $clientId) {
            try {
                // Store the uploaded file
                $storedFilePath = $this->storeUploadedFile($file);

                // Create initial upload record
                $uploadRecord = $this->createUploadRecord([
                    'user_id' => $user->id,
                    'client_id' => $clientId,
                    'expense_user_id' => $expenseUserId,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_filename' => basename($storedFilePath),
                    'file_path' => $storedFilePath,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'status' => PocketExpenseFileUpload::STATUS_VALIDATING,
                ]);

                // Validate CSV structure and content
                $validationResult = $this->validateCsvStructure($storedFilePath);

                if (!$validationResult['success']) {
                    // Mark upload as failed and store errors
                    $uploadRecord->markAsFailed('CSV validation failed');
                    $uploadRecord->addValidationErrors($validationResult['errors']);
                    
                    return $uploadRecord;
                }

                // Update record counts
                $uploadRecord->updateRecordCounts(
                    $validationResult['total_rows'],
                    $validationResult['valid_rows'],
                    $validationResult['total_rows'] - $validationResult['valid_rows']
                );

                // Create upload data records for each row
                $this->createUploadDataRecords($uploadRecord, $storedFilePath, $validationResult);

                // Determine processing method based on file size
                if ($this->shouldProcessAsync($file)) {
                    // Queue for background processing
                    ProcessPocketExpenseUploadJob::dispatch($uploadRecord->id)
                        ->delay(now()->addSeconds(5));
                    
                    $uploadRecord->markAsProcessing();
                } else {
                    // Process synchronously for small files
                    $this->processSynchronously($uploadRecord);
                }

                Log::info('Upload processed successfully', [
                    'upload_id' => $uploadRecord->id,
                    'user_id' => $user->id,
                    'expense_user_id' => $expenseUserId,
                    'client_id' => $clientId,
                    'filename' => $file->getClientOriginalName(),
                    'total_rows' => $validationResult['total_rows'],
                    'valid_rows' => $validationResult['valid_rows'],
                    'processing_method' => $this->shouldProcessAsync($file) ? 'async' : 'sync',
                ]);

                return $uploadRecord;

            } catch (\Exception $e) {
                Log::error('Error processing upload', [
                    'user_id' => $user->id,
                    'expense_user_id' => $expenseUserId,
                    'client_id' => $clientId,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Validate CSV file structure and content.
     */
    public function validateCsvStructure(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'errors' => [
                        [
                            'line_number' => 0,
                            'field' => 'file',
                            'error' => 'File not found',
                            'value' => $filePath,
                        ],
                    ],
                ];
            }

            // Use League CSV to read the file
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(self::CSV_DELIMITER);
            $csv->setEnclosure(self::CSV_ENCLOSURE);
            $csv->setEscape(self::CSV_ESCAPE);

            // Get headers
            $headers = $csv->getHeader();
            
            if (empty($headers)) {
                return [
                    'success' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'errors' => [
                        [
                            'line_number' => 1,
                            'field' => 'headers',
                            'error' => 'No header row found',
                            'value' => '',
                        ],
                    ],
                ];
            }

            // Validate required headers
            $requiredHeaders = [
                'Date',
                'Expense Type',
                'Currency Code',
                'Amount',
                'Merchant Name',
            ];

            $missingHeaders = array_diff($requiredHeaders, $headers);
            if (!empty($missingHeaders)) {
                return [
                    'success' => false,
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'errors' => [
                        [
                            'line_number' => 1,
                            'field' => 'headers',
                            'error' => 'Missing required headers: ' . implode(', ', $missingHeaders),
                            'value' => implode(', ', $headers),
                        ],
                    ],
                ];
            }

            // Get records and count them
            $statement = Statement::create();
            $records = $statement->process($csv);
            
            $totalRows = 0;
            $validRows = 0;
            $allErrors = [];

            foreach ($records as $lineNumber => $record) {
                $actualLineNumber = $lineNumber + 2; // Account for header row and 0-based index
                
                // Skip empty rows
                if (array_filter($record, function($value) { return trim($value) !== ''; }) === []) {
                    continue;
                }

                $totalRows++;

                // Basic validation for required fields
                $rowErrors = [];
                
                foreach ($requiredHeaders as $requiredHeader) {
                    if (empty(trim($record[$requiredHeader] ?? ''))) {
                        $rowErrors[] = [
                            'line_number' => $actualLineNumber,
                            'field' => $requiredHeader,
                            'error' => $requiredHeader . ' is required',
                            'value' => $record[$requiredHeader] ?? '',
                        ];
                    }
                }

                if (empty($rowErrors)) {
                    $validRows++;
                } else {
                    $allErrors = array_merge($allErrors, $rowErrors);
                }
            }

            $success = empty($allErrors);

            Log::info('CSV structure validation completed', [
                'file_path' => $filePath,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'error_count' => count($allErrors),
                'success' => $success,
            ]);

            return [
                'success' => $success,
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'errors' => $allErrors,
            ];

        } catch (\Exception $e) {
            Log::error('CSV structure validation error', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'errors' => [
                    [
                        'line_number' => 0,
                        'field' => 'system',
                        'error' => 'System error during validation: ' . $e->getMessage(),
                        'value' => '',
                    ],
                ],
            ];
        }
    }

    /**
     * Create upload record in database.
     */
    public function createUploadRecord(array $data): PocketExpenseFileUpload
    {
        try {
            $uploadData = array_merge([
                'status' => PocketExpenseFileUpload::STATUS_UPLOADING,
                'total_records' => 0,
                'valid_records' => 0,
                'invalid_records' => 0,
                'processed_records' => 0,
                'started_at' => now(),
            ], $data);

            $uploadRecord = PocketExpenseFileUpload::create($uploadData);

            Log::info('Upload record created', [
                'upload_id' => $uploadRecord->id,
                'user_id' => $uploadRecord->user_id,
                'client_id' => $uploadRecord->client_id,
                'expense_user_id' => $uploadRecord->expense_user_id,
                'filename' => $uploadRecord->original_filename,
            ]);

            return $uploadRecord;

        } catch (\Exception $e) {
            Log::error('Error creating upload record', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Store uploaded file to disk.
     */
    private function storeUploadedFile(UploadedFile $file): string
    {
        try {
            $filename = $this->generateStorageFilename($file);
            $directory = $this->storagePath . '/' . date('Y/m/d');
            
            $storedPath = $file->storeAs(
                $directory,
                $filename,
                $this->storageDisk
            );

            if (!$storedPath) {
                throw new \RuntimeException('Failed to store uploaded file');
            }

            Log::info('File stored successfully', [
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $file->getSize(),
            ]);

            return $storedPath;

        } catch (\Exception $e) {
            Log::error('Error storing uploaded file', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate unique filename for storage.
     */
    private function generateStorageFilename(UploadedFile $file): string
    {
        $timestamp = now()->format('YmdHis');
        $uuid = Str::uuid()->toString();
        $extension = $file->getClientOriginalExtension();
        
        return "pocket_expense_{$timestamp}_{$uuid}.{$extension}";
    }

    /**
     * Create upload data records for each CSV row.
     */
    private function createUploadDataRecords(PocketExpenseFileUpload $uploadRecord, string $filePath, array $validationResult): void
    {
        try {
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(self::CSV_DELIMITER);
            $csv->setEnclosure(self::CSV_ENCLOSURE);
            $csv->setEscape(self::CSV_ESCAPE);

            $statement = Statement::create();
            $records = $statement->process($csv);
            
            $batchData = [];
            $rowNumber = 1;

            foreach ($records as $record) {
                // Skip empty rows
                if (array_filter($record, function($value) { return trim($value) !== ''; }) === []) {
                    continue;
                }

                $batchData[] = [
                    'file_upload_id' => $uploadRecord->id,
                    'row_number' => $rowNumber,
                    'original_data' => $record,
                    'row_status' => PocketExpenseUploadsData::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $rowNumber++;

                // Insert in batches
                if (count($batchData) >= self::PROCESSING_BATCH_SIZE) {
                    $this->insertUploadDataBatch($batchData);
                    $batchData = [];
                }
            }

            // Insert remaining records
            if (!empty($batchData)) {
                $this->insertUploadDataBatch($batchData);
            }

            Log::info('Upload data records created', [
                'upload_id' => $uploadRecord->id,
                'total_records' => $rowNumber - 1,
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating upload data records', [
                'upload_id' => $uploadRecord->id,
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Insert batch of upload data records.
     */
    private function insertUploadDataBatch(array $batchData): void
    {
        try {
            // Add UUIDs to batch data
            foreach ($batchData as &$record) {
                $record['uuid'] = (string) Str::uuid();
            }
            
            PocketExpenseUploadsData::insert($batchData);

        } catch (\Exception $e) {
            Log::error('Error inserting upload data batch', [
                'batch_size' => count($batchData),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Determine if upload should be processed asynchronously.
     */
    private function shouldProcessAsync