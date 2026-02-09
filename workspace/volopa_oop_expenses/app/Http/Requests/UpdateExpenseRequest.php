## Code: app/Http/Requests/UpdateExpenseRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\OopExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\ExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class UpdateExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $expense = $this->route('expense');
        
        if (!$expense instanceof OopExpense) {
            return false;
        }
        
        $expensePolicy = new ExpensePolicy();
        return $expensePolicy->update($this->user(), $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $currentDate = Carbon::now();
        $threeYearsAgo = $currentDate->copy()->subYears(3);
        $expense = $this->route('expense');
        
        return [
            'date' => [
                'sometimes',
                'date',
                'date_format:Y-m-d',
                'before_or_equal:' . $currentDate->format('Y-m-d'),
                'after_or_equal:' . $threeYearsAgo->format('Y-m-d'),
            ],
            'merchant_name' => [
                'sometimes',
                'string',
                'max:255',
                'min:1',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            'transaction_type' => [
                'sometimes',
                'string',
                Rule::in(OopExpense::getValidTransactionTypes()),
                Rule::exists('opt_pocket_expense_type', 'option')->where('is_active', true),
            ],
            'currency' => [
                'sometimes',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'amount' => [
                'sometimes',
                'numeric',
                'between:0.01,999999.99',
                'decimal:0,2',
            ],
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'country' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'source' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'source_note' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'required_if:source,Other',
            ],
            'category' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'custom_fields' => [
                'sometimes',
                'nullable',
                'array',
                'max:10',
            ],
            'custom_fields.*' => [
                'string',
                'max:500',
            ],
            'tracking_code_i' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'tracking_code_ii' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'vat' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:0,100',
                'decimal:0,2',
            ],
            'receipt_path' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'date.date' => 'The expense date must be a valid date.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            'date.after_or_equal' => 'The expense date cannot be older than 3 years.',
            'merchant_name.max' => 'The merchant name may not be greater than 255 characters.',
            'merchant_name.min' => 'The merchant name must be at least 1 character.',
            'description.max' => 'The description may not be greater than 1000 characters.',
            'transaction_type.in' => 'The selected transaction type is invalid.',
            'transaction_type.exists' => 'The selected transaction type is not available.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be a valid 3-letter ISO currency code.',
            'amount.numeric' => 'The expense amount must be a number.',
            'amount.between' => 'The expense amount must be between 0.01 and 999,999.99.',
            'amount.decimal' => 'The expense amount may have at most 2 decimal places.',
            'merchant_address.max' => 'The merchant address may not be greater than 500 characters.',
            'country.max' => 'The country may not be greater than 100 characters.',
            'source.max' => 'The source may not be greater than 100 characters.',
            'source_note.required_if' => 'The source note is required when source is "Other".',
            'source_note.max' => 'The source note may not be greater than 255 characters.',
            'category.max' => 'The category may not be greater than 100 characters.',
            'custom_fields.array' => 'The custom fields must be an array.',
            'custom_fields.max' => 'The custom fields may not have more than 10 items.',
            'custom_fields.*.string' => 'Each custom field must be a string.',
            'custom_fields.*.max' => 'Each custom field may not be greater than 500 characters.',
            'tracking_code_i.max' => 'The first tracking code may not be greater than 100 characters.',
            'tracking_code_ii.max' => 'The second tracking code may not be greater than 100 characters.',
            'project_id.exists' => 'The selected project does not exist.',
            'vat.numeric' => 'The VAT percentage must be a number.',
            'vat.between' => 'The VAT percentage must be between 0 and 100.',
            'vat.decimal' => 'The VAT percentage may have at most 2 decimal places.',
            'receipt_path.max' => 'The receipt path may not be greater than 500 characters.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'date' => 'expense date',
            'merchant_name' => 'merchant name',
            'description' => 'description',
            'transaction_type' => 'transaction type',
            'currency' => 'currency',
            'amount' => 'expense amount',
            'merchant_address' => 'merchant address',
            'country' => 'country',
            'source' => 'expense source',
            'source_note' => 'source note',
            'category' => 'expense category',
            'custom_fields' => 'custom fields',
            'tracking_code_i' => 'first tracking code',
            'tracking_code_ii' => 'second tracking code',
            'project_id' => 'project',
            'vat' => 'VAT percentage',
            'receipt_path' => 'receipt path',
            'notes' => 'notes',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateExpenseCanBeUpdated($validator);
            $this->validateExpenseSource($validator);
            $this->validateTransactionType($validator);
            $this->validateCurrencySupported($validator);
            $this->validateProjectBelongsToClient($validator);
            $this->validateCustomFields($validator);
        });
    }

    /**
     * Validate that the expense can be updated (must be pending).
     */
    protected function validateExpenseCanBeUpdated($validator): void
    {
        $expense = $this->route('expense');
        
        if ($expense && !$expense->canBeUpdated()) {
            $validator->errors()->add('status', 'Only pending expenses can be updated.');
        }
    }

    /**
     * Validate that the expense source exists for the client.
     */
    protected function validateExpenseSource($validator): void
    {
        $source = $this->input('source');
        
        if ($source) {
            $expense = $this->route('expense');
            $clientId = $expense ? $expense->client_id : null;
            
            if ($clientId) {
                $sourceConfig = PocketExpenseSourceClientConfig::findByNameForClient($source, $clientId);
                
                if (!$sourceConfig) {
                    $validator->errors()->add('source', 'The selected expense source is not available for this client.');
                }
            }
        }
    }

    /**
     * Validate that the transaction type is active and valid.
     */
    protected function validateTransactionType($validator): void
    {
        $transactionType = $this->input('transaction_type');
        
        if ($transactionType) {
            $expenseType = OptPocketExpenseType::findActiveByOption($transactionType);
            
            if (!$expenseType) {
                $validator->errors()->add('transaction_type', 'The selected transaction type is not active or available.');
            }
        }
    }

    /**
     * Validate that the currency is supported.
     */
    protected function validateCurrencySupported($validator): void
    {
        $currency = $this->input('currency');
        
        if ($currency) {
            // Define supported currencies (this could be moved to config or database)
            $supportedCurrencies = [
                'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
                'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR', 'KRW',
                'DKK', 'PLN', 'TWD', 'THB', 'MYR', 'HUF', 'CZK', 'ILS', 'CLP', 'PHP',
                'AED', 'COP', 'SAR', 'RON', 'BGN', 'HRK', 'ISK', 'LBP', 'EGP', 'JOD',
            ];
            
            if (!in_array($currency, $supportedCurrencies)) {
                $validator->errors()->add('currency', 'The selected currency is not supported.');
            }
        }
    }

    /**
     * Validate that the project belongs to the specified client.
     */
    protected function validateProjectBelongsToClient($validator): void
    {
        $projectId = $this->input('project_id');
        
        if ($projectId) {
            $expense = $this->route('expense');
            $clientId = $expense ? $expense->client_id : null;
            
            if ($clientId) {
                // Check if project belongs to client (this would require a project-client relationship)
                // For now, we'll assume the relationship exists
                // This validation would be implemented based on the actual project-client relationship model
            }
        }
    }

    /**
     * Validate custom fields structure and content.
     */
    protected function validateCustomFields($validator): void
    {
        $customFields = $this->input('custom_fields');
        
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                // Validate key format
                if (!is_string($key) || empty(trim($key))) {
                    $validator->errors()->add('custom_fields', 'Custom field keys must be non-empty strings.');
                    break;
                }
                
                // Validate key length
                if (strlen($key) > 100) {
                    $validator->errors()->add('custom_fields', 'Custom field keys may not be greater than 100 characters.');
                    break;
                }
                
                // Validate key format (alphanumeric and underscores only)
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                    $validator->errors()->add('custom_fields', 'Custom field keys may only contain letters, numbers, and underscores.');
                    break;
                }
            }
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $data = [];
        
        // Normalize merchant name
        if ($this->has('merchant_name')) {
            $data['merchant_name'] = trim($this->input('merchant_name'));
        }
        
        // Normalize description
        if ($this->has('description')) {
            $data['description'] = trim($this->input('description')) ?: null;
        }
        
        // Normalize merchant address
        if ($this->has('merchant_address')) {
            $data['merchant_address'] = trim($this->input('merchant_address')) ?: null;
        }
        
        // Normalize country
        if ($this->has('country')) {
            $data['country'] = trim($this->input('country')) ?: null;
        }
        
        // Normalize source
        if ($this->has('source')) {
            $data['source'] = trim($this->input('source')) ?: null;
        }
        
        // Normalize source note
        if ($this->has('source_note')) {
            $data['source_note'] = trim($this->input('source_note')) ?: null;
        }
        
        // Normalize category
        if ($this->has('category')) {
            $data['category'] = trim($this->input('category')) ?: null;
        }
        
        // Normalize tracking codes
        if ($this->has('tracking_code_i')) {
            $data['tracking_code_i'] = trim($this->input('tracking_code_i')) ?: null;
        }
        
        if ($this->has('tracking_code_ii')) {
            $data['tracking_code_ii'] = trim($this->input('tracking_code_ii')) ?: null;
        }
        
        // Normalize notes
        if ($this->has('notes')) {
            $data['notes'] = trim($this->input('notes')) ?: null;
        }
        
        // Normalize receipt path
        if ($this->has('receipt_path')) {
            $data['receipt_path'] = trim($this->input('receipt_path')) ?: null;
        }
        
        // Uppercase currency code
        if ($this->has('currency')) {
            $data['currency'] = strtoupper(trim($this->input('currency')));
        }
        
        // Clean custom fields
        if ($this->has('custom_fields') && is_array($this->input('custom_fields'))) {
            $customFields = [];
            foreach ($this->input('custom_fields') as $key => $value) {
                $cleanKey = trim($key);
                $cleanValue = is_string($value) ? trim($value) : $value;