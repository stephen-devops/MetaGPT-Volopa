Looking at the context and requirements, I need to implement the `MassPaymentFileService.php` class that handles the business logic for mass payment file operations. Based on the design patterns and data structures provided, this service should handle file creation, approval workflow, CSV processing coordination, and status management.

## Code: app/Services/MassPaymentFileService.php

```php
<?php

namespace App\Services;

use App\Models\MassPaymentFile;
use App\Models\TccAccount;
use App\Models\PaymentInstruction;
use App\Jobs\ValidateMassPaymentFileJob;
use App\Jobs\ProcessPaymentInstructionsJob;
use App\Notifications\PaymentFileApprovalPending;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class MassPaymentFileService
{
    /**
     * The CSV validation service.
     */
    private CsvValidationService $csvValidationService;

    /**
     * Maximum file size in bytes (50MB).
     */
    private const MAX_FILE_SIZE = 52428800;

    /**
     * Allowed file MIME types.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'text/comma-separated-values',
    ];

    /**
     * File storage disk for mass payment files.
     */
    private const STORAGE_DISK = 'mass_payments';

    /**
     * Default queue for validation jobs.
     */
    private const VALIDATION_QUEUE = 'validation';

    /**
     * Default queue for processing jobs.
     */
    private const PROCESSING_QUEUE = 'processing';

    /**
     * Create a new service instance.
     */
    public function __construct(CsvValidationService $csvValidationService)
    {
        $this->csvValidationService = $csvValidationService;
    }

    /**
     * Create a new mass payment file with uploaded CSV.
     */
    public function create(array $data, UploadedFile $file): MassPaymentFile
    {
        DB::beginTransaction();

        try {
            // Validate file format before processing
            $this->validateFileFormat($file);

            // Validate TCC account access
            $tccAccount = $this->validateTccAccount($data['tcc_account_id'], $data['currency']);

            // Store the CSV file securely
            $filePath = $this->storeCsvFile($file);

            // Create the mass payment file record
            $massPaymentFile = $this->createMassPaymentFileRecord($data, $file, $filePath, $tccAccount);

            // Dispatch validation job
            $this->dispatchValidationJob($massPaymentFile);

            // Log the creation
            $this->logFileCreation($massPaymentFile);

            DB::commit();

            return $massPaymentFile;

        } catch (Exception $e) {
            DB::rollBack();
            
            // Clean up uploaded file if it was stored
            if (isset($filePath)) {
                $this->cleanupFile($filePath);
            }

            Log::error('Failed to create mass payment file', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'filename' => $file->getClientOriginalName(),
            ]);

            throw $e;
        }
    }

    /**
     * Approve a mass payment file and trigger processing.
     */
    public function approve(MassPaymentFile $file, $user): bool
    {
        DB::beginTransaction();

        try {
            // Validate approval conditions
            $this->validateApprovalConditions($file, $user);

            // Mark file as approved
            $file->markAsApproved($user->id);

            // Dispatch processing job
            $this->dispatchProcessingJob($file);

            // Send approval notifications
            $this->sendApprovalNotifications($file, $user);

            // Log the approval
            $this->logFileApproval($file, $user);

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to approve mass payment file', [
                'file_id' => $file->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a mass payment file and cleanup associated data.
     */
    public function delete(MassPaymentFile $file): bool
    {
        DB::beginTransaction();

        try {
            // Validate deletion conditions
            $this->validateDeletionConditions($file);

            // Soft delete payment instructions
            $file->paymentInstructions()->delete();

            // Cleanup file storage
            $this->cleanupFile($file->file_path);

            // Soft delete the file record
            $file->delete();

            // Log the deletion
            $this->logFileDeletion($file);

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete mass payment file', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get status summary for a mass payment file.
     */
    public function getStatusSummary(MassPaymentFile $file): array
    {
        $summary = [
            'file_id' => $file->id,
            'status' => $file->status,
            'formatted_status' => $file->getFormattedStatus(),
            'status_color' => $file->getStatusColor(),
            'progress_percentage' => $file->getProgressPercentage(),
            'total_instructions' => $file->total_instructions,
            'valid_instructions' => $file->valid_instructions,
            'invalid_instructions' => $file->invalid_instructions,
            'success_rate' => $file->getSuccessRate(),
            'total_amount' => $file->total_amount,
            'currency' => $file->currency,
            'formatted_amount' => number_format($file->total_amount, 2) . ' ' . $file->currency,
            'created_at' => $file->created_at->toISOString(),
            'updated_at' => $file->updated_at->toISOString(),
        ];

        // Add approval information if approved
        if ($file->isApproved()) {
            $summary['approved_by'] = $file->approved_by;
            $summary['approved_at'] = $file->approved_at?->toISOString();
        }

        // Add validation errors if any
        if ($file->hasValidationErrors()) {
            $summary['validation_errors'] = $file->validation_errors;
            $summary['validation_error_count'] = $file->getValidationErrorCount();
        }

        // Add processing statistics
        $summary['processing_stats'] = $this->getProcessingStatistics($file);

        return $summary;
    }

    /**
     * Update the status of a mass payment file.
     */
    public function updateStatus(MassPaymentFile $file, string $status, ?array $validationErrors = null): bool
    {
        try {
            $oldStatus = $file->status;
            $updated = $file->updateStatus($status, $validationErrors);

            if ($updated) {
                // Log status change
                Log::info('Mass payment file status updated', [
                    'file_id' => $file->id,
                    'old_status' => $oldStatus,
                    'new_status' => $status,
                    'has_errors' => !empty($validationErrors),
                ]);

                // Send notifications for status changes
                $this->handleStatusChangeNotifications($file, $oldStatus, $status);
            }

            return $updated;

        } catch (Exception $e) {
            Log::error('Failed to update mass payment file status', [
                'file_id' => $file->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reprocess a failed mass payment file.
     */
    public function reprocess(MassPaymentFile $file): bool
    {
        try {
            // Validate reprocessing conditions
            if (!in_array($file->status, [MassPaymentFile::STATUS_FAILED, MassPaymentFile::STATUS_VALIDATION_FAILED])) {
                throw new Exception('File cannot be reprocessed in current status: ' . $file->status);
            }

            // Reset file status
            $file->updateStatus(MassPaymentFile::STATUS_VALIDATING);

            // Dispatch validation job
            $this->dispatchValidationJob($file);

            Log::info('Mass payment file reprocessing initiated', [
                'file_id' => $file->id,
                'user_id' => Auth::id(),
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to reprocess mass payment file', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cancel processing of a mass payment file.
     */
    public function cancel(MassPaymentFile $file): bool
    {
        DB::beginTransaction();

        try {
            // Validate cancellation conditions
            if (!in_array($file->status, [
                MassPaymentFile::STATUS_DRAFT,
                MassPaymentFile::STATUS_VALIDATING,
                MassPaymentFile::STATUS_AWAITING_APPROVAL,
                MassPaymentFile::STATUS_APPROVED,
            ])) {
                throw new Exception('File cannot be cancelled in current status: ' . $file->status);
            }

            // Update file status
            $file->updateStatus(MassPaymentFile::STATUS_FAILED);

            // Cancel related payment instructions
            $file->paymentInstructions()
                ->whereIn('status', ['draft', 'validated', 'pending'])
                ->update(['status' => 'cancelled']);

            // Log cancellation
            Log::info('Mass payment file cancelled', [
                'file_id' => $file->id,
                'user_id' => Auth::id(),
            ]);

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to cancel mass payment file', [
                'file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate file format and constraints.
     */
    private function validateFileFormat(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new Exception('File size exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB');
        }

        // Check MIME type
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new Exception('Invalid file type. Only CSV files are allowed.');
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv', 'txt'])) {
            throw new Exception('Invalid file extension. Only .csv and .txt files are allowed.');
        }

        // Check if file is readable
        if (!is_readable($file->getPathname())) {
            throw new Exception('Uploaded file cannot be read.');
        }
    }

    /**
     * Validate TCC account access and currency support.
     */
    private function validateTccAccount(string $tccAccountId, string $currency): TccAccount
    {
        $tccAccount = TccAccount::where('id', $tccAccountId)
            ->where('client_id', Auth::user()->client_id)
            ->first();

        if (!$tccAccount) {
            throw new Exception('TCC account not found or access denied.');
        }

        if (!$tccAccount->isActive()) {
            throw new Exception('TCC account is not active.');
        }

        if (!$tccAccount->supportsCurrency($currency)) {
            throw new Exception("TCC account does not support payments in {$currency}.");
        }

        if (!$tccAccount->canTransact()) {
            throw new Exception('TCC account cannot be used for transactions.');
        }

        return $tccAccount;
    }

    /**
     * Store CSV file securely.
     */
    private function storeCsvFile(UploadedFile $file): string
    {
        try {
            $clientId = Auth::user()->client_id;
            $timestamp = Carbon::now()->format('Y/m/d');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            
            $filePath = "clients/{$clientId}/mass-payments/{$timestamp}/{$filename}";

            // Store file on configured disk
            $stored = Storage::disk(self::STORAGE_DISK)->putFileAs(
                dirname($filePath),
                $file,
                basename($filePath)
            );

            if (!$stored) {
                throw new Exception('Failed to store uploaded file.');
            }

            return $filePath;

        } catch (Exception $e) {
            throw new Exception('Failed to store CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Create mass payment file database record.
     */
    private function createMassPaymentFileRecord(array $data, UploadedFile $file, string $filePath, TccAccount $tccAccount): MassPaymentFile
    {
        return MassPaymentFile::create([
            'client_id' => Auth::user()->client_id,
            'tcc_account_id' => $tccAccount->id,
            'filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'currency' => strtoupper($data['currency']),
            'status' => MassPaymentFile::STATUS_DRAFT,
            'total_amount' => 0.00,
            'total_instructions' => 0,
            'valid_instructions' => 0,
            'invalid_instructions' => 0,
            'validation_errors' => null,
        ]);
    }

    /**
     * Dispatch validation job for mass payment file.
     */
    private function dispatchValidationJob(MassPaymentFile $file): void
    {
        // Update status to validating
        $file->updateStatus(MassPaymentFile::STATUS_VALIDATING);

        // Dispatch job to validation queue
        ValidateMassPaymentFileJob::dispatch($file)
            ->onQueue(self::VALIDATION_QUEUE)
            ->delay(now()->addSeconds(5)); // Small delay for UI responsiveness
    }

    /**
     * Dispatch processing job for approved file.
     */
    private function dispatchProcessingJob(MassPaymentFile $file): void
    {
        ProcessPaymentInstructionsJob::dispatch($file)
            ->onQueue(self::PROCESSING_QUEUE)
            ->delay(now()->addSeconds(10)); // Small delay after approval
    }

    /**
     * Validate conditions for file approval.
     */
    private function validateApprovalConditions(MassPaymentFile $file, $user): void
    {
        if (!$file->canBeApproved()) {
            throw new Exception('File cannot be approved in current status: ' . $file->status);
        }

        if (!$file->isAwaitingApproval()) {
            throw new Exception('File is not awaiting approval.');
        }

        // Check if user has valid instructions to approve
        if ($file->valid_instructions === 0) {
            throw new Exception('Cannot approve file with no valid payment instructions.');
        