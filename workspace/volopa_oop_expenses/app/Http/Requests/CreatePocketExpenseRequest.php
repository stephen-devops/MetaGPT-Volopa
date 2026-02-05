## Code: app/Http/Requests/CreatePocketExpenseRequest.php

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;
use Carbon\Carbon;

/**
 * CreatePocketExpenseRequest
 * 
 * Handles validation and authorization for creating pocket expenses.
 * Validates expense data according to business rules and database constraints.
 * Implements policy-based authorization using PocketExpensePolicy.
 */
class CreatePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $clientId = $this->input('client_id');
        $targetUserId = $this->input('user_id', $user->id);

        // Use policy to check authorization
        return $user->can('create', [PocketExpense::class, $clientId, $targetUserId]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $clientId = $this->input('client_id');
        
        return [
            // Core required fields
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($user, $clientId) {
                    // Additional authorization check for target user
                    if (!$user->can('create', [PocketExpense::class, $clientId, $value])) {
                        $fail('You do not have permission to create expenses for this user.');
                    }
                },
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
                'before_or_equal:today',
                function ($attribute, $value, $fail) {
                    $threeYearsAgo = Carbon::now()->subYears(3);
                    $expenseDate = Carbon::parse($value);
                    
                    if ($expenseDate->lt($threeYearsAgo)) {
                        $fail('The expense date cannot be more than 3 years old.');
                    }
                },
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:255',
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
                'size:3',
                'regex:/^[A-Z]{3}$/',
                function ($attribute, $value, $fail) {
                    // Validate against allowed currencies
                    $allowedCurrencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK'];
                    if (!in_array($value, $allowedCurrencies)) {
                        $fail('The selected currency is not supported.');
                    }
                },
            ],
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d+(\.\d{1,2})?$/',
                'min:0.01',
                'max:999999.99',
            ],

            // Optional fields
            'merchant_description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'merchant_country' => [
                'nullable',
                'string',
                'size:2',
                'regex:/^[A-Z]{2}$/',
                function ($attribute, $value, $fail) {
                    // Validate against ISO country codes
                    $allowedCountries = [
                        'US', 'GB', 'CA', 'AU', 'FR', 'DE', 'IT', 'ES', 'NL', 'BE', 
                        'CH', 'AT', 'SE', 'NO', 'DK', 'FI', 'IE', 'PT', 'LU', 'JP'
                    ];
                    if ($value && !in_array($value, $allowedCountries)) {
                        $fail('The selected country is not supported.');
                    }
                },
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'regex:/^\d+(\.\d{1,2})?$/',
                'min:0',
                'max:99.99',
            ],
            'user_converted_amount' => [
                'nullable',
                'numeric',
                'regex:/^\d+(\.\d{1,2})?$/',
                'min:0.01',
                'max:999999.99',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(['draft', 'submitted']),
            ],

            // Metadata fields
            'expense_source_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value) {
                        // Validate expense source exists and is available for client
                        $source = PocketExpenseSourceClientConfig::active()
                            ->availableForClient($clientId)
                            ->find($value);
                            
                        if (!$source) {
                            $fail('The selected expense source is not available for this client.');
                        }
                    }
                },
            ],
            'source_note' => [
                'nullable',
                'string',
                'max:500',
                'required_if:expense_source_name,Other',
            ],
            'expense_source_name' => [
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value) {
                        // Validate expense source name exists for client
                        $source = PocketExpenseSourceClientConfig::active()
                            ->availableForClient($clientId)
                            ->byNameInsensitive($value)
                            ->first();
                            
                        if (!$source) {
                            $fail('The selected expense source is not available for this client.');
                        }
                        
                        // If "Other" is selected, source_note becomes required
                        if (strtolower($value) === 'other' && empty($this->input('source_note'))) {
                            $fail('Source note is required when expense source is "Other".');
                        }
                    }
                },
            ],
            'transaction_category_id' => [
                'nullable',
                'integer',
                'exists:transaction_categories,id',
            ],
            'tracking_code_id' => [
                'nullable',
                'integer',
                'exists:tracking_codes,id',
            ],
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'additional_fields' => [
                'nullable',
                'array',
                'max:10',
            ],
            'additional_fields.*.field_id' => [
                'required_with:additional_fields',
                'integer',
                'exists:additional_fields,id',
            ],
            'additional_fields.*.value' => [
                'required_with:additional_fields',
                'string',
                'max:1000',
            ],
            'files' => [
                'nullable',
                'array',
                'max:5',
            ],
            'files.*' => [
                'file',
                'mimes:pdf,jpg,jpeg,png,gif',
                'max:5120', // 5MB max per file
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
            'user_id.required' => 'The user is required.',
            'user_id.integer' => 'The user must be a valid user ID.',
            'user_id.exists' => 'The selected user does not exist.',
            
            'client_id.required' => 'The client is required.',
            'client_id.integer' => 'The client must be a valid client ID.',
            'client_id.exists' => 'The selected client does not exist.',
            
            'date.required' => 'The expense date is required.',
            'date.date' => 'The expense date must be a valid date.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.string' => 'The merchant name must be a string.',
            'merchant_name.max' => 'The merchant name may not be greater than 255 characters.',
            'merchant_name.min' => 'The merchant name must be at least 1 character.',
            
            'expense_type.required' => 'The expense type is required.',
            'expense_type.integer' => 'The expense type must be a valid type ID.',
            'expense_type.exists' => 'The selected expense type does not exist.',
            
            'currency.required' => 'The currency is required.',
            'currency.string' => 'The currency must be a string.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be a valid 3-letter ISO code.',
            
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a number.',
            'amount.regex' => 'The amount must have at most 2 decimal places.',
            'amount.min' => 'The amount must be at least 0.01.',
            'amount.max' => 'The amount may not be greater than 999,999.99.',
            
            'merchant_description.string' => 'The merchant description must be a string.',
            'merchant_description.max' => 'The merchant description may not be greater than 1000 characters.',
            
            'merchant_address.string' => 'The merchant address must be a string.',
            'merchant_address.max' => 'The merchant address may not be greater than 500 characters.',
            
            'merchant_country.string' => 'The merchant country must be a string.',
            'merchant_country.size' => 'The merchant country must be exactly 2 characters.',
            'merchant_country.regex' => 'The merchant country must be a valid 2-letter ISO country code.',
            
            'vat_amount.numeric' => 'The VAT amount must be a number.',
            'vat_amount.regex' => 'The VAT amount must have at most 2 decimal places.',
            'vat_amount.min' => 'The VAT amount must be at least 0.',
            'vat_amount.max' => 'The VAT amount may not be greater than 99.99.',
            
            'user_converted_amount.numeric' => 'The converted amount must be a number.',
            'user_converted_amount.regex' => 'The converted amount must have at most 2 decimal places.',
            'user_converted_amount.min' => 'The converted amount must be at least 0.01.',
            'user_converted_amount.max' => 'The converted amount may not be greater than 999,999.99.',
            
            'notes.string' => 'The notes must be a string.',
            'notes.max' => 'The notes may not be greater than 2000 characters.',
            
            'status.string' => 'The status must be a string.',
            'status.in' => 'The status must be either draft or submitted.',
            
            'expense_source_id.integer' => 'The expense source must be a valid source ID.',
            
            'source_note.string' => 'The source note must be a string.',
            'source_note.max' => 'The source note may not be greater than 500 characters.',
            'source_note.required_if' => 'The source note is required when expense source is "Other".',
            
            'expense_source_name.string' => 'The expense source name must be a string.',
            'expense_source_name.max' => 'The expense source name may not be greater than 100 characters.',
            
            'transaction_category_id.integer' => 'The transaction category must be a valid category ID.',
            'transaction_category_id.exists' => 'The selected transaction category does not exist.',
            
            'tracking_code_id.integer' => 'The tracking code must be a valid tracking code ID.',
            'tracking_code_id.exists' => 'The selected tracking code does not exist.',
            
            'project_id.integer' => 'The project must be a valid project ID.',
            'project_id.exists' => 'The selected project does not exist.',
            
            'additional_fields.array' => 'The additional fields must be an array.',
            'additional_fields.max' => 'You may not add more than 10 additional fields.',
            
            'additional_fields.*.field_id.required_with' => 'The additional field ID is required.',
            'additional_fields.*.field_id.integer' => 'The additional field ID must be a valid field ID.',
            'additional_fields.*.field_id.exists' => 'The selected additional field does not exist.',
            
            'additional_fields.*.value.required_with' => 'The additional field value is required.',
            'additional_fields.*.value.string' => 'The additional field value must be a string.',
            'additional_fields.*.value.max' => 'The additional field value may not be greater than 1000 characters.',
            
            'files.array' => 'The files must be an array.',
            'files.max' => 'You may not upload more than 5 files.',
            
            'files.*.file' => 'Each file must be a valid file.',
            'files.*.mimes' => 'Each file must be a PDF, JPG, JPEG, PNG, or GIF.',
            'files.*.max' => 'Each file may not be larger than 5MB.',
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
            'user_id' => 'user',
            'client_id' => 'client',
            'expense_type' => 'expense type',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'merchant_address' => 'merchant address',
            'merchant_country' => 'merchant country',
            'vat_amount' => 'VAT amount',
            'user_converted_amount' => 'converted amount',
            'expense_source_id' => 'expense source',
            'expense_source_name' => 'expense source name',
            'source_note' => 'source note',
            'transaction_category_id' => 'transaction category',
            'tracking_code_id' => 'tracking code',
            'project_id' => 'project',
            'additional_fields' => 'additional fields',
            'additional_fields.*.field_id' => 'additional field',
            'additional_fields.*.value' => 'additional field value',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     *