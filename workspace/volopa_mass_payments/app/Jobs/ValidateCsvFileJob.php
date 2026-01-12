## Code: app/Jobs/ValidateCsvFileJob.php

```php
<?php

namespace App\Jobs;

use App\Models\MassPaymentFile;
use App\Services\CsvValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Exception as CsvException;

class ValidateCsvFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The mass payment file to validate.
     */
    public MassPaymentFile $massPaymentFile;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

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
    public function handle(CsvValidationService $csvValidationService): void
    {
        Log::info('Starting CSV file validation', [
            'job_id' => $this->job?->getJobId(),
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'file_path' => $this->massPaymentFile->file_path
        ]);

        try {
            // Update file status to validating
            $this->massPaymentFile->updateStatus('validating');

            // Get the full path to the CSV file
            $filePath = $this->getFullFilePath();

            // Validate CSV structure first
            $structureValidation = $csvValidationService->validateCsvStructure($filePath);
            
            if (!$structureValidation['valid']) {
                $this->handleStructureValidationFailure($structureValidation);
                return;
            }

            // Update file with basic information from structure validation
            $this->updateFileFromStructureValidation($structureValidation);

            // Validate individual rows
            $rowValidationResults = $this->validateCsvRows($csvValidationService, $filePath);

            // Process validation results and update file status
            $this->processValidationResults($rowValidationResults, $structureValidation);

            Log::info('CSV file validation completed successfully', [
                'file_id' => $this->massPaymentFile->id,
                'total_rows' => $this->massPaymentFile->total_rows,
                'valid_rows' => $this->massPaymentFile->valid_rows,
                'error_rows' => $this->massPaymentFile->error_rows,
                'success_rate' => $this->massPaymentFile->getSuccessRate()
            ]);

        } catch (CsvException $e) {
            Log::error('CSV parsing error during validation', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage(),
                'job_id' => $this->job?->getJobId()
            ]);

            $this->handleValidationError('CSV parsing error: ' . $e->getMessage());

        } catch (\Exception $e) {
            Log::error('Unexpected error during CSV validation', [
                'file_id' => $this->massPaymentFile->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'job_id' => $this->job?->getJobId()
            ]);

            $this->handleValidationError('Unexpected validation error: ' . $e->getMessage());
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CSV validation job failed permanently', [
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'job_id' => $this->job?->getJobId()
        ]);

        // Update file status to failed
        $this->massPaymentFile->updateStatus('validation_failed', [
            'validation_errors' => [
                'system_error' => 'Validation job failed after ' . $this->tries . ' attempts: ' . $exception->getMessage()
            ]
        ]);

        // Optionally notify administrators or file uploader about the failure
        $this->notifyValidationFailure($exception);
    }

    /**
     * Get the full file system path to the CSV file.
     */
    private function getFullFilePath(): string
    {
        if (empty($this->massPaymentFile->file_path)) {
            throw new \RuntimeException('File path is empty');
        }

        $fullPath = Storage::path($this->massPaymentFile->file_path);

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("CSV file not found at path: {$fullPath}");
        }

        if (!is_readable($fullPath)) {
            throw new \RuntimeException("CSV file is not readable: {$fullPath}");
        }

        return $fullPath;
    }

    /**
     * Handle structure validation failure.
     */
    private function handleStructureValidationFailure(array $structureValidation): void
    {
        $errors = $structureValidation['errors'];
        
        Log::warning('CSV structure validation failed', [
            'file_id' => $this->massPaymentFile->id,
            'errors' => $errors
        ]);

        $this->massPaymentFile->updateStatus('validation_failed', [
            'validation_errors' => [
                'structure_errors' => $errors,
                'validation_completed_at' => now()->toISOString()
            ],
            'error_rows' => 1, // Structure error affects entire file
            'total_rows' => $structureValidation['row_count'] ?? 0
        ]);
    }

    /**
     * Update file with information from structure validation.
     */
    private function updateFileFromStructureValidation(array $structureValidation): void
    {
        $this->massPaymentFile->update([
            'total_rows' => $structureValidation['row_count'],
        ]);

        Log::debug('Updated file with structure validation results', [
            'file_id' => $this->massPaymentFile->id,
            'row_count' => $structureValidation['row_count'],
            'file_size' => $structureValidation['file_size'] ?? 0
        ]);
    }

    /**
     * Validate individual CSV rows.
     */
    private function validateCsvRows(CsvValidationService $csvValidationService, string $filePath): array
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);
        
        $validRows = 0;
        $errorRows = 0;
        $allErrors = [];
        $allWarnings = [];
        $rowNumber = 1; // Start from 1 (header is 0)

        Log::info('Starting row-by-row validation', [
            'file_id' => $this->massPaymentFile->id,
            'total_rows' => $this->massPaymentFile->total_rows
        ]);

        foreach ($csv->getRecords() as $record) {
            $rowNumber++;
            
            // Validate this row
            $rowValidation = $csvValidationService->validateRowData($record, $rowNumber);
            
            if ($rowValidation['valid']) {
                $validRows++;
            } else {
                $errorRows++;
                
                // Collect errors for this row
                foreach ($rowValidation['errors'] as $error) {
                    $allErrors[] = $error;
                }
            }
            
            // Collect warnings
            if (!empty($rowValidation['warnings'])) {
                foreach ($rowValidation['warnings'] as $warning) {
                    $allWarnings[] = "Row {$rowNumber}: {$warning}";
                }
            }

            // Progress logging for large files
            if ($rowNumber % 1000 === 0) {
                Log::info('Validation progress', [
                    'file_id' => $this->massPaymentFile->id,
                    'processed_rows' => $rowNumber - 1,
                    'valid_rows' => $validRows,
                    'error_rows' => $errorRows
                ]);
            }
        }

        Log::info('Row validation completed', [
            'file_id' => $this->massPaymentFile->id,
            'total_processed' => $rowNumber - 1,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'total_errors' => count($allErrors),
            'total_warnings' => count($allWarnings)
        ]);

        return [
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'errors' => $allErrors,
            'warnings' => $allWarnings,
            'total_processed' => $rowNumber - 1
        ];
    }

    /**
     * Process validation results and update file status.
     */
    private function processValidationResults(array $rowValidationResults, array $structureValidation): void
    {
        $validRows = $rowValidationResults['valid_rows'];
        $errorRows = $rowValidationResults['error_rows'];
        $totalRows = $rowValidationResults['total_processed'];
        $errors = $rowValidationResults['errors'];
        $warnings = $rowValidationResults['warnings'];

        // Prepare validation errors data
        $validationErrors = [];
        
        if (!empty($errors)) {
            $validationErrors['row_errors'] = array_slice($errors, 0, 100); // Limit to first 100 errors
            if (count($errors) > 100) {
                $validationErrors['additional_error_count'] = count($errors) - 100;
            }
        }

        if (!empty($warnings)) {
            $validationErrors['warnings'] = array_slice($warnings, 0, 50); // Limit to first 50 warnings
            if (count($warnings) > 50) {
                $validationErrors['additional_warning_count'] = count($warnings) - 50;
            }
        }

        // Add structure validation warnings if any
        if (!empty($structureValidation['warnings'])) {
            $validationErrors['structure_warnings'] = $structureValidation['warnings'];
        }

        // Add validation metadata
        $validationErrors['validation_completed_at'] = now()->toISOString();
        $validationErrors['validation_summary'] = [
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'success_rate' => $totalRows > 0 ? round(($validRows / $totalRows) * 100, 2) : 0,
            'error_rate' => $totalRows > 0 ? round(($errorRows / $totalRows) * 100, 2) : 0
        ];

        // Determine final status
        $finalStatus = $this->determineFinalStatus($validRows, $errorRows, $errors);

        // Update the mass payment file
        $updateData = [
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'status' => $finalStatus
        ];

        if (!empty($validationErrors)) {
            $updateData['validation_errors'] = $validationErrors;
        }

        $this->massPaymentFile->update($updateData);

        Log::info('File updated with validation results', [
            'file_id' => $this->massPaymentFile->id,
            'final_status' => $finalStatus,
            'validation_summary' => $validationErrors['validation_summary'] ?? []
        ]);

        // Trigger next step based on validation results
        $this->triggerNextStep($finalStatus);
    }

    /**
     * Determine the final status based on validation results.
     */
    private function determineFinalStatus(int $validRows, int $errorRows, array $errors): string
    {
        if (!empty($errors) && $validRows === 0) {
            // All rows failed validation
            return 'validation_failed';
        }

        if (!empty($errors) && $validRows > 0) {
            // Some rows failed, but some are valid - decision depends on business rules
            $errorRate = ($errorRows / ($validRows + $errorRows)) * 100;
            
            if ($errorRate > 50) {
                // More than 50% errors - mark as failed
                return 'validation_failed';
            } else {
                // Less than 50% errors - mark as validated but requires approval
                return 'pending_approval';
            }
        }

        if (empty($errors)) {
            // All rows are valid
            return 'validated';
        }

        // Fallback
        return 'validated';
    }

    /**
     * Trigger the next step in the processing workflow.
     */
    private function triggerNextStep(string $status): void
    {
        switch ($status) {
            case 'validated':
                // File is fully valid - can proceed to approval or auto-approval
                Log::info('File validation successful, ready for approval', [
                    'file_id' => $this->massPaymentFile->id
                ]);
                break;

            case 'pending_approval':
                // File has some errors but might be approvable - notify for review
                Log::info('File validation completed with warnings, requires approval review', [
                    'file_id' => $this->massPaymentFile->id
                ]);
                break;

            case 'validation_failed':
                // File has too many errors - notify uploader
                Log::warning('File validation failed, too many errors', [
                    'file_id' => $this->massPaymentFile->id,
                    'error_rate' => $this->massPaymentFile->getErrorRate()
                ]);
                break;

            default:
                Log::warning('Unexpected validation status', [
                    'file_id' => $this->massPaymentFile->id,
                    'status' => $status
                ]);
                break;
        }
    }

    /**
     * Handle validation error by updating file status.
     */
    private function handleValidationError(string $errorMessage): void
    {
        $this->massPaymentFile->updateStatus('validation_failed', [
            'validation_errors' => [
                'system_error' => $errorMessage,
                'validation_failed_at' => now()->toISOString()
            ]
        ]);
    }

    /**
     * Notify relevant parties about validation failure.
     */
    private function notifyValidationFailure(\Throwable $exception): void
    {
        try {
            // This would typically send notifications to:
            // 1. File uploader
            // 2. System administrators
            // 3. Monitoring systems
            
            Log::info('Validation failure notifications would be sent', [
                'file_id' => $this->massPaymentFile->id,
                'uploader_id' => $this->massPaymentFile->uploaded_by,
                'error' => $exception->getMessage()
            ]);

            // Example notification implementation:
            // Notification::send($this->massPaymentFile->uploader, new ValidationFailedNotification($this->massPaymentFile, $exception));

        } catch (\Exception $e) {
            Log::error('Failed to send validation failure notifications', [
                'file_id' => $this->massPaymentFile->id,
                'notification