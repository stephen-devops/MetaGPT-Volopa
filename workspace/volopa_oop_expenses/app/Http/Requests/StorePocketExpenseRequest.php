## Code: app/Http/Requests/StorePocketExpenseRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * StorePocketExpenseRequest
 * 
 * Form request validation for creating new pocket expenses.
 * Handles validation rules, authorization checks, and business logic validation
 * for expense creation with multi-tenant security and FX validation.
 * 
 * Validation Rules:
 * - date: Required, valid date, not older than 3 years from current date
 * - merchant_name: Required, max 180 characters per database VARCHAR definition
 * - merchant_description: Optional string
 * - expense_type: Required, must exist in opt_pocket_expense_type table
 * - currency: Required, 3-letter ISO format validated against platform list
 * - amount: Required, positive decimal with 4 decimal precision, minimum 0.01
 * - merchant_address: Optional text
 * - vat_amount: Optional positive decimal with 4 decimal precision
 * - notes: Optional text, trimmed and SQL injection prevention
 * - All data must be scoped to authenticated user's client_id
 */
class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    private const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Maximum age in years for expense date validation.
     *
     * @var int
     */
    private const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Minimum amount value for expenses.
     *
     * @var float
     */
    private const MIN_AMOUNT = 0.01;

    /**
     * Maximum amount precision (decimal places).
     *
     * @var int
     */
    private const AMOUNT_PRECISION = 4;

    /**
     * Valid 3-letter ISO currency codes (subset of platform supported currencies).
     *
     * @var array<int, string>
     */
    private const VALID_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
        'MXN', 'SGD', 'HKD', 'NOK', 'KRW', 'TRY', 'RUB', 'INR', 'BRL', 'ZAR',
        'PLN', 'DKK', 'CZK', 'HUF', 'ILS', 'AED', 'SAR', 'THB', 'MYR', 'PHP'
    ];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user || !$user->client_id) {
            return false;
        }

        // Use policy to check if user can create expenses
        $policy = new PocketExpensePolicy();
        
        // Check if creating for specific user (from expense_user_id if provided)
        $expenseUserId = $this->input('expense_user_id', $user->id);
        
        return $policy->createFor($user, $expenseUserId, $user->client_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = Auth::user();
        $clientId = $user ? $user->client_id : null;
        $oldestAllowedDate = Carbon::now()->subYears(self::MAX_EXPENSE_AGE_YEARS)->format('Y-m-d');

        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                'after_or_equal:' . $oldestAllowedDate,
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:' . self::MERCHANT_NAME_MAX_LENGTH,
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+$/', // Prevent control characters
            ],
            'merchant_description' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', // Prevent control characters
            ],
            'expense_type' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('opt_pocket_expense_type', 'id'),
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::in(self::VALID_CURRENCIES),
            ],
            'amount' => [
                'required',
                'numeric',
                'min:' . self::MIN_AMOUNT,
                'max:999999999999.9999', // DECIMAL(15,4) max value
                'regex:/^\d{1,11}(\.\d{1,' . self::AMOUNT_PRECISION . '})?$/',
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', // Prevent control characters
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999999999.9999', // DECIMAL(15,4) max value
                'regex:/^\d{1,11}(\.\d{1,' . self::AMOUNT_PRECISION . '})?$/',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', // Prevent control characters
            ],
            'expense_user_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $query->where('client_id', $clientId);
                    }
                    return $query;
                }),
            ],
            // Metadata fields (optional)
            'category_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('transaction_categories', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $query->where('client_id', $clientId);
                    }
                    return $query;
                }),
            ],
            'tracking_code_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('tracking_codes', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $query->where('client_id', $clientId);
                    }
                    return $query;
                }),
            ],
            'project_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('projects', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $