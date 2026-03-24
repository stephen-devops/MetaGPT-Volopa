<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\UserFeaturePermission;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

/**
 * Form Request for storing User Feature Permission
 * 
 * Handles validation and authorization for granting feature permissions to users.
 * Validates user relationships, feature enablement, and grantor permissions
 * according to the user management hierarchy and platform constraints.
 */
class StoreUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * 
     * Uses the UserFeaturePermissionPolicy to check if the authenticated user
     * can create feature permissions based on their role and management rights.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', UserFeaturePermission::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $authenticatedUser = Auth::user();
        $clientId = $authenticatedUser->client_id ?? 1; // Default to client 1 if not set

        return [
            // Target user receiving the permission
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($clientId) {
                    // Verify target user belongs to same client
                    $targetUser = \App\Models\User::find($value);
                    if (!$targetUser || $targetUser->client_id !== $clientId) {
                        $fail('The selected user must belong to the same client.');
                    }
                },
            ],
            
            // Feature being granted (OOP Expense = 16)
            'feature_id' => [
                'required',
                'integer',
                'exists:features,id',
                function ($attribute, $value, $fail) {
                    // Validate that this is the OOP Expense feature (ID 16) as per constraints
                    if ($value !== 16) {
                        $fail('Invalid feature ID. Only OOP Expense feature permissions can be granted through this endpoint.');
                    }
                },
            ],
            
            // Optional manager user who can manage the target user
            'manager_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                'different:user_id', // Manager cannot be the same as target user
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value !== null) {
                        // Verify manager belongs to same client
                        $managerUser = \App\Models\User::find($value);
                        if (!$managerUser || $managerUser->client_id !== $clientId) {
                            $fail('The selected manager must belong to the same client.');
                        }
                    }
                },
            ],
            
            // Permission enabled state (defaults to true)
            'is_enabled' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     * 
     * Adds the authenticated user as grantor_id and ensures client_id context.
     * Sets default values for optional fields before validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $authenticatedUser = Auth::user();
        
        $this->merge([
            // Add grantor as the authenticated user
            'grantor_id' => $authenticatedUser->id,
            
            // Add client context for multi-tenancy
            'client_id' => $authenticatedUser->client_id ?? 1,
            
            // Set default enabled state if not provided
            'is_enabled' => $this->input('is_enabled', true),
            
            // Ensure feature_id defaults to OOP Expense if not provided
            'feature_id' => $this->input('feature_id', 16),
        ]);
    }

    /**
     * Get the validated data with additional context fields.
     * 
     * Returns the validated input merged with system-generated fields
     * like timestamps and client context for the service layer.
     *
     * @return array
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);
        
        // Add system fields that aren't part of user input validation
        $validated['grantor_id'] = $this->input('grantor_id');
        $validated['client_id'] = $this->input('client_id');
        $validated['is_enabled'] = $this->boolean('is_enabled', true) ? 1 : 0;
        
        // Add Volopa legacy timestamp fields
        $now = now();
        $validated['create_time'] = $now;
        $validated['update_time'] = $now;
        
        return $validated;
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'A target user must be specified.',
            'user_id.exists' => 'The specified user does not exist.',
            'feature_id.required' => 'A feature must be specified.',
            'feature_id.exists' => 'The specified feature does not exist.',
            'manager_user_id.exists' => 'The specified manager user does not exist.',
            'manager_user_id.different' => 'The manager cannot be the same as the target user.',
            'is_enabled.boolean' => 'The enabled status must be true or false.',
        ];
    }

    /**
     * Get custom attribute names for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'target user',
            'feature_id' => 'feature',
            'manager_user_id' => 'manager user',
            'is_enabled' => 'enabled status',
        ];
    }

    /**
     * Handle a failed validation attempt.
     * 
     * Ensures that validation failures return proper API error format
     * with detailed field-level error messages.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Let parent handle the validation exception with proper API formatting
        parent::failedValidation($validator);
    }

    /**
     * Handle a failed authorization attempt.
     * 
     * Ensures that authorization failures return 403 Forbidden status
     * with appropriate error message for API consumers.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You are not authorized to grant feature permissions. Only Primary Admins and users with management rights can grant permissions to their managed users.'
        );
    }

    /**
     * Configure the validator instance.
     * 
     * Adds additional validation logic that requires access to the full
     * validator context, such as checking for duplicate permissions.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $userId = $this->input('user_id');
            $clientId = $this->input('client_id');
            $featureId = $this->input('feature_id');

            // Check for existing permission to prevent duplicates
            if ($userId && $clientId && $featureId) {
                $existingPermission = UserFeaturePermission::where('user_id', $userId)
                    ->where('client_id', $clientId)
                    ->where('feature_id', $featureId)
                    ->first();

                if ($existingPermission) {
                    $validator->errors()->add('user_id', 'This user already has permission for the specified feature in this client context.');
                }
            }

            // Additional business logic validation can be added here
            // For example, checking if the client has the OOP Expense feature enabled
            // This would require access to ClientFeatures model when available
        });
    }

    /**
     * Get the user ID of the target user receiving the permission.
     * 
     * Convenience method for the service layer to access the validated
     * target user ID.
     *
     * @return int
     */
    public function getTargetUserId(): int
    {
        return (int) $this->input('user_id');
    }

    /**
     * Get the feature ID being granted.
     * 
     * Convenience method for the service layer to access the validated
     * feature ID.
     *
     * @return int
     */
    public function getFeatureId(): int
    {
        return (int) $this->input('feature_id');
    }

    /**
     * Get the manager user ID if provided.
     * 
     * Convenience method for the service layer to access the validated
     * manager user ID.
     *
     * @return int|null
     */
    public function getManagerUserId(): ?int
    {
        $managerId = $this->input('manager_user_id');
        return $managerId !== null ? (int) $managerId : null;
    }

    /**
     * Get the grantor user ID (authenticated user).
     * 
     * Convenience method for the service layer to access the grantor
     * user ID.
     *
     * @return int
     */
    public function getGrantorId(): int
    {
        return (int) $this->input('grantor_id');
    }

    /**
     * Get the client ID context.
     * 
     * Convenience method for the service layer to access the client
     * context for multi-tenancy.
     *
     * @return int
     */
    public function getClientId(): int
    {
        return (int) $this->input('client_id');
    }

    /**
     * Check if the permission should be enabled.
     * 
     * Convenience method for the service layer to check the enabled
     * status as a boolean.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->boolean('is_enabled', true);
    }
}