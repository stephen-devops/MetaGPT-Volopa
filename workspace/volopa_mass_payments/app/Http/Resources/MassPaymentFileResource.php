## Code: app/Http/Resources/MassPaymentFileResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class MassPaymentFileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'tcc_account_id' => $this->tcc_account_id,
            'filename' => $this->filename,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'file_size_formatted' => $this->formatFileSize($this->file_size),
            'total_amount' => (float) $this->total_amount,
            'total_amount_formatted' => number_format($this->total_amount, 2) . ' ' . $this->currency,
            'currency' => $this->currency,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplay($this->status),
            'validation_errors' => $this->when(
                $this->hasValidationErrors() && $this->canViewValidationErrors($request),
                $this->validation_errors
            ),
            'validation_error_count' => $this->getValidationErrorCount(),
            'uploaded_by' => $this->uploaded_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            
            // Progress and statistics
            'progress_percentage' => $this->progress_percentage,
            'payment_counts' => [
                'total_instructions' => $this->payment_instructions_count,
                'successful_payments' => $this->successful_payments_count,
                'failed_payments' => $this->failed_payments_count,
                'pending_payments' => $this->payment_instructions_count - $this->successful_payments_count - $this->failed_payments_count,
            ],

            // Processing status flags
            'can_be_approved' => $this->canBeApproved(),
            'can_be_deleted' => $this->canBeDeleted(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'has_failed' => $this->hasFailed(),
            'is_draft' => $this->isDraft(),
            'is_validating' => $this->isValidating(),
            'has_validation_failed' => $this->hasValidationFailed(),
            'is_awaiting_approval' => $this->isAwaitingApproval(),
            'is_approved' => $this->isApproved(),

            // Related data - conditionally loaded
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                    'code' => $this->client->code ?? null,
                ];
            }),

            'tcc_account' => $this->whenLoaded('tccAccount', function () {
                return [
                    'id' => $this->tccAccount->id,
                    'account_name' => $this->tccAccount->account_name,
                    'account_number' => $this->tccAccount->getMaskedAccountNumberAttribute(),
                    'currency' => $this->tccAccount->currency,
                    'balance' => (float) $this->tccAccount->balance,
                    'available_balance' => (float) $this->tccAccount->available_balance,
                    'balance_formatted' => $this->tccAccount->getFormattedBalanceAttribute(),
                    'available_balance_formatted' => $this->tccAccount->getFormattedAvailableBalanceAttribute(),
                    'is_active' => $this->tccAccount->is_active,
                    'account_type' => $this->tccAccount->account_type,
                ];
            }),

            'uploader' => $this->whenLoaded('uploader', function () {
                return [
                    'id' => $this->uploader->id,
                    'name' => $this->uploader->name ?? 'Unknown User',
                    'email' => $this->when(
                        $this->canViewUserDetails($request),
                        $this->uploader->email
                    ),
                ];
            }),

            'approver' => $this->whenLoaded('approver', function () {
                return $this->approver ? [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name ?? 'Unknown User',
                    'email' => $this->when(
                        $this->canViewUserDetails($request),
                        $this->approver->email
                    ),
                ] : null;
            }),

            'payment_instructions' => PaymentInstructionResource::collection(
                $this->whenLoaded('paymentInstructions')
            ),

            // Additional metadata
            'metadata' => [
                'days_since_upload' => $this->created_at->diffInDays(now()),
                'hours_since_last_update' => $this->updated_at->diffInHours(now()),
                'processing_duration' => $this->getProcessingDuration(),
                'approval_duration' => $this->getApprovalDuration(),
            ],

            // Action URLs - conditionally included
            'actions' => $this->when(
                $this->shouldIncludeActions($request),
                $this->getAvailableActions($request)
            ),

            // Links for HATEOAS compliance
            'links' => [
                'self' => route('api.v1.mass-payment-files.show', $this->id),
                'payment_instructions' => route('api.v1.payment-instructions.index', ['file_id' => $this->id]),
                'download' => $this->when(
                    $this->canDownload($request),
                    route('api.v1.mass-payment-files.download', $this->id)
                ),
            ],
        ];
    }

    /**
     * Get display-friendly status name
     *
     * @param string $status
     * @return string
     */
    protected function getStatusDisplay(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'validating' => 'Validating',
            'validation_failed' => 'Validation Failed',
            'awaiting_approval' => 'Awaiting Approval',
            'approved' => 'Approved',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => 'Unknown',
        };
    }

    /**
     * Format file size for human readability
     *
     * @param int $bytes
     * @return string
     */
    protected function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);

        return round($bytes / pow(1024, $factor), 2) . ' ' . $units[$factor];
    }

    /**
     * Get validation error count
     *
     * @return int
     */
    protected function getValidationErrorCount(): int
    {
        if (!$this->validation_errors || !is_array($this->validation_errors)) {
            return 0;
        }

        $count = 0;

        // Count global errors
        if (isset($this->validation_errors['global_errors']) && is_array($this->validation_errors['global_errors'])) {
            $count += count($this->validation_errors['global_errors']);
        }

        // Count instruction errors
        if (isset($this->validation_errors['instruction_errors']) && is_array($this->validation_errors['instruction_errors'])) {
            foreach ($this->validation_errors['instruction_errors'] as $instructionErrors) {
                if (is_array($instructionErrors)) {
                    $count += count($instructionErrors);
                }
            }
        }

        return $count;
    }

    /**
     * Check if user can view validation errors
     *
     * @param Request $request
     * @return bool
     */
    protected function canViewValidationErrors(Request $request): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // User can view validation errors for their own client's files
        if ($user->client_id !== $this->client_id) {
            return false;
        }

        // Check specific permission if available
        if (method_exists($user, 'can')) {
            return $user->can('viewValidationErrors', $this->resource);
        }

        return true;
    }

    /**
     * Check if user can view user details
     *
     * @param Request $request
     * @return bool
     */
    protected function canViewUserDetails(Request $request): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Same client users can view each other's details
        return $user->client_id === $this->client_id;
    }

    /**
     * Check if user can download file
     *
     * @param Request $request
     * @return bool
     */
    protected function canDownload(Request $request): bool
    {
        $user = Auth::user();

        if (!$user || $user->client_id !== $this->client_id) {
            return false;
        }

        if (method_exists($user, 'can')) {
            return $user->can('download', $this->resource);
        }

        return true;
    }

    /**
     * Check if actions should be included in response
     *
     * @param Request $request
     * @return bool
     */
    protected function shouldIncludeActions(Request $request): bool
    {
        // Include actions for detail views or when explicitly requested
        return $request->route()->getName() === 'api.v1.mass-payment-files.show' ||
               $request->boolean('include_actions', false);
    }

    /**
     * Get available actions for the current user and file state
     *
     * @param Request $request
     * @return array
     */
    protected function getAvailableActions(Request $request): array
    {
        $user = Auth::user();
        $actions = [];

        if (!$user || $user->client_id !== $this->client_id) {
            return $actions;
        }

        // Approve action
        if ($this->canBeApproved() && method_exists($user, 'can') && $user->can('approve', $this->resource)) {
            $actions['approve'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.approve', $this->id),
                'method' => 'POST',
                'label' => 'Approve',
            ];
        }

        // Delete action
        if ($this->canBeDeleted() && method_exists($user, 'can') && $user->can('delete', $this->resource)) {
            $actions['delete'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.destroy', $this->id),
                'method' => 'DELETE',
                'label' => 'Delete',
                'confirm_message' => 'Are you sure you want to delete this payment file?',
            ];
        }

        // Download action
        if ($this->canDownload($request)) {
            $actions['download'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.download', $this->id),
                'method' => 'GET',
                'label' => 'Download',
            ];
        }

        // View validation errors action
        if ($this->hasValidationFailed() && $this->canViewValidationErrors($request)) {
            $actions['view_errors'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.validation-errors', $this->id),
                'method' => 'GET',
                'label' => 'View Validation Errors',
            ];
        }

        // Retry processing action
        if ($this->hasFailed() && method_exists($user, 'can') && $user->can('reprocess', $this->resource)) {
            $actions['retry'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.retry', $this->id),
                'method' => 'POST',
                'label' => 'Retry Processing',
            ];
        }

        // Cancel processing action
        if ($this->isProcessing() && method_exists($user, 'can') && $user->can('cancel', $this->resource)) {
            $actions['cancel'] = [
                'available' => true,
                'url' => route('api.v1.mass-payment-files.cancel', $this->id),
                'method' => 'POST',
                'label' => 'Cancel Processing',
                'confirm_message' => 'Are you sure you want to cancel processing?',
            ];
        }

        return $actions;
    }

    /**
     * Get processing duration in human-readable format
     *
     * @return string|null
     */
    protected function getProcessingDuration(): ?string
    {
        if (!$this->approved_at) {
            return null;
        }

        $endTime = $this->isCompleted() || $this->hasFailed() ? $this->updated_at : now();
        $duration = $this->approved_at->diffInSeconds($endTime);

        if ($duration < 60) {
            return $duration . ' seconds';
        } elseif ($duration < 3600) {
            return round($duration / 60) . ' minutes';
        } else {
            return round($duration / 3600, 1) . ' hours';
        }
    }

    /**
     * Get approval duration in human-readable format
     *
     * @return string|null
     */
    protected function getApprovalDuration(): ?string
    {
        if (!$this->approved_at) {
            return null;
        }

        $duration = $this->created_at->diffInSeconds($this->approved_at);

        if ($duration < 60) {
            return $duration . ' seconds';
        } elseif ($duration < 3600) {
            return round($duration / 60) . ' minutes';
        } elseif ($duration < 86400) {
            return round($duration / 3600, 1) . ' hours';
        } else {
            return round($duration / 86400, 1) . ' days';
        }
    }

    /**
     * Check if validation errors exist
     *
     * @return bool
     */
    protected function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    /**
     * Get additional attributes when needed
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'mass_payment_file',
                'api_version' => 'v1',
                'generated_at' => now()->toISOString(),
                'permissions' => $this->getUserPermissions($request),
            ],
        ];
    }

    /**
     * Get user permissions for this resource
     *
     * @