<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\UserFeaturePermission;

/**
 * Update User Feature Permission Request
 * 
 * Handles validation and authorization for updating user feature permissions.
 * Implements RBAC constraints and multi-tenancy validation.
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
        // Authorization is handled by the UserFeaturePermissionPolicy
        // The policy will check if the authenticated user can update the specific permission
        $permission = $this->route('user_feature_permission');
        
        if (!$permission) {
            return false;
        }

        return $this->user()->can('update', $permission);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $permission = $this->route('user_feature_permission');
        
        return [
            'user_id' => [
                'sometimes',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($permission) {
                    // Ensure user belongs to the same client when updating user_id
                    if ($value && $permission && $this->filled('client_id')) {
                        // TODO: Add validation to check if user belongs to client
                        // This requires checking user-client relationship which is not defined in current context
                    }
                },
            ],
            'client_id' => [
                'sometimes',
                'integer',
                'exists:clients,id',
            ],
            'feature_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'grantor_id' => [
                'sometimes',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    // Grantor must be the authenticated user or someone they can act on behalf of
                    if ($value && $value !== $this->user()->id) {
                        // TODO: Add validation to check if current user can act as this grantor
                        // This requires checking delegation permissions
                    }
                },
            ],
            'manager_user_id' => [
                'sometimes',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($permission) {
                    // Manager must be within the scope of users the grantor can manage
                    if ($value && $permission) {
                        // TODO: Add validation to check if manager is within grantor's scope
                        // This requires checking management hierarchy
                    }
                },
            ],
            'is_enabled' => [
                'sometimes',
                'boolean',
            ],
        ];
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
            $permission = $this->route('user_feature_permission');
            
            if (!$permission) {
                $validator->errors()->add('permission', 'Permission not found.');
                return;
            }

            // Validate unique constraint if user_id, client_id, or feature_id is being updated
            if ($this->hasAny(['user_id', 'client_id', 'feature_id'])) {
                $userId = $this->input('user_id', $permission->user_id);
                $clientId = $this->input('client_id', $permission->client_id);
                $featureId = $this->input('feature_id', $permission->feature_id);

                $exists = UserFeaturePermission::where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->where('feature_id', $featureId)
                    ->where('id', '!=', $permission->id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('user_feature_permission', 'This permission combination already exists for the user.');
                }
            }

            // Additional business rule validations
            $this->validateBusinessRules($validator, $permission);
        });
    }

    /**
     * Validate business rules specific to permission updates.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  \App\Models\UserFeaturePermission  $permission
     * @return void
     */
    protected function validateBusinessRules($validator, $permission): void
    {
        // Rule: Admin can only grant access to their own managed users
        if ($this->filled('user_id') || $this->filled('manager_user_id')) {
            $targetUserId = $this->input('user_id', $permission->user_id);
            $managerUserId = $this->input('manager_user_id', $permission->manager_user_id);
            $currentUserId = $this->user()->id;

            // TODO: Implement validation for management hierarchy
            // This requires checking if currentUserId can manage targetUserId and managerUserId
            // Based on system constraints: "Admin can only grant access to their own managed users"
        }

        // Rule: Feature ID 16 represents OOP Expense feature per system constraints
        if ($this->filled('feature_id')) {
            $featureId = $this->input('feature_id');
            
            // TODO: Add validation to ensure feature_id is valid and enabled for the client
            // This requires checking client feature configuration
            if ($this->filled('client_id')) {
                $clientId = $this->input('client_id', $permission->client_id);
                // TODO: Validate that clientId has featureId enabled
            }
        }

        // Rule: Ensure grantor has permission to grant this specific feature
        if ($this->filled('grantor_id') || $this->filled('feature_id')) {
            $grantorId = $this->input('grantor_id', $permission->grantor_id);
            $featureId = $this->input('feature_id', $permission->feature_id);
            $clientId = $this->input('client_id', $permission->client_id);

            // TODO: Add validation to check if grantor has permission to grant this feature
            // This requires checking grantor's own permissions for the feature in the client context
        }
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.exists' => 'The selected user does not exist.',
            'client_id.exists' => 'The selected client does not exist.',
            'feature_id.integer' => 'The feature ID must be a valid integer.',
            'feature_id.min' => 'The feature ID must be at least 1.',
            'grantor_id.exists' => 'The selected grantor does not exist.',
            'manager_user_id.exists' => 'The selected manager does not exist.',
            'is_enabled.boolean' => 'The enabled status must be true or false.',
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
            'manager_user_id' => 'manager',
            'is_enabled' => 'enabled status',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure boolean values are properly cast
        if ($this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => filter_var($this->input('is_enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        // Ensure integer values are properly cast for foreign key fields
        $integerFields = ['user_id', 'client_id', 'feature_id', 'grantor_id', 'manager_user_id'];
        
        foreach ($integerFields as $field) {
            if ($this->has($field) && !is_null($this->input($field))) {
                $this->merge([
                    $field => (int) $this->input($field),
                ]);
            }
        }
    }

    /**
     * Handle a passed validation attempt.
     *
     * @return void
     */
    protected function passedValidation(): void
    {
        // Additional processing after validation passes
        // This could include logging or other business logic
        
        // TODO: Add audit logging for permission update attempts
        // This should log who is attempting to update which permission
    }
}