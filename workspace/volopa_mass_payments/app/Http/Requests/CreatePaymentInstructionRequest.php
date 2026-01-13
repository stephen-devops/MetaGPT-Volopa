## Code: app/Http/Requests/CreatePaymentInstructionRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\Beneficiary;
use App\Models\PaymentInstruction;
use App\Models\TccAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CreatePaymentInstructionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user has permission to create payment instructions
        return Gate::allows('create', PaymentInstruction::class);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'beneficiary_id' => [
                'required',
                'uuid',
                'exists:beneficiaries,id',
                function ($attribute, $value, $fail) {
                    $this->validateBeneficiaryAccess($value, $fail);
                }
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999999.99',
                'decimal:0,2'
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(['USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'CAD', 'JPY', 'CNY', 'THB', 'MYR', 'IDR', 'PHP', 'VND'])
            ],
            'purpose_code' => [
                'nullable',
                'string',
                'max:10',
                Rule::in(['SAL', 'DIV', 'INT', 'FEE', 'RFD', 'TRD', 'SVC', 'SUP', 'INV', 'OTH'])
            ],
            'remittance_information' => [
                'nullable',
                'string',
                'max:500'
            ],
            'payment_reference' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9\-_\.\/]+$/'
            ],
            'tcc_account_id' => [
                'required',
                'uuid',
                'exists:tcc_accounts,id',
                function ($attribute, $value, $fail) {
                    $this->validateTccAccountAccess($value, $fail);
                }
            ],
            'effective_date' => [
                'nullable',
                'date',
                'after_or_equal:today',
                'before_or_equal:' . now()->addDays(30)->format('Y-m-d')
            ],
            'priority' => [
                'nullable',
                'string',
                Rule::in(['low', 'normal', 'high', 'urgent'])
            ],
            'notification_method' => [
                'nullable',
                'string',
                Rule::in(['email', 'sms', 'webhook', 'none'])
            ],
            'metadata' => [
                'nullable',
                'array',
                'max:20'
            ],
            'metadata.*' => [
                'string',
                'max:1000'
            ]
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'beneficiary_id.required' => 'Beneficiary is required.',
            'beneficiary_id.uuid' => 'Invalid beneficiary ID format.',
            'beneficiary_id.exists' => 'The selected beneficiary does not exist.',
            'amount.required' => 'Payment amount is required.',
            'amount.numeric' => 'Payment amount must be a valid number.',
            'amount.min' => 'Payment amount must be at least 0.01.',
            'amount.max' => 'Payment amount cannot exceed 999,999,999.99.',
            'amount.decimal' => 'Payment amount cannot have more than 2 decimal places.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.in' => 'The selected currency is not supported. Supported currencies: USD, EUR, GBP, SGD, HKD, AUD, CAD, JPY, CNY, THB, MYR, IDR, PHP, VND.',
            'purpose_code.max' => 'Purpose code cannot exceed 10 characters.',
            'purpose_code.in' => 'Invalid purpose code. Valid codes: SAL (Salary), DIV (Dividend), INT (Interest), FEE (Fee), RFD (Refund), TRD (Trade), SVC (Service), SUP (Supplier), INV (Investment), OTH (Other).',
            'remittance_information.max' => 'Remittance information cannot exceed 500 characters.',
            'payment_reference.max' => 'Payment reference cannot exceed 100 characters.',
            'payment_reference.regex' => 'Payment reference can only contain letters, numbers, hyphens, underscores, dots, and forward slashes.',
            'tcc_account_id.required' => 'TCC Account is required.',
            'tcc_account_id.uuid' => 'Invalid TCC Account ID format.',
            'tcc_account_id.exists' => 'The selected TCC Account does not exist.',
            'effective_date.date' => 'Effective date must be a valid date.',
            'effective_date.after_or_equal' => 'Effective date cannot be in the past.',
            'effective_date.before_or_equal' => 'Effective date cannot be more than 30 days in the future.',
            'priority.in' => 'Invalid priority. Must be: low, normal, high, or urgent.',
            'notification_method.in' => 'Invalid notification method. Must be: email, sms, webhook, or none.',
            'metadata.array' => 'Metadata must be an array.',
            'metadata.max' => 'Metadata cannot contain more than 20 items.',
            'metadata.*.string' => 'Each metadata value must be a string.',
            'metadata.*.max' => 'Each metadata value cannot exceed 1000 characters.'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'beneficiary_id' => 'beneficiary',
            'amount' => 'payment amount',
            'currency' => 'currency',
            'purpose_code' => 'purpose code',
            'remittance_information' => 'remittance information',
            'payment_reference' => 'payment reference',
            'tcc_account_id' => 'TCC Account',
            'effective_date' => 'effective date',
            'priority' => 'priority',
            'notification_method' => 'notification method',
            'metadata' => 'metadata'
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional cross-field validation
            $this->validateCurrencyConsistency($validator);
            $this->validatePaymentAmountLimits($validator);
            $this->validateTccAccountCurrency($validator);
            $this->validateBeneficiaryCurrency($validator);
            $this->validateDuplicatePayment($validator);
            $this->validateAccountBalance($validator);
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency'))
            ]);
        }

        // Normalize purpose code to uppercase
        if ($this->has('purpose_code') && !empty($this->input('purpose_code'))) {
            $this->merge([
                'purpose_code' => strtoupper($this->input('purpose_code'))
            ]);
        }

        // Set default values
        $defaults = [
            'priority' => 'normal',
            'notification_method' => 'email',
            'purpose_code' => 'OTH'
        ];

        foreach ($defaults as $key => $defaultValue) {
            if (!$this->has($key) || empty($this->input($key))) {
                $this->merge([$key => $defaultValue]);
            }
        }

        // Set default effective date if not provided
        if (!$this->has('effective_date') || empty($this->input('effective_date'))) {
            $this->merge([
                'effective_date' => now()->addDay()->format('Y-m-d')
            ]);
        }

        // Trim string fields
        $stringFields = ['remittance_information', 'payment_reference'];
        foreach ($stringFields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => trim($this->input($field))
                ]);
            }
        }

        // Clean metadata
        if ($this->has('metadata') && is_array($this->input('metadata'))) {
            $cleanMetadata = array_filter($this->input('metadata'), function ($value) {
                return !empty(trim($value));
            });
            $this->merge(['metadata' => $cleanMetadata]);
        }

        // Format amount to 2 decimal places
        if ($this->has('amount') && is_numeric($this->input('amount'))) {
            $this->merge([
                'amount' => number_format((float) $this->input('amount'), 2, '.', '')
            ]);
        }
    }

    /**
     * Validate that the user has access to the specified beneficiary.
     */
    private function validateBeneficiaryAccess(string $beneficiaryId, callable $fail): void
    {
        $user = $this->user();
        
        if (!$user) {
            $fail('User authentication required.');
            return;
        }

        $beneficiary = Beneficiary::find($beneficiaryId);
        
        if (!$beneficiary) {
            $fail('The selected beneficiary does not exist.');
            return;
        }

        // Check if user's client has access to this beneficiary
        if ($beneficiary->client_id !== $user->client_id) {
            $fail('You do not have access to the selected beneficiary.');
            return;
        }

        // Check if beneficiary is active
        if (isset($beneficiary->is_active) && !$beneficiary->is_active) {
            $fail('The selected beneficiary is not active.');
            return;
        }

        // Check if beneficiary is verified for payments
        if (isset($beneficiary->is_verified) && !$beneficiary->is_verified) {
            $fail('The selected beneficiary is not verified for payments.');
            return;
        }
    }

    /**
     * Validate that the user has access to the specified TCC Account.
     */
    private function validateTccAccountAccess(string $tccAccountId, callable $fail): void
    {
        $user = $this->user();
        
        if (!$user) {
            $fail('User authentication required.');
            return;
        }

        $tccAccount = TccAccount::find($tccAccountId);
        
        if (!$tccAccount) {
            $fail('The selected TCC Account does not exist.');
            return;
        }

        // Check if user's client has access to this TCC Account
        if ($tccAccount->client_id !== $user->client_id) {
            $fail('You do not have access to the selected TCC Account.');
            return;
        }

        // Check if TCC Account is active
        if (!$tccAccount->is_active) {
            $fail('The selected TCC Account is not active.');
            return;
        }

        // Check if TCC Account is enabled for outbound payments
        if (isset($tccAccount->can_send_payments) && !$tccAccount->can_send_payments) {
            $fail('The selected TCC Account is not enabled for outbound payments.');
            return;
        }
    }

    /**
     * Validate currency consistency across beneficiary and TCC account.
     */
    private function validateCurrencyConsistency($validator): void
    {
        $currency = $this->input('currency');
        $beneficiaryId = $this->input('beneficiary_id');
        $tccAccountId = $this->input('tcc_account_id');

        if (!$currency || !$beneficiaryId || !$tccAccountId) {
            return;
        }

        $beneficiary = Beneficiary::find($beneficiaryId);
        $tccAccount = TccAccount::find($tccAccountId);

        if (!$beneficiary || !$tccAccount) {
            return;
        }

        // Check if beneficiary supports this currency
        $beneficiaryCurrencies = $beneficiary->supported_currencies ?? [];
        if (!empty($beneficiaryCurrencies) && !in_array($currency, $beneficiaryCurrencies)) {
            $validator->errors()->add(
                'currency',
                'The selected beneficiary does not support ' . $currency . ' currency.'
            );
        }

        // Check if TCC Account supports this currency
        $tccCurrencies = $tccAccount->supported_currencies ?? [];
        if (!empty($tccCurrencies) && !in_array($currency, $tccCurrencies)) {
            $validator->errors()->add(
                'currency',
                'The selected TCC Account does not support ' . $currency . ' currency.'
            );
        }
    }

    /**
     * Validate payment amount against limits.
     */
    private function validatePaymentAmountLimits($validator): void
    {
        $amount = (float) $this->input('amount', 0);
        $currency = $this->input('currency');
        $user = $this->user();

        if (!$user || $amount <= 0) {
            return;
        }

        // Get user's payment limits
        $limits = $this->getUserPaymentLimits($user, $currency);

        // Check single transaction limit
        if (isset($limits['single_transaction']) && $amount > $limits['single_transaction']) {
            $validator->errors()->add(
                'amount',
                'Payment amount exceeds your single transaction limit of ' . 
                number_format($limits['single_transaction'], 2) . ' ' . $currency . '.'
            );
        }

        // Check daily limit
        if (isset($limits['daily_limit'])) {
            $todayTotal = PaymentInstruction::where('client_id', $user->client_id)
                ->where('currency', $currency)
                ->whereDate('created_at', now())
                ->whereIn('status', ['pending', 'processing', 'completed'])
                ->sum('amount');
            
            if (($todayTotal + $amount) > $limits['daily_limit']) {
                $validator->errors()->add(
                    'amount',
                    'This payment would exceed your daily limit of ' . 
                    number_format($limits['daily_limit'], 2) . ' ' . $currency . '.'
                );
            }
        }

        // Check monthly limit
        if (isset($limits['monthly_limit'])) {
            $monthlyTotal = PaymentInstruction::where('client_id', $user->client_id)
                ->where('currency', $currency)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->whereIn('status', ['pending', 'processing', 'completed'])
                ->sum('amount');
            
            if (($monthlyTotal + $amount) > $limits['monthly_limit']) {
                $validator->errors()->add(
                    'amount',
                    'This payment would exceed your monthly limit of ' . 
                    number_format($limits['monthly_limit'], 2) . ' ' . $currency . '.'
                );
            }
        }
    }

    /**