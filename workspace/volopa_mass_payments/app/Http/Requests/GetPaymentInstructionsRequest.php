## Code: app/Http/Requests/GetPaymentInstructionsRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class GetPaymentInstructionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // User must be authenticated
        if (!$this->user()) {
            return false;
        }

        // If file_id is provided, check view permission for that specific file
        $fileId = $this->get('file_id') ?? $this->route('file_id');
        
        if ($fileId) {
            $massPaymentFile = MassPaymentFile::find($fileId);
            if (!$massPaymentFile) {
                return false;
            }

            // Use MassPaymentFilePolicy to check view payment instructions permission
            return $this->user()->can('viewPaymentInstructions', $massPaymentFile);
        }

        // General permission to view payment instructions
        return $this->user()->client_id !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // File ID filter - must belong to user's client
            'file_id' => [
                'sometimes',
                'nullable',
                'string',
                'exists:mass_payment_files,id',
                function ($attribute, $value, $fail) {
                    if (!$value) {
                        return;
                    }

                    $file = MassPaymentFile::find($value);
                    if (!$file) {
                        $fail('The specified mass payment file does not exist.');
                        return;
                    }

                    // Validate file belongs to user's client
                    $user = Auth::user();
                    if ($user && $user->client_id !== $file->client_id) {
                        $fail('The mass payment file does not belong to your organization.');
                        return;
                    }

                    // Check if user can view payment instructions for this file
                    if ($user && !$user->can('viewPaymentInstructions', $file)) {
                        $fail('You do not have permission to view payment instructions for this file.');
                        return;
                    }
                },
            ],

            // Status filter
            'status' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(PaymentInstruction::getStatuses()),
            ],

            // Multiple status filter (array)
            'statuses' => [
                'sometimes',
                'nullable',
                'array',
                'max:10',
            ],
            'statuses.*' => [
                'string',
                Rule::in(PaymentInstruction::getStatuses()),
            ],

            // Currency filter
            'currency' => [
                'sometimes',
                'nullable',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::in(config('mass-payments.supported_currencies', ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY'])),
            ],

            // Multiple currency filter (array)
            'currencies' => [
                'sometimes',
                'nullable',
                'array',
                'max:10',
            ],
            'currencies.*' => [
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::in(config('mass-payments.supported_currencies', ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY'])),
            ],

            // Beneficiary ID filter
            'beneficiary_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:beneficiaries,id',
                function ($attribute, $value, $fail) {
                    if (!$value) {
                        return;
                    }

                    // Validate beneficiary belongs to user's client (through global scope)
                    $user = Auth::user();
                    if ($user && $user->client_id) {
                        $beneficiary = \App\Models\Beneficiary::find($value);
                        if ($beneficiary && $beneficiary->client_id !== $user->client_id) {
                            $fail('The beneficiary does not belong to your organization.');
                            return;
                        }
                    }
                },
            ],

            // Amount range filters
            'min_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:999999999.99',
            ],

            'max_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:999999999.99',
                'gte:min_amount',
            ],

            // Date range filters
            'created_from' => [
                'sometimes',
                'nullable',
                'date',
                'before_or_equal:today',
            ],

            'created_to' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:created_from',
                'before_or_equal:today',
            ],

            // Purpose code filter
            'purpose_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::in(config('mass-payments.purpose_codes', [])),
            ],

            // Reference search
            'reference' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            // Row number range
            'min_row' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'max_row' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'gte:min_row',
            ],

            // Validation errors filter
            'has_validation_errors' => [
                'sometimes',
                'nullable',
                'boolean',
            ],

            // Sorting parameters
            'sort_by' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'created_at',
                    'updated_at',
                    'amount',
                    'currency',
                    'status',
                    'row_number',
                    'reference',
                    'purpose_code',
                ]),
            ],

            'sort_direction' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['asc', 'desc']),
            ],

            // Pagination parameters
            'page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:1000',
            ],

            'per_page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:500',
            ],

            // Include related data
            'include' => [
                'sometimes',
                'nullable',
                'array',
                'max:5',
            ],
            'include.*' => [
                'string',
                Rule::in([
                    'mass_payment_file',
                    'beneficiary',
                    'mass_payment_file.client',
                    'mass_payment_file.tcc_account',
                    'mass_payment_file.uploader',
                    'mass_payment_file.approver',
                ]),
            ],

            // Search query for full-text search
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                'min:2',
            ],

            // Export format (if requesting export)
            'export_format' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['csv', 'xlsx', 'json']),
            ],

            // Export fields selection
            'export_fields' => [
                'sometimes',
                'nullable',
                'array',
                'max:20',
            ],
            'export_fields.*' => [
                'string',
                Rule::in([
                    'id',
                    'amount',
                    'currency',
                    'purpose_code',
                    'reference',
                    'status',
                    'row_number',
                    'validation_errors',
                    'created_at',
                    'updated_at',
                    'beneficiary_name',
                    'beneficiary_account',
                    'file_id',
                    'file_name',
                ]),
            ],

            // Advanced filters
            'group_by' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['status', 'currency', 'purpose_code', 'beneficiary_id']),
            ],

            // Statistics inclusion
            'include_statistics' => [
                'sometimes',
                'nullable',
                'boolean',
            ],

            // Filter by final states
            'final_states_only' => [
                'sometimes',
                'nullable',
                'boolean',
            ],

            // Filter by processable states
            'processable_only' => [
                'sometimes',
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Get the validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // File ID messages
            'file_id.string' => 'File ID must be a text value.',
            'file_id.exists' => 'The specified mass payment file does not exist.',

            // Status messages
            'status.string' => 'Status must be a text value.',
            'status.in' => 'The selected status is invalid.',
            'statuses.array' => 'Statuses must be an array.',
            'statuses.max' => 'You can filter by a maximum of 10 statuses.',
            'statuses.*.string' => 'Each status must be a text value.',
            'statuses.*.in' => 'One or more selected statuses are invalid.',

            // Currency messages
            'currency.string' => 'Currency must be a text value.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.regex' => 'Currency code must contain only uppercase letters.',
            'currency.in' => 'The specified currency is not supported.',
            'currencies.array' => 'Currencies must be an array.',
            'currencies.max' => 'You can filter by a maximum of 10 currencies.',
            'currencies.*.string' => 'Each currency must be a text value.',
            'currencies.*.size' => 'Each currency code must be exactly 3 characters.',
            'currencies.*.regex' => 'Each currency code must contain only uppercase letters.',
            'currencies.*.in' => 'One or more specified currencies are not supported.',

            // Beneficiary messages
            'beneficiary_id.integer' => 'Beneficiary ID must be a number.',
            'beneficiary_id.exists' => 'The specified beneficiary does not exist.',

            // Amount messages
            'min_amount.numeric' => 'Minimum amount must be a number.',
            'min_amount.min' => 'Minimum amount cannot be negative.',
            'min_amount.max' => 'Minimum amount is too large.',
            'max_amount.numeric' => 'Maximum amount must be a number.',
            'max_amount.min' => 'Maximum amount cannot be negative.',
            'max_amount.max' => 'Maximum amount is too large.',
            'max_amount.gte' => 'Maximum amount must be greater than or equal to minimum amount.',

            // Date messages
            'created_from.date' => 'Created from date must be a valid date.',
            'created_from.before_or_equal' => 'Created from date cannot be in the future.',
            'created_to.date' => 'Created to date must be a valid date.',
            'created_to.after_or_equal' => 'Created to date must be after or equal to created from date.',
            'created_to.before_or_equal' => 'Created to date cannot be in the future.',

            // Purpose code messages
            'purpose_code.string' => 'Purpose code must be text.',
            'purpose_code.max' => 'Purpose code cannot exceed 20 characters.',
            'purpose_code.in' => 'The specified purpose code is not valid.',

            // Reference messages
            'reference.string' => 'Reference must be text.',
            'reference.max' => 'Reference cannot exceed 255 characters.',

            // Row number messages
            'min_row.integer' => 'Minimum row must be a number.',
            'min_row.min' => 'Minimum row must be at least 1.',
            'max_row.integer' => 'Maximum row must be a number.',
            'max_row.min' => 'Maximum row must be at least 1.',
            'max_row.gte' => 'Maximum row must be greater than or equal to minimum row.',

            // Boolean messages
            'has_validation_errors.boolean' => 'Has validation errors must be true or false.',
            'include_statistics.boolean' => 'Include statistics must be true or false.',
            'final_states_only.boolean' => 'Final states only must be true or false.',
            'processable_only.boolean' => 'Processable only must be true or false.',

            // Sorting messages
            'sort_by.string' => 'Sort by field must be text.',
            'sort_by.in' => 'The specified sort field is not allowed.',
            'sort_direction.string' => 'Sort direction must be text.',
            'sort_direction.in' => 'Sort direction must be either asc or desc.',

            // Pagination messages
            'page.integer' => 'Page must be a number.',
            'page.min' => 'Page must be at least 1.',
            'page.max' => 'Page cannot exceed 1000.',
            'per_page.integer' => 'Per page must be a number.',
            'per_page.min' => 'Per page must be at least 1.',
            'per_page.max' => 'Per page cannot exceed 500.',

            // Include messages
            'include.array' => 'Include must be an array.',
            'include.max' => 'You can include a maximum of 5 relationships.',
            'include.*.string' => 'Each include value must be text.',
            'include.*.in' => 'One or more include relationships are not allowed.',

            // Search messages
            'search.string' => 'Search query must be text.',
            'search.max' => 'Search query cannot exceed 100 characters.',
            'search.min' => 'Search query must be at least 2 characters.',

            // Export messages
            'export_format.string' => 'Export format must be text.',
            'export_format.in' => 'Export format must be csv, xlsx, or json.',
            'export_fields.array' => 'Export fields must be an array.',
            'export_fields.max' => 'You can export a maximum of 20 fields.',
            'export_fields.*.string' => 'Each export field must be text.',
            'export_fields.*.in' => 'One or more export fields are not allowed.',

            // Advanced filter messages
            'group_by.string' => 'Group by field must be text.',
            'group_by.in' => 'The specified group by field is not allowed.',
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
            'file_id' => 'mass payment file',
            'status' => 'payment status',
            'statuses' => 'payment statuses',
            'currency' => 'currency code',
            'currencies' =>