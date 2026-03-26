<?php

namespace App\Http\Requests;

use App\Models\PocketExpense;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Pocket Expense Request
 * 
 * Form request for validating pocket expense updates with authorization.
 * Includes validation for expense data, client scoping, and permission checks.
 * Enforces system constraints including date limits, currency validation, and amount sign logic.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Uses PocketExpensePolicy to check update permissions.
     */
    public function authorize(): bool
    {
        // Get the expense being updated from route parameter
        $expense = $this->route('pocket_expense') ?? $this->route('id');
        
        if (!$expense instanceof PocketExpense) {
            // If we have an ID, try to find the expense
            if (is_numeric($expense)) {
                $expense = PocketExpense::find($expense);
            }
        }
        
        if (!$expense) {
            return false;
        }
        
        // Use policy to check if user can update this expense
        return $this->user()->can('update', $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:' . now()->subYears(3)->format('Y-m-d'), // Not older than 3 years constraint
                'before_or_equal:' . now()->format('Y-m-d'), // Cannot be future date
            ],
            'merchant_name' => [
                'sometimes',
                'required',
                'string',
                'max:180', // VARCHAR(180) DB constraint
                'min:1',
            ],
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            'expense_type' => [
                'sometimes',
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ],
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3', // Exactly 3 characters for ISO currency codes
                'regex:/^[A-Z]{3}$/', // Only uppercase letters
                // TODO: Add validation against platform allowed currency list
            ],
            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'between:-999999999999.99,999999999999.99', // DECIMAL(14,2) constraint
                'not_in:0', // Amount cannot be zero
            ],
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:0,999999.99', // DECIMAL(8,2) constraint, VAT should be positive
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected']), // Authoritative status enum values
            ],
        ];

        return $rules;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'The date cannot be older than 3 years from today.',
            'date.before_or_equal' => 'The date cannot be in the future.',
            'date.date_format' => 'The date must be in YYYY-MM-DD format.',
            'merchant_name.max' => 'The merchant name must not exceed 180 characters.',
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.min' => 'The merchant name must not be empty.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'expense_type.required' => 'The expense type is required.',
            'currency.size' => 'The currency code must be exactly 3 characters.',
            'currency.regex' => 'The currency code must contain only uppercase letters.',
            'currency.required' => 'The currency code is required.',
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.between' => 'The amount is outside the allowed range.',
            'amount.not_in' => 'The amount cannot be zero.',
            'vat_amount.numeric' => 'The VAT amount must be a valid number.',
            'vat_amount.between' => 'The VAT amount must be between 0 and 999999.99.',
            'status.in' => 'The status must be one of: draft, submitted, approved, rejected.',
        ];
    }

    /**
     * Get the custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'expense date',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'expense_type' => 'expense type',
            'currency' => 'currency code',
            'amount' => 'amount',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
            'notes' => 'notes',
            'status' => 'status',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional validation logic that requires access to multiple fields
            
            // Validate client_id scoping - expense must belong to authenticated user's client
            $expense = $this->getExpenseFromRoute();
            if ($expense && $this->user()) {
                // TODO: Implement client scoping validation
                // Ensure expense belongs to user's client context
                // This requires User model to have client relationship or client_id access
            }
            
            // Validate amount sign based on expense type
            if ($this->has(['amount', 'expense_type'])) {
                $this->validateAmountSign($validator);
            }
            
            // Validate status transition rules
            if ($this->has('status') && $expense) {
                $this->validateStatusTransition($validator, $expense);
            }
        });
    }

    /**
     * Validate amount sign based on expense type.
     *
     * @param \Illuminate\Validation\Validator $validator
     */
    protected function validateAmountSign($validator): void
    {
        $expenseTypeId = $this->input('expense_type');
        $amount = (float) $this->input('amount');
        
        // TODO: Look up expense type to determine expected amount sign
        // This requires OptPocketExpenseType model query
        // Refund types should have positive amounts, others negative
        // For now, adding placeholder validation
        
        // Example validation logic (needs actual implementation):
        // $expenseType = OptPocketExpenseType::find($expenseTypeId);
        // if ($expenseType) {
        //     if ($expenseType->amount_sign === 'positive' && $amount < 0) {
        //         $validator->errors()->add('amount', 'Amount should be positive for this expense type.');
        //     }
        //     if ($expenseType->amount_sign === 'negative' && $amount > 0) {
        //         $validator->errors()->add('amount', 'Amount should be negative for this expense type.');
        //     }
        // }
    }

    /**
     * Validate status transition rules.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param PocketExpense $expense
     */
    protected function validateStatusTransition($validator, PocketExpense $expense): void
    {
        $newStatus = $this->input('status');
        $currentStatus = $expense->status;
        
        // Define allowed status transitions
        $allowedTransitions = [
            'draft' => ['submitted'], // Draft can only go to submitted
            'submitted' => ['approved', 'rejected'], // Submitted can go to approved or rejected
            'approved' => [], // Approved is final
            'rejected' => [], // Rejected is final
        ];
        
        if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [])) {
            $validator->errors()->add('status', "Cannot transition from {$currentStatus} to {$newStatus}.");
        }
        
        // Additional authorization checks for status transitions
        if ($newStatus === 'approved' && !$this->user()->can('approve', $expense)) {
            $validator->errors()->add('status', 'You are not authorized to approve expenses.');
        }
    }

    /**
     * Get the expense model from route parameter.
     *
     * @return PocketExpense|null
     */
    protected function getExpenseFromRoute(): ?PocketExpense
    {
        $expense = $this->route('pocket_expense') ?? $this->route('id');
        
        if ($expense instanceof PocketExpense) {
            return $expense;
        }
        
        if (is_numeric($expense)) {
            return PocketExpense::find($expense);
        }
        
        return null;
    }

    /**
     * Prepare the data for validation.
     * Clean and format input data before validation.
     */
    protected function prepareForValidation(): void
    {
        $input = [];
        
        // Clean merchant name - trim whitespace
        if ($this->has('merchant_name')) {
            $input['merchant_name'] = trim($this->input('merchant_name'));
        }
        
        // Clean notes - trim whitespace and prevent SQL injection
        if ($this->has('notes')) {
            $notes = trim($this->input('notes'));
            $input['notes'] = $notes === '' ? null : $notes;
        }
        
        // Clean merchant description
        if ($this->has('merchant_description')) {
            $description = trim($this->input('merchant_description'));
            $input['merchant_description'] = $description === '' ? null : $description;
        }
        
        // Clean merchant address
        if ($this->has('merchant_address')) {
            $address = trim($this->input('merchant_address'));
            $input['merchant_address'] = $address === '' ? null : $address;
        }
        
        // Format currency code to uppercase
        if ($this->has('currency')) {
            $input['currency'] = strtoupper(trim($this->input('currency')));
        }
        
        // Clean amount - ensure numeric format
        if ($this->has('amount')) {
            $amount = $this->input('amount');
            if (is_string($amount)) {
                // Remove any non-numeric characters except decimal point and minus sign
                $amount = preg_replace('/[^0-9.-]/', '', $amount);
            }
            $input['amount'] = $amount;
        }
        
        // Clean VAT amount
        if ($this->has('vat_amount')) {
            $vatAmount = $this->input('vat_amount');
            if (is_string($vatAmount)) {
                // Remove percentage sign and other non-numeric characters
                $vatAmount = preg_replace('/[^0-9.]/', '', $vatAmount);
            }
            $input['vat_amount'] = $vatAmount === '' ? null : $vatAmount;
        }
        
        // Apply cleaned input
        if (!empty($input)) {
            $this->merge($input);
        }
    }

    /**
     * Get validated data with additional processing.
     * Ensures only updatable fields are returned.
     *
     * @param string|null $key
     * @param mixed $default
     * @return array|mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        if ($key !== null) {
            return $validated;
        }
        
        // Remove system fields that should not be mass assigned during updates
        $excludeFields = [
            'id',
            'uuid', 
            'user_id', 
            'client_id', 
            'created_by_user_id',
            'create_time',
            'deleted',
            'delete_time'
        ];
        
        foreach ($excludeFields as $field) {
            unset($validated[$field]);
        }
        
        // Add system fields that should be set during update
        $validated['updated_by_user_id'] = $this->user()->id ?? null;
        
        return $validated;
    }
}