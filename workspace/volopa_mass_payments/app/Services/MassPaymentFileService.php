## Code: app/Services/MassPaymentFileService.php

```php
<?php

namespace App\Services;

use App\Models\MassPaymentFile;
use App\Models\TccAccount;
use App\Models\User;
use App\Jobs\ProcessMassPaymentFileJob;
use App\Notifications\FileApprovalRequiredNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class MassPaymentFileService
{
    /**
     * Maximum file size allowed (in bytes) - 10MB.
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Allowed MIME types for uploaded files.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel'
    ];

    /**
     * Maximum number of rows allowed per file.
     */
    private const MAX_ROWS = 10000;

    /**
     * Default processing timeout in seconds.
     */
    private const PROCESSING_TIMEOUT = 300;

    /**
     * File status constants.
     */
    private const STATUS_UPLOADING = 'uploading';
    private const STATUS_UPLOADED = 'uploaded';
    private const STATUS_VALIDATING = 'validating';
    private const STATUS_VALIDATION_FAILED = 'validation_failed';
    private const STATUS_VALIDATED = 'validated';
    private const STATUS_PENDING_APPROVAL = 'pending_approval';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_PROCESSED = 'processed';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    /**
     * Upload a mass payment file and initiate processing.
     *
     * @param array $data Validated request data
     * @param UploadedFile $file Uploaded CSV file
     * @return MassPaymentFile Created mass payment file
     * @throws \Exception If upload or processing fails
     */
    public function uploadFile(array $data, UploadedFile $file): MassPaymentFile
    {
        Log::info('Starting mass payment file upload', [
            'filename' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'client_id' => $data['client_id'],
            'tcc_account_id' => $data['tcc_account_id'],
            'uploaded_by' => $data['uploaded_by']
        ]);

        try {
            return DB::transaction(function () use ($data, $file) {
                // Validate file before processing
                $this->validateUploadedFile($file);

                // Get TCC account and validate
                $tccAccount = TccAccount::findOrFail($data['tcc_account_id']);
                $this->validateTccAccount($tccAccount, $data['client_id']);

                // Store file securely
                $storedPath = $this->storeUploadedFile($file, $data['client_id']);

                // Get file metadata
                $fileMetadata = $this->extractFileMetadata($file, $storedPath);

                // Create mass payment file record
                $massPaymentFile = $this->createMassPaymentFileRecord($data, $fileMetadata, $tccAccount);

                // Dispatch processing job
                $this->dispatchProcessingJob($massPaymentFile);

                Log::info('Mass payment file uploaded successfully', [
                    'file_id' => $massPaymentFile->id,
                    'filename' => $massPaymentFile->filename,
                    'stored_path' => $storedPath
                ]);

                return $massPaymentFile;
            });

        } catch (\Exception $e) {
            Log::error('Error uploading mass payment file', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'client_id' => $data['client_id'] ?? null,
                'user_id' => $data['uploaded_by'] ?? null
            ]);

            // Clean up stored file if it was created
            if (isset($storedPath) && Storage::exists($storedPath)) {
                Storage::delete($storedPath);
            }

            throw $e;
        }
    }

    /**
     * Approve a mass payment file.
     *
     * @param MassPaymentFile $file File to approve
     * @param int $approverId ID of the approving user
     * @return MassPaymentFile Updated mass payment file
     * @throws \Exception If approval fails
     */
    public function approveFile(MassPaymentFile $file, int $approverId): MassPaymentFile
    {
        Log::info('Starting mass payment file approval', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'approver_id' => $approverId,
            'current_status' => $file->status
        ]);

        try {
            return DB::transaction(function () use ($file, $approverId) {
                // Validate file can be approved
                $this->validateFileForApproval($file);

                // Validate approver permissions
                $approver = User::findOrFail($approverId);
                $this->validateApproverPermissions($approver, $file);

                // Check TCC account balance
                $this->validateAccountBalance($file);

                // Update file status to approved
                $file->markAsApproved($approverId);

                // Send notification to relevant users
                $this->sendApprovalNotifications($file, $approver);

                // Dispatch payment processing job
                ProcessMassPaymentFileJob::dispatch($file)
                    ->delay(now()->addMinutes(1)); // Small delay for database consistency

                Log::info('Mass payment file approved successfully', [
                    'file_id' => $file->id,
                    'approver_id' => $approverId,
                    'total_amount' => $file->total_amount,
                    'currency' => $file->currency
                ]);

                return $file->fresh();
            });

        } catch (\Exception $e) {
            Log::error('Error approving mass payment file', [
                'file_id' => $file->id,
                'approver_id' => $approverId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Get the current status of a mass payment file.
     *
     * @param int $fileId Mass payment file ID
     * @return array Status information
     * @throws \Exception If file not found
     */
    public function getFileStatus(int $fileId): array
    {
        try {
            $file = MassPaymentFile::with(['tccAccount', 'uploader', 'approver', 'paymentInstructions'])
                                  ->findOrFail($fileId);

            $statusInfo = [
                'id' => $file->id,
                'filename' => $file->filename,
                'status' => $file->status,
                'total_rows' => $file->total_rows,
                'valid_rows' => $file->valid_rows,
                'error_rows' => $file->error_rows,
                'success_rate' => $file->getSuccessRate(),
                'error_rate' => $file->getErrorRate(),
                'total_amount' => $file->total_amount,
                'currency' => $file->currency,
                'created_at' => $file->created_at->toISOString(),
                'updated_at' => $file->updated_at->toISOString(),
                'uploaded_by' => [
                    'id' => $file->uploader->id,
                    'name' => $file->uploader->name,
                    'email' => $file->uploader->email
                ],
                'tcc_account' => [
                    'id' => $file->tccAccount->id,
                    'account_number' => $file->tccAccount->account_number,
                    'currency' => $file->tccAccount->currency,
                    'balance' => $file->tccAccount->balance
                ],
                'processing_info' => $this->getProcessingInfo($file),
                'validation_errors' => $file->validation_errors,
                'can_be_approved' => $this->canFileBeApproved($file),
                'can_be_rejected' => $this->canFileBeRejected($file),
                'can_be_cancelled' => $this->canFileBeCancelled($file)
            ];

            // Add approval information if approved
            if ($file->isApproved() && $file->approver) {
                $statusInfo['approved_by'] = [
                    'id' => $file->approver->id,
                    'name' => $file->approver->name,
                    'email' => $file->approver->email
                ];
                $statusInfo['approved_at'] = $file->approved_at->toISOString();
            }

            // Add rejection information if rejected
            if ($file->isRejected()) {
                $statusInfo['rejection_reason'] = $file->rejection_reason;
            }

            // Add payment instruction summary
            if ($file->paymentInstructions->isNotEmpty()) {
                $statusInfo['payment_instructions'] = [
                    'total_count' => $file->paymentInstructions->count(),
                    'pending_count' => $file->paymentInstructions->where('status', 'pending')->count(),
                    'validated_count' => $file->paymentInstructions->where('status', 'validated')->count(),
                    'processing_count' => $file->paymentInstructions->where('status', 'processing')->count(),
                    'completed_count' => $file->paymentInstructions->where('status', 'completed')->count(),
                    'failed_count' => $file->paymentInstructions->where('status', 'failed')->count()
                ];
            }

            Log::debug('File status retrieved', [
                'file_id' => $fileId,
                'status' => $file->status,
                'client_id' => $file->client_id
            ]);

            return $statusInfo;

        } catch (\Exception $e) {
            Log::error('Error getting file status', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Delete a mass payment file.
     *
     * @param MassPaymentFile $file File to delete
     * @return bool True if deletion was successful
     * @throws \Exception If deletion fails
     */
    public function deleteFile(MassPaymentFile $file): bool
    {
        Log::info('Starting mass payment file deletion', [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'status' => $file->status
        ]);

        try {
            return DB::transaction(function () use ($file) {
                // Validate file can be deleted
                $this->validateFileForDeletion($file);

                // Delete associated payment instructions first
                if ($file->paymentInstructions()->exists()) {
                    $instructionCount = $file->paymentInstructions()->count();
                    $file->paymentInstructions()->delete();
                    
                    Log::info('Deleted payment instructions', [
                        'file_id' => $file->id,
                        'instruction_count' => $instructionCount
                    ]);
                }

                // Delete physical file from storage
                if (!empty($file->file_path) && Storage::exists($file->file_path)) {
                    Storage::delete($file->file_path);
                    Log::info('Deleted physical file', [
                        'file_id' => $file->id,
                        'file_path' => $file->file_path
                    ]);
                }

                // Soft delete the mass payment file record
                $file->delete();

                Log::info('Mass payment file deleted successfully', [
                    'file_id' => $file->id,
                    'filename' => $file->filename
                ]);

                return true;
            });

        } catch (\Exception $e) {
            Log::error('Error deleting mass payment file', [
                'file_id' => $file->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Validate uploaded file meets requirements.
     *
     * @param UploadedFile $file Uploaded file
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateUploadedFile(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB');
        }

        // Check file is valid
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Uploaded file is not valid');
        }

        // Check MIME type
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('File must be a CSV file');
        }

        // Check file extension
        $allowedExtensions = ['csv', 'txt'];
        if (!in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions)) {
            throw new \InvalidArgumentException('File must have .csv or .txt extension');
        }

        // Basic CSV structure check
        if (!$this->isValidCsvStructure($file)) {
            throw new \InvalidArgumentException('File does not appear to be a valid CSV file');
        }
    }

    /**
     * Validate TCC account is accessible and active.
     *
     * @param TccAccount $tccAccount TCC account model
     * @param int $clientId Client ID to validate against
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateTccAccount(TccAccount $tccAccount, int $clientId): void
    {
        if ($tccAccount->client_id !== $clientId) {
            throw new \InvalidArgumentException('TCC account does not belong to the specified client');
        }

        if ($tccAccount->status !== 'active') {
            throw new \InvalidArgumentException('TCC account is not active');
        }

        if ($tccAccount->balance <= 0) {
            throw new \InvalidArgumentException('TCC account has insufficient balance');
        }
    }

    /**
     * Store uploaded file securely.
     *
     * @param UploadedFile $file Uploaded file
     * @param int $clientId Client ID for directory structure
     * @return string Stored file path
     */
    private function storeUploadedFile(UploadedFile $file, int $clientId): string
    {
        $year = now()->year;
        $month = now()->format('m');
        $directory = "mass_payments/client_{$clientId}/{$year}/{$month}";

        // Generate unique filename
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        $uniqueId = uniqid();
        $filename = "{$originalName}_{$timestamp}_{$uniqueId}.{$extension}";

        // Store file
        $path = $file->storeAs($directory, $filename, 'local');

        if (!$path) {
            throw new \RuntimeException('Failed to store uploaded file');
        }

        return $path;
    }

    /**
     * Extract metadata from uploaded file.
     *
     * @param UploadedFile $file Uploaded file
     * @param string $storedPath Stored file