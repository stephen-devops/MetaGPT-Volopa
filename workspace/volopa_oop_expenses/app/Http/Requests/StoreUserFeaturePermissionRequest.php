<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use Illuminate\Validation\Rule;

/**
 * StoreUserFeaturePermissionRequest
 * 
 * Form request for validating user feature permission creation.
 * Handles validation and authorization for granting permissions to users.
 * Enforces business rules around permission delegation and client scoping.
 */
class StoreUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Use the UserFeaturePermissionPolicy to check if user can create permissions
        return $this->user()->can('create', UserFeaturePermission::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_user_id' => [
                'required',
                'integer',
                'exists:users,id',
                // Ensure target user belongs to the same client
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->whereNotNull('id'); // TODO: Add client relationship validation when User-Client relationship is confirmed
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
                'exists:features,id', // TODO: Replace with actual features table when confirmed
                // TODO: Add validation to ensure client has this feature enabled
            ],
            'manager_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                'different:target_user_id', // Manager cannot be the same as target user
                // TODO: Add validation that manager_user_id belongs to same client
            ],
            'is_enabled' => [
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
            'target_user_id.required' => 'Target user is required.',
            'target_user_id.exists' => 'The selected target user does not exist.',
            'client_id.required' => 'Client ID is required.',
            'client_id.exists' => 'The selected client does not exist.',
            'feature_id.required' => 'Feature ID is required.',
            'feature_id.exists' => 'The selected feature does not exist.',
            'manager_user_id.exists' => 'The selected manager user does not exist.',
            'manager_user_id.different' => 'Manager user cannot be the same as target user.',
            'is_enabled.boolean' => 'Is enabled must be true or false.',
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
            'target_user_id' => 'target user',
            'client_id' => 'client',
            'feature_id' => 'feature',
            'manager_user_id' => 'manager user',
            'is_enabled' => 'enabled status',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default values for optional fields
        $this->merge([
            'is_enabled' => $this->input('is_enabled', true), // Default to enabled
        ]);

        // Ensure grantor_id is set to the authenticated user (this will be handled in the service/controller)
        // as it's not a user input but derived from authentication context
    }

    /**
     * Get the validated data from the request.
     * Adds grantor_id from authenticated user context.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        // Add grantor_id from authenticated user
        $validated['grantor_id'] = $this->user()->id;

        // Map target_user_id to user_id for the model
        $validated['user_id'] = $validated['target_user_id'];
        unset($validated['target_user_id']);

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
        // Add any additional business rule validations here
        $this->validateBusinessRules($validator);

        parent::failedValidation($validator);
    }

    /**
     * Perform additional business rule validations.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     */
    protected function validateBusinessRules(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // TODO: Add business rule validation:
        // - Check if authenticated user has permission to grant access to target_user_id
        // - Verify client has OOP Expense feature enabled (feature_id = 16)
        // - Ensure no duplicate permission exists for user_id + client_id + feature_id combination
        // - Validate that manager_user_id (if provided) can manage target_user_id within client context
        
        // Placeholder for unique permission check
        if ($this->has(['target_user_id', 'client_id', 'feature_id'])) {
            $existingPermission = UserFeaturePermission::where([
                'user_id' => $this->input('target_user_id'),
                'client_id' => $this->input('client_id'),
                'feature_id' => $this->input('feature_id'),
            ])->first();

            if ($existingPermission) {
                $validator->errors()->add('target_user_id', 'This user already has permission for this feature in this client.');
            }
        }
    }

    /**
     * Get the client ID for scoping validation.
     *
     * @return int|null
     */
    public function getClientId(): ?int
    {
        return $this->input('client_id');
    }

    /**
     * Get the target user ID.
     *
     * @return int|null
     */
    public function getTargetUserId(): ?int
    {
        return $this->input('target_user_id');
    }

    /**
     * Get the feature ID.
     *
     * @return int|null
     */
    public function getFeatureId(): ?int
    {
        return $this->input('feature_id');
    }

    /**
     * Check if a manager is being assigned.
     *
     * @return bool
     */
    public function hasManager(): bool
    {
        return $this->filled('manager_user_id');
    }

    /**
     * Check if the permission should be enabled.
     *
     * @return bool
     */
    public function shouldBeEnabled(): bool
    {
        return $this->input('is_enabled', true);
    }
}