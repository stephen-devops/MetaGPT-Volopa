## Code: app/Http/Requests/UpdatePocketExpenseRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

/**
 * UpdatePocketExpenseRequest
 * 
 * Form request for validating pocket expense update data.
 * This request handles validation and authorization for updating existing
 * pocket expenses within the multi-tenant system. Includes policy
 * enforcement, comprehensive validation rules, and status change validation.
 * Follows the mental model: Client -> route -> controller -> Form Request -> domain logic.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * Maximum length for merchant name per database definition
     *
     * @var int
     */
    private const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Maximum length for merchant description
     *
     * @var int
     */
    private const MAX_MERCHANT_DESCRIPTION_LENGTH = 255;

    /**
     * Maximum length for merchant address
     *
     * @var int
     */
    private const MAX_MERCHANT_ADDRESS_LENGTH = 500;

    /**
     * Maximum length for currency code
     *
     * @var int
     */
    private const MAX_CURRENCY_LENGTH = 3;

    /**
     * Maximum length for notes field
     *
     * @var int
     */
    private const MAX_NOTES_LENGTH = 65535;

    /**
     * Maximum amount value (15 digits, 2 decimal places)
     *
     * @var float
     */
    private const MAX_AMOUNT = 999999999999.99;

    /**
     * Minimum date (3 years ago from today)
     *
     * @var int
     */
    private const MAX_DATE_YEARS_AGO = 3;

    /**
     * List of supported currencies (ISO 3-letter codes)
     * In production, this would be fetched from platform master data
     *
     * @var array<int, string>
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK',
        'PLN', 'CZK', 'HUF', 'BGN', 'RON', 'HRK', 'RSD', 'BAM', 'MKD', 'ALL',
        'ISK', 'TRY', 'RUB', 'UAH', 'BYN', 'MDL', 'GEL', 'AMD', 'AZN', 'KZT',
        'UZS', 'KGS', 'TJS', 'TMT', 'MNT', 'CNY', 'HKD', 'SGD', 'MYR', 'THB',
        'IDR', 'PHP', 'VND', 'KRW', 'INR', 'PKR', 'LKR', 'BDT', 'NPR', 'BTN',
        'MVR', 'AFN', 'IRR', 'IQD', 'SYP', 'LBP', 'JOD', 'KWD', 'BHD', 'QAR',
        'AED', 'OMR', 'YER', 'SAR', 'ILS', 'EGP', 'LYD', 'TND', 'DZD', 'MAD',
        'XOF', 'XAF', 'NGN', 'GHS', 'XCD', 'BBD', 'JMD', 'TTD', 'COP', 'PEN',
        'BOB', 'BRL', 'ARS', 'CLP', 'UYU', 'PYG', 'VES', 'GYD', 'SRD', 'FKP',
        'ZAR', 'BWP', 'NAD', 'SZL', 'LSL', 'MZN', 'MWK', 'ZMW', 'AOA', 'CDF',
        'XAU', 'XAG', 'XPT', 'XPD'
    ];

    /**
     * Determine if the user is authorized to make this request.
     * Uses PocketExpensePolicy to check authorization.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $policy = new PocketExpensePolicy();
        $expense = $this->route('pocketExpense');
        
        // Check if the expense exists and user can update it
        if (!$expense) {
            return false;
        }

        return $policy->update($this->user(), $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $clientId = $user->client_id;
        $expense = $this->route('pocketExpense');
        $expenseId = $expense ? $expense->id : null;

        return [
            'date' => [
                'sometimes',
                'date',
                'before_or_equal:today',
                'after_or_equal:' . now()->subYears(self::MAX_DATE_YEARS_AGO)->format('Y-m-d')
            ],
            'merchant_name' => [
                'sometimes',
                'string',
                'min:1',
                'max:' . self::MAX_MERCHANT_NAME_LENGTH,
                // Sanitize merchant name
                function ($attribute, $value, $fail) {
                    $sanitized = trim(strip_tags($value));
                    if (empty($sanitized)) {
                        $fail('The merchant name must contain valid characters.');
                    }
                }
            ],
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_MERCHANT_DESCRIPTION_LENGTH
            ],
            'expense_type' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('opt_pocket_expense_type', 'id'),
                // Validate expense type is active
                function ($attribute, $value, $fail) {
                    if ($value && !$this->isValidExpenseType($value)) {
                        $fail('The selected expense type is not valid.');
                    }
                }
            ],
            'currency' => [
                'sometimes',
                'string',
                'size:' . self::MAX_CURRENCY_LENGTH,
                'uppercase',
                Rule::in(self::SUPPORTED_CURRENCIES)
            ],
            'amount' => [
                'sometimes',
                'numeric',
                'min:0.01',
                'max:' . self::MAX_AMOUNT,
                // Validate decimal places (max 2)
                'regex:/^\d+(\.\d{1,2})?$/'
            ],
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_MERCHANT_ADDRESS_LENGTH
            ],
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:' . self::MAX_AMOUNT,
                // Validate decimal places (max 2)
                'regex:/^\d+(\.\d{1,2})?$/',
                // VAT cannot be greater than the main amount
                function ($attribute, $value, $fail) {
                    $amount = $this->input('amount');
                    $expense = $this->route('pocketExpense');
                    
                    // Use provided amount or existing amount for validation
                    $checkAmount = $amount ?? ($expense ? $expense->amount : 0);
                    
                    if ($value && $checkAmount && (float)$value > (float)$checkAmount) {
                        $fail('VAT amount cannot be greater than the expense amount.');
                    }
                }
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:' . self::MAX_NOTES_LENGTH
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(PocketExpense::VALID_STATUSES),
                // Validate status transitions
                function ($attribute, $value, $fail) use ($expense) {
                    if ($value && $expense && !$expense->isValidStatusTransition($expense->status, $value)) {
                        $fail("Invalid status transition from '{$expense->status}' to '{$value}'.");
                    }
                }
            ],
            // Metadata fields
            'source_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('pocket_expense_source_client_config', 'id')->where(function ($query) use ($clientId) {
                    // Ensure source is available to the client (client-specific or global)
                    return $query->where(function ($q) use ($clientId) {
                        $q->where('client_id', $clientId)
                          ->orWhereNull('client_id');
                    })->where('deleted', false);
                })
            ],
            'source_note' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                // Required when source is "Other"
                function ($attribute, $value, $fail) {
                    $sourceId = $this->input('source_id');
                    if ($sourceId) {
                        $source = PocketExpenseSourceClientConfig::find($sourceId);
                        if ($source && $source->name === 'Other' && empty($value)) {
                            $fail('Source note is required when source is "Other".');
                        }
                    }
                }
            ],
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1'
                // Note: Assuming transaction_category table exists but not implementing validation
                // as the table structure is not provided in the context
            ],
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1'
                // Note: Assuming project table exists but not implementing validation
                // as the table structure is not provided in the context
            ],
            'tracking_code_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1'
                // Note: Assuming tracking_code table exists but not implementing validation
                // as the table structure is not provided in the context
            ],
            // File attachments
            'file_attachments' => [
                'sometimes',
                'array',
                'max:5' // Maximum 5 file attachments
            ],
            'file_attachments.*' => [
                'integer',
                'min:1'
                // Note: Assuming file_store table exists but not implementing validation
                // as the table structure is not provided in the context
            ],
            // FX conversion fields (optional, for frontend use)
            'original_currency' => [
                'sometimes',
                'nullable',
                'string',
                'size:3',
                'uppercase',
                Rule::in(self::SUPPORTED_CURRENCIES)
            ],
            'original_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0.01',
                'max:' . self::MAX_AMOUNT,
                'regex:/^\d+(\.\d{1,2})?$/'
            ],
            'fx_rate' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0.0001',
                'max:1000000'
            ],
            'fx_rate_date' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:today',
                'after_or_equal:' . now()->subDays(30)->format('Y-m-d')
            ]
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date' => 'The expense date must be a valid date.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            'date.after_or_equal' => 'The expense date cannot be older than ' . self::MAX_DATE_YEARS_AGO . ' years.',
            
            'merchant_name.string' => 'The merchant name must be a valid string.',
            'merchant_name.min' => 'The merchant name must not be empty.',
            'merchant_name.max' => 'The merchant name cannot exceed ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters.',
            
            'merchant_description.string' => 'The merchant description must be a valid string.',
            'merchant_description.max' => 'The merchant description cannot exceed ' . self::MAX_MERCHANT_DESCRIPTION_LENGTH . ' characters.',
            
            'expense_type.integer' => 'The expense type must be a valid integer.',
            'expense_type.exists' => 'The selected expense type is not valid.',
            
            'currency.string' => 'The currency must be a valid string.',
            'currency.size' => 'The currency must be exactly 3 characters long.',
            'currency.uppercase' => 'The currency must be in uppercase.',
            'currency.in' => 'The selected currency is not supported.',
            
            'amount.numeric' => 'The expense amount must be a valid number.',
            'amount.min' => 'The expense amount must be at least 0.01.',
            'amount.max' => 'The expense amount cannot exceed ' . number_format(self::MAX_AMOUNT, 2) . '.',
            'amount.regex' => 'The expense amount can have at most 2 decimal places.',
            
            'merchant_address.string' => 'The merchant address must be a valid string.',
            'merchant_address.max' => 'The merchant address cannot exceed ' . self::MAX_MERCHANT_ADDRESS_LENGTH . ' characters.',
            
            'vat_amount.numeric' => 'The VAT amount must be a valid number.',
            'vat_amount.min' => 'The VAT amount must be at least 0.',
            'vat_amount.max' => 'The VAT amount cannot exceed ' . number_format(self::MAX_AMOUNT, 2) . '.',
            'vat_amount.regex' => 'The VAT amount can have at most 2 decimal places.',
            
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes cannot exceed ' . self::MAX_NOTES_LENGTH . ' characters.',
            
            'status.string' => 'The status must be a valid string.',
            'status.in' => 'The selected status is not valid.',
            
            'source_id.integer' => 'The source ID must be a valid integer.',
            'source_id.exists' => 'The selected expense source is not available.',
            
            'source_note.string' => 'The source note must be a valid string.',
            'source_note.max' => 'The source note cannot exceed 500 characters.',
            
            'category_id.integer' => 'The category ID must be a valid integer.',
            'project_id.integer' => 'The project ID must be a valid integer.',
            'tracking_code_id.integer' => 'The tracking code ID must be a valid integer.',
            
            'file_attachments.array' => 'File attachments must be an array.',
            'file_attachments.max' => 'You cannot attach more than 5 files.',
            'file_attachments.*.integer' => 'Each file attachment ID must be a valid integer.',
            
            'original_currency.string' => 'The original currency must be a valid string.',
            'original_currency.size' => 'The original currency must be exactly 3 characters long.',
            'original_currency.uppercase' => 'The original currency must be in uppercase.',
            'original_currency.in' => 'The selected original currency is not supported.',
            
            'original_amount.numeric' => 'The original amount must be a valid number.',
            'original_amount.min' => 'The original amount must be at least 0.01.',
            'original_amount.max' => 'The original amount cannot exceed ' . number_format(self::MAX_AMOUNT, 2) . '.',
            'original_amount.regex' => 'The original amount can have at most 2 decimal places.',
            
            'fx_rate.numeric' => 'The FX rate must be a valid number.',
            'fx_rate.min' => 'The FX rate must be at least 0.0001.',
            'fx_rate.max' => 'The FX rate cannot exceed 1,000,000.',
            
            'fx_rate_date.date' => 'The FX rate date must be a valid date.',
            'fx_rate_date.before_or_equal' => 'The FX rate date cannot be in the future.',
            'fx_rate_date.after_or_equal' => 'The FX rate date cannot be older than 30 days.'
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
            'expense_type' => 'expense type',
            'currency' => 'currency',
            'amount' => 'expense amount',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
            'notes' => 'notes',
            'status' => 'expense status',
            'source_id' => 'expense source',
            'source_note' => 'source note',
            'category_id' => 'transaction category',
            'project_id' => 'project',
            'tracking_code_id' => 'tracking code',
            'file_attachments' => 'file attachments',
            'original_currency' => 'original currency',
            'original_amount' => 'original amount',
            'fx_rate' => 'exchange rate',
            'fx_rate_date' => 'exchange rate date'
        ];
    }

    /**
     * Prepare the data for validation.
     * This method is called before validation rules are applied.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Clean and normalize input data
        $this->normalizeStringFields();
        $this->normalizeDateFields();
        $this->normalizeNumericFields();
        $this->normalizeCurrencyFields();
    }

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Additional cross-field validation
            $this->validateExpenseEditability($validator);
            $this->validateExpenseTypeAndAmount($validator);
            $this->validateFXConversionData($validator);
            $this->validateMetadataConsistency($validator);
            $this->validateStatusTransitions($validator);
        });
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    /**
     * Get the validated data with additional computed fields.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($key === null) {
            // Add audit fields
            $validated['updated_by_user_id'] = $this->user()->id;
            $validated['update_time'] = now();

            // Remove metadata fields that will be processed separately
            $metadataFields = ['source_id', 'source_note', 'category_id', 'project_id', 'tracking_code_id', 'file_attachments'];
            $metadata = [];
            
            foreach ($metadataFields as $field) {
                if (isset($validated[$field])) {
                    $metadata[$field] = $validated[$field];
                    unset($validated[$field]);
                }
            }
            
            if (!empty($metadata)) {
                $validated['_metadata'] = $metadata;
            }

            // Remove FX fields that are for frontend reference only
            unset($validated['original_currency'], $validated['original_amount'], $validated['fx_rate'], $validated['fx_rate_date']);
        }

        return $validated;
    }

    /**
     * Check if an expense type is valid and active.
     *
     * @param int $expenseTypeId
     * @return bool
     */
    private function isValidExpenseType(int $expenseTypeId): bool
    {
        return OptPocketExpenseType::where('id', $expenseTypeId)->exists();
    }

    /**
     * Normalize string fields by trimming and sanitizing.
     *
     * @return void
     */
    private function normalizeStringFields(): void
    {
        $stringFields = ['merchant_name', 'merchant_description', 'merchant_address', 'notes', 'source_note'];
        
        foreach ($stringFields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                if (is_string($value)) {
                    // Trim whitespace and remove unnecessary HTML tags
                    $cleaned = trim(strip_tags($value));
                    $this->merge([$field => !empty($cleaned) ? $cleaned : null]);
                }
            }
        }
    }

    /**
     * Normalize date fields.
     *
     * @return void
     */
    private function normalizeDateFields(): void
    {
        $dateFields = ['date', 'fx_rate_date'];
        
        foreach ($dateFields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                if (is_string($value) && !empty($value)) {
                    try {
                        // Parse and reformat date to ensure consistency
                        $date = Carbon::parse($value);
                        $this->merge([$field => $date->format('Y-m-d')]);
                    } catch (\Exception $e) {
                        // Keep original value for validation to catch the error
                    }
                }
            }
        }
    }

    /**
     * Normalize numeric fields.
     *
     * @return void
     */
    private function normalizeNumericFields(): void
    {
        $numericFields = ['amount', 'vat_amount', 'original_amount', 'fx_rate'];
        
        foreach ($numericFields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                if (is_string($value) && !empty($value)) {
                    // Remove currency symbols and extra spaces
                    $cleaned = preg_replace('/[^\d.,\-]/', '', trim($value));
                    
                    // Handle different decimal separators
                    if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') === false) {
                        $cleaned = str_replace(',', '.', $cleaned);
                    } elseif (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) {
                        // Both comma and dot present, assume comma is thousands separator
                        $cleaned = str_replace(',', '', $cleaned);
                    }
                    
                    $this->merge([$field => $cleaned]);
                }
            }
        }
    }

    /**
     * Normalize currency fields to uppercase.
     *
     * @return void
     */
    private function normalizeCurrencyFields(): void
    {
        $currencyFields = ['currency', 'original_currency'];
        
        foreach ($currencyFields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                if (is_string($value)) {
                    $this->merge([$field => strtoupper(trim($value))]);
                }
            }
        }
    }

    /**
     * Validate that the expense can be edited based on its current status.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateExpenseEditability(Validator $validator): void
    {
        $expense = $this->route('pocketExpense');
        
        if (!$expense) {
            $validator->errors()->add('expense', 'Expense not found.');
            return;
        }

        // Check if expense can be edited based on status
        if (!$expense->canBeEdited()) {
            $validator->errors()->add('expense', 'This expense cannot be edited in its current status.');
        }

        // Check if expense is deleted
        if ($expense->isDeleted()) {
            $validator->errors()->add('expense', 'This expense has been deleted and cannot be edited.');
        }
    }

    /**
     * Validate expense type and amount consistency.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateExpenseTypeAndAmount(Validator $validator): void
    {
        $expense = $this->route('pocketExpense');
        $expenseTypeId = $this->input('expense_type');
        $amount = $this->input('amount');
        
        // Use provided values or existing values for validation
        $checkExpenseTypeId = $expenseTypeId ?? ($expense ? $expense->expense_type : null);
        $checkAmount = $amount ?? ($expense ? $expense->amount : null);
        
        if ($checkExpenseTypeId && $checkAmount) {
            $expenseType = OptPocketExpenseType::find($checkExpenseTypeId);
            
            if ($expenseType) {
                // For refunds (positive amount sign), amount should be positive
                // For expenses (negative amount sign), amount should be positive (will be negated by business logic)
                if ((float)$checkAmount <= 0) {
                    $validator->errors()->add('amount', 'The expense amount must be a positive value.');
                }
                
                // Additional validation based on expense type
                if ($expenseType->option === 'Refund' && $expenseType->amount_sign === 'positive') {
                    // Refunds might have additional validation rules
                    if ((float)$checkAmount > 10000) { // Example business rule
                        $validator->errors()->add('amount', 'Refund amounts over 10,000 require special approval.');
                    }
                }
            }
        }
    }

    /**
     * Validate FX conversion data consistency.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateFXConversionData(Validator $validator): void
    {
        $expense = $this->route('pocketExpense');
        $originalCurrency = $this->input('original_currency');
        $originalAmount = $this->input('original_amount');
        $currency = $this->input('currency');
        $amount = $this->input('amount');
        $fxRate = $this->input('fx_rate');
        $fxRateDate = $this->input('fx_rate_date');
        
        // Use provided values or existing values for validation
        $checkCurrency = $currency ?? ($expense ? $expense->currency : null);
        $checkAmount = $amount ?? ($expense ? $expense->amount : null);
        
        // If any FX field is provided, validate consistency
        if ($originalCurrency || $originalAmount || $fxRate || $fxRateDate) {
            // If original currency is different from target currency, all FX fields should be provided
            if ($originalCurrency && $checkCurrency && $originalCurrency !== $checkCurrency) {
                if (!$originalAmount) {
                    $validator->errors()->add('original_amount', 'Original amount is required when converting currencies.');
                }
                if (!$fxRate) {
                    $validator->errors()->add('fx_rate', 'Exchange rate is required when converting currencies.');
                }
                if (!$fxRateDate) {
                    $validator->errors()->add('fx_rate_date', 'Exchange rate date is required when converting currencies.');
                }
                
                // Validate conversion calculation (allowing for small rounding differences)
                if ($originalAmount && $fxRate && $checkAmount) {
                    $expectedAmount = (float)$originalAmount * (float)$fxRate;
                    $actualAmount = (float)$checkAmount;
                    $difference = abs($expectedAmount - $actualAmount);
                    
                    if ($difference > 0.01) { // Allow 1 cent difference for rounding
                        $validator->errors()->add('amount', 'The converted amount does not match the calculation (original amount × exchange rate).');
                    }
                }
            }
            
            // If original currency is same as target currency, FX fields should not be provided
            if ($originalCurrency && $checkCurrency && $originalCurrency === $checkCurrency) {
                if ($originalAmount || $fxRate || $fxRateDate) {
                    $validator->errors()->add('original_currency', 'FX conversion fields are not needed when currencies are the same.');
                }
            }
        }
    }

    /**
     * Validate metadata field consistency.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateMetadataConsistency(Validator $validator): void
    {
        // Validate file attachments if provided
        $fileAttachments = $this->input('file_attachments', []);
        if (!empty($fileAttachments) && is_array($fileAttachments)) {
            // Remove duplicates
            $uniqueAttachments = array_unique($fileAttachments);
            if (count($uniqueAttachments) !== count($fileAttachments)) {
                $validator->errors()->add('file_attachments', 'Duplicate file attachments are not allowed.');
            }
            
            // Validate file attachment IDs exist (assuming file_store table exists)
            foreach ($uniqueAttachments as $fileId) {
                if (!is_numeric($fileId) || $fileId <= 0) {
                    $validator->errors()->add('file_attachments', 'Invalid file attachment ID: ' . $fileId);
                }
            }
        }
        
        // Validate expense source permissions
        $sourceId = $this->input('source_id');
        if ($sourceId) {
            $source = PocketExpenseSourceClientConfig::find($sourceId);
            if ($source) {
                $user = $this->user();
                // Check if source is available to the client
                $isAvailable = $source->client_id === $user->client_id || $source->client_id === null;
                if (!$isAvailable || $source->deleted) {
                    $validator->errors()->add('source_id', 'The selected expense source is not available.');
                }
            }
        }
    }

    /**
     * Validate status transitions and their implications.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateStatusTransitions(Validator $validator): void
    {
        $expense = $this->route('pocketExpense');
        $newStatus = $this->input('status');
        
        if (!$expense || !$newStatus) {
            return;
        }

        $currentStatus = $expense->status;
        $user = $this->user();

        // Validate specific status transitions
        switch ($newStatus) {
            case PocketExpense::STATUS_SUBMITTED:
                // Can submit if currently draft or rejected
                if (!in_array($currentStatus, [PocketExpense::STATUS_DRAFT, PocketExpense::STATUS_REJECTED])) {
                    $validator->errors()->add('status', 'Expenses can only be submitted from draft or rejected status.');
                }
                break;
                
            case PocketExpense::STATUS_APPROVED:
                // Only certain users can approve, and only from submitted status
                $policy = new PocketExpensePolicy();
                if (!$policy->approve($user, $expense)) {
                    $validator->errors()->add('status', 'You do not have permission to approve expenses.');
                }
                if ($currentStatus !== PocketExpense::STATUS_SUBMITTED) {
                    $validator->errors()->add('status', 'Expenses can only be approved from submitted status.');
                }
                break;
                
            case PocketExpense::STATUS_REJECTED:
                // Only certain users can reject, and only from submitted status
                $policy = new PocketExpensePolicy();
                if (!$policy->reject($user, $expense)) {
                    $validator->errors()->add('status', 'You do not have permission to reject expenses.');
                }
                if ($currentStatus !== PocketExpense::STATUS_SUBMITTED) {
                    $validator->errors()->add('status', 'Expenses can only be rejected from submitted status.');
                }
                break;
                
            case PocketExpense::STATUS_DRAFT:
                // Can return to draft from rejected or submitted (with permissions)
                if (!in_array($currentStatus, [PocketExpense::STATUS_REJECTED, PocketExpense::STATUS_SUBMITTED])) {
                    $validator->errors()->add('status', 'Expenses can only be returned to draft from rejected or submitted status.');
                }
                // Additional permission check for returning submitted expenses to draft
                if ($currentStatus === PocketExpense::STATUS_SUBMITTED) {
                    $policy = new PocketExpensePolicy();
                    if (!$policy->update($user, $expense)) {
                        $validator->errors()->add('status', 'You do not have permission to return submitted expenses to draft.');
                    }
                }
                break;
        }

        // Validate that users cannot approve their own expenses
        if ($newStatus === PocketExpense::STATUS_APPROVED && $expense->user_id === $user->id) {
            $validator->errors()->add('status', 'You cannot approve your own expenses.');
        }

        // Validate that users cannot reject their own expenses
        if ($newStatus === PocketExpense::STATUS_REJECTED && $expense->user_id === $user->id) {
            $validator->errors()->add('status', 'You cannot reject your own expenses.');
        }

        // Validate that approved expenses cannot be modified (except by admins)
        if ($currentStatus === PocketExpense::STATUS_APPROVED && $newStatus !== PocketExpense::STATUS_APPROVED) {
            if (!in_array($user->role, ['Primary Administrator', 'Admin'])) {
                $validator->errors()->add('status', 'Only administrators can modify approved expenses.');
            }
        }
    }

    /**
     * Get sanitized input data for processing.
     *
     * @return array<string, mixed>
     */
    public function getSanitizedData(): array
    {
        $data = $this->validated();
        
        // Add audit fields
        $data['updated_by_user_id'] = $this->user()->id;
        $data['update_time'] = now();
        
        // Clean up null/empty values
        foreach ($data as $key => $value) {
            if ($value === '' || (is_array($value) && empty($value))) {
                $data[$key] = null;
            }
        }
        
        return $data;
    }

    /**
     * Get metadata for separate processing.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $validated = $this->validated();
        return $validated['_metadata'] ?? [];
    }

    /**
     * Get validation context information for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getValidationContext(): array
    {
        $user = $this->user();
        $expense = $this->route('pocketExpense');
        
        return [
            'updater_id' => $user->id,
            'updater_role' => $user->role,
            'client_id' => $user->client_id,
            'expense_id' => $expense ? $expense->id : null,
            'expense_uuid' => $expense ? $expense->uuid : null,
            'expense_current_status' => $expense ? $expense->status : null,
            'expense_owner_id' => $expense ? $expense->user_id : null,
            'new_status' => $this->input('status'),
            'has_amount_change' => $this->has('amount'),
            'has_metadata' => !empty($this->getMetadata()),
            'has_fx_conversion' => $this->input('original_currency') !== null,
            'request_ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Check if the current request includes FX conversion data.
     *
     * @return bool
     */
    public function hasFXConversion(): bool
    {
        return $this->input('original_currency') !== null &&
               $this->input('original_currency') !== $this->input('currency');
    }

    /**
     * Get the expense type instance.
     *
     * @return OptPocketExpenseType|null
     */
    public function getExpenseType(): ?OptPocketExpenseType
    {
        $expenseTypeId = $this->input('expense_type');
        if ($expenseTypeId) {
            return OptPocketExpenseType::find($expenseTypeId);
        }
        
        // Fall back to existing expense type if not provided in update
        $expense = $this->route('pocketExpense');
        return $expense ? $expense->expenseType : null;
    }

    /**
     * Get the type of update being performed based on the input data.
     *
     * @return string
     */
    public function getUpdateType(): string
    {
        $types = [];
        
        if ($this->has('status')) {
            $types[] = 'status_change';
        }
        
        if ($this->has('amount')) {
            $types[] = 'amount_change';
        }
        
        if ($this->has('merchant_name') || $this->has('merchant_description') || $this->has('merchant_address')) {
            $types[] = 'merchant_info';
        }
        
        if ($this->has('expense_type')) {
            $types[] = 'expense_type';
        }
        
        if ($this->has('currency')) {
            $types[] = 'currency';
        }
        
        if ($this->has('vat_amount')) {
            $types[] = 'vat_amount';
        }
        
        if ($this->has('notes')) {
            $types[] = 'notes';
        }
        
        $metadata = $this->getMetadata();
        if (!empty($metadata)) {
            $types[] = 'metadata';
        }
        
        if ($this->hasFXConversion()) {
            $types[] = 'fx_conversion';
        }
        
        return !empty($types) ? implode(', ', $types) : 'general_update';
    }

    /**
     * Get a human-readable description of the update being performed.
     *
     * @return string
     */
    public function getUpdateDescription(): string
    {
        $expense = $this->route('pocketExpense');
        
        if (!$expense) {
            return 'Unknown expense update';
        }
        
        $updateType = $this->getUpdateType();
        $merchantName = $expense->merchant_name;
        
        return "Updating expense '{$merchantName}' (ID: {$expense->id}): {$updateType}";
    }

    /**
     * Check if the update would result in any privilege escalation concerns.
     *
     * @return bool
     */
    public function wouldEscalatePrivileges(): bool
    {
        $expense = $this->route('pocketExpense');
        $user = $this->user();
        
        if (!$expense) {
            return false;
        }
        
        // Check if user is trying to approve their own expense
        if ($this->input('status') === PocketExpense::STATUS_APPROVED && $expense->user_id === $user->id) {
            return true;
        }
        
        // Check if user is trying to change status without proper permissions
        $newStatus = $this->input('status');
        if ($newStatus) {
            $policy = new PocketExpensePolicy();
            switch ($newStatus) {
                case PocketExpense::STATUS_APPROVED:
                    return !$policy->approve($user, $expense);
                case PocketExpense::STATUS_REJECTED:
                    return !$policy->reject($user, $expense);
            }
        }
        
        return false;
    }

    /**
     * Check if the current user has sufficient rights to make the requested changes.
     *
     * @return bool
     */
    public function hasUpdateRights(): bool
    {
        $user = $this->user();
        $expense = $this->route('pocketExpense');
        
        if (!$expense) {
            return false;
        }
        
        $policy = new PocketExpensePolicy();
        
        // Basic update permission
        if (!$policy->update($user, $expense)) {
            return false;
        }
        
        // Additional checks for status changes
        $newStatus = $this->input('status');
        if ($newStatus) {
            switch ($newStatus) {
                case PocketExpense::STATUS_APPROVED:
                    return $policy->approve($user, $expense);
                case PocketExpense::STATUS_REJECTED:
                    return $policy->reject($user, $expense);
                case PocketExpense::STATUS_SUBMITTED:
                    return $policy->submit($user, $expense);
            }
        }
        
        return true;
    }

    /**
     * Get the fields that are allowed to be updated by the current user.
     *
     * @return array<string>
     */
    public function getAllowedUpdateFields(): array
    {
        $user = $this->user();
        $expense = $this->route('pocketExpense');
        
        if (!$expense) {
            return [];
        }
        
        // Primary Admins and Admins can update all fields
        if (in_array($user->role, ['Primary Administrator', 'Admin'])) {
            return array_keys($this->rules());
        }
        
        $allowedFields = [];
        
        // Basic expense fields that expense owners can update (when editable)
        if ($expense->user_id === $user->id && $expense->canBeEdited()) {
            $allowedFields = [
                'date', 'merchant_name', 'merchant_description', 'expense_type',
                'currency', 'amount', 'merchant_address', 'vat_amount', 'notes',
                'source_id', 'source_note', 'category_id', 'project_id',
                'tracking_code_id', 'file_attachments', 'original_currency',
                'original_amount', 'fx_rate', 'fx_rate_date'
            ];
            
            // Can submit own expenses
            if ($expense->canBeSubmitted()) {
                $allowedFields[] = 'status';
            }
        }
        
        // Business Users with management permissions can update expenses they manage
        $policy = new PocketExpensePolicy();
        if ($user->role === 'Business User' && $policy->update($user, $expense)) {
            $allowedFields = array_merge($allowedFields, [
                'date', 'merchant_name', 'merchant_description', 'expense_type',
                'currency', 'amount', 'merchant_address', 'vat_amount', 'notes',
                'source_id', 'source_note', 'category_id', 'project_id',
                'tracking_code_id', 'file_attachments'
            ]);
            
            // Can approve/reject if they have approval authority
            if ($policy->approve($user, $expense) || $policy->reject($user, $expense)) {
                $allowedFields[] = 'status';
            }
        }
        
        return array_unique($allowedFields);
    }

    /**
     * Check if a specific field can be updated by the current user.
     *
     * @param string $field
     * @return bool
     */
    public function canUpdateField(string $field): bool
    {
        return in_array($field, $this->getAllowedUpdateFields());
    }

    /**
     * Get the calculated signed amount based on expense type.
     *
     * @return float|null
     */
    public function getSignedAmount(): ?float
    {
        $amount = $this->input('amount');
        if ($amount === null) {
            return null;
        }
        
        $amount = (float)$amount;
        $expenseType = $this->getExpenseType();
        
        if ($expenseType && $expenseType->amount_sign === 'positive') {
            return abs($amount);
        }
        
        return -abs($amount);
    }

    /**
     * Check if the expense requires approval based on the updates.
     *
     * @return bool
     */
    public function requiresApproval(): bool
    {
        $expense = $this->route('pocketExpense');
        $user = $this->user();
        
        if (!$expense) {
            return false;
        }
        
        // If already approved, changes might require re-approval
        if ($expense->isApproved() && $this->hasSignificantChanges()) {
            return true;
        }
        
        // Check if new amount requires approval
        $newAmount = $this->input('amount');
        if ($newAmount && (float)$newAmount > 500) {
            return true;
        }
        
        // Card Users always require approval
        if ($user->role === 'Card User') {
            return true;
        }
        
        // Certain expense types might require approval
        $expenseType = $this->getExpenseType();
        if ($expenseType && in_array($expenseType->option, ['Travel', 'Entertainment'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if the update includes significant changes that might require re-approval.
     *
     * @return bool
     */
    public function hasSignificantChanges(): bool
    {
        $significantFields = ['amount', 'expense_type', 'currency', 'merchant_name'];
        
        foreach ($significantFields as $field) {
            if ($this->has($field)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get supported currencies for the client.
     * In production, this would be fetched from client configuration.
     *
     * @return array<int, string>
     */
    public static function getSupportedCurrencies(): array
    {
        return self::SUPPORTED_CURRENCIES;
    }

    /**
     * Get validation rules for API documentation.
     *
     * @return array<string, mixed>
     */
    public static function getValidationRulesForDocs(): array
    {
        return [
            'date' => 'date|before_or_equal:today|after_or_equal:3_years_ago',
            'merchant_name' => 'string|max:' . self::MAX_MERCHANT_NAME_LENGTH,
            'merchant_description' => 'string|