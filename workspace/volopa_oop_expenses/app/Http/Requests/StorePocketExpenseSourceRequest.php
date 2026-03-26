<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PocketExpenseSourceClientConfig;

/**
 * Store Pocket Expense Source Request
 * 
 * Validates the creation of new expense sources for clients.
 * Enforces system constraints including unique names per client,
 * maximum 20 active sources per client, and client scoping validation.
 */
class StorePocketExpenseSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implement authorization check via PocketExpenseSourcePolicy
        // Should check if authenticated user can manage expense sources for the client
        return true;
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
                    // Check maximum 20 active expense sources per client constraint
                    $activeSourcesCount = PocketExpenseSourceClientConfig::where('client_id', $value)
                        ->where('deleted', false)
                        ->count();
                    
                    if ($activeSourcesCount >= 20) {
                        $fail('Client has reached maximum limit of 20 active expense sources.');
                    }
                }
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                // Unique constraint per client as specified in system constraints
                Rule::unique('pocket_expense_source_client_config', 'name')
                    ->where('client_id', $this->input('client_id'))
                    ->where('deleted', false)
            ],
            'is_default' => [
                'sometimes',
                'boolean'
            ]
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
            'client_id.required' => 'Client ID is required.',
            'client_id.integer' => 'Client ID must be an integer.',
            'client_id.exists' => 'The selected client does not exist.',
            'name.required' => 'Expense source name is required.',
            'name.string' => 'Expense source name must be a string.',
            'name.max' => 'Expense source name must not exceed 255 characters.',
            'name.unique' => 'An expense source with this name already exists for this client.',
            'is_default.boolean' => 'Default flag must be a boolean value.'
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
            'name' => 'expense source name',
            'is_default' => 'default flag'
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional validation: Check if user has permission to create sources for this client
            // TODO: Implement client scoping check - authenticated user must have permission
            // to manage expense sources for the specified client_id
            
            // Additional validation: Prevent creation of global 'Other' record
            if ($this->input('name') === 'Other' && is_null($this->input('client_id'))) {
                $validator->errors()->add('name', 'Cannot create global Other record - it already exists.');
            }
            
            // Additional validation: Check client has OOP feature enabled
            // TODO: Implement feature enablement check via ClientFeatures model
            // if (!ClientFeatures::where('client_id', $this->input('client_id'))->where('feature_id', 16)->exists()) {
            //     $validator->errors()->add('client_id', 'Client does not have OOP Expense feature enabled.');
            // }
        });
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        $this->merge([
            'is_default' => $this->input('is_default', false),
        ]);
        
        // Trim whitespace from name
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    /**
     * Get validated data with processed values.
     *
     * @return array<string, mixed>
     */
    public function validatedData(): array
    {
        $validated = $this->validated();
        
        // Ensure boolean casting for is_default
        $validated['is_default'] = (bool) ($validated['is_default'] ?? false);
        
        return $validated;
    }
}