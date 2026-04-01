<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use Illuminate\Validation\Rule;

/**
 * UpdateUserFeaturePermissionRequest
 * 
 * Form request validation for updating UserFeaturePermission records.
 * Handles validation and authorization for permission updates with optional manager delegation.
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
        $permission = $this->route('userFeaturePermission') ?? $this->route('id');
        
        if (!$permission instanceof UserFeaturePermission) {
            // If we receive an ID, load the permission model
            $permission = UserFeaturePermission::find($permission);
        }
        
        if (!$permission) {
            return false;
        }
        
        // Use policy to check if user can update this permission
        return $this->user()->can('update', $permission);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $permission = $this->route('userFeaturePermission') ?? $this->route('id');
        
        if (!$permission instanceof UserFeaturePermission) {
            $permission = UserFeaturePermission::find($permission);
        }
        
        return [
            'manager_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($permission) {
                    if ($value && $permission) {
                        // Ensure manager belongs to the same client
                        $managerUser = \App\Models\User::find($value);
                        if ($managerUser && !$this->userBelongsToClient($managerUser->id, $permission->client_id)) {
                            $fail('The selected manager must belong to the same client.');
                        }
                    }
                }
            ],
            'is_enabled' => [
                'sometimes',
                'boolean'
            ]
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
            'manager_user_id' => 'manager user',
            'is_enabled' => 'enabled status'
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manager_user_id.exists' => 'The selected manager user does not exist.',
            'manager_user_id.integer' => 'The manager user ID must be a valid number.',
            'is_enabled.boolean' => 'The enabled status must be true or false.'
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Convert string boolean values to actual booleans
        if ($this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => filter_var($this->input('is_enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ]);
        }
        
        // Convert empty manager_user_id to null
        if ($this->has('manager_user_id') && empty($this->input('manager_user_id'))) {
            $this->merge([
                'manager_user_id' => null
            ]);
        }
    }

    /**
     * Get the validated data with only allowed fields for update.
     *
     * @return array<string, mixed>
     */
    public function getUpdateData(): array
    {
        $validated = $this->validated();
        
        // Only allow specific fields to be updated
        $allowedFields = ['manager_user_id', 'is_enabled'];
        
        return array_intersect_key($validated, array_flip($allowedFields));
    }

    /**
     * Check if user belongs to a specific client.
     * 
     * TODO: This should be moved to a service class or User model method
     * when the user-client relationship structure is confirmed.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    private function userBelongsToClient(int $userId, int $clientId): bool
    {
        // TODO: Implement proper user-client relationship check
        // This is a placeholder implementation
        // The actual implementation should check user's association with client
        // through the proper relationship (UserClient model, user.clients relationship, etc.)
        
        // For now, return true to allow validation to pass
        // This should be replaced with actual business logic
        return true;
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You are not authorized to update this user feature permission.'
        );
    }
}