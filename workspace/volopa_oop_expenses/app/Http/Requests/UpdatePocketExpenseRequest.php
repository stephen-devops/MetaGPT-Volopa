<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

/**
 * Form Request for updating pocket expenses
 * 
 * Handles validation and authorization for expense updates with partial field validation.
 * Supports FX conversion, metadata updates, and enforces platform constraints.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by PocketExpensePolicy in the controller
        // This ensures proper policy-based access control
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $pocketExpense = $this->route('pocket_expense');
        $clientId = $pocketExpense ? $pocketExpense->client_id : null;

        return [
            // Core expense fields - all optional for updates (partial updates allowed)
            'date' => [
                'sometimes',
                'required',
                'date_format:d/m/Y',
                'before_or_equal:today',
                function ($attribute, $value, $fail) {
                    $date = Carbon::createFromFormat('d/m/Y', $value);
                    $threeYearsAgo = Carbon::now()->subYears(3);
                    if ($date->lt($threeYearsAgo)) {
                        $fail('The date must not be older than 3 years.');
                    }
                },
            ],
            'merchant_name' => [
                'sometimes',
                'required',
                'string',
                'max:180', // VARCHAR(180) as per DB constraint
                'regex:/^[a-zA-Z0-9\s\-\.\,\&\(\)\'\"]+$/', // Prevent SQL injection
            ],
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
                'regex:/^[a-zA-Z0-9\s\-\.\,\&\(\)\'\"\n\r]+$/', // Prevent SQL injection
            ],
            'expense_type' => [
                'sometimes',
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id,is_active,1',
            ],
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/', // 3-letter ISO currency code
                // Note: Currency validation against platform list would need additional validation
                // This would require access to the currency reference data
            ],
            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'between:-999999999999.99,999999999999.99', // DECIMAL(14,2) constraints
                'not_in:0', // Amount cannot be zero
            ],
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'regex:/^[a-zA-Z0-9\s\-\.\,\&\(\)\'\"\n\r]+$/', // Prevent SQL injection
            ],
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:0,100', // VAT percentage between 0-100
                'regex:/^\d{1,2}(\.\d{1,2})?$/', // Max 2 decimal places
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000', // Reasonable limit for TEXT field
                'regex:/^[a-zA-Z0-9\s\-\.\,\&\(\)\'\"\n\r\!\?\@\#\$\%\*\+\=\[\]\_\{\}\|\\\/\:\;]+$/', // Prevent SQL injection
            ],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected']),
            ],

            // Metadata fields - optional for updates
            'source' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && $clientId) {
                        // Validate source exists for client (including global 'Other')
                        $sourceExists = \DB::table('pocket_expense_source_client_config')
                            ->where(function ($query) use ($clientId) {
                                $query->where('client_id', $clientId)
                                      ->orWhereNull('client_id'); // Global sources like 'Other'
                            })
                            ->where('name', $value)
                            ->where('deleted', false)
                            ->exists();

                        if (!$sourceExists) {
                            $fail('The selected source is not available for this client.');
                        }
                    }
                },
            ],
            'source_note' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'regex:/^[a-zA-Z0-9\s\-\.\,\&\(\)\'\"\n\r\!\?\@\#\$\%\*\+\=\[\]\_\{\}\|\\\/\:\;]+$/', // Prevent SQL injection
                // Required when source = 'Other' - validated in withValidator
            ],

            // Additional metadata fields for comprehensive expense data
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:transaction_categories,id', // Assuming this table exists
            ],
            'tracking_code_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:tracking_codes,id', // Assuming this table exists
            ],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:projects,id', // Assuming this table exists
            ],
            'additional_field_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:additional_fields,id', // Assuming this table exists
            ],

            // File attachments
            'receipt' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
                'max:10240', // 10MB max file size
            ],

            // System fields - not directly updatable by users
            'user_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id,deleted,0',
            ],
            'client_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:clients,id,deleted,0',
            ],
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
            // Validate source note required when source = 'Other'
            if ($this->has('source') && $this->input('source') === 'Other') {
                if (!$this->has('source_note') || empty(trim($this->input('source_note')))) {
                    $validator->errors()->add('source_note', 'Source note is required when source is Other.');
                }
            }

            // Validate user belongs to client (if both are being updated)
            if ($this->has('user_id') && $this->has('client_id')) {
                $userBelongsToClient = \DB::table('client_users')
                    ->where('user_id', $this->input('user_id'))
                    ->where('client_id', $this->input('client_id'))
                    ->exists();

                if (!$userBelongsToClient) {
                    $validator->errors()->add('user_id', 'The selected user does not belong to the specified client.');
                }
            }

            // Validate expense type determines amount sign
            if ($this->has('expense_type') && $this->has('amount')) {
                $expenseType = \DB::table('opt_pocket_expense_type')
                    ->find($this->input('expense_type'));

                if ($expenseType) {
                    $amount = (float) $this->input('amount');
                    
                    if ($expenseType->amount_sign === 'positive' && $amount < 0) {
                        $validator->errors()->add('amount', 'Amount must be positive for this expense type.');
                    } elseif ($expenseType->amount_sign === 'negative' && $amount > 0) {
                        $validator->errors()->add('amount', 'Amount must be negative for this expense type.');
                    }
                }
            }

            // Validate currency is supported by platform
            if ($this->has('currency')) {
                $supportedCurrencies = $this->getSupportedCurrencies();
                if (!in_array($this->input('currency'), $supportedCurrencies)) {
                    $validator->errors()->add('currency', 'The selected currency is not supported.');
                }
            }

            // Validate client has OOP feature enabled
            if ($this->has('client_id')) {
                $hasOopFeature = \DB::table('client_features')
                    ->where('client_id', $this->input('client_id'))
                    ->where('feature_id', 16) // OOP Expenses feature ID
                    ->where('is_enabled', true)
                    ->exists();

                if (!$hasOopFeature) {
                    $validator->errors()->add('client_id', 'OOP Expenses feature is not enabled for this client.');
                }
            }

            // Validate date format and convert for processing
            if ($this->has('date')) {
                try {
                    $date = Carbon::createFromFormat('d/m/Y', $this->input('date'));
                    // Store the parsed date for use in the controller
                    $this->merge(['parsed_date' => $date->format('Y-m-d')]);
                } catch (\Exception $e) {
                    $validator->errors()->add('date', 'Invalid date format. Use DD/MM/YYYY.');
                }
            }

            // Validate VAT amount format (strip % if present)
            if ($this->has('vat_amount') && !is_null($this->input('vat_amount'))) {
                $vatAmount = $this->input('vat_amount');
                
                // Strip % sign if present
                if (is_string($vatAmount) && str_ends_with($vatAmount, '%')) {
                    $vatAmount = rtrim($vatAmount, '%');
                    $this->merge(['vat_amount' => (float) $vatAmount]);
                }
            }

            // Validate status transitions (business rule enforcement)
            $pocketExpense = $this->route('pocket_expense');
            if ($this->has('status') && $pocketExpense) {
                $currentStatus = $pocketExpense->status;
                $newStatus = $this->input('status');

                $allowedTransitions = [
                    'draft' => ['submitted', 'rejected'],
                    'submitted' => ['approved', 'rejected', 'draft'],
                    'approved' => [], // Approved expenses cannot be changed
                    'rejected' => ['draft', 'submitted'],
                ];

                if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [])) {
                    $validator->errors()->add('status', "Cannot change status from {$currentStatus} to {$newStatus}.");
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format' => 'The date must be in DD/MM/YYYY format.',
            'date.before_or_equal' => 'The date cannot be in the future.',
            'merchant_name.max' => 'The merchant name may not be greater than 180 characters.',
            'merchant_name.regex' => 'The merchant name contains invalid characters.',
            'expense_type.exists' => 'The selected expense type is invalid or inactive.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be a valid 3-letter ISO code.',
            'amount.between' => 'The amount must be between -999,999,999,999.99 and 999,999,999,999.99.',
            'amount.not_in' => 'The amount cannot be zero.',
            'vat_amount.between' => 'The VAT percentage must be between 0 and 100.',
            'notes.regex' => 'The notes contain invalid characters.',
            'source_note.regex' => 'The source note contains invalid characters.',
            'receipt.mimes' => 'The receipt must be a file of type: pdf, jpg, jpeg, png, doc, docx, xls, xlsx.',
            'receipt.max' => 'The receipt may not be greater than 10MB.',
            'user_id.exists' => 'The selected user is invalid or deleted.',
            'client_id.exists' => 'The selected client is invalid or deleted.',
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
            'vat_amount' => 'VAT percentage',
            'source_note' => 'source note',
            'category_id' => 'category',
            'tracking_code_id' => 'tracking code',
            'project_id' => 'project',
            'additional_field_id' => 'additional field',
            'user_id' => 'user',
            'client_id' => 'client',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Trim string inputs to prevent leading/trailing whitespace issues
        $this->merge([
            'merchant_name' => $this->merchant_name ? trim($this->merchant_name) : $this->merchant_name,
            'merchant_description' => $this->merchant_description ? trim($this->merchant_description) : $this->merchant_description,
            'merchant_address' => $this->merchant_address ? trim($this->merchant_address) : $this->merchant_address,
            'notes' => $this->notes ? trim($this->notes) : $this->notes,
            'source_note' => $this->source_note ? trim($this->source_note) : $this->source_note,
            'currency' => $this->currency ? strtoupper(trim($this->currency)) : $this->currency,
        ]);

        // Handle VAT amount - strip % sign if present
        if ($this->has('vat_amount') && is_string($this->vat_amount)) {
            $vatAmount = trim($this->vat_amount);
            if (str_ends_with($vatAmount, '%')) {
                $vatAmount = rtrim($vatAmount, '%');
            }
            $this->merge(['vat_amount' => $vatAmount !== '' ? (float) $vatAmount : null]);
        }

        // Ensure numeric fields are properly typed
        if ($this->has('amount') && is_string($this->amount)) {
            $this->merge(['amount' => (float) $this->amount]);
        }

        if ($this->has('user_id') && is_string($this->user_id)) {
            $this->merge(['user_id' => (int) $this->user_id]);
        }

        if ($this->has('client_id') && is_string($this->client_id)) {
            $this->merge(['client_id' => (int) $this->client_id]);
        }

        if ($this->has('expense_type') && is_string($this->expense_type)) {
            $this->merge(['expense_type' => (int) $this->expense_type]);
        }
    }

    /**
     * Get the validated data from the request.
     * 
     * @param array|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // Add computed fields that were set during validation
        if ($this->has('parsed_date')) {
            $validated['date'] = $this->input('parsed_date');
        }

        return $validated;
    }

    /**
     * Get supported currencies from platform configuration.
     * 
     * @return array<string>
     */
    private function getSupportedCurrencies(): array
    {
        // This would typically fetch from a configuration table or cache
        // For now, return common currencies as per platform constraints
        return [
            'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'HKD', 'SGD',
            'NOK', 'SEK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'HRK',
            'RUB', 'TRY', 'ZAR', 'BRL', 'MXN', 'INR', 'KRW', 'THB', 'MYR',
            'IDR', 'PHP', 'VND', 'NZD', 'ILS', 'AED', 'SAR', 'QAR', 'KWD',
            'BHD', 'OMR', 'JOD', 'LBP', 'EGP', 'MAD', 'TND', 'DZD', 'LYD'
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     * 
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation($validator): void
    {
        // Log validation failures for debugging in development
        if (config('app.debug')) {
            \Log::info('PocketExpense update validation failed', [
                'errors' => $validator->errors()->toArray(),
                'input' => $this->except(['receipt']), // Exclude file from logs
            ]);
        }

        parent::failedValidation($validator);
    }

    /**
     * Get the expense ID from the route parameter.
     * 
     * @return int|null
     */
    public function getExpenseId(): ?int
    {
        $expense = $this->route('pocket_expense');
        return $expense ? $expense->id : null;
    }

    /**
     * Check if the request is updating the expense status.
     * 
     * @return bool
     */
    public function isStatusUpdate(): bool
    {
        return $this->has('status');
    }

    /**
     * Check if the request includes financial data changes.
     * 
     * @return bool
     */
    public function hasFinancialChanges(): bool
    {
        return $this->hasAny(['amount', 'currency', 'vat_amount']);
    }

    /**
     * Check if the request includes metadata changes.
     * 
     * @return bool
     */
    public function hasMetadataChanges(): bool
    {
        return $this->hasAny(['source', 'source_note', 'category_id', 'tracking_code_id', 'project_id', 'additional_field_id']);
    }

    /**
     * Get only the fields that are being updated.
     * 
     * @return array
     */
    public function getUpdateFields(): array
    {
        $validated = $this->validated();
        $expense = $this->route('pocket_expense');
        
        if (!$expense) {
            return $validated;
        }

        $updateFields = [];
        
        // Only include fields that are actually being changed
        foreach ($validated as $field => $value) {
            if ($expense->$field !== $value) {
                $updateFields[$field] = $value;
            }
        }

        return $updateFields;
    }

    /**
     * Determine if the request requires FX conversion.
     * 
     * @return bool
     */
    public function requiresFxConversion(): bool
    {
        return $this->hasAny(['amount', 'currency', 'date']) && $this->has('currency');
    }

    /**
     * Get the data formatted for FX conversion service.
     * 
     * @return array
     */
    public function getFxConversionData(): array
    {
        $expense = $this->route('pocket_expense');
        
        return [
            'currency' => $this->input('currency', $expense->currency ?? 'USD'),
            'amount' => $this->input('amount', $expense->amount ?? 0),
            'date' => $this->input('parsed_date', $expense->date ?? now()->format('Y-m-d')),
            'client_id' => $this->input('client_id', $expense->client_id ?? null),
        ];
    }
}