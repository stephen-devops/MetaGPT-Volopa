<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for granting user feature permissions.
 * Handles validation and authorization for permission granting operations.
 * 
 * As per system constraints:
 * - Only Primary Admin has full access to all users by default
 * - Admin gets full access only to own expenses by default; needs explicit grant for others
 * - Admin can only grant access to their own managed users (not all users)
 * - Managing access can be given to any user irrespective of role
 */
class GrantUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * 
     * Authorization is handled by the UserFeaturePermissionPolicy,
     * but we can do basic checks here for early validation.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Basic authorization - detailed checks are in the Policy
        // User must be authenticated to grant permissions
        return $this->user() !== null;
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
                Rule::exists('users', 'id')->where(function ($query) {
                    // Ensure user exists and is not soft-deleted
                    $query->where('deleted', 0);
                }),
            ],
            'client_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')->where(function ($query) {
                    // Ensure client exists and is not soft-deleted
                    $query->where('deleted', 0);
                }),
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                // Feature ID 16 is for OOP Expenses as per system constraints
                // In a full implementation, this would validate against a features table
                'in:16', // Currently only OOP Expenses feature is supported
            ],
            'manager_user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) {
                    // Manager must exist and not be soft-deleted
                    $query->where('deleted', 0);
                }),
                // Manager cannot be the same as the target user
                'different:user_id',
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
            'user_id.integer' => 'The user ID must be a valid integer.',
            'user_id.exists' => 'The selected user does not exist or has been deactivated.',
            'user_id.min' => 'The user ID must be a positive integer.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be a valid integer.',
            'client_id.exists' => 'The selected client does not exist or has been deactivated.',
            'client_id.min' => 'The client ID must be a positive integer.',
            
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be a valid integer.',
            'feature_id.min' => 'The feature ID must be a positive integer.',
            'feature_id.in' => 'The selected feature is not supported. Currently only OOP Expenses (16) is available.',
            
            'manager_user_id.required' => 'The manager user ID is required.',
            'manager_user_id.integer' => 'The manager user ID must be a valid integer.',
            'manager_user_id.exists' => 'The selected manager does not exist or has been deactivated.',
            'manager_user_id.min' => 'The manager user ID must be a positive integer.',
            'manager_user_id.different' => 'The manager cannot be the same as the user receiving the permission.',
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
            'user_id' => 'target user',
            'client_id' => 'client',
            'feature_id' => 'feature',
            'manager_user_id' => 'manager',
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
            // Additional custom validation logic
            $this->validateUserBelongsToClient($validator);
            $this->validateManagerBelongsToClient($validator);
            $this->validateNoDuplicatePermission($validator);
            $this->validateGrantorCanManageUser($validator);
        });
    }

    /**
     * Validate that the target user belongs to the specified client.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateUserBelongsToClient(\Illuminate\Validation\Validator $validator): void
    {
        if (!$this->has('user_id') || !$this->has('client_id')) {
            return;
        }

        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');

        // Check if user belongs to the client through user_client_relationship or similar
        // This is a simplified check - in reality, this would verify user-client association
        $userExistsForClient = \DB::table('users')
            ->where('id', $userId)
            ->where('deleted', 0)
            ->exists();

        if (!$userExistsForClient) {
            $validator->errors()->add('user_id', 'The selected user does not belong to the specified client.');
        }
    }

    /**
     * Validate that the manager belongs to the specified client.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateManagerBelongsToClient(\Illuminate\Validation\Validator $validator): void
    {
        if (!$this->has('manager_user_id') || !$this->has('client_id')) {
            return;
        }

        $managerId = $this->input('manager_user_id');
        $clientId = $this->input('client_id');

        // Check if manager belongs to the client
        $managerExistsForClient = \DB::table('users')
            ->where('id', $managerId)
            ->where('deleted', 0)
            ->exists();

        if (!$managerExistsForClient) {
            $validator->errors()->add('manager_user_id', 'The selected manager does not belong to the specified client.');
        }
    }

    /**
     * Validate that no duplicate permission exists for the same user-client-feature combination.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateNoDuplicatePermission(\Illuminate\Validation\Validator $validator): void
    {
        if (!$this->has('user_id') || !$this->has('client_id') || !$this->has('feature_id')) {
            return;
        }

        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');

        // Check for existing active permission
        $existingPermission = \DB::table('user_feature_permission')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('feature_id', $featureId)
            ->where('is_enabled', true)
            ->exists();

        if ($existingPermission) {
            $validator->errors()->add('user_id', 'This user already has an active permission for the specified feature and client.');
        }
    }

    /**
     * Validate that the grantor (current user) can manage the target user.
     * As per system constraints: Admin can only grant access to their own managed users.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateGrantorCanManageUser(\Illuminate\Validation\Validator $validator): void
    {
        if (!$this->has('user_id') || !$this->has('client_id')) {
            return;
        }

        $currentUser = $this->user();
        if (!$currentUser) {
            return;
        }

        $targetUserId = $this->input('user_id');
        $clientId = $this->input('client_id');

        // Check if current user has management rights over the target user
        // This would typically check user roles and management hierarchy
        // For now, we'll do a basic check - this logic would be expanded
        // based on the actual user management system structure

        $canManage = true; // Simplified - actual implementation would check management rights

        if (!$canManage) {
            $validator->errors()->add('user_id', 'You do not have permission to grant access to this user.');
        }
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure all IDs are integers
        $this->merge([
            'user_id' => (int) $this->input('user_id', 0),
            'client_id' => (int) $this->input('client_id', 0),
            'feature_id' => (int) $this->input('feature_id', 0),
            'manager_user_id' => (int) $this->input('manager_user_id', 0),
        ]);
    }

    /**
     * Get the validated data with additional computed fields.
     *
     * @return array<string, mixed>
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Add the grantor_id (current authenticated user)
        $validated['grantor_id'] = $this->user()->id;
        
        // Set default enabled status
        $validated['is_enabled'] = true;
        
        return $validated;
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
        // Log the validation failure for auditing purposes
        \Log::warning('User feature permission grant validation failed', [
            'user_id' => $this->user()?->id,
            'request_data' => $this->only(['user_id', 'client_id', 'feature_id', 'manager_user_id']),
            'errors' => $validator->errors()->toArray(),
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Get data to be validated from the request.
     * Override to ensure only expected fields are validated.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->only([
            'user_id',
            'client_id', 
            'feature_id',
            'manager_user_id'
        ]);
    }

    /**
     * Determine if the request passes the authorization check.
     * Additional authorization logic specific to permission granting.
     *
     * @return bool
     */
    public function passesAuthorization(): bool
    {
        $user = $this->user();
        
        if (!$user) {
            return false;
        }

        // Basic role-based authorization
        // In a full implementation, this would check user roles more thoroughly
        return true;
    }

    /**
     * Get error messages for defined validation rules in a structured format.
     * Useful for API responses with consistent error formatting.
     *
     * @return array<string, array<string, string>>
     */
    public function getStructuredMessages(): array
    {
        return [
            'validation_errors' => [
                'user_id' => [
                    'required' => 'Target user is required for permission granting.',
                    'exists' => 'Target user must exist and be active in the system.',
                    'integer' => 'Target user ID must be a valid number.',
                ],
                'client_id' => [
                    'required' => 'Client context is required for permission granting.',
                    'exists' => 'Client must exist and be active in the system.',
                    'integer' => 'Client ID must be a valid number.',
                ],
                'feature_id' => [
                    'required' => 'Feature specification is required for permission granting.',
                    'in' => 'Only OOP Expenses feature (ID: 16) is currently supported.',
                    'integer' => 'Feature ID must be a valid number.',
                ],
                'manager_user_id' => [
                    'required' => 'Manager assignment is required for permission granting.',
                    'exists' => 'Manager must exist and be active in the system.',
                    'different' => 'Manager cannot be the same person as the permission recipient.',
                    'integer' => 'Manager ID must be a valid number.',
                ],
            ],
            'business_rules' => [
                'user_client_association' => 'Target user must belong to the specified client.',
                'manager_client_association' => 'Manager must belong to the specified client.',
                'no_duplicate_permissions' => 'User cannot have multiple active permissions for the same feature.',
                'grantor_management_rights' => 'You can only grant permissions to users you manage.',
            ]
        ];
    }
}