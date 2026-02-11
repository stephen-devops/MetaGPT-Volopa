<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Client;
use App\Models\OopExpense;
use Illuminate\Support\Facades\Auth;

class UpdateOopExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Get the expense from route parameter
        $expense = $this->route('oop_expense');
        
        if (!$expense instanceof OopExpense) {
            return false;
        }

        // Use the policy to check if user can update this expense
        return $user->can('update', $expense);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $expense = $this->route('oop_expense');
        $expenseId = $expense instanceof OopExpense ? $expense->id : null;

        return [
            'date' => [
                'sometimes',
                'date',
                'before_or_equal:today',
                'after_or_equal:' . now()->subYear()->format('Y-m-d'),
            ],
            'merchant_name' => [
                'sometimes',
                'string',
                'max:255',
                'min:1',
            ],
            'amount' => [
                'sometimes',
                'numeric',
                'min:0.01',
                'max:999999.99',
                'decimal:0,2',
            ],
            'currency' => [
                'sometimes',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::exists('currency', 'code')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'status' => [
                'sometimes',
                'string',
                Rule::in(OopExpense::getStatusOptions()),
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
            'receipt_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
                'url',
            ],
            'category' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'converted_amount' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0.01',
                'max:999999.99',
                'decimal:0,2',
            ],
            'converted_currency' => [
                'sometimes',
                'nullable',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::exists('currency', 'code')->where(function ($query) {
                    $query->where('is_active', true);
                }),
                'required_with:converted_amount,fx_rate',
            ],
            'fx_rate' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0.000001',
                'max:999999.999999',
                'decimal:0,6',
                'required_with:converted_amount,converted_currency',
            ],
            'fx_commission' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:99.9999',
                'decimal:0,4',
            ],
            'metadata' => [
                'sometimes',
                'nullable',
                'json',
            ],
            'is_reimbursable' => [
                'sometimes',
                'boolean',
            ],
            'expense_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/i',
            ],
            'project_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/i',
            ],
            'cost_center' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/i',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'date.date' => 'The expense date must be a valid date.',
            'date.before_or_equal' => 'The expense date cannot be in the future.',
            'date.after_or_equal' => 'The expense date cannot be more than one year ago.',
            
            'merchant_name.string' => 'The merchant name must be a string.',
            'merchant_name.max' => 'The merchant name may not be greater than 255 characters.',
            'merchant_name.min' => 'The merchant name must be at least 1 character.',
            
            'amount.numeric' => 'The expense amount must be a number.',
            'amount.min' => 'The expense amount must be at least 0.01.',
            'amount.max' => 'The expense amount may not be greater than 999,999.99.',
            'amount.decimal' => 'The expense amount must have at most 2 decimal places.',
            
            'currency.string' => 'The currency must be a string.',
            'currency.size' => 'The currency must be exactly 3 characters.',
            'currency.regex' => 'The currency must be in uppercase 3-letter format (e.g., USD).',
            'currency.exists' => 'The selected currency is not valid or inactive.',
            
            'status.string' => 'The status must be a string.',
            'status.in' => 'The selected status is invalid.',
            
            'description.string' => 'The description must be a string.',
            'description.max' => 'The description may not be greater than 1000 characters.',
            
            'receipt_url.string' => 'The receipt URL must be a string.',
            'receipt_url.max' => 'The receipt URL may not be greater than 500 characters.',
            'receipt_url.url' => 'The receipt URL must be a valid URL.',
            
            'category.string' => 'The category must be a string.',
            'category.max' => 'The category may not be greater than 100 characters.',
            
            'converted_amount.numeric' => 'The converted amount must be a number.',
            'converted_amount.min' => 'The converted amount must be at least 0.01.',
            'converted_amount.max' => 'The converted amount may not be greater than 999,999.99.',
            'converted_amount.decimal' => 'The converted amount must have at most 2 decimal places.',
            
            'converted_currency.string' => 'The converted currency must be a string.',
            'converted_currency.size' => 'The converted currency must be exactly 3 characters.',
            'converted_currency.regex' => 'The converted currency must be in uppercase 3-letter format (e.g., USD).',
            'converted_currency.exists' => 'The selected converted currency is not valid or inactive.',
            'converted_currency.required_with' => 'The converted currency is required when converted amount or FX rate is provided.',
            
            'fx_rate.numeric' => 'The FX rate must be a number.',
            'fx_rate.min' => 'The FX rate must be at least 0.000001.',
            'fx_rate.max' => 'The FX rate may not be greater than 999,999.999999.',
            'fx_rate.decimal' => 'The FX rate must have at most 6 decimal places.',
            'fx_rate.required_with' => 'The FX rate is required when converted amount or converted currency is provided.',
            
            'fx_commission.numeric' => 'The FX commission must be a number.',
            'fx_commission.min' => 'The FX commission must be at least 0.',
            'fx_commission.max' => 'The FX commission may not be greater than 99.9999.',
            'fx_commission.decimal' => 'The FX commission must have at most 4 decimal places.',
            
            'metadata.json' => 'The metadata must be valid JSON.',
            
            'is_reimbursable.boolean' => 'The reimbursable flag must be true or false.',
            
            'expense_code.string' => 'The expense code must be a string.',
            'expense_code.max' => 'The expense code may not be greater than 50 characters.',
            'expense_code.regex' => 'The expense code may only contain letters, numbers, underscores, and hyphens.',
            
            'project_code.string' => 'The project code must be a string.',
            'project_code.max' => 'The project code may not be greater than 50 characters.',
            'project_code.regex' => 'The project code may only contain letters, numbers, underscores, and hyphens.',
            
            'cost_center.string' => 'The cost center must be a string.',
            'cost_center.max' => 'The cost center may not be greater than 50 characters.',
            'cost_center.regex' => 'The cost center may only contain letters, numbers, underscores, and hyphens.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'date' => 'expense date',
            'merchant_name' => 'merchant name',
            'amount' => 'amount',
            'currency' => 'currency',
            'status' => 'status',
            'description' => 'description',
            'receipt_url' => 'receipt URL',
            'category' => 'category',
            'converted_amount' => 'converted amount',
            'converted_currency' => 'converted currency',
            'fx_rate' => 'FX rate',
            'fx_commission' => 'FX commission',
            'metadata' => 'metadata',
            'is_reimbursable' => 'reimbursable',
            'expense_code' => 'expense code',
            'project_code' => 'project code',
            'cost_center' => 'cost center',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateExpenseStatus($validator);
            $this->validateAmountLimits($validator);
            $this->validateCurrencyConsistency($validator);
            $this->validateFxConversionLogic($validator);
            $this->validateMetadataFormat($validator);
            $this->validateBusinessRules($validator);
            $this->validateUpdatePermissions($validator);
        });
    }

    /**
     * Validate that the expense can be updated based on its current status.
     */
    protected function validateExpenseStatus($validator): void
    {
        $expense = $this->route('oop_expense');

        if ($expense instanceof OopExpense) {
            // Only allow updates for pending and rejected expenses
            if (!in_array($expense->status, ['pending', 'rejected'])) {
                $validator->errors()->add('status', 'Only pending or rejected expenses can be updated.');
            }

            // Validate status transitions
            $newStatus = $this->input('status');
            if ($newStatus && !$this->isValidStatusTransition($expense->status, $newStatus)) {
                $validator->errors()->add('status', "Cannot change status from {$expense->status} to {$newStatus}.");
            }
        }
    }

    /**
     * Validate amount limits based on user role and expense current state.
     */
    protected function validateAmountLimits($validator): void
    {
        $amount = $this->input('amount');
        $authUser = Auth::user();
        $expense = $this->route('oop_expense');

        if ($amount && $authUser && $expense instanceof OopExpense) {
            // Check if user is the expense owner
            $isOwner = $expense->user_id === $authUser->id;
            
            // Define amount limits based on user role and ownership
            $limits = [
                'owner' => [
                    'user' => 5000.00,
                    'manager' => 10000.00,
                    'admin' => PHP_FLOAT_MAX,
                    'super_admin' => PHP_FLOAT_MAX
                ],
                'non_owner' => [
                    'user' => 0.00, // Users cannot modify others' expenses
                    'manager' => 2000.00,
                    'admin' => PHP_FLOAT_MAX,
                    'super_admin' => PHP_FLOAT_MAX
                ]
            ];

            $userRole = $this->getUserHighestRole($authUser);
            $limitType = $isOwner ? 'owner' : 'non_owner';
            $limit = $limits[$limitType][$userRole] ?? 0;

            if ($amount > $limit) {
                $ownershipText = $isOwner ? 'your own' : 'other users\'';
                $validator->errors()->add('amount', "The expense amount exceeds the limit of " . number_format($limit, 2) . " for updating {$ownershipText} expenses with your role.");
            }

            // Check if the amount increase is within reasonable bounds
            if ($amount > $expense->amount) {
                $increase = $amount - $expense->amount;
                $increasePercentage = ($increase / $expense->amount) * 100;

                if ($increasePercentage > 100) { // More than 100% increase
                    $validator->errors()->add('amount', 'The expense amount increase exceeds 100% of the original amount. Please provide justification.');
                }
            }
        }
    }

    /**
     * Validate currency consistency and conversion logic.
     */
    protected function validateCurrencyConsistency($validator): void
    {
        $currency = $this->input('currency');
        $convertedCurrency = $this->input('converted_currency');
        $expense = $this->route('oop_expense');

        if ($currency && $convertedCurrency && $currency === $convertedCurrency) {
            $validator->errors()->add('converted_currency', 'The converted currency must be different from the original currency.');
        }

        // If updating currency, ensure it's compatible with existing conversions
        if ($expense instanceof OopExpense && $currency && $expense->hasFxConversion()) {
            if ($currency !== $expense->currency && !$this->input('converted_amount')) {
                $validator->errors()->add('currency', 'When changing currency on an expense with FX conversion, you must also update the conversion details.');
            }
        }
    }

    /**
     * Validate FX conversion logic and calculations.
     */
    protected function validateFxConversionLogic($validator): void
    {
        $expense = $this->route('oop_expense');
        $amount = $this->input('amount', $expense instanceof OopExpense ? $expense->amount : 0);
        $convertedAmount = $this->input('converted_amount');
        $fxRate = $this->input('fx_rate');
        $fxCommission = $this->input('fx_commission', 0);

        // If any FX field is provided, validate the calculation
        if ($amount && $convertedAmount && $fxRate) {
            $expectedAmount = $amount * $fxRate * (1 - $fxCommission);
            $tolerance = 0.01; // 1 cent tolerance

            if (abs($convertedAmount - $expectedAmount) > $tolerance) {
                $validator->errors()->add('converted_amount', 
                    'The converted amount does not match the calculation: ' . 
                    number_format($amount, 2) . ' × ' . number_format($fxRate, 6) . 
                    ' × (1 - ' . number_format($fxCommission, 4) . ') = ' . 
                    number_format($expectedAmount, 2)
                );
            }
        }

        // Validate commission is within reasonable bounds
        if ($fxCommission !== null && ($fxCommission < 0 || $fxCommission > 0.1)) {
            $validator->errors()->add('fx_commission', 'The FX commission should be between 0% and 10%.');
        }

        // Validate FX rate is reasonable (not too extreme)
        if ($fxRate !== null) {
            if ($fxRate < 0.001 || $fxRate > 1000) {
                $validator->errors()->add('fx_rate', 'The FX rate appears to be unrealistic. Please verify the conversion rate.');
            }
        }
    }

    /**
     * Validate metadata JSON format and structure.
     */
    protected function validateMetadataFormat($validator): void
    {
        $metadata = $this->input('metadata');

        if ($metadata !== null) {
            // Try to decode JSON if it's a string
            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $validator->errors()->add('metadata', 'The metadata must be valid JSON.');
                    return;
                }
                $metadata = $decoded;
            }

            // Validate metadata structure if it's an array
            if (is_array($metadata)) {
                $this->validateMetadataStructure($metadata, $validator);
            }
        }
    }

    /**
     * Validate metadata structure and content.
     */
    protected function validateMetadataStructure(array $metadata, $validator): void
    {
        // Check for maximum depth
        if ($this->getArrayDepth($metadata) > 5) {
            $validator->errors()->add('metadata', 'The metadata structure is too deep (maximum 5 levels).');
        }

        // Check for maximum size (serialized)
        if (strlen(json_encode($metadata)) > 10000) {
            $validator->errors()->add('metadata', 'The metadata is too large (maximum 10KB when serialized).');
        }

        // Validate specific metadata fields if they exist
        $allowedFields = [
            'receipt_number', 'vendor_id', 'tax_amount', 'tax_rate', 'location',
            'attendees', 'purpose', 'account_code', 'department', 'tags'
        ];

        foreach ($metadata as $key => $value) {
            if (!in_array($key, $allowedFields)) {
                $validator->errors()->add('metadata', "The metadata field '{$key}' is not allowed.");
            }

            // Validate specific field types
            if ($key === 'tax_amount' && !is_numeric($value)) {
                $validator->errors()->add('metadata', 'The metadata tax_amount must be numeric.');
            }

            if ($key === 'tax_rate' && (!is_numeric($value) || $value < 0 || $value > 1)) {
                $validator->errors()->add('metadata', 'The metadata tax_rate must be between 0 and 1.');
            }
        }
    }

    /**
     * Validate additional business rules for updates.
     */
    protected function validateBusinessRules($validator): void
    {
        $expense = $this->route('oop_expense');
        $date = $this->input('date');
        
        if ($expense instanceof OopExpense) {
            $clientId = $expense->client_id;
            
            // Validate business date restrictions for updates
            if ($date) {
                $expenseDate = \Carbon\Carbon::parse($date);
                $originalDate = \Carbon\Carbon::parse($expense->date);
                
                // Don't allow moving expenses to much older dates
                if ($expenseDate->diffInDays($originalDate) > 30) {
                    $validator->errors()->add('date', 'Cannot move expense date more than 30 days from the original date.');
                }
                
                // No expenses on weekends for certain clients (example business rule)
                if ($expenseDate->isWeekend() && $this->isWeekendRestrictedClient($clientId)) {
                    $validator->errors()->add('date', 'Expenses cannot be updated to weekend dates for this client.');
                }

                // No expenses during client's fiscal year closure periods
                if ($this->isInClosurePeriod($expenseDate, $clientId)) {
                    $validator->errors()->add('date', 'Expenses cannot be updated to dates during the fiscal year closure period.');
                }
            }

            // Validate merchant name against blacklist
            $merchantName = $this->input('merchant_name');
            if ($merchantName && $this->isMerchantBlacklisted($merchantName, $clientId)) {
                $validator->errors()->add('merchant_name', 'This merchant is not allowed for expenses.');
            }

            // Validate that critical fields aren't changed after certain time period
            $timeLimit = now()->subDays(7);
            if ($expense->created_at < $timeLimit) {
                $criticalFields = ['amount', 'currency', 'date'];
                foreach ($criticalFields as $field) {
                    if ($this->has($field) && $this->input($field) != $expense->$field) {
                        $validator->errors()->add($field, "The {$field} cannot be changed on expenses older than 7 days.");
                    }
                }
            }
        }
    }

    /**
     * Validate update permissions based on expense ownership and user role.
     */
    protected function validateUpdatePermissions($validator): void
    {
        $expense = $this->route('oop_expense');
        $authUser = Auth::user();

        if ($expense instanceof OopExpense && $authUser) {
            $isOwner = $expense->user_id === $authUser->id;
            
            // Non-owners have restricted update capabilities
            if (!$isOwner) {
                $restrictedFields = ['amount', 'currency', 'merchant_name', 'date'];
                $userRole = $this->getUserHighestRole($authUser);
                
                // Only admins and super admins can modify core expense details of others
                if (!in_array($userRole, ['admin', 'super_admin'])) {
                    foreach ($restrictedFields as $field) {
                        if ($this->has($field)) {
                            $validator->errors()->add($field, "You cannot modify the {$field} of expenses belonging to other users.");
                        }
                    }
                }
            }

            // Status updates require special permissions
            if ($this->has('status')) {
                $newStatus = $this->input('status');
                if ($newStatus === 'approved' && !$authUser->can('approve', $expense)) {
                    $validator->errors()->add('status', 'You do not have permission to approve this expense.');
                }
            }
        }
    }

    /**
     * Check if status transition is valid.
     */
    protected function isValidStatusTransition(string $currentStatus, string $newStatus): bool
    {
        $allowedTransitions = [
            'pending' => ['pending', 'approved', 'rejected', 'processing'],
            'approved' => ['approved'], // Approved expenses cannot be changed
            'rejected' => ['pending', 'rejected'], // Can resubmit or keep rejected
            'processing' => ['processing', 'approved', 'rejected'],
        ];

        return in_array($newStatus, $allowedTransitions[$currentStatus] ?? []);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency codes to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency')),
            ]);
        }

        if ($this->has('converted_currency')) {
            $this->merge([
                'converted_currency' => strtoupper($this->input('converted_currency')),
            ]);
        }

        // Parse metadata JSON if it's a string
        if ($this->has('metadata') && is_string($this->input('metadata'))) {
            $metadata = json_decode($this->input('metadata'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['metadata' => $metadata]);
            }
        }

        // Ensure boolean conversion
        if ($this->has('is_reimbursable')) {
            $this->merge([
                'is_reimbursable' => filter_var($this->input('is_reimbursable'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        // Set default fx_commission if FX fields are being updated
        if (($this->has('converted_amount') || $this->has('fx_rate')) && !$this->has('fx_commission')) {
            $this->merge(['fx_commission' => 0.0000]);
        }
    }

    /**
     * Get the validated data for update.
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Apply defaults only for fields being updated
        if (isset($validated['fx_commission'])) {
            $validated['fx_commission'] = $validated['fx_commission'] ?? 0.0000;
        }
        
        return $validated;
    }

    /**
     * Get user's highest role for permission checking.
     */
    private function getUserHighestRole(User $user): string
    {
        if ($user->hasRole('super_admin')) {
            return 'super_admin';
        }
        if ($user->hasRole('admin')) {
            return 'admin';
        }
        if ($user->hasRole('manager')) {
            return 'manager';
        }
        return 'user';
    }

    /**
     * Get the depth of an array.
     */
    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $maxDepth = max($maxDepth, 1 + $this->getArrayDepth($value));
            }
        }

        return $maxDepth;
    }

    /**
     * Check if client has weekend restrictions.
     */
    private function isWeekendRestrictedClient(int $clientId): bool
    {
        // This would typically check client settings from database
        // For now, return false (implement based on business requirements)
        return false;
    }

    /**
     * Check if date falls within client's closure period.
     */
    private function isInClosurePeriod(\Carbon\Carbon $date, int $clientId): bool
    {
        // This would typically check client's fiscal calendar
        // For now, return false (implement based on business requirements)
        return false;
    }

    /**
     * Check if merchant is blacklisted for client.
     */
    private function isMerchantBlacklisted(string $merchantName, int $clientId): bool
    {
        // This would typically check against a blacklist table
        // For now, return false (implement based on business requirements)
        return false;
    }

    /**
     * Get the proper failed validation response for the request.
     */
    public function response(array $errors): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $errors,
        ], 422);
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You do not have permission to update this OOP expense.'
        );
    }

    /**
     * Get the validator instance for the request.
     */
    public function getValidatorInstance(): \Illuminate\Validation\Validator
    {
        $factory = $this->container->make(\Illuminate\Validation\Factory::class);
        
        if (method_exists($this, 'validator')) {
            $validator = $this->container->call([$this, 'validator'], compact('factory'));
        } else {
            $validator = $this->createDefaultValidator($factory);
        }

        if (method_exists($this, 'withValidator')) {
            $this->withValidator($validator);
        }

        return $validator;
    }

    /**
     * Create the default validator instance.
     */
    protected function createDefaultValidator(\Illuminate\Validation\Factory $factory): \Illuminate\Validation\Validator
    {
        return $factory->make(
            $this->validationData(),
            $this->container->call([$this, 'rules']),
            $this->messages(),
            $this->attributes()
        );
    }

    /**
     * Get data to be validated from the request.
     */
    public function validationData(): array
    {
        return $this->all();
    }
}