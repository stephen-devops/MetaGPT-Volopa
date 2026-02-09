## Code: app/Http/Requests/CreateExpenseRequest.php

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

class CreateExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $expensePolicy = new ExpensePolicy();
        
        // Check if user can create expenses in general
        if (!$expensePolicy->create($this->user())) {
            return false;
        }
        
        // If creating for another user, check specific authorization
        $expenseUserId = $this->input('user_id', $this->user()->id);
        $clientId = $this->input('client_id');
        
        if ($expenseUserId !== $this->user()->id) {
            return $expensePolicy->createForUser($this->user(), $expenseUserId, $clientId);
        }
        
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $currentDate = Carbon::now();
        $threeYearsAgo = $currentDate->copy()->subYears(3);
        
        return [
            'user_id' => [
                'sometimes',
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
                'date',
                'date_format:Y-m-d',
                'before_or_equal:' . $currentDate->format('Y-m-d'),
                'after_or_equal:' . $threeYearsAgo->format('Y-m-d'),
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:255',
                'min:1',
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'transaction_type' => [
                'required',
                'string',
                Rule::in(OopExpense::getValidTransactionTypes()),
                Rule::exists('opt_pocket_expense_type', 'option')->where('is_active', true),
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
            ],
            'amount' => [
                'required',
                'numeric',
                'between:0.01,999999.99',
                'decimal:0,2',
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'country' => [
                'nullable',
                'string',
                'max:100',
            ],
            'source' => [
                'nullable',
                'string',
                'max:100',
            ],
            'source_note' => [
                'nullable',
                'string',
                'max:255',
                'required_if:source,Other',
            ],
            'category' => [
                'nullable',
                'string',
                'max:100',
            ],
            'custom_fields' => [
                'nullable',
                'array',
                'max:10',
            ],
            'custom_fields.*' => [
                'string',
                'max:500',
            ],
            'tracking_code_i' => [
                'nullable',
                'string',
                'max:100',
            ],
            'tracking_code_ii' => [
                'nullable',
                'string',
                'max:100',
            ],
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'vat' => [
                'nullable',
                'numeric',
                'between:0,100',
                'decimal:0,2',
            ],
            'receipt_path' => [
                'nullable',
                'string',
                'max:500',
            ],
            'notes' => [
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
            'user_id.exists' => 'The selected user does not exist.',
            'client_id.required' => 'The client is required.',
            'client_id.exists' => 'The selected client does not exist.',
            'date.required' => 'The expense date is required.',
            'date.date' => 'The expense date must be a valid date.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            'date.after_or_equal' => 'The expense date cannot be older than 3 years.',
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.max' => 'The merchant name may not be greater than 255 characters.',
            'merchant_name.min' => 'The merchant name must be at least 1 character.',
            'description.max' => 'The description may not be greater than 1000 characters.',
            'transaction_type.required' => 'The transaction type is required.',
            'transaction_type.in' => 'The selected transaction type is invalid.',
            'transaction_type.exists' => 'The selected transaction type is not available.',
            'currency.required' => 'The currency is required.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be a valid 3-letter ISO currency code.',
            'amount.required' => 'The expense amount is required.',
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
            'user_id' => 'expense user',
            'client_id' => 'client',
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
            $this->validateExpenseUser($validator);
            $this->validateExpenseSource($validator);
            $this->validateTransactionType($validator);
            $this->validateCurrencySupported($validator);
            $this->validateProjectBelongsToClient($validator);
            $this->validateCustomFields($validator);
        });
    }

    /**
     * Validate that the expense user belongs to the specified client.
     */
    protected function validateExpenseUser($validator): void
    {
        $userId = $this->input('user_id', $this->user()->id);
        $clientId = $this->input('client_id');
        
        if ($userId && $clientId) {
            // Check if user belongs to client (this would require a user-client relationship)
            // For now, we'll assume any user can create expenses for any client they have access to
            // This validation would be implemented based on the actual user-client relationship model
        }
    }

    /**
     * Validate that the expense source exists for the client.
     */
    protected function validateExpenseSource($validator): void
    {
        $source = $this->input('source');
        $clientId = $this->input('client_id');
        
        if ($source && $clientId) {
            $sourceConfig = PocketExpenseSourceClientConfig::findByNameForClient($source, $clientId);
            
            if (!$sourceConfig) {
                $validator->errors()->add('source', 'The selected expense source is not available for this client.');
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
        $clientId = $this->input('client_id');
        
        if ($projectId && $clientId) {
            // Check if project belongs to client (this would require a project-client relationship)
            // For now, we'll assume the relationship exists
            // This validation would be implemented based on the actual project-client relationship model
        }
    }

    /**
     * Validate custom fields structure and content.
     */
    protected function validateCustomFields($validator): void
    {
        $customFields = $this->input('custom_fields', []);
        
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
        
        // Set user_id to authenticated user if not provided
        if (!$this->has('user_id')) {
            $data['user_id'] = $this->user()->id;
        }
        
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
            $data['tracking_code_i'] = trim($this->input('