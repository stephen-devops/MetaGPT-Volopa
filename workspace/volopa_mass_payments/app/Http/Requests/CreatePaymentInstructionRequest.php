## Code: app/Http/Requests/CreatePaymentInstructionRequest.php

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PaymentInstruction;
use App\Models\Beneficiary;
use App\Models\MassPaymentFile;
use App\Models\TccAccount;

class CreatePaymentInstructionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user can create payment instructions
        // User must be authenticated and have access to the specified mass payment file
        if (!$this->user()) {
            return false;
        }

        // If mass_payment_file_id is provided, check if user owns that file
        if ($this->mass_payment_file_id) {
            $massPaymentFile = MassPaymentFile::find($this->mass_payment_file_id);
            if (!$massPaymentFile || $massPaymentFile->client_id !== $this->user()->client_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'mass_payment_file_id' => [
                'required',
                'integer',
                Rule::exists('mass_payment_files', 'id')->where(function ($query) {
                    $query->where('client_id', $this->user()->client_id);
                }),
            ],
            'beneficiary_id' => [
                'required',
                'integer',
                Rule::exists('beneficiaries', 'id')->where(function ($query) {
                    $query->where('client_id', $this->user()->client_id)
                          ->where('status', 'active');
                }),
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
                'regex:/^\d+(\.\d{1,2})?$/', // Ensure proper decimal format
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'in:GBP,EUR,USD,INR',
            ],
            'purpose_code' => [
                'nullable',
                'string',
                'max:10',
                'alpha_num',
            ],
            'reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'row_number' => [
                'required',
                'integer',
                'min:1',
            ],
            'additional_data' => [
                'nullable',
                'array',
            ],
            'additional_data.*' => [
                'string',
                'max:500',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'mass_payment_file_id.required' => 'A mass payment file must be specified.',
            'mass_payment_file_id.integer' => 'The mass payment file ID must be a valid integer.',
            'mass_payment_file_id.exists' => 'The specified mass payment file does not exist or does not belong to your organization.',
            
            'beneficiary_id.required' => 'A beneficiary must be specified.',
            'beneficiary_id.integer' => 'The beneficiary ID must be a valid integer.',
            'beneficiary_id.exists' => 'The specified beneficiary does not exist, is inactive, or does not belong to your organization.',
            
            'amount.required' => 'The payment amount is required.',
            'amount.numeric' => 'The payment amount must be a valid number.',
            'amount.min' => 'The payment amount must be at least 0.01.',
            'amount.max' => 'The payment amount must not exceed 999,999.99.',
            'amount.regex' => 'The payment amount must be in valid decimal format (e.g., 123.45).',
            
            'currency.required' => 'The currency is required.',
            'currency.string' => 'The currency must be a valid text.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.in' => 'The currency must be one of: GBP, EUR, USD, INR.',
            
            'purpose_code.string' => 'The purpose code must be valid text.',
            'purpose_code.max' => 'The purpose code must not exceed 10 characters.',
            'purpose_code.alpha_num' => 'The purpose code must contain only letters and numbers.',
            
            'reference.string' => 'The reference must be valid text.',
            'reference.max' => 'The reference must not exceed 255 characters.',
            
            'row_number.required' => 'The row number is required.',
            'row_number.integer' => 'The row number must be a valid integer.',
            'row_number.min' => 'The row number must be at least 1.',
            
            'additional_data.array' => 'The additional data must be a valid array.',
            'additional_data.*.string' => 'All additional data values must be valid text.',
            'additional_data.*.max' => 'Additional data values must not exceed 500 characters each.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'mass_payment_file_id' => 'mass payment file',
            'beneficiary_id' => 'beneficiary',
            'amount' => 'payment amount',
            'currency' => 'currency',
            'purpose_code' => 'purpose code',
            'reference' => 'payment reference',
            'row_number' => 'row number',
            'additional_data' => 'additional data',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure numeric fields are properly cast
        if ($this->has('mass_payment_file_id')) {
            $this->merge([
                'mass_payment_file_id' => (int) $this->mass_payment_file_id,
            ]);
        }

        if ($this->has('beneficiary_id')) {
            $this->merge([
                'beneficiary_id' => (int) $this->beneficiary_id,
            ]);
        }

        if ($this->has('row_number')) {
            $this->merge([
                'row_number' => (int) $this->row_number,
            ]);
        }

        // Clean and format amount
        if ($this->has('amount')) {
            $amount = $this->amount;
            if (is_string($amount)) {
                // Remove any currency symbols or spaces
                $amount = preg_replace('/[^\d.-]/', '', $amount);
            }
            $this->merge([
                'amount' => (float) $amount,
            ]);
        }

        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->currency),
            ]);
        }

        // Trim string fields
        if ($this->has('purpose_code')) {
            $this->merge([
                'purpose_code' => trim($this->purpose_code),
            ]);
        }

        if ($this->has('reference')) {
            $this->merge([
                'reference' => trim($this->reference),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate mass payment file status and ownership
            if ($this->mass_payment_file_id && !$validator->errors()->has('mass_payment_file_id')) {
                $massPaymentFile = MassPaymentFile::find($this->mass_payment_file_id);
                
                if ($massPaymentFile) {
                    // Check if file is in a state that allows adding instructions
                    if (!in_array($massPaymentFile->status, ['uploading', 'uploaded', 'validating', 'validated', 'validation_failed'])) {
                        $validator->errors()->add('mass_payment_file_id', 'Payment instructions cannot be added to this file in its current status: ' . $massPaymentFile->status);
                    }
                }
            }

            // Validate beneficiary currency compatibility
            if ($this->beneficiary_id && $this->currency && !$validator->errors()->has('beneficiary_id') && !$validator->errors()->has('currency')) {
                $beneficiary = Beneficiary::find($this->beneficiary_id);
                
                if ($beneficiary && !$beneficiary->supportsCurrency($this->currency)) {
                    $validator->errors()->add('currency', 'The selected beneficiary does not support payments in ' . $this->currency . '. Beneficiary currency: ' . $beneficiary->currency);
                }
            }

            // Check for duplicate row number within the same file
            if ($this->mass_payment_file_id && $this->row_number && !$validator->errors()->has('row_number')) {
                $existingInstruction = PaymentInstruction::where('mass_payment_file_id', $this->mass_payment_file_id)
                                                        ->where('row_number', $this->row_number)
                                                        ->first();
                
                if ($existingInstruction) {
                    $validator->errors()->add('row_number', 'A payment instruction with row number ' . $this->row_number . ' already exists for this file.');
                }
            }

            // Validate currency-specific amount limits
            if ($this->currency && $this->amount && !$validator->errors()->has('amount')) {
                $this->validateCurrencyLimits($validator);
            }

            // Validate currency-specific purpose codes
            if ($this->currency && $this->purpose_code && !$validator->errors()->has('purpose_code')) {
                $this->validatePurposeCode($validator);
            }

            // Validate TCC account currency matches payment currency
            if ($this->mass_payment_file_id && $this->currency && !$validator->errors()->has('currency')) {
                $massPaymentFile = MassPaymentFile::with('tccAccount')->find($this->mass_payment_file_id);
                
                if ($massPaymentFile && $massPaymentFile->tccAccount) {
                    if ($massPaymentFile->tccAccount->currency !== $this->currency) {
                        $validator->errors()->add('currency', 'Payment currency (' . $this->currency . ') must match TCC account currency (' . $massPaymentFile->tccAccount->currency . ').');
                    }
                }
            }

            // Validate additional data structure
            if ($this->additional_data && is_array($this->additional_data)) {
                foreach ($this->additional_data as $key => $value) {
                    if (!is_string($key) || strlen($key) > 50) {
                        $validator->errors()->add('additional_data', 'Additional data keys must be strings with maximum 50 characters.');
                        break;
                    }
                }
            }

            // Business rule: Validate minimum amounts per currency
            if ($this->amount && $this->currency) {
                $minAmounts = [
                    'GBP' => 1.00,
                    'EUR' => 1.00,
                    'USD' => 1.00,
                    'INR' => 100.00,
                ];
                
                $minAmount = $minAmounts[$this->currency] ?? 1.00;
                
                if ($this->amount < $minAmount) {
                    $validator->errors()->add('amount', 'Minimum payment amount for ' . $this->currency . ' is ' . number_format($minAmount, 2));
                }
            }
        });
    }

    /**
     * Validate currency-specific amount limits.
     */
    private function validateCurrencyLimits($validator): void
    {
        $limits = [
            'GBP' => ['min' => 1.00, 'max' => 50000.00],
            'EUR' => ['min' => 1.00, 'max' => 50000.00],
            'USD' => ['min' => 1.00, 'max' => 50000.00],
            'INR' => ['min' => 100.00, 'max' => 5000000.00],
        ];

        if (isset($limits[$this->currency])) {
            $limit = $limits[$this->currency];
            
            if ($this->amount < $limit['min']) {
                $validator->errors()->add('amount', 'Minimum amount for ' . $this->currency . ' is ' . number_format($limit['min'], 2));
            }
            
            if ($this->amount > $limit['max']) {
                $validator->errors()->add('amount', 'Maximum amount for ' . $this->currency . ' is ' . number_format($limit['max'], 2));
            }
        }
    }

    /**
     * Validate purpose codes for specific currencies.
     */
    private function validatePurposeCode($validator): void
    {
        $requiredPurposeCodes = [
            'INR' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'OTHER'],
        ];

        $validPurposeCodes = [
            'GBP' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'DIVIDEND', 'OTHER'],
            'EUR' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'DIVIDEND', 'OTHER'],
            'USD' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'DIVIDEND', 'OTHER'],
            'INR' => ['SALARY', 'INVOICE', 'TRADE', 'SERVICE', 'OTHER'],
        ];

        // Check if purpose code is required for this currency
        if (isset($requiredPurposeCodes[$this->currency]) && empty($this->purpose_code)) {
            $validator->errors()->add('purpose_code', 'Purpose code is required for ' . $this->currency . ' payments.');
            return;
        }

        // Validate purpose code is in allowed list
        if (isset($validPurposeCodes[$this->currency]) && !empty($this->purpose_code)) {
            if (!in_array($this->purpose_code, $validPurposeCodes[$this->currency])) {
                $validator->errors()->add('purpose_code', 'Invalid purpose code for ' . $this->currency . '. Allowed codes: ' . implode(', ', $validPurposeCodes[$this->currency]));
            }
        }
    }

    /**
     * Get validated data with additional processed information.
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();
        
        // Set default status for new payment instruction
        $validated['status'] = 'pending';
        
        // Format amount to 2 decimal places
        if (isset($validated['amount'])) {
            $validated['amount'] = round($validated['amount'], 2);
        }
        
        // Set default additional_data as empty array if not provided
        $validated['additional_data'] = $validated['additional_data'] ?? [];
        
        // Set null values for optional fields if empty
        $validated['purpose_code'] = !empty($validated['purpose_code']) ? $validated['purpose_code'] : null;
        $validated['reference'] = !empty($validated['reference']) ? $validated['reference'] : null;
        
        return $key ? data_get($validated, $key, $default) : $validated;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log the validation failure for monitoring
        \Log::warning('Payment instruction creation validation failed', [
            'user_id' => $this->user()->id,
            'client_id' => $this