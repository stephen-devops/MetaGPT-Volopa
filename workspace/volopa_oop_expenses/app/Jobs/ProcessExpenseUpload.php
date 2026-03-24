<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\User;
use App\Models\Client;
use App\Services\FXConversionService;
use App\Services\PocketExpenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use Throwable;

/**
 * Background job to process CSV expense upload data.
 * Handles the asynchronous creation of PocketExpense records from validated CSV data.
 * 
 * This job is dispatched after successful CSV validation and processes the staging data
 * in pocket_expense_uploads_data table to create actual expense records with metadata.
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
     * The maximum number of seconds the job can run.
     *
     * @var int
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     *
     * @param int $uploadId
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
        
        // Set queue name based on priority (expense processing should be high priority)
        $this->onQueue('expense-processing');
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        Log::info("Starting ProcessExpenseUpload job", [
            'upload_id' => $this->uploadId,
            'attempt' => $this->attempts()
        ]);

        try {
            // Load the upload record
            $upload = PocketExpenseFileUpload::find($this->uploadId);
            
            if (!$upload) {
                Log::error("Upload record not found", ['upload_id' => $this->uploadId]);
                throw new Exception("Upload record not found: {$this->uploadId}");
            }

            // Verify upload is in correct status for processing
            if (!in_array($upload->status, ['validation_passed', 'processing'])) {
                Log::warning("Upload not ready for processing", [
                    'upload_id' => $this->uploadId,
                    'status' => $upload->status
                ]);
                return;
            }

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Get all pending upload data rows
            $uploadDataRows = PocketExpenseUploadsData::where('upload_id', $this->uploadId)
                ->where('status', 'pending')
                ->orderBy('line_number')
                ->get();

            if ($uploadDataRows->isEmpty()) {
                Log::warning("No pending data rows found for processing", ['upload_id' => $this->uploadId]);
                $this->updateUploadStatus('completed');
                return;
            }

            Log::info("Processing upload data rows", [
                'upload_id' => $this->uploadId,
                'row_count' => $uploadDataRows->count()
            ]);

            // Process all rows in a single transaction for atomicity
            DB::transaction(function () use ($uploadDataRows, $upload) {
                $this->syncExpenseBatch($uploadDataRows, $upload);
            });

            // Update upload status to completed
            $this->updateUploadStatus('completed', now());

            Log::info("ProcessExpenseUpload job completed successfully", [
                'upload_id' => $this->uploadId,
                'processed_rows' => $uploadDataRows->count()
            ]);

        } catch (Throwable $exception) {
            Log::error("ProcessExpenseUpload job failed", [
                'upload_id' => $this->uploadId,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            // Update upload status to failed
            $this->updateUploadStatus('sync_failed');

            // Re-throw to trigger job retry mechanism
            throw $exception;
        }
    }

    /**
     * Process a batch of expense data rows and create PocketExpense records.
     *
     * @param \Illuminate\Database\Eloquent\Collection $uploadDataRows
     * @param PocketExpenseFileUpload $upload
     * @return void
     * @throws Exception
     */
    private function syncExpenseBatch($uploadDataRows, PocketExpenseFileUpload $upload): void
    {
        $processedCount = 0;
        $failedCount = 0;

        // Preload reference data for performance
        $expenseTypes = OptPocketExpenseType::where('is_active', true)
            ->pluck('id', 'option')
            ->toArray();

        $expenseSources = PocketExpenseSourceClientConfig::where(function ($query) use ($upload) {
                $query->where('client_id', $upload->client_id)
                      ->orWhereNull('client_id'); // Include global sources like 'Other'
            })
            ->where('deleted', false)
            ->pluck('id', 'name')
            ->toArray();

        foreach ($uploadDataRows as $uploadDataRow) {
            try {
                // Update row status to processing
                $uploadDataRow->update(['status' => 'processing']);

                // Parse the expense data
                $expenseData = $uploadDataRow->expense_data;
                
                // Create the main expense record
                $expense = $this->createExpenseFromData($expenseData, $upload, $expenseTypes);

                // Create metadata if expense source is specified
                if (!empty($expenseData['Source']) && isset($expenseSources[$expenseData['Source']])) {
                    $this->createExpenseMetadata($expense, $expenseData, $expenseSources, $upload);
                }

                // Update row status to synced
                $uploadDataRow->update([
                    'status' => 'synced',
                    'error_message' => null
                ]);

                $processedCount++;

            } catch (Throwable $exception) {
                Log::error("Failed to process upload data row", [
                    'upload_id' => $this->uploadId,
                    'line_number' => $uploadDataRow->line_number,
                    'error' => $exception->getMessage()
                ]);

                // Update row status to failed with error message
                $uploadDataRow->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage()
                ]);

                $failedCount++;
            }
        }

        Log::info("Batch processing completed", [
            'upload_id' => $this->uploadId,
            'processed' => $processedCount,
            'failed' => $failedCount,
            'total' => $uploadDataRows->count()
        ]);

        // If any rows failed, throw exception to mark the entire job as failed
        if ($failedCount > 0) {
            throw new Exception("Failed to process {$failedCount} out of {$uploadDataRows->count()} rows");
        }
    }

    /**
     * Create a PocketExpense record from CSV data.
     *
     * @param array $expenseData
     * @param PocketExpenseFileUpload $upload
     * @param array $expenseTypes
     * @return PocketExpense
     * @throws Exception
     */
    private function createExpenseFromData(array $expenseData, PocketExpenseFileUpload $upload, array $expenseTypes): PocketExpense
    {
        // Parse and validate date (expecting DD/MM/YYYY format from CSV)
        $expenseDate = $this->parseDate($expenseData['Date']);
        
        // Get expense type ID
        $expenseTypeOption = $expenseData['Expense Type'];
        if (!isset($expenseTypes[$expenseTypeOption])) {
            throw new Exception("Invalid expense type: {$expenseTypeOption}");
        }
        $expenseTypeId = $expenseTypes[$expenseTypeOption];

        // Get the expense type to determine amount sign
        $expenseType = OptPocketExpenseType::find($expenseTypeId);
        if (!$expenseType) {
            throw new Exception("Expense type not found: {$expenseTypeId}");
        }

        // Parse amount and apply correct sign based on expense type
        $amount = (float) $expenseData['Amount'];
        if ($expenseType->amount_sign === 'negative' && $amount > 0) {
            $amount = -$amount;
        } elseif ($expenseType->amount_sign === 'positive' && $amount < 0) {
            $amount = abs($amount);
        }

        // Parse VAT amount if provided
        $vatAmount = null;
        if (!empty($expenseData['VAT %'])) {
            $vatPercentage = str_replace('%', '', $expenseData['VAT %']);
            $vatAmount = (float) $vatPercentage;
        }

        // Apply FX conversion if needed
        $fxConversionService = app(FXConversionService::class);
        $fxData = $fxConversionService->convertAmount(
            $expenseData['Currency Code'],
            abs($amount), // Pass absolute value for FX calculation
            $expenseDate,
            $upload->client_id
        );

        // Create the expense record
        $expense = PocketExpense::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $upload->user_id,
            'client_id' => $upload->client_id,
            'date' => $expenseDate,
            'merchant_name' => substr($expenseData['Merchant Name'], 0, 180), // Enforce VARCHAR(180) limit
            'merchant_description' => $expenseData['Description'] ?? null,
            'expense_type' => $expenseTypeId,
            'currency' => strtoupper($expenseData['Currency Code']),
            'amount' => $amount,
            'merchant_address' => $expenseData['Merchant Address'] ?? null,
            'vat_amount' => $vatAmount,
            'notes' => $this->sanitizeNotes($expenseData['Notes'] ?? null),
            'status' => 'submitted', // CSV uploads go directly to submitted status
            'created_by_user_id' => $upload->created_by_user_id,
            'updated_by_user_id' => $upload->created_by_user_id,
            'approved_by_user_id' => null,
            'create_time' => now(),
            'update_time' => now(),
            'deleted' => false,
            'delete_time' => null,
        ]);

        Log::debug("Created expense record", [
            'expense_id' => $expense->id,
            'expense_uuid' => $expense->uuid,
            'upload_id' => $this->uploadId,
            'amount' => $amount,
            'currency' => $expense->currency
        ]);

        return $expense;
    }

    /**
     * Create expense metadata for the expense source.
     *
     * @param PocketExpense $expense
     * @param array $expenseData
     * @param array $expenseSources
     * @param PocketExpenseFileUpload $upload
     * @return void
     */
    private function createExpenseMetadata(PocketExpense $expense, array $expenseData, array $expenseSources, PocketExpenseFileUpload $upload): void
    {
        $sourceName = $expenseData['Source'];
        $expenseSourceId = $expenseSources[$sourceName];

        // Prepare details JSON for the metadata
        $detailsJson = [
            'import_source' => 'csv_upload',
            'import_date' => now()->toISOString(),
            'original_line_number' => $expenseData['_line_number'] ?? null,
        ];

        // Add source note if provided (required for 'Other' source)
        if (!empty($expenseData['Source Note'])) {
            $detailsJson['source_note'] = trim($expenseData['Source Note']);
        }

        // Create the metadata record
        PocketExpenseMetadata::create([
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => $expenseSourceId,
            'additional_field_id' => null,
            'user_id' => $upload->user_id,
            'details_json' => $detailsJson,
            'create_time' => now(),
            'update_time' => now(),
            'deleted' => false,
            'delete_time' => null,
        ]);

        Log::debug("Created expense metadata", [
            'expense_id' => $expense->id,
            'source_name' => $sourceName,
            'expense_source_id' => $expenseSourceId,
            'upload_id' => $this->uploadId
        ]);
    }

    /**
     * Parse date from DD/MM/YYYY format to Y-m-d format.
     *
     * @param string $dateString
     * @return string
     * @throws Exception
     */
    private function parseDate(string $dateString): string
    {
        // Handle both DD/MM/YYYY and DD-MM-YYYY formats
        $dateString = str_replace('-', '/', trim($dateString));
        
        try {
            $date = Carbon::createFromFormat('d/m/Y', $dateString);
            
            // Validate date is not older than 3 years
            $threeYearsAgo = Carbon::now()->subYears(3);
            if ($date->lt($threeYearsAgo)) {
                throw new Exception("Date is older than 3 years: {$dateString}");
            }
            
            return $date->format('Y-m-d');
            
        } catch (Throwable $exception) {
            throw new Exception("Invalid date format: {$dateString}. Expected DD/MM/YYYY");
        }
    }

    /**
     * Sanitize notes field to prevent SQL injection and enforce length limits.
     *
     * @param string|null $notes
     * @return string|null
     */
    private function sanitizeNotes(?string $notes): ?string
    {
        if (empty($notes)) {
            return null;
        }

        // Trim whitespace
        $notes = trim($notes);
        
        // Basic HTML/SQL injection prevention (strip tags)
        $notes = strip_tags($notes);
        
        // Enforce database TEXT field practical limit (adjust based on actual DB config)
        // Most MySQL TEXT fields can handle 65,535 characters, but we'll be conservative
        if (strlen($notes) > 5000) {
            $notes = substr($notes, 0, 5000);
        }
        
        return $notes;
    }

    /**
     * Update the upload record status.
     *
     * @param string $status
     * @param \Carbon\Carbon|null $processedAt
     * @return void
     */
    private function updateUploadStatus(string $status, ?Carbon $processedAt = null): void
    {
        $updateData = [
            'status' => $status,
            'updated_at' => now(),
        ];

        if ($processedAt) {
            $updateData['processed_at'] = $processedAt;
        }

        PocketExpenseFileUpload::where('id', $this->uploadId)->update($updateData);

        Log::debug("Updated upload status", [
            'upload_id' => $this->uploadId,
            'status' => $status,
            'processed_at' => $processedAt?->toISOString()
        ]);
    }

    /**
     * Handle job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error("ProcessExpenseUpload job failed permanently", [
            'upload_id' => $this->uploadId,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Update upload status to failed
        $this->updateUploadStatus('sync_failed');

        // Update all pending upload data rows to failed status
        PocketExpenseUploadsData::where('upload_id', $this->uploadId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_message' => 'Job failed after maximum retry attempts: ' . $exception->getMessage()
            ]);
    }

    /**
     * Determine if the job should be retried based on the exception.
     *
     * @param Throwable $exception
     * @return bool
     */
    public function retryUntil(): Carbon
    {
        // Retry for up to 1 hour from first attempt
        return now()->addHour();
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function backoff(): int
    {
        // Exponential backoff: 30 seconds, 60 seconds, 120 seconds
        return 30 * pow(2, $this->attempts() - 1);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'expense-upload',
            'upload:' . $this->uploadId,
            'processing'
        ];
    }

    /**
     * Get the unique ID for the job (prevent duplicate processing).
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return 'process-expense-upload-' . $this->uploadId;
    }

    /**
     * The unique lock will be released automatically when the job completes.
     * This prevents multiple instances of the same upload from being processed simultaneously.
     *
     * @return int
     */
    public function uniqueFor(): int
    {
        return 3600; // Lock for 1 hour
    }
}