<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreUserFeaturePermissionRequest
 * 
 * Form Request for validating user feature permission creation.
 * Handles validation and authorization for granting user permissions.
 */
class StoreUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Authorization is handled by the controller and policy
        // This form request focuses on validation only
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
            'user_id' => [
                'required',
                'integer',
                'min:1',
                'exists:users,id',
            ],
            'client_id' => [
                'required',
                'integer',
                'min:1',
                'exists:clients,id',
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                'exists:features,id',
            ],
            'manager_user_id' => [
                'required',
                'integer',
                'min:1',
                'exists:users,id',
                'different:user_id', // Manager cannot be the same as the user being granted permission
            ],
            'is_enabled' => [
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
            'user_id.required' => 'The user ID is required.',
            'user_id.integer' => 'The user ID must be an integer.',
            'user_id.min' => 'The user ID must be at least 1.',
            'user_id.exists' => 'The specified user does not exist.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The specified client does not exist.',
            
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be an integer.',
            'feature_id.min' => 'The feature ID must be at least 1.',
            'feature_id.exists' => 'The specified feature does not exist.',
            
            'manager_user_id.required' => 'The manager user ID is required.',
            'manager_user_id.integer' => 'The manager user ID must be an integer.',
            'manager_user_id.min' => 'The manager user ID must be at least 1.',
            'manager_user_id.exists' => 'The specified manager user does not exist.',
            'manager_user_id.different' => 'The manager user cannot be the same as the user receiving the permission.',
            
            'is_enabled.boolean' => 'The enabled status must be true or false.',
        ];
    }

    /**
     * Get custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'user',
            'client_id' => 'client',
            'feature_id' => 'feature',
            'manager_user_id' => 'manager user',
            'is_enabled' => 'enabled status',
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
            // Additional validation: Check for duplicate permission
            $existingPermission = \App\Models\UserFeaturePermission::where([
                'user_id' => $this->user_id,
                'client_id' => $this->client_id,
                'feature_id' => $this->feature_id,
            ])->first();

            if ($existingPermission) {
                $validator->errors()->add('user_id', 'This user already has a permission record for this feature and client combination.');
            }

            // TODO: Add validation to check if the authenticated user (grantor) has permission 
            // to grant access to the specified user and client combination.
            // This requires integration with UserPermissionService.canManageUser()
            
            // TODO: Add validation to ensure the target user belongs to the specified client
            // This requires checking user-client relationship
        });
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default value for is_enabled if not provided
        if (!$this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => true,
            ]);
        }

        // Ensure integer casting for IDs
        if ($this->has('user_id')) {
            $this->merge([
                'user_id' => (int) $this->user_id,
            ]);
        }

        if ($this->has('client_id')) {
            $this->merge([
                'client_id' => (int) $this->client_id,
            ]);
        }

        if ($this->has('feature_id')) {
            $this->merge([
                'feature_id' => (int) $this->feature_id,
            ]);
        }

        if ($this->has('manager_user_id')) {
            $this->merge([
                'manager_user_id' => (int) $this->manager_user_id,
            ]);
        }
    }

    /**
     * Get the validated data with defaults applied.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();

        // Add the grantor_id from the authenticated user
        // TODO: Get authenticated user from OAuth2 middleware context
        // For now, this is a placeholder that should be set by the controller
        if (!isset($validated['grantor_id'])) {
            $validated['grantor_id'] = null; // Will be set by controller
        }

        return $validated;
    }
}