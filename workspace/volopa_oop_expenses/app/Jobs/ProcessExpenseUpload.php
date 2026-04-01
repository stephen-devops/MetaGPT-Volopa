<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * ProcessExpenseUpload Job
 * 
 * Background job for processing validated CSV data.
 * Syncs expense data to main service in batches of 100 rows as per system constraints.
 * Updates upload status throughout the processing workflow.
 */
class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process.
     *
     * @var int
     */
    public int $uploadId;

    /**
     * Create a new job instance.
     *
     * @param int $uploadId
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
        $this->onQueue('expense-processing'); // Background job queue name as per system constraints
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info('ProcessExpenseUpload job started', ['upload_id' => $this->uploadId]);

            $upload = PocketExpenseFileUpload::find($this->uploadId);
            
            if (!$upload) {
                Log::error('Upload record not found', ['upload_id' => $this->uploadId]);
                return;
            }

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Get all pending upload data records
            $uploadDataRecords = PocketExpenseUploadsData::where('upload_id', $this->uploadId)
                ->where('status', 'pending')
                ->orderBy('line_number')
                ->get();

            if ($uploadDataRecords->isEmpty()) {
                Log::warning('No pending upload data found', ['upload_id' => $this->uploadId]);
                $this->updateUploadStatus('completed');
                return;
            }

            // Process in batches of 100 as per system constraints
            $batchSize = 100;
            $batches = $uploadDataRecords->chunk($batchSize);
            $totalProcessed = 0;
            $totalFailed = 0;

            foreach ($batches as $batch) {
                try {
                    $result = $this->syncExpenseBatch($batch->toArray());
                    $totalProcessed += $result['processed'];
                    $totalFailed += $result['failed'];

                    Log::info('Batch processed', [
                        'upload_id' => $this->uploadId,
                        'batch_size' => $batch->count(),
                        'processed' => $result['processed'],
                        'failed' => $result['failed']
                    ]);
                } catch (Exception $e) {
                    Log::error('Batch processing failed', [
                        'upload_id' => $this->uploadId,
                        'batch_size' => $batch->count(),
                        'error' => $e->getMessage()
                    ]);

                    // Mark all records in this batch as failed
                    foreach ($batch as $record) {
                        $record->update(['status' => 'failed']);
                    }
                    $totalFailed += $batch->count();
                }
            }

            // Determine final status
            if ($totalFailed === 0) {
                $this->updateUploadStatus('completed');
            } else if ($totalProcessed === 0) {
                $this->updateUploadStatus('failed');
            } else {
                $this->updateUploadStatus('sync_failed');
            }

            Log::info('ProcessExpenseUpload job completed', [
                'upload_id' => $this->uploadId,
                'total_processed' => $totalProcessed,
                'total_failed' => $totalFailed
            ]);

            // TODO: Send notification about upload completion
            // Decision needed on notification channel preference (in-app, email, push, or combination)

        } catch (Exception $e) {
            Log::error('ProcessExpenseUpload job failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateUploadStatus('failed');
            throw $e; // Re-throw to trigger job failure handling
        }
    }

    /**
     * Sync expense batch to create actual expense records.
     * Processes expenses in batches with transaction safety.
     *
     * @param array $expenses
     * @return array ['processed' => int, 'failed' => int]
     */
    public function syncExpenseBatch(array $expenses): array
    {
        $processedCount = 0;
        $failedCount = 0;

        foreach ($expenses as $expenseData) {
            try {
                DB::transaction(function () use ($expenseData, &$processedCount) {
                    // Mark as processing
                    PocketExpenseUploadsData::where('id', $expenseData['id'])
                        ->update(['status' => 'processing']);

                    $csvData = $expenseData['expense_data'];
                    $upload = PocketExpenseFileUpload::find($this->uploadId);

                    // Convert CSV data to expense record format
                    $expenseRecord = $this->convertCSVToExpenseData($csvData, $upload);

                    // Create the pocket expense record
                    $expense = PocketExpense::create($expenseRecord);

                    // Create metadata if needed (source information)
                    if (!empty($csvData['Source']) && $csvData['Source'] !== '') {
                        $this->createExpenseSourceMetadata($expense, $csvData, $upload->client_id);
                    }

                    // Create source note metadata if Other source is used
                    if (!empty($csvData['Source Note']) && $csvData['Source Note'] !== '') {
                        $this->createExpenseSourceNoteMetadata($expense, $csvData['Source Note']);
                    }

                    // Mark as synced
                    PocketExpenseUploadsData::where('id', $expenseData['id'])
                        ->update(['status' => 'synced']);

                    $processedCount++;

                    Log::debug('Expense synced successfully', [
                        'upload_id' => $this->uploadId,
                        'upload_data_id' => $expenseData['id'],
                        'expense_id' => $expense->id,
                        'line_number' => $expenseData['line_number']
                    ]);
                });
            } catch (Exception $e) {
                // Mark as failed
                PocketExpenseUploadsData::where('id', $expenseData['id'])
                    ->update(['status' => 'failed']);

                $failedCount++;

                Log::error('Expense sync failed', [
                    'upload_id' => $this->uploadId,
                    'upload_data_id' => $expenseData['id'],
                    'line_number' => $expenseData['line_number'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'processed' => $processedCount,
            'failed' => $failedCount
        ];
    }

    /**
     * Update upload status with timestamp tracking.
     *
     * @param string $status
     * @return void
     */
    public function updateUploadStatus(string $status): void
    {
        $updates = ['status' => $status];

        // Set processed_at timestamp when completed or failed
        if (in_array($status, ['completed', 'failed', 'sync_failed'])) {
            $updates['processed_at'] = now();
        }

        PocketExpenseFileUpload::where('id', $this->uploadId)->update($updates);

        Log::info('Upload status updated', [
            'upload_id' => $this->uploadId,
            'status' => $status
        ]);
    }

    /**
     * Convert CSV row data to PocketExpense model attributes.
     *
     * @param array $csvData
     * @param PocketExpenseFileUpload $upload
     * @return array
     */
    private function convertCSVToExpenseData(array $csvData, PocketExpenseFileUpload $upload): array
    {
        // Parse date from DD/MM/YYYY format
        $date = Carbon::createFromFormat('d/m/Y', $csvData['Date'])->format('Y-m-d');

        // Get expense type ID
        $expenseType = OptPocketExpenseType::where('option', $csvData['Expense Type'])->first();

        // Apply correct amount sign based on expense type
        $amount = abs((float) $csvData['Amount']);
        if ($expenseType && $expenseType->amount_sign === 'negative') {
            $amount = -$amount;
        }

        // Parse VAT percentage
        $vatAmount = null;
        if (!empty($csvData['VAT %']) && $csvData['VAT %'] !== '') {
            $vatPercentage = (float) str_replace('%', '', $csvData['VAT %']);
            $vatAmount = abs($amount) * ($vatPercentage / 100);
        }

        return [
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'user_id' => $upload->user_id,
            'client_id' => $upload->client_id,
            'date' => $date,
            'merchant_name' => trim($csvData['Merchant Name']),
            'merchant_description' => !empty($csvData['Description']) ? trim($csvData['Description']) : null,
            'expense_type' => $expenseType->id,
            'currency' => $csvData['Currency Code'],
            'amount' => $amount,
            'merchant_address' => !empty($csvData['Merchant Address']) ? trim($csvData['Merchant Address']) : null,
            'vat_amount' => $vatAmount,
            'notes' => !empty($csvData['Notes']) ? trim($csvData['Notes']) : null,
            'status' => 'submitted', // Default status for CSV uploads
            'created_by_user_id' => $upload->created_by_user_id,
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Create expense source metadata for the expense.
     *
     * @param PocketExpense $expense
     * @param array $csvData
     * @param int $clientId
     * @return void
     */
    private function createExpenseSourceMetadata(PocketExpense $expense, array $csvData, int $clientId): void
    {
        $sourceName = $csvData['Source'];

        // Find the expense source
        $source = PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->where('name', $sourceName)
            ->first();

        if ($source) {
            PocketExpenseMetadata::create([
                'pocket_expense_id' => $expense->id,
                'metadata_type' => 'expense_source',
                'expense_source_id' => $source->id,
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => null,
                'deleted' => 0,
                'delete_time' => null,
                'create_time' => now(),
                'update_time' => now(),
            ]);
        }
    }

    /**
     * Create source note metadata for 'Other' source type.
     *
     * @param PocketExpense $expense
     * @param string $sourceNote
     * @return void
     */
    private function createExpenseSourceNoteMetadata(PocketExpense $expense, string $sourceNote): void
    {
        PocketExpenseMetadata::create([
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'expense_source_id' => null,
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'additional_field_id' => null,
            'user_id' => null,
            'details_json' => [
                'source_note' => trim($sourceNote),
                'source_type' => 'other'
            ],
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ]);
    }

    /**
     * Handle job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error('ProcessExpenseUpload job failed permanently', [
            'upload_id' => $this->uploadId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->updateUploadStatus('failed');

        // TODO: Send failure notification to admin user
        // Decision needed on notification channel preference
    }
}