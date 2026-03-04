## Code: app/Http/Requests/RevokeUserFeaturePermissionRequest.php

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
 * RevokeUserFeaturePermissionRequest
 * 
 * Form request validation for revoking user feature permissions.
 * Handles validation rules, authorization checks, and business logic validation
 * for the delegation-based RBAC system permission revocation.
 * 
 * Validation Rules:
 * - Permission must exist and be accessible to the current user
 * - User must have authority to revoke the specific permission
 * - Cannot revoke permissions that would break system integrity
 * - Must respect role hierarchy and delegation rules
 */
class RevokeUserFeaturePermissionRequest extends FormRequest
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
     * The user feature permission being revoked.
     *
     * @var UserFeaturePermission|null
     */
    protected ?UserFeaturePermission $permission = null;

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

        // Get the permission being revoked from route parameter
        $permissionId = $this->route('id') ?? $this->route('permission') ?? null;
        
        if (!$permissionId) {
            return false;
        }

        // Load the permission with client scope
        $this->permission = UserFeaturePermission::where('id', $permissionId)
            ->where('client_id', $user->client_id)
            ->first();

        if (!$this->permission) {
            return false;
        }

        // Use policy to check if user can revoke this specific permission
        $policy = new UserFeaturePermissionPolicy();
        return $policy->revoke($user, $this->permission);
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
        $permissionId = $this->route('id') ?? $this->route('permission') ?? null;

        return [
            'reason' => [
                'sometimes',
                'string',
                'max:500',
                'nullable',
            ],
            'reassign_to_primary_admin' => [
                'sometimes',
                'boolean',
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

            if (!$this->permission) {
                $validator->errors()->add('permission', 'Permission not found or not accessible.');
                return;
            }

            $clientId = $user->client_id;

            // Check if permission belongs to same client
            if ($this->permission->client_id !== $clientId) {
                $validator->errors()->add('permission', 'Permission does not belong to your organization.');
                return;
            }

            // Check if user has authority to revoke this permission
            if (!$this->canRevokePermission($user, $this->permission)) {
                $validator->errors()->add('authorization', 'You are not authorized to revoke this permission.');
                return;
            }

            // Check if revoking would break system integrity
            if ($this->wouldBreakSystemIntegrity($user, $this->permission)) {
                $validator->errors()->add('permission', 'Cannot revoke this permission as it would break system integrity.');
                return;
            }

            // Validate reassignment logic
            if ($this->input('reassign_to_primary_admin', false)) {
                if (!$this->canReassignToPrimaryAdmin($user, $this->permission)) {
                    $validator->errors()->add('reassign_to_primary_admin', 'Cannot reassign to Primary Administrator.');
                }
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
            'reason.string' => 'Reason must be a valid string.',
            'reason.max' => 'Reason cannot exceed 500 characters.',
            'reassign_to_primary_admin.boolean' => 'Reassign to Primary Admin must be true or false.',
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
            'reason' => 'revocation reason',
            'reassign_to_primary_admin' => 'reassign to primary administrator flag',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        $this->merge([
            'reason' => $this->input('reason', null),
            'reassign_to_primary_admin' => $this->input('reassign_to_primary_admin', false),
        ]);
    }

    /**
     * Get the validated data from the request with defaults.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated();

        // Ensure default values are set
        $validated['reason'] = $validated['reason'] ?? null;
        $validated['reassign_to_primary_admin'] = $validated['reassign_to_primary_admin'] ?? false;

        return $key ? ($validated[$key] ?? $default) : $validated;
    }

    /**
     * Get the permission being revoked.
     *
     * @return UserFeaturePermission|null
     */
    public function getPermission(): ?UserFeaturePermission
    {
        return $this->permission;
    }

    /**
     * Check if user can revoke the specific permission.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     * @return bool
     */
    private function canRevokePermission(User $user, UserFeaturePermission $permission): bool
    {
        // Primary Administrator can revoke any permission within client
        if ($this->isPrimaryAdministrator($user)) {
            return true;
        }

        // Administrator can revoke permissions they manage
        if ($this->isAdministrator($user)) {
            return $permission->manager_user_id === $user->id;
        }

        // Business User and Card User cannot revoke permissions
        return false;
    }

    /**
     * Check if revoking would break system integrity.
     *
     * @param User $user
     * @param UserFeaturePermission $permission
     *