<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PaymentFile;
use App\Models\Approval;

class ApprovalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Get the payment file from the route parameter
        $paymentFileId = $this->route('id');
        $paymentFile = PaymentFile::find($paymentFileId);

        if (!$paymentFile) {
            return false;
        }

        // Check if user can approve/reject payment files using policy
        return $this->user() && $this->user()->can('approve', $paymentFile);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(['approve', 'reject']),
            ],
            'comments' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'An action (approve or reject) is required.',
            'action.string' => 'The action must be a valid string.',
            'action.in' => 'The action must be either "approve" or "reject".',
            'comments.string' => 'Comments must be a valid string.',
            'comments.max' => 'Comments cannot exceed 1000 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'approval action',
            'comments' => 'approval comments',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert action to lowercase for consistency
        if ($this->has('action')) {
            $this->merge([
                'action' => strtolower(trim($this->action)),
            ]);
        }

        // Trim comments if provided
        if ($this->has('comments') && $this->comments !== null) {
            $this->merge([
                'comments' => trim($this->comments),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateApprovalEligibility($validator);
            $this->validateDuplicateApproval($validator);
            $this->validateRejectionComments($validator);
        });
    }

    /**
     * Validate that the payment file is eligible for approval/rejection.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateApprovalEligibility($validator): void
    {
        $paymentFileId = $this->route('id');
        $paymentFile = PaymentFile::find($paymentFileId);

        if (!$paymentFile) {
            $validator->errors()->add('payment_file', 'Payment file not found.');
            return;
        }

        // Check if the payment file requires approval
        if (!$paymentFile->requiresApproval()) {
            $validator->errors()->add('payment_file', 
                'This payment file does not require approval or has already been processed.'
            );
            return;
        }

        // Check if the payment file has any critical validation errors
        if ($paymentFile->validationErrors()->exists() && $this->action === 'approve') {
            $criticalErrorsCount = $paymentFile->validationErrors()
                ->whereIn('error_code', [
                    'REQUIRED_FIELD',
                    'INVALID_CURRENCY',
                    'INVALID_AMOUNT',
                    'INVALID_ACCOUNT',
                    'UNSUPPORTED_CURRENCY_SETTLEMENT'
                ])
                ->count();

            if ($criticalErrorsCount > 0) {
                $validator->errors()->add('payment_file', 
                    'Cannot approve a payment file that contains critical validation errors. Please fix the errors first.'
                );
            }
        }

        // Check if the payment file has any payment instructions
        if ($paymentFile->paymentInstructions()->count() === 0 && $this->action === 'approve') {
            $validator->errors()->add('payment_file', 
                'Cannot approve a payment file with no valid payment instructions.'
            );
        }
    }

    /**
     * Validate that the user hasn't already approved/rejected this file.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateDuplicateApproval($validator): void
    {
        $paymentFileId = $this->route('id');
        $userId = $this->user()->id ?? null;

        if (!$userId) {
            return;
        }

        $existingApproval = Approval::where('payment_file_id', $paymentFileId)
            ->where('approver_id', $userId)
            ->whereIn('status', [Approval::STATUS_APPROVED, Approval::STATUS_REJECTED])
            ->first();

        if ($existingApproval) {
            $status = $existingApproval->status === Approval::STATUS_APPROVED ? 'approved' : 'rejected';
            $validator->errors()->add('approval', 
                "You have already {$status} this payment file."
            );
        }
    }

    /**
     * Validate that rejection includes comments.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateRejectionComments($validator): void
    {
        if ($this->action === 'reject' && empty(trim($this->comments ?? ''))) {
            $validator->errors()->add('comments', 
                'Comments are required when rejecting a payment file.'
            );
        }
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You do not have permission to approve or reject this payment file.'
        );
    }

    /**
     * Get the validated data with additional processing.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);
        
        // Ensure comments is null if empty string
        if (isset($validated['comments']) && trim($validated['comments']) === '') {
            $validated['comments'] = null;
        }

        return $validated;
    }

    /**
     * Get the approval action from the request.
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->input('action', 'approve');
    }

    /**
     * Get the approval comments from the request.
     *
     * @return string|null
     */
    public function getComments(): ?string
    {
        $comments = $this->input('comments');
        return $comments !== null ? trim($comments) : null;
    }

    /**
     * Check if the action is to approve.
     *
     * @return bool
     */
    public function isApprovalAction(): bool
    {
        return $this->getAction() === 'approve';
    }

    /**
     * Check if the action is to reject.
     *
     * @return bool
     */
    public function isRejectionAction(): bool
    {
        return $this->getAction() === 'reject';
    }

    /**
     * Get the payment file from the route.
     *
     * @return PaymentFile|null
     */
    public function getPaymentFile(): ?PaymentFile
    {
        $paymentFileId = $this->route('id');
        return PaymentFile::find($paymentFileId);
    }

    /**
     * Get the approver user.
     *
     * @return \App\Models\User|null
     */
    public function getApprover()
    {
        return $this->user();
    }

    /**
     * Check if the request has valid comments.
     *
     * @return bool
     */
    public function hasComments(): bool
    {
        return !empty(trim($this->getComments() ?? ''));
    }

    /**
     * Get validation rules for testing purposes.
     *
     * @return array<string, array>
     */
    public static function getValidationRules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(['approve', 'reject']),
            ],
            'comments' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * Get the available actions.
     *
     * @return array<string>
     */
    public static function getAvailableActions(): array
    {
        return ['approve', 'reject'];
    }

    /**
     * Validate that the current user can perform the requested action.
     *
     * @return bool
     */
    public function canPerformAction(): bool
    {
        $paymentFile = $this->getPaymentFile();
        
        if (!$paymentFile) {
            return false;
        }

        return $this->user() && $this->user()->can('approve', $paymentFile);
    }

    /**
     * Get contextual validation error message for the payment file status.
     *
     * @return string|null
     */
    public function getStatusValidationMessage(): ?string
    {
        $paymentFile = $this->getPaymentFile();
        
        if (!$paymentFile) {
            return 'Payment file not found.';
        }

        switch ($paymentFile->status) {
            case PaymentFile::STATUS_UPLOADED:
                return 'Payment file is still being processed and is not ready for approval.';
            
            case PaymentFile::STATUS_PROCESSING:
                return 'Payment file is currently being processed and cannot be approved at this time.';
            
            case PaymentFile::STATUS_APPROVED:
                return 'Payment file has already been approved.';
            
            case PaymentFile::STATUS_REJECTED:
                return 'Payment file has already been rejected.';
            
            case PaymentFile::STATUS_COMPLETED:
                return 'Payment file has already been processed and completed.';
            
            case PaymentFile::STATUS_FAILED:
                return 'Payment file processing failed and cannot be approved.';
            
            default:
                return null;
        }
    }

    /**
     * Get detailed validation context for API responses.
     *
     * @return array<string, mixed>
     */
    public function getValidationContext(): array
    {
        $paymentFile = $this->getPaymentFile();
        
        if (!$paymentFile) {
            return [
                'can_approve' => false,
                'can_reject' => false,
                'reason' => 'Payment file not found',
            ];
        }

        $canApprove = $paymentFile->requiresApproval() && 
                     $paymentFile->validationErrors()->where('error_code', 'CRITICAL')->count() === 0;
        
        $canReject = $paymentFile->requiresApproval();

        return [
            'can_approve' => $canApprove,
            'can_reject' => $canReject,
            'current_status' => $paymentFile->status,
            'has_critical_errors' => $paymentFile->validationErrors()->exists(),
            'total_amount' => $paymentFile->total_amount,
            'currency' => $paymentFile->currency,
            'total_records' => $paymentFile->total_records,
            'valid_records' => $paymentFile->valid_records,
            'invalid_records' => $paymentFile->invalid_records,
        ];
    }
}