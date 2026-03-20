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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * ProcessExpenseUpload Job
 * 
 * Handles asynchronous processing of CSV expense uploads.
 * Processes validated expense data in batches and creates PocketExpense records.
 * Updates upload status and handles error scenarios.
 * 
 * @package App\Jobs
 */
class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload record to process.
     *
     * @var \App\Models\PocketExpenseFileUpload
     */
    protected PocketExpenseFileUpload $upload;

    /**
     * Batch size for processing upload data records.
     */
    private const BATCH_SIZE = 100;

    /**
     * Maximum number of retry attempts for failed records.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     *
     * @var int
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     *
     * @param \App\Models\PocketExpenseFileUpload $upload
     */
    public function __construct(PocketExpenseFileUpload $upload)
    {
        $this->upload = $upload;
        $this->onQueue('expense-processing');
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Exception
     */
    public function handle(): void
    {
        Log::info('Starting expense upload processing', [
            'upload_id' => $this->upload->id,
            'uuid' => $this->upload->uuid,
            'total_records' => $this->upload->total_records,
            'valid_records' => $this->upload->valid_records,
        ]);

        try {
            // Verify upload is in correct status for processing
            if (!$this->canProcessUpload()) {
                Log::warning('Upload cannot be processed due to invalid status', [
                    'upload_id' => $this->upload->id,
                    'status' => $this->upload->status,
                ]);
                return;
            }

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Process expense records in batches
            $totalProcessed = 0;
            $totalFailed = 0;
            $batchNumber = 1;

            while (true) {
                $batch = $this->getNextBatch($batchNumber);
                
                if ($batch->isEmpty()) {
                    break;
                }

                Log::debug('Processing batch', [
                    'upload_id' => $this->upload->id,
                    'batch_number' => $batchNumber,
                    'batch_size' => $batch->count(),
                ]);

                $batchResult = $this->processBatch($batch);
                $totalProcessed += $batchResult['processed'];
                $totalFailed += $batchResult['failed'];

                $batchNumber++;
            }

            // Determine final status based on processing results
            $finalStatus = $this->determineFinalStatus($totalProcessed, $totalFailed);
            $this->updateUploadStatus($finalStatus, now());

            Log::info('Expense upload processing completed', [
                'upload_id' => $this->upload->id,
                'total_processed' => $totalProcessed,
                'total_failed' => $totalFailed,
                'final_status' => $finalStatus,
            ]);

        } catch (Exception $e) {
            Log::error('Error processing expense upload', [
                'upload_id' => $this->upload->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateUploadStatus('failed');
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Expense upload processing job failed permanently', [
            'upload_id' => $this->upload->id,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        $this->updateUploadStatus('failed');
    }

    /**
     * Check if the upload can be processed.
     *
     * @return bool
     */
    private function canProcessUpload(): bool
    {
        // Refresh the model to get latest status
        $this->upload->refresh();

        return in_array($this->upload->status, [
            'validation_passed',
            'processing', // Allow resuming of interrupted processing
        ]);
    }

    /**
     * Get the next batch of upload data records to process.
     *
     * @param int $batchNumber
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function getNextBatch(int $batchNumber): \Illuminate\Database\Eloquent\Collection
    {
        $offset = ($batchNumber - 1) * self::BATCH_SIZE;

        return PocketExpenseUploadsData::where('upload_id', $this->upload->id)
            ->whereIn('status', ['validated', 'pending', 'failed']) // Include failed for retry
            ->orderBy('line_number')
            ->offset($offset)
            ->limit(self::BATCH_SIZE)
            ->get();
    }

    /**
     * Process a batch of upload data records.
     *
     * @param \Illuminate\Database\Eloquent\Collection $batch
     * @return array{processed: int, failed: int}
     */
    private function processBatch(\Illuminate\Database\Eloquent\Collection $batch): array
    {
        $processed = 0;
        $failed = 0;
        $pocketExpenseService = app(PocketExpenseService::class);

        foreach ($batch as $uploadData) {
            try {
                DB::beginTransaction();

                // Extract expense data from JSON
                $expenseData = $uploadData->expense_data;
                
                if (!is_array($expenseData)) {
                    throw new Exception('Invalid expense data format');
                }

                // Add required fields for expense creation
                $expenseData['user_id'] = $this->upload->user_id;
                $expenseData['client_id'] = $this->upload->client_id;
                $expenseData['created_by_user_id'] = $this->upload->created_by_user_id;
                $expenseData['status'] = 'draft'; // Start as draft per business rules

                // Create the expense through the service
                $expense = $pocketExpenseService->createExpense($expenseData);

                // Update upload data status
                $uploadData->update(['status' => 'processed']);

                DB::commit();
                $processed++;

                Log::debug('Expense created from upload', [
                    'upload_id' => $this->upload->id,
                    'upload_data_id' => $uploadData->id,
                    'expense_id' => $expense->id,
                    'expense_uuid' => $expense->uuid,
                    'line_number' => $uploadData->line_number,
                ]);

            } catch (Exception $e) {
                DB::rollBack();
                $failed++;

                Log::warning('Failed to process upload data record', [
                    'upload_id' => $this->upload->id,
                    'upload_data_id' => $uploadData->id,
                    'line_number' => $uploadData->line_number,
                    'error' => $e->getMessage(),
                ]);

                // Update status to failed
                $uploadData->update(['status' => 'failed']);
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * Synchronize a batch of expenses in a transaction.
     * Alternative method for atomic batch processing if needed.
     *
     * @param \Illuminate\Database\Eloquent\Collection $batch
     * @return void
     * @throws \Exception
     */
    public function syncExpenseBatch(\Illuminate\Database\Eloquent\Collection $batch): void
    {
        DB::beginTransaction();

        try {
            $pocketExpenseService = app(PocketExpenseService::class);

            foreach ($batch as $uploadData) {
                $expenseData = $uploadData->expense_data;
                
                if (!is_array($expenseData)) {
                    throw new Exception("Invalid expense data format for line {$uploadData->line_number}");
                }

                // Add required fields
                $expenseData['user_id'] = $this->upload->user_id;
                $expenseData['client_id'] = $this->upload->client_id;
                $expenseData['created_by_user_id'] = $this->upload->created_by_user_id;
                $expenseData['status'] = 'draft';

                // Create the expense
                $expense = $pocketExpenseService->createExpense($expenseData);

                // Update upload data status
                $uploadData->update(['status' => 'processed']);

                Log::debug('Expense synced from batch', [
                    'upload_id' => $this->upload->id,
                    'expense_id' => $expense->id,
                    'line_number' => $uploadData->line_number,
                ]);
            }

            DB::commit();

            Log::info('Expense batch synchronized successfully', [
                'upload_id' => $this->upload->id,
                'batch_size' => $batch->count(),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to synchronize expense batch', [
                'upload_id' => $this->upload->id,
                'batch_size' => $batch->count(),
                'error' => $e->getMessage(),
            ]);

            // Mark all batch records as failed
            foreach ($batch as $uploadData) {
                $uploadData->update(['status' => 'failed']);
            }

            throw $e;
        }
    }

    /**
     * Update the upload status.
     *
     * @param string $status
     * @param \Carbon\Carbon|null $processedAt
     * @return void
     */
    public function updateUploadStatus(string $status, ?\Carbon\Carbon $processedAt = null): void
    {
        $updateData = ['status' => $status];

        if ($processedAt) {
            $updateData['processed_at'] = $processedAt;
        }

        $this->upload->update($updateData);

        Log::info('Upload status updated', [
            'upload_id' => $this->upload->id,
            'old_status' => $this->upload->getOriginal('status'),
            'new_status' => $status,
            'processed_at' => $processedAt,
        ]);
    }

    /**
     * Determine the final status based on processing results.
     *
     * @param int $totalProcessed
     * @param int $totalFailed
     * @return string
     */
    private function determineFinalStatus(int $totalProcessed, int $totalFailed): string
    {
        if ($totalFailed === 0) {
            return 'completed';
        }

        if ($totalProcessed === 0) {
            return 'sync_failed';
        }

        // Partial success - some records processed, some failed
        return 'sync_failed';
    }

    /**
     * Get processing statistics for the upload.
     *
     * @return array{total: int, processed: int, failed: int, pending: int}
     */
    private function getProcessingStats(): array
    {
        $stats = PocketExpenseUploadsData::where('upload_id', $this->upload->id)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "processed" THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status IN ("pending", "validated") THEN 1 ELSE 0 END) as pending
            ')
            ->first();

        return [
            'total' => $stats->total ?? 0,
            'processed' => $stats->processed ?? 0,
            'failed' => $stats->failed ?? 0,
            'pending' => $stats->pending ?? 0,
        ];
    }

    /**
     * Clean up processed upload data if configured to do so.
     * This method can be called after successful processing to free up space.
     *
     * @param bool $keepFailedRecords
     * @return int Number of records deleted
     */
    private function cleanupProcessedData(bool $keepFailedRecords = true): int
    {
        $query = PocketExpenseUploadsData::where('upload_id', $this->upload->id)
            ->where('status', 'processed');

        if (!$keepFailedRecords) {
            $query->orWhere('status', 'failed');
        }

        $deletedCount = $query->count();
        $query->delete();

        Log::info('Cleaned up processed upload data', [
            'upload_id' => $this->upload->id,
            'deleted_count' => $deletedCount,
            'kept_failed_records' => $keepFailedRecords,
        ]);

        return $deletedCount;
    }

    /**
     * Get the retry delay in seconds.
     *
     * @return int
     */
    public function retryAfter(): int
    {
        return 60; // Retry after 1 minute
    }

    /**
     * Get the tags for this job (useful for monitoring).
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'expense-upload',
            'upload:' . $this->upload->id,
            'client:' . $this->upload->client_id,
            'user:' . $this->upload->user_id,
        ];
    }

    /**
     * Calculate the percentage of processing completion.
     *
     * @return float
     */
    private function getCompletionPercentage(): float
    {
        $stats = $this->getProcessingStats();
        
        if ($stats['total'] === 0) {
            return 0.0;
        }

        $completed = $stats['processed'] + $stats['failed'];
        return ($completed / $stats['total']) * 100.0;
    }

    /**
     * Get a summary of the processing results.
     *
     * @return array
     */
    private function getProcessingSummary(): array
    {
        $stats = $this->getProcessingStats();
        
        return [
            'upload_id' => $this->upload->id,
            'uuid' => $this->upload->uuid,
            'total_records' => $stats['total'],
            'processed_records' => $stats['processed'],
            'failed_records' => $stats['failed'],
            'pending_records' => $stats['pending'],
            'completion_percentage' => $this->getCompletionPercentage(),
            'success_rate' => $stats['total'] > 0 ? ($stats['processed'] / $stats['total']) * 100 : 0,
        ];
    }

    /**
     * Handle job middleware if needed.
     *
     * @return array
     */
    public function middleware(): array
    {
        return [
            // Add rate limiting or other middleware if needed
        ];
    }

    /**
     * Get the display name for the queued job.
     *
     * @return string
     */
    public function displayName(): string
    {
        return "Process Expense Upload #{$this->upload->id} ({$this->upload->file_name})";
    }
}