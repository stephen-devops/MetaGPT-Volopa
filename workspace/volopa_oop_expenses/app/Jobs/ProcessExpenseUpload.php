## Code: app/Jobs/ProcessExpenseUpload.php

```php
<?php

namespace App\Jobs;

use App\Models\PocketExpense;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadData;
use App\Services\PocketExpenseService;
use App\Services\PocketExpenseFXService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Exception;
use Throwable;

class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process.
     *
     * @var int
     */
    public int $upload_id;

    /**
     * Default batch size for processing uploads.
     *
     * @var int
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * Maximum number of attempts for processing.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Maximum execution time in seconds.
     *
     * @var int
     */
    public int $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     *
     * @param int $uploadId
     */
    public function __construct(int $uploadId)
    {
        $this->upload_id = $uploadId;
        $this->onQueue('expense-processing');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('Starting expense upload processing', [
            'upload_id' => $this->upload_id
        ]);

        try {
            // Get the upload record
            $upload = PocketExpenseFileUpload::find($this->upload_id);
            
            if (!$upload) {
                Log::error('Upload record not found', [
                    'upload_id' => $this->upload_id
                ]);
                return;
            }

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Get all pending upload data records
            $pendingData = PocketExpenseUploadData::where('upload_id', $this->upload_id)
                                                  ->where('status', 'pending')
                                                  ->orderBy('line_number')
                                                  ->get();

            if ($pendingData->isEmpty()) {
                Log::warning('No pending upload data found', [
                    'upload_id' => $this->upload_id
                ]);
                $this->updateUploadStatus('completed');
                return;
            }

            Log::info('Processing upload data records', [
                'upload_id' => $this->upload_id,
                'total_pending_records' => $pendingData->count()
            ]);

            // Process in batches
            $batches = $pendingData->chunk(self::DEFAULT_BATCH_SIZE);
            $totalSynced = 0;
            $totalFailed = 0;

            foreach ($batches as $batch) {
                $result = $this->processBatch($batch, $upload);
                $totalSynced += $result['synced'];
                $totalFailed += $result['failed'];

                Log::info('Batch processed', [
                    'upload_id' => $this->upload_id,
                    'batch_synced' => $result['synced'],
                    'batch_failed' => $result['failed'],
                    'total_synced' => $totalSynced,
                    'total_failed' => $totalFailed
                ]);
            }

            // Update final status
            if ($totalFailed === 0) {
                $this->updateUploadStatus('completed');
                $message = "Upload completed successfully. {$totalSynced} expenses were processed.";
            } else {
                $this->updateUploadStatus('completed');
                $message = "Upload completed with issues. {$totalSynced} expenses were processed successfully, {$totalFailed} failed.";
            }

            // Notify user
            $this->notifyUser($upload->created_by_user_id, $message);

            Log::info('Expense upload processing completed', [
                'upload_id' => $this->upload_id,
                'total_synced' => $totalSynced,
                'total_failed' => $totalFailed
            ]);

        } catch (Exception $e) {
            Log::error('Expense upload processing failed', [
                'upload_id' => $this->upload_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateUploadStatus('failed');
            $this->notifyUser($this->getUploadCreatorUserId(), 'Upload processing failed: ' . $e->getMessage());
            
            // Re-throw to trigger job retry
            throw $e;
        }
    }

    /**
     * Process a batch of upload data records.
     *
     * @param Collection $batch
     * @param PocketExpenseFileUpload $upload
     * @return array<string, int>
     */
    private function processBatch(Collection $batch, PocketExpenseFileUpload $upload): array
    {
        $synced = 0;
        $failed = 0;
        $service = new PocketExpenseService();

        foreach ($batch as $uploadData) {
            try {
                DB::beginTransaction();

                // Sync expense to main service
                $result = $this->syncExpenseToMainService($uploadData, $upload, $service);
                
                if ($result) {
                    $uploadData->markAsSynced();
                    $synced++;
                } else {
                    $uploadData->markAsFailed();
                    $failed++;
                }

                DB::commit();

            } catch (Exception $e) {
                DB::rollBack();
                
                Log::error('Failed to process upload data record', [
                    'upload_id' => $this->upload_id,
                    'upload_data_id' => $uploadData->id,
                    'line_number' => $uploadData->line_number,
                    'error' => $e->getMessage()
                ]);

                try {
                    $uploadData->markAsFailed();
                } catch (Exception $markFailedException) {
                    Log::error('Failed to mark upload data as failed', [
                        'upload_data_id' => $uploadData->id,
                        'error' => $markFailedException->getMessage()
                    ]);
                }

                $failed++;
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed
        ];
    }

    /**
     * Sync individual expense to main expense service.
     *
     * @param PocketExpenseUploadData $uploadData
     * @param PocketExpenseFileUpload $upload
     * @param PocketExpenseService $service
     * @return bool
     */
    private function syncExpenseToMainService(
        PocketExpenseUploadData $uploadData, 
        PocketExpenseFileUpload $upload,
        PocketExpenseService $service
    ): bool {
        try {
            $expenseData = $uploadData->getExpenseDataArray();
            
            if (empty($expenseData)) {
                Log::warning('Empty expense data found', [
                    'upload_data_id' => $uploadData->id,
                    'line_number' => $uploadData->line_number
                ]);
                return false;
            }

            // Prepare data for expense creation
            $createData = [
                'date' => $expenseData['date'] ?? null,
                'merchant_name' => $expenseData['merchant_name'] ?? null,
                