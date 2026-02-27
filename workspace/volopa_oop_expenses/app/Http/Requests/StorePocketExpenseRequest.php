## Code: app/Http/Requests/StorePocketExpenseRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StorePocketExpenseRequest extends FormRequest
{
    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * Maximum date lookback in years for expense dates.
     *
     * @var int
     */
    private const MAX_DATE_LOOKBACK_YEARS = 3;

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    private const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Valid currency codes (ISO 3-letter format).
     *
     * @var array<string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'INR', 'KRW', 'THB', 'BRL', 'ZAR', 'RUB'
    ];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Use policy to check if user can create pocket expenses
        $policy = new PocketExpensePolicy();
        
        if (!$policy->create($user)) {
            return false;
        }

        // Additional authorization checks for client access
        if ($this->has('client_id')) {
            if (!$this->validateUserClientAccess($user, $this->input('client_id'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minDate = Carbon::now()->subYears(self::MAX_DATE_LOOKBACK_YEARS)->format('Y-m-d');
        $maxDate = Carbon::now()->format('Y-m-d');

        return [
            'client_id' => [
                'required',
                'integer',
                'min:1',
                'exists:clients,id',
            ],
            'date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                "after_or_equal:{$minDate}",
                "before_or_equal:{$maxDate}",
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:' . self::MERCHANT_NAME_MAX_LENGTH,
                'regex:/^[a-zA-Z0-9\s\.\-\_\&\(\)\,\!]+$/',
            ],
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            'expense_type' => [
                'required',
                'integer',
                'min:1',
                'exists:opt_pocket_expense_type,id',
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'alpha',
                Rule::in(self::VALID_CURRENCIES),
            ],
            'amount' => [
                'required',
                'numeric',
                'between:0.01,999999.99',
                'regex:/^\d{1,6}(\.\d{1,2})?$/',
            ],
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:0,999999.99',
                'regex:/^\d{1,6}(\.\d{1,2})?$/',
            ],
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(PocketExpense::VALID_STATUSES),
            ],
            // Metadata fields
            'metadata' => [
                'sometimes',
                'array',
            ],
            'metadata.transaction_category_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:transaction_categories,id',
            ],
            'metadata.tracking_code_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:tracking_codes,id',
            ],
            'metadata.project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:configurable_projects,id',
            ],
            'metadata.source_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) {
                    if (!$this->validateExpenseSource($value, $this->input('client_id'))) {
                        $fail('The selected expense source is not available for this client.');
                    }
                },
            ],
            'metadata.additional_field_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:expense_additional_fields,id',
            ],
            'metadata.source_note' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'required_if:metadata.source_id,' . $this->getOtherSourceId(),
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The selected client does not exist.',
            
            'date.required' => 'The expense date is required.',
            'date.date' => 'The expense date must be a valid date.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'date.after_or_equal' => 'The expense date cannot be older than 3 years.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.string' => 'The merchant name must be text.',
            'merchant_name.max' => 'The merchant name cannot exceed 180 characters.',
            'merchant_name.regex' => 'The merchant name contains invalid characters.',
            
            'merchant_description.string' => 'The merchant description must be text.',
            'merchant_description.max' => 'The merchant description cannot exceed 1000 characters.',
            
            'expense_type.required' => 'The expense type is required.',
            'expense_type.integer' => 'The expense type must be an integer.',
            'expense_type.min' => 'The expense type must be at least 1