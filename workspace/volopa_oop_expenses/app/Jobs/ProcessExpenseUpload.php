<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Services\PocketExpenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

/**
 * Job for processing CSV expense upload data asynchronously
 * 
 * Processes expense upload data in batches of 100 records, converting
 * validated CSV rows into actual PocketExpense records. Updates upload
 * status throughout the process and handles error scenarios gracefully.
 * Runs on the 'expense-processing' queue as per constraints.
 */
class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload record being processed
     *
     * @var PocketExpenseFileUpload
     */
    protected PocketExpenseFileUpload $upload;

    /**
     * Batch size for processing upload data records
     *
     * @var int
     */
    protected int $batchSize = 100;

    /**
     * Maximum number of retry attempts for failed processing
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Maximum time in seconds the job may run before timing out
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new job instance
     *
     * @param PocketExpenseFileUpload $upload The upload record to process
     */
    public function __construct(PocketExpenseFileUpload $upload)
    {
        $this->upload = $upload;
        
        // Set queue connection as per constraints
        $this->onQueue('expense-processing');
        
        // Set connection for multi-tenancy if needed
        $this->onConnection('default');
    }

    /**
     * Execute the job
     * 
     * Processes all pending upload data records in batches, creating
     * PocketExpense records for each valid row. Updates upload status
     * throughout the process and handles transaction rollback on failures.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        Log::info('Starting expense upload processing', [
            'upload_id' => $this->upload->id,
            'total_records' => $this->upload->total_records,
            'valid_records' => $this->upload->valid_records,
        ]);

        try {
            // Update upload status to processing
            $this->updateUploadStatus('processing');

            // Get all pending upload data records for this upload
            $pendingRecords = PocketExpenseUploadsData::where('upload_id', $this->upload->id)
                ->where('status', 'pending')
                ->orderBy('line_number')
                ->get();

            if ($pendingRecords->isEmpty()) {
                Log::warning('No pending records found for processing', [
                    'upload_id' => $this->upload->id,
                ]);
                
                $this->updateUploadStatus('completed');
                return;
            }

            // Process records in batches
            $totalProcessed = 0;
            $totalFailed = 0;
            $batches = $pendingRecords->chunk($this->batchSize);

            foreach ($batches as $batch) {
                $batchResult = $this->processBatch($batch);
                $totalProcessed += $batchResult['processed'];
                $totalFailed += $batchResult['failed'];

                Log::info('Batch processed', [
                    'upload_id' => $this->upload->id,
                    'batch_size' => $batch->count(),
                    'batch_processed' => $batchResult['processed'],
                    'batch_failed' => $batchResult['failed'],
                    'total_processed' => $totalProcessed,
                    'total_failed' => $totalFailed,
                ]);
            }

            // Update upload status based on results
            if ($totalFailed === 0) {
                $this->updateUploadStatus('completed');
                Log::info('Expense upload processing completed successfully', [
                    'upload_id' => $this->upload->id,
                    'total_processed' => $totalProcessed,
                ]);
            } else {
                $this->updateUploadStatus('failed');
                Log::error('Expense upload processing completed with failures', [
                    'upload_id' => $this->upload->id,
                    'total_processed' => $totalProcessed,
                    'total_failed' => $totalFailed,
                ]);
            }

            // TODO: Send notification to target user when batch upload completes
            // This requires clarification of notification mechanism (email vs in-app)
            
        } catch (Exception $e) {
            $this->handleJobFailure($e);
            throw $e;
        }
    }

    /**
     * Process a batch of upload data records
     * 
     * @param Collection $batch Collection of PocketExpenseUploadsData records
     * @return array Array with 'processed' and 'failed' counts
     */
    protected function processBatch(Collection $batch): array
    {
        $processed = 0;
        $failed = 0;
        $expenseService = app(PocketExpenseService::class);

        foreach ($batch as $uploadData) {
            try {
                DB::beginTransaction();

                // Mark record as processing
                $uploadData->update([
                    'status' => 'processing',
                    'processing_errors' => null,
                ]);

                // Create expense record from CSV data
                $expenseData = $this->parseExpenseData($uploadData->expense_data);
                
                $createdExpense = $expenseService->createExpense(
                    $expenseData,
                    $this->upload->user_id,
                    $this->upload->client_id
                );

                // Update upload data record with success status
                $uploadData->update([
                    'status' => 'completed',
                    'created_expense_id' => $createdExpense->id,
                    'processing_errors' => null,
                ]);

                DB::commit();
                $processed++;

                Log::debug('Upload record processed successfully', [
                    'upload_id' => $this->upload->id,
                    'upload_data_id' => $uploadData->id,
                    'line_number' => $uploadData->line_number,
                    'created_expense_id' => $createdExpense->id,
                ]);

            } catch (Exception $e) {
                DB::rollBack();
                $failed++;

                // Update upload data record with failure status
                $uploadData->update([
                    'status' => 'failed',
                    'processing_errors' => json_encode([
                        'error_message' => $e->getMessage(),
                        'error_code' => $e->getCode(),
                        'timestamp' => now()->toISOString(),
                    ]),
                ]);

                Log::error('Failed to process upload record', [
                    'upload_id' => $this->upload->id,
                    'upload_data_id' => $uploadData->id,
                    'line_number' => $uploadData->line_number,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * Parse CSV expense data into format expected by PocketExpenseService
     * 
     * @param array $csvData Raw CSV data from upload record
     * @return array Formatted expense data for service
     */
    protected function parseExpenseData(array $csvData): array
    {
        // Convert DD/MM/YYYY date format to Y-m-d for database storage
        $date = \DateTime::createFromFormat('d/m/Y', $csvData['date']);
        
        return [
            'date' => $date ? $date->format('Y-m-d') : now()->format('Y-m-d'),
            'merchant_name' => trim($csvData['merchant_name'] ?? ''),
            'merchant_description' => trim($csvData['merchant_description'] ?? ''),
            'expense_type' => (int) ($csvData['expense_type_id'] ?? 2), // Default to Point of Sale
            'currency' => strtoupper($csvData['currency'] ?? 'GBP'),
            'amount' => (float) ($csvData['amount'] ?? 0.00),
            'merchant_address' => trim($csvData['merchant_address'] ?? ''),
            'vat_amount' => isset($csvData['vat_amount']) ? (float) $csvData['vat_amount'] : null,
            'notes' => trim($csvData['notes'] ?? ''),
            'status' => 'submitted', // CSV uploads start in submitted status
            
            // Metadata from CSV if present
            'metadata' => $this->parseMetadata($csvData),
        ];
    }

    /**
     * Parse metadata fields from CSV data
     * 
     * @param array $csvData Raw CSV data
     * @return array Metadata array for expense creation
     */
    protected function parseMetadata(array $csvData): array
    {
        $metadata = [];

        // Transaction Category metadata
        if (!empty($csvData['category_id'])) {
            $metadata[] = [
                'metadata_type' => 'category',
                'transaction_category_id' => (int) $csvData['category_id'],
                'details_json' => json_encode([
                    'source' => 'csv_upload',
                    'category_name' => $csvData['category_name'] ?? null,
                ]),
            ];
        }

        // Expense Source metadata
        if (!empty($csvData['source_id'])) {
            $metadata[] = [
                'metadata_type' => 'expense_source',
                'expense_source_id' => (int) $csvData['source_id'],
                'details_json' => json_encode([
                    'source' => 'csv_upload',
                    'source_name' => $csvData['source_name'] ?? null,
                    'source_note' => $csvData['source_note'] ?? null,
                ]),
            ];
        }

        // Tracking Code Type 1 metadata
        if (!empty($csvData['tracking_code_1_id'])) {
            $metadata[] = [
                'metadata_type' => 'tracking_code_type_1',
                'tracking_code_id' => (int) $csvData['tracking_code_1_id'],
                'details_json' => json_encode([
                    'source' => 'csv_upload',
                    'tracking_code_name' => $csvData['tracking_code_1_name'] ?? null,
                ]),
            ];
        }

        // Tracking Code Type 2 metadata
        if (!empty($csvData['tracking_code_2_id'])) {
            $metadata[] = [
                'metadata_type' => 'tracking_code_type_2',
                'tracking_code_id' => (int) $csvData['tracking_code_2_id'],
                'details_json' => json_encode([
                    'source' => 'csv_upload',
                    'tracking_code_name' => $csvData['tracking_code_2_name'] ?? null,
                ]),
            ];
        }

        // Project metadata
        if (!empty($csvData['project_id'])) {
            $metadata[] = [
                'metadata_type' => 'project',
                'project_id' => (int) $csvData['project_id'],
                'details_json' => json_encode([
                    'source' => 'csv_upload',
                    'project_name' => $csvData['project_name'] ?? null,
                ]),
            ];
        }

        return $metadata;
    }

    /**
     * Update upload status with processed timestamp
     * 
     * @param string $status New status value
     * @return void
     */
    protected function updateUploadStatus(string $status): void
    {
        $updates = ['status' => $status];

        // Set processed timestamp when completed or failed
        if (in_array($status, ['completed', 'failed'])) {
            $updates['processed_at'] = now();
        }

        $this->upload->update($updates);

        Log::info('Upload status updated', [
            'upload_id' => $this->upload->id,
            'status' => $status,
        ]);
    }

    /**
     * Handle job failure scenarios
     * 
     * @param Exception $exception The exception that caused the failure
     * @return void
     */
    protected function handleJobFailure(Exception $exception): void
    {
        Log::error('Expense upload processing job failed', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        try {
            // Update upload status to failed
            $this->updateUploadStatus('failed');

            // Mark any processing records as failed
            PocketExpenseUploadsData::where('upload_id', $this->upload->id)
                ->where('status', 'processing')
                ->update([
                    'status' => 'failed',
                    'processing_errors' => json_encode([
                        'error_message' => 'Job failed: ' . $exception->getMessage(),
                        'error_code' => $exception->getCode(),
                        'timestamp' => now()->toISOString(),
                        'job_failure' => true,
                    ]),
                ]);

        } catch (Exception $updateException) {
            Log::critical('Failed to update upload status after job failure', [
                'upload_id' => $this->upload->id,
                'original_error' => $exception->getMessage(),
                'update_error' => $updateException->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failure after max attempts
     * 
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('Expense upload processing job failed permanently', [
            'upload_id' => $this->upload->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
        ]);

        $this->handleJobFailure(new Exception($exception->getMessage(), $exception->getCode(), $exception));
        
        // TODO: Send notification to admin users about permanent job failure
        // This requires clarification of notification mechanism and admin user identification
    }

    /**
     * Get the tags that should be assigned to the job
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'upload:' . $this->upload->id,
            'client:' . $this->upload->client_id,
            'user:' . $this->upload->user_id,
            'expense-processing',
        ];
    }

    /**
     * Calculate the number of seconds to wait before retrying the job
     *
     * @return int
     */
    public function backoff(): int
    {
        // Exponential backoff: 10 seconds, then 30 seconds, then 90 seconds
        return [10, 30, 90][$this->attempts() - 1] ?? 90;
    }

    /**
     * Determine if the job should be retried based on the exception
     *
     * @param Exception $exception
     * @return bool
     */
    public function retryUntil(): \DateTime
    {
        // Retry for up to 1 hour after the job was initially queued
        return now()->addHour();
    }

    /**
     * Get the unique ID for the job (prevents duplicate processing)
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return 'process-expense-upload-' . $this->upload->id;
    }
}