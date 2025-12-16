## Code: app/Services/ApprovalWorkflowService.php

```php
<?php

namespace App\Services;

use App\Models\PaymentFile;
use App\Models\Approval;
use App\Models\User;
use App\Notifications\ApprovalRequiredNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Exception;
use Carbon\Carbon;

class ApprovalWorkflowService
{
    /**
     * Approval threshold amounts by currency.
     */
    private const APPROVAL_THRESHOLDS = [
        'USD' => 10000.00,
        'EUR' => 8500.00,
        'GBP' => 7500.00,
    ];

    /**
     * Default approval threshold for unknown currencies.
     */
    private const DEFAULT_APPROVAL_THRESHOLD = 10000.00;

    /**
     * Maximum number of approval attempts allowed.
     */
    private const MAX_APPROVAL_ATTEMPTS = 3;

    /**
     * Approval timeout in hours.
     */
    private const APPROVAL_TIMEOUT_HOURS = 72;

    /**
     * Check if a payment file requires approval.
     *
     * @param PaymentFile $file PaymentFile to check
     * @return bool True if approval is required
     */
    public function requiresApproval(PaymentFile $file): bool
    {
        Log::info('Checking if payment file requires approval', [
            'payment_file_id' => $file->id,
            'total_amount' => $file->total_amount,
            'currency' => $file->currency,
        ]);

        try {
            // Get approval threshold for the currency
            $threshold = $this->getApprovalThreshold($file->currency);

            // Check if total amount exceeds threshold
            $requiresApproval = $file->total_amount >= $threshold;

            // Additional business rules for approval requirement
            $requiresApproval = $requiresApproval || $this->hasHighRiskCharacteristics($file);

            Log::info('Approval requirement check completed', [
                'payment_file_id' => $file->id,
                'requires_approval' => $requiresApproval,
                'threshold' => $threshold,
                'total_amount' => $file->total_amount,
            ]);

            return $requiresApproval;

        } catch (Exception $e) {
            Log::error('Error checking approval requirement', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);

            // Default to requiring approval on error for safety
            return true;
        }
    }

    /**
     * Create an approval request for a payment file.
     *
     * @param PaymentFile $file PaymentFile to create approval for
     * @return Approval Created approval instance
     * @throws Exception If approval creation fails
     */
    public function createApprovalRequest(PaymentFile $file): Approval
    {
        Log::info('Creating approval request', ['payment_file_id' => $file->id]);

        try {
            return DB::transaction(function () use ($file) {
                // Check if approval already exists
                $existingApproval = Approval::where('payment_file_id', $file->id)
                    ->where('status', Approval::STATUS_PENDING)
                    ->first();

                if ($existingApproval) {
                    Log::info('Approval request already exists', [
                        'payment_file_id' => $file->id,
                        'approval_id' => $existingApproval->id,
                    ]);
                    return $existingApproval;
                }

                // Get appropriate approver
                $approver = $this->getApproverForFile($file);

                if (!$approver) {
                    throw new Exception('No suitable approver found for payment file');
                }

                // Create approval request
                $approval = Approval::create([
                    'payment_file_id' => $file->id,
                    'approver_id' => $approver->id,
                    'status' => Approval::STATUS_PENDING,
                    'approved_at' => null,
                    'comments' => null,
                ]);

                // Update payment file status
                $file->updateStatus(PaymentFile::STATUS_PENDING_APPROVAL);

                Log::info('Approval request created', [
                    'payment_file_id' => $file->id,
                    'approval_id' => $approval->id,
                    'approver_id' => $approver->id,
                ]);

                return $approval;
            });

        } catch (Exception $e) {
            Log::error('Failed to create approval request', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process an approval action (approve or reject).
     *
     * @param Approval $approval Approval instance to process
     * @param string $action Action to perform ('approve' or 'reject')
     * @param User $approver User performing the action
     * @return bool True if processing was successful
     * @throws Exception If processing fails
     */
    public function processApproval(Approval $approval, string $action, User $approver): bool
    {
        Log::info('Processing approval action', [
            'approval_id' => $approval->id,
            'payment_file_id' => $approval->payment_file_id,
            'action' => $action,
            'approver_id' => $approver->id,
        ]);

        try {
            return DB::transaction(function () use ($approval, $action, $approver) {
                // Validate approval is in pending status
                if ($approval->status !== Approval::STATUS_PENDING) {
                    throw new Exception('Approval is not in pending status');
                }

                // Validate approver authorization
                if ($approval->approver_id !== $approver->id) {
                    throw new Exception('User is not authorized to approve this payment file');
                }

                // Check if approval has timed out
                if ($this->hasApprovalTimedOut($approval)) {
                    throw new Exception('Approval request has timed out');
                }

                $paymentFile = $approval->paymentFile;
                if (!$paymentFile) {
                    throw new Exception('Payment file not found for approval');
                }

                // Process the approval action
                if ($action === 'approve') {
                    return $this->processApprovalApprove($approval, $approver, $paymentFile);
                } elseif ($action === 'reject') {
                    return $this->processApprovalReject($approval, $approver, $paymentFile);
                } else {
                    throw new Exception('Invalid approval action');
                }
            });

        } catch (Exception $e) {
            Log::error('Failed to process approval action', [
                'approval_id' => $approval->id,
                'action' => $action,
                'approver_id' => $approver->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send notifications to approvers about pending approval.
     *
     * @param PaymentFile $file PaymentFile requiring approval
     * @return void
     */
    public function notifyApprovers(PaymentFile $file): void
    {
        Log::info('Sending approver notifications', ['payment_file_id' => $file->id]);

        try {
            // Get all pending approvals for this file
            $pendingApprovals = Approval::where('payment_file_id', $file->id)
                ->where('status', Approval::STATUS_PENDING)
                ->with('approver')
                ->get();

            if ($pendingApprovals->isEmpty()) {
                Log::warning('No pending approvals found for notification', [
                    'payment_file_id' => $file->id,
                ]);
                return;
            }

            // Send notification to each approver
            foreach ($pendingApprovals as $approval) {
                if ($approval->approver) {
                    try {
                        $approval->approver->notify(new ApprovalRequiredNotification($file, $approval));
                        
                        Log::info('Approval notification sent', [
                            'payment_file_id' => $file->id,
                            'approval_id' => $approval->id,
                            'approver_id' => $approval->approver->id,
                        ]);

                    } catch (Exception $e) {
                        Log::error('Failed to send approval notification', [
                            'payment_file_id' => $file->id,
                            'approval_id' => $approval->id,
                            'approver_id' => $approval->approver->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

        } catch (Exception $e) {
            Log::error('Failed to notify approvers', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if an approval request has timed out.
     *
     * @param Approval $approval Approval to check
     * @return bool True if approval has timed out
     */
    public function hasApprovalTimedOut(Approval $approval): bool
    {
        if ($approval->status !== Approval::STATUS_PENDING) {
            return false;
        }

        $timeoutThreshold = Carbon::now()->subHours(self::APPROVAL_TIMEOUT_HOURS);
        return $approval->created_at->lt($timeoutThreshold);
    }

    /**
     * Get all pending approvals for a user.
     *
     * @param User $user User to get approvals for
     * @return \Illuminate\Database\Eloquent\Collection Collection of pending approvals
     */
    public function getPendingApprovalsForUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return Approval::where('approver_id', $user->id)
            ->where('status', Approval::STATUS_PENDING)
            ->with(['paymentFile', 'paymentFile.user'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get approval statistics for a payment file.
     *
     * @param PaymentFile $file PaymentFile to get statistics for
     * @return array Approval statistics
     */
    public function getApprovalStatistics(PaymentFile $file): array
    {
        $approvals = Approval::where('payment_file_id', $file->id)->get();

        return [
            'total_approvals' => $approvals->count(),
            'pending_approvals' => $approvals->where('status', Approval::STATUS_PENDING)->count(),
            'approved_count' => $approvals->where('status', Approval::STATUS_APPROVED)->count(),
            'rejected_count' => $approvals->where('status', Approval::STATUS_REJECTED)->count(),
            'requires_approval' => $this->requiresApproval($file),
            'approval_threshold' => $this->getApprovalThreshold($file->currency),
            'has_timed_out_approvals' => $approvals->filter(function ($approval) {
                return $this->hasApprovalTimedOut($approval);
            })->isNotEmpty(),
        ];
    }

    /**
     * Cancel all pending approvals for a payment file.
     *
     * @param PaymentFile $file PaymentFile to cancel approvals for
     * @return int Number of cancelled approvals
     */
    public function cancelPendingApprovals(PaymentFile $file): int
    {
        Log::info('Cancelling pending approvals', ['payment_file_id' => $file->id]);

        try {
            $cancelledCount = Approval::where('payment_file_id', $file->id)
                ->where('status', Approval::STATUS_PENDING)
                ->update([
                    'status' => Approval::STATUS_REJECTED,
                    'approved_at' => Carbon::now(),
                    'comments' => 'Automatically cancelled due to file processing',
                ]);

            Log::info('Pending approvals cancelled', [
                'payment_file_id' => $file->id,
                'cancelled_count' => $cancelledCount,
            ]);

            return $cancelledCount;

        } catch (Exception $e) {
            Log::error('Failed to cancel pending approvals', [
                'payment_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Process approval for approval action.
     *
     * @param Approval $approval Approval instance
     * @param User $approver Approver user
     * @param PaymentFile $paymentFile Payment file
     * @return bool Success status
     */
    private function processApprovalApprove(Approval $approval, User $approver, PaymentFile $paymentFile): bool
    {
        // Update approval status
        $approval->update([
            'status' => Approval::STATUS_APPROVED,
            'approved_at' => Carbon::now(),
        ]);

        // Update payment file status
        $paymentFile->updateStatus(PaymentFile::STATUS_APPROVED);

        Log::info('Payment file approved', [
            'approval_id' => $approval->id,
            'payment_file_id' => $paymentFile->id,
            'approver_id' => $approver->id,
        ]);

        return true;
    }

    /**
     * Process rejection for approval action.
     *
     * @param Approval $approval Approval instance
     * @param User $approver Approver user
     * @param PaymentFile $paymentFile Payment file
     * @return bool Success status
     */
    private function processApprovalReject(Approval $approval, User $approver, PaymentFile $paymentFile): bool
    {
        // Update approval status
        $approval->update([
            'status' => Approval::STATUS_REJECTED,
            'approved_at' => Carbon::now(),
        ]);

        // Update payment file status
        $paymentFile->updateStatus(PaymentFile::STATUS_REJECTED);

        Log::info('Payment file rejected', [
            'approval_id' => $approval->id,
            'payment_file_id' => $paymentFile->id,
            'approver_id' => $approver->id,
        ]);

        return true;
    }

    /**
     * Get approval threshold for a currency.
     *
     * @param string $currency Currency code
     * @return float Approval threshold amount
     */
    private function getApprovalThreshold(string $currency): float
    {
        return self::APPROVAL_THRESHOLDS[$currency] ?? self::DEFAULT_APPROVAL_THRESHOLD;
    }

    /**
     * Check if a payment file has high-risk characteristics.
     *
     * @param PaymentFile $file PaymentFile to check
     * @return bool True if file has high-risk characteristics
     */
    private function hasHighRiskCharacteristics(PaymentFile $file): bool
    {
        // Business logic for high-risk detection
        $highRiskFactors = [];

        // Check if file has a large number of payment instructions
        if ($file->total_records > 1000) {
            $highRiskFactors[] = 'high_volume';
        }

        // Check if file contains cross-border payments (simplified logic)
        if ($file->currency !== 'USD') {
            $highRiskFactors[] = 'foreign_currency';
        }

        // Check if file has validation errors that could indicate suspicious activity
        if ($file->invalid_records > ($file->total_records * 0.1)) { // More than 10% invalid
            $highRiskFactors[] = 'high_error_rate';
        }

        // Log high-risk factors found
        if (!empty($highRiskFactors)) {
            Log::info('High-risk characteristics detected', [
                'payment_file_id' => $file->id,
                'risk_factors' => $highRiskFactors,
            ]);
        }

        return