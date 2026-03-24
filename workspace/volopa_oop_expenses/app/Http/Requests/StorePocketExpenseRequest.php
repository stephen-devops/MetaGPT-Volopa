<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;

/**
 * Form Request for creating new pocket expenses
 * 
 * Handles validation and authorization for expense creation with comprehensive
 * field validation, date constraints, currency validation, amount sign conventions,
 * VAT percentage validation, source validation with "Other" source note requirements,
 * and metadata validation for categories, tracking codes, projects, files, and additional fields.
 */
class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Use the PocketExpensePolicy to determine if user can create expenses
        return $this->user() && $this->user()->can('create', \App\Models\PocketExpense::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $clientId = $this->user()?->client_id ?? 1;
        
        return [
            // Core expense fields - required
            'date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . Carbon::now()->subYears(3)->format('Y-m-d'), // Not older than 3 years
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:180', // VARCHAR(180) as per DB definition
                'min:1',
            ],
            'expense_type' => [
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ],
            'currency' => [
                'required',
                'string',
                'size:3', // Exactly 3 characters for ISO currency codes
                'regex:/^[A-Z]{3}$/', // Must be uppercase letters only
                // Note: Additional validation against platform currency list should be handled in controller
            ],
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2', // Maximum 2 decimal places
                'min:0.01', // Minimum positive amount (sign determined by expense type)
                'max:999999999999.99', // Within DECIMAL(14,2) range
            ],
            
            // Optional core expense fields
            'merchant_description' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:999999999999.99', // Within DECIMAL(14,2) range
                'lte:amount', // VAT cannot exceed the total amount
            ],
            'vat_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100', // Between 0-100% as per constraints
            ],
            'notes' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            
            // Status - defaults to draft, but allow explicit setting
            'status' => [
                'nullable',
                'string',
                'in:draft,submitted', // Only draft and submitted allowed for creation
            ],
            
            // Metadata fields - all optional but with specific validation rules
            
            // Category metadata
            'category_id' => [
                'nullable',
                'integer',
                'exists:transaction_category,id,client_id,' . $clientId . ',deleted,0',
            ],
            
            // Tracking code metadata (type 1)
            'tracking_code_1_id' => [
                'nullable',
                'integer',
                'exists:tracking_codes,id,client_id,' . $clientId . ',deleted,0',
            ],
            
            // Tracking code metadata (type 2) 
            'tracking_code_2_id' => [
                'nullable',
                'integer',
                'exists:tracking_codes,id,client_id,' . $clientId . ',deleted,0',
            ],
            
            // Project metadata
            'project_id' => [
                'nullable',
                'integer',
                'exists:configurable_projects,id,deleted,0',
            ],
            
            // File attachment metadata
            'file_store_id' => [
                'nullable',
                'integer',
                'exists:file_store,id,deleted,0',
            ],
            
            // Expense source metadata
            'expense_source_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value !== null) {
                        // Check if source exists and is active for this client or is global "Other"
                        $source = PocketExpenseSourceClientConfig::where('id', $value)
                            ->where(function ($query) use ($clientId) {
                                $query->where('client_id', $clientId)
                                      ->orWhereNull('client_id'); // Global "Other" source
                            })
                            ->where('deleted', 0)
                            ->first();
                            
                        if (!$source) {
                            $fail('The selected expense source is invalid or not available for your organization.');
                        }
                    }
                },
            ],
            'expense_source_note' => [
                'nullable',
                'string',
                'max:500',
                // Required when expense_source_id points to "Other" source
                function ($attribute, $value, $fail) {
                    $expenseSourceId = $this->input('expense_source_id');
                    if ($expenseSourceId) {
                        $source = PocketExpenseSourceClientConfig::find($expenseSourceId);
                        if ($source && $source->name === 'Other' && empty($value)) {
                            $fail('Source note is required when "Other" is selected as the expense source.');
                        }
                    }
                },
            ],
            
            // Additional field metadata
            'additional_field_id' => [
                'nullable',
                'integer',
                'exists:expense_additional_field,id,client_id,' . $clientId . ',deleted,0',
            ],
            'additional_field_value' => [
                'nullable',
                'string',
                'max:1000',
                // Required if additional_field_id is provided
                'required_with:additional_field_id',
            ],
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
            'date.required' => 'Expense date is required.',
            'date.date_format' => 'Expense date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'Expense date cannot be in the future.',
            'date.after_or_equal' => 'Expense date cannot be older than 3 years.',
            
            'merchant_name.required' => 'Merchant name is required.',
            'merchant_name.max' => 'Merchant name cannot exceed 180 characters.',
            'merchant_name.min' => 'Merchant name cannot be empty.',
            
            'expense_type.required' => 'Expense type is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            
            'currency.required' => 'Currency code is required.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.regex' => 'Currency code must be 3 uppercase letters (e.g., GBP, EUR, USD).',
            
            'amount.required' => 'Expense amount is required.',
            'amount.numeric' => 'Expense amount must be a valid number.',
            'amount.decimal' => 'Expense amount can have at most 2 decimal places.',
            'amount.min' => 'Expense amount must be at least 0.01.',
            'amount.max' => 'Expense amount is too large.',
            
            'merchant_description.max' => 'Merchant description is too long.',
            'merchant_address.max' => 'Merchant address is too long.',
            
            'vat_amount.numeric' => 'VAT amount must be a valid number.',
            'vat_amount.decimal' => 'VAT amount can have at most 2 decimal places.',
            'vat_amount.min' => 'VAT amount cannot be negative.',
            'vat_amount.max' => 'VAT amount is too large.',
            'vat_amount.lte' => 'VAT amount cannot exceed the total expense amount.',
            
            'vat_percentage.numeric' => 'VAT percentage must be a valid number.',
            'vat_percentage.min' => 'VAT percentage cannot be negative.',
            'vat_percentage.max' => 'VAT percentage cannot exceed 100%.',
            
            'notes.max' => 'Notes are too long.',
            
            'status.in' => 'Status must be either draft or submitted.',
            
            'category_id.exists' => 'The selected category is invalid or not available for your organization.',
            'tracking_code_1_id.exists' => 'The selected tracking code (type 1) is invalid or not available for your organization.',
            'tracking_code_2_id.exists' => 'The selected tracking code (type 2) is invalid or not available for your organization.',
            'project_id.exists' => 'The selected project is invalid.',
            'file_store_id.exists' => 'The selected file attachment is invalid.',
            
            'additional_field_id.exists' => 'The selected additional field is invalid or not available for your organization.',
            'additional_field_value.required_with' => 'Additional field value is required when an additional field is selected.',
            'additional_field_value.max' => 'Additional field value is too long.',
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
            'currency' => 'currency',
            'amount' => 'amount',
            'vat_amount' => 'VAT amount',
            'vat_percentage' => 'VAT percentage',
            'notes' => 'notes',
            'status' => 'status',
            'category_id' => 'category',
            'tracking_code_1_id' => 'tracking code (type 1)',
            'tracking_code_2_id' => 'tracking code (type 2)',
            'project_id' => 'project',
            'file_store_id' => 'file attachment',
            'expense_source_id' => 'expense source',
            'expense_source_note' => 'source note',
            'additional_field_id' => 'additional field',
            'additional_field_value' => 'additional field value',
        ];
    }

    /**
     * Prepare the data for validation.
     * Clean and format input data before validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = [];
        
        // Trim string fields to prevent whitespace issues
        if ($this->has('merchant_name')) {
            $data['merchant_name'] = trim($this->merchant_name);
        }
        
        if ($this->has('merchant_description')) {
            $data['merchant_description'] = trim($this->merchant_description ?? '') ?: null;
        }
        
        if ($this->has('merchant_address')) {
            $data['merchant_address'] = trim($this->merchant_address ?? '') ?: null;
        }
        
        if ($this->has('notes')) {
            $data['notes'] = trim($this->notes ?? '') ?: null;
        }
        
        if ($this->has('expense_source_note')) {
            $data['expense_source_note'] = trim($this->expense_source_note ?? '') ?: null;
        }
        
        if ($this->has('additional_field_value')) {
            $data['additional_field_value'] = trim($this->additional_field_value ?? '') ?: null;
        }
        
        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $data['currency'] = strtoupper(trim($this->currency ?? ''));
        }
        
        // Clean amount and VAT values
        if ($this->has('amount')) {
            $data['amount'] = $this->amount;
        }
        
        if ($this->has('vat_amount')) {
            $data['vat_amount'] = $this->vat_amount;
        }
        
        if ($this->has('vat_percentage')) {
            $data['vat_percentage'] = $this->vat_percentage;
        }
        
        // Set default status if not provided
        if (!$this->has('status') || empty($this->status)) {
            $data['status'] = 'draft';
        }
        
        // Parse date from DD/MM/YYYY to YYYY-MM-DD if needed
        if ($this->has('date')) {
            $date = $this->date;
            
            // Check if date is in DD/MM/YYYY format (from CSV) and convert
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
                $day = $matches[1];
                $month = $matches[2];
                $year = $matches[3];
                $data['date'] = $year . '-' . $month . '-' . $day;
            } else {
                $data['date'] = $date;
            }
        }
        
        // Convert null values to actual null for optional fields
        $nullableFields = [
            'merchant_description',
            'merchant_address', 
            'vat_amount',
            'vat_percentage',
            'notes',
            'category_id',
            'tracking_code_1_id',
            'tracking_code_2_id',
            'project_id',
            'file_store_id',
            'expense_source_id',
            'expense_source_note',
            'additional_field_id',
            'additional_field_value',
        ];
        
        foreach ($nullableFields as $field) {
            if ($this->has($field) && ($this->$field === '' || $this->$field === 'null')) {
                $data[$field] = null;
            }
        }
        
        // Merge cleaned data back to request
        $this->merge($data);
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     * 
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Add additional context to validation errors for API responses
        $errors = $validator->errors();
        
        // If amount validation failed, add context about expense type amount sign conventions
        if ($errors->has('amount') && $this->has('expense_type')) {
            $expenseType = OptPocketExpenseType::find($this->expense_type);
            if ($expenseType) {
                $errors->add('amount', "Note: {$expenseType->option} expenses use {$expenseType->amount_sign} amount convention.");
            }
        }
        
        // If currency validation failed, add context about supported currencies
        if ($errors->has('currency')) {
            $errors->add('currency', 'Supported currencies depend on your organization\'s configuration. Contact your administrator if needed.');
        }
        
        // If source validation failed and "Other" was selected without note
        if ($errors->has('expense_source_note') && $this->has('expense_source_id')) {
            $source = PocketExpenseSourceClientConfig::find($this->expense_source_id);
            if ($source && $source->name === 'Other') {
                $errors->add('expense_source_note', 'Please provide a description when using "Other" as the expense source.');
            }
        }
        
        parent::failedValidation($validator);
    }

    /**
     * Get validated data with additional processing.
     * 
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        // If getting all validated data, add computed fields
        if ($key === null) {
            // Add user context for the expense
            $validated['user_id'] = $this->user()->id;
            $validated['client_id'] = $this->user()->client_id;
            $validated['created_by_user_id'] = $this->user()->id;
            
            // Ensure status is set
            $validated['status'] = $validated['status'] ?? 'draft';
        }
        
        return $validated;
    }
}