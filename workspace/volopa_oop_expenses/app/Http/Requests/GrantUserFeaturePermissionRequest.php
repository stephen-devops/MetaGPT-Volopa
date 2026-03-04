## Code: app/Http/Requests/GrantUserFeaturePermissionRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * GrantUserFeaturePermissionRequest
 * 
 * Form request validation for granting user feature permissions.
 * Handles validation rules, authorization checks, and business logic validation
 * for the delegation-based RBAC system.
 * 
 * Validation Rules:
 * - user_id: Required, must exist in users table and belong to same client
 * - feature_id: Required, must exist in features table
 * - manager_user_id: Required, must exist in users table and belong to same client
 * - Cannot grant permission to self
 * - Cannot duplicate existing permissions
 * - Must respect role hierarchy and delegation rules
 */
class GrantUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Primary Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Administrator';

    /**
     * Business User role identifier.
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role identifier.
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user || !$user->client_id) {
            return false;
        }

        // Use policy to check if user can grant permissions
        $policy = new UserFeaturePermissionPolicy();
        return $policy->create($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = Auth::user();
        $clientId = $user ? $user->client_id : null;

        return [
            'user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $query->where('client_id', $clientId);
                    }
                    return $query;
                }),
                function ($attribute, $value, $fail) use ($user) {
                    // Cannot grant permission to self
                    if ($user && $user->id == $value) {
                        $fail('Cannot grant permission to yourself.');
                    }
                },
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('features', 'id'),
            ],
            'manager_user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $query->where('client_id', $clientId);
                    }
                    return $query;
                }),
                function ($attribute, $value, $fail) use ($user) {
                    // Manager must be different from the user receiving permission
                    if ($this->input('user_id') && $this->input('user_id') == $value) {
                        $fail('Manager cannot be the same as the user receiving permission.');
                    }
                },
            ],
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
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                $validator->errors()->add('authorization', 'Invalid user context.');
                return;
            }

            $userId = $this->input('user_id');
            $featureId = $this->input('feature_id');
            $managerId = $this->input('manager_user_id');
            $clientId = $user->client_id;

            // Check if permission already exists
            if ($this->permissionAlreadyExists($userId, $clientId, $featureId)) {
                $validator->errors()->add('user_id', 'Permission already exists for this user and feature.');
            }

            // Check authorization to grant to specific user
            if (!$this->canGrantToUser($user, $userId, $clientId, $featureId)) {
                $validator->errors()->add('user_id', 'You are not authorized to grant permissions to this user.');
            }

            // Check if manager is valid
            if (!$this->isValidManager($user, $managerId, $clientId)) {
                $validator->errors()->add('manager_user_id', 'Invalid manager selection.');
            }

            // Validate target user exists and belongs to same client
            if (!$this->isValidTargetUser($userId, $clientId)) {
                $validator->errors()->add('user_id', 'Target user does not exist or does not belong to your organization.');
            }

            // Validate manager exists and belongs to same client
            if (!$this->isValidTargetUser($managerId, $clientId)) {
                $validator->errors()->add('manager_user_id', 'Manager does not exist or does not belong to your organization.');
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID is required.',
            'user_id.integer' => 'User ID must be a valid integer.',
            'user_id.min' => 'User ID must be greater than 0.',
            'user_id.exists' => 'Selected user does not exist.',
            
            'feature_id.required' => 'Feature ID is required.',
            'feature_id.integer' => 'Feature ID must be a valid integer.',
            'feature_id.min' => 'Feature ID must be greater than 0.',
            'feature_id.exists' => 'Selected feature does not exist.',
            
            'manager_user_id.required' => 'Manager User ID is required.',
            'manager_user_id.integer' => 'Manager User ID must be a valid integer.',
            'manager_user_id.min' => 'Manager User ID must be greater than 0.',
            'manager_user_id.exists' => 'Selected manager does not exist.',
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
            'feature_id' => 'feature',
            'manager_user_id' => 'manager',
        ];
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
            'user_id' => $this->input('user_id') ? (int) $this->input('user_id') : null,
            'feature_id' => $this->input('feature_id') ? (int) $this->input('feature_id') : null,
            'manager_user_id' => $this->input('manager_user_id') ? (int) $this->input('manager_user_id') : null,
        ]);
    }