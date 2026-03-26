<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;

/**
 * Store User Feature Permission Request
 * 
 * Handles validation for creating new user feature permissions.
 * Implements authorization via UserFeaturePermissionPolicy.
 * Validates unique constraint on user_id, client_id, feature_id combination.
 */
class StoreUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: Implement authorization check via UserFeaturePermissionPolicy
        // Should verify that the authenticated user can grant permissions
        // for the specified client and feature combination
        return $this->user()->can('create', [UserFeaturePermission::class, $this->input('client_id')]);
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
                'exists:users,id',
                // Validate unique constraint: user_id + client_id + feature_id must be unique
                Rule::unique('user_feature_permission')->where(function ($query) {
                    return $query->where('client_id', $this->input('client_id'))
                                ->where('feature_id', $this->input('feature_id'));
                }),
            ],
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'feature_id' => [
                'required',
                'integer',
                // TODO: Add validation for feature existence once features table is defined
                // For now, assuming OOP Expense feature_id = 16 as per system constraints
            ],
            'grantor_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'manager_user_id' => [
                'required',
                'integer',
                'exists:users,id',
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
            'user_id.exists' => 'The selected user does not exist.',
            'user_id.unique' => 'This user already has permission for this feature and client.',
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.exists' => 'The selected client does not exist.',
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be an integer.',
            'grantor_id.required' => 'The grantor ID is required.',
            'grantor_id.integer' => 'The grantor ID must be an integer.',
            'grantor_id.exists' => 'The selected grantor does not exist.',
            'manager_user_id.required' => 'The manager user ID is required.',
            'manager_user_id.integer' => 'The manager user ID must be an integer.',
            'manager_user_id.exists' => 'The selected manager user does not exist.',
            'is_enabled.boolean' => 'The enabled flag must be a boolean value.',
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
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            // TODO: Add custom validation logic here if needed
            // For example, validate that grantor has permission to grant access
            // to the specified user for the specified client and feature
            
            // TODO: Validate that manager_user_id is within grantor's managed users
            // Admin can only grant access to their own managed users (not all users)
            
            // TODO: Validate that target user belongs to the specified client
            // expense_user_id must belong to client_id constraint
            
            // TODO: Validate that client has OOP feature enabled
            // Client must have feature_id enabled through ClientFeatures or similar
        });
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default values if not provided
        $this->merge([
            'is_enabled' => $this->input('is_enabled', true), // Default to enabled
            'grantor_id' => $this->input('grantor_id', $this->user()->id ?? null), // Default to current user
        ]);

        // TODO: Add any data preparation logic here
        // For example, ensure feature_id defaults to OOP Expense feature (16)
        // if not explicitly provided
        if (!$this->has('feature_id')) {
            $this->merge(['feature_id' => 16]); // OOP Expense feature ID
        }
    }

    /**
     * Get validated data with proper casting.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();

        // Ensure proper data types
        $validated['user_id'] = (int) $validated['user_id'];
        $validated['client_id'] = (int) $validated['client_id'];
        $validated['feature_id'] = (int) $validated['feature_id'];
        $validated['grantor_id'] = (int) $validated['grantor_id'];
        $validated['manager_user_id'] = (int) $validated['manager_user_id'];
        $validated['is_enabled'] = (bool) ($validated['is_enabled'] ?? true);

        return $validated;
    }
}