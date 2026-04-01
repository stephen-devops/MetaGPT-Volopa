<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpenseSourcePolicy;
use Illuminate\Validation\Rule;

/**
 * StorePocketExpenseSourceRequest
 * 
 * Form request validation for creating PocketExpenseSourceClientConfig.
 * Enforces unique source names per client and validates against system constraints.
 * Maximum 20 active expense sources per client.
 */
class StorePocketExpenseSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Use PocketExpenseSourcePolicy to check create permission
        return $this->user()->can('create', PocketExpenseSourceClientConfig::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
                function ($attribute, $value, $fail) {
                    // Check if client has reached maximum 20 active sources
                    $activeSourcesCount = PocketExpenseSourceClientConfig::active()
                        ->forClient($value)
                        ->count();
                    
                    if ($activeSourcesCount >= 20) {
                        $fail('Client has reached maximum limit of 20 active expense sources.');
                    }
                },
            ],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pocket_expense_source_client_config')
                    ->where('client_id', $this->input('client_id'))
                    ->where('deleted', 0), // Only check against non-deleted sources
            ],
            'is_default' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Client ID is required.',
            'client_id.integer' => 'Client ID must be an integer.',
            'client_id.exists' => 'Selected client does not exist.',
            'name.required' => 'Source name is required.',
            'name.string' => 'Source name must be a string.',
            'name.max' => 'Source name may not be greater than 100 characters.',
            'name.unique' => 'Source name already exists for this client.',
            'is_default.boolean' => 'Default flag must be true or false.',
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
            'client_id' => 'client',
            'name' => 'source name',
            'is_default' => 'default status',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default value for is_default if not provided
        if (!$this->has('is_default')) {
            $this->merge([
                'is_default' => false,
            ]);
        }

        // Ensure client_id is from the authenticated user's context
        // This should be validated against user's accessible clients in the policy
        if ($this->has('client_id')) {
            $this->merge([
                'client_id' => (int) $this->input('client_id'),
            ]);
        }
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
            // Additional validation: Prevent creating global 'Other' source
            if ($this->input('name') === 'Other' && is_null($this->input('client_id'))) {
                $validator->errors()->add('name', 'Global "Other" source cannot be created through this endpoint.');
            }

            // Additional validation: Check if user can manage this client
            $clientId = $this->input('client_id');
            if ($clientId && !$this->userCanManageClient($clientId)) {
                $validator->errors()->add('client_id', 'You do not have permission to manage sources for this client.');
            }
        });
    }

    /**
     * Check if the authenticated user can manage the specified client.
     * 
     * TODO: Implement actual client access checking based on user permissions
     * This should verify that the user has management rights for the client
     *
     * @param int $clientId
     * @return bool
     */
    private function userCanManageClient(int $clientId): bool
    {
        // TODO: Implement proper client access validation
        // This should check user's client access permissions
        // For now, return true as placeholder
        return true;
    }
}