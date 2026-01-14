## Code: app/Http/Requests/ApproveMassPaymentFileRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\MassPaymentFile;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ApproveMassPaymentFileRequest extends FormRequest
{
    /**
     * The mass payment file being approved.
     */
    private ?MassPaymentFile $massPaymentFile = null;

    /**
     * Maximum approval comment length.
     */
    private const MAX_COMMENT_LENGTH = 1000;

    /**
     * Valid approval actions.
     */
    private const VALID_APPROVAL_ACTIONS = ['approve', 'reject'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $policy = new MassPaymentFilePolicy();
        $user = Auth::user();

        // Get the mass payment file from route parameter
        $massPaymentFileId = $this->route('mass_payment_file') ?? $this->route('id');
        
        if (!$massPaymentFileId) {
            return false;
        }

        try {
            // Load the mass payment file
            $this->massPaymentFile = MassPaymentFile::findOrFail($massPaymentFileId);

            // Check approval permissions
            if (!$policy->approve($user, $this->massPaymentFile)) {
                return false;
            }

            // Additional validation: check if file can be approved
            if (!$this->massPaymentFile->canBeApproved()) {
                return false;
            }

            // Check if this is a rejection and user has reject permission
            $action = $this->input('action', 'approve');
            if ($action === 'reject' && !$this->canRejectFile($user)) {
                return false;
            }

            return true;

        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(self::VALID_APPROVAL_ACTIONS),
            ],
            'approval_comment' => [
                'nullable',
                'string',
                'max:' . self::MAX_COMMENT_LENGTH,
                function ($attribute, $value, $fail) {
                    $this->validateApprovalComment($value, $fail);
                },
            ],
            'override_validations' => [
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    $this->validateOverridePermissions($value, $fail);
                },
            ],
            'force_approve' => [
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    $this->validateForceApprove($value, $fail);
                },
            ],
            'notification_settings' => [
                'nullable',
                'array',
            ],
            'notification_settings.notify_creator' => [
                'nullable',
                'boolean',
            ],
            'notification_settings.notify_admins' => [
                'nullable',
                'boolean',
            ],
            'notification_settings.send_webhook' => [
                'nullable',
                'boolean',
            ],
            'processing_priority' => [
                'nullable',
                'string',
                Rule::in(['low', 'normal', 'high', 'urgent']),
            ],
            'scheduled_processing_time' => [
                'nullable',
                'date',
                'after:now',
                function ($attribute, $value, $fail) {
                    $this->validateScheduledTime($value, $fail);
                },
            ],
            'approval_notes' => [
                'nullable',
                'array',
            ],
            'approval_notes.*.note' => [
                'required_with:approval_notes',
                'string',
                'max:500',
            ],
            'approval_notes.*.category' => [
                'required_with:approval_notes',
                'string',
                Rule::in(['general', 'risk', 'compliance', 'technical', 'business']),
            ],
            'risk_assessment' => [
                'nullable',
                'array',
            ],
            'risk_assessment.risk_level' => [
                'nullable',
                'string',
                Rule::in(['low', 'medium', 'high', 'critical']),
            ],
            'risk_assessment.risk_factors' => [
                'nullable',
                'array',
            ],
            'risk_assessment.mitigation_notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Please specify whether you want to approve or reject this file.',
            'action.string' => 'Action must be a valid string.',
            'action.in' => 'Action must be either "approve" or "reject".',

            'approval_comment.string' => 'Approval comment must be a valid string.',
            'approval_comment.max' => 'Approval comment cannot exceed ' . self::MAX_COMMENT_LENGTH . ' characters.',

            'override_validations.boolean' => 'Override validations setting must be true or false.',
            'force_approve.boolean' => 'Force approve setting must be true or false.',

            'notification_settings.array' => 'Notification settings must be a valid array.',
            'notification_settings.notify_creator.boolean' => 'Notify creator setting must be true or false.',
            'notification_settings.notify_admins.boolean' => 'Notify admins setting must be true or false.',
            'notification_settings.send_webhook.boolean' => 'Send webhook setting must be true or false.',

            'processing_priority.string' => 'Processing priority must be a valid string.',
            'processing_priority.in' => 'Processing priority must be one of: low, normal, high, urgent.',

            'scheduled_processing_time.date' => 'Scheduled processing time must be a valid date.',
            'scheduled_processing_time.after' => 'Scheduled processing time must be in the future.',

            'approval_notes.array' => 'Approval notes must be a valid array.',
            'approval_notes.*.note.required_with' => 'Note text is required when adding approval notes.',
            'approval_notes.*.note.string' => 'Each note must be a valid string.',
            'approval_notes.*.note.max' => 'Each note cannot exceed 500 characters.',
            'approval_notes.*.category.required_with' => 'Note category is required when adding approval notes.',
            'approval_notes.*.category.string' => 'Note category must be a valid string.',
            'approval_notes.*.category.in' => 'Note category must be one of: general, risk, compliance, technical, business.',

            'risk_assessment.array' => 'Risk assessment must be a valid array.',
            'risk_assessment.risk_level.string' => 'Risk level must be a valid string.',
            'risk_assessment.risk_level.in' => 'Risk level must be one of: low, medium, high, critical.',
            'risk_assessment.risk_factors.array' => 'Risk factors must be a valid array.',
            'risk_assessment.mitigation_notes.string' => 'Risk mitigation notes must be a valid string.',
            'risk_assessment.mitigation_notes.max' => 'Risk mitigation notes cannot exceed 1000 characters.',
        ];
    }

    /**
     * Get custom attribute names for validation messages.
     */
    public function attributes(): array
    {
        return [
            'action' => 'approval action',
            'approval_comment' => 'approval comment',
            'override_validations' => 'validation override',
            'force_approve' => 'force approval',
            'notification_settings' => 'notification settings',
            'processing_priority' => 'processing priority',
            'scheduled_processing_time' => 'scheduled processing time',
            'approval_notes' => 'approval notes',
            'risk_assessment' => 'risk assessment',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default action if not provided
        if (!$this->has('action')) {
            $this->merge(['action' => 'approve']);
        }

        // Set default notification settings
        if (!$this->has('notification_settings')) {
            $this->merge([
                'notification_settings' => [
                    'notify_creator' => true,
                    'notify_admins' => false,
                    'send_webhook' => true,
                ],
            ]);
        }

        // Set default processing priority
        if (!$this->has('processing_priority')) {
            $this->merge(['processing_priority' => 'normal']);
        }

        // Set default boolean values
        $this->merge([
            'override_validations' => $this->input('override_validations', false),
            'force_approve' => $this->input('force_approve', false),
        ]);

        // Normalize action to lowercase
        if ($this->has('action')) {
            $this->merge(['action' => strtolower($this->input('action'))]);
        }

        // Set default risk assessment for high-value files
        if (!$this->has('risk_assessment') && $this->massPaymentFile) {
            $totalAmount = $this->massPaymentFile->total_amount;
            $defaultRiskLevel = $this->determineDefaultRiskLevel($totalAmount);
            
            $this->merge([
                'risk_assessment' => [
                    'risk_level' => $defaultRiskLevel,
                    'risk_factors' => [],
                    'mitigation_notes' => null,
                ],
            ]);
        }
    }

    /**
     * Validate approval comment requirements.
     */
    private function validateApprovalComment(?string $comment, callable $fail): void
    {
        $action = $this->input('action', 'approve');

        // Rejection requires a comment
        if ($action === 'reject' && empty(trim($comment))) {
            $fail('A comment explaining the rejection reason is required.');
            return;
        }

        // High-value approvals require comments
        if ($this->massPaymentFile && $this->massPaymentFile->total_amount > 50000.00) {
            if ($action === 'approve' && empty(trim($comment))) {
                $fail('High-value payment approvals require an approval comment.');
                return;
            }
        }

        // Check for override validations comment requirement
        if ($this->input('override_validations', false) && empty(trim($comment))) {
            $fail('Overriding validations requires a detailed comment explaining the justification.');
        }
    }

    /**
     * Validate override permissions.
     */
    private function validateOverridePermissions(?bool $override, callable $fail): void
    {
        if (!$override) {
            return;
        }

        $user = Auth::user();

        // Check if user has permission to override validations
        if (!$this->hasOverridePermission($user)) {
            $fail('You do not have permission to override validation requirements.');
            return;
        }

        // Check if file has validation errors that can be overridden
        if ($this->massPaymentFile && !$this->massPaymentFile->hasValidationErrors()) {
            $fail('Cannot override validations for a file without validation errors.');
        }
    }

    /**
     * Validate force approve permissions and conditions.
     */
    private function validateForceApprove(?bool $forceApprove, callable $fail): void
    {
        if (!$forceApprove) {
            return;
        }

        $user = Auth::user();

        // Check if user has permission to force approve
        if (!$this->hasForceApprovePermission($user)) {
            $fail('You do not have permission to force approve payments.');
            return;
        }

        // Force approve is only for files with validation errors
        if ($this->massPaymentFile && !$this->massPaymentFile->hasValidationErrors()) {
            $fail('Force approve can only be used for files with validation errors.');
            return;
        }

        // Additional checks for force approve
        if ($this->massPaymentFile && $this->massPaymentFile->total_amount > 100000.00) {
            $fail('Force approve cannot be used for files with total amount exceeding $100,000.');
        }
    }

    /**
     * Validate scheduled processing time.
     */
    private function validateScheduledTime(?string $scheduledTime, callable $fail): void
    {
        if (!$scheduledTime) {
            return;
        }

        try {
            $scheduledDateTime = new \DateTime($scheduledTime);
            $now = new \DateTime();
            $maxFuture = new \DateTime('+30 days');

            // Check if scheduled time is too far in the future
            if ($scheduledDateTime > $maxFuture) {
                $fail('Scheduled processing time cannot be more than 30 days in the future.');
                return;
            }

            // Check business hours for non-urgent processing
            $priority = $this->input('processing_priority', 'normal');
            if ($priority !== 'urgent' && !$this->isBusinessHours($scheduledDateTime)) {
                $fail('Non-urgent payments can only be scheduled during business hours (9 AM - 5 PM, Monday to Friday).');
            }

        } catch (\Exception $e) {
            $fail('Invalid scheduled processing time format.');
        }
    }

    /**
     * Check if user can reject files.
     */
    private function canRejectFile($user): bool
    {
        // Check if user has reject permission
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission('mass_payments.reject');
        }

        // Check user roles
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('mass_payment_approver') || 
                   $user->hasRole('mass_payment_manager') || 
                   $user->hasRole('mass_payment_admin');
        }

        // Default: if user can approve, they can reject
        return true;
    }

    /**
     * Check if user has override permission.
     */
    private function hasOverridePermission($user): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission('mass_payments.override_validations');
        }

        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('mass_payment_admin') || $user->hasRole('super_admin');
        }

        return false;
    }

    /**
     * Check if user has force approve permission.
     */
    private function hasForceApprovePermission($user): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission('mass_payments.force_approve');
        }

        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('mass_payment_admin') || $user->hasRole('super_admin');
        }

        return false;
    }

    /**
     * Determine default risk level based on amount.
     */
    private function determineDefaultRiskLevel(float $amount): string
    {
        if ($amount >= 500000.00) {
            return 'critical';
        } elseif ($amount >= 100000.00) {
            return 'high';
        } elseif ($amount >= 25000.00) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Check if datetime is within business hours.
     */
    private function isBusinessHours(\DateTime $dateTime): bool
    {
        $dayOfWeek = (int) $dateTime