<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;

/**
 * Form Request for updating User Feature Permission records
 * 
 * Handles validation and authorization for updating user feature permissions
 * in the delegation table. Validates user, client, feature relationships
 * and enforces business rules around permission management hierarchy.
 * 
 * Supports partial updates where only changed fields are validated.
 * Uses UserFeaturePermissionPolicy for authorization checks.
 */
class UpdateUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * 
     * Uses UserFeaturePermissionPolicy to check if the authenticated user
     * can update the specific UserFeaturePermission record.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission instanceof UserFeaturePermission) {
            return false;
        }
        
        return $this->user()->can('update', $permission);
    }

    /**
     * Get the validation rules that apply to the request.
     * 
     * Validates user relationships, feature assignments, and management hierarchy.
     * All fields are optional for updates (PATCH semantics).
     * Enforces business constraints around permission delegation.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $permission = $this->route('userFeaturePermission');
        $currentClientId = auth()->user()->client_id ?? 0;
        
        return [
            // User receiving the permission (optional for updates)
            'user_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($currentClientId) {
                    $query->where('deleted', 0)
                          ->where('client_id', $currentClientId);
                }),
            ],
            
            // Feature being granted (optional for updates)
            'feature_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('features', 'id'),
            ],
            
            // User who can manage the target user (optional)
            'manager_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'different:user_id',
                Rule::exists('users', 'id')->where(function ($query) use ($currentClientId) {
                    $query->where('deleted', 0)
                          ->where('client_id', $currentClientId);
                }),
            ],
            
            // Permission state (optional for updates)
            'is_enabled' => [
                'sometimes',
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     * 
     * Provides user-friendly field names for validation error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'target user',
            'feature_id' => 'feature',
            'manager_user_id' => 'manager user',
            'is_enabled' => 'permission status',
        ];
    }

    /**
     * Get custom messages for validator errors.
     * 
     * Provides detailed, business-context-aware error messages
     * that guide users on permission management constraints.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'A target user must be specified for the permission.',
            'user_id.exists' => 'The selected target user does not exist or is not active in your client.',
            'user_id.integer' => 'The target user ID must be a valid number.',
            'user_id.min' => 'The target user ID must be greater than zero.',
            
            'feature_id.required' => 'A feature must be specified for the permission.',
            'feature_id.exists' => 'The selected feature does not exist or is not available.',
            'feature_id.integer' => 'The feature ID must be a valid number.',
            'feature_id.min' => 'The feature ID must be greater than zero.',
            
            'manager_user_id.exists' => 'The selected manager user does not exist or is not active in your client.',
            'manager_user_id.different' => 'The manager user cannot be the same as the target user.',
            'manager_user_id.integer' => 'The manager user ID must be a valid number.',
            'manager_user_id.min' => 'The manager user ID must be greater than zero.',
            
            'is_enabled.required' => 'The permission status must be specified.',
            'is_enabled.boolean' => 'The permission status must be either enabled or disabled.',
        ];
    }

    /**
     * Configure the validator instance.
     * 
     * Adds custom validation logic for business rules that cannot be expressed
     * through simple rule arrays. Validates unique permission constraints
     * and management hierarchy rules.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $permission = $this->route('userFeaturePermission');
            $currentUserId = auth()->id();
            $currentClientId = auth()->user()->client_id ?? 0;
            
            // Only validate if user_id and feature_id are being updated
            if ($this->has('user_id') && $this->has('feature_id')) {
                $this->validateUniquePermission($validator, $permission);
            }
            
            // Validate manager hierarchy if manager_user_id is being updated
            if ($this->has('manager_user_id')) {
                $this->validateManagerHierarchy($validator, $currentUserId, $currentClientId);
            }
            
            // Validate permission change authorization
            if ($this->has('is_enabled') && $this->input('is_enabled') === false) {
                $this->validateRevocationAuthorization($validator, $permission, $currentUserId);
            }
        });
    }

    /**
     * Validate that the user-client-feature combination remains unique.
     * 
     * Prevents duplicate permission records for the same user, client, and feature,
     * excluding the current permission being updated.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param UserFeaturePermission $permission
     * @return void
     */
    protected function validateUniquePermission($validator, UserFeaturePermission $permission): void
    {
        $existingPermission = UserFeaturePermission::where('user_id', $this->input('user_id'))
            ->where('client_id', auth()->user()->client_id ?? 0)
            ->where('feature_id', $this->input('feature_id'))
            ->where('id', '!=', $permission->id)
            ->first();
            
        if ($existingPermission) {
            $validator->errors()->add(
                'user_id', 
                'This user already has a permission record for the specified feature in your client.'
            );
        }
    }

    /**
     * Validate manager hierarchy and authorization rules.
     * 
     * Ensures that only authorized users can assign management rights
     * and that the manager has appropriate permissions.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param int $currentUserId
     * @param int $currentClientId
     * @return void
     */
    protected function validateManagerHierarchy($validator, int $currentUserId, int $currentClientId): void
    {
        $managerUserId = $this->input('manager_user_id');
        
        if ($managerUserId === null) {
            return; // Removing manager is allowed
        }
        
        // Check if current user has authority to assign this manager
        // Only Primary Admin or existing managers can assign management rights
        $currentUserRole = auth()->user()->role ?? '';
        
        if ($currentUserRole !== 'Primary Admin') {
            // Check if current user is already a manager of the target user
            $existingManagerRelation = UserFeaturePermission::where('manager_user_id', $currentUserId)
                ->where('user_id', $this->input('user_id', $this->route('userFeaturePermission')->user_id))
                ->where('client_id', $currentClientId)
                ->where('is_enabled', 1)
                ->exists();
                
            if (!$existingManagerRelation) {
                $validator->errors()->add(
                    'manager_user_id',
                    'You do not have authority to assign management rights for this user.'
                );
            }
        }
        
        // Validate that the proposed manager exists and is active
        $managerExists = \DB::table('users')
            ->where('id', $managerUserId)
            ->where('client_id', $currentClientId)
            ->where('deleted', 0)
            ->exists();
            
        if (!$managerExists) {
            $validator->errors()->add(
                'manager_user_id',
                'The selected manager user is not found or inactive in your client.'
            );
        }
    }

    /**
     * Validate authorization for permission revocation.
     * 
     * Ensures that only Primary Admin or Volopa Admin can revoke permissions,
     * as per the platform permission constraints.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @param UserFeaturePermission $permission
     * @param int $currentUserId
     * @return void
     */
    protected function validateRevocationAuthorization($validator, UserFeaturePermission $permission, int $currentUserId): void
    {
        $currentUserRole = auth()->user()->role ?? '';
        
        // Only Primary Admin or Volopa Admin can revoke permissions
        if (!in_array($currentUserRole, ['Primary Admin', 'Volopa Admin'])) {
            $validator->errors()->add(
                'is_enabled',
                'You do not have authority to revoke permissions. Only Primary Admin or Volopa Admin can disable permissions.'
            );
        }
        
        // Additional check: prevent self-revocation of critical permissions
        if ($permission->user_id === $currentUserId && $permission->feature_id === 16) { // OOP Expense feature
            $validator->errors()->add(
                'is_enabled',
                'You cannot revoke your own access to the OOP Expense feature.'
            );
        }
    }

    /**
     * Prepare the data for validation.
     * 
     * Performs data normalization and type casting before validation.
     * Ensures consistent data types and handles edge cases.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Normalize boolean values
        if ($this->has('is_enabled')) {
            $isEnabled = $this->input('is_enabled');
            if (is_string($isEnabled)) {
                $this->merge([
                    'is_enabled' => filter_var($isEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false
                ]);
            }
        }
        
        // Normalize null values for optional fields
        if ($this->has('manager_user_id') && ($this->input('manager_user_id') === '' || $this->input('manager_user_id') === '0')) {
            $this->merge([
                'manager_user_id' => null
            ]);
        }
        
        // Cast numeric fields to integers
        foreach (['user_id', 'feature_id', 'manager_user_id'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $value = $this->input($field);
                if (is_numeric($value)) {
                    $this->merge([
                        $field => (int) $value
                    ]);
                }
            }
        }
    }

    /**
     * Get the validated data from the request.
     * 
     * Overrides parent method to ensure only non-null values are returned
     * for partial updates, supporting PATCH semantics.
     *
     * @param array|null $key
     * @param mixed $default
     * @return array
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();
        
        // Remove null values for partial updates
        return array_filter($validated, function ($value) {
            return $value !== null;
        });
    }

    /**
     * Handle a passed validation attempt.
     * 
     * Called after successful validation. Can be used to perform
     * additional business logic or data transformation.
     *
     * @return void
     */
    protected function passedValidation(): void
    {
        // Add client_id from authenticated user context
        // This ensures multi-tenant scoping is enforced
        $this->merge([
            'client_id' => auth()->user()->client_id ?? 0,
            'grantor_id' => auth()->id(), // Track who is making the update
        ]);
    }

    /**
     * Get data to be validated from the request.
     * 
     * Filters the request data to only include fields that are relevant
     * for UserFeaturePermission updates.
     *
     * @return array
     */
    public function validationData(): array
    {
        return $this->only([
            'user_id',
            'feature_id', 
            'manager_user_id',
            'is_enabled'
        ]);
    }
}