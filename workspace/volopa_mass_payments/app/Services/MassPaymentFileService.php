{
  "Development Plan": [
    "app/Services/MassPaymentFileService.php"
  ],
  "Incremental Change": [
    "app/Services/MassPaymentFileService.php: Core business logic service with file upload, approval, validation coordination, status management, and transaction handling"
  ]
}

## Code: app/Services/MassPaymentFileService.php
```php
<?php

namespace App\Services;

use App\Jobs\ValidateMassPaymentFileJob;
use App\Models\MassPaymentFile;
use App\Models\TccAccount;
use App\Notifications\PaymentFileApprovalNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

class MassPaymentFileService
{
    /**
     * CSV Validation Service instance
     */
    protected CsvValidationService $csvValidationService;

    /**
     * Constructor
     */
    public function __construct(CsvValidationService $csvValidationService)
    {
        $this->csvValidationService = $csvValidationService;
    }

    /**
     * Upload and process a mass payment file
     *
     * @param UploadedFile $file
     * @param int $clientId
     * @param int $tccAccountId
     * @param array $options
     * @return MassPaymentFile
     * @throws Exception
     */
    public function uploadFile(
        UploadedFile $file, 
        int $clientId, 
        int $tccAccountId, 
        array $options = []
    ): MassPaymentFile {
        // Validate input parameters
        $this->validateUploadParameters($file, $clientId, $tccAccountId);

        // Validate TCC account
        $tccAccount = $this->validateTccAccount($tccAccountId, $clientId);

        DB::beginTransaction();

        try {
            // Store the file securely
            $storedFilename = $this->storeUploadedFile($file);

            // Extract initial file metadata
            $fileMetadata = $this->extractFileMetadata($file, $storedFilename, $options);

            // Create mass payment file record
            $massPaymentFile = $this->createMassPaymentFileRecord(
                $clientId,
                $tccAccountId,
                $fileMetadata,
                $options
            );

            // Log the upload
            $this->logFileUpload($massPaymentFile, $options);

            // Dispatch validation job asynchronously
            $this->dispatchValidationJob($massPaymentFile);

            // Send notification if enabled
            $this->sendUploadNotification($massPaymentFile, $options);

            DB::commit();

            return $massPaymentFile->fresh();

        } catch (Exception $e) {
            DB::rollBack();
            
            // Clean up stored file on error
            if (isset($storedFilename)) {
                $this->cleanupStoredFile($storedFilename);
            }

            Log::error('Mass payment file upload failed', [
                'client_id' => $clientId,
                'tcc_account_id' => $tccAccountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to upload mass payment file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Approve a mass payment file
     *
     * @param string $fileId
     * @param int $approverId
     * @param array $options
     * @return MassPaymentFile
     * @throws Exception
     */
    public function approveFile(string $fileId, int $approverId, array $options = []): MassPaymentFile
    {
        // Validate parameters
        if (empty($fileId) || $approverId <= 0) {
            throw new InvalidArgumentException('Invalid file ID or approver ID provided');
        }

        // Find and validate the file
        $massPaymentFile = MassPaymentFile::find($fileId);
        if (!$massPaymentFile) {
            throw new Exception('Mass payment file not found');
        }

        // Validate approval eligibility
        $this->validateApprovalEligibility($massPaymentFile, $approverId, $options);

        DB::beginTransaction();

        try {
            // Reserve funds in TCC account if required
            if (!isset($options['skip_fund_reservation']) || !$options['skip_fund_reservation']) {
                $this->reserveFundsForPayment($massPaymentFile);
            }

            // Mark file as approved
            $massPaymentFile->markAsApproved($approverId);

            // Add approval notes if provided
            if (!empty($options['approval_notes'])) {
                $this->addApprovalNotes($massPaymentFile, $options['approval_notes']);
            }

            // Log the approval
            $this->logFileApproval($massPaymentFile, $approverId, $options);

            // Dispatch processing job if auto-process is enabled
            if ($this->shouldAutoProcessAfterApproval($options)) {
                $this->dispatchProcessingJob($massPaymentFile);
            }

            // Send approval notifications
            $this->sendApprovalNotifications($massPaymentFile, $approverId, $options);

            DB::commit();

            return $massPaymentFile->fresh();

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Mass payment file approval failed', [
                'file_id' => $fileId,
                'approver_id' => $approverId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to approve mass payment file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get comprehensive file status information
     *
     * @param string $fileId
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function getFileStatus(string $fileId, array $options = []): array
    {
        if (empty($fileId)) {
            throw new InvalidArgumentException('File ID is required');
        }

        // Find the file with necessary relationships
        $relations = $options['include'] ?? ['paymentInstructions', 'tccAccount', 'client', 'uploader', 'approver'];
        
        $massPaymentFile = MassPaymentFile::with($relations)->find($fileId);
        
        if (!$massPaymentFile) {
            throw new Exception('Mass payment file not found');
        }

        // Build comprehensive status information
        return [
            'file_id' => $massPaymentFile->id,
            'status' => $massPaymentFile->status,
            'status_display' => $this->getStatusDisplay($massPaymentFile->status),
            'progress_percentage' => $massPaymentFile->progress_percentage,
            'created_at' => $massPaymentFile->created_at,
            'updated_at' => $massPaymentFile->updated_at,
            'file_info' => [
                'original_filename' => $massPaymentFile->original_filename,
                'file_size' => $massPaymentFile->file_size,
                'total_amount' => $massPaymentFile->total_amount,
                'currency' => $massPaymentFile->currency,
            ],
            'counts' => [
                'total_instructions' => $massPaymentFile->payment_instructions_count,
                'successful_payments' => $massPaymentFile->successful_payments_count,
                'failed_payments' => $massPaymentFile->failed_payments_count,
                'pending_payments' => $this->getPendingPaymentsCount($massPaymentFile),
            ],
            'validation_info' => [
                'has_errors' => !empty($massPaymentFile->validation_errors),
                'error_count' => is_array($massPaymentFile->validation_errors) 
                    ? count($massPaymentFile->validation_errors) : 0,
                'errors' => $massPaymentFile->validation_errors ?? [],
            ],
            'approval_info' => [
                'approved_by' => $massPaymentFile->approved_by,
                'approved_at' => $massPaymentFile->approved_at,
                'approver' => $massPaymentFile->approver ? [
                    'id' => $massPaymentFile->approver->id,
                    'name' => $massPaymentFile->approver->name ?? 'Unknown',
                ] : null,
            ],
            'upload_info' => [
                'uploaded_by' => $massPaymentFile->uploaded_by,
                'uploader' => $massPaymentFile->uploader ? [
                    'id' => $massPaymentFile->uploader->id,
                    'name' => $massPaymentFile->uploader->name ?? 'Unknown',
                ] : null,
            ],
            'tcc_account_info' => $massPaymentFile->tccAccount ? [
                'id' => $massPaymentFile->tccAccount->id,
                'account_name' => $massPaymentFile->tccAccount->account_name,
                'currency' => $massPaymentFile->tccAccount->currency,
                'balance' => $massPaymentFile->tccAccount->balance,
                'available_balance' => $massPaymentFile->tccAccount->available_balance,
            ] : null,
            'processing_info' => [
                'can_be_approved' => $massPaymentFile->canBeApproved(),
                'can_be_deleted' => $massPaymentFile->canBeDeleted(),
                'is_processing' => $massPaymentFile->isProcessing(),
                'is_completed' => $massPaymentFile->isCompleted(),
                'has_failed' => $massPaymentFile->hasFailed(),
            ],
            'timestamps' => [
                'created_at' => $massPaymentFile->created_at,
                'updated_at' => $massPaymentFile->updated_at,
                'approved_at' => $massPaymentFile->approved_at,
            ],
        ];
    }

    /**
     * Delete a mass payment file (soft delete)
     *
     * @param string $fileId
     * @param array $options
     * @return bool
     * @throws Exception
     */
    public function deleteFile(string $fileId, array $options = []): bool
    {
        if (empty($fileId)) {
            throw new InvalidArgumentException('File ID is required');
        }

        // Find the file
        $massPaymentFile = MassPaymentFile::find($fileId);
        if (!$massPaymentFile) {
            throw new Exception('Mass payment file not found');
        }

        // Check if file can be deleted
        if (!$massPaymentFile->canBeDeleted() && !($options['force_delete'] ?? false)) {
            throw new Exception('Mass payment file cannot be deleted in its current status');
        }

        DB::beginTransaction();

        try {
            // Release reserved funds if any
            if ($massPaymentFile->isApproved() || $massPaymentFile->isProcessing()) {
                $this->releaseFundsForPayment($massPaymentFile);
            }

            // Soft delete the file and related payment instructions
            $this->performSoftDelete($massPaymentFile);

            // Clean up physical file if specified
            if ($options['delete_physical_file'] ?? false) {
                $this->cleanupStoredFile($massPaymentFile->filename);
            }

            // Log the deletion
            $this->logFileDeletion($massPaymentFile, $options);

            // Send deletion notification if enabled
            if ($options['notify_deletion'] ?? false) {
                $this->sendDeletionNotification($massPaymentFile, $options);
            }

            DB::commit();

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Mass payment file deletion failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new Exception('Failed to delete mass payment file: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get file statistics and analytics
     *
     * @param array $filters
     * @return array
     */
    public function getFileStatistics(array $filters = []): array
    {
        $cacheKey = 'mass_payment_file_stats_' . md5(json_encode($filters));
        $cacheTtl = config('mass-payments.cache.currency_cache_minutes', 60);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($filters) {
            $query = MassPaymentFile::query();

            // Apply filters
            if (!empty($filters['client_id'])) {
                $query->where('client_id', $filters['client_id']);
            }

            if (!empty($filters['currency'])) {
                $query->where('currency', $filters['currency']);
            }

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            // Get basic counts
            $stats = [
                'total_files' => $query->count(),
                'total_amount' => $query->sum('total_amount'),
                'status_breakdown' => $query->groupBy('status')
                    ->selectRaw('status, count(*) as count, sum(total_amount) as total_amount')
                    ->get()
                    ->keyBy('status')
                    ->toArray(),
                'currency_breakdown' => $query->groupBy('currency')
                    ->selectRaw('currency, count(*) as count, sum(total_amount) as total_amount')
                    ->get()
                    ->keyBy('currency')
                    ->toArray(),
                'daily_stats' => $this->getDailyStats($query, $filters),
                'performance_metrics' => $this->getPerformanceMetrics($query),
            ];

            return $stats;
        });
    }

    /**
     * Reprocess a failed mass payment file
     *
     * @param string $fileId
     * @param array $options
     * @return MassPaymentFile
     * @throws Exception
     */
    public function reprocessFile(string $fileId, array $options = []): MassPaymentFile
    {
        if (empty($fileId)) {
            throw new InvalidArgumentException('File ID is required');
        }

        $massPaymentFile = MassPaymentFile::find($fileId);
        if (!$massPaymentFile) {
            throw new Exception('Mass payment file not found');
        }

        if (!$massPaymentFile->hasFailed() && !($options['force_reprocess'] ?? false)) {
            throw new Exception('Only failed files can be reprocessed');
        }

        DB::beginTransaction();

        try {
            // Reset file status
            $massPaymentFile->update(['status' => MassPaymentFile::STATUS_APPROVED]);

            // Reset payment instruction statuses if needed
            if ($options['reset_instructions'] ?? true) {
                $this->resetPaymentInstructionStatuses($massPaymentFile);
            }

            // Clear previous validation errors
            $massPaymentFile->update(['validation_errors' => null]);

            // Log the reprocess
            $this->logFileReprocess($massPaymentFile, $options);

            // Dispatch processing job
            $this->dispatchProcessingJob($massPaymentFile);

            DB::commit();

            return $massPaymentFile->fresh();

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Mass payment file reprocess failed', [
                'file_id' => $fileId,