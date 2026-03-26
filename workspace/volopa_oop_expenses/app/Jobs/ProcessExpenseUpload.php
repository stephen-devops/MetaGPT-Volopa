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
 * Process Expense Upload Job
 * 
 * Background job for processing validated CSV upload data into main expense records.
 * Handles batch processing with proper error handling and status updates.
 * 
 * @property int $uploadId
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
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 300;

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
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info('Starting expense upload processing', ['upload_id' => $this->uploadId]);
            
            // Load the upload record
            $upload = PocketExpenseFileUpload::find($this->uploadId);
            
            if (!$upload) {
                Log::error('Upload record not found', ['upload_id' => $this->uploadId]);
                return;
            }

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Get all pending upload data for this upload
            $pendingData = PocketExpenseUploadsData::where('upload_id', $this->uploadId)
                ->where('status', 'pending')
                ->get();

            if ($pendingData->isEmpty()) {
                Log::warning('No pending data found for upload', ['upload_id' => $this->uploadId]);
                $this->updateUploadStatus('completed');
                return;
            }

            // Process expenses in batches
            $this->syncExpensesToMainService($pendingData);

            // Update upload status to completed
            $this->updateUploadStatus('completed');
            
            // Mark upload as processed
            $upload->processed_at = now();
            $upload->save();

            // Notify user of completion
            $this->notifyUser();

            Log::info('Expense upload processing completed successfully', [
                'upload_id' => $this->uploadId,
                'processed_count' => $pendingData->count()
            ]);

        } catch (Exception $e) {
            Log::error('Error processing expense upload', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update upload status to failed
            $this->updateUploadStatus('failed');
            
            // Re-throw to trigger job failure handling
            throw $e;
        }
    }

    /**
     * Sync validated expenses to main service.
     * Processes expenses in batches to avoid memory issues.
     *
     * @param \Illuminate\Support\Collection $expenses
     * @return void
     */
    private function syncExpensesToMainService(\Illuminate\Support\Collection $expenses): void
    {
        $batchSize = 100;
        $processed = 0;
        $failed = 0;

        Log::info('Starting expense sync to main service', [
            'upload_id' => $this->uploadId,
            'total_expenses' => $expenses->count(),
            'batch_size' => $batchSize
        ]);

        // Process expenses in batches
        $expenses->chunk($batchSize)->each(function ($batch) use (&$processed, &$failed) {
            try {
                DB::transaction(function () use ($batch, &$processed, &$failed) {
                    foreach ($batch as $uploadData) {
                        try {
                            $this->processIndividualExpense($uploadData);
                            $processed++;
                            
                            // Mark upload data as processed
                            $uploadData->status = 'processed';
                            $uploadData->save();
                            
                        } catch (Exception $e) {
                            $failed++;
                            Log::error('Failed to process individual expense', [
                                'upload_id' => $this->uploadId,
                                'line_number' => $uploadData->line_number,
                                'error' => $e->getMessage()
                            ]);
                            
                            // Mark upload data as failed
                            $uploadData->status = 'failed';
                            $uploadData->save();
                        }
                    }
                });
                
                Log::info('Processed batch successfully', [
                    'upload_id' => $this->uploadId,
                    'batch_size' => $batch->count(),
                    'total_processed' => $processed,
                    'total_failed' => $failed
                ]);
                
            } catch (Exception $e) {
                Log::error('Batch processing failed', [
                    'upload_id' => $this->uploadId,
                    'error' => $e->getMessage()
                ]);
                
                // Mark entire batch as failed
                $batch->each(function ($uploadData) {
                    $uploadData->status = 'failed';
                    $uploadData->save();
                });
                
                $failed += $batch->count();
            }
        });

        Log::info('Expense sync completed', [
            'upload_id' => $this->uploadId,
            'processed' => $processed,
            'failed' => $failed
        ]);
    }

    /**
     * Process an individual expense record.
     *
     * @param \App\Models\PocketExpenseUploadsData $uploadData
     * @return void
     * @throws \Exception
     */
    private function processIndividualExpense(PocketExpenseUploadsData $uploadData): void
    {
        $expenseData = json_decode($uploadData->expense_data, true);
        
        if (!$expenseData) {
            throw new Exception('Invalid expense data JSON');
        }

        // Get upload record to extract user and client information
        $upload = $uploadData->upload;
        
        if (!$upload) {
            throw new Exception('Upload record not found');
        }

        // Transform CSV data to expense creation format
        $expenseCreateData = $this->transformCSVDataToExpenseData($expenseData, $upload);

        // Create expense using PocketExpenseService
        $service = app(PocketExpenseService::class);
        $expense = $service->create($expenseCreateData);

        Log::debug('Individual expense processed successfully', [
            'upload_id' => $this->uploadId,
            'line_number' => $uploadData->line_number,
            'expense_id' => $expense->id
        ]);
    }

    /**
     * Transform CSV data to expense creation format.
     *
     * @param array $csvData
     * @param \App\Models\PocketExpenseFileUpload $upload
     * @return array
     */
    private function transformCSVDataToExpenseData(array $csvData, PocketExpenseFileUpload $upload): array
    {
        // TODO: Implement proper date parsing from DD/MM/YYYY format to database format
        $date = $this->parseCSVDate($csvData['date'] ?? '');

        // TODO: Implement expense type lookup by name to get ID
        $expenseTypeId = $this->getExpenseTypeIdByName($csvData['expense_type'] ?? '');

        // TODO: Implement amount sign adjustment based on expense type
        $amount = $this->adjustAmountByExpenseType($csvData['amount'] ?? 0, $csvData['expense_type'] ?? '');

        // TODO: Implement VAT amount calculation if VAT percentage is provided
        $vatAmount = $this->calculateVATAmount($csvData['vat_percent'] ?? null, $amount);

        return [
            'user_id' => $upload->user_id,
            'client_id' => $upload->client_id,
            'date' => $date,
            'merchant_name' => substr($csvData['merchant_name'] ?? '', 0, 180), // Ensure VARCHAR(180) limit
            'merchant_description' => $csvData['description'] ?? null,
            'expense_type' => $expenseTypeId,
            'currency' => $csvData['currency_code'] ?? 'USD',
            'amount' => $amount,
            'merchant_address' => $csvData['merchant_address'] ?? null,
            'vat_amount' => $vatAmount,
            'notes' => $csvData['notes'] ?? null,
            'status' => 'submitted', // Default status for batch uploaded expenses
            'created_by_user_id' => $upload->created_by_user_id,
            // TODO: Add metadata handling for source, source_note, etc.
        ];
    }

    /**
     * Parse CSV date format (DD/MM/YYYY) to database format.
     *
     * @param string $dateString
     * @return string
     */
    private function parseCSVDate(string $dateString): string
    {
        // TODO: Implement proper date parsing with validation
        // Expected format: DD/MM/YYYY or DD-MM-YYYY
        try {
            $date = \DateTime::createFromFormat('d/m/Y', $dateString);
            if (!$date) {
                $date = \DateTime::createFromFormat('d-m-Y', $dateString);
            }
            if (!$date) {
                throw new Exception('Invalid date format');
            }
            return $date->format('Y-m-d');
        } catch (Exception $e) {
            Log::error('Date parsing failed', ['date' => $dateString, 'error' => $e->getMessage()]);
            throw new Exception('Invalid date format: ' . $dateString);
        }
    }

    /**
     * Get expense type ID by name.
     *
     * @param string $expenseTypeName
     * @return int
     * @throws \Exception
     */
    private function getExpenseTypeIdByName(string $expenseTypeName): int
    {
        // TODO: Implement expense type lookup with caching
        // Should query opt_pocket_expense_type table by option field
        $expenseType = DB::table('opt_pocket_expense_type')
            ->where('option', $expenseTypeName)
            ->first();

        if (!$expenseType) {
            throw new Exception('Invalid expense type: ' . $expenseTypeName);
        }

        return $expenseType->id;
    }

    /**
     * Adjust amount sign based on expense type.
     *
     * @param float $amount
     * @param string $expenseTypeName
     * @return float
     */
    private function adjustAmountByExpenseType(float $amount, string $expenseTypeName): float
    {
        // TODO: Implement amount sign logic based on expense type
        // Refund from Merchant = positive, others = negative
        $expenseType = DB::table('opt_pocket_expense_type')
            ->where('option', $expenseTypeName)
            ->first();

        if ($expenseType && $expenseType->amount_sign === 'positive') {
            return abs($amount);
        } else {
            return -abs($amount);
        }
    }

    /**
     * Calculate VAT amount from percentage.
     *
     * @param string|null $vatPercent
     * @param float $amount
     * @return float|null
     */
    private function calculateVATAmount(?string $vatPercent, float $amount): ?float
    {
        if (empty($vatPercent)) {
            return null;
        }

        // TODO: Implement VAT calculation
        // Strip % sign if present and calculate VAT amount
        $percent = floatval(str_replace('%', '', $vatPercent));
        
        if ($percent < 0 || $percent > 100) {
            return null;
        }

        return abs($amount) * ($percent / 100);
    }

    /**
     * Update upload status.
     *
     * @param string $status
     * @return void
     */
    private function updateUploadStatus(string $status): void
    {
        try {
            DB::table('pocket_expense_file_uploads')
                ->where('id', $this->uploadId)
                ->update([
                    'status' => $status,
                    'updated_at' => now()
                ]);

            Log::info('Upload status updated', [
                'upload_id' => $this->uploadId,
                'status' => $status
            ]);
        } catch (Exception $e) {
            Log::error('Failed to update upload status', [
                'upload_id' => $this->uploadId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify user of processing completion.
     *
     * @return void
     */
    private function notifyUser(): void
    {
        try {
            // TODO: Implement user notification
            // The notification infrastructure existence is marked as UNKNOWN
            // This could be in-app notifications, email, push notifications, or a combination
            // For now, just log the notification intent
            
            Log::info('User notification should be sent', [
                'upload_id' => $this->uploadId,
                'message' => 'Expense upload processing completed successfully'
            ]);

            // TODO: Implement actual notification delivery based on platform preferences
            // Examples:
            // - app(NotificationService::class)->notifyUploadComplete($upload);
            // - Mail::to($user)->send(new ExpenseUploadCompleted($upload));
            // - Notification::send($user, new ExpenseUploadCompleted($upload));
            
        } catch (Exception $e) {
            Log::error('Failed to send user notification', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage()
            ]);
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
        Log::error('ProcessExpenseUpload job failed permanently', [
            'upload_id' => $this->uploadId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Update upload status to failed
        $this->updateUploadStatus('failed');

        // TODO: Notify user of processing failure
        Log::info('User should be notified of processing failure', [
            'upload_id' => $this->uploadId,
            'error' => 'Expense upload processing failed after all retry attempts'
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return ['expense-upload', 'upload:' . $this->uploadId];
    }
}