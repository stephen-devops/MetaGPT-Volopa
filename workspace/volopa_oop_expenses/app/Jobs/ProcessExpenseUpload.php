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
 * Background job for processing validated CSV expense data.
 * Syncs validated expenses from pocket_expense_uploads_data to main pocket_expense table.
 * Processes in batches of 100 records as per constraints.
 */
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
     * Batch size for processing records.
     * As per constraints: "Queue job ProcessExpenseUpload syncs to main service in batches of 100"
     *
     * @var int
     */
    private int $batchSize = 100;

    /**
     * Create a new job instance.
     *
     * @param int $upload_id
     * @return void
     */
    public function __construct(int $upload_id)
    {
        $this->upload_id = $upload_id;
        $this->onQueue('expense-processing'); // As per constraints: queue name is 'expense-processing'
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info("Starting ProcessExpenseUpload job for upload ID: {$this->upload_id}");

            // Load the upload record
            $upload = PocketExpenseFileUpload::find($this->upload_id);
            
            if (!$upload) {
                Log::error("Upload record not found for ID: {$this->upload_id}");
                return;
            }

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Process upload data in batches
            $this->processBatches($upload);

            // Update final status and completion timestamp
            $this->updateUploadStatus('completed');
            $upload->update(['processed_at' => now()]);

            Log::info("Completed ProcessExpenseUpload job for upload ID: {$this->upload_id}");
            
        } catch (Exception $e) {
            Log::error("ProcessExpenseUpload job failed for upload ID: {$this->upload_id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update status to failed
            $this->updateUploadStatus('failed');
            
            // Re-throw the exception to mark job as failed
            throw $e;
        }
    }

    /**
     * Process upload data in batches.
     *
     * @param PocketExpenseFileUpload $upload
     * @return void
     */
    private function processBatches(PocketExpenseFileUpload $upload): void
    {
        $processedCount = 0;
        $totalProcessed = 0;

        // Get pending upload data in batches
        PocketExpenseUploadsData::where('upload_id', $this->upload_id)
            ->where('status', 'pending')
            ->orderBy('line_number')
            ->chunk($this->batchSize, function ($uploadDataBatch) use (&$processedCount, &$totalProcessed) {
                try {
                    // Convert upload data to expense collection
                    $expenses = $uploadDataBatch->map(function ($uploadData) {
                        return $this->convertUploadDataToExpense($uploadData);
                    });

                    // Sync expenses to main service
                    $this->syncExpensesToMainService($expenses);

                    // Mark batch as synced
                    $uploadDataIds = $uploadDataBatch->pluck('id')->toArray();
                    PocketExpenseUploadsData::whereIn('id', $uploadDataIds)
                        ->update(['status' => 'synced']);

                    $processedCount = $uploadDataBatch->count();
                    $totalProcessed += $processedCount;

                    Log::info("Processed batch of {$processedCount} records for upload ID: {$this->upload_id}. Total processed: {$totalProcessed}");

                } catch (Exception $e) {
                    Log::error("Failed to process batch for upload ID: {$this->upload_id}", [
                        'error' => $e->getMessage(),
                        'batch_size' => $uploadDataBatch->count()
                    ]);

                    // Mark batch as failed
                    $uploadDataIds = $uploadDataBatch->pluck('id')->toArray();
                    PocketExpenseUploadsData::whereIn('id', $uploadDataIds)
                        ->update(['status' => 'failed']);

                    // Update upload status to sync_failed
                    $this->updateUploadStatus('sync_failed');
                    
                    throw $e;
                }
            });

        Log::info("Completed processing all batches for upload ID: {$this->upload_id}. Total records: {$totalProcessed}");
    }

    /**
     * Convert upload data to expense array format.
     *
     * @param PocketExpenseUploadsData $uploadData
     * @return array
     */
    private function convertUploadDataToExpense(PocketExpenseUploadsData $uploadData): array
    {
        $expenseData = json_decode($uploadData->expense_data, true);
        $upload = PocketExpenseFileUpload::find($uploadData->upload_id);

        // TODO: Implement complete data conversion based on CSV column mapping
        // This is a placeholder implementation - real implementation should:
        // 1. Map CSV columns to database fields as per CSV_COLUMN_SCHEMA
        // 2. Apply amount sign based on expense type
        // 3. Convert date format from DD/MM/YYYY to Y-m-d
        // 4. Handle VAT percentage conversion
        // 5. Map source to expense_source_id
        // 6. Validate and clean all fields

        return [
            'user_id' => $upload->user_id,
            'client_id' => $upload->client_id,
            'date' => $this->convertDateFormat($expenseData['date'] ?? ''),
            'merchant_name' => $expenseData['merchant_name'] ?? '',
            'merchant_description' => $expenseData['description'] ?? null,
            'expense_type' => $this->getExpenseTypeId($expenseData['expense_type'] ?? ''),
            'currency' => $expenseData['currency_code'] ?? 'USD',
            'amount' => $this->calculateAmountWithSign($expenseData['amount'] ?? 0, $expenseData['expense_type'] ?? ''),
            'merchant_address' => $expenseData['merchant_address'] ?? null,
            'vat_amount' => $this->convertVatAmount($expenseData['vat_percent'] ?? null),
            'notes' => $expenseData['notes'] ?? null,
            'status' => 'submitted', // As per constraints: CSV uploads default to 'submitted' status
            'created_by_user_id' => $upload->created_by_user_id,
            'metadata' => $this->extractMetadata($expenseData),
        ];
    }

    /**
     * Sync expenses to main service.
     * Uses the PocketExpenseService to create expenses with proper validation and transactions.
     *
     * @param \Illuminate\Support\Collection $expenses
     * @return void
     */
    public function syncExpensesToMainService(\Illuminate\Support\Collection $expenses): void
    {
        $pocketExpenseService = app(PocketExpenseService::class);

        DB::transaction(function () use ($expenses, $pocketExpenseService) {
            foreach ($expenses as $expenseData) {
                try {
                    // Extract metadata before creating expense
                    $metadata = $expenseData['metadata'] ?? [];
                    unset($expenseData['metadata']);

                    // Create expense via service
                    $expense = $pocketExpenseService->createExpense(
                        $expenseData,
                        $expenseData['created_by_user_id']
                    );

                    // Attach metadata if present
                    if (!empty($metadata)) {
                        $pocketExpenseService->attachMetadata($expense, $metadata);
                    }

                } catch (Exception $e) {
                    Log::error("Failed to create expense during sync", [
                        'upload_id' => $this->upload_id,
                        'expense_data' => $expenseData,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }
        });
    }

    /**
     * Update the upload status.
     *
     * @param string $status
     * @return void
     */
    public function updateUploadStatus(string $status): void
    {
        try {
            PocketExpenseFileUpload::where('id', $this->upload_id)
                ->update(['status' => $status]);

            Log::info("Updated upload status to '{$status}' for upload ID: {$this->upload_id}");
            
        } catch (Exception $e) {
            Log::error("Failed to update upload status for upload ID: {$this->upload_id}", [
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Convert date from DD/MM/YYYY format to Y-m-d format.
     *
     * @param string $date
     * @return string
     */
    private function convertDateFormat(string $date): string
    {
        // TODO: Implement robust date conversion from DD/MM/YYYY to Y-m-d
        // This should handle various input formats and validate date constraints
        try {
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
                return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
            }
            return date('Y-m-d'); // Fallback to today if parsing fails
        } catch (Exception $e) {
            Log::warning("Date conversion failed for: {$date}", ['error' => $e->getMessage()]);
            return date('Y-m-d');
        }
    }

    /**
     * Get expense type ID from option name.
     *
     * @param string $expenseTypeOption
     * @return int
     */
    private function getExpenseTypeId(string $expenseTypeOption): int
    {
        // TODO: Implement lookup of expense type ID from opt_pocket_expense_type table
        // This should cache the lookup for performance during batch processing
        static $expenseTypeCache = [];
        
        if (isset($expenseTypeCache[$expenseTypeOption])) {
            return $expenseTypeCache[$expenseTypeOption];
        }

        // Placeholder - should query opt_pocket_expense_type table
        $expenseTypeCache[$expenseTypeOption] = 1; // Default to first type
        return 1;
    }

    /**
     * Calculate amount with proper sign based on expense type.
     *
     * @param float $amount
     * @param string $expenseType
     * @return float
     */
    private function calculateAmountWithSign(float $amount, string $expenseType): float
    {
        // TODO: Implement amount sign calculation based on expense type
        // Should lookup amount_sign from opt_pocket_expense_type and apply to amount
        // As per constraints: "Amount sign determined by expense type (Refund = positive, others = negative)"
        
        if ($expenseType === 'Refund from Merchant') {
            return abs($amount); // Positive for refunds
        }
        
        return -1 * abs($amount); // Negative for other expense types
    }

    /**
     * Convert VAT percentage to decimal amount.
     *
     * @param string|null $vatPercent
     * @return float|null
     */
    private function convertVatAmount(?string $vatPercent): ?float
    {
        if (empty($vatPercent)) {
            return null;
        }

        // TODO: Implement VAT percentage conversion
        // Should strip % sign and convert to decimal between 0-100
        // As per constraints: "VAT % must be numeric between 0-100 (strip % sign)"
        
        $cleaned = str_replace('%', '', trim($vatPercent));
        $value = floatval($cleaned);
        
        return ($value >= 0 && $value <= 100) ? $value : null;
    }

    /**
     * Extract metadata from expense data.
     *
     * @param array $expenseData
     * @return array
     */
    private function extractMetadata(array $expenseData): array
    {
        // TODO: Implement metadata extraction for expense source, notes, etc.
        // This should create appropriate metadata records for source, additional fields, etc.
        
        $metadata = [];
        
        // Extract source metadata
        if (!empty($expenseData['source'])) {
            $metadata[] = [
                'metadata_type' => 'expense_source',
                'details_json' => json_encode([
                    'source_name' => $expenseData['source'],
                    'source_note' => $expenseData['source_note'] ?? null
                ])
            ];
        }
        
        return $metadata;
    }

    /**
     * Handle a job failure.
     *
     * @param Exception $exception
     * @return void
     */
    public function failed(Exception $exception): void
    {
        Log::error("ProcessExpenseUpload job permanently failed for upload ID: {$this->upload_id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Update upload status to failed
        $this->updateUploadStatus('failed');

        // TODO: Implement notification mechanism for upload failure
        // As per UNCLEAR: notification channels not yet defined (DEC-UNRESOLVED-001)
        // Should notify the admin user who uploaded the file about the failure
    }
}