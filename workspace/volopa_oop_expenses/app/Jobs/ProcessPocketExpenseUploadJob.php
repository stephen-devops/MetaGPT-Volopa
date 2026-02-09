## Code: app/Jobs/ProcessPocketExpenseUploadJob.php

```php
<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\OopExpense;
use App\Services\ExpenseService;
use App\Services\PocketExpenseCSVValidator;
use App\Services\FxConversionService;
use App\Models\OptPocketExpenseType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use League\Csv\Statement;
use Carbon\Carbon;

class ProcessPocketExpenseUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process.
     */
    private int $uploadId;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600;

    /**
     * Batch size for processing records.
     */
    private const BATCH_SIZE = 100;

    /**
     * CSV delimiter character.
     */
    private const CSV_DELIMITER = ',';

    /**
     * CSV enclosure character.
     */
    private const CSV_ENCLOSURE = '"';

    /**
     * CSV escape character.
     */
    private const CSV_ESCAPE = '\\';

    /**
     * Create a new job instance.
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
        $this->queue = 'pocket-expense-processing';
        $this->delay = now()->addSeconds(2);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting pocket expense upload processing', [
                'upload_id' => $this->uploadId,
                'job_id' => $this->job->getJobId(),
            ]);

            // Get the upload record
            $upload = PocketExpenseFileUpload::find($this->uploadId);

            if (!$upload) {
                Log::error('Upload record not found', [
                    'upload_id' => $this->uploadId,
                ]);
                $this->fail('Upload record not found');
                return;
            }

            // Check if upload is in correct status
            if (!in_array($upload->status, [
                PocketExpenseFileUpload::STATUS_VALIDATING,
                PocketExpenseFileUpload::STATUS_PROCESSING
            ])) {
                Log::warning('Upload not in processable status', [
                    'upload_id' => $this->uploadId,
                    'status' => $upload->status,
                ]);
                return;
            }

            // Mark as processing if not already
            if ($upload->status !== PocketExpenseFileUpload::STATUS_PROCESSING) {
                $upload->markAsProcessing();
            }

            // Process the upload
            $this->processUpload($upload);

            Log::info('Pocket expense upload processing completed', [
                'upload_id' => $this->uploadId,
                'total_records' => $upload->total_records,
                'processed_records' => $upload->processed_records,
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing pocket expense upload', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailure($e);
        }
    }

    /**
     * Process the upload by validating and creating expenses.
     */
    private function processUpload(PocketExpenseFileUpload $upload): void
    {
        DB::transaction(function () use ($upload) {
            // Initialize services
            $fxService = new FxConversionService();
            $expenseService = new ExpenseService($fxService);
            $validator = new PocketExpenseCSVValidator(
                $upload->expense_user_id,
                $upload->client_id,
                $upload->user_id
            );

            // Get the CSV file path
            $filePath = storage_path('app/' . $upload->file_path);

            if (!file_exists($filePath)) {
                throw new \RuntimeException('Upload file not found: ' . $filePath);
            }

            // Validate CSV structure and content
            $validationResult = $validator->validateCsv($filePath);

            if (!$validationResult['success']) {
                $upload->markAsFailed('CSV validation failed');
                $upload->addValidationErrors($validationResult['errors']);
                return;
            }

            // Update validation results
            $upload->updateRecordCounts(
                $validationResult['total_rows'],
                $validationResult['valid_rows'],
                $validationResult['total_rows'] - $validationResult['valid_rows']
            );

            // Process valid records in batches
            $this->processValidRecords($upload, $expenseService, $filePath);

            // Mark upload as completed
            $upload->markAsCompleted();
        });
    }

    /**
     * Process valid CSV records by creating expense entries.
     */
    private function processValidRecords(
        PocketExpenseFileUpload $upload,
        ExpenseService $expenseService,
        string $filePath
    ): void {
        try {
            // Read CSV file
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter(self::CSV_DELIMITER);
            $csv->setEnclosure(self::CSV_ENCLOSURE);
            $csv->setEscape(self::CSV_ESCAPE);

            $statement = Statement::create();
            $records = $statement->process($csv);

            $processedCount = 0;
            $batch = [];

            foreach ($records as $rowIndex => $record) {
                $rowNumber = $rowIndex + 1;

                // Skip empty rows
                if (array_filter($record, function($value) { return trim($value) !== ''; }) === []) {
                    continue;
                }

                $batch[] = [
                    'row_number' => $rowNumber,
                    'record' => $record,
                ];

                // Process batch when it reaches the batch size
                if (count($batch) >= self::BATCH_SIZE) {
                    $processedCount += $this->processBatch($batch, $upload, $expenseService);
                    $batch = [];

                    // Update progress
                    $upload->updateRecordCounts(null, null, null, $processedCount);
                }
            }

            // Process remaining records
            if (!empty($batch)) {
                $processedCount += $this->processBatch($batch, $upload, $expenseService);
            }

            // Final progress update
            $upload->updateRecordCounts(null, null, null, $processedCount);

            Log::info('All records processed', [
                'upload_id' => $upload->id,
                'total_processed' => $processedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing valid records', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process a batch of CSV records.
     */
    private function processBatch(
        array $batch,
        PocketExpenseFileUpload $upload,
        ExpenseService $expenseService
    ): int {
        $processedCount = 0;

        foreach ($batch as $item) {
            try {
                $expenseData = $this->convertCsvRowToExpenseData($item['record'], $upload);
                
                if ($expenseData) {
                    // Create the expense
                    $user = $upload->expenseUser;
                    $expense = $expenseService->createExpense($expenseData, $user);

                    // Update upload data record
                    $this->updateUploadDataRecord(
                        $upload->id,
                        $item['row_number'],
                        PocketExpenseUploadsData::STATUS_PROCESSED,
                        $expense->id
                    );

                    $processedCount++;

                    Log::debug('Expense created from CSV row', [
                        'upload_id' => $upload->id,
                        'row_number' => $item['row_number'],
                        'expense_id' => $expense->id,
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('Error processing CSV row', [
                    'upload_id' => $upload->id,
                    'row_number' => $item['row_number'],
                    'error' => $e->getMessage(),
                ]);

                // Mark row as failed
                $this->updateUploadDataRecord(
                    $upload->id,
                    $item['row_number'],
                    PocketExpenseUploadsData::STATUS_FAILED,
                    null,
                    'Error creating expense: ' . $e->getMessage()
                );
            }
        }

        return $processedCount;
    }

    /**
     * Convert CSV row to expense data array.
     */
    private function convertCsvRowToExpenseData(array $record, PocketExpenseFileUpload $upload): ?array
    {
        try {
            // Map CSV columns to expense fields
            $expenseData = [
                'user_id' => $upload->expense_user_id,
                'client_id' => $upload->client_id,
                'date' => $this->parseDate($record['Date'] ?? ''),
                'merchant_name' => trim($record['Merchant Name'] ?? ''),
                'description' => trim($record['Description'] ?? '') ?: null,
                'transaction_type' => trim($record['Expense Type'] ?? ''),
                'currency' => strtoupper(trim($record['Currency Code'] ?? '')),
                'amount' => $this->parseAmount($record['Amount'] ?? '', $record['Expense Type'] ?? ''),
                'merchant_address' => trim($record['Merchant Address'] ?? '') ?: null,
                'country' => trim($record['Merchant Country'] ?? '') ?: null,
                'source' => trim($record['Source'] ?? '') ?: null,
                'notes' => trim($record['Notes'] ?? '') ?: null,
                'status' => OopExpense::STATUS_PENDING,
            ];

            // Handle VAT percentage
            if (!empty($record['VAT %'])) {
                $vatString = trim($record['VAT %']);
                $vatString = str_replace('%', '', $vatString);
                $vatValue = floatval($vatString);
                if ($vatValue > 0 && $vatValue <= 100) {
                    $expenseData['vat'] = $vatValue;
                }
            }

            // Handle currency equivalent amount
            if (!empty($record['Currency Equivalent Amount'])) {
                $convertedAmount = $this->parseConvertedAmount(
                    $record['Currency Equivalent Amount'],
                    $record['Expense Type'] ?? ''
                );
                if ($convertedAmount !== null) {
                    // Store as custom field for now
                    $expenseData['custom_fields'] = [
                        'user_converted_amount' => $convertedAmount
                    ];
                }
            }

            // Handle source note
            if (!empty($record['Source Note']) && 
                !empty($expenseData['source']) && 
                strtolower($expenseData['source']) === 'other') {
                $expenseData['custom_fields'] = $expenseData['custom_fields'] ?? [];
                $expenseData['custom_fields']['source_note'] = trim($record['Source Note']);
            }

            return $expenseData;

        } catch (\Exception $e) {
            Log::error('Error converting CSV row to expense data', [
                'upload_id' => $upload->id,
                'record' => $record,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse date from CSV format.
     */
    private function parseDate(string $dateString): string
    {
        $dateString = trim($dateString);
        
        if (empty($dateString)) {
            throw new \InvalidArgumentException('Date is required');
        }

        try {
            // Try DD/MM/YYYY format first
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateString, $matches)) {
                $day = intval($matches[1]);
                $month = intval($matches[2]);
                $year = intval($matches[3]);
                
                $date = Carbon::createFromDate($year, $month, $day);
                return $date->format('Y-m-d');
            }

            // Try other common formats
            $date = Carbon::parse($dateString);
            return $date->format('Y-m-d');

        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format: ' . $dateString);
        }
    }

    /**
     * Parse amount and apply transaction type sign.
     */
    private function parseAmount(string $amountString, string $transactionType): float
    {
        $amountString = trim($amountString);
        
        if (empty($amountString)) {
            throw new \InvalidArgumentException('Amount is required');
        }

        // Remove currency symbols and commas
        $amountString = preg_replace('/[^\d.-]/', '', $amountString);
        
        $amount = floatval($amountString);
        
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero');
        }

        // Apply transaction type sign
        $expenseType = OptPocketExpenseType::findActiveByOption($transactionType);
        if ($expenseType) {
            return $expenseType->getSignedAmount($amount);
        }

        // Default to negative (expense)
        return -abs($amount);
    }

    /**
     * Parse converted amount and apply transaction type sign.
     */
    private function parseConvertedAmount(string $amountString, string $transactionType): ?float
    {
        $amountString = trim($amountString);
        
        if (empty($amountString)) {
            return null;
        }

        try {
            // Remove currency symbols and commas
            $amountString = preg_replace('/[^\d.-]/', '', $amountString);
            
            $amount = floatval($amountString);
            
            if ($amount <= 0) {
                return null;
            }

            // Apply transaction type sign
            $expenseType = OptPocketExpenseType::findActiveByOption($transactionType);
            if ($expenseType) {
                return $expenseType->getSignedAmount($amount);
            }

            // Default to negative (expense)
            return -abs($amount);

        } catch (\Exception $e) {
            Log::warning('Error parsing converted amount', [
                'amount_string' => $amountString,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update upload data record status.
     */
    private function updateUploadDataRecord(
        int $uploadId,
        int $rowNumber,
        string $status,
        ?int $createdExpenseId = null,
        ?string $notes = null
    ): void {
        try {
            $uploadData = PocketExpenseUploadsData::where('file_upload_id', $uploadId)
                ->where('row_number', $rowNumber)
                ->first();

            if ($uploadData) {
                $updateData = [
                    'row_