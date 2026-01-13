## Code: app/Http/Requests/ApproveMassPaymentFileRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\MassPaymentFile;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ApproveMassPaymentFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $massPaymentFile = $this->route('massPaymentFile');
        
        if (!$massPaymentFile instanceof MassPaymentFile) {
            return false;
        }

        // Use the MassPaymentFilePolicy to check if user can approve this mass payment file
        return Gate::allows('approve', $massPaymentFile);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'approval_notes' => [
                'nullable',
                'string',
                'max:1000'
            ],
            'force_approve' => [
                'nullable',
                'boolean'
            ],
            'override_currency_limits' => [
                'nullable',
                'boolean'
            ],
            'approved_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999999.99'
            ],
            'approval_level' => [
                'nullable',
                'string',
                Rule::in(['first_level', 'second_level', 'final_approval'])
            ],
            'conditions' => [
                'nullable',
                'array'
            ],
            'conditions.*.type' => [
                'required_with:conditions',
                'string',
                Rule::in(['amount_limit', 'currency_restriction', 'beneficiary_check', 'compliance_review'])
            ],
            'conditions.*.value' => [
                'required_with:conditions',
                'string',
                'max:500'
            ],
            'conditions.*.status' => [
                'required_with:conditions',
                'string',
                Rule::in(['pending', 'satisfied', 'waived'])
            ],
            'effective_date' => [
                'nullable',
                'date',
                'after_or_equal:today',
                'before_or_equal:' . now()->addDays(90)->format('Y-m-d')
            ],
            'priority' => [
                'nullable',
                'string',
                Rule::in(['low', 'normal', 'high', 'urgent'])
            ]
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'approval_notes.string' => 'Approval notes must be text.',
            'approval_notes.max' => 'Approval notes cannot exceed 1000 characters.',
            'force_approve.boolean' => 'Force approve must be true or false.',
            'override_currency_limits.boolean' => 'Override currency limits must be true or false.',
            'approved_amount.numeric' => 'Approved amount must be a valid number.',
            'approved_amount.min' => 'Approved amount cannot be negative.',
            'approved_amount.max' => 'Approved amount cannot exceed 999,999,999.99.',
            'approval_level.in' => 'Invalid approval level. Must be: first_level, second_level, or final_approval.',
            'conditions.array' => 'Conditions must be an array.',
            'conditions.*.type.required_with' => 'Condition type is required.',
            'conditions.*.type.in' => 'Invalid condition type. Must be: amount_limit, currency_restriction, beneficiary_check, or compliance_review.',
            'conditions.*.value.required_with' => 'Condition value is required.',
            'conditions.*.value.max' => 'Condition value cannot exceed 500 characters.',
            'conditions.*.status.required_with' => 'Condition status is required.',
            'conditions.*.status.in' => 'Invalid condition status. Must be: pending, satisfied, or waived.',
            'effective_date.date' => 'Effective date must be a valid date.',
            'effective_date.after_or_equal' => 'Effective date cannot be in the past.',
            'effective_date.before_or_equal' => 'Effective date cannot be more than 90 days in the future.',
            'priority.in' => 'Invalid priority. Must be: low, normal, high, or urgent.'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'approval_notes' => 'approval notes',
            'force_approve' => 'force approve flag',
            'override_currency_limits' => 'override currency limits flag',
            'approved_amount' => 'approved amount',
            'approval_level' => 'approval level',
            'conditions' => 'approval conditions',
            'conditions.*.type' => 'condition type',
            'conditions.*.value' => 'condition value',
            'conditions.*.status' => 'condition status',
            'effective_date' => 'effective date',
            'priority' => 'priority level'
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $massPaymentFile = $this->route('massPaymentFile');
            
            if ($massPaymentFile instanceof MassPaymentFile) {
                // Additional business logic validation
                $this->validateApprovalBusinessRules($validator, $massPaymentFile);
                $this->validateForceApprovalPermissions($validator, $massPaymentFile);
                $this->validateApprovedAmount($validator, $massPaymentFile);
                $this->validateCurrencyLimits($validator, $massPaymentFile);
                $this->validateApprovalConditions($validator, $massPaymentFile);
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        $defaults = [
            'force_approve' => false,
            'override_currency_limits' => false,
            'approval_level' => 'first_level',
            'priority' => 'normal'
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!$this->has($key)) {
                $this->merge([$key => $defaultValue]);
            }
        }

        // Trim approval notes
        if ($this->has('approval_notes') && is_string($this->input('approval_notes'))) {
            $this->merge([
                'approval_notes' => trim($this->input('approval_notes'))
            ]);
        }

        // Set default effective date if not provided
        if (!$this->has('effective_date') || empty($this->input('effective_date'))) {
            $this->merge([
                'effective_date' => now()->addDay()->format('Y-m-d')
            ]);
        }

        // Normalize conditions array
        if ($this->has('conditions') && is_array($this->input('conditions'))) {
            $normalizedConditions = [];
            foreach ($this->input('conditions') as $condition) {
                if (is_array($condition)) {
                    $normalizedConditions[] = [
                        'type' => $condition['type'] ?? '',
                        'value' => trim($condition['value'] ?? ''),
                        'status' => $condition['status'] ?? 'pending'
                    ];
                }
            }
            $this->merge(['conditions' => $normalizedConditions]);
        }
    }

    /**
     * Validate approval business rules.
     */
    private function validateApprovalBusinessRules($validator, MassPaymentFile $massPaymentFile): void
    {
        $user = $this->user();
        
        if (!$user) {
            $validator->errors()->add('authorization', 'User authentication required.');
            return;
        }

        // Check if file is in the correct status for approval
        if (!$massPaymentFile->isPendingApproval()) {
            $validator->errors()->add(
                'status',
                'This file cannot be approved. Current status: ' . $massPaymentFile->status
            );
        }

        // Check if file has validation errors (unless force approve is requested)
        if ($massPaymentFile->hasValidationErrors() && !$this->input('force_approve', false)) {
            $validator->errors()->add(
                'validation',
                'Cannot approve file with validation errors. Use force approve to override.'
            );
        }

        // Maker-checker validation: user cannot approve their own files
        if ($massPaymentFile->created_by === $user->id) {
            $validator->errors()->add(
                'maker_checker',
                'You cannot approve a file that you created (maker-checker principle).'
            );
        }

        // Check if file was already approved by this user (prevent double approval)
        $existingApproval = $massPaymentFile->approvals()
            ->where('approved_by', $user->id)
            ->where('status', 'approved')
            ->exists();

        if ($existingApproval) {
            $validator->errors()->add(
                'duplicate_approval',
                'You have already approved this file.'
            );
        }

        // Check client consistency
        if ($user->client_id !== $massPaymentFile->client_id) {
            $validator->errors()->add(
                'client_access',
                'You can only approve files from your own organization.'
            );
        }
    }

    /**
     * Validate force approval permissions.
     */
    private function validateForceApprovalPermissions($validator, MassPaymentFile $massPaymentFile): void
    {
        $forceApprove = $this->input('force_approve', false);
        
        if (!$forceApprove) {
            return;
        }

        $user = $this->user();
        
        if (!$user) {
            return;
        }

        // Check if user has permission to force approve
        if (!Gate::allows('forceApprove', $massPaymentFile)) {
            $validator->errors()->add(
                'force_approve',
                'You do not have permission to force approve this file.'
            );
        }

        // Force approval requires approval notes
        if (empty($this->input('approval_notes'))) {
            $validator->errors()->add(
                'approval_notes',
                'Approval notes are required when force approving a file.'
            );
        }
    }

    /**
     * Validate approved amount against file total.
     */
    private function validateApprovedAmount($validator, MassPaymentFile $massPaymentFile): void
    {
        $approvedAmount = $this->input('approved_amount');
        
        if ($approvedAmount === null) {
            return;
        }

        // Approved amount cannot exceed total file amount
        if ($approvedAmount > $massPaymentFile->total_amount) {
            $validator->errors()->add(
                'approved_amount',
                'Approved amount cannot exceed the total file amount of ' . 
                number_format($massPaymentFile->total_amount, 2) . ' ' . $massPaymentFile->currency
            );
        }

        // If approved amount is less than total, require explanation
        if ($approvedAmount < $massPaymentFile->total_amount && empty($this->input('approval_notes'))) {
            $validator->errors()->add(
                'approval_notes',
                'Approval notes are required when approving a partial amount.'
            );
        }
    }

    /**
     * Validate currency limits and restrictions.
     */
    private function validateCurrencyLimits($validator, MassPaymentFile $massPaymentFile): void
    {
        $user = $this->user();
        $overrideLimits = $this->input('override_currency_limits', false);
        
        if (!$user || $overrideLimits) {
            return;
        }

        // Get user's approval limits for this currency
        $userLimits = $this->getUserApprovalLimits($user, $massPaymentFile->currency);
        $fileAmount = $this->input('approved_amount', $massPaymentFile->total_amount);

        // Check single transaction limit
        if (isset($userLimits['single_transaction']) && $fileAmount > $userLimits['single_transaction']) {
            $validator->errors()->add(
                'approved_amount',
                'Amount exceeds your single transaction limit of ' . 
                number_format($userLimits['single_transaction'], 2) . ' ' . $massPaymentFile->currency
            );
        }

        // Check daily limit
        if (isset($userLimits['daily_limit'])) {
            $todayTotal = MassPaymentFile::where('client_id', $user->client_id)
                ->where('currency', $massPaymentFile->currency)
                ->where('status', MassPaymentFile::STATUS_APPROVED)
                ->whereDate('approved_at', now())
                ->sum('total_amount');
            
            if (($todayTotal + $fileAmount) > $userLimits['daily_limit']) {
                $validator->errors()->add(
                    'approved_amount',
                    'This approval would exceed your daily limit of ' . 
                    number_format($userLimits['daily_limit'], 2) . ' ' . $massPaymentFile->currency
                );
            }
        }
    }

    /**
     * Validate approval conditions.
     */
    private function validateApprovalConditions($validator, MassPaymentFile $massPaymentFile): void
    {
        $conditions = $this->input('conditions', []);
        
        if (empty($conditions)) {
            return;
        }

        foreach ($conditions as $index => $condition) {
            // Validate condition type specific rules
            switch ($condition['type']) {
                case 'amount_limit':
                    if (!is_numeric($condition['value'])) {
                        $validator->errors()->add(
                            "conditions.{$index}.value",
                            'Amount limit condition must be a numeric value.'
                        );
                    }
                    break;
                    
                case 'currency_restriction':
                    $validCurrencies = ['USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'CAD', 'JPY'];
                    if (!in_array(strtoupper($condition['value']), $validCurrencies)) {
                        $validator->errors()->add(
                            "conditions.{$index}.value",
                            'Invalid currency restriction.'
                        );
                    }
                    break;
                    
                case 'beneficiary_check':
                    if (strlen($condition['value']) < 3) {
                        $validator->errors()->add(
                            "conditions.{$index}.value",
                            'Beneficiary check condition must be at least 3 characters.'
                        );
                    }
                    break;
                    
                case 'compliance_review':
                    $validReviewTypes = ['aml_check', 'sanctions_screening', 'risk_assessment'];
                    if (!in_array($condition['value'], $validReviewTypes)) {
                        $validator->errors()->add(
                            "conditions.{$index}.value",
                            'Invalid compliance review type.'
                        );
                    }
                    break;
            }
        }
    }

    /**
     * Get user approval limits for the specified currency.
     */
    private function getUserApprovalLimits($user, string $currency): array
    {
        // Default limits based on user role
        $roleLimits = [
            'admin' => [
                'single_transaction' => PHP_FLOAT_MAX,
                'daily_limit' => PHP_FLOAT_MAX
            ],
            'finance_manager' => [
                'single_transaction' => 1000000.00,
                'daily_limit' => 5000000.00
            ],
            'approver' => [
                'single_transaction' =>