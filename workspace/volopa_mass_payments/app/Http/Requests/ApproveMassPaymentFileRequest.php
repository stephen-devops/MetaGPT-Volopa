## Code: app/Http/Requests/ApproveMassPaymentFileRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\MassPaymentFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ApproveMassPaymentFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Get the mass payment file from route parameter
        $massPaymentFile = $this->route('mass_payment_file') ?? $this->route('id');
        
        // If we have a string ID, find the model
        if (is_string($massPaymentFile)) {
            $massPaymentFile = MassPaymentFile::find($massPaymentFile);
        }

        // User must be authenticated and have approval permission
        if (!$this->user() || !$massPaymentFile) {
            return false;
        }

        // Use MassPaymentFilePolicy to check approval permission
        return $this->user()->can('approve', $massPaymentFile);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Approval confirmation - required for security
            'confirm_approval' => [
                'required',
                'boolean',
                'accepted',
            ],

            // Optional approval notes/comments
            'approval_notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            // Force approval flag for special cases (admin only)
            'force_approval' => [
                'sometimes',
                'boolean',
            ],

            // Override validation errors flag (admin only)
            'override_validation_errors' => [
                'sometimes',
                'boolean',
            ],

            // Notification preferences
            'notify_uploader' => [
                'sometimes',
                'boolean',
            ],

            'notify_finance_team' => [
                'sometimes',
                'boolean',
            ],

            // Custom validation rules based on file state
            'file_id' => [
                'sometimes',
                'string',
                'exists:mass_payment_files,id',
                function ($attribute, $value, $fail) {
                    $file = MassPaymentFile::find($value);
                    if (!$file) {
                        $fail('The specified mass payment file does not exist.');
                        return;
                    }

                    // Validate file belongs to user's client
                    $user = Auth::user();
                    if ($user && $user->client_id !== $file->client_id) {
                        $fail('The mass payment file does not belong to your organization.');
                        return;
                    }

                    // Validate file can be approved
                    if (!$file->canBeApproved()) {
                        $fail('The mass payment file cannot be approved in its current status.');
                        return;
                    }

                    // Validate user is not the uploader
                    if ($user && $user->id === $file->uploaded_by) {
                        $fail('You cannot approve a file that you uploaded.');
                        return;
                    }

                    // Check if file has validation errors and override is not set
                    if ($file->hasValidationFailed() && !$this->boolean('override_validation_errors')) {
                        $fail('The mass payment file has validation errors that must be resolved first.');
                        return;
                    }

                    // Validate TCC account has sufficient balance if required
                    if ($file->tccAccount && !$file->tccAccount->hasSufficientBalance($file->total_amount)) {
                        if (!$this->boolean('force_approval')) {
                            $fail('The TCC account has insufficient funds for this payment file.');
                            return;
                        }
                    }
                },
            ],

            // Additional security validation
            'approval_timestamp' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:' . now()->subMinutes(5)->toISOString(),
                'before_or_equal:' . now()->addMinutes(5)->toISOString(),
            ],

            // Risk assessment fields
            'risk_assessment' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['low', 'medium', 'high', 'critical']),
            ],

            'compliance_check' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get the validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Approval confirmation messages
            'confirm_approval.required' => 'You must confirm the approval action.',
            'confirm_approval.boolean' => 'Approval confirmation must be true or false.',
            'confirm_approval.accepted' => 'You must confirm that you want to approve this mass payment file.',

            // Approval notes messages
            'approval_notes.string' => 'Approval notes must be text.',
            'approval_notes.max' => 'Approval notes cannot exceed 1000 characters.',

            // Force approval messages
            'force_approval.boolean' => 'Force approval flag must be true or false.',

            // Override validation errors messages
            'override_validation_errors.boolean' => 'Override validation errors flag must be true or false.',

            // Notification messages
            'notify_uploader.boolean' => 'Uploader notification preference must be true or false.',
            'notify_finance_team.boolean' => 'Finance team notification preference must be true or false.',

            // File ID messages
            'file_id.string' => 'File ID must be a text value.',
            'file_id.exists' => 'The specified mass payment file does not exist.',

            // Timestamp messages
            'approval_timestamp.date' => 'Approval timestamp must be a valid date.',
            'approval_timestamp.after_or_equal' => 'Approval timestamp is too old.',
            'approval_timestamp.before_or_equal' => 'Approval timestamp is in the future.',

            // Risk assessment messages
            'risk_assessment.string' => 'Risk assessment must be text.',
            'risk_assessment.in' => 'Risk assessment must be low, medium, high, or critical.',

            // Compliance check messages
            'compliance_check.boolean' => 'Compliance check must be true or false.',
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
            'confirm_approval' => 'approval confirmation',
            'approval_notes' => 'approval notes',
            'force_approval' => 'force approval',
            'override_validation_errors' => 'override validation errors',
            'notify_uploader' => 'uploader notification',
            'notify_finance_team' => 'finance team notification',
            'file_id' => 'file ID',
            'approval_timestamp' => 'approval timestamp',
            'risk_assessment' => 'risk assessment',
            'compliance_check' => 'compliance check',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Get file ID from route if not provided in request
        $fileId = $this->get('file_id') ?? $this->route('mass_payment_file') ?? $this->route('id');
        
        if ($fileId) {
            $this->merge([
                'file_id' => is_string($fileId) ? $fileId : $fileId->id,
            ]);
        }

        // Set default values for optional fields
        $this->merge([
            'confirm_approval' => $this->boolean('confirm_approval', false),
            'force_approval' => $this->boolean('force_approval', false),
            'override_validation_errors' => $this->boolean('override_validation_errors', false),
            'notify_uploader' => $this->boolean('notify_uploader', true),
            'notify_finance_team' => $this->boolean('notify_finance_team', true),
            'compliance_check' => $this->boolean('compliance_check', false),
        ]);

        // Trim approval notes if provided
        if ($this->has('approval_notes')) {
            $this->merge([
                'approval_notes' => trim($this->get('approval_notes', '')),
            ]);
        }

        // Set approval timestamp to current time if not provided
        if (!$this->has('approval_timestamp')) {
            $this->merge([
                'approval_timestamp' => now()->toISOString(),
            ]);
        }

        // Normalize risk assessment
        if ($this->has('risk_assessment')) {
            $this->merge([
                'risk_assessment' => strtolower(trim($this->get('risk_assessment', ''))),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Additional approval validation
            $this->validateApprovalPermissions($validator);
            $this->validateFileStatus($validator);
            $this->validateBusinessRules($validator);
            $this->validateAdminOnlyFlags($validator);
        });
    }

    /**
     * Validate approval permissions based on user role and file state.
     */
    private function validateApprovalPermissions(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $user = Auth::user();
        $fileId = $this->get('file_id');
        
        if (!$user || !$fileId) {
            return;
        }

        $file = MassPaymentFile::find($fileId);
        if (!$file) {
            return;
        }

        // Check if user has approval permission for this specific file
        if (!$user->can('approve', $file)) {
            $validator->errors()->add(
                'file_id',
                'You do not have permission to approve this mass payment file.'
            );
        }

        // Additional role-based validations
        if ($this->boolean('force_approval') || $this->boolean('override_validation_errors')) {
            if (!$this->userHasAdminRole($user)) {
                $validator->errors()->add(
                    'force_approval',
                    'Only administrators can force approve files or override validation errors.'
                );
            }
        }
    }

    /**
     * Validate file status and business rules.
     */
    private function validateFileStatus(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $fileId = $this->get('file_id');
        
        if (!$fileId) {
            return;
        }

        $file = MassPaymentFile::find($fileId);
        if (!$file) {
            return;
        }

        // Validate file is in correct status for approval
        if (!$file->isAwaitingApproval() && !$this->boolean('force_approval')) {
            $validator->errors()->add(
                'file_id',
                'The mass payment file is not in a status that can be approved.'
            );
        }

        // Check if file has already been approved
        if ($file->isApproved()) {
            $validator->errors()->add(
                'file_id',
                'This mass payment file has already been approved.'
            );
        }

        // Validate file is not too old
        $maxAgeHours = config('mass-payments.max_approval_age_hours', 72);
        if ($file->created_at->diffInHours(now()) > $maxAgeHours) {
            if (!$this->boolean('force_approval')) {
                $validator->errors()->add(
                    'file_id',
                    "This mass payment file is too old to approve (older than {$maxAgeHours} hours)."
                );
            }
        }
    }

    /**
     * Validate business rules for approval.
     */
    private function validateBusinessRules(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $fileId = $this->get('file_id');
        
        if (!$fileId) {
            return;
        }

        $file = MassPaymentFile::find($fileId);
        if (!$file) {
            return;
        }

        // Check daily approval limits
        $user = Auth::user();
        if ($user) {
            $dailyApprovalLimit = config('mass-payments.daily_approval_limit', 50);
            $todayApprovals = MassPaymentFile::where('approved_by', $user->id)
                ->whereDate('approved_at', today())
                ->count();

            if ($todayApprovals >= $dailyApprovalLimit && !$this->boolean('force_approval')) {
                $validator->errors()->add(
                    'confirm_approval',
                    "You have reached your daily approval limit of {$dailyApprovalLimit} files."
                );
            }
        }

        // Check if file amount exceeds approval threshold
        $approvalThreshold = config('mass-payments.approval_threshold', 100000.00);
        if ($file->total_amount > $approvalThreshold) {
            if (!$this->has('risk_assessment') && !$this->boolean('force_approval')) {
                $validator->errors()->add(
                    'risk_assessment',
                    'Risk assessment is required for high-value payment files.'
                );
            }
        }

        // Validate compliance check for certain currencies
        $complianceCurrencies = config('mass-payments.compliance_required_currencies', ['USD', 'EUR']);
        if (in_array($file->currency, $complianceCurrencies)) {
            if (!$this->boolean('compliance_check') && !$this->boolean('force_approval')) {
                $validator->errors()->add(
                    'compliance_check',
                    'Compliance check is required for this currency.'
                );
            }
        }
    }

    /**
     * Validate admin-only flags.
     */
    private function validateAdminOnlyFlags(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $user = Auth::user();
        
        if (!$user) {
            return;
        }

        $adminOnlyFlags = [
            'force_approval',
            'override_validation_errors',
        ];

        foreach ($adminOnlyFlags as $flag) {
            if ($this->boolean($flag) && !$this->userHasAdminRole($user)) {
                $validator->errors()->add(
                    $flag,
                    'Only administrators can use this option.'
                );
            }
        }
    }

    /**
     * Check if user has admin role.
     */
    private function userHasAdminRole($user): bool
    {
        // Check if user has admin role using multiple methods
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole('admin') || $user->hasRole('super_admin');
        }

        if (isset($user->role)) {
            return in_array($user->role, ['admin', 'super_admin']);
        }

        if (method_exists($user, 'can')) {
            return $user->can('mass_payments.force_approve');
        }

        return false;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log approval validation failures for security auditing
        \Illuminate\Support\Facades\Log::warning('Mass payment file approval validation failed', [
            'user_id' => $this->user()?->id,
            'client_id' => $this->user()?->client_id,
            'file_id' => $this->get('file_id'),
            'errors' => $validator->errors()->toArray(),
            'input' => $this->except(['confirm_approval']), // Exclude sensitive flags
            'ip_address' => $this->ip(),
            