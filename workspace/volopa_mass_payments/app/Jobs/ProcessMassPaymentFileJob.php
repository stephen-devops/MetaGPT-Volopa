## Code: app/Jobs/ProcessMassPaymentFileJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Services\CsvValidationService;
use App\Services\PaymentProcessingService;
use App\Notifications\FileApprovalRequiredNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;
use Carbon\Carbon;

class ProcessMassPaymentFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The mass payment file to process.
     */
    public MassPaymentFile $massPaymentFile;

    /**
     * The CSV validation service instance.
     */
    private CsvValidationService $csvValidationService;

    /**
     * The payment processing service instance.
     */
    private PaymentProcessingService $paymentProcessingService;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 600;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Maximum number of rows to process in a single batch.
     */
    private const MAX_BATCH_SIZE = 500;

    /**
     * Maximum number of validation errors to store.
     */
    private const MAX_STORED_ERRORS = 200;

    /**
     * Processing status constants.
     */
    private const STATUS_UPLOADING = 'uploading';
    private const STATUS_UPLOADED = 'uploaded';
    private const STATUS_VALIDATING = 'validating';
    private const STATUS_VALIDATION_FAILED = 'validation_failed';
    private const STATUS_VALIDATED = 'validated';
    private const STATUS_PENDING_APPROVAL = 'pending_approval';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_PROCESSED = 'processed';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    /**
     * Create a new job instance.
     */
    public function __construct(MassPaymentFile $massPaymentFile)
    {
        $this->massPaymentFile = $massPaymentFile;
        $this->onQueue('mass-payments');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Inject dependencies
        $this->csvValidationService = app(CsvValidationService::class);
        $this->paymentProcessingService = app(PaymentProcessingService::class);

        Log::info('Starting mass payment file processing', [
            'job_id' => $this->job?->getJobId(),
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'current_status' => $this->massPaymentFile->status,
            'total_rows' => $this->massPaymentFile->total_rows
        ]);

        try {
            // Process based on current file status
            switch ($this->massPaymentFile->status) {
                case self::STATUS_UPLOADED:
                case self::STATUS_UPLOADING:
                    $this->processUploadedFile();
                    break;

                case self::STATUS_APPROVED:
                    $this->processApprovedFile();
                    break;

                case self::STATUS_PROCESSING:
                    $this->continueProcessing();
                    break;

                default:
                    Log::warning('File is not in a processable status', [
                        'file_id' => $this->massPaymentFile->id,
                        'status' => $this->massPaymentFile->status
                    ]);
                    return;
            }

            Log::info('Mass payment file processing completed successfully', [
                'file_id' => $this->massPaymentFile->id,
                'final_status' => $this->massPaymentFile->fresh()->status,
                'processing_duration' => $this->getProcessingDuration()
            ]);

        } catch (CsvException $e) {
            Log::error('CSV parsing error during processing', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage(),
                'job_id' => $this->job?->getJobId()
            ]);

            $this->handleProcessingError('CSV parsing error: ' . $e->getMessage());

        } catch (\Exception $e) {
            Log::error('Unexpected error during mass payment file processing', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job_id' => $this->job?->getJobId()
            ]);

            $this->handleProcessingError('Processing error: ' . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Mass payment file processing job failed permanently', [
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'job_id' => $this->job?->getJobId()
        ]);

        // Update file status to failed
        $this->massPaymentFile->updateStatus(self::STATUS_FAILED, [
            'processing_error' => 'Job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage(),
            'failed_at' => now()->toISOString()
        ]);

        // Notify relevant parties about the failure
        $this->notifyProcessingFailure($exception);
    }

    /**
     * Process an uploaded file (validation phase).
     */
    private function processUploadedFile(): void
    {
        Log::info('Processing uploaded file - starting validation', [
            'file_id' => $this->massPaymentFile->id
        ]);

        // Update status to validating
        $this->massPaymentFile->updateStatus(self::STATUS_VALIDATING);

        // Get file path
        $filePath = $this->getFullFilePath();

        // Validate CSV structure
        $structureValidation = $this->csvValidationService->validateCsvStructure($filePath);
        
        if (!$structureValidation['valid']) {
            $this->handleStructureValidationFailure($structureValidation);
            return;
        }

        // Update file with basic information
        $this->updateFileFromStructureValidation($structureValidation);

        // Validate individual rows and create payment instructions
        $this->processAndValidateRows($filePath);
    }

    /**
     * Process an approved file (payment execution phase).
     */
    private function processApprovedFile(): void
    {
        Log::info('Processing approved file - starting payment execution', [
            'file_id' => $this->massPaymentFile->id,
            'total_amount' => $this->massPaymentFile->total_amount,
            'currency' => $this->massPaymentFile->currency
        ]);

        DB::transaction(function () {
            // Update status to processing
            $this->massPaymentFile->updateStatus(self::STATUS_PROCESSING);

            // Get validated payment instructions
            $instructions = $this->massPaymentFile->paymentInstructions()
                                                 ->where('status', 'validated')
                                                 ->get();

            if ($instructions->isEmpty()) {
                Log::warning('No validated payment instructions found for approved file', [
                    'file_id' => $this->massPaymentFile->id
                ]);
                
                $this->massPaymentFile->updateStatus(self::STATUS_FAILED, [
                    'processing_error' => 'No validated payment instructions found'
                ]);
                return;
            }

            // Process payments
            $processingResults = $this->executePayments($instructions);

            // Update file status based on results
            $this->updateFileAfterPaymentProcessing($processingResults);
        });
    }

    /**
     * Continue processing if job was interrupted.
     */
    private function continueProcessing(): void
    {
        Log::info('Continuing interrupted processing', [
            'file_id' => $this->massPaymentFile->id
        ]);

        // Check if we have payment instructions that need processing
        $pendingInstructions = $this->massPaymentFile->paymentInstructions()
                                                    ->whereIn('status', ['validated', 'processing'])
                                                    ->get();

        if ($pendingInstructions->isNotEmpty()) {
            $processingResults = $this->executePayments($pendingInstructions);
            $this->updateFileAfterPaymentProcessing($processingResults);
        } else {
            // Check current state and determine next action
            $this->determineNextAction();
        }
    }

    /**
     * Process and validate CSV rows, creating payment instructions.
     */
    private function processAndValidateRows(string $filePath): void
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $totalRows = 0;
        $validRows = 0;
        $errorRows = 0;
        $allErrors = [];
        $allWarnings = [];
        $totalAmount = 0.0;
        $currency = null;

        Log::info('Starting row processing and validation', [
            'file_id' => $this->massPaymentFile->id
        ]);

        DB::transaction(function () use ($csv, &$totalRows, &$validRows, &$errorRows, &$allErrors, &$allWarnings, &$totalAmount, &$currency) {
            $batch = [];
            $batchNumber = 1;

            foreach ($csv->getRecords() as $rowNumber => $record) {
                $totalRows++;
                $actualRowNumber = $rowNumber + 2; // Account for 0-based index and header row

                // Validate row data
                $rowValidation = $this->csvValidationService->validateRowData($record, $actualRowNumber);

                if ($rowValidation['valid']) {
                    // Prepare for payment instruction creation
                    $instructionData = $this->prepareInstructionData($record, $actualRowNumber);
                    
                    if ($instructionData) {
                        $batch[] = $instructionData;
                        $validRows++;
                        $totalAmount += $instructionData['amount'];
                        
                        // Set currency from first valid row
                        if (!$currency) {
                            $currency = $instructionData['currency'];
                        }
                    } else {
                        $errorRows++;
                        $allErrors[] = "Row {$actualRowNumber}: Failed to prepare instruction data";
                    }
                } else {
                    $errorRows++;
                    foreach ($rowValidation['errors'] as $error) {
                        $allErrors[] = $error;
                    }
                }

                // Collect warnings
                if (!empty($rowValidation['warnings'])) {
                    foreach ($rowValidation['warnings'] as $warning) {
                        $allWarnings[] = "Row {$actualRowNumber}: {$warning}";
                    }
                }

                // Process batch when it reaches max size
                if (count($batch) >= self::MAX_BATCH_SIZE) {
                    $this->createPaymentInstructionsBatch($batch, $batchNumber);
                    $batch = [];
                    $batchNumber++;
                }

                // Progress logging for large files
                if ($totalRows % 1000 === 0) {
                    Log::info('Row processing progress', [
                        'file_id' => $this->massPaymentFile->id,
                        'processed_rows' => $totalRows,
                        'valid_rows' => $validRows,
                        'error_rows' => $errorRows
                    ]);
                }
            }

            // Process remaining batch
            if (!empty($batch)) {
                $this->createPaymentInstructionsBatch($batch, $batchNumber);
            }
        });

        // Update file with validation results
        $this->updateFileWithValidationResults($totalRows, $validRows, $errorRows, $allErrors, $allWarnings, $totalAmount, $currency);

        Log::info('Row processing and validation completed', [
            'file_id' => $this->massPaymentFile->id,
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'success_rate' => $totalRows > 0 ? round(($validRows / $totalRows) * 100, 2) : 0
        ]);
    }

    /**
     * Prepare payment instruction data from CSV row.
     */
    private function prepareInstructionData(array $record, int $rowNumber): ?array
    {
        try {
            // Extract and validate required fields
            $beneficiaryName = trim($record['beneficiary_name'] ?? '');
            $amount = $this->parseAmount($record['amount'] ?? '');
            $currency = strtoupper(trim($record['currency'] ?? ''));
            $reference = trim($record['reference'] ?? '');

            if (empty($beneficiaryName) || $amount <= 0 || empty($currency)) {
                return null;
            }

            // Find or prepare beneficiary data
            $beneficiary = $this->findBeneficiaryByRecord($record, $currency);
            
            if (!$beneficiary) {
                Log::warning('Beneficiary not found for row', [
                    'file_id' => $this->massPaymentFile->id,
                    'row_number' => $rowNumber,
                    'beneficiary_name' => $beneficiaryName
                ]);
                return null;
            }

            return [
                'mass_payment_file_id' => $this->massPaymentFile->id,
                'beneficiary_id' => $beneficiary->id,
                'amount' => round($amount, 2),
                'currency' => $currency,
                'purpose_code' => trim($record['purpose_code'] ?? '') ?: null,
                'reference' => $reference,
                'row_number' => $rowNumber,
                'status' => 'pending',
                'additional_data' => $this->extractAdditionalData($record)
            ];

        } catch (\Exception $e) {
            Log::error('Error preparing instruction data', [
                'file_id' => $this->massPaymentFile->id,
                'row_number' => $rowNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create a batch of payment instructions.
     */
    private function createPaymentInstructionsBatch(array $batch, int $batchNumber): void
    {
        try {
            Log::debug('Creating payment instructions batch', [
                'file_id' => $this->massPaymentFile->id,
                'batch_number' => $batchNumber,
                'batch_size' => count($batch)
            ]);

            foreach ($batch as $instructionData) {
                PaymentInstruction::create($instructionData);
            }

            Log::debug('Payment instructions batch created successfully', [
                'file_id' => $this->massPaymentFile->id,
                'batch_number' => $batchNumber,
                'created_count' => count($batch)
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating payment