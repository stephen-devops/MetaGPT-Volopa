<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use Carbon\Carbon;

/**
 * StorePocketExpenseRequest
 * 
 * Form request validation for creating pocket expenses.
 * Validates all required fields and business rules for expense creation.
 * Enforces authorization through PocketExpensePolicy.
 */
class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', PocketExpense::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Required fields as per system constraints
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id'
            ],
            'date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                function ($attribute, $value, $fail) {
                    // Date must not be older than 3 years as per system constraints
                    $threeYearsAgo = Carbon::now()->subYears(3)->format('Y-m-d');
                    if ($value < $threeYearsAgo) {
                        $fail('The date must not be older than 3 years.');
                    }
                },
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:180' // VARCHAR 180 as per database schema
            ],
            'expense_type' => [
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id'
            ],
            'currency' => [
                'required',
                'string',
                'size:3', // 3-letter ISO currency code
                // TODO: Add validation against platform currency list when available
                'regex:/^[A-Z]{3}$/' // Must be 3 uppercase letters
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999999999.99' // DECIMAL(14,2) constraint
            ],
            
            // Optional fields
            'merchant_description' => [
                'nullable',
                'string',
                'max:255'
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:500'
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999.99' // DECIMAL(14,2) constraint
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000' // Reasonable limit for text field
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected'])
            ]
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Client ID is required.',
            'client_id.exists' => 'The selected client does not exist.',
            'date.required' => 'Expense date is required.',
            'date.date_format' => 'Date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'Expense date cannot be in the future.',
            'merchant_name.required' => 'Merchant name is required.',
            'merchant_name.max' => 'Merchant name cannot exceed 180 characters.',
            'expense_type.required' => 'Expense type is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'currency.required' => 'Currency code is required.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.regex' => 'Currency code must be 3 uppercase letters (ISO format).',
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.min' => 'Amount must be greater than 0.',
            'amount.max' => 'Amount exceeds maximum allowed value.',
            'vat_amount.numeric' => 'VAT amount must be a valid number.',
            'vat_amount.min' => 'VAT amount cannot be negative.',
            'vat_amount.max' => 'VAT amount exceeds maximum allowed value.',
            'status.in' => 'Status must be one of: draft, submitted, approved, rejected.',
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
            'client_id' => 'client',
            'expense_type' => 'expense type',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Trim string fields to prevent whitespace issues
        $this->merge([
            'merchant_name' => $this->input('merchant_name') ? trim($this->input('merchant_name')) : null,
            'merchant_description' => $this->input('merchant_description') ? trim($this->input('merchant_description')) : null,
            'merchant_address' => $this->input('merchant_address') ? trim($this->input('merchant_address')) : null,
            'notes' => $this->input('notes') ? trim($this->input('notes')) : null,
            'currency' => $this->input('currency') ? strtoupper(trim($this->input('currency'))) : null,
        ]);

        // Set default status if not provided
        if (!$this->has('status') || empty($this->input('status'))) {
            $this->merge(['status' => 'draft']);
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
            // Additional business logic validation
            
            // Ensure user_id is set to authenticated user (server-side check as per constraints)
            if (!$this->has('user_id')) {
                $this->merge(['user_id' => auth()->id()]);
            }
            
            // Validate that the expense user belongs to the same client (multi-tenancy check)
            if ($this->filled(['client_id'])) {
                // TODO: Add validation to ensure authenticated user belongs to the specified client
                // This requires checking user-client relationship when User model is fully defined
            }

            // Additional validation for VAT amount relative to main amount
            if ($this->filled(['amount', 'vat_amount'])) {
                $amount = (float) $this->input('amount');
                $vatAmount = (float) $this->input('vat_amount');
                
                // VAT amount should not exceed the main amount (reasonable business rule)
                if ($vatAmount > $amount) {
                    $validator->errors()->add('vat_amount', 'VAT amount cannot exceed the main expense amount.');
                }
            }
        });
    }

    /**
     * Get the validated data with additional processing.
     *
     * @return array
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();
        
        // Ensure required system fields are set
        $validated['user_id'] = auth()->id();
        $validated['created_by_user_id'] = auth()->id();
        
        // Generate UUID for the expense
        $validated['uuid'] = \Illuminate\Support\Str::uuid()->toString();
        
        return $validated;
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     */
    protected function failedValidation($validator): void
    {
        // Log validation failures for observability as per system constraints
        \Log::info('PocketExpense creation validation failed', [
            'user_id' => auth()->id(),
            'client_id' => $this->input('client_id'),
            'errors' => $validator->errors()->toArray(),
            'input' => $this->except(['password', 'token']) // Exclude sensitive fields
        ]);

        parent::failedValidation($validator);
    }
}