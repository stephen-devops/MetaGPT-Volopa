<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Policies\PocketExpensePolicy;
use Carbon\Carbon;

/**
 * UpdatePocketExpenseRequest
 * 
 * Form request validation for updating PocketExpense records.
 * Handles validation rules, authorization, and data preparation for expense updates.
 * All fields are optional for updates to allow partial updates.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Uses PocketExpensePolicy to check update authorization.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $expense = $this->route('pocket_expense') ?? $this->route('id');
        
        if (!$expense instanceof PocketExpense) {
            // If expense ID is provided as parameter, fetch the expense
            $expenseId = is_numeric($expense) ? $expense : null;
            if ($expenseId) {
                $expense = PocketExpense::find($expenseId);
            }
        }
        
        if (!$expense) {
            return false;
        }
        
        return $this->user()->can('update', $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $expense = $this->route('pocket_expense') ?? $this->route('id');
        $expenseId = null;
        
        if ($expense instanceof PocketExpense) {
            $expenseId = $expense->id;
        } elseif (is_numeric($expense)) {
            $expenseId = $expense;
        }

        return [
            // Optional date field - must not be older than 3 years as per system constraints
            'date' => [
                'sometimes',
                'required',
                'date',
                'date_format:Y-m-d',
                'after_or_equal:' . Carbon::now()->subYears(3)->format('Y-m-d'),
                'before_or_equal:' . Carbon::now()->format('Y-m-d'),
            ],
            
            // Optional merchant name with max length as per DB definition (VARCHAR 180)
            'merchant_name' => [
                'sometimes',
                'required',
                'string',
                'max:180',
                'min:1',
            ],
            
            // Optional merchant description
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            
            // Optional expense type - must exist in opt_pocket_expense_type table
            'expense_type' => [
                'sometimes',
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ],
            
            // Optional currency - must be 3-letter ISO code
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                // TODO: Add validation against allowed currency list from platform
            ],
            
            // Optional amount - decimal with 2 decimal places, can be positive or negative
            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'between:-999999999999.99,999999999999.99',
            ],
            
            // Optional merchant address
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            
            // Optional VAT amount - must be between 0-100 if provided
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:0,999999999999.99',
            ],
            
            // Optional notes with length limit and trimming
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            
            // Optional status - must be valid enum value
            'status' => [
                'sometimes',
                'required',
                'string',
                'in:draft,submitted,approved,rejected',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'The date must not be older than 3 years.',
            'date.before_or_equal' => 'The date cannot be in the future.',
            'date.date_format' => 'The date must be in YYYY-MM-DD format.',
            'merchant_name.max' => 'The merchant name may not be greater than 180 characters.',
            'merchant_name.required' => 'The merchant name is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'currency.size' => 'The currency code must be exactly 3 characters.',
            'currency.regex' => 'The currency code must be 3 uppercase letters.',
            'amount.between' => 'The amount must be a valid decimal number.',
            'vat_amount.between' => 'The VAT amount must be between 0 and 999999999999.99.',
            'status.in' => 'The status must be one of: draft, submitted, approved, rejected.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
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
            'expense_type' => 'expense type',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
        ];
    }

    /**
     * Prepare the data for validation.
     * Trim notes and apply other data preparation as per system constraints.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();
        
        // Trim notes to prevent SQL injection and clean data
        if (isset($input['notes'])) {
            $input['notes'] = trim($input['notes']);
            // Set to null if empty after trimming
            if ($input['notes'] === '') {
                $input['notes'] = null;
            }
        }
        
        // Trim merchant description
        if (isset($input['merchant_description'])) {
            $input['merchant_description'] = trim($input['merchant_description']);
            if ($input['merchant_description'] === '') {
                $input['merchant_description'] = null;
            }
        }
        
        // Trim merchant address
        if (isset($input['merchant_address'])) {
            $input['merchant_address'] = trim($input['merchant_address']);
            if ($input['merchant_address'] === '') {
                $input['merchant_address'] = null;
            }
        }
        
        // Trim and clean merchant name
        if (isset($input['merchant_name'])) {
            $input['merchant_name'] = trim($input['merchant_name']);
        }
        
        // Ensure currency is uppercase
        if (isset($input['currency'])) {
            $input['currency'] = strtoupper(trim($input['currency']));
        }
        
        $this->replace($input);
    }

    /**
     * Get the validated data with additional processing.
     * Adds system fields that should not be mass-assigned directly.
     *
     * @return array
     */
    public function getValidatedData(): array
    {
        $validated = $this->validated();
        
        // Add system fields for audit trail
        $validated['updated_by_user_id'] = $this->user()->id;
        
        return $validated;
    }

    /**
     * Handle a failed validation attempt.
     * Customize the response format for API consistency.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Validation\ValidationException($validator, response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422));
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('You are not authorized to update this expense.');
    }

    /**
     * Get data to be validated from the request.
     * Only return fields that are allowed for update operations.
     *
     * @return array
     */
    public function validationData(): array
    {
        // Only allow specific fields to be updated, excluding system fields
        $allowedFields = [
            'date',
            'merchant_name', 
            'merchant_description',
            'expense_type',
            'currency',
            'amount',
            'merchant_address',
            'vat_amount',
            'notes',
            'status',
        ];
        
        return $this->only($allowedFields);
    }

    /**
     * Determine if only status is being updated (for approval workflow).
     *
     * @return bool
     */
    public function isStatusOnlyUpdate(): bool
    {
        $input = $this->validationData();
        return count($input) === 1 && isset($input['status']);
    }

    /**
     * Check if the update includes financial data that requires recalculation.
     *
     * @return bool
     */
    public function requiresFXRecalculation(): bool
    {
        $financialFields = ['amount', 'currency', 'date'];
        $input = array_keys($this->validationData());
        
        return !empty(array_intersect($financialFields, $input));
    }
}