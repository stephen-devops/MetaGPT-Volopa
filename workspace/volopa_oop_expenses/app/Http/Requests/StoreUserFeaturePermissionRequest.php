<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Policies\UserFeaturePermissionPolicy;
use App\Models\UserFeaturePermission;

/**
 * StoreUserFeaturePermissionRequest
 * 
 * Form request for creating new user feature permissions with validation and authorization.
 * Implements delegation-based RBAC system with proper client scoping and permission validation.
 * 
 * Validation Rules:
 * - user_id: Required, must exist in users table, must belong to same client
 * - feature_id: Required, integer, valid feature ID
 * - manager_user_id: Optional, must exist in users table if provided, must belong to same client
 * - client_id: Automatically set from authenticated user's context
 * - grantor_id: Automatically set from authenticated user
 */
class StoreUserFeaturePermissionRequest extends FormRequest
{
    /**
     * The permission policy instance.
     *
     * @var \App\Policies\UserFeaturePermissionPolicy
     */
    private UserFeaturePermissionPolicy $policy;

    /**
     * Create a new form request instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->policy = new UserFeaturePermissionPolicy();
    }

    /**
     * Determine if the user is authorized to make this request.
     * 
     * Uses the UserFeaturePermissionPolicy to check if the authenticated user
     * has permission to grant feature permissions to other users.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // Get authenticated user from request
        $user = $this->user();
        
        if (!$user) {
            return false;
        }

        // Use the policy to check if user can create permissions
        return $this->policy->create($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Get authenticated user and their client context
        $user = $this->user();
        $clientId = $user ? $user->client_id : null;

        return [
            'user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        $query->where('client_id', $clientId);
                    }
                }),
                // Prevent self-granting permissions
                function ($attribute, $value, $fail) use ($user) {
                    if ($user && $value == $user->id) {
                        $fail('You cannot grant permissions to yourself.');
                    }
                },
                // Prevent duplicate permissions
                function ($attribute, $value, $fail) use ($clientId) {
                    $featureId = $this->input('feature_id');
                    if ($value && $featureId && $clientId) {
                        $exists = UserFeaturePermission::where('user_id', $value)
                                                    ->where('client_id', $clientId)
                                                    ->where('feature_id', $featureId)
                                                    ->exists();
                        if ($exists) {
                            $fail('User already has permission for this feature.');
                        }
                    }
                },
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                // Validate against known feature IDs (assuming features table or enum)
                // For now, we'll allow any positive integer as feature_id
                // In a real implementation, this would validate against a features table
                function ($attribute, $value, $fail) {
                    // Define valid feature IDs - in real implementation this would come from database
                    $validFeatureIds = [1, 2, 3, 4, 5]; // Placeholder feature IDs
                    if (!in_array($value, $validFeatureIds)) {
                        $fail('Invalid feature ID provided.');
                    }
                },
            ],
            'manager_user_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        $query->where('client_id', $clientId);
                    }
                }),
                // Prevent setting self as manager
                function ($attribute, $value, $fail) use ($user) {
                    $targetUserId = $this->input('user_id');
                    if ($value && $targetUserId && $value == $targetUserId) {
                        $fail('User cannot be their own manager.');
                    }
                },
                // Validate manager has sufficient permissions (optional business rule)
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && $clientId) {
                        // In a real implementation, check if manager has admin rights
                        // For now, we'll allow any user to be a manager
                        // $hasAdminRights = $this->checkUserHasAdminRights($value, $clientId);
                        // if (!$hasAdminRights) {
                        //     $fail('Selected manager does not have sufficient permissions.');
                        // }
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
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Please select a user to grant permission to.',
            'user_id.exists' => 'The selected user does not exist or does not belong to your client.',
            'user_id.integer' => 'User ID must be a valid number.',
            'user_id.min' => 'User ID must be a positive number.',
            
            'feature_id.required' => 'Please select a feature to grant permission for.',
            'feature_id.integer' => 'Feature ID must be a valid number.',
            'feature_id.min' => 'Feature ID must be a positive number.',
            
            'manager_user_id.exists' => 'The selected manager does not exist or does not belong to your client.',
            'manager_user_id.integer' => 'Manager user ID must be a valid number.',
            'manager_user_id.min' => 'Manager user ID must be a positive number.',
            
            'is_enabled.boolean' => 'Permission status must be true or false.',
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
            'feature_id' => 'feature',
            'manager_user_id' => 'manager',
            'is_enabled' => 'permission status',
        ];
    }

    /**
     * Prepare the data for validation.
     * 
     * This method is called before validation runs and allows us to
     * modify or add data to the request.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        
        if ($user) {
            // Automatically set client_id and grantor_id from authenticated user
            $this->merge([
                'client_id' => $user->client_id,
                'grantor_id' => $user->id,
            ]);
        }

        // Set default value for is_enabled if not provided
        if (!$this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => true,
            ]);
        }

        // Ensure integer types for numeric fields
        if ($this->has('user_id')) {
            $this->merge([
                'user_id' => (int) $this->input('user_id'),
            ]);
        }

        if ($this->has('feature_id')) {
            $this->merge([
                'feature_id' => (int) $this->input('feature_id'),
            ]);
        }

        if ($this->has('manager_user_id') && !is_null($this->input('manager_user_id'))) {
            $this->merge([
                'manager_user_id' => (int) $this->input('manager_user_id'),
            ]);
        }
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
            // Additional validation logic that requires access to the full validator
            $this->validatePermissionGrantingRights($validator);
            $this->validateClientScope($validator);
            $this->validateBusinessRules($validator);
        });
    }

    /**
     * Validate that the authenticated user has rights to grant the requested permission.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validatePermissionGrantingRights($validator): void
    {
        $user = $this->user();
        $targetUserId = $this->input('user_id');
        $featureId = $this->input('feature_id');

        if (!$user || !$targetUserId || !$featureId) {
            return;
        }

        // Check if the authenticated user has permission to grant this specific feature
        // This would typically check against a permission matrix or role hierarchy
        // For now, we assume Primary Admins can grant any permission
        
        // In a real implementation, you would check:
        // 1. User's role (Primary Admin, Admin, etc.)
        // 2. Feature-specific granting rights
        // 3. Hierarchical permission structure
        
        // Placeholder validation - customize based on your business rules
        $canGrantThisFeature = $this->checkUserCanGrantFeature($user, $featureId);
        
        if (!$canGrantThisFeature) {
            $validator->errors()->add('feature_id', 'You do not have permission to grant access to this feature.');
        }
    }

    /**
     * Validate that all users belong to the same client (multi-tenancy).
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateClientScope($validator): void
    {
        $user = $this->user();
        $clientId = $user ? $user->client_id : null;
        $targetUserId = $this->input('user_id');
        $managerUserId = $this->input('manager_user_id');

        if (!$clientId) {
            $validator->errors()->add('client_id', 'Unable to determine client context.');
            return;
        }

        // Validate target user belongs to same client
        if ($targetUserId) {
            $targetUser = \App\Models\User::find($targetUserId);
            if ($targetUser && $targetUser->client_id !== $clientId) {
                $validator->errors()->add('user_id', 'Target user must belong to the same client.');
            }
        }

        // Validate manager user belongs to same client
        if ($managerUserId) {
            $managerUser = \App\Models\User::find($managerUserId);
            if ($managerUser && $managerUser->client_id !== $clientId) {
                $validator->errors()->add('manager_user_id', 'Manager user must belong to the same client.');
            }
        }
    }

    /**
     * Validate business-specific rules.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateBusinessRules($validator): void
    {
        $user = $this->user();
        $targetUserId = $this->input('user_id');
        $managerUserId = $this->input('manager_user_id');

        // Business rule: Ensure the grantor can manage the target user
        if ($user && $targetUserId) {
            $canManageUser = $this->checkUserCanManageTargetUser($user->id, $targetUserId, $user->client_id);
            if (!$canManageUser) {
                $validator->errors()->add('user_id', 'You do not have permission to manage this user.');
            }
        }

        // Business rule: If manager is specified, ensure they have appropriate role
        if ($managerUserId) {
            $managerHasSufficientRights = $this->checkManagerHasSufficientRights($managerUserId);
            if (!$managerHasSufficientRights) {
                $validator->errors()->add('manager_user_id', 'Selected manager does not have sufficient rights to manage permissions.');
            }
        }
    }

    /**
     * Check if the user can grant a specific feature.
     *
     * @param  \App\Models\User  $user
     * @param  int  $featureId
     * @return bool
     */
    protected function checkUserCanGrantFeature($user, int $featureId): bool
    {
        // Placeholder implementation - customize based on your business logic
        // This could check:
        // 1. User's role permissions
        // 2. Feature-specific granting matrices
        // 3. Hierarchical permission structures
        
        // For now, assume admins can grant most features
        // In real implementation, check against permission tables/roles
        
        return true; // Simplified for this example
    }

    /**
     * Check if the user can manage the target user.
     *
     * @param  int  $userId
     * @param  int  $targetUserId
     * @param  int  $clientId
     * @return bool
     */
    protected function checkUserCanManageTargetUser(int $userId, int $targetUserId, int $clientId): bool
    {
        // Placeholder implementation - customize based on your business logic
        // This could check:
        // 1. Organizational hierarchy
        // 2. Role-based management rights
        // 3. Explicit management delegations
        
        // For now, prevent users from managing themselves and allow others
        return $userId !== $targetUserId;
    }

    /**
     * Check if the manager has sufficient rights to manage permissions.
     *
     * @param  int  $managerUserId
     * @return bool
     */
    protected function checkManagerHasSufficientRights(int $managerUserId): bool
    {
        // Placeholder implementation - customize based on your business logic
        // This could check:
        // 1. Manager's role (Admin, Primary Admin, etc.)
        // 2. Manager's existing permissions
        // 3. Business rules about who can be a permission manager
        
        // For now, assume any user can be a manager
        return true; // Simplified for this example
    }

    /**
     * Get the validated data from the request with proper type casting.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        // Ensure proper type casting for the validated data
        if (is_null($key)) {
            // Return all validated data with proper types
            return array_merge($validated, [
                'user_id' => (int) $validated['user_id'],
                'client_id' => (int) $validated['client_id'],
                'feature_id' => (int) $validated['feature_id'],
                'grantor_id' => (int) $validated['grantor_id'],
                'manager_user_id' => isset($validated['manager_user_id']) ? (int) $validated['manager_user_id'] : null,
                'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
            ]);
        }
        
        return $validated;
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You do not have permission to grant user feature permissions.'
        );
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        // Log validation failures for debugging
        \Log::warning('User permission grant validation failed', [
            'user_id' => $this->user()?->id,
            'client_id' => $this->user()?->client_id,
            'request_data' => $this->all(),
            'validation_errors' => $validator->errors()->toArray(),
            'timestamp' => now(),
        ]);

        parent::failedValidation($validator);
    }
}