<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;

/**
 * Store Pocket Expense Request
 * 
 * Handles validation for creating new pocket expenses.
 * Includes authorization via PocketExpensePolicy and comprehensive validation rules.
 */
class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implement authorization using PocketExpensePolicy
        // Should check if authenticated user can create expenses for the specified user_id and client_id
        return $this->user()->can('create', [\App\Models\PocketExpense::class, $this->input('client_id')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Core expense fields
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . now()->subYears(3)->format('Y-m-d'), // Not older than 3 years constraint
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:180', // VARCHAR(180) constraint from database
                'min:1',
            ],
            'merchant_description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'expense_type' => [
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ],
            'currency' => [
                'required',
                'string',
                'size:3', // 3-letter ISO currency code constraint
                'regex:/^[A-Z]{3}$/',
                // TODO: Add validation against platform allowed currency list
            ],
            'amount' => [
                'required',
                'numeric',
                'between:-999999999999.99,999999999999.99', // DECIMAL(14,2) constraint
                'not_in:0', // Amount cannot be zero
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'between:0,99999.99', // DECIMAL(8,2) constraint, VAT must be positive
                'min:0',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'submitted']), // Only allow draft or submitted on creation
            ],
            
            // Metadata fields (optional)
            'transaction_category_id' => [
                'nullable',
                'integer',
                // TODO: Add exists validation against transaction_category table when available
            ],
            'tracking_code_id' => [
                'nullable',
                'integer',
                // TODO: Add exists validation against tracking_code table when available
            ],
            'project_id' => [
                'nullable',
                'integer',
                // TODO: Add exists validation against project table when available
            ],
            'expense_source_id' => [
                'nullable',
                'integer',
                'exists:pocket_expense_source_client_config,id',
            ],
            'source_note' => [
                'nullable',
                'string',
                'max:500',
                'required_if:expense_source_name,Other', // Required when source is "Other"
            ],
            'expense_source_name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID is required.',
            'user_id.exists' => 'The selected user does not exist.',
            'client_id.required' => 'Client ID is required.',
            'client_id.exists' => 'The selected client does not exist.',
            'date.required' => 'Expense date is required.',
            'date.date_format' => 'Date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'Expense date cannot be in the future.',
            'date.after_or_equal' => 'Expense date cannot be older than 3 years.',
            'merchant_name.required' => 'Merchant name is required.',
            'merchant_name.max' => 'Merchant name cannot exceed 180 characters.',
            'expense_type.required' => 'Expense type is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'currency.required' => 'Currency code is required.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.regex' => 'Currency code must be 3 uppercase letters.',
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.not_in' => 'Amount cannot be zero.',
            'vat_amount.numeric' => 'VAT amount must be a valid number.',
            'vat_amount.min' => 'VAT amount must be positive.',
            'status.in' => 'Status must be either draft or submitted.',
            'expense_source_id.exists' => 'The selected expense source is invalid.',
            'source_note.required_if' => 'Source note is required when expense source is "Other".',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Custom validation: Check if user belongs to client
            if ($this->input('user_id') && $this->input('client_id')) {
                // TODO: Implement user-client relationship validation
                // Verify that the user_id belongs to the specified client_id
            }

            // Custom validation: Check expense source belongs to client
            if ($this->input('expense_source_id') && $this->input('client_id')) {
                $source = PocketExpenseSourceClientConfig::find($this->input('expense_source_id'));
                if ($source && $source->client_id !== null && $source->client_id !== (int)$this->input('client_id')) {
                    $validator->errors()->add('expense_source_id', 'The selected expense source does not belong to the specified client.');
                }
            }

            // Custom validation: Amount sign based on expense type
            if ($this->input('expense_type') && $this->input('amount')) {
                $expenseType = OptPocketExpenseType::find($this->input('expense_type'));
                if ($expenseType) {
                    $amount = (float)$this->input('amount');
                    
                    if ($expenseType->amount_sign === 'positive' && $amount < 0) {
                        $validator->errors()->add('amount', 'Amount must be positive for refund expense types.');
                    } elseif ($expenseType->amount_sign === 'negative' && $amount > 0) {
                        $validator->errors()->add('amount', 'Amount must be negative for expense types other than refunds.');
                    }
                }
            }

            // Custom validation: Source note required when source name is "Other"
            if ($this->input('expense_source_name') === 'Other' && empty($this->input('source_note'))) {
                $validator->errors()->add('source_note', 'Source note is required when expense source is "Other".');
            }

            // Custom validation: Client has OOP feature enabled
            if ($this->input('client_id')) {
                // TODO: Implement client feature validation
                // Check if client_id has feature_id=16 (OOP Expense) enabled
                // This requires integration with ClientFeatures model/service
            }

            // Custom validation: User has permission to create expenses for target user
            if ($this->input('user_id') && $this->input('client_id')) {
                // TODO: Implement user permission validation
                // Check if authenticated user has permission to manage expenses for the target user_id
                // This should use UserPermissionService to verify management rights
            }
        });
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default status if not provided
        if (!$this->has('status')) {
            $this->merge([
                'status' => 'draft',
            ]);
        }

        // Set created_by_user_id to authenticated user
        $this->merge([
            'created_by_user_id' => $this->user()->id,
        ]);

        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency')),
            ]);
        }

        // Trim text fields
        $textFields = ['merchant_name', 'merchant_description', 'merchant_address', 'notes', 'source_note'];
        $trimmedData = [];
        
        foreach ($textFields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $trimmedData[$field] = trim($this->input($field));
            }
        }
        
        if (!empty($trimmedData)) {
            $this->merge($trimmedData);
        }
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'user',
            'client_id' => 'client',
            'expense_type' => 'expense type',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
            'expense_source_id' => 'expense source',
            'source_note' => 'source note',
            'transaction_category_id' => 'transaction category',
            'tracking_code_id' => 'tracking code',
            'project_id' => 'project',
        ];
    }
}