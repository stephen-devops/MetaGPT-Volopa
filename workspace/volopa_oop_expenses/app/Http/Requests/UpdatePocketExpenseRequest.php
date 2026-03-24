<?php

namespace App\Http\Requests;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Form Request for updating pocket expenses
 * 
 * Handles validation and authorization for expense update operations.
 * Implements business rules including date constraints, currency validation,
 * amount sign logic based on expense type, VAT percentage validation,
 * source validation with "Other" note requirements, and field length limits.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        // Get the expense being updated from route model binding
        $expense = $this->route('expense');
        if (!$expense instanceof PocketExpense) {
            return false;
        }

        // Use the PocketExpensePolicy to check update authorization
        return $user->can('update', $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $user = Auth::user();
        $clientId = $user ? $user->client_id : null;
        
        // Get the expense being updated to allow partial updates
        $expense = $this->route('expense');
        $isDateRequired = $this->has('date');
        $isMerchantNameRequired = $this->has('merchant_name');
        $isAmountRequired = $this->has('amount');
        $isCurrencyRequired = $this->has('currency');
        $isExpenseTypeRequired = $this->has('expense_type');

        $rules = [];

        // Date validation - not older than 3 years, DD/MM/YYYY format expected from frontend
        if ($isDateRequired || $this->filled('date')) {
            $rules['date'] = [
                'required',
                'date_format:d/m/Y',
                function ($attribute, $value, $fail) {
                    try {
                        $parsedDate = Carbon::createFromFormat('d/m/Y', $value);
                        $threeYearsAgo = Carbon::now()->subYears(3);
                        
                        if ($parsedDate->isBefore($threeYearsAgo)) {
                            $fail('The expense date must not be older than 3 years.');
                        }
                        
                        if ($parsedDate->isFuture()) {
                            $fail('The expense date cannot be in the future.');
                        }
                    } catch (\Exception $e) {
                        $fail('The expense date must be in DD/MM/YYYY format.');
                    }
                },
            ];
        }

        // Merchant name - max 180 characters as per DB definition
        if ($isMerchantNameRequired || $this->filled('merchant_name')) {
            $rules['merchant_name'] = [
                'required',
                'string',
                'max:180',
                'not_regex:/[<>"\']/', // Prevent basic XSS patterns
            ];
        }

        // Merchant description - optional text field
        if ($this->has('merchant_description')) {
            $rules['merchant_description'] = [
                'nullable',
                'string',
                'max:1000',
                'not_regex:/[<>"\']/', // Prevent basic XSS patterns
            ];
        }

        // Expense type validation - must exist in opt_pocket_expense_type table
        if ($isExpenseTypeRequired || $this->filled('expense_type')) {
            $rules['expense_type'] = [
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ];
        }

        // Currency validation - 3-letter ISO format, must be in platform allowed list
        if ($isCurrencyRequired || $this->filled('currency')) {
            $rules['currency'] = [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                function ($attribute, $value, $fail) {
                    // Platform-managed currency validation
                    $allowedCurrencies = ['GBP', 'EUR', 'USD', 'CAD', 'AUD', 'CHF', 'JPY', 'SEK', 'NOK', 'DKK'];
                    if (!in_array(strtoupper($value), $allowedCurrencies)) {
                        $fail('The selected currency is not supported.');
                    }
                },
            ];
        }

        // Amount validation - decimal with precision, sign determined by expense type
        if ($isAmountRequired || $this->filled('amount')) {
            $rules['amount'] = [
                'required',
                'numeric',
                'not_in:0',
                'regex:/^\d+(\.\d{1,2})?$/', // Max 2 decimal places
                function ($attribute, $value, $fail) {
                    $expenseTypeId = $this->input('expense_type');
                    
                    if ($expenseTypeId) {
                        $expenseType = OptPocketExpenseType::find($expenseTypeId);
                        if ($expenseType) {
                            // Validate amount sign based on expense type
                            if ($expenseType->amount_sign === 'positive' && $value < 0) {
                                $fail('Amount must be positive for ' . $expenseType->option . ' expense type.');
                            } elseif ($expenseType->amount_sign === 'negative' && $value > 0) {
                                $fail('Amount must be negative for ' . $expenseType->option . ' expense type.');
                            }
                        }
                    }
                },
            ];
        }

        // Merchant address - optional text field
        if ($this->has('merchant_address')) {
            $rules['merchant_address'] = [
                'nullable',
                'string',
                'max:500',
                'not_regex:/[<>"\']/', // Prevent basic XSS patterns
            ];
        }

        // VAT amount validation - must be numeric, between 0 and amount
        if ($this->has('vat_amount')) {
            $rules['vat_amount'] = [
                'nullable',
                'numeric',
                'min:0',
                'regex:/^\d+(\.\d{1,2})?$/', // Max 2 decimal places
                function ($attribute, $value, $fail) {
                    $amount = abs($this->input('amount', 0));
                    if ($value && $amount && $value > $amount) {
                        $fail('VAT amount cannot exceed the total expense amount.');
                    }
                },
            ];
        }

        // VAT percentage validation - strip % sign, numeric between 0-100
        if ($this->has('vat_percentage')) {
            $rules['vat_percentage'] = [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    
                    // Strip % sign if present
                    $cleanValue = str_replace('%', '', $value);
                    
                    if (!is_numeric($cleanValue)) {
                        $fail('VAT percentage must be a numeric value.');
                        return;
                    }
                    
                    $numericValue = (float) $cleanValue;
                    if ($numericValue < 0 || $numericValue > 100) {
                        $fail('VAT percentage must be between 0 and 100.');
                    }
                },
            ];
        }

        // Notes validation - trim and prevent SQL injection, follow DB limit
        if ($this->has('notes')) {
            $rules['notes'] = [
                'nullable',
                'string',
                'max:2000',
                'not_regex:/[<>"\']/', // Prevent basic XSS patterns
            ];
        }

        // Status validation - must be valid enum value
        if ($this->has('status')) {
            $rules['status'] = [
                'sometimes',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected']),
                function ($attribute, $value, $fail) {
                    $user = Auth::user();
                    $expense = $this->route('expense');
                    
                    // Business users and card users cannot approve expenses
                    if ($value === 'approved' && $user && in_array($user->role, ['Business User', 'Card User'])) {
                        $fail('You do not have permission to approve expenses.');
                    }
                    
                    // Prevent status regression (approved/rejected -> draft/submitted)
                    if ($expense && in_array($expense->status, ['approved', 'rejected']) && in_array($value, ['draft', 'submitted'])) {
                        $fail('Cannot change status from ' . $expense->status . ' back to ' . $value . '.');
                    }
                },
            ];
        }

        // Metadata validation rules
        $this->addMetadataValidationRules($rules, $clientId);

        return $rules;
    }

    /**
     * Add metadata-related validation rules.
     *
     * @param array $rules
     * @param int|null $clientId
     * @return void
     */
    protected function addMetadataValidationRules(array &$rules, ?int $clientId): void
    {
        // Transaction category validation
        if ($this->has('transaction_category_id')) {
            $rules['transaction_category_id'] = [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && $clientId) {
                        $exists = DB::table('transaction_category')
                            ->where('id', $value)
                            ->where('client_id', $clientId)
                            ->where('deleted', 0)
                            ->exists();
                        
                        if (!$exists) {
                            $fail('The selected transaction category is invalid for your organization.');
                        }
                    }
                },
            ];
        }

        // Tracking code validation
        if ($this->has('tracking_code_id')) {
            $rules['tracking_code_id'] = [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && $clientId) {
                        $user = Auth::user();
                        $exists = DB::table('tracking_codes')
                            ->where('id', $value)
                            ->where('client_id', $clientId)
                            ->where(function ($query) use ($user) {
                                $query->whereNull('user_id')
                                    ->orWhere('user_id', $user->id);
                            })
                            ->where('deleted', 0)
                            ->exists();
                        
                        if (!$exists) {
                            $fail('The selected tracking code is invalid or not accessible to you.');
                        }
                    }
                },
            ];
        }

        // Project validation
        if ($this->has('project_id')) {
            $rules['project_id'] = [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $exists = DB::table('configurable_projects')
                            ->where('id', $value)
                            ->where('deleted', 0)
                            ->exists();
                        
                        if (!$exists) {
                            $fail('The selected project is invalid.');
                        }
                    }
                },
            ];
        }

        // File store validation
        if ($this->has('file_store_id')) {
            $rules['file_store_id'] = [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $user = Auth::user();
                        $exists = DB::table('file_store')
                            ->where('id', $value)
                            ->where('user_id', $user->id)
                            ->where('deleted', 0)
                            ->exists();
                        
                        if (!$exists) {
                            $fail('The selected file is invalid or not accessible to you.');
                        }
                    }
                },
            ];
        }

        // Expense source validation - must match client sources or global "Other"
        if ($this->has('expense_source_id')) {
            $rules['expense_source_id'] = [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value) {
                        $source = PocketExpenseSourceClientConfig::where('id', $value)
                            ->where('deleted', 0)
                            ->where(function ($query) use ($clientId) {
                                $query->where('client_id', $clientId)
                                    ->orWhereNull('client_id'); // Global "Other" source
                            })
                            ->first();
                        
                        if (!$source) {
                            $fail('The selected expense source is invalid for your organization.');
                        }
                    }
                },
            ];
        }

        // Source note validation - required when expense source is "Other"
        if ($this->has('source_note')) {
            $rules['source_note'] = [
                'nullable',
                'string',
                'max:255',
                'not_regex:/[<>"\']/', // Prevent basic XSS patterns
                function ($attribute, $value, $fail) {
                    $expenseSourceId = $this->input('expense_source_id');
                    
                    if ($expenseSourceId) {
                        $source = PocketExpenseSourceClientConfig::find($expenseSourceId);
                        
                        // Require note when source is "Other" (global source with client_id = null)
                        if ($source && $source->name === 'Other' && $source->client_id === null) {
                            if (empty($value)) {
                                $fail('Source note is required when expense source is "Other".');
                            }
                        }
                    }
                },
            ];
        }

        // Additional field validation
        if ($this->has('additional_field_id')) {
            $rules['additional_field_id'] = [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && $clientId) {
                        $exists = DB::table('expense_additional_field')
                            ->where('id', $value)
                            ->where('client_id', $clientId)
                            ->where('deleted', 0)
                            ->exists();
                        
                        if (!$exists) {
                            $fail('The selected additional field is invalid for your organization.');
                        }
                    }
                },
            ];
        }

        // Additional field value validation
        if ($this->has('additional_field_value')) {
            $rules['additional_field_value'] = [
                'nullable',
                'string',
                'max:500',
                'not_regex:/[<>"\']/', // Prevent basic XSS patterns
            ];
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
            $this->validateExpenseOwnership($validator);
            $this->validateStatusTransitions($validator);
            $this->validateAmountConsistency($validator);
        });
    }

    /**
     * Validate that the expense belongs to the authenticated user's client.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateExpenseOwnership($validator): void
    {
        $user = Auth::user();
        $expense = $this->route('expense');
        
        if ($user && $expense && $expense->client_id !== $user->client_id) {
            $validator->errors()->add('expense', 'This expense does not belong to your organization.');
        }
    }

    /**
     * Validate status transition business rules.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateStatusTransitions($validator): void
    {
        $expense = $this->route('expense');
        $newStatus = $this->input('status');
        $user = Auth::user();
        
        if (!$expense || !$newStatus || !$user) {
            return;
        }

        // Users can only edit their own expenses unless they have management rights
        if ($expense->user_id !== $user->id) {
            // Check if user has management rights for the expense owner
            $hasManagementRights = DB::table('user_feature_permission')
                ->where('manager_user_id', $user->id)
                ->where('user_id', $expense->user_id)
                ->where('client_id', $expense->client_id)
                ->where('feature_id', 16) // OOP Expense feature
                ->where('is_enabled', 1)
                ->exists();
            
            if (!$hasManagementRights && !in_array($user->role, ['Primary Admin'])) {
                $validator->errors()->add('expense', 'You do not have permission to update this expense.');
                return;
            }
        }

        // Validate specific status transitions
        switch ($expense->status) {
            case 'approved':
                if ($newStatus !== 'approved') {
                    $validator->errors()->add('status', 'Approved expenses cannot be changed to another status.');
                }
                break;
            
            case 'rejected':
                if ($newStatus === 'approved') {
                    $validator->errors()->add('status', 'Rejected expenses cannot be directly approved. Change to draft or submitted first.');
                }
                break;
            
            case 'submitted':
                // Only admins and users with approval rights can change from submitted
                if ($newStatus === 'approved' && in_array($user->role, ['Business User', 'Card User'])) {
                    $validator->errors()->add('status', 'You do not have permission to approve expenses.');
                }
                break;
        }
    }

    /**
     * Validate amount and VAT consistency.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateAmountConsistency($validator): void
    {
        $amount = $this->input('amount');
        $vatAmount = $this->input('vat_amount');
        $vatPercentage = $this->input('vat_percentage');
        
        if (!$amount) {
            return;
        }

        $absAmount = abs($amount);
        
        // Validate VAT amount doesn't exceed total amount
        if ($vatAmount && $vatAmount > $absAmount) {
            $validator->errors()->add('vat_amount', 'VAT amount cannot exceed the total expense amount.');
        }
        
        // Validate VAT percentage calculation consistency
        if ($vatAmount && $vatPercentage) {
            $cleanPercentage = str_replace('%', '', $vatPercentage);
            if (is_numeric($cleanPercentage)) {
                $expectedVat = $absAmount * ((float) $cleanPercentage / 100);
                $tolerance = 0.01; // 1 cent tolerance for rounding
                
                if (abs($vatAmount - $expectedVat) > $tolerance) {
                    $validator->errors()->add('vat_amount', 'VAT amount does not match the calculated VAT percentage.');
                }
            }
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Expense date is required.',
            'date.date_format' => 'Expense date must be in DD/MM/YYYY format.',
            'merchant_name.required' => 'Merchant name is required.',
            'merchant_name.max' => 'Merchant name cannot exceed 180 characters.',
            'merchant_name.not_regex' => 'Merchant name contains invalid characters.',
            'expense_type.required' => 'Expense type is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency must be exactly 3 characters.',
            'currency.regex' => 'Currency must be 3 uppercase letters (e.g., GBP, EUR, USD).',
            'amount.required' => 'Amount is required.',
            'amount.numeric' => 'Amount must be a valid number.',
            'amount.not_in' => 'Amount cannot be zero.',
            'amount.regex' => 'Amount must have at most 2 decimal places.',
            'vat_amount.numeric' => 'VAT amount must be a valid number.',
            'vat_amount.min' => 'VAT amount cannot be negative.',
            'vat_amount.regex' => 'VAT amount must have at most 2 decimal places.',
            'status.in' => 'Status must be one of: draft, submitted, approved, rejected.',
            'source_note.max' => 'Source note cannot exceed 255 characters.',
            'source_note.not_regex' => 'Source note contains invalid characters.',
            'notes.max' => 'Notes cannot exceed 2000 characters.',
            'notes.not_regex' => 'Notes contain invalid characters.',
            'merchant_description.max' => 'Merchant description cannot exceed 1000 characters.',
            'merchant_description.not_regex' => 'Merchant description contains invalid characters.',
            'merchant_address.max' => 'Merchant address cannot exceed 500 characters.',
            'merchant_address.not_regex' => 'Merchant address contains invalid characters.',
            'additional_field_value.max' => 'Additional field value cannot exceed 500 characters.',
            'additional_field_value.not_regex' => 'Additional field value contains invalid characters.',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();
        
        // Convert date from DD/MM/YYYY to Y-m-d format for database storage
        if (isset($data['date']) && !empty($data['date'])) {
            try {
                $date = Carbon::createFromFormat('d/m/Y', $data['date']);
                $data['date'] = $date->format('Y-m-d');
            } catch (\Exception $e) {
                // Keep original value for validation to catch the error
            }
        }
        
        // Normalize currency to uppercase
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        
        // Trim and clean text fields to prevent XSS and improve data quality
        $textFields = ['merchant_name', 'merchant_description', 'merchant_address', 'notes', 'source_note', 'additional_field_value'];
        foreach ($textFields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
                // Remove null bytes and normalize whitespace
                $data[$field] = preg_replace('/\s+/', ' ', str_replace("\0", '', $data[$field]));
                // Convert empty strings to null for optional fields
                if ($data[$field] === '' && in_array($field, ['merchant_description', 'merchant_address', 'notes', 'source_note', 'additional_field_value'])) {
                    $data[$field] = null;
                }
            }
        }
        
        // Strip % sign from VAT percentage for internal processing
        if (isset($data['vat_percentage']) && !empty($data['vat_percentage'])) {
            $data['vat_percentage'] = str_replace('%', '', $data['vat_percentage']);
        }
        
        // Ensure numeric fields are properly typed
        $numericFields = ['amount', 'vat_amount'];
        foreach ($numericFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                $data[$field] = (float) $data[$field];
            }
        }
        
        // Ensure integer fields are properly typed
        $integerFields = ['expense_type', 'transaction_category_id', 'tracking_code_id', 'project_id', 'file_store_id', 'expense_source_id', 'additional_field_id'];
        foreach ($integerFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
                $data[$field] = (int) $data[$field];
            }
        }
        
        $this->replace($data);
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
            'expense_type' => 'expense type',
            'currency' => 'currency',
            'amount' => 'amount',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
            'vat_percentage' => 'VAT percentage',
            'notes' => 'notes',
            'status' => 'status',
            'transaction_category_id' => 'transaction category',
            'tracking_code_id' => 'tracking code',
            'project_id' => 'project',
            'file_store_id' => 'file attachment',
            'expense_source_id' => 'expense source',
            'source_note' => 'source note',
            'additional_field_id' => 'additional field',
            'additional_field_value' => 'additional field value',
        ];
    }
}