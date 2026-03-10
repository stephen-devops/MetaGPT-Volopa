<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\User;
use App\Services\PocketExpenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * ProcessExpenseUpload Job
 * 
 * Queue job for processing validated CSV expense upload data in batches.
 * Handles the conversion of validated upload data into actual expense records
 * with metadata, manages the processing workflow, and updates upload status.
 * Implements batch processing for performance optimization and error handling
 * for partial failures while maintaining data consistency.
 * 
 * Key responsibilities:
 * - Process validated upload data in configurable batches
 * - Create expense records with associated metadata
 * - Handle mapping from CSV data to expense model structure
 * - Manage upload status transitions and error reporting
 * - Support transaction rollback for batch failures
 * - Provide comprehensive logging and progress tracking
 * - Handle expense type mapping and source configuration
 * - Support multi-tenant processing with proper scoping
 */
class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload record to process
     *
     * @var PocketExpenseFileUpload
     */
    protected PocketExpenseFileUpload $upload;

    /**
     * The pocket expense service instance
     *
     * @var PocketExpenseService
     */
    protected PocketExpenseService $expenseService;

    /**
     * Batch size for processing expenses
     *
     * @var int
     */
    private const BATCH_SIZE = 50;

    /**
     * Maximum retry attempts for failed batches
     *
     * @var int
     */
    private const MAX_BATCH_RETRIES = 3;

    /**
     * Default expense status for imported expenses
     *
     * @var string
     */
    private const DEFAULT_IMPORT_STATUS = 'draft';

    /**
     * Maximum processing time in seconds before timeout
     *
     * @var int
     */
    private const MAX_PROCESSING_TIME = 1800; // 30 minutes

    /**
     * Memory limit threshold in MB to trigger batch size reduction
     *
     * @var int
     */
    private const MEMORY_THRESHOLD_MB = 256;

    /**
     * Number of times the job may be attempted
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out
     *
     * @var int
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * Reference data cache for mapping validation
     *
     * @var array<string, mixed>
     */
    private array $referenceDataCache = [];

    /**
     * Processing statistics
     *
     * @var array<string, int>
     */
    private array $processingStats = [
        'total_records' => 0,
        'processed_records' => 0,
        'successful_records' => 0,
        'failed_records' => 0,
        'batches_processed' => 0,
        'batches_failed' => 0
    ];

    /**
     * Processing errors collection
     *
     * @var array<int, array<string, mixed>>
     */
    private array $processingErrors = [];

    /**
     * Create a new job instance.
     *
     * @param PocketExpenseFileUpload $upload
     */
    public function __construct(PocketExpenseFileUpload $upload)
    {
        $this->upload = $upload;
        
        // Set queue configuration
        $this->onQueue('expense-processing');
        $this->onConnection('database');
        
        // Initialize processing stats
        $this->processingStats['total_records'] = $upload->total_records ?? 0;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Exception
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        try {
            // Initialize dependencies
            $this->expenseService = app(PocketExpenseService::class);
            
            Log::info('Starting expense upload processing', [
                'upload_id' => $this->upload->id,
                'upload_uuid' => $this->upload->uuid,
                'client_id' => $this->upload->client_id,
                'user_id' => $this->upload->user_id,
                'total_records' => $this->upload->total_records,
                'valid_records' => $this->upload->valid_records
            ]);

            // Update upload status to processing
            $this->updateUploadStatus(PocketExpenseFileUpload::STATUS_PROCESSING);

            // Preload reference data for efficient processing
            $this->preloadReferenceData();

            // Get validated upload data records ready for processing
            $uploadDataRecords = $this->getReadyUploadData();

            if ($uploadDataRecords->isEmpty()) {
                Log::warning('No valid records found for processing', [
                    'upload_id' => $this->upload->id
                ]);
                $this->updateUploadStatus(PocketExpenseFileUpload::STATUS_COMPLETED);
                return;
            }

            // Process expenses in batches
            $this->processExpensesInBatches($uploadDataRecords);

            // Update final statistics
            $this->updateFinalStatistics();

            // Determine final status based on processing results
            $finalStatus = $this->determineFinalStatus();
            $this->updateUploadStatus($finalStatus);

            $processingTime = microtime(true) - $startTime;

            Log::info('Expense upload processing completed', [
                'upload_id' => $this->upload->id,
                'final_status' => $finalStatus,
                'processing_time' => round($processingTime, 2),
                'stats' => $this->processingStats,
                'error_count' => count($this->processingErrors)
            ]);

        } catch (\Exception $e) {
            $this->handleProcessingFailure($e, $startTime);
            throw $e;
        }
    }

    /**
     * Handle job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Expense upload processing job failed', [
            'upload_id' => $this->upload->id,
            'upload_uuid' => $this->upload->uuid,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'stats' => $this->processingStats
        ]);

        // Update upload status to failed
        try {
            $this->updateUploadStatus(PocketExpenseFileUpload::STATUS_FAILED, [
                'error' => $exception->getMessage(),
                'failed_at' => now()->toISOString(),
                'stats' => $this->processingStats
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update upload status after job failure', [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get upload data records ready for processing.
     *
     * @return Collection<int, PocketExpenseUploadsData>
     */
    private function getReadyUploadData(): Collection
    {
        return PocketExpenseUploadsData::where('upload_id', $this->upload->id)
            ->where('status', PocketExpenseUploadsData::STATUS_VALID)
            ->orderBy('line_number')
            ->get();
    }

    /**
     * Process expenses in batches for performance optimization.
     *
     * @param Collection<int, PocketExpenseUploadsData> $uploadDataRecords
     * @return void
     */
    private function processExpensesInBatches(Collection $uploadDataRecords): void
    {
        $batchSize = $this->calculateOptimalBatchSize();
        $totalRecords = $uploadDataRecords->count();
        $batchNumber = 1;

        Log::info('Starting batch processing', [
            'upload_id' => $this->upload->id,
            'total_records' => $totalRecords,
            'batch_size' => $batchSize,
            'estimated_batches' => ceil($totalRecords / $batchSize)
        ]);

        $uploadDataRecords->chunk($batchSize)->each(function ($batch) use (&$batchNumber, $totalRecords, $batchSize) {
            $batchStartTime = microtime(true);
            
            try {
                Log::info("Processing batch {$batchNumber}", [
                    'upload_id' => $this->upload->id,
                    'batch_number' => $batchNumber,
                    'batch_size' => $batch->count(),
                    'records_processed' => $this->processingStats['processed_records'],
                    'total_records' => $totalRecords
                ]);

                $this->processBatch($batch);
                $this->processingStats['batches_processed']++;

                $batchTime = microtime(true) - $batchStartTime;
                
                Log::info("Batch {$batchNumber} completed successfully", [
                    'upload_id' => $this->upload->id,
                    'batch_number' => $batchNumber,
                    'batch_time' => round($batchTime, 2),
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
                ]);

            } catch (\Exception $e) {
                $this->handleBatchFailure($batchNumber, $batch, $e);
                $this->processingStats['batches_failed']++;
            }

            $batchNumber++;

            // Check memory usage and adjust batch size if needed
            $this->checkMemoryUsage();
        });
    }

    /**
     * Process a single batch of upload data records.
     *
     * @param Collection<int, PocketExpenseUploadsData> $batch
     * @return void
     * @throws \Exception
     */
    private function processBatch(Collection $batch): void
    {
        DB::beginTransaction();

        try {
            foreach ($batch as $uploadDataRecord) {
                $this->processingStats['processed_records']++;
                
                try {
                    $this->processUploadDataRecord($uploadDataRecord);
                    $uploadDataRecord->markAsProcessed();
                    $this->processingStats['successful_records']++;
                    
                } catch (\Exception $e) {
                    $this->handleRecordProcessingFailure($uploadDataRecord, $e);
                    $uploadDataRecord->markAsFailed(['processing_error' => $e->getMessage()]);
                    $this->processingStats['failed_records']++;
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Mark all records in batch as failed
            foreach ($batch as $uploadDataRecord) {
                try {
                    $uploadDataRecord->markAsFailed(['batch_error' => $e->getMessage()]);
                } catch (\Exception $markFailedException) {
                    Log::error('Failed to mark upload data record as failed', [
                        'upload_data_id' => $uploadDataRecord->id,
                        'error' => $markFailedException->getMessage()
                    ]);
                }
            }
            
            throw $e;
        }
    }

    /**
     * Process a single upload data record into expense.
     *
     * @param PocketExpenseUploadsData $uploadDataRecord
     * @return PocketExpense
     * @throws \Exception
     */
    private function processUploadDataRecord(PocketExpenseUploadsData $uploadDataRecord): PocketExpense
    {
        $expenseData = $this->mapUploadDataToExpenseData($uploadDataRecord);
        
        // Create the expense using the service
        $expense = $this->expenseService->createExpense($expenseData);
        
        Log::debug('Expense created from upload data', [
            'upload_id' => $this->upload->id,
            'upload_data_id' => $uploadDataRecord->id,
            'expense_id' => $expense->id,
            'expense_uuid' => $expense->uuid,
            'line_number' => $uploadDataRecord->line_number
        ]);

        return $expense;
    }

    /**
     * Map upload data record to expense creation data.
     *
     * @param PocketExpenseUploadsData $uploadDataRecord
     * @return array<string, mixed>
     * @throws \Exception
     */
    private function mapUploadDataToExpenseData(PocketExpenseUploadsData $uploadDataRecord): array
    {
        $csvData = $uploadDataRecord->expense_data;

        // Parse and validate CSV data
        $parsedData = $this->parseExpenseData($csvData);

        // Build expense data array
        $expenseData = [
            'user_id' => $this->upload->user_id,
            'client_id' => $this->upload->client_id,
            'date' => $parsedData['date']->format('Y-m-d'),
            'merchant_name' => $this->sanitizeString($parsedData['merchant_name']),
            'merchant_description' => $this->sanitizeString($parsedData['merchant_description']),
            'expense_type' => $parsedData['expense_type_id'],
            'currency' => strtoupper($parsedData['currency']),
            'amount' => $parsedData['amount'],
            'merchant_address' => $this->sanitizeString($parsedData['merchant_address']),
            'vat_amount' => $parsedData['vat_amount'],
            'notes' => $this->sanitizeString($parsedData['notes']),
            'status' => self::DEFAULT_IMPORT_STATUS,
            'created_by_user_id' => $this->upload->created_by_user_id ?? $this->upload->user_id
        ];

        // Add metadata if available
        $metadata = [];
        
        if ($parsedData['source_id']) {
            $metadata['source_id'] = $parsedData['source_id'];
            if (!empty($parsedData['source_note'])) {
                $metadata['source_note'] = $this->sanitizeString($parsedData['source_note']);
            }
        }

        if (!empty($metadata)) {
            $expenseData['_metadata'] = $metadata;
        }

        return $expenseData;
    }

    /**
     * Parse expense data from CSV row data.
     *
     * @param array<string, string> $csvData
     * @return array<string, mixed>
     * @throws \Exception
     */
    private function parseExpenseData(array $csvData): array
    {
        // Parse date
        $date = $this->parseDate($csvData['Date'] ?? '');
        if (!$date) {
            throw new \Exception('Invalid date format: ' . ($csvData['Date'] ?? 'empty'));
        }

        // Parse expense type
        $expenseTypeId = $this->getExpenseTypeId($csvData['Expense Type'] ?? '');
        if (!$expenseTypeId) {
            throw new \Exception('Unknown expense type: ' . ($csvData['Expense Type'] ?? 'empty'));
        }

        // Parse amounts
        $amount = $this->parseAmount($csvData['Amount'] ?? '');
        if ($amount === null || $amount <= 0) {
            throw new \Exception('Invalid amount: ' . ($csvData['Amount'] ?? 'empty'));
        }

        $vatAmount = $this->parseAmount($csvData['VAT Amount'] ?? '');

        // Parse currency
        $currency = strtoupper(trim($csvData['Currency'] ?? ''));
        if (empty($currency) || strlen($currency) !== 3) {
            throw new \Exception('Invalid currency: ' . ($csvData['Currency'] ?? 'empty'));
        }

        // Parse source
        $sourceId = $this->getSourceId($csvData['Source'] ?? '');

        return [
            'date' => $date,
            'merchant_name' => trim($csvData['Merchant Name'] ?? ''),
            'merchant_description' => trim($csvData['Merchant Description'] ?? '') ?: null,
            'expense_type_id' => $expenseTypeId,
            'currency' => $currency,
            'amount' => $amount,
            'merchant_address' => trim($csvData['Merchant Address'] ?? '') ?: null,
            'vat_amount' => $vatAmount,
            'notes' => trim($csvData['Notes'] ?? '') ?: null,
            'source_id' => $sourceId,
            'source_note' => trim($csvData['Source Note'] ?? '') ?: null
        ];
    }

    /**
     * Parse date from CSV string.
     *
     * @param string $dateString
     * @return Carbon|null
     */
    private function parseDate(string $dateString): ?Carbon
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            // Expected format: DD/MM/YYYY
            return Carbon::createFromFormat('d/m/Y', trim($dateString));
        } catch (\Exception $e) {
            Log::warning('Failed to parse date from CSV', [
                'date_string' => $dateString,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Parse amount from CSV string.
     *
     * @param string $amountString
     * @return float|null
     */
    private function parseAmount(string $amountString): ?float
    {
        if (empty($amountString)) {
            return null;
        }

        // Remove currency symbols, spaces, and non-numeric characters except decimal separators
        $cleaned = preg_replace('/[^\d.,\-]/', '', trim($amountString));

        if (empty($cleaned)) {
            return null;
        }

        // Handle different decimal separators
        if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') === false) {
            // Only comma present, treat as decimal separator
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) {
            // Both present, assume comma is thousands separator
            $cleaned = str_replace(',', '', $cleaned);
        }

        if (!is_numeric($cleaned)) {
            return null;
        }

        return round((float) $cleaned, 2);
    }

    /**
     * Get expense type ID from option name.
     *
     * @param string $expenseTypeOption
     * @return int|null
     */
    private function getExpenseTypeId(string $expenseTypeOption): ?int
    {
        if (empty($expenseTypeOption)) {
            return null;
        }

        $expenseTypes = $this->referenceDataCache['expense_types'] ?? [];
        
        foreach ($expenseTypes as $expenseType) {
            if (strcasecmp($expenseType['option'], trim($expenseTypeOption)) === 0) {
                return $expenseType['id'];
            }
        }

        return null;
    }

    /**
     * Get source ID from source name.
     *
     * @param string $sourceName
     * @return int|null
     */
    private function getSourceId(string $sourceName): ?int
    {
        if (empty($sourceName)) {
            return null;
        }

        $sources = $this->referenceDataCache['expense_sources'] ?? [];
        
        foreach ($sources as $source) {
            if (strcasecmp($source['name'], trim($sourceName)) === 0) {
                return $source['id'];
            }
        }

        return null;
    }

    /**
     * Sanitize string value for database storage.
     *
     * @param string|null $value
     * @return string|null
     */
    private function sanitizeString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = trim(strip_tags($value));
        
        if (empty($sanitized)) {
            return null;
        }

        // Remove potential SQL injection attempts and clean the string
        return htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Preload reference data for efficient processing.
     *
     * @return void
     */
    private function preloadReferenceData(): void
    {
        // Load expense types
        $this->referenceDataCache['expense_types'] = OptPocketExpenseType::select('id', 'option', 'amount_sign')
            ->get()
            ->toArray();

        // Load expense sources (client-specific and global)
        $this->referenceDataCache['expense_sources'] = PocketExpenseSourceClientConfig::where(function ($query) {
                $query->where('client_id', $this->upload->client_id)
                      ->orWhereNull('client_id');
            })
            ->where('deleted', false)
            ->select('id', 'name', 'client_id')
            ->get()
            ->toArray();

        Log::debug('Reference data preloaded for expense processing', [
            'upload_id' => $this->upload->id,
            'expense_types_count' => count($this->referenceDataCache['expense_types']),
            'expense_sources_count' => count($this->referenceDataCache['expense_sources'])
        ]);
    }

    /**
     * Calculate optimal batch size based on memory usage and system resources.
     *
     * @return int
     */
    private function calculateOptimalBatchSize(): int
    {
        $currentMemoryMB = memory_get_usage(true) / 1024 / 1024;
        
        // Reduce batch size if memory usage is high
        if ($currentMemoryMB > self::MEMORY_THRESHOLD_MB) {
            return max(10, self::BATCH_SIZE / 2);
        }
        
        return self::BATCH_SIZE;
    }

    /**
     * Check memory usage and trigger garbage collection if needed.
     *
     * @return void
     */
    private function checkMemoryUsage(): void
    {
        $memoryUsageMB = memory_get_usage(true) / 1024 / 1024;
        
        if ($memoryUsageMB > self::MEMORY_THRESHOLD_MB) {
            Log::info('High memory usage detected, triggering garbage collection', [
                'upload_id' => $this->upload->id,
                'memory_usage_mb' => round($memoryUsageMB, 2),
                'threshold_mb' => self::MEMORY_THRESHOLD_MB
            ]);
            
            // Force garbage collection
            gc_collect_cycles();
        }
    }

    /**
     * Handle batch processing failure.
     *
     * @param int $batchNumber
     * @param Collection<int, PocketExpenseUploadsData> $batch
     * @param \Exception $exception
     * @return void
     */
    private function handleBatchFailure(int $batchNumber, Collection $batch, \Exception $exception): void
    {
        Log::error("Batch {$batchNumber} processing failed", [
            'upload_id' => $this->upload->id,
            'batch_number' => $batchNumber,
            'batch_size' => $batch->count(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->processingErrors[] = [
            'type' => 'batch_failure',
            'batch_number' => $batchNumber,
            'message' => $exception->getMessage(),
            'records_affected' => $batch->count(),
            'timestamp' => now()->toISOString()
        ];

        // Update failed records count
        $this->processingStats['failed_records'] += $batch->count();
        $this->processingStats['processed_records'] += $batch->count();
    }

    /**
     * Handle individual record processing failure.
     *
     * @param PocketExpenseUploadsData $uploadDataRecord
     * @param \Exception $exception
     * @return void
     */
    private function handleRecordProcessingFailure(PocketExpenseUploadsData $uploadDataRecord, \Exception $exception): void
    {
        Log::warning('Record processing failed', [
            'upload_id' => $this->upload->id,
            'upload_data_id' => $uploadDataRecord->id,
            'line_number' => $uploadDataRecord->line_number,
            'error' => $exception->getMessage()
        ]);

        $this->processingErrors[] = [
            'type' => 'record_failure',
            'upload_data_id' => $uploadDataRecord->id,
            'line_number' => $uploadDataRecord->line_number,
            'message' => $exception->getMessage(),
            'expense_data' => $uploadDataRecord->expense_data,
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Handle overall processing failure.
     *
     * @param \Exception $exception
     * @param float $startTime
     * @return void
     */
    private function handleProcessingFailure(\Exception $exception, float $startTime): void
    {
        $processingTime = microtime(true) - $startTime;

        Log::error('Expense upload processing failed', [
            'upload_id' => $this->upload->id,
            'upload_uuid' => $this->upload->uuid,
            'processing_time' => round($processingTime, 2),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'stats' => $this->processingStats,
            'total_errors' => count($this->processingErrors)
        ]);

        // Prepare error details for storage
        $errorDetails = [
            'processing_error' => $exception->getMessage(),
            'processing_time' => round($processingTime, 2),
            'stats' => $this->processingStats,
            'errors' => array_slice($this->processingErrors, 0, 100), // Limit to first 100 errors
            'failed_at' => now()->toISOString()
        ];

        $this->updateUploadStatus(PocketExpenseFileUpload::STATUS_FAILED, $errorDetails);
    }

    /**
     * Update upload status with optional error information.
     *
     * @param string $status
     * @param array<string, mixed>|null $errorInfo
     * @return void
     */
    private function updateUploadStatus(string $status, ?array $errorInfo = null): void
    {
        try {
            $updateData = ['status' => $status];
            
            // Set appropriate timestamps
            switch ($status) {
                case PocketExpenseFileUpload::STATUS_PROCESSING:
                    // No specific timestamp needed
                    break;
                    
                case PocketExpenseFileUpload::STATUS_COMPLETED:
                    $updateData['processed_at'] = now();
                    break;
                    
                case PocketExpenseFileUpload::STATUS_FAILED:
                    $updateData['processed_at'] = now();
                    if ($errorInfo) {
                        $updateData['validation_errors'] = array_merge(
                            $this->upload->validation_errors ?? [],
                            [$errorInfo]
                        );
                    }
                    break;
            }

            $this->upload->update($updateData);
            $this->upload->refresh();

        } catch (\Exception $e) {
            Log::error('Failed to update upload status', [
                'upload_id' => $this->upload->id,
                'new_status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update final statistics in the upload record.
     *
     * @return void
     */
    private function updateFinalStatistics(): void
    {
        try {
            $this->upload->update([
                'total_records' => $this->processingStats['total_records'],
                'valid_records' => $this->processingStats['successful_records']
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update final statistics', [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Determine final upload status based on processing results.
     *
     * @return string
     */
    private function determineFinalStatus(): string
    {
        $successfulRecords = $this->processingStats['successful_records'];
        $failedRecords = $this->processingStats['failed_records'];
        $totalProcessed = $successfulRecords + $failedRecords;

        // If no records were processed successfully, mark as failed
        if ($successfulRecords === 0 && $failedRecords > 0) {
            return PocketExpenseFileUpload::STATUS_FAILED;
        }

        // If all records were processed successfully, mark as completed
        if ($failedRecords === 0 && $successfulRecords > 0) {
            return PocketExpenseFileUpload::STATUS_COMPLETED;
        }

        // If some records succeeded and some failed, still mark as completed
        // but the statistics will show the partial success
        if ($successfulRecords > 0) {
            Log::warning('Partial processing success', [
                'upload_id' => $this->upload->id,
                'successful_records' => $successfulRecords,
                'failed_records' => $failedRecords,
                'success_rate' => round(($successfulRecords / $totalProcessed) * 100, 2)
            ]);
            
            return PocketExpenseFileUpload::STATUS_COMPLETED;
        }

        // Default to failed if we can't determine success
        return PocketExpenseFileUpload::STATUS_FAILED;
    }

    /**
     * Get job tags for monitoring and debugging.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'expense-upload',
            'client:' . $this->upload->client_id,
            'user:' . $this->upload->user_id,
            'upload:' . $this->upload->id
        ];
    }

    /**
     * Get processing statistics for monitoring.
     *
     * @return array<string, mixed>
     */
    public function getProcessingStats(): array
    {
        return [
            'stats' => $this->processingStats,
            'errors' => $this->processingErrors,
            'upload_id' => $this->upload->id,
            'upload_uuid' => $this->upload->uuid,
            'current_status' => $this->upload->status
        ];
    }

    /**
     * Calculate processing progress percentage.
     *
     * @return float
     */
    public function getProcessingProgress(): float
    {
        $totalRecords = $this->processingStats['total_records'];
        $processedRecords = $this->processingStats['processed_records'];

        if ($totalRecords === 0) {
            return 0.0;
        }

        return round(($processedRecords / $totalRecords) * 100, 2);
    }

    /**
     * Get estimated time remaining for processing.
     *
     * @param float $startTime
     * @return float|null
     */
    public function getEstimatedTimeRemaining(float $startTime): ?float
    {
        $processedRecords = $this->processingStats['processed_records'];
        $totalRecords = $this->processingStats['total_records'];
        
        if ($processedRecords === 0 || $totalRecords === 0) {
            return null;
        }

        $elapsedTime = microtime(true) - $startTime;
        $recordsPerSecond = $processedRecords / $elapsedTime;
        $remainingRecords = $totalRecords - $processedRecords;

        return $recordsPerSecond > 0 ? $remainingRecords / $recordsPerSecond : null;
    }

    /**
     * Clean up resources and reset state.
     *
     * @return void
     */
    public function cleanup(): void
    {
        $this->referenceDataCache = [];
        $this->processingErrors = [];
        
        // Force garbage collection to free memory
        gc_collect_cycles();
    }
}