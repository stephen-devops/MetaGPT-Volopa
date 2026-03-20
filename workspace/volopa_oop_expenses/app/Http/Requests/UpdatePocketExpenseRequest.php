<?php

namespace App\Http\Requests;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

/**
 * UpdatePocketExpenseRequest
 * 
 * Form request for validating pocket expense updates with authorization policy.
 * Handles validation rules, field sanitization, and authorization checks.
 * Supports partial updates while maintaining data integrity constraints.
 */
class UpdatePocketExpenseRequest extends FormRequest
{
    /**
     * The PocketExpense model instance being updated.
     *
     * @var \App\Models\PocketExpense|null
     */
    protected ?PocketExpense $expense = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Get the expense model from route binding
        $this->expense = $this->route('pocket_expense') ?? $this->route('expense');
        
        if (!$this->expense) {
            return false;
        }

        // Use the policy to check if user can update this expense
        return Gate::forUser($this->user())->allows('update', $this->expense);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->user()->client_id ?? null;
        $expenseId = $this->expense ? $this->expense->id : null;
        
        return [
            // Core expense fields
            'date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
                function ($attribute, $value, $fail) {
                    $date = Carbon::parse($value);
                    $threeYearsAgo = now()->subYears(3);
                    if ($date->lt($threeYearsAgo)) {
                        $fail('The expense date cannot be older than 3 years.');
                    }
                },
            ],
            
            'merchant_name' => [
                'sometimes',
                'required',
                'string',
                'max:180',
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+$/u', // Prevent control characters
            ],
            
            'merchant_description' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u',
            ],
            
            'expense_type' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('opt_pocket_expense_type', 'id'),
            ],
            
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                function ($attribute, $value, $fail) {
                    // Basic currency validation - should be expanded with actual currency list
                    $validCurrencies = [
                        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY', 'SEK', 'NZD',
                        'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'ZAR', 'BRL', 'INR', 'KRW', 'PLN'
                    ];
                    
                    if (!in_array(strtoupper($value), $validCurrencies)) {
                        $fail('The selected currency is not supported.');
                    }
                },
            ],
            
            'amount' => [
                'sometimes',
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
                'regex:/^\d+(\.\d{1,2})?$/', // Ensure max 2 decimal places
            ],
            
            'merchant_address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u',
            ],
            
            'vat_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99',
                'regex:/^\d+(\.\d{1,2})?$/',
                function ($attribute, $value, $fail) {
                    $amount = $this->input('amount') ?? ($this->expense ? $this->expense->amount : 0);
                    if ($value !== null && $amount > 0 && $value >= $amount) {
                        $fail('The VAT amount must be less than the total amount.');
                    }
                },
            ],
            
            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
                function ($attribute, $value, $fail) {
                    if ($value !== null) {
                        // Prevent potential SQL injection patterns
                        $dangerousPatterns = [
                            '/\b(union|select|insert|update|delete|drop|create|alter|exec|execute)\b/i',
                            '/[<>]/', // Basic XSS prevention
                            '/\x00/', // Null byte
                        ];
                        
                        foreach ($dangerousPatterns as $pattern) {
                            if (preg_match($pattern, $value)) {
                                $fail('The notes field contains invalid characters.');
                                break;
                            }
                        }
                    }
                },
            ],
            
            'status' => [
                'sometimes',
                'string',
                Rule::in(PocketExpense::$validStatuses),
                function ($attribute, $value, $fail) {
                    // Business rule: only drafts can change status to submitted
                    if ($this->expense && $value && $value !== $this->expense->status) {
                        if ($this->expense->status !== PocketExpense::STATUS_DRAFT && 
                            $value === PocketExpense::STATUS_SUBMITTED) {
                            $fail('Only draft expenses can be submitted for approval.');
                        }
                        
                        // Business rule: only certain roles can approve/reject
                        if (in_array($value, [PocketExpense::STATUS_APPROVED, PocketExpense::STATUS_REJECTED])) {
                            if (!Gate::forUser($this->user())->allows('approve', $this->expense)) {
                                $fail('You do not have permission to approve or reject expenses.');
                            }
                        }
                    }
                },
            ],
            
            // Metadata fields (optional)
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('transaction_categories', 'id')->where('client_id', $clientId),
            ],
            
            'tracking_code_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('tracking_codes', 'id')->where('client_id', $clientId),
            ],
            
            'project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('configurable_projects', 'id')->where('client_id', $clientId),
            ],
            
            'expense_source_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value !== null) {
                        $source = PocketExpenseSourceClientConfig::active()
                            ->where('id', $value)
                            ->where(function ($query) use ($clientId) {
                                $query->whereNull('client_id') // Global sources
                                      ->orWhere('client_id', $clientId); // Client-specific sources
                            })
                            ->first();
                            
                        if (!$source) {
                            $fail('The selected expense source is not available.');
                        }
                    }
                },
            ],
            
            'source_note' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                function ($attribute, $value, $fail) {
                    $sourceId = $this->input('expense_source_id');
                    if ($sourceId) {
                        $source = PocketExpenseSourceClientConfig::find($sourceId);
                        if ($source && $source->name === 'Other' && empty(trim($value))) {
                            $fail('Source note is required when expense source is "Other".');
                        }
                    }
                },
            ],
            
            // File upload field (for receipt attachments)
            'receipt_file' => [
                'sometimes',
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240', // 10MB max
            ],
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
            'date.required' => 'The expense date is required.',
            'date.date_format' => 'The expense date must be in YYYY-MM-DD format.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.max' => 'The merchant name must not exceed 180 characters.',
            'merchant_name.regex' => 'The merchant name contains invalid characters.',
            
            'merchant_description.max' => 'The merchant description must not exceed 255 characters.',
            'merchant_description.regex' => 'The merchant description contains invalid characters.',
            
            'expense_type.exists' => 'The selected expense type is invalid.',
            
            'currency.required' => 'The currency is required.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be a valid 3-letter ISO code (e.g., USD, EUR).',
            
            'amount.required' => 'The expense amount is required.',
            'amount.numeric' => 'The expense amount must be a valid number.',
            'amount.min' => 'The expense amount must be at least 0.01.',
            'amount.max' => 'The expense amount must not exceed 999,999.99.',
            'amount.regex' => 'The expense amount can have at most 2 decimal places.',
            
            'merchant_address.max' => 'The merchant address must not exceed 500 characters.',
            'merchant_address.regex' => 'The merchant address contains invalid characters.',
            
            'vat_amount.numeric' => 'The VAT amount must be a valid number.',
            'vat_amount.min' => 'The VAT amount must be at least 0.',
            'vat_amount.max' => 'The VAT amount must not exceed 999,999.99.',
            'vat_amount.regex' => 'The VAT amount can have at most 2 decimal places.',
            
            'notes.max' => 'The notes must not exceed 1,000 characters.',
            
            'status.in' => 'The selected status is invalid.',
            
            'category_id.exists' => 'The selected category is invalid.',
            'tracking_code_id.exists' => 'The selected tracking code is invalid.',
            'project_id.exists' => 'The selected project is invalid.',
            
            'source_note.max' => 'The source note must not exceed 500 characters.',
            
            'receipt_file.file' => 'The receipt must be a valid file.',
            'receipt_file.mimes' => 'The receipt must be a JPG, PNG, or PDF file.',
            'receipt_file.max' => 'The receipt file must not exceed 10MB.',
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
            'amount' => 'amount',
            'merchant_address' => 'merchant address',
            'vat_amount' => 'VAT amount',
            'notes' => 'notes',
            'status' => 'status',
            'category_id' => 'category',
            'tracking_code_id' => 'tracking code',
            'project_id' => 'project',
            'expense_source_id' => 'expense source',
            'source_note' => 'source note',
            'receipt_file' => 'receipt file',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Trim string fields to prevent whitespace issues
        $trimFields = ['merchant_name', 'merchant_description', 'merchant_address', 'notes', 'source_note'];
        $data = $this->all();
        
        foreach ($trimFields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
                // Convert empty strings to null for optional fields
                if (in_array($field, ['merchant_description', 'merchant_address', 'notes', 'source_note'])) {
                    $data[$field] = $data[$field] === '' ? null : $data[$field];
                }
            }
        }
        
        // Normalize currency to uppercase
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper(trim($data['currency']));
        }
        
        // Ensure amount is properly formatted
        if (isset($data['amount'])) {
            $data['amount'] = number_format((float)$data['amount'], 2, '.', '');
        }
        
        // Ensure VAT amount is properly formatted
        if (isset($data['vat_amount']) && $data['vat_amount'] !== null) {
            $data['vat_amount'] = number_format((float)$data['vat_amount'], 2, '.', '');
        }
        
        // Convert date format if needed (from DD/MM/YYYY to YYYY-MM-DD)
        if (isset($data['date']) && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data['date'], $matches)) {
            $data['date'] = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        
        // Set updated_by_user_id for audit trail
        $data['updated_by_user_id'] = $this->user()->id;
        
        $this->replace($data);
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional cross-field validation
            $this->validateBusinessRules($validator);
        });
    }

    /**
     * Validate business rules that require multiple fields.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateBusinessRules($validator): void
    {
        // Rule: Only draft expenses can be edited (except for status changes by approvers)
        if ($this->expense && !$this->expense->isDraft()) {
            $allowedFields = ['status']; // Only status can be changed for non-draft expenses
            $changedFields = array_keys($this->except(['updated_by_user_id']));
            $disallowedChanges = array_diff($changedFields, $allowedFields);
            
            if (!empty($disallowedChanges) && !Gate::forUser($this->user())->allows('approve', $this->expense)) {
                $validator->errors()->add('expense', 'Only draft expenses can be edited.');
            }
        }
        
        // Rule: Ensure user can only update their own expenses (unless they have management rights)
        if ($this->expense && $this->expense->user_id !== $this->user()->id) {
            if (!Gate::forUser($this->user())->allows('update', $this->expense)) {
                $validator->errors()->add('expense', 'You can only update your own expenses.');
            }
        }
        
        // Rule: Ensure client_id matches (multi-tenancy)
        if ($this->expense && $this->expense->client_id !== $this->user()->client_id) {
            $validator->errors()->add('expense', 'Invalid expense access.');
        }
        
        // Rule: VAT validation
        $amount = $this->input('amount') ?? ($this->expense ? $this->expense->amount : 0);
        $vatAmount = $this->input('vat_amount');
        
        if ($vatAmount !== null && $amount > 0) {
            $vatPercentage = ($vatAmount / ($amount - $vatAmount)) * 100;
            if ($vatPercentage > 100) {
                $validator->errors()->add('vat_amount', 'VAT percentage cannot exceed 100%.');
            }
        }
        
        // Rule: Status transition validation
        $newStatus = $this->input('status');
        if ($newStatus && $this->expense && $newStatus !== $this->expense->status) {
            $this->validateStatusTransition($validator, $this->expense->status, $newStatus);
        }
        
        // Rule: Date validation against business constraints
        $expenseDate = $this->input('date') ? Carbon::parse($this->input('date')) : null;
        if ($expenseDate) {
            // Cannot be weekend for certain expense types (business rule example)
            $expenseType = $this->input('expense_type');
            if ($expenseType && in_array($expenseType, [1, 2]) && $expenseDate->isWeekend()) {
                // This is an example business rule - adjust as needed
                $validator->errors()->add('date', 'Business expenses cannot be dated on weekends.');
            }
        }
    }

    /**
     * Validate status transition rules.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  string  $currentStatus
     * @param  string  $newStatus
     * @return void
     */
    protected function validateStatusTransition($validator, string $currentStatus, string $newStatus): void
    {
        $validTransitions = [
            PocketExpense::STATUS_DRAFT => [PocketExpense::STATUS_SUBMITTED],
            PocketExpense::STATUS_SUBMITTED => [PocketExpense::STATUS_APPROVED, PocketExpense::STATUS_REJECTED, PocketExpense::STATUS_DRAFT],
            PocketExpense::STATUS_APPROVED => [], // No transitions from approved
            PocketExpense::STATUS_REJECTED => [PocketExpense::STATUS_DRAFT], // Can revert to draft for corrections
        ];
        
        if (!isset($validTransitions[$currentStatus]) || 
            !in_array($newStatus, $validTransitions[$currentStatus])) {
            $validator->errors()->add('status', 'Invalid status transition from ' . $currentStatus . ' to ' . $newStatus . '.');
        }
        
        // Additional authorization checks for specific transitions
        if (in_array($newStatus, [PocketExpense::STATUS_APPROVED, PocketExpense::STATUS_REJECTED])) {
            if (!Gate::forUser($this->user())->allows('approve', $this->expense)) {
                $validator->errors()->add('status', 'You do not have permission to approve or reject expenses.');
            }
        }
    }

    /**
     * Get the validated data with proper type casting.
     *
     * @return array
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        // Ensure numeric fields are properly cast
        if (isset($validated['amount'])) {
            $validated['amount'] = (float) $validated['amount'];
        }
        
        if (isset($validated['vat_amount']) && $validated['vat_amount'] !== null) {
            $validated['vat_amount'] = (float) $validated['vat_amount'];
        }
        
        if (isset($validated['expense_type']) && $validated['expense_type'] !== null) {
            $validated['expense_type'] = (int) $validated['expense_type'];
        }
        
        // Remove fields that shouldn't be mass assigned
        $protectedFields = ['id', 'uuid', 'create_time', 'deleted', 'delete_time'];
        foreach ($protectedFields as $field) {
            unset($validated[$field]);
        }
        
        return $validated;
    }

    /**
     * Get the expense model being updated.
     *
     * @return \App\Models\PocketExpense|null
     */
    public function getExpense(): ?PocketExpense
    {
        return $this->expense;
    }

    /**
     * Check if the request is trying to submit the expense for approval.
     *
     * @return bool
     */
    public function isSubmittingForApproval(): bool
    {
        return $this->input('status') === PocketExpense::STATUS_SUBMITTED;
    }

    /**
     * Check if the request is trying to approve the expense.
     *
     * @return bool
     */
    public function isApprovingExpense(): bool
    {
        return $this->input('status') === PocketExpense::STATUS_APPROVED;
    }

    /**
     * Check if the request is trying to reject the expense.
     *
     * @return bool
     */
    public function isRejectingExpense(): bool
    {
        return $this->input('status') === PocketExpense::STATUS_REJECTED;
    }

    /**
     * Check if the request contains file upload.
     *
     * @return bool
     */
    public function hasReceiptFile(): bool
    {
        return $this->hasFile('receipt_file') && $this->file('receipt_file')->isValid();
    }

    /**
     * Get sanitized notes with potential security risks removed.
     *
     * @return string|null
     */
    public function getSanitizedNotes(): ?string
    {
        $notes = $this->input('notes');
        
        if ($notes === null) {
            return null;
        }
        
        // Remove potential XSS and SQL injection patterns
        $notes = strip_tags($notes);
        $notes = preg_replace('/[<>]/', '', $notes);
        $notes = preg_replace('/\x00/', '', $notes); // Remove null bytes
        
        return trim($notes) ?: null;
    }
}