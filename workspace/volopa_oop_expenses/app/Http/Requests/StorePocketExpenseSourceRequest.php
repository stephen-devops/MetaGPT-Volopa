<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StorePocketExpenseSourceRequest
 * 
 * Handles validation for creating new expense sources.
 * Validates source name uniqueness per client and enforces constraints.
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
        // TODO: Implement authorization logic using PocketExpenseSourceClientConfigPolicy
        // Should check if authenticated user can create expense sources for the client
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('pocket_expense_source_client_config', 'name')
                    ->where('client_id', $this->input('client_id'))
                    ->where('deleted', 0), // Only check against active sources
            ],
            'is_default' => [
                'sometimes',
                'boolean',
            ],
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
            'is_default' => 'default source flag',
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
            'name.unique' => 'An expense source with this name already exists for this client.',
            'name.max' => 'The expense source name may not be greater than 100 characters.',
            'client_id.required' => 'A client must be specified.',
            'client_id.exists' => 'The selected client does not exist.',
        ];
    }

    /**
     * Prepare the data for validation.
     * 
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Trim whitespace from name field
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name'))
            ]);
        }

        // Set default value for is_default if not provided
        if (!$this->has('is_default')) {
            $this->merge([
                'is_default' => false
            ]);
        }
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
            // Check maximum 20 active expense sources per client constraint
            if ($this->input('client_id')) {
                $activeSourcesCount = \App\Models\PocketExpenseSourceClientConfig::where('client_id', $this->input('client_id'))
                    ->where('deleted', 0)
                    ->count();
                    
                if ($activeSourcesCount >= 20) {
                    $validator->errors()->add('client_id', 'This client already has the maximum of 20 active expense sources.');
                }
            }

            // Prevent creating sources with reserved names that conflict with global sources
            if ($this->input('name') === 'Other') {
                $validator->errors()->add('name', 'The name "Other" is reserved for the global expense source.');
            }
        });
    }

    /**
     * Get the validated data with additional processing.
     *
     * @return array
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);
        
        // Ensure boolean conversion for is_default
        if (isset($validated['is_default'])) {
            $validated['is_default'] = (int) $validated['is_default'];
        }
        
        return $validated;
    }
}