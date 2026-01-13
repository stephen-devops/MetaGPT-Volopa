## Code: app/Http/Resources/PaymentInstructionResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class PaymentInstructionResource extends JsonResource
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
            'mass_payment_file_id' => $this->mass_payment_file_id,
            'beneficiary_id' => $this->beneficiary_id,
            'amount' => (float) $this->amount,
            'amount_formatted' => $this->getFormattedAmountAttribute(),
            'currency' => $this->currency,
            'purpose_code' => $this->purpose_code,
            'purpose_code_display' => $this->getPurposeCodeDisplay($this->purpose_code),
            'reference' => $this->reference,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplayAttribute(),
            'validation_errors' => $this->when(
                $this->hasValidationErrors() && $this->canViewValidationErrors($request),
                $this->validation_errors
            ),
            'validation_error_count' => $this->getValidationErrorCountAttribute(),
            'first_validation_error' => $this->when(
                $this->hasValidationErrors() && $this->canViewValidationErrors($request),
                $this->getFirstValidationErrorAttribute()
            ),
            'row_number' => $this->row_number,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Status flags
            'is_pending' => $this->isPending(),
            'is_validated' => $this->isValidated(),
            'has_validation_failed' => $this->hasValidationFailed(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'has_failed' => $this->hasFailed(),
            'is_cancelled' => $this->isCancelled(),
            'can_be_processed' => $this->canBeProcessed(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'is_in_final_state' => $this->isInFinalState(),
            'is_processable' => $this->isProcessable(),

            // Related data - conditionally loaded
            'mass_payment_file' => $this->whenLoaded('massPaymentFile', function () {
                return [
                    'id' => $this->massPaymentFile->id,
                    'original_filename' => $this->massPaymentFile->original_filename,
                    'status' => $this->massPaymentFile->status,
                    'status_display' => $this->getFileStatusDisplay($this->massPaymentFile->status),
                    'currency' => $this->massPaymentFile->currency,
                    'total_amount' => (float) $this->massPaymentFile->total_amount,
                    'created_at' => $this->massPaymentFile->created_at->toISOString(),
                    'approved_at' => $this->massPaymentFile->approved_at?->toISOString(),
                ];
            }),

            'beneficiary' => $this->whenLoaded('beneficiary', function () {
                return $this->beneficiary ? [
                    'id' => $this->beneficiary->id,
                    'name' => $this->beneficiary->name,
                    'account_number' => $this->when(
                        $this->canViewSensitiveData($request),
                        $this->beneficiary->account_number,
                        $this->maskAccountNumber($this->beneficiary->account_number ?? '')
                    ),
                    'bank_code' => $this->beneficiary->bank_code,
                    'country' => $this->beneficiary->country,
                    'address' => $this->when(
                        $this->canViewSensitiveData($request),
                        $this->beneficiary->address
                    ),
                    'city' => $this->beneficiary->city,
                    'created_at' => $this->beneficiary->created_at->toISOString(),
                ] : null;
            }),

            // Processing metadata
            'processing_info' => [
                'retry_count' => $this->retry_count ?? 0,
                'last_processed_at' => $this->last_processed_at?->toISOString(),
                'transaction_id' => $this->when(
                    $this->isCompleted() && $this->canViewTransactionDetails($request),
                    $this->transaction_id
                ),
                'processing_time_ms' => $this->processing_time_ms ?? null,
                'error_code' => $this->when(
                    $this->hasFailed(),
                    $this->error_code
                ),
                'error_message' => $this->when(
                    $this->hasFailed() && $this->canViewValidationErrors($request),
                    $this->error_message
                ),
            ],

            // Additional metadata
            'metadata' => [
                'days_since_creation' => $this->created_at->diffInDays(now()),
                'hours_since_last_update' => $this->updated_at->diffInHours(now()),
                'processing_duration' => $this->getProcessingDuration(),
                'has_sensitive_data' => $this->hasSensitiveData(),
                'risk_level' => $this->getRiskLevel(),
            ],

            // Action URLs - conditionally included
            'actions' => $this->when(
                $this->shouldIncludeActions($request),
                $this->getAvailableActions($request)
            ),

            // Links for HATEOAS compliance
            'links' => [
                'self' => route('api.v1.payment-instructions.show', $this->id),
                'mass_payment_file' => route('api.v1.mass-payment-files.show', $this->mass_payment_file_id),
                'beneficiary' => $this->when(
                    $this->beneficiary_id,
                    route('api.v1.beneficiaries.show', $this->beneficiary_id)
                ),
            ],
        ];
    }

    /**
     * Get display-friendly purpose code name
     *
     * @param string|null $purposeCode
     * @return string|null
     */
    protected function getPurposeCodeDisplay(?string $purposeCode): ?string
    {
        if (!$purposeCode) {
            return null;
        }

        $purposeCodes = config('mass-payments.purpose_codes', []);
        return $purposeCodes[$purposeCode] ?? $purposeCode;
    }

    /**
     * Get display-friendly file status name
     *
     * @param string $status
     * @return string
     */
    protected function getFileStatusDisplay(string $status): string
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

        // User can view validation errors for instructions in their client's files
        if (method_exists($user, 'client_id') && isset($user->client_id)) {
            // Check through mass payment file relationship
            if ($this->relationLoaded('massPaymentFile') && $this->massPaymentFile) {
                return $user->client_id === $this->massPaymentFile->client_id;
            }
        }

        // Check specific permission if available
        if (method_exists($user, 'can')) {
            return $user->can('viewValidationErrors', $this->resource);
        }

        return true;
    }

    /**
     * Check if user can view sensitive data
     *
     * @param Request $request
     * @return bool
     */
    protected function canViewSensitiveData(Request $request): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Check if user belongs to same client
        if (method_exists($user, 'client_id') && isset($user->client_id)) {
            if ($this->relationLoaded('massPaymentFile') && $this->massPaymentFile) {
                if ($user->client_id !== $this->massPaymentFile->client_id) {
                    return false;
                }
            }
        }

        // Check specific permission if available
        if (method_exists($user, 'can')) {
            return $user->can('viewSensitiveData', $this->resource);
        }

        // Default to true if same client
        return true;
    }

    /**
     * Check if user can view transaction details
     *
     * @param Request $request
     * @return bool
     */
    protected function canViewTransactionDetails(Request $request): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Same client users can view transaction details
        if (method_exists($user, 'client_id') && isset($user->client_id)) {
            if ($this->relationLoaded('massPaymentFile') && $this->massPaymentFile) {
                return $user->client_id === $this->massPaymentFile->client_id;
            }
        }

        return true;
    }

    /**
     * Mask account number for security
     *
     * @param string $accountNumber
     * @return string
     */
    protected function maskAccountNumber(string $accountNumber): string
    {
        if (strlen($accountNumber) <= 4) {
            return $accountNumber;
        }

        return '****' . substr($accountNumber, -4);
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
        return $request->route()->getName() === 'api.v1.payment-instructions.show' ||
               $request->boolean('include_actions', false);
    }

    /**
     * Get available actions for the current user and instruction state
     *
     * @param Request $request
     * @return array
     */
    protected function getAvailableActions(Request $request): array
    {
        $user = Auth::user();
        $actions = [];

        if (!$user) {
            return $actions;
        }

        // Check client access
        $hasClientAccess = false;
        if (method_exists($user, 'client_id') && isset($user->client_id)) {
            if ($this->relationLoaded('massPaymentFile') && $this->massPaymentFile) {
                $hasClientAccess = $user->client_id === $this->massPaymentFile->client_id;
            }
        }

        if (!$hasClientAccess) {
            return $actions;
        }

        // Cancel action
        if ($this->canBeCancelled() && method_exists($user, 'can') && $user->can('cancel', $this->resource)) {
            $actions['cancel'] = [
                'available' => true,
                'url' => route('api.v1.payment-instructions.cancel', $this->id),
                'method' => 'POST',
                'label' => 'Cancel Payment',
                'confirm_message' => 'Are you sure you want to cancel this payment instruction?',
            ];
        }

        // Retry action
        if ($this->hasFailed() && method_exists($user, 'can') && $user->can('retry', $this->resource)) {
            $actions['retry'] = [
                'available' => true,
                'url' => route('api.v1.payment-instructions.retry', $this->id),
                'method' => 'POST',
                'label' => 'Retry Payment',
            ];
        }

        // View details action
        if (method_exists($user, 'can') && $user->can('view', $this->resource)) {
            $actions['view_details'] = [
                'available' => true,
                'url' => route('api.v1.payment-instructions.show', $this->id),
                'method' => 'GET',
                'label' => 'View Details',
            ];
        }

        // View validation errors action
        if ($this->hasValidationFailed() && $this->canViewValidationErrors($request)) {
            $actions['view_errors'] = [
                'available' => true,
                'url' => route('api.v1.payment-instructions.validation-errors', $this->id),
                'method' => 'GET',
                'label' => 'View Validation Errors',
            ];
        }

        // Download receipt action (for completed payments)
        if ($this->isCompleted() && method_exists($user, 'can') && $user->can('downloadReceipt', $this->resource)) {
            $actions['download_receipt'] = [
                'available' => true,
                'url' => route('api.v1.payment-instructions.receipt', $this->id),
                'method' => 'GET',
                'label' => 'Download Receipt',
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
        if (!$this->isCompleted() && !$this->hasFailed()) {
            return null;
        }

        $startTime = $this->updated_at;
        $endTime = now();

        // Try to get more accurate processing timestamps if available
        if (isset($this->processing_started_at)) {
            $startTime = $this->processing_started_at;
        }

        if (isset($this->processing_completed_at)) {
            $endTime = $this->processing_completed_at;
        }

        $duration = $startTime->diffInSeconds($endTime);

        if ($duration < 1) {
            return 'Less than 1 second';
        } elseif ($duration < 60) {
            return $duration . ' seconds';
        } elseif ($duration < 3600) {
            return round($duration / 60) . ' minutes';
        } else {
            return round($duration / 3600, 1) . ' hours';
        }
    }

    /**
     * Check if instruction contains sensitive data
     *
     * @return bool
     */
    protected function hasSensitiveData(): bool
    {
        // Consider high amounts as sensitive
        $sensitiveAmountThreshold = config('mass-payments.security.sensitive_amount_threshold', 10000.00);
        
        if ($this->amount >= $sensitiveAmountThreshold) {
            return true;
        }

        // Consider certain purpose codes as sensitive
        $sensitivePurposeCodes = config('mass-payments.security.sensitive_purpose_codes', ['LOAN', 'INVE', 'DIVD']);
        
        if (in_array($this->purpose_code, $sensitivePurposeCodes)) {
            return true;
        }

        return false;
    }

    /**
     * Get risk level assessment
     *
     * @return string
     */
    protected function getRiskLevel(): string
    {
        $riskScore = 0;