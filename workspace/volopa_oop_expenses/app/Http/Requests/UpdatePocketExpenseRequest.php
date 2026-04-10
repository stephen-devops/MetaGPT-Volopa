<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Models\PocketExpense;

/**
 * UpdatePocketExpenseRequest
 * 
 * Handles validation and authorization for updating pocket expenses.
 * Validates expense data including date constraints, currency codes, expense types,
 * merchant information, VAT amounts, and expense sources.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Uses PocketExpensePolicy to check if user can update the specific expense.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $expense = $this->route('expense');
        
        // If no expense is found in route, deny authorization
        if (!$expense instanceof PocketExpense) {
            return false;
        }
        
        // Use policy to check if user can update this expense
        return $this->user()->can('update', $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        $expense = $this->route('expense');
        $clientId = $expense ? $expense->client_id : null;
        
        // Calculate the earliest allowed date (3 years ago)
        $earliestDate = Carbon::now()->subYears(3)->format('Y-m-d');
        
        return [
            // Basic expense information
            'date' => [
                'sometimes',
                'required',
                'date',
                'date_format:Y-m-d',
                "after_or_equal:{$earliestDate}",
                'before_or_equal:today'
            ],
            'merchant_name' => [
                'sometimes',
                'required',
                'string',
                'max:180'
            ],
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:255'
            ],
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500'
            ],
            
            // Expense type validation
            'expense_type' => [
                'sometimes',
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id'
            ],
            
            // Currency and amount validation
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/', // 3-letter ISO currency format
                // TODO: Add validation against platform allowed currency list
                // Rule::in($this->getAllowedCurrencies())
            ],
            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'between:-999999999999.99,999999999999.99' // DECIMAL(14,2) constraints
            ],
            
            // VAT validation
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:0,999999999.99' // DECIMAL(12,2) constraints, non-negative
            ],
            
            // Notes validation
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:65535' // TEXT field limit
            ],
            
            // Status validation - only allow specific transitions
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['draft', 'submitted']) // Users can only set to draft or submitted
            ],
            
            // Metadata validation
            'metadata' => [
                'sometimes',
                'nullable',
                'array'
            ],
            'metadata.*.metadata_type' => [
                'required_with:metadata',
                'string',
                Rule::in([
                    'category',
                    'tracking_code_type_1',
                    'tracking_code_type_2',
                    'project',
                    'additional_field',
                    'file',
                    'expense_source'
                ])
            ],
            'metadata.*.transaction_category_id' => [
                'nullable',
                'integer',
                'exists:transaction_category,id'
            ],
            'metadata.*.tracking_code_id' => [
                'nullable',
                'integer',
                'exists:tracking_codes,id'
            ],
            'metadata.*.project_id' => [
                'nullable',
                'integer',
                'exists:configurable_projects,id'
            ],
            'metadata.*.file_store_id' => [
                'nullable',
                'integer',
                'exists:file_store,id'
            ],
            'metadata.*.expense_source_id' => [
                'nullable',
                'integer',
                $clientId ? "exists:pocket_expense_source_client_config,id,client_id,{$clientId},deleted,0" : 'exists:pocket_expense_source_client_config,id,deleted,0'
            ],
            'metadata.*.additional_field_id' => [
                'nullable',
                'integer',
                'exists:expense_additional_field,id'
            ],
            'metadata.*.details_json' => [
                'nullable',
                'json'
            ]
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'The expense date must not be older than 3 years.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.max' => 'The merchant name must not exceed 180 characters.',
            'merchant_description.max' => 'The merchant description must not exceed 255 characters.',
            'merchant_address.max' => 'The merchant address must not exceed 500 characters.',
            'expense_type.required' => 'The expense type is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'currency.required' => 'The currency code is required.',
            'currency.size' => 'The currency code must be exactly 3 characters.',
            'currency.regex' => 'The currency code must be a valid 3-letter ISO code (e.g., USD, EUR).',
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.between' => 'The amount is outside the allowed range.',
            'vat_amount.numeric' => 'The VAT amount must be a valid number.',
            'vat_amount.between' => 'The VAT amount must be between 0 and 999,999,999.99.',
            'notes.max' => 'The notes field is too long.',
            'status.in' => 'The status must be either draft or submitted.',
            'metadata.array' => 'The metadata must be an array.',
            'metadata.*.metadata_type.required_with' => 'The metadata type is required when metadata is provided.',
            'metadata.*.metadata_type.in' => 'The metadata type is invalid.',
            'metadata.*.transaction_category_id.exists' => 'The selected transaction category is invalid.',
            'metadata.*.tracking_code_id.exists' => 'The selected tracking code is invalid.',
            'metadata.*.project_id.exists' => 'The selected project is invalid.',
            'metadata.*.file_store_id.exists' => 'The selected file is invalid.',
            'metadata.*.expense_source_id.exists' => 'The selected expense source is invalid.',
            'metadata.*.additional_field_id.exists' => 'The selected additional field is invalid.',
            'metadata.*.details_json.json' => 'The details must be valid JSON.',
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
            'date' => 'expense date',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'merchant_address' => 'merchant address',
            'expense_type' => 'expense type',
            'currency' => 'currency code',
            'amount' => 'amount',
            'vat_amount' => 'VAT amount',
            'notes' => 'notes',
            'status' => 'status',
            'metadata' => 'metadata',
            'metadata.*.metadata_type' => 'metadata type',
            'metadata.*.transaction_category_id' => 'transaction category',
            'metadata.*.tracking_code_id' => 'tracking code',
            'metadata.*.project_id' => 'project',
            'metadata.*.file_store_id' => 'file',
            'metadata.*.expense_source_id' => 'expense source',
            'metadata.*.additional_field_id' => 'additional field',
            'metadata.*.details_json' => 'additional details',
        ];
    }

    /**
     * Configure the validator instance.
     * Add custom validation logic that requires access to the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $expense = $this->route('expense');
            
            if (!$expense) {
                return;
            }
            
            // Validate client_id match if provided in request
            if ($this->has('client_id') && $this->input('client_id') !== $expense->client_id) {
                $validator->errors()->add('client_id', 'Cannot change the client for an existing expense.');
            }
            
            // Validate user_id match if provided in request  
            if ($this->has('user_id') && $this->input('user_id') !== $expense->user_id) {
                $validator->errors()->add('user_id', 'Cannot change the user for an existing expense.');
            }
            
            // Validate status transitions
            if ($this->has('status')) {
                $currentStatus = $expense->status;
                $newStatus = $this->input('status');
                
                // Business logic: once approved or rejected, cannot be changed back to draft/submitted
                if (in_array($currentStatus, ['approved', 'rejected']) && in_array($newStatus, ['draft', 'submitted'])) {
                    $validator->errors()->add('status', 'Cannot change status from ' . $currentStatus . ' back to ' . $newStatus . '.');
                }
            }
            
            // TODO: Add validation for FX conversion if currency changes
            // This would require checking if the new currency is different and if FX rates are available
            
            // TODO: Add validation for expense source requirements
            // When source is "Other", source note should be required in metadata
            $this->validateExpenseSourceRequirements($validator);
        });
    }

    /**
     * Validate expense source requirements.
     * When expense source is "Other", ensure source note is provided.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateExpenseSourceRequirements($validator): void
    {
        if (!$this->has('metadata')) {
            return;
        }
        
        $metadata = $this->input('metadata', []);
        $expenseSourceMetadata = collect($metadata)->first(function ($item) {
            return isset($item['metadata_type']) && $item['metadata_type'] === 'expense_source';
        });
        
        if (!$expenseSourceMetadata || !isset($expenseSourceMetadata['expense_source_id'])) {
            return;
        }
        
        // TODO: Check if the expense source is "Other" and validate source note requirement
        // This requires querying the PocketExpenseSourceClientConfig model
        // $expenseSource = PocketExpenseSourceClientConfig::find($expenseSourceMetadata['expense_source_id']);
        // if ($expenseSource && $expenseSource->name === 'Other') {
        //     $sourceNote = $expenseSourceMetadata['details_json'] ?? null;
        //     if (empty($sourceNote)) {
        //         $validator->errors()->add('metadata.expense_source.details_json', 'Source note is required when expense source is "Other".');
        //     }
        // }
    }

    /**
     * Prepare the data for validation.
     * Clean and normalize input data before validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency'))
            ]);
        }
        
        // Trim and sanitize text fields
        if ($this->has('merchant_name')) {
            $this->merge([
                'merchant_name' => trim($this->input('merchant_name'))
            ]);
        }
        
        if ($this->has('merchant_description')) {
            $this->merge([
                'merchant_description' => trim($this->input('merchant_description'))
            ]);
        }
        
        if ($this->has('merchant_address')) {
            $this->merge([
                'merchant_address' => trim($this->input('merchant_address'))
            ]);
        }
        
        if ($this->has('notes')) {
            $this->merge([
                'notes' => trim($this->input('notes'))
            ]);
        }
        
        // TODO: Apply amount sign based on expense type
        // This would require looking up the OptPocketExpenseType to determine sign
        
        // TODO: Strip % sign from VAT amount if present
        // if ($this->has('vat_amount')) {
        //     $vatAmount = $this->input('vat_amount');
        //     if (is_string($vatAmount) && str_ends_with($vatAmount, '%')) {
        //         $this->merge([
        //             'vat_amount' => rtrim($vatAmount, '%')
        //         ]);
        //     }
        // }
    }

    /**
     * Get the validated data from the request.
     * Override to ensure only allowed fields are returned for updating.
     *
     * @param array|null $key
     * @param mixed $default
     * @return array|mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        // Remove fields that should not be mass assignable during updates
        $protectedFields = ['id', 'uuid', 'created_by_user_id', 'create_time'];
        
        foreach ($protectedFields as $field) {
            unset($validated[$field]);
        }
        
        return $validated;
    }
}