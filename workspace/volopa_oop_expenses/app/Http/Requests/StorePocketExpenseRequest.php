<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Carbon\Carbon;

class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by the controller via policy
        // The form request focuses on data validation
        return true;
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
            'date' => [
                'required',
                'date_format:d/m/Y',
                'before_or_equal:today',
                'after_or_equal:' . now()->subYears(3)->format('d/m/Y'),
            ],
            'expense_type' => [
                'required',
                'integer',
                Rule::exists('opt_pocket_expense_type', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
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
                'not_in:0',
                'regex:/^-?\d+(\.\d{1,2})?$/',
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:180',
                'min:1',
            ],
            
            // Optional fields
            'merchant_description' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'regex:/^\d+(\.\d{1,2})?$/',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            
            // Context fields - these are provided by the controller/middleware
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('deleted', false);
                }),
            ],
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(function ($query) {
                    $query->where('deleted', false);
                }),
            ],
            
            // Status field - defaults to draft but can be overridden
            'status' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'submitted']),
            ],
            
            // Metadata fields for expense source and additional info
            'expense_source_id' => [
                'nullable',
                'integer',
                Rule::exists('pocket_expense_source_client_config', 'id')->where(function ($query) {
                    if ($this->has('client_id')) {
                        $query->where(function ($subQuery) {
                            $subQuery->where('client_id', $this->client_id)
                                   ->orWhereNull('client_id'); // Allow global sources like 'Other'
                        })->where('deleted', false);
                    }
                }),
            ],
            'source_note' => [
                'required_if:expense_source_name,Other',
                'nullable',
                'string',
                'max:1000',
            ],
            'expense_source_name' => [
                'nullable',
                'string',
                'max:100',
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
            'date.required' => 'Expense date is required.',
            'date.date_format' => 'Expense date must be in DD/MM/YYYY format.',
            'date.before_or_equal' => 'Expense date cannot be in the future.',
            'date.after_or_equal' => 'Expense date cannot be older than 3 years.',
            'expense_type.required' => 'Expense type is required.',
            'expense_type.exists' => 'Selected expense type is not valid or inactive.',
            'currency.required' => 'Currency code is required.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.regex' => 'Currency code must be 3 uppercase letters.',
            'amount.required' => 'Expense amount is required.',
            'amount.numeric' => 'Expense amount must be a valid number.',
            'amount.not_in' => 'Expense amount cannot be zero.',
            'amount.regex' => 'Expense amount must have at most 2 decimal places.',
            'merchant_name.required' => 'Merchant name is required.',
            'merchant_name.max' => 'Merchant name cannot exceed 180 characters.',
            'merchant_name.min' => 'Merchant name cannot be empty.',
            'merchant_description.max' => 'Merchant description is too long.',
            'merchant_address.max' => 'Merchant address cannot exceed 500 characters.',
            'vat_amount.numeric' => 'VAT amount must be a valid number.',
            'vat_amount.min' => 'VAT percentage cannot be negative.',
            'vat_amount.max' => 'VAT percentage cannot exceed 100%.',
            'vat_amount.regex' => 'VAT amount must have at most 2 decimal places.',
            'notes.max' => 'Notes are too long.',
            'user_id.required' => 'User ID is required.',
            'user_id.exists' => 'Selected user does not exist or is inactive.',
            'client_id.required' => 'Client ID is required.',
            'client_id.exists' => 'Selected client does not exist or is inactive.',
            'status.in' => 'Status must be either draft or submitted.',
            'expense_source_id.exists' => 'Selected expense source is not valid or inactive.',
            'source_note.required_if' => 'Source note is required when expense source is Other.',
            'source_note.max' => 'Source note cannot exceed 1000 characters.',
            'expense_source_name.max' => 'Expense source name cannot exceed 100 characters.',
        ];
    }

    /**
     * Get custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'expense date',
            'expense_type' => 'expense type',
            'currency' => 'currency code',
            'amount' => 'amount',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT percentage',
            'notes' => 'notes',
            'user_id' => 'user',
            'client_id' => 'client',
            'status' => 'status',
            'expense_source_id' => 'expense source',
            'source_note' => 'source note',
            'expense_source_name' => 'expense source name',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Trim string fields to prevent leading/trailing whitespace issues
        $this->merge([
            'merchant_name' => $this->trimField('merchant_name'),
            'merchant_description' => $this->trimField('merchant_description'),
            'merchant_address' => $this->trimField('merchant_address'),
            'notes' => $this->trimField('notes'),
            'source_note' => $this->trimField('source_note'),
            'expense_source_name' => $this->trimField('expense_source_name'),
        ]);

        // Strip % sign from VAT amount if present
        if ($this->has('vat_amount') && $this->vat_amount !== null) {
            $vatAmount = str_replace('%', '', (string) $this->vat_amount);
            $this->merge(['vat_amount' => $vatAmount ?: null]);
        }

        // Set default status if not provided
        if (!$this->has('status')) {
            $this->merge(['status' => 'draft']);
        }

        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper($this->currency)]);
        }
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
            // Validate that user belongs to the specified client
            if ($this->user_id && $this->client_id) {
                $userBelongsToClient = User::where('id', $this->user_id)
                    ->where('client_id', $this->client_id)
                    ->where('deleted', false)
                    ->exists();

                if (!$userBelongsToClient) {
                    $validator->errors()->add('user_id', 'The selected user does not belong to the specified client.');
                }
            }

            // Validate currency against platform supported currencies
            if ($this->currency) {
                $supportedCurrencies = $this->getSupportedCurrencies();
                if (!in_array($this->currency, $supportedCurrencies)) {
                    $validator->errors()->add('currency', 'The selected currency is not supported.');
                }
            }

            // Validate amount sign based on expense type
            if ($this->expense_type && $this->amount) {
                $expenseType = OptPocketExpenseType::find($this->expense_type);
                if ($expenseType) {
                    $expectedSign = $expenseType->amount_sign;
                    $actualSign = $this->amount >= 0 ? 'positive' : 'negative';
                    
                    if ($expectedSign !== $actualSign) {
                        $signText = $expectedSign === 'positive' ? 'positive' : 'negative';
                        $validator->errors()->add('amount', "Amount must be {$signText} for the selected expense type.");
                    }
                }
            }

            // Validate expense source requirements for 'Other'
            if ($this->expense_source_name === 'Other' && empty($this->source_note)) {
                $validator->errors()->add('source_note', 'Source note is required when expense source is Other.');
            }

            // Validate that expense source belongs to client (if specified)
            if ($this->expense_source_id && $this->client_id) {
                $sourceExists = PocketExpenseSourceClientConfig::where('id', $this->expense_source_id)
                    ->where(function ($query) {
                        $query->where('client_id', $this->client_id)
                            ->orWhereNull('client_id'); // Allow global sources
                    })
                    ->where('deleted', false)
                    ->exists();

                if (!$sourceExists) {
                    $validator->errors()->add('expense_source_id', 'The selected expense source is not available for this client.');
                }
            }
        });
    }

    /**
     * Get the validated data with additional processing.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // Convert date from DD/MM/YYYY to Y-m-d format for database storage
        if (isset($validated['date'])) {
            $validated['date'] = Carbon::createFromFormat('d/m/Y', $validated['date'])->format('Y-m-d');
        }

        return $key ? ($validated[$key] ?? $default) : $validated;
    }

    /**
     * Trim a field value if it exists and is a string.
     *
     * @param string $field
     * @return string|null
     */
    private function trimField(string $field): ?string
    {
        $value = $this->input($field);
        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Get the list of supported currencies.
     * In a real implementation, this would come from platform configuration.
     *
     * @return array<string>
     */
    private function getSupportedCurrencies(): array
    {
        // This should ideally come from a configuration service or database
        // For now, returning common ISO currency codes as per platform constraints
        return [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'SGD', 
            'HKD', 'NZD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF',
            'ZAR', 'BRL', 'MXN', 'INR', 'CNY', 'KRW', 'THB', 'MYR',
        ];
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('You are not authorized to create expenses for this user.');
    }
}