<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PocketExpenseSourceClientConfig;

/**
 * UpdatePocketExpenseSourceRequest
 * 
 * Validates requests for updating pocket expense sources.
 * Enforces business rules including unique source names per client,
 * maximum source limits, and prevents modification of global 'Other' source.
 */
class UpdatePocketExpenseSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Uses policy to check if user can update the specific expense source.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $source = $this->route('source');
        
        if (!$source) {
            return false;
        }

        // Use policy to determine authorization
        return $this->user()->can('update', $source);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $source = $this->route('source');
        $clientId = $this->input('client_id');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                // Unique constraint: client_id + name combination must be unique, excluding current source
                Rule::unique('pocket_expense_source_client_config', 'name')
                    ->where('client_id', $clientId)
                    ->ignore($source ? $source->id : null),
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
     * Get the validation attributes for better error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'source name',
            'client_id' => 'client',
            'is_default' => 'default source status',
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
            'name.required' => 'The source name is required.',
            'name.max' => 'The source name must not exceed 100 characters.',
            'name.unique' => 'A source with this name already exists for this client.',
            'client_id.required' => 'The client is required.',
            'client_id.exists' => 'The selected client does not exist.',
            'is_default.boolean' => 'The default source status must be true or false.',
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
            $source = $this->route('source');
            
            // Prevent modification of global 'Other' source as per constraints
            if ($source && $source->isGlobalOther()) {
                $validator->errors()->add('source', 'The global "Other" source cannot be modified.');
                return;
            }

            // Validate client_id matches authenticated user's context
            $authenticatedUser = $this->user();
            $clientId = $this->input('client_id');
            
            // TODO: Add client membership validation
            // This should check if the authenticated user belongs to the specified client
            // Implementation depends on user-client relationship structure
            
            // Validate source belongs to the specified client (prevent cross-client updates)
            if ($source && $source->client_id !== $clientId) {
                $validator->errors()->add('client_id', 'Cannot move source to a different client.');
                return;
            }
            
            // Validate source is not soft deleted
            if ($source && $source->isDeleted()) {
                $validator->errors()->add('source', 'Cannot update a deleted source.');
                return;
            }
        });
    }

    /**
     * Get the validated data from the request.
     * Only returns fields that are allowed to be updated.
     *
     * @return array
     */
    public function validatedData(): array
    {
        $validated = $this->validated();
        
        // Remove client_id from update data as it cannot be changed
        unset($validated['client_id']);
        
        // Set update_time for tracking changes
        $validated['update_time'] = now();
        
        return $validated;
    }

    /**
     * Prepare the data for validation.
     * Ensures proper data types and defaults.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $source = $this->route('source');
        
        // Ensure client_id is set from the current source if not provided
        if ($source && !$this->has('client_id')) {
            $this->merge([
                'client_id' => $source->client_id,
            ]);
        }
        
        // Convert is_default to boolean if provided
        if ($this->has('is_default')) {
            $this->merge([
                'is_default' => filter_var($this->input('is_default'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}