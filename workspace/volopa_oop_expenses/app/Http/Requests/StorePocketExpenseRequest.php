<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Policies\PocketExpensePolicy;
use App\Models\OptPocketExpenseType;
use Carbon\Carbon;

/**
 * StorePocketExpenseRequest Form Request
 * 
 * Handles validation and authorization for creating new pocket expenses.
 * Implements comprehensive validation rules for expense data including
 * currency validation, amount constraints, date validation, and reference
 * data validation with policy-based authorization.
 */
class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Maximum allowed expense age in years.
     *
     * @var int
     */
    private const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Maximum merchant name length.
     *
     * @var int
     */
    private const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Maximum merchant description length.
     *
     * @var int
     */
    private const MAX_MERCHANT_DESCRIPTION_LENGTH = 255;

    /**
     * Maximum merchant address length.
     *
     * @var int
     */
    private const MAX_MERCHANT_ADDRESS_LENGTH = 500;

    /**
     * Maximum notes length.
     *
     * @var int
     */
    private const MAX_NOTES_LENGTH = 65535; // TEXT field limit

    /**
     * Maximum amount value.
     *
     * @var float
     */
    private const MAX_AMOUNT = 9999999999999.99; // Based on DECIMAL(15,2)

    /**
     * Supported currency codes (3-letter ISO format).
     * In production, this should be fetched from platform master data.
     *
     * @var array<string>
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR', 'KRW',
        'PLN', 'CZK', 'HUF', 'ILS', 'CLP', 'PHP', 'AED', 'SAR', 'THB', 'MYR'
    ];

    /**
     * Valid expense status values for creation.
     * Only draft status is allowed for new expenses.
     *
     * @var array<string>
     */
    private const ALLOWED_CREATION_STATUSES = ['draft'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Use the PocketExpensePolicy to determine if user can create expenses
        $policy = new PocketExpensePolicy();
        
        return $policy->create($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $clientId = $user?->client_id ?? null;

        return [
            // Required core fields
            'date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . Carbon::now()->subYears(self::MAX_EXPENSE_AGE_YEARS)->format('Y-m-d')
            ],
            'merchant_name' => [
                'required',
                'string',
                'min:1',
                'max:' . self::MAX_MERCHANT_NAME_LENGTH,
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', // Prevent control characters
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::in(self::SUPPORTED_CURRENCIES),
            ],
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:' . self::MAX_AMOUNT,
                'decimal:0,2',
            ],

            // Optional fields with validation
            'merchant_description' => [
                'nullable',
                'string',
                'max:' . self::MAX_MERCHANT_DESCRIPTION_LENGTH,
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:' . self::MAX_MERCHANT_ADDRESS_LENGTH,
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:' . self::MAX_AMOUNT,
                'decimal:0,2',
                'lt:amount', // VAT amount must be less than total amount
            ],
            'notes' => [
                'nullable',
                'string',
                'max:' . self::MAX_NOTES_LENGTH,
            ],

            // Reference fields
            'expense_type' => [
                'nullable',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ],

            // Status field - only draft allowed for creation
            'status' => [
                'nullable',
                'string',
                Rule::in(self::ALLOWED_CREATION_STATUSES),
            ],

            // Target user validation (for admin/manager creating on behalf of others)
            'target_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && $clientId) {
                        // Ensure target user belongs to the same client
                        $targetUser = \App\Models\User::find($value);
                        if (!$targetUser || $targetUser->client_id !== $clientId) {
                            $fail('The selected target user must belong to the same client.');
                        }
                    }
                },
            ],

            // Metadata fields (for expense categorization)
            'metadata' => [
                'nullable',
                'array',
                'max:10', // Reasonable limit on metadata entries
            ],
            'metadata.*.type' => [
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
                ]),
            ],
            'metadata.*.value' => [
                'required_with:metadata',
                'integer',
                'min:1',
            ],
            'metadata.*.details' => [
                'nullable',
                'array',
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
            'date.required' => 'The expense date is required.',
            'date.date' => 'The expense date must be a valid date.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            'date.after_or_equal' => 'The expense date cannot be older than ' . self::MAX_EXPENSE_AGE_YEARS . ' years.',
            
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.string' => 'The merchant name must be a text string.',
            'merchant_name.min' => 'The merchant name cannot be empty.',
            'merchant_name.max' => 'The merchant name cannot exceed ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters.',
            'merchant_name.regex' => 'The merchant name contains invalid characters.',
            
            'currency.required' => 'The currency is required.',
            'currency.string' => 'The currency must be a text string.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be a 3-letter uppercase code.',
            'currency.in' => 'The selected currency is not supported.',
            
            'amount.required' => 'The expense amount is required.',
            'amount.numeric' => 'The expense amount must be a valid number.',
            'amount.min' => 'The expense amount must be at least 0.01.',
            'amount.max' => 'The expense amount is too large.',
            'amount.decimal' => 'The expense amount can have at most 2 decimal places.',
            
            'merchant_description.max' => 'The merchant description cannot exceed ' . self::MAX_MERCHANT_DESCRIPTION_LENGTH . ' characters.',
            'merchant_address.max' => 'The merchant address cannot exceed ' . self::MAX_MERCHANT_ADDRESS_LENGTH . ' characters.',
            
            'vat_amount.numeric' => 'The VAT amount must be a valid number.',
            'vat_amount.min' => 'The VAT amount must be zero or positive.',
            'vat_amount.max' => 'The VAT amount is too large.',
            'vat_amount.decimal' => 'The VAT amount can have at most 2 decimal places.',
            'vat_amount.lt' => 'The VAT amount must be less than the total expense amount.',
            
            'notes.max' => 'The notes field is too long.',
            
            'expense_type.integer' => 'The expense type must be a valid identifier.',
            'expense_type.exists' => 'The selected expense type is not valid.',
            
            'status.in' => 'Only draft status is allowed when creating a new expense.',
            
            'target_user_id.integer' => 'The target user ID must be a valid identifier.',
            'target_user_id.exists' => 'The selected target user does not exist.',
            
            'metadata.array' => 'The metadata must be a valid array.',
            'metadata.max' => 'Too many metadata entries provided.',
            'metadata.*.type.required_with' => 'The metadata type is required when metadata is provided.',
            'metadata.*.type.in' => 'The metadata type is not valid.',
            'metadata.*.value.required_with' => 'The metadata value is required when metadata is provided.',
            'metadata.*.value.integer' => 'The metadata value must be a valid identifier.',
            'metadata.*.value.min' => 'The metadata value must be a positive integer.',
            'metadata.*.details.array' => 'The metadata details must be a valid array.',
        ];
    }

    /**
     * Get custom attributes for validation error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => 'expense date',
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'currency' => 'currency',
            'amount' => 'amount',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
            'notes' => 'notes',
            'expense_type' => 'expense type',
            'status' => 'status',
            'target_user_id' => 'target user',
            'metadata' => 'metadata',
        ];
    }

    /**
     * Prepare the data for validation.
     * Clean and standardize input data before validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        // Trim string fields
        if (isset($input['merchant_name'])) {
            $input['merchant_name'] = trim($input['merchant_name']);
        }

        if (isset($input['merchant_description'])) {
            $input['merchant_description'] = trim($input['merchant_description']);
        }

        if (isset($input['merchant_address'])) {
            $input['merchant_address'] = trim($input['merchant_address']);
        }

        if (isset($input['notes'])) {
            $input['notes'] = trim($input['notes']);
            // Convert empty string to null
            if (empty($input['notes'])) {
                $input['notes'] = null;
            }
        }

        // Standardize currency to uppercase
        if (isset($input['currency'])) {
            $input['currency'] = strtoupper(trim($input['currency']));
        }

        // Convert numeric strings to proper numeric values
        if (isset($input['amount'])) {
            $input['amount'] = is_numeric($input['amount']) ? (float) $input['amount'] : $input['amount'];
        }

        if (isset($input['vat_amount'])) {
            $input['vat_amount'] = is_numeric($input['vat_amount']) ? (float) $input['vat_amount'] : $input['vat_amount'];
        }

        // Set default status if not provided
        if (!isset($input['status']) || empty($input['status'])) {
            $input['status'] = 'draft';
        }

        // Convert date format if needed (handle DD/MM/YYYY from CSV uploads)
        if (isset($input['date']) && is_string($input['date'])) {
            $date = $input['date'];
            
            // Check if date is in DD/MM/YYYY format
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                $year = $matches[3];
                $input['date'] = "$year-$month-$day";
            }
        }

        // Clean metadata array
        if (isset($input['metadata']) && is_array($input['metadata'])) {
            $input['metadata'] = array_filter($input['metadata'], function ($metadata) {
                return isset($metadata['type']) && isset($metadata['value']);
            });
        }

        $this->replace($input);
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            // Custom validation logic that requires multiple fields

            // Validate VAT amount doesn't exceed total amount
            if ($this->filled(['amount', 'vat_amount'])) {
                $amount = (float) $this->input('amount');
                $vatAmount = (float) $this->input('vat_amount');
                
                if ($vatAmount >= $amount) {
                    $validator->errors()->add('vat_amount', 'The VAT amount must be less than the total expense amount.');
                }
            }

            // Validate expense type exists and is active
            if ($this->filled('expense_type')) {
                $expenseType = OptPocketExpenseType::find($this->input('expense_type'));
                if (!$expenseType) {
                    $validator->errors()->add('expense_type', 'The selected expense type is not valid.');
                }
            }

            // Validate that user belongs to the authenticated user's client
            $user = $this->user();
            if ($user && $this->filled('target_user_id')) {
                $targetUserId = (int) $this->input('target_user_id');
                $targetUser = \App\Models\User::find($targetUserId);
                
                if ($targetUser && $targetUser->client_id !== $user->client_id) {
                    $validator->errors()->add('target_user_id', 'The target user must belong to your client organization.');
                }
            }

            // Validate metadata references exist (basic validation - detailed validation in service layer)
            if ($this->filled('metadata') && is_array($this->input('metadata'))) {
                foreach ($this->input('metadata') as $index => $metadata) {
                    if (!isset($metadata['type']) || !isset($metadata['value'])) {
                        continue;
                    }

                    $type = $metadata['type'];
                    $value = (int) $metadata['value'];

                    // Validate reference exists based on type
                    switch ($type) {
                        case 'category':
                            if (!\DB::table('transaction_categories')->where('id', $value)->exists()) {
                                $validator->errors()->add("metadata.{$index}.value", 'The selected category does not exist.');
                            }
                            break;
                            
                        case 'tracking_code_type_1':
                        case 'tracking_code_type_2':
                            if (!\DB::table('tracking_codes')->where('id', $value)->exists()) {
                                $validator->errors()->add("metadata.{$index}.value", 'The selected tracking code does not exist.');
                            }
                            break;
                            
                        case 'project':
                            if (!\DB::table('configurable_projects')->where('id', $value)->exists()) {
                                $validator->errors()->add("metadata.{$index}.value", 'The selected project does not exist.');
                            }
                            break;
                            
                        case 'expense_source':
                            if (!\DB::table('pocket_expense_source_client_config')
                                    ->where('id', $value)
                                    ->where('deleted', false)
                                    ->exists()) {
                                $validator->errors()->add("metadata.{$index}.value", 'The selected expense source does not exist.');
                            }
                            break;
                    }
                }
            }
        });
    }

    /**
     * Get the validated data with proper type casting and defaults.
     *
     * @param string|null $key
     * @param mixed $default
     * @return array|mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated();

        // Ensure proper type casting
        if (isset($validated['amount'])) {
            $validated['amount'] = (float) $validated['amount'];
        }

        if (isset($validated['vat_amount'])) {
            $validated['vat_amount'] = (float) $validated['vat_amount'];
        }

        if (isset($validated['expense_type'])) {
            $validated['expense_type'] = (int) $validated['expense_type'];
        }

        if (isset($validated['target_user_id'])) {
            $validated['target_user_id'] = (int) $validated['target_user_id'];
        }

        // Set defaults for optional fields
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['merchant_description'] = $validated['merchant_description'] ?? null;
        $validated['merchant_address'] = $validated['merchant_address'] ?? null;
        $validated['vat_amount'] = $validated['vat_amount'] ?? null;
        $validated['notes'] = $validated['notes'] ?? null;
        $validated['expense_type'] = $validated['expense_type'] ?? null;
        $validated['metadata'] = $validated['metadata'] ?? [];

        return $key ? data_get($validated, $key, $default) : $validated;
    }

    /**
     * Get the user ID that should own this expense.
     * This considers the target_user_id for admin/manager scenarios.
     *
     * @return int
     */
    public function getTargetUserId(): int
    {
        $targetUserId = $this->input('target_user_id');
        
        // If target_user_id is specified and user has permission, use it
        if ($targetUserId && $this->user()) {
            // Additional authorization check should be done in the controller/service
            return (int) $targetUserId;
        }

        // Default to authenticated user
        return $this->user()->id;
    }

    /**
     * Get the client ID for this expense.
     *
     * @return int
     */
    public function getClientId(): int
    {
        return $this->user()->client_id;
    }

    /**
     * Check if this is an admin/manager creating expense for another user.
     *
     * @return bool
     */
    public function isCreatingForAnotherUser(): bool
    {
        $targetUserId = $this->input('target_user_id');
        return $targetUserId && $targetUserId !== $this->user()->id;
    }

    /**
     * Get supported currencies for validation and frontend usage.
     *
     * @return array<string>
     */
    public static function getSupportedCurrencies(): array
    {
        return self::SUPPORTED_CURRENCIES;
    }

    /**
     * Get allowed creation statuses.
     *
     * @return array<string>
     */
    public static function getAllowedCreationStatuses(): array
    {
        return self::ALLOWED_CREATION_STATUSES;
    }

    /**
     * Get the maximum expense age in years.
     *
     * @return int
     */
    public static function getMaxExpenseAgeYears(): int
    {
        return self::MAX_EXPENSE_AGE_YEARS;
    }
}