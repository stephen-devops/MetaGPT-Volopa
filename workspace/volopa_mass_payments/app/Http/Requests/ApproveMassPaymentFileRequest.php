## Code: app/Http/Requests/ApproveMassPaymentFileRequest.php

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\MassPaymentFile;
use App\Policies\MassPaymentFilePolicy;

class ApproveMassPaymentFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Get the mass payment file from route parameter
        $massPaymentFile = $this->route('mass_payment_file') ?? MassPaymentFile::find($this->route('id'));
        
        if (!$massPaymentFile) {
            return false;
        }

        // Check if user can approve this specific mass payment file using policy
        return $this->user()->can('approve', $massPaymentFile);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'approval_comments' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'force_approve' => [
                'sometimes',
                'boolean',
            ],
            'override_validation_errors' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'approval_comments.string' => 'The approval comments must be a valid text.',
            'approval_comments.max' => 'The approval comments must not exceed 1000 characters.',
            'force_approve.boolean' => 'The force approve field must be true or false.',
            'override_validation_errors.boolean' => 'The override validation errors field must be true or false.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'approval_comments' => 'approval comments',
            'force_approve' => 'force approval',
            'override_validation_errors' => 'override validation errors',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure boolean fields are properly cast
        if ($this->has('force_approve')) {
            $this->merge([
                'force_approve' => filter_var($this->force_approve, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        if ($this->has('override_validation_errors')) {
            $this->merge([
                'override_validation_errors' => filter_var($this->override_validation_errors, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Get the mass payment file
            $massPaymentFile = $this->route('mass_payment_file') ?? MassPaymentFile::find($this->route('id'));
            
            if (!$massPaymentFile) {
                $validator->errors()->add('file', 'Mass payment file not found.');
                return;
            }

            // Check if file is in a state that can be approved
            if (!in_array($massPaymentFile->status, ['validated', 'pending_approval', 'validation_failed'])) {
                $validator->errors()->add('status', 'This file cannot be approved in its current status: ' . $massPaymentFile->status);
            }

            // Check if file belongs to user's client
            if ($massPaymentFile->client_id !== $this->user()->client_id) {
                $validator->errors()->add('authorization', 'You do not have permission to approve this file.');
            }

            // If file has validation errors and override is not requested
            if ($massPaymentFile->hasValidationErrors() && !$this->input('override_validation_errors', false)) {
                $validator->errors()->add('validation_errors', 'This file has validation errors that must be resolved or overridden before approval.');
            }

            // Check if file has already been approved
            if ($massPaymentFile->isApproved()) {
                $validator->errors()->add('already_approved', 'This file has already been approved.');
            }

            // Check if file has been rejected
            if ($massPaymentFile->isRejected()) {
                $validator->errors()->add('rejected', 'This file has been rejected and cannot be approved.');
            }

            // Validate TCC account has sufficient balance if not force approving
            if (!$this->input('force_approve', false)) {
                $tccAccount = $massPaymentFile->tccAccount;
                
                if (!$tccAccount) {
                    $validator->errors()->add('tcc_account', 'Associated TCC account not found.');
                } elseif ($tccAccount->status !== 'active') {
                    $validator->errors()->add('tcc_account', 'Associated TCC account is not active.');
                } elseif ($tccAccount->balance < $massPaymentFile->total_amount) {
                    $validator->errors()->add('insufficient_balance', 
                        'TCC account has insufficient balance. Required: ' . 
                        number_format($massPaymentFile->total_amount, 2) . ' ' . $massPaymentFile->currency . 
                        ', Available: ' . number_format($tccAccount->balance, 2) . ' ' . $tccAccount->currency
                    );
                }
            }

            // Check if user is trying to approve their own upload
            if ($massPaymentFile->uploaded_by === $this->user()->id && !$this->input('force_approve', false)) {
                $validator->errors()->add('self_approval', 'You cannot approve a file that you uploaded yourself unless force approval is enabled.');
            }

            // Validate user has approver role/permission
            $user = $this->user();
            if (!$user->hasRole('approver') && !$user->hasPermission('approve_mass_payments') && !$this->input('force_approve', false)) {
                $validator->errors()->add('insufficient_permissions', 'You do not have the required approver permissions.');
            }

            // Check if there are any pending payment instructions with errors
            if (!$this->input('override_validation_errors', false)) {
                $errorCount = $massPaymentFile->paymentInstructions()
                                             ->where('status', 'failed_validation')
                                             ->count();
                
                if ($errorCount > 0) {
                    $validator->errors()->add('payment_errors', 
                        "There are {$errorCount} payment instructions with validation errors that must be resolved."
                    );
                }
            }

            // Check for duplicate beneficiaries in the same file (business rule validation)
            $duplicateCount = $massPaymentFile->paymentInstructions()
                                             ->selectRaw('beneficiary_id, COUNT(*) as count')
                                             ->groupBy('beneficiary_id')
                                             ->having('count', '>', 1)
                                             ->count();
            
            if ($duplicateCount > 0 && !$this->input('override_validation_errors', false)) {
                $validator->errors()->add('duplicate_beneficiaries', 
                    'This file contains duplicate payments to the same beneficiary which requires override approval.'
                );
            }

            // Validate daily/monthly limits if applicable
            if (!$this->input('force_approve', false)) {
                $this->validatePaymentLimits($validator, $massPaymentFile);
            }
        });
    }

    /**
     * Validate payment limits for the client and TCC account.
     */
    private function validatePaymentLimits($validator, MassPaymentFile $massPaymentFile): void
    {
        $tccAccount = $massPaymentFile->tccAccount;
        $currency = $massPaymentFile->currency;
        $amount = $massPaymentFile->total_amount;

        // Check daily limit
        $dailyTotal = MassPaymentFile::where('tcc_account_id', $massPaymentFile->tcc_account_id)
                                   ->where('currency', $currency)
                                   ->where('status', 'approved')
                                   ->whereDate('approved_at', today())
                                   ->sum('total_amount');

        $dailyLimit = $this->getDailyLimit($tccAccount, $currency);
        if ($dailyLimit && ($dailyTotal + $amount) > $dailyLimit) {
            $validator->errors()->add('daily_limit', 
                "This approval would exceed the daily limit of " . 
                number_format($dailyLimit, 2) . " {$currency}. " .
                "Current daily total: " . number_format($dailyTotal, 2) . " {$currency}."
            );
        }

        // Check monthly limit
        $monthlyTotal = MassPaymentFile::where('tcc_account_id', $massPaymentFile->tcc_account_id)
                                     ->where('currency', $currency)
                                     ->where('status', 'approved')
                                     ->whereMonth('approved_at', now()->month)
                                     ->whereYear('approved_at', now()->year)
                                     ->sum('total_amount');

        $monthlyLimit = $this->getMonthlyLimit($tccAccount, $currency);
        if ($monthlyLimit && ($monthlyTotal + $amount) > $monthlyLimit) {
            $validator->errors()->add('monthly_limit', 
                "This approval would exceed the monthly limit of " . 
                number_format($monthlyLimit, 2) . " {$currency}. " .
                "Current monthly total: " . number_format($monthlyTotal, 2) . " {$currency}."
            );
        }
    }

    /**
     * Get daily limit for TCC account and currency.
     */
    private function getDailyLimit($tccAccount, string $currency): ?float
    {
        // This would typically come from account settings or configuration
        $limits = [
            'GBP' => 100000.00,
            'EUR' => 100000.00,
            'USD' => 100000.00,
            'INR' => 10000000.00,
        ];

        return $limits[$currency] ?? null;
    }

    /**
     * Get monthly limit for TCC account and currency.
     */
    private function getMonthlyLimit($tccAccount, string $currency): ?float
    {
        // This would typically come from account settings or configuration
        $limits = [
            'GBP' => 1000000.00,
            'EUR' => 1000000.00,
            'USD' => 1000000.00,
            'INR' => 100000000.00,
        ];

        return $limits[$currency] ?? null;
    }

    /**
     * Get validated data with additional processed information.
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();
        
        // Add approver information
        $validated['approved_by'] = $this->user()->id;
        $validated['approved_at'] = now();
        
        // Set default values for optional fields
        $validated['approval_comments'] = $validated['approval_comments'] ?? null;
        $validated['force_approve'] = $validated['force_approve'] ?? false;
        $validated['override_validation_errors'] = $validated['override_validation_errors'] ?? false;
        
        return $key ? data_get($validated, $key, $default) : $validated;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log the validation failure for monitoring
        \Log::warning('Mass payment file approval validation failed', [
            'user_id' => $this->user()->id,
            'client_id' => $this->user()->client_id,
            'file_id' => $this->route('id'),
            'errors' => $validator->errors()->toArray(),
            'input' => $this->all(),
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        // Log the authorization failure for monitoring
        \Log::warning('Mass payment file approval authorization failed', [
            'user_id' => $this->user()->id,
            'client_id' => $this->user()->client_id ?? 'unknown',
            'file_id' => $this->route('id'),
        ]);

        parent::failedAuthorization();
    }

    /**
     * Get the mass payment file being approved.
     */
    public function getMassPaymentFile(): ?MassPaymentFile
    {
        return $this->route('mass_payment_file') ?? MassPaymentFile::find($this->route('id'));
    }

    /**
     * Check if this is a force approval request.
     */
    public function isForceApproval(): bool
    {
        return $this->input('force_approve', false);
    }

    /**
     * Check if validation errors should be overridden.
     */
    public function shouldOverrideValidationErrors(): bool
    {
        return $this->input('override_validation_errors', false);
    }

    /**
     * Get approval metadata for logging.
     */
    public function getApprovalMetadata(): array
    {
        return [
            'approver_id' => $this->user()->id,
            'approver_name' => $this->user()->name,
            'approval_timestamp' => now()->toISOString(),
            'force_approve' => $this->isForceApproval(),
            'override_validation_errors' => $this->shouldOverrideValidationErrors(),
            'approval_comments' => $this->input('approval_comments'),
            'client_id' => $this->user()->client_id,
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ];
    }

    /**
     * Get summary of what will be approved.
     */
    public function getApprovalSummary(): array
    {
        $file = $this->getMassPaymentFile();
        
        if (!$file) {
            return [];
        }

        return [
            'file_id' => $file->id,
            'filename' => $file->filename,
            'total_amount' => $file->total_amount,
            'currency' => $file->currency,
            'total_rows' => $file->total_rows,
            'valid_rows' => $file->valid_rows,
            'error_rows' => $file->error_rows,
            'success_rate' => $file->getSuccessRate(),
            'tcc_account_id' => $file->tcc_account_id,
            'uploaded_by' => $file->uploaded_by,
            'uploaded_at' => $file->created_at->toISOString(),
            'current_status' => $file->status,
            'has_validation_errors' => $file->hasValidationErrors(),
            'validation_error_count' => $file->getValidationErrorCount(),
        ];
    }

    /**
     * Check if approval requires additional confirmations.
     */
    public function requiresAdditionalConfirmation(): bool
    {
        $file = $this->getMassPaymentFile();
        
        if (!$file) {
            return false;
        }

        // Require additional confirmation for:
        // - Large amounts (>50k in any currency)
        // - Files with validation errors being overridden
        // - Force approvals
        // - Self-approvals
        
        $largeAmountThresholds = [
            'GBP' => 50000,
            'EUR' => 50000,
            'USD' => 50000,
            'INR' => 5000000,
        ];
        
        $threshold = $largeAmountThresholds[$file->currency] ?? 50000;
        
        return $file->total_amount > $threshold ||
               $this->shouldOverrideValidationErrors() ||
               $this->isForceApproval() ||
               $file->uploade