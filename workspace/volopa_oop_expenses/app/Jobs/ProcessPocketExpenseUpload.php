<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use App\Jobs\SyncPocketExpenseToMainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Exception;
use Throwable;

class ProcessPocketExpenseUpload implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process.
     *
     * @var int
     */
    private int $uploadId;

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
    public int $timeout = 3600; // 1 hour

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public int $uniqueFor = 3600;

    /**
     * Default batch size for processing expenses.
     *
     * @var int
     */
    private const DEFAULT_BATCH_SIZE = 100;

    /**
     * Maximum batch size allowed.
     *
     * @var int
     */
    private const MAX_BATCH_SIZE = 500;

    /**
     * Default timeout between batches in seconds.
     *
     * @var int
     */
    private const DEFAULT_BATCH_DELAY = 2;

    /**
     * Create a new job instance.
     *
     * @param int $uploadId
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
    }

    /**
     * Get the unique ID for the job.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return 'process_pocket_expense_upload_' . $this->uploadId;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        try {
            Log::info('Starting pocket expense upload processing', [
                'upload_id' => $this->uploadId,
                'job_id' => $this->job->getJobId() ?? 'unknown'
            ]);

            // Get and validate the upload record
            $upload = $this->getAndValidateUpload();
            if (!$upload) {
                return;
            }

            // Mark upload as processing
            $this->markUploadAsProcessing($upload);

            // Get processing configuration
            $config = $this->getProcessingConfiguration($upload);

            // Get valid data rows to process
            $dataRows = $this->getValidDataRows($upload);
            if ($dataRows->isEmpty()) {
                $this->completeUploadWithNoData($upload);
                return;
            }

            // Update total rows count
            $upload->updateRowCounts(totalRows: $dataRows->count());

            // Process data in batches
            $this->processDataInBatches($upload, $dataRows, $config);

            // Complete the upload
            $this->completeUpload($upload, $config);

            Log::info('Pocket expense upload processing completed successfully', [
                'upload_id' => $this->uploadId,
                'processed_rows' => $upload->processed_rows,
                'successful_rows' => $upload->successful_rows,
                'failed_rows' => $upload->failed_rows
            ]);

        } catch (Exception $e) {
            Log::error('Pocket expense upload processing failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->handleProcessingFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        try {
            Log::error('Pocket expense upload processing job failed permanently', [
                'upload_id' => $this->uploadId,
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            $upload = PocketExpenseFileUpload::find($this->uploadId);
            if ($upload) {
                $upload->markAsFailed([
                    'job_failed' => true,
                    'final_error' => $exception->getMessage(),
                    'attempts' => $this->attempts(),
                    'failed_at' => now()->toISOString()
                ]);

                // Send failure notification if email is configured
                $this->sendFailureNotification($upload, $exception);
            }

        } catch (Exception $e) {
            Log::error('Failed to handle job failure', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get and validate the upload record.
     *
     * @return PocketExpenseFileUpload|null
     */
    private function getAndValidateUpload(): ?PocketExpenseFileUpload
    {
        $upload = PocketExpenseFileUpload::find($this->uploadId);

        if (!$upload) {
            Log::error('Upload record not found', ['upload_id' => $this->uploadId]);
            return null;
        }

        if ($upload->status !== 'validating' && $upload->status !== 'processing') {
            Log::warning('Upload is not in a processable state', [
                'upload_id' => $this->uploadId,
                'current_status' => $upload->status
            ]);
            return null;
        }

        return $upload;
    }

    /**
     * Mark upload as processing.
     *
     * @param PocketExpenseFileUpload $upload
     * @return void
     */
    private function markUploadAsProcessing(PocketExpenseFileUpload $upload): void
    {
        $upload->markAsProcessing();
        
        Log::info('Upload marked as processing', [
            'upload_id' => $this->uploadId,
            'started_at' => $upload->started_at
        ]);
    }

    /**
     * Get processing configuration from upload record.
     *
     * @param PocketExpenseFileUpload $upload
     * @return array
     */
    private function getProcessingConfiguration(PocketExpenseFileUpload $upload): array
    {
        $processingOptions = $upload->processing_summary['processing_options'] ?? [];
        
        return [
            'batch_size' => min(
                max($processingOptions['batch_size'] ?? self::DEFAULT_BATCH_SIZE, 10), 
                self::MAX_BATCH_SIZE
            ),
            'batch_delay' => $processingOptions['batch_delay'] ?? self::DEFAULT_BATCH_DELAY,
            'auto_approve' => $processingOptions['auto_approve'] ?? false,
            'notification_email' => $processingOptions['notification_email'] ?? null,
            'sync_to_main_service' => $processingOptions['sync_to_main_service'] ?? false,
        ];
    }

    /**
     * Get valid data rows to process.
     *
     * @param PocketExpenseFileUpload $upload
     * @return \Illuminate\Support\Collection
     */
    private function getValidDataRows(PocketExpenseFileUpload $upload): \Illuminate\Support\Collection
    {
        return PocketExpenseUploadsData::forUpload($this->uploadId)
            ->valid()
            ->unprocessed()
            ->orderedByRow()
            ->get();
    }

    /**
     * Process data in batches.
     *
     * @param PocketExpenseFileUpload $upload
     * @param \Illuminate\Support\Collection $dataRows
     * @param array $config
     * @return void
     * @throws Exception
     */
    private function processDataInBatches(PocketExpenseFileUpload $upload, \Illuminate\Support\Collection $dataRows, array $config): void
    {
        $batchSize = $config['batch_size'];
        $batchDelay = $config['batch_delay'];
        $batches = $dataRows->chunk($batchSize);
        $batchNumber = 1;
        $totalBatches = $batches->count();

        Log::info('Starting batch processing', [
            'upload_id' => $this->uploadId,
            'total_rows' => $dataRows->count(),
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches
        ]);

        foreach ($batches as $batch) {
            try {
                Log::debug('Processing batch', [
                    'upload_id' => $this->uploadId,
                    'batch_number' => $batchNumber,
                    'batch_size' => $batch->count(),
                    'total_batches' => $totalBatches
                ]);

                $this->processBatch($upload, $batch, $config, $batchNumber);

                // Add delay between batches to avoid overwhelming the system
                if ($batchNumber < $totalBatches && $batchDelay > 0) {
                    sleep($batchDelay);
                }

                $batchNumber++;

            } catch (Exception $e) {
                Log::error('Batch processing failed', [
                    'upload_id' => $this->uploadId,
                    'batch_number' => $batchNumber,
                    'error' => $e->getMessage()
                ]);

                // Mark batch rows as failed
                $this->markBatchAsFailed($batch, $e->getMessage());
                
                // Continue with next batch unless it's a critical error
                if ($this->isCriticalError($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Process a single batch of data rows.
     *
     * @param PocketExpenseFileUpload $upload
     * @param \Illuminate\Support\Collection $batch
     * @param array $config
     * @param int $batchNumber
     * @return void
     * @throws Exception
     */
    private function processBatch(PocketExpenseFileUpload $upload, \Illuminate\Support\Collection $batch, array $config, int $batchNumber): void
    {
        $pocketExpenseService = new PocketExpenseService();
        $fxService = new FXConversionService();
        
        $successfulRows = [];
        $failedRows = [];

        foreach ($batch as $dataRow) {
            try {
                // Create pocket expense from data row
                $expenseData = $this->prepareExpenseData($dataRow, $upload, $fxService);
                
                $expense = DB::transaction(function () use ($pocketExpenseService, $expenseData, $upload, $dataRow, $config) {
                    // Create the expense
                    $expense = $pocketExpenseService->createExpense(
                        $expenseData,
                        $upload->user_id,
                        $upload->client_id
                    );

                    // Create metadata if available
                    $metadata = $this->prepareMetadataFromDataRow($dataRow, $expense->id);
                    if (!empty($metadata)) {
                        $this->createExpenseMetadata($expense->id, $metadata);
                    }

                    // Auto-approve if configured
                    if ($config['auto_approve']) {
                        $expense->approve($upload->created_by_user_id);
                    }

                    return $expense;
                });

                // Mark data row as processed
                $dataRow->markAsProcessed($expense->id);
                $successfulRows[] = $dataRow->id;

                // Dispatch sync job if configured
                if ($config['sync_to_main_service']) {
                    SyncPocketExpenseToMainService::dispatch($expense->id);
                }

            } catch (Exception $e) {
                Log::error('Failed to create expense from data row', [
                    'upload_id' => $this->uploadId,
                    'data_row_id' => $dataRow->id,
                    'row_number' => $dataRow->row_number,
                    'error' => $e->getMessage()
                ]);

                // Mark data row as failed
                $dataRow->markAsProcessingFailed($e->getMessage());
                $failedRows[] = $dataRow->id;
            }
        }

        // Update upload counters
        $upload->incrementSuccessfulRows(count($successfulRows));
        $upload->incrementFailedRows(count($failedRows));
        $upload->incrementProcessedRows(count($successfulRows) + count($failedRows));

        Log::debug('Batch processing completed', [
            'upload_id' => $this->uploadId,
            'batch_number' => $batchNumber,
            'successful_rows' => count($successfulRows),
            'failed_rows' => count($failedRows)
        ]);
    }

    /**
     * Prepare expense data from data row.
     *
     * @param PocketExpenseUploadsData $dataRow
     * @param PocketExpenseFileUpload $upload
     * @param FXConversionService $fxService
     * @return array
     * @throws Exception
     */
    private function prepareExpenseData(PocketExpenseUploadsData $dataRow, PocketExpenseFileUpload $upload, FXConversionService $fxService): array
    {
        $expenseData = [
            'date' => $dataRow->date,
            'merchant_name' => $dataRow->merchant_name,
            'amount' => $dataRow->getAmountAsFloat(),
            'currency' => $dataRow->currency,
            'description' => $dataRow->description,
            'project_code' => $dataRow->project_code,
            'cost_center' => $dataRow->cost_center,
            'status' => 'pending',
            'is_billable' => true,
        ];

        // Find expense type ID
        if ($dataRow->expense_type) {
            $expenseType = OptPocketExpenseType::where('name', $dataRow->expense_type)
                ->where('is_active', true)
                ->first();
            if ($expenseType) {
                $expenseData['expense_type_id'] = $expenseType->id;
            }
        }

        // Apply FX conversion if needed
        $baseCurrency = $fxService->getWalletBaseCurrency($upload->client_id);
        if ($dataRow->currency !== $baseCurrency) {
            try {
                $fxRate = $fxService->getFXRate($dataRow->currency, $baseCurrency, $dataRow->date);
                $commission = $fxService->getClientCommission($upload->client_id);
                $convertedAmount = $fxService->calculateConvertedAmount($dataRow->getAmountAsFloat(), $fxRate, $commission);

                $expenseData['converted_amount'] = $convertedAmount;
                $expenseData['converted_currency'] = $baseCurrency;
                $expenseData['fx_rate'] = $fxRate;
                $expenseData['fx_commission'] = $commission;
            } catch (Exception $e) {
                Log::warning('FX conversion failed, proceeding without conversion', [
                    'upload_id' => $this->uploadId,
                    'data_row_id' => $dataRow->id,
                    'from_currency' => $dataRow->currency,
                    'to_currency' => $baseCurrency,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expenseData;
    }

    /**
     * Prepare metadata from data row.
     *
     * @param PocketExpenseUploadsData $dataRow
     * @param int $expenseId
     * @return array
     */
    private function prepareMetadataFromDataRow(PocketExpenseUploadsData $dataRow, int $expenseId): array
    {
        $metadata = [];
        $sortOrder = 0;

        // Category metadata
        if ($dataRow->category) {
            $metadata[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'category',
                'value' => $dataRow->category,
                'label' => 'Category',
                'is_required' => false,
                'is_editable' => true,
                'sort_order' => $sortOrder++,
            ];
        }

        // Source metadata
        if ($dataRow->source) {
            $sourceConfig = PocketExpenseSourceClientConfig::where('client_id', $this->getUploadClientId())
                ->where('source_name', $dataRow->source)
                ->where('is_active', true)
                ->first();

            $metadata[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'source',
                'value' => $dataRow->source,
                'label' => 'Source',
                'source_id' => $sourceConfig ? $sourceConfig->id : null,
                'is_required' => false,
                'is_editable' => true,
                'sort_order' => $sortOrder++,
            ];
        }

        // Location metadata
        if ($dataRow->location) {
            $metadata[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'location',
                'value' => $dataRow->location,
                'label' => 'Location',
                'is_required' => false,
                'is_editable' => true,
                'sort_order' => $sortOrder++,
            ];
        }

        // Tax metadata
        if ($dataRow->tax_amount || $dataRow->tax_rate) {
            $taxDetails = [];
            if ($dataRow->tax_amount) {
                $taxDetails['tax_amount'] = $dataRow->getTaxAmountAsFloat();
            }
            if ($dataRow->tax_rate) {
                $taxDetails['tax_rate'] = $dataRow->getTaxRateAsFloat();
            }

            $metadata[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'tax',
                'details_json' => $taxDetails,
                'label' => 'Tax Information',
                'is_required' => false,
                'is_editable' => true,
                'sort_order' => $sortOrder++,
            ];
        }

        // Receipt reference metadata
        if ($dataRow->receipt_reference) {
            $metadata[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'custom',
                'value' => $dataRow->receipt_reference,
                'label' => 'Receipt Reference',
                'is_required' => false,
                'is_editable' => true,
                'sort_order' => $sortOrder++,
            ];
        }

        // Custom fields metadata
        $customFields = $dataRow->custom_fields ?? [];
        foreach ($customFields as $key => $value) {
            if (!empty($value)) {
                $metadata[] = [
                    'pocket_expense_id' => $expenseId,
                    'metadata_type' => 'custom',
                    'value' => $value,
                    'label' => ucfirst(str_replace('_', ' ', $key)),
                    'is_required' => false,
                    'is_editable' => true,
                    'sort_order' => $sortOrder++,
                ];
            }
        }

        return $metadata;
    }

    /**
     * Create expense metadata.
     *
     * @param int $expenseId
     * @param array $metadataItems
     * @return void
     * @throws Exception
     */
    private function createExpenseMetadata(int $expenseId, array $metadataItems): void
    {
        if (empty($metadataItems)) {
            return;
        }

        try {
            PocketExpenseMetadata::bulkCreateForExpense($expenseId, $metadataItems);
        } catch (Exception $e) {
            Log::warning('Failed to create expense metadata', [
                'expense_id' => $expenseId,
                'metadata_count' => count($metadataItems),
                'error' => $e->getMessage()
            ]);
            // Don't throw - expense creation succeeded, metadata is optional
        }
    }

    /**
     * Mark batch rows as failed.
     *
     * @param \Illuminate\Support\Collection $batch
     * @param string $error
     * @return void
     */
    private function markBatchAsFailed(\Illuminate\Support\Collection $batch, string $error): void
    {
        $rowIds = $batch->pluck('id')->toArray();
        PocketExpenseUploadsData::batchMarkAsProcessingFailed($rowIds, $error);
    }

    /**
     * Check if error is critical and should stop processing.
     *
     * @param Exception $exception
     * @return bool
     */
    private function isCriticalError(Exception $exception): bool
    {
        // Database connection errors
        if (strpos($exception->getMessage(), 'database') !== false) {
            return true;
        }

        // Memory exhaustion
        if (strpos($exception->getMessage(), 'memory') !== false) {
            return true;
        }

        // File system errors
        if (strpos($exception->getMessage(), 'permission') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Complete upload with no data.
     *
     * @param PocketExpenseFileUpload $upload
     * @return void
     */
    private function completeUploadWithNoData(PocketExpenseFileUpload $upload): void
    {
        $upload->markAsCompleted([
            'message' => 'No valid data rows found to process',
            'completed_at' => now()->toISOString()
        ]);

        Log::info('Upload completed with no data to process', [
            'upload_id' => $this->uploadId
        ]);
    }

    /**
     * Complete the upload processing.
     *
     * @param PocketExpenseFileUpload $upload
     * @param array $config
     * @return void
     */
    private function completeUpload(PocketExpenseFileUpload $upload, array $config): void
    {
        $summary = [
            'processing_completed_at' => now()->toISOString(),
            'total_rows_processed' => $upload->processed_rows,
            'successful_rows' => $upload->successful_rows,
            'failed_rows' => $upload->failed_rows,
            'success_rate' => $upload->processed_rows > 0 ? 
                round(($upload->successful_rows / $upload->processed_rows) * 100, 2) : 0,
            'processing_duration_seconds' => $upload->getProcessingDuration(),
            'auto_approved' => $config['auto_approve'],
            'synced_to_main_service' => $config['sync_to_main_service'],
        ];

        $upload->markAsCompleted($summary);

        // Send completion notification
        $this->sendCompletionNotification($upload, $config, $summary);

        Log::info('Upload processing completed', [
            'upload_id' => $this->uploadId,
            'summary' => $summary
        ]);
    }

    /**
     * Handle processing failure.
     *
     * @param Exception $exception
     * @return void
     */
    private function handleProcessingFailure(Exception $exception): void
    {
        try {
            $upload = PocketExpenseFileUpload::find($this->uploadId);
            if ($upload) {
                $errors = [
                    'processing_failed' => true,
                    'error_message' => $exception->getMessage(),
                    'attempt' => $this->attempts(),
                    'failed_at' => now()->toISOString()
                ];

                if ($this->attempts() >= $this->tries) {
                    $upload->markAsFailed($errors);
                } else {
                    $upload->addValidationErrors($errors);
                }
            }
        } catch (Exception $e) {
            Log::error('Failed to handle processing failure', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send completion notification.
     *
     * @param PocketExpenseFileUpload $upload
     * @param array $config
     * @param array $summary
     * @return void
     */
    private function sendCompletionNotification(PocketExpenseFileUpload $upload, array $config, array $summary): void
    {
        try {
            $email = $config['notification_email'];
            if (!$email) {
                return;
            }

            // For simplicity, just log the notification
            // In a real implementation, you would send an actual email
            Log::info('Sending completion notification', [
                'upload_id' => $this->uploadId,
                'email' => $email,
                'summary' => $summary
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send completion notification', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send failure notification.
     *
     * @param PocketExpenseFileUpload $upload
     * @param Throwable $exception
     * @return void
     */
    private function sendFailureNotification(PocketExpenseFileUpload $upload, Throwable $exception): void
    {
        try {
            $processingOptions = $upload->processing_summary['processing_options'] ?? [];
            $email = $processingOptions['notification_email'] ?? null;
            
            if (!$email) {
                return;
            }

            // For simplicity, just log the notification
            // In a real implementation, you would send an actual email
            Log::info('Sending failure notification', [
                'upload_id' => $this->uploadId,
                'email' => $email,
                'error' => $exception->getMessage(),
                'attempts' => $this->attempts()
            ]);

        } catch (Exception $e) {
            Log::error('Failed to send failure notification', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the client ID from upload.
     *
     * @return int
     */
    private function getUploadClientId(): int
    {
        $upload = PocketExpenseFileUpload::find($this->uploadId);
        return $upload ? $upload->client_id : 0;
    }

    /**
     * Calculate processing progress and update upload.
     *
     * @param PocketExpenseFileUpload $upload
     * @return void
     */
    private function updateProgress(PocketExpenseFileUpload $upload): void
    {
        try {
            $progress = $upload->getProgressPercentage();
            $estimatedTimeRemaining = $upload->getEstimatedTimeRemaining();

            $summary = $upload->processing_summary ?? [];
            $summary['progress_percentage'] = $progress;
            $summary['estimated_time_remaining_seconds'] = $estimatedTimeRemaining;
            $summary['last_updated'] = now()->toISOString();

            $upload->updateProcessingSummary($summary);

        } catch (Exception $e) {
            Log::error('Failed to update progress', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up temporary resources.
     *
     * @return void
     */
    private function cleanup(): void
    {
        try {
            // Clean up any temporary files or resources
            // For now, just log that cleanup is happening
            Log::debug('Cleaning up resources for upload processing', [
                'upload_id' => $this->uploadId
            ]);

        } catch (Exception $e) {
            Log::error('Failed to cleanup resources', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get job tags for monitoring.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'pocket-expense-upload',
            'upload-id:' . $this->uploadId,
            'processing'
        ];
    }

    /**
     * Get the job display name.
     *
     * @return string
     */
    public function displayName(): string
    {
        return 'Process Pocket Expense Upload #' . $this->uploadId;
    }
}