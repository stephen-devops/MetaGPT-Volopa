<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateUserFeaturePermissionRequest
 * 
 * Validates requests for updating user feature permissions.
 * Handles permission updates including enabling/disabling and changing managed user assignments.
 * Enforces RBAC constraints and delegation hierarchy rules.
 */
class UpdateUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // TODO: Implement authorization logic based on UserFeaturePermissionPolicy
        // Should check if authenticated user can update the target permission
        // considering delegation hierarchy and client scoping
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        // Get the permission being updated from route parameters
        $permission = $this->route('permission');
        $permissionId = $permission ? $permission->id : null;

        return [
            'user_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
            ],
            'client_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:clients,id',
            ],
            'feature_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:features,id',
            ],
            'grantor_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
            ],
            'manager_user_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:users,id',
            ],
            'is_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID is required.',
            'user_id.integer' => 'User ID must be an integer.',
            'user_id.exists' => 'The selected user does not exist.',
            
            'client_id.required' => 'Client ID is required.',
            'client_id.integer' => 'Client ID must be an integer.',
            'client_id.exists' => 'The selected client does not exist.',
            
            'feature_id.required' => 'Feature ID is required.',
            'feature_id.integer' => 'Feature ID must be an integer.',
            'feature_id.exists' => 'The selected feature does not exist.',
            
            'grantor_id.required' => 'Grantor ID is required.',
            'grantor_id.integer' => 'Grantor ID must be an integer.',
            'grantor_id.exists' => 'The selected grantor user does not exist.',
            
            'manager_user_id.required' => 'Manager user ID is required.',
            'manager_user_id.integer' => 'Manager user ID must be an integer.',
            'manager_user_id.exists' => 'The selected manager user does not exist.',
            
            'is_enabled.required' => 'Enabled status is required.',
            'is_enabled.boolean' => 'Enabled status must be true or false.',
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
            'user_id' => 'user',
            'client_id' => 'client',
            'feature_id' => 'feature',
            'grantor_id' => 'grantor',
            'manager_user_id' => 'managed user',
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
            $this->validateBusinessRules($validator);
        });
    }

    /**
     * Validate business rules for permission updates.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateBusinessRules($validator): void
    {
        // TODO: Implement business rule validation
        // 1. Verify that if user_id/client_id/feature_id are being changed,
        //    the unique constraint (user_id, client_id, feature_id) is not violated
        // 2. Validate that the grantor_id has permission to grant/modify this permission
        // 3. Ensure manager_user_id belongs to the same client_id
        // 4. Verify delegation hierarchy rules per RBAC constraints
        // 5. Check that the authenticated user has authority to update this permission
        
        // Example validation placeholder:
        if ($this->filled(['user_id', 'client_id', 'feature_id'])) {
            // Check for unique constraint violation
            // This should query UserFeaturePermission to ensure no duplicate exists
        }
        
        if ($this->filled('manager_user_id') && $this->filled('client_id')) {
            // Validate that manager_user_id belongs to client_id
            // This should verify the user-client relationship
        }
    }

    /**
     * Get the validated data from the request.
     * Only includes fields that are allowed to be updated.
     *
     * @return array
     */
    public function validatedForUpdate(): array
    {
        return $this->only([
            'user_id',
            'client_id', 
            'feature_id',
            'grantor_id',
            'manager_user_id',
            'is_enabled',
        ]);
    }

    /**
     * Prepare the data for validation.
     * Ensures proper data types and formats.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Convert string booleans to proper boolean values
        if ($this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => filter_var($this->is_enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        // Ensure integer types for ID fields
        $integerFields = ['user_id', 'client_id', 'feature_id', 'grantor_id', 'manager_user_id'];
        $data = [];
        
        foreach ($integerFields as $field) {
            if ($this->has($field) && $this->filled($field)) {
                $data[$field] = (int) $this->input($field);
            }
        }
        
        if (!empty($data)) {
            $this->merge($data);
        }
    }
}