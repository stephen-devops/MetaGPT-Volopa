## Code: app/Jobs/ProcessExpenseUpload.php

```php
<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadData;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\PocketExpenseService;
use App\Notifications\ExpenseProcessingCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use Exception;

/**
 * ProcessExpenseUpload Job
 * 
 * Background job for processing validated CSV upload data into pocket expenses.
 * Processes upload data in batches, creates expense records with metadata,
 * handles errors gracefully, and sends completion notifications.
 * Uses DB transactions for data integrity and comprehensive error handling.
 */
class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process.
     *
     * @var int
     */
    private int $uploadId;

    /**
     * Batch size for processing upload data.
     */
    private const BATCH_SIZE = 100;

    /**
     * Maximum number of retry attempts.
     */
    private const MAX_RETRIES = 3;

    /**
     * Job timeout in seconds (5 minutes).
     */
    private const JOB_TIMEOUT = 300;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = self::MAX_RETRIES;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = self::JOB_TIMEOUT;

    /**
     * Create a new job instance.
     *
     * @param int $uploadId
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
        $this->onQueue(config('pocket_expense.queue.csv_processing_queue', 'pocket-expense-csv'));
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        Log::info('Starting expense upload processing', [
            'upload_id' => $this->uploadId,
            'job_id' => $this->job->getJobId(),
        ]);

        try {
            // Get upload record
            $upload = PocketExpenseFileUpload::findOrFail($this->uploadId);

            // Validate upload status
            if (!$this->canProcessUpload($upload)) {
                Log::warning('Upload cannot be processed', [
                    'upload_id' => $this->uploadId,
                    'status' => $upload->status,
                ]);
                return;
            }

            // Update status to processing
            $this->updateUploadStatus($upload, PocketExpenseFileUpload::STATUS_PROCESSING, [
                'started_at' => now(),
            ]);

            // Process the upload data
            $result = $this->processUploadData($upload);

            // Update final status based on results
            $this->updateFinalStatus($upload, $result);

            // Send completion notification
            $this->sendCompletionNotification($upload, $result);

            Log::info('Expense upload processing completed', [
                'upload_id' => $this->uploadId,
                'processed' => $result['processed'],
                'failed' => $result['failed'],
                'status' => $upload->fresh()->status,
            ]);

        } catch (Exception $e) {
            $this->handleProcessingFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error('Expense upload processing job failed', [
            'upload_id' => $this->uploadId,
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        try {
            // Get upload record
            $upload = PocketExpenseFileUpload::find($this->uploadId);
            
            if ($upload) {
                // Update upload status to failed
                $this->updateUploadStatus($upload, PocketExpenseFileUpload::STATUS_FAILED, [
                    'failed_at' => now(),
                    'processing_errors' => [
                        [
                            'error' => 'Job failed after ' . $this->attempts() . ' attempts',
                            'message' => $exception->getMessage(),
                            'failed_at' => now()->toISOString(),
                        ]
                    ],
                ]);

                // Send failure notification
                $this->sendFailureNotification($upload, $exception);
            }

        } catch (Exception $notificationException) {
            Log::error('Failed to handle job failure properly', [
                'upload_id' => $this->uploadId,
                'original_error' => $exception->getMessage(),
                'notification_error' => $notificationException->getMessage(),
            ]);
        }
    }

    /**
     * Check if upload can be processed.
     *
     * @param PocketExpenseFileUpload $upload
     * @return bool
     */
    private function canProcessUpload(PocketExpenseFileUpload $upload): bool
    {
        $allowedStatuses = [
            PocketExpenseFileUpload::STATUS_VALIDATION_PASSED,
            PocketExpenseFileUpload::STATUS_PROCESSING, // Allow reprocessing
        ];

        return in_array($upload->status, $allowedStatuses) && 
               $upload->isActive() && 
               $upload->valid_records > 0;
    }

    /**
     * Process all upload data in batches.
     *
     * @param PocketExpenseFileUpload $upload
     * @return array<string, int>
     */
    private function processUploadData(PocketExpenseFileUpload $upload): array
    {
        $totalProcessed = 0;
        $totalFailed = 0;
        $batchNumber = 1;

        // Get pending upload data in batches
        $query = PocketExpenseUploadData::active()
            ->forUpload($this->uploadId)
            ->pending()
            ->orderByLine();

        while (true) {
            // Get next batch
            $uploadDataBatch = $query->take(self::BATCH_SIZE)->get();
            
            if ($uploadDataBatch->isEmpty()) {
                break;
            }

            Log::info('Processing upload data batch', [
                'upload_id' => $this->uploadId,
                'batch_number' => $batchNumber,
                'batch_size' => $uploadDataBatch->count(),
            ]);

            // Process the batch
            $batchResult = $this->processBatch($uploadDataBatch, $upload);
            
            $totalProcessed += $batchResult['processed'];
            $totalFailed += $batchResult['failed'];

            // Update progress
            $this->updateUploadProgress($upload, $totalProcessed, $totalFailed);

            $batchNumber++;

            // Remove processed items from query for next iteration
            $processedIds = $uploadDataBatch->pluck('id')->toArray();
            $query->whereNotIn('id', $processedIds);
        }

        return [
            'processed' => $totalProcessed,
            'failed' => $totalFailed,
            'total_batches' => $batchNumber - 1,
        ];
    }

    /**
     * Process a batch of upload data records.
     *
     * @param \Illuminate\Database\Eloquent\Collection $uploadDataBatch
     * @param PocketExpenseFileUpload $upload
     * @return array<string, int>
     */
    private function processBatch($uploadDataBatch, PocketExpenseFileUpload $upload): array
    {
        $processed = 0;
        $failed = 0;

        DB::beginTransaction();

        try {
            foreach ($uploadDataBatch as $uploadData) {
                $result = $this->processUploadDataRecord($uploadData, $upload);
                
                if ($result) {
                    $processed++;
                } else {
                    $failed++;
                }
            }

            DB::commit();

            Log::debug('Batch processing completed', [
                'upload_id' => $this->uploadId,
                'batch_processed' => $processed,
                'batch_failed' => $failed,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Batch processing failed, rolling back', [
                'upload_id' => $this->uploadId,
                'batch_size' => $uploadDataBatch->count(),
                'error' => $e->getMessage(),
            ]);

            // Mark all records in this batch as failed
            foreach ($uploadDataBatch as $uploadData) {
                $this->markUploadDataAsFailed($uploadData, [
                    'error' => 'Batch processing failed',
                    'message' => $e->getMessage(),
                ]);
            }

            $failed = $uploadDataBatch->count();
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    /**
     * Process a single upload data record.
     *
     * @param PocketExpenseUploadData $uploadData
     * @param PocketExpenseFileUpload $upload
     * @return bool
     */
    private function processUploadDataRecord(PocketExpenseUploadData $uploadData, PocketExpenseFileUpload $upload): bool
    {
        try {
            $expenseData = $uploadData->expense_data;

            // Create the pocket expense
            $expense = $this->createPocketExpense($expenseData, $upload);

            // Create metadata
            $this->createExpenseMetadata($expense, $expenseData);

            // Mark upload data as synced
            $uploadData->status = PocketExpenseUploadData::STATUS_SYNCED;
            $uploadData->created_expense_id = $expense->id;
            $uploadData->synced_at = now();
            $uploadData->processing_errors = null;
            $uploadData->save();

            return true;

        } catch (Exception $e) {
            Log::error('Failed to process upload data record', [
                'upload_id' => $this->uploadId,
                'upload_data_id' => $uploadData->id,
                'line_number' => $uploadData->line_number,
                'error' => $e->getMessage(),
            ]);

            // Mark as failed with error details
            $this->markUploadDataAsFailed($uploadData, [
                'error' => 'Record processing failed',
                'message' => $e->getMessage(),
                'expense_data' => $expenseData ?? null,
            ]);

            return false;
        }
    }

    /**
     * Create a pocket expense from upload data.
     *
     * @param array<string, mixed> $expenseData
     * @param PocketExpenseFileUpload $upload
     * @return PocketExpense
     * @throws Exception
     */
    private function createPocketExpense(array $expenseData, PocketExpenseFileUpload $upload): PocketExpense
    {
        // Get expense type for amount sign calculation
        $expenseType = OptPocketExpenseType::findByOption($expenseData['expense_type']);
        if (!$expenseType) {
            throw new Exception('Invalid expense type: ' . $expenseData['expense_type']);
        }

        // Apply expense type sign to amounts
        $amount = $expenseType->applySign(abs((float) $expenseData['amount']));
        $userConvertedAmount = null;
        
        if (isset($expenseData['user_converted_amount']) && $expenseData['user_converted_amount']) {
            $userConvertedAmount = $expenseType->applySign(abs((float) $expenseData['user_converted_amount']));
        }

        // Prepare expense data
        $expense = PocketExpense::create([
            'user_id' => $upload->target_user_id,
            'client_id' => $upload->client_id,
            'date' => Carbon::parse($expenseData['date']),
            'merchant_name' => trim($expenseData['merchant_name']),
            'merchant_description' => isset($expenseData['merchant_description']) 
                ? trim($expenseData['merchant_description']) : null,
            'expense_type' => $expenseType->id,
            'currency' => strtoupper($expenseData['currency']),
            'amount' => $amount,
            'merchant_address' => isset($expenseData['merchant_address']) 
                ? trim($expenseData['merchant_address']) : null,
            'merchant_country' => isset($expenseData['merchant_country']) 
                ? strtoupper($expenseData['merchant_country']) : null,
            'vat_amount' => isset($expenseData['vat_amount']) 
                ? (float) $expenseData['vat_amount'] : null,
            'user_converted_amount' => $userConvertedAmount,
            'notes' => isset($expenseData['notes']) 
                ? trim($expenseData['notes']) : null,
            'status' => PocketExpense::STATUS_SUBMITTED, // CSV uploads are auto-submitted
            'created_by_user_id' => $upload->user_id,
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
            'approved_at' => null,
        ]);

        return $expense;
    }

    /**
     * Create expense metadata from upload data.
     *
     * @param PocketExpense $expense
     * @param array<string, mixed> $expenseData
     * @return void
     * @throws Exception
     */
    private function createExpenseMetadata(PocketExpense $expense, array $expenseData): void
    {
        $metadataRecords = [];

        // Handle expense source
        if (isset($expenseData['expense_source_name']) && $expenseData['expense_source_name']) {
            $source = PocketExpenseSourceClientConfig::findByNameForClient(
                $expenseData['expense_source_name'],
                $expense->client_id
            );

            if ($source) {
                $metadataRecords[] = [
                    'pocket_expense_id' => $expense->id,
                    'metadata_type' => PocketExpenseMetadata::TYPE_EXPENSE_SOURCE,
                    'expense_source_id' => $source->id,
                    'user_id' => $expense->user_id,
                    'details_json' => [
                        'source_name' => $source->name,
                        'source_note' => isset($expenseData['source_note']) ? trim($expenseData['source_note']) : null,
                    ],
                ];
            }
        }

        // Handle other metadata types if provided
        $metadataTypes = [
            'transaction_category_id' => PocketExpenseMetadata::TYPE_CATEGORY,
            'tracking_code_id' => PocketExpenseMetadata::TYPE_TRACKING_CODE_TYPE_1,
            'project_id' => PocketExpenseMetadata::TYPE_PROJECT,
        ];

        foreach ($metadataTypes as $dataKey => $metadataType) {
            if (isset($expenseData[$dataKey]) && $expenseData[$dataKey]) {
                $metadataRecords[] = [
                    '