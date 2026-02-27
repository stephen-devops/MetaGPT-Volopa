## Code: app/Http/Requests/UpdateUserFeaturePermissionRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UpdateUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Get the permission being updated from route model binding
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission) {
            return false;
        }

        // Use policy to check if user can update this specific permission
        $policy = new UserFeaturePermissionPolicy();
        
        if (!$policy->update($user, $permission)) {
            return false;
        }

        // Additional authorization checks for manager assignment
        if ($this->has('manager_user_id')) {
            if (!$policy->canAssignManager($user, $this->input('manager_user_id'), $permission->client_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $permission = $this->route('userFeaturePermission');
        
        return [
            'manager_user_id' => [
                'sometimes',
                'integer',
                'min:1',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($permission) {
                    if ($permission && !$this->validateManagerClientRelationship($value, $permission->client_id)) {
                        $fail('The selected manager does not belong to the specified client.');
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
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manager_user_id.integer' => 'The manager user ID must be an integer.',
            'manager_user_id.min' => 'The manager user ID must be at least 1.',
            'manager_user_id.exists' => 'The selected manager user does not exist.',
            'is_enabled.boolean' => 'The is enabled field must be true or false.',
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
        // Convert string booleans to actual booleans
        if ($this->has('is_enabled')) {
            $isEnabled = $this->input('is_enabled');
            if (is_string($isEnabled)) {
                $this->merge([
                    'is_enabled' => filter_var($isEnabled, FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        // Ensure integer values are properly cast
        if ($this->has('manager_user_id') && is_string($this->input('manager_user_id'))) {
            $value = $this->input('manager_user_id');
            if (is_numeric($value)) {
                $this->merge([
                    'manager_user_id' => (int) $value,
                ]);
            }
        }
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
            $permission = $this->route('userFeaturePermission');
            
            if (!$permission) {
                $validator->errors()->add(
                    'general',
                    'The permission to update was not found.'
                );
                return;
            }

            // Validate that user cannot be their own manager
            if ($this->has('manager_user_id') && $permission->user_id === $this->input('manager_user_id')) {
                $validator->errors()->add(
                    'manager_user_id',
                    'The manager cannot be the same as the user who owns the permission.'
                );
            }

            // Additional business logic validation
            if (!$this->validateUpdateBusinessRules($permission)) {
                $validator->errors()->add(
                    'general',
                    'The permission update request does not meet business requirements.'
                );
            }
        });
    }

    /**
     * Get the validated data with proper formatting.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);
        
        // Only return fields that can be updated
        $allowedFields = ['manager_user_id', 'is_enabled'];
        $filteredValidated = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $validated)) {
                $filteredValidated[$field] = $validated[$field];
            }
        }
        
        return $filteredValidated;
    }

    /**
     * Validate that the manager belongs to the specified client.
     *
     * @param int $managerUserId
     * @param int $clientId
     * @return bool
     */
    private function validateManagerClientRelationship(int $managerUserId, int $clientId): bool
    {
        return DB::table('users')
                 ->where('id', $managerUserId)
                 ->where('client_id', $clientId)
                 ->exists();
    }

    /**
     * Validate business rules for permission updates.
     *
     * @param UserFeaturePermission $permission
     * @return bool
     */
    private function validateUpdateBusinessRules(UserFeaturePermission $permission): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Primary Admin can update any permission
        if ($user->isPrimaryAdmin()) {
            return true;
        }

        // Admin can only update permissions for users in their client
        if ($user->isAdmin()) {
            if ($permission->client_id !== $user->client_id) {
                return false;
            }
            
            // Additional validation for admin role restrictions
            return $this->validateAdminUpdateRules($user, $permission);
        }

        // Business Users and Card Users cannot update permissions
        return false;
    }

    /**
     * Validate admin-specific update rules.
     *
     * @param \App\Models\User $admin
     * @param UserFeaturePermission $permission
     * @return bool
     */
    private function validateAdminUpdateRules($admin, UserFeaturePermission $permission): bool
    {
        // Admin can update permissions they granted
        if ($permission->grantor_id === $admin->id) {
            return true;
        }

        // Admin can update permissions for users they manage
        if ($permission->manager_user_id === $admin->id) {
            return true;
        }

        // Check if admin has management access to the user
        return $this->canUserManageTarget($admin->id, $permission->user_id, $permission->client_id);
    }

    /**
     * Check if a manager user can manage a target user within a client.
     *
     * @param int $managerUserId
     * @param int $targetUs