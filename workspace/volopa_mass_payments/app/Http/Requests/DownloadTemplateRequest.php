## Code: app/Http/Requests/DownloadTemplateRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Policies\MassPaymentFilePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class DownloadTemplateRequest extends FormRequest
{
    /**
     * Supported currencies for template download.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
        'SEK', 'NZD', 'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'ZAR',
        'INR', 'BRL', 'PLN', 'CZK', 'HUF', 'ILS', 'KRW', 'TWD',
        'THB', 'MYR', 'PHP', 'IDR', 'VND', 'AED', 'SAR', 'EGP',
    ];

    /**
     * Valid template formats.
     */
    private const VALID_TEMPLATE_FORMATS = ['csv', 'xlsx', 'json'];

    /**
     * Valid template types.
     */
    private const VALID_TEMPLATE_TYPES = ['basic', 'detailed', 'with_beneficiaries'];

    /**
     * Maximum number of sample rows to include.
     */
    private const MAX_SAMPLE_ROWS = 100;

    /**
     * Currencies that require invoice details.
     */
    private const INVOICE_REQUIRED_CURRENCIES = ['INR'];

    /**
     * Currencies that require incorporation number for business recipients.
     */
    private const INCORPORATION_REQUIRED_CURRENCIES = ['TRY'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $policy = new MassPaymentFilePolicy();
        $user = Auth::user();
        
        // Check basic template download permission
        if (!$policy->downloadTemplate($user)) {
            return false;
        }

        // Check currency-specific permissions
        $currency = $this->input('currency');
        if ($currency && !$policy->processCurrency($user, $currency)) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(self::SUPPORTED_CURRENCIES),
                function ($attribute, $value, $fail) {
                    $this->validateCurrencyPermissions($value, $fail);
                },
            ],
            'format' => [
                'nullable',
                'string',
                Rule::in(self::VALID_TEMPLATE_FORMATS),
            ],
            'template_type' => [
                'nullable',
                'string',
                Rule::in(self::VALID_TEMPLATE_TYPES),
            ],
            'include_sample_data' => [
                'nullable',
                'boolean',
            ],
            'sample_rows_count' => [
                'nullable',
                'integer',
                'min:1',
                'max:' . self::MAX_SAMPLE_ROWS,
                'required_if:include_sample_data,true',
            ],
            'include_beneficiaries' => [
                'nullable',
                'boolean',
                function ($attribute, $value, $fail) {
                    $this->validateBeneficiaryAccess($value, $fail);
                },
            ],
            'beneficiary_filters' => [
                'nullable',
                'array',
                'required_if:include_beneficiaries,true',
            ],
            'beneficiary_filters.country' => [
                'nullable',
                'string',
                'size:2',
            ],
            'beneficiary_filters.type' => [
                'nullable',
                'string',
                Rule::in(['individual', 'business', 'all']),
            ],
            'beneficiary_filters.status' => [
                'nullable',
                'string',
                Rule::in(['active', 'verified', 'all']),
            ],
            'beneficiary_filters.limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:1000',
            ],
            'include_headers' => [
                'nullable',
                'boolean',
            ],
            'include_instructions' => [
                'nullable',
                'boolean',
            ],
            'include_validation_rules' => [
                'nullable',
                'boolean',
            ],
            'locale' => [
                'nullable',
                'string',
                Rule::in(['en', 'es', 'fr', 'de', 'pt', 'zh', 'ja', 'ko']),
            ],
            'custom_fields' => [
                'nullable',
                'array',
            ],
            'custom_fields.*' => [
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_-]+$/',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'currency.required' => 'Please specify the currency for the template.',
            'currency.string' => 'Currency must be a valid string.',
            'currency.size' => 'Currency must be exactly 3 characters (e.g., USD, EUR).',
            'currency.in' => 'The selected currency is not supported for mass payment templates.',

            'format.string' => 'Template format must be a valid string.',
            'format.in' => 'Template format must be one of: ' . implode(', ', self::VALID_TEMPLATE_FORMATS),

            'template_type.string' => 'Template type must be a valid string.',
            'template_type.in' => 'Template type must be one of: ' . implode(', ', self::VALID_TEMPLATE_TYPES),

            'include_sample_data.boolean' => 'Include sample data setting must be true or false.',

            'sample_rows_count.integer' => 'Sample rows count must be a valid number.',
            'sample_rows_count.min' => 'Sample rows count must be at least 1.',
            'sample_rows_count.max' => 'Sample rows count cannot exceed ' . self::MAX_SAMPLE_ROWS . '.',
            'sample_rows_count.required_if' => 'Sample rows count is required when including sample data.',

            'include_beneficiaries.boolean' => 'Include beneficiaries setting must be true or false.',

            'beneficiary_filters.array' => 'Beneficiary filters must be a valid array.',
            'beneficiary_filters.required_if' => 'Beneficiary filters are required when including beneficiaries.',
            'beneficiary_filters.country.string' => 'Country filter must be a valid string.',
            'beneficiary_filters.country.size' => 'Country filter must be exactly 2 characters (ISO country code).',
            'beneficiary_filters.type.string' => 'Beneficiary type filter must be a valid string.',
            'beneficiary_filters.type.in' => 'Beneficiary type filter must be: individual, business, or all.',
            'beneficiary_filters.status.string' => 'Status filter must be a valid string.',
            'beneficiary_filters.status.in' => 'Status filter must be: active, verified, or all.',
            'beneficiary_filters.limit.integer' => 'Beneficiary limit must be a valid number.',
            'beneficiary_filters.limit.min' => 'Beneficiary limit must be at least 1.',
            'beneficiary_filters.limit.max' => 'Beneficiary limit cannot exceed 1000.',

            'include_headers.boolean' => 'Include headers setting must be true or false.',
            'include_instructions.boolean' => 'Include instructions setting must be true or false.',
            'include_validation_rules.boolean' => 'Include validation rules setting must be true or false.',

            'locale.string' => 'Locale must be a valid string.',
            'locale.in' => 'Locale must be one of: en, es, fr, de, pt, zh, ja, ko.',

            'custom_fields.array' => 'Custom fields must be a valid array.',
            'custom_fields.*.string' => 'Each custom field must be a valid string.',
            'custom_fields.*.max' => 'Each custom field name cannot exceed 50 characters.',
            'custom_fields.*.regex' => 'Custom field names can only contain letters, numbers, hyphens, and underscores.',
        ];
    }

    /**
     * Get custom attribute names for validation messages.
     */
    public function attributes(): array
    {
        return [
            'currency' => 'currency',
            'format' => 'template format',
            'template_type' => 'template type',
            'include_sample_data' => 'sample data inclusion',
            'sample_rows_count' => 'number of sample rows',
            'include_beneficiaries' => 'beneficiary inclusion',
            'beneficiary_filters' => 'beneficiary filters',
            'beneficiary_filters.country' => 'country filter',
            'beneficiary_filters.type' => 'beneficiary type filter',
            'beneficiary_filters.status' => 'status filter',
            'beneficiary_filters.limit' => 'beneficiary limit',
            'include_headers' => 'header inclusion',
            'include_instructions' => 'instruction inclusion',
            'include_validation_rules' => 'validation rules inclusion',
            'locale' => 'language locale',
            'custom_fields' => 'custom fields',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency')),
            ]);
        }

        // Set default values
        $defaults = [
            'format' => 'csv',
            'template_type' => 'basic',
            'include_sample_data' => false,
            'include_beneficiaries' => false,
            'include_headers' => true,
            'include_instructions' => true,
            'include_validation_rules' => false,
            'locale' => 'en',
        ];

        foreach ($defaults as $key => $value) {
            if (!$this->has($key)) {
                $this->merge([$key => $value]);
            }
        }

        // Set default sample rows count
        if ($this->input('include_sample_data', false) && !$this->has('sample_rows_count')) {
            $this->merge(['sample_rows_count' => 5]);
        }

        // Set default beneficiary filters
        if ($this->input('include_beneficiaries', false) && !$this->has('beneficiary_filters')) {
            $this->merge([
                'beneficiary_filters' => [
                    'type' => 'all',
                    'status' => 'active',
                    'limit' => 100,
                ],
            ]);
        }

        // Normalize country code to uppercase
        if ($this->has('beneficiary_filters.country')) {
            $filters = $this->input('beneficiary_filters', []);
            $filters['country'] = strtoupper($filters['country']);
            $this->merge(['beneficiary_filters' => $filters]);
        }

        // Normalize locale to lowercase
        if ($this->has('locale')) {
            $this->merge(['locale' => strtolower($this->input('locale'))]);
        }
    }

    /**
     * Validate currency-specific permissions.
     */
    private function validateCurrencyPermissions(string $currency, callable $fail): void
    {
        $user = Auth::user();
        $policy = new MassPaymentFilePolicy();

        if (!$policy->processCurrency($user, $currency)) {
            $fail("You do not have permission to download templates for {$currency} payments.");
        }
    }

    /**
     * Validate beneficiary access permissions.
     */
    private function validateBeneficiaryAccess(?bool $includeBeneficiaries, callable $fail): void
    {
        if (!$includeBeneficiaries) {
            return;
        }

        $user = Auth::user();

        // Check if user has permission to access beneficiary data
        if (method_exists($user, 'hasPermission')) {
            if (!$user->hasPermission('beneficiaries.view')) {
                $fail('You do not have permission to include beneficiary data in templates.');
                return;
            }
        }

        // Check user roles for beneficiary access
        if (method_exists($user, 'hasRole')) {
            $allowedRoles = [
                'mass_payment_manager',
                'mass_payment_admin',
                'beneficiary_manager',
                'admin',
                'super_admin'
            ];

            $hasAccess = false;
            foreach ($allowedRoles as $role) {
                if ($user->hasRole($role)) {
                    $hasAccess = true;
                    break;
                }
            }

            if (!$hasAccess) {
                $fail('Your role does not allow access to beneficiary data.');
            }
        }
    }

    /**
     * Get the validated data with processed values.
     */
    public function validated(): array
    {
        $validated = parent::validated();
        
        // Ensure currency is uppercase
        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        // Process currency-specific requirements
        $validated['currency_requirements'] = $this->getCurrencyRequirements($validated['currency']);

        // Add template metadata
        $validated['template_metadata'] = [
            'generated_at' => now()->toISOString(),
            'user_id' => Auth::id(),
            'client_id' => Auth::user()->client_id ?? null,
            'version' => '1.0',
        ];

        return $validated;
    }

    /**
     * Get currency-specific requirements.
     */
    private function getCurrencyRequirements(string $currency): array
    {
        $requirements = [
            'requires_invoice_details' => in_array($currency, self::INVOICE_REQUIRED_CURRENCIES),
            'requires_incorporation_number' => in_array($currency, self::INCORPORATION_REQUIRED_CURRENCIES),
            'special_fields' => [],
        ];

        // Add currency-specific special fields
        if (in_array($currency, self::INVOICE_REQUIRED_CURRENCIES)) {
            $requirements['special_fields'] = array_merge($requirements['special_fields'], [
                'invoice_number',
                'invoice_date',
            ]);
        }

        if (in_array($currency, self::INCORPORATION_REQUIRED_CURRENCIES)) {
            $requirements['special_fields'][] = 'incorporation_number';
        }

        // Add regulatory requirements
        $requirements['regulatory_notes'] = $this->getRegulatoryNotes($currency);

        return $requirements;
    }

    /**
     * Get regulatory notes for currency.
     */
    private function getRegulatoryNotes(string $currency): array
    {
        return match ($currency) {
            'INR' => [
                'Invoice number and date are mandatory for all payments',
                'Purpose code must comply with RBI regulations',
                'Maximum single payment limit may apply',
            ],
            'TRY' => [
                'Incorporation number required for business recipients',
                'Additional documentation may be required for high-value transfers',
                'Turkish banking regulations apply',
            ],
            'CNY' => [
                'Subject to Chinese foreign exchange controls',
                'Additional documentation required for business payments',
                'Daily and annual limits may apply',
            ],
            'USD', 'EUR', 'GBP' => [
                'Standard international payment