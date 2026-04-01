<?php

namespace App\Http\Requests;

use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpenseSourcePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Pocket Expense Source Request
 * 
 * Validates update operations for pocket expense source configurations.
 * Enforces unique source names per client and validates business rules.
 */
class UpdatePocketExpenseSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $source = $this->route('pocket_expense_source') ?? $this->route('id');
        
        if (!$source instanceof PocketExpenseSourceClientConfig) {
            $source = PocketExpenseSourceClientConfig::find($source);
        }
        
        if (!$source) {
            return false;
        }
        
        return $this->user()->can('update', $source);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $source = $this->route('pocket_expense_source') ?? $this->route('id');
        $sourceId = $source instanceof PocketExpenseSourceClientConfig ? $source->id : $source;
        
        // Get the current source to check client_id
        $currentSource = PocketExpenseSourceClientConfig::find($sourceId);
        $clientId = $currentSource ? $currentSource->client_id : null;
        
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('pocket_expense_source_client_config', 'name')
                    ->where('client_id', $clientId)
                    ->ignore($sourceId)
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
            'name.required' => 'The source name is required.',
            'name.string' => 'The source name must be a string.',
            'name.max' => 'The source name may not be greater than 100 characters.',
            'name.unique' => 'A source with this name already exists for this client.',
            'is_default.boolean' => 'The is_default field must be true or false.'
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
            $source = $this->route('pocket_expense_source') ?? $this->route('id');
            
            if (!$source instanceof PocketExpenseSourceClientConfig) {
                $source = PocketExpenseSourceClientConfig::find($source);
            }
            
            if (!$source) {
                $validator->errors()->add('source', 'Source not found.');
                return;
            }
            
            // Prevent editing global 'Other' record as per system constraints
            if ($source->isGlobalOther()) {
                $validator->errors()->add('source', 'The global Other source cannot be edited.');
                return;
            }
            
            // Validate client context - user must belong to same client as source
            $userClientId = $this->user()->client_id ?? null;
            if ($source->client_id && $userClientId !== $source->client_id) {
                $validator->errors()->add('client', 'You can only update sources for your own client.');
                return;
            }
        });
    }

    /**
     * Get validated data with defaults applied.
     *
     * @return array
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Apply defaults for optional fields
        if (!isset($validated['is_default'])) {
            $validated['is_default'] = false;
        }
        
        return $validated;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean values to actual booleans
        if ($this->has('is_default')) {
            $this->merge([
                'is_default' => filter_var($this->input('is_default'), FILTER_VALIDATE_BOOLEAN)
            ]);
        }
        
        // Trim whitespace from name
        if ($this->has('name')) {
            $this->merge([
                'name' => trim($this->input('name'))
            ]);
        }
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Validation\ValidationException($validator, response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422));
    }
}