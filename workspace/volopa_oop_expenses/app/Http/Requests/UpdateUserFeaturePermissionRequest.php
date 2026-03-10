<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

/**
 * UpdateUserFeaturePermissionRequest
 * 
 * Form request for validating user feature permission update data.
 * This request handles validation and authorization for updating existing
 * feature permissions within the multi-tenant system. Includes policy
 * enforcement and comprehensive validation rules for modification scenarios.
 */
class UpdateUserFeaturePermissionRequest extends FormRequest
{
    /**
     * OOP Feature ID constant for validation
     *
     * @var int
     */
    private const OOP_FEATURE_ID = 16;

    /**
     * Determine if the user is authorized to make this request.
     * Uses UserFeaturePermissionPolicy to check authorization.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $policy = new UserFeaturePermissionPolicy();
        $permission = $this->route('userFeaturePermission');
        
        // Check if the permission exists and user can update it
        if (!$permission) {
            return false;
        }

        return $policy->update($this->user(), $permission);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $clientId = $user->client_id;
        $permission = $this->route('userFeaturePermission');
        $permissionId = $permission ? $permission->id : null;

        return [
            'manager_user_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    // Ensure manager is in the same client
                    return $query->where('client_id', $clientId);
                }),
                // Manager cannot be the same as the user receiving permission
                function ($attribute, $value, $fail) use ($permission) {
                    if ($value && $permission && $value == $permission->user_id) {
                        $fail('The manager cannot be the same as the user receiving permission.');
                    }
                },
                // Manager must have appropriate role or permissions
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($value && !$this->canUserBeManager($value, $clientId)) {
                        $fail('The specified user cannot be assigned as a manager for this permission.');
                    }
                },
                // Check for circular management relationships
                function ($attribute, $value, $fail) use ($permission) {
                    if ($value && $permission && $this->wouldCreateCircularManagement($value, $permission->user_id, $permission->id)) {
                        $fail('This manager assignment would create a circular management relationship.');
                    }
                }
            ],
            'is_enabled' => [
                'sometimes',
                'boolean'
            ],
            // Additional validation for permission-specific data
            'details' => [
                'sometimes',
                'array'
            ],
            'details.can_approve' => [
                'sometimes',
                'boolean'
            ],
            'details.can_manage' => [
                'sometimes',
                'boolean'
            ],
            'details.can_delegate' => [
                'sometimes',
                'boolean'
            ],
            'details.expiry_date' => [
                'sometimes',
                'nullable',
                'date',
                'after:today'
            ],
            'details.notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000'
            ],
            'details.updated_reason' => [
                'sometimes',
                'nullable',
                'string',
                'max:500'
            ]
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
            'manager_user_id.integer' => 'The manager user ID must be a valid integer.',
            'manager_user_id.exists' => 'The specified manager does not exist or is not in your client.',
            
            'is_enabled.boolean' => 'The enabled status must be true or false.',
            
            'details.array' => 'The details must be a valid array.',
            'details.can_approve.boolean' => 'The approve permission must be true or false.',
            'details.can_manage.boolean' => 'The manage permission must be true or false.',
            'details.can_delegate.boolean' => 'The delegate permission must be true or false.',
            'details.expiry_date.date' => 'The expiry date must be a valid date.',
            'details.expiry_date.after' => 'The expiry date must be in the future.',
            'details.notes.string' => 'The notes must be a valid string.',
            'details.notes.max' => 'The notes cannot exceed 1000 characters.',
            'details.updated_reason.string' => 'The update reason must be a valid string.',
            'details.updated_reason.max' => 'The update reason cannot exceed 500 characters.'
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
            'manager_user_id' => 'manager',
            'is_enabled' => 'enabled status',
            'details.can_approve' => 'approval permission',
            'details.can_manage' => 'management permission',
            'details.can_delegate' => 'delegation permission',
            'details.expiry_date' => 'expiry date',
            'details.notes' => 'notes',
            'details.updated_reason' => 'update reason'
        ];
    }

    /**
     * Prepare the data for validation.
     * This method is called before validation rules are applied.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Clean and normalize input data
        if ($this->has('details')) {
            $details = $this->input('details', []);
            
            // Normalize boolean values in details
            foreach (['can_approve', 'can_manage', 'can_delegate'] as $boolField) {
                if (isset($details[$boolField])) {
                    $details[$boolField] = filter_var($details[$boolField], FILTER_VALIDATE_BOOLEAN);
                }
            }
            
            // Clean string fields
            foreach (['notes', 'updated_reason'] as $stringField) {
                if (isset($details[$stringField])) {
                    $details[$stringField] = trim(strip_tags($details[$stringField]));
                    if (empty($details[$stringField])) {
                        $details[$stringField] = null;
                    }
                }
            }
            
            // Handle expiry date
            if (isset($details['expiry_date']) && empty($details['expiry_date'])) {
                $details['expiry_date'] = null;
            }
            
            $this->merge(['details' => $details]);
        }

        // Convert string booleans to actual booleans
        if ($this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => $this->boolean('is_enabled')
            ]);
        }

        // Convert empty manager_user_id to null
        if ($this->has('manager_user_id') && empty($this->input('manager_user_id'))) {
            $this->merge(['manager_user_id' => null]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Additional cross-field validation
            $this->validatePermissionModificationRights($validator);
            $this->validateFeatureSpecificRules($validator);
            $this->validateStatusChangeImpact($validator);
        });
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    /**
     * Get the validated data with additional computed fields.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($key === null) {
            // Process details if provided
            if (isset($validated['details']) && is_array($validated['details'])) {
                $permission = $this->route('userFeaturePermission');
                $existingDetails = $permission && $permission->details_json ? $permission->details_json : [];
                
                // Merge with existing details, allowing for partial updates
                $mergedDetails = array_merge($existingDetails, array_filter($validated['details'], function($value) {
                    return $value !== null;
                }));
                
                // Store details as JSON for the model
                $validated['details_json'] = !empty($mergedDetails) ? $mergedDetails : null;
                unset($validated['details']);
            }

            // Add audit fields
            $validated['updated_by_user_id'] = $this->user()->id;
        }

        return $validated;
    }

    /**
     * Check if a user can be assigned as a manager.
     *
     * @param int $managerId
     * @param int $clientId
     * @return bool
     */
    private function canUserBeManager(int $managerId, int $clientId): bool
    {
        $manager = User::find($managerId);
        
        if (!$manager || $manager->client_id !== $clientId) {
            return false;
        }

        // Manager must be Primary Admin, Admin, or Business User with management permissions
        $allowedRoles = ['Primary Administrator', 'Admin'];
        if (in_array($manager->role, $allowedRoles)) {
            return true;
        }

        // Business Users can be managers if they have management permissions
        if ($manager->role === 'Business User') {
            return UserFeaturePermission::where('user_id', $managerId)
                ->where('client_id', $clientId)
                ->where('feature_id', self::OOP_FEATURE_ID)
                ->where('is_enabled', true)
                ->whereJsonContains('details_json->can_manage', true)
                ->exists();
        }

        return false;
    }

    /**
     * Check if assigning a manager would create a circular management relationship.
     *
     * @param int $managerId
     * @param int $targetUserId
     * @param int|null $excludePermissionId
     * @return bool
     */
    private function wouldCreateCircularManagement(int $managerId, int $targetUserId, ?int $excludePermissionId = null): bool
    {
        // Check if the target user is already managing the proposed manager
        $query = UserFeaturePermission::where('user_id', $managerId)
            ->where('manager_user_id', $targetUserId)
            ->where('client_id', $this->user()->client_id);

        if ($excludePermissionId) {
            $query->where('id', '!=', $excludePermissionId);
        }

        return $query->exists();
    }

    /**
     * Validate permission modification rights based on user role and existing permissions.
     *
     * @param Validator $validator
     * @return void
     */
    private function validatePermissionModificationRights(Validator $validator): void
    {
        $user = $this->user();
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission) {
            $validator->errors()->add('permission', 'Permission not found.');
            return;
        }

        // Business Users have limited modification rights
        if ($user->role === 'Business User') {
            // Can only modify permissions they granted or manage
            $canModify = ($permission->grantor_id === $user->id) || 
                        ($permission->manager_user_id === $user->id);
            
            if (!$canModify) {
                $validator->errors()->add('permission', 'You can only modify permissions that you granted or manage.');
            }

            // Cannot modify permissions for users with higher roles
            $targetUser = User::find($permission->user_id);
            if ($targetUser && in_array($targetUser->role, ['Primary Administrator', 'Admin'])) {
                $validator->errors()->add('permission', 'You cannot modify permissions for administrators.');
            }
        }
    }

    /**
     * Validate feature-specific rules and constraints.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateFeatureSpecificRules(Validator $validator): void
    {
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission || $permission->feature_id !== self::OOP_FEATURE_ID) {
            return;
        }

        $details = $this->input('details', []);
        $targetUser = User::find($permission->user_id);
        
        // Special validation for OOP feature
        if ($targetUser) {
            // If granting approval rights, ensure user has appropriate role
            if (isset($details['can_approve']) && $details['can_approve'] && $targetUser->role === 'Card User') {
                $validator->errors()->add('details.can_approve', 'Card Users cannot be granted approval rights.');
            }

            // Validate management rights assignment
            if (isset($details['can_manage']) && $details['can_manage'] && $targetUser->role === 'Card User') {
                $validator->errors()->add('details.can_manage', 'Card Users cannot be granted management rights.');
            }
        }

        // Validate delegation rights
        $user = $this->user();
        if (isset($details['can_delegate']) && $details['can_delegate']) {
            // Only users with delegation rights themselves can grant delegation rights
            if ($user->role === 'Business User') {
                $canDelegate = UserFeaturePermission::where('user_id', $user->id)
                    ->where('client_id', $user->client_id)
                    ->where('feature_id', self::OOP_FEATURE_ID)
                    ->where('is_enabled', true)
                    ->whereJsonContains('details_json->can_delegate', true)
                    ->exists();

                if (!$canDelegate) {
                    $validator->errors()->add('details.can_delegate', 'You do not have delegation rights to grant this permission.');
                }
            }
        }
    }

    /**
     * Validate the impact of status changes on related data.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateStatusChangeImpact(Validator $validator): void
    {
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission || !$this->has('is_enabled')) {
            return;
        }

        $newStatus = $this->input('is_enabled');
        $currentStatus = $permission->is_enabled;

        // If disabling a permission, check for dependent permissions
        if ($currentStatus && !$newStatus) {
            $dependentPermissions = UserFeaturePermission::where('grantor_id', $permission->user_id)
                ->where('client_id', $permission->client_id)
                ->where('feature_id', $permission->feature_id)
                ->where('is_enabled', true)
                ->count();

            if ($dependentPermissions > 0) {
                $validator->errors()->add('is_enabled', 
                    "Cannot disable this permission as the user has granted {$dependentPermissions} dependent permission(s) to others. Please revoke those permissions first.");
            }

            // Check if user is currently managing other users
            $managedPermissions = UserFeaturePermission::where('manager_user_id', $permission->user_id)
                ->where('client_id', $permission->client_id)
                ->where('is_enabled', true)
                ->count();

            if ($managedPermissions > 0) {
                $validator->errors()->add('is_enabled', 
                    "Cannot disable this permission as the user is currently managing {$managedPermissions} permission(s). Please reassign management first.");
            }
        }
    }

    /**
     * Get sanitized input data for processing.
     *
     * @return array<string, mixed>
     */
    public function getSanitizedData(): array
    {
        $data = $this->validated();
        
        // Process details JSON
        if (isset($data['details_json'])) {
            // Remove any empty or null values from details except where explicitly null is meaningful
            $data['details_json'] = array_filter($data['details_json'], function($value, $key) {
                // Keep null values for expiry_date, notes, updated_reason as they might be intentionally cleared
                if (in_array($key, ['expiry_date', 'notes', 'updated_reason'])) {
                    return true;
                }
                return $value !== null && $value !== '';
            }, ARRAY_FILTER_USE_BOTH);
            
            // If details is empty after filtering, set to null
            if (empty($data['details_json'])) {
                $data['details_json'] = null;
            }
        }
        
        return $data;
    }

    /**
     * Get validation context information for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getValidationContext(): array
    {
        $user = $this->user();
        $permission = $this->route('userFeaturePermission');
        
        return [
            'updater_id' => $user->id,
            'updater_role' => $user->role,
            'client_id' => $user->client_id,
            'permission_id' => $permission ? $permission->id : null,
            'permission_user_id' => $permission ? $permission->user_id : null,
            'permission_feature_id' => $permission ? $permission->feature_id : null,
            'current_enabled_status' => $permission ? $permission->is_enabled : null,
            'new_enabled_status' => $this->input('is_enabled'),
            'manager_user_id' => $this->input('manager_user_id'),
            'has_details' => $this->has('details'),
            'request_ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Check if the current request is for OOP feature permission.
     *
     * @return bool
     */
    public function isOOPFeatureRequest(): bool
    {
        $permission = $this->route('userFeaturePermission');
        return $permission && $permission->feature_id === self::OOP_FEATURE_ID;
    }

    /**
     * Get the type of update being performed based on the input data.
     *
     * @return string
     */
    public function getUpdateType(): string
    {
        $types = [];
        
        if ($this->has('is_enabled')) {
            $types[] = $this->input('is_enabled') ? 'enable' : 'disable';
        }
        
        if ($this->has('manager_user_id')) {
            $types[] = $this->input('manager_user_id') ? 'assign_manager' : 'remove_manager';
        }
        
        $details = $this->input('details', []);
        if (!empty($details)) {
            if (isset($details['can_approve'])) {
                $types[] = $details['can_approve'] ? 'grant_approval' : 'revoke_approval';
            }
            if (isset($details['can_manage'])) {
                $types[] = $details['can_manage'] ? 'grant_management' : 'revoke_management';
            }
            if (isset($details['can_delegate'])) {
                $types[] = $details['can_delegate'] ? 'grant_delegation' : 'revoke_delegation';
            }
            if (isset($details['expiry_date'])) {
                $types[] = $details['expiry_date'] ? 'set_expiry' : 'remove_expiry';
            }
            if (isset($details['notes'])) {
                $types[] = 'update_notes';
            }
        }
        
        return !empty($types) ? implode(', ', $types) : 'general_update';
    }

    /**
     * Get a human-readable description of the update being performed.
     *
     * @return string
     */
    public function getUpdateDescription(): string
    {
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission) {
            return 'Unknown permission update';
        }
        
        $targetUser = User::find($permission->user_id);
        $targetUserName = $targetUser ? $targetUser->name : 'Unknown User';
        $updateType = $this->getUpdateType();
        
        return "Updating permission for {$targetUserName}: {$updateType}";
    }

    /**
     * Check if the update would result in any privilege escalation.
     *
     * @return bool
     */
    public function wouldEscalatePrivileges(): bool
    {
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission) {
            return false;
        }
        
        $details = $this->input('details', []);
        $currentDetails = $permission->details_json ?? [];
        
        // Check if any new privilege is being granted that wasn't there before
        $newPrivileges = ['can_approve', 'can_manage', 'can_delegate'];
        
        foreach ($newPrivileges as $privilege) {
            $hadPrivilege = isset($currentDetails[$privilege]) && $currentDetails[$privilege];
            $gettingPrivilege = isset($details[$privilege]) && $details[$privilege];
            
            if (!$hadPrivilege && $gettingPrivilege) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if the current user has sufficient rights to make the requested changes.
     *
     * @return bool
     */
    public function hasUpdateRights(): bool
    {
        $user = $this->user();
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission) {
            return false;
        }
        
        // Primary Admins and Admins can make any changes
        if (in_array($user->role, ['Primary Administrator', 'Admin'])) {
            return true;
        }
        
        // Business Users have limited rights
        if ($user->role === 'Business User') {
            // Must be grantor or manager to modify
            $isGrantorOrManager = ($permission->grantor_id === $user->id) || 
                                ($permission->manager_user_id === $user->id);
            
            if (!$isGrantorOrManager) {
                return false;
            }
            
            // If escalating privileges, must have those privileges themselves
            if ($this->wouldEscalatePrivileges()) {
                $details = $this->input('details', []);
                $userPermission = UserFeaturePermission::where('user_id', $user->id)
                    ->where('client_id', $user->client_id)
                    ->where('feature_id', $permission->feature_id)
                    ->where('is_enabled', true)
                    ->first();
                
                if (!$userPermission || !$userPermission->details_json) {
                    return false;
                }
                
                $userDetails = $userPermission->details_json;
                
                foreach (['can_approve', 'can_manage', 'can_delegate'] as $privilege) {
                    if (isset($details[$privilege]) && $details[$privilege]) {
                        if (!isset($userDetails[$privilege]) || !$userDetails[$privilege]) {
                            return false;
                        }
                    }
                }
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * Get the fields that are allowed to be updated by the current user.
     *
     * @return array<string>
     */
    public function getAllowedUpdateFields(): array
    {
        $user = $this->user();
        $permission = $this->route('userFeaturePermission');
        
        if (!$permission) {
            return [];
        }
        
        // Primary Admins and Admins can update all fields
        if (in_array($user->role, ['Primary Administrator', 'Admin'])) {
            return ['manager_user_id', 'is_enabled', 'details'];
        }
        
        // Business Users have restricted update rights
        if ($user->role === 'Business User') {
            $allowedFields = [];
            
            // Can modify manager if they are the grantor
            if ($permission->grantor_id === $user->id) {
                $allowedFields[] = 'manager_user_id';
                $allowedFields[] = 'is_enabled';
                $allowedFields[] = 'details';
            }
            
            // Can modify some details if they are the manager
            if ($permission->manager_user_id === $user->id) {
                $allowedFields[] = 'details';
            }
            
            return array_unique($allowedFields);
        }
        
        return [];
    }
}