<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\PocketExpenseSourceClientConfig;

/**
 * Update Pocket Expense Source Request
 * 
 * Form request for updating expense source configuration.
 * Validates client scoping, unique name constraint, and permission checks.
 * Prevents editing of global 'Other' record per system constraints.
 */
class UpdatePocketExpenseSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Get the expense source being updated
        $expenseSource = $this->route('expense_source') ?? $this->route('id');
        
        if (!$expenseSource || !($expenseSource instanceof PocketExpenseSourceClientConfig)) {
            return false;
        }

        // Cannot edit global 'Other' record per system constraints
        if ($expenseSource->isGlobalOther()) {
            return false;
        }

        $user = Auth::user();
        if (!$user) {
            return false;
        }

        // TODO: Implement proper authorization logic
        // Check if user has permission to manage expense sources for the client
        // This should verify:
        // 1. User belongs to the same client as the expense source
        // 2. User has appropriate role/permissions for expense source management
        // 3. Admin can only manage sources for their own client context
        
        return true; // Placeholder - should implement proper permission check
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Get the expense source being updated
        $expenseSource = $this->route('expense_source') ?? $this->route('id');
        $expenseSourceId = $expenseSource ? $expenseSource->id : null;
        
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // Unique constraint: client_id + name (excluding current record)
                Rule::unique('pocket_expense_source_client_config', 'name')
                    ->where('client_id', $this->input('client_id'))
                    ->ignore($expenseSourceId),
            ],
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'is_default' => [
                'sometimes',
                'boolean',
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
            'name.required' => 'Expense source name is required.',
            'name.string' => 'Expense source name must be a string.',
            'name.max' => 'Expense source name cannot exceed 255 characters.',
            'name.unique' => 'An expense source with this name already exists for the client.',
            'client_id.required' => 'Client ID is required.',
            'client_id.integer' => 'Client ID must be an integer.',
            'client_id.exists' => 'The specified client does not exist.',
            'is_default.boolean' => 'Default flag must be a boolean value.',
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
            'name' => 'expense source name',
            'client_id' => 'client',
            'is_default' => 'default flag',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Additional validation logic
            $this->validateClientScoping($validator);
            $this->validateMaxActiveSources($validator);
            $this->validateGlobalOtherRestrictions($validator);
        });
    }

    /**
     * Validate client scoping constraints.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateClientScoping(\Illuminate\Validation\Validator $validator): void
    {
        $user = Auth::user();
        $clientId = $this->input('client_id');
        
        if (!$user || !$clientId) {
            return;
        }

        // TODO: Implement client scoping validation
        // Ensure the authenticated user has access to the specified client
        // This should verify:
        // 1. User belongs to the client
        // 2. User has permission to manage expense sources for this client
        // 3. Multi-tenancy constraints are respected
    }

    /**
     * Validate maximum active sources constraint (20 per client).
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateMaxActiveSources(\Illuminate\Validation\Validator $validator): void
    {
        $clientId = $this->input('client_id');
        $expenseSource = $this->route('expense_source') ?? $this->route('id');
        $expenseSourceId = $expenseSource ? $expenseSource->id : null;
        
        if (!$clientId) {
            return;
        }

        // Count active sources for the client (excluding current record being updated)
        $activeSourcesCount = PocketExpenseSourceClientConfig::where('client_id', $clientId)
            ->where('deleted', false)
            ->when($expenseSourceId, function ($query) use ($expenseSourceId) {
                return $query->where('id', '!=', $expenseSourceId);
            })
            ->count();

        // Check if updating would exceed the limit (if we're changing from deleted to active)
        $currentSource = $expenseSource instanceof PocketExpenseSourceClientConfig ? $expenseSource : null;
        $isCurrentlyDeleted = $currentSource && $currentSource->deleted;
        $wouldBeActive = !$this->input('deleted', false); // Default to active if not specified

        if ($isCurrentlyDeleted && $wouldBeActive && $activeSourcesCount >= 20) {
            $validator->errors()->add('client_id', 'Cannot have more than 20 active expense sources per client.');
        }
    }

    /**
     * Validate global 'Other' record restrictions.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateGlobalOtherRestrictions(\Illuminate\Validation\Validator $validator): void
    {
        $expenseSource = $this->route('expense_source') ?? $this->route('id');
        
        if (!($expenseSource instanceof PocketExpenseSourceClientConfig)) {
            return;
        }

        // Global 'Other' record cannot be edited per system constraints
        if ($expenseSource->isGlobalOther()) {
            $validator->errors()->add('name', 'Global Other record cannot be edited.');
            $validator->errors()->add('client_id', 'Global Other record cannot be modified.');
        }
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure client_id is set from the expense source if not provided
        $expenseSource = $this->route('expense_source') ?? $this->route('id');
        
        if ($expenseSource instanceof PocketExpenseSourceClientConfig && !$this->has('client_id')) {
            $this->merge([
                'client_id' => $expenseSource->client_id,
            ]);
        }

        // Trim whitespace from name
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }

        // Set default value for is_default if not provided
        if (!$this->has('is_default')) {
            $this->merge([
                'is_default' => false,
            ]);
        }
    }

    /**
     * Get validated data with appropriate casting.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();

        // Ensure proper casting
        if (isset($validated['is_default'])) {
            $validated['is_default'] = (bool) $validated['is_default'];
        }

        if (isset($validated['client_id'])) {
            $validated['client_id'] = (int) $validated['client_id'];
        }

        return $validated;
    }
}