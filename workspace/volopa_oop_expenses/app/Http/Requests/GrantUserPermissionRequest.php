## Code: app/Http/Requests/GrantUserPermissionRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use App\Models\UserFeaturePermission;
use App\Policies\UserPermissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantUserPermissionRequest extends FormRequest
{
    /**
     * Feature ID for user permission management.
     */
    const USER_PERMISSION_FEATURE_ID = 2;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $userPermissionPolicy = new UserPermissionPolicy();
        
        // Check if user can grant permissions in general
        if (!$userPermissionPolicy->create($this->user())) {
            return false;
        }
        
        // Check specific authorization for granting to target user
        $targetUserId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');
        
        if ($targetUserId && $clientId && $featureId) {
            return $userPermissionPolicy->grant($this->user(), $targetUserId, $clientId, $featureId);
        }
        
        return true; // Allow validation to proceed, specific checks will happen in withValidator
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                'different:grantor_id',
            ],
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'feature_id' => [
                'required',
                'integer',
                'exists:features,id',
            ],
            'grantor_id' => [
                'sometimes',
                'integer',
                'exists:users,id',
            ],
            'manager_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                'different:user_id',
            ],
            'is_enabled' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'The target user is required.',
            'user_id.integer' => 'The target user must be a valid user ID.',
            'user_id.exists' => 'The selected target user does not exist.',
            'user_id.different' => 'The target user cannot be the same as the grantor.',
            'client_id.required' => 'The client is required.',
            'client_id.integer' => 'The client must be a valid client ID.',
            'client_id.exists' => 'The selected client does not exist.',
            'feature_id.required' => 'The feature is required.',
            'feature_id.integer' => 'The feature must be a valid feature ID.',
            'feature_id.exists' => 'The selected feature does not exist.',
            'grantor_id.integer' => 'The grantor must be a valid user ID.',
            'grantor_id.exists' => 'The selected grantor does not exist.',
            'manager_user_id.integer' => 'The manager must be a valid user ID.',
            'manager_user_id.exists' => 'The selected manager does not exist.',
            'manager_user_id.different' => 'The manager cannot be the same as the target user.',
            'is_enabled.boolean' => 'The enabled status must be true or false.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'user_id' => 'target user',
            'client_id' => 'client',
            'feature_id' => 'feature',
            'grantor_id' => 'grantor',
            'manager_user_id' => 'manager user',
            'is_enabled' => 'enabled status',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateTargetUserBelongsToClient($validator);
            $this->validateGrantorHasPermissionFeature($validator);
            $this->validateManagerBelongsToClient($validator);
            $this->validateManagerHasPermissionFeature($validator);
            $this->validateFeatureCanBeGranted($validator);
            $this->validatePermissionDoesNotExist($validator);
            $this->validateGrantorCanManageTargetUser($validator);
            $this->validateManagerCannotBeGrantor($validator);
        });
    }

    /**
     * Validate that the target user belongs to the specified client.
     */
    protected function validateTargetUserBelongsToClient($validator): void
    {
        $targetUserId = $this->input('user_id');
        $clientId = $this->input('client_id');
        
        if ($targetUserId && $clientId) {
            // Check if target user has any existing permissions for this client
            $hasClientAccess = UserFeaturePermission::forUserAndClient($targetUserId, $clientId)
                ->exists();
            
            if (!$hasClientAccess) {
                // For new users, we might allow granting permissions
                // This is a business decision - for now, we'll allow it
                // but in a real implementation, you might want to check
                // if the user is associated with the client in some other way
            }
        }
    }

    /**
     * Validate that the grantor has the user permission management feature.
     */
    protected function validateGrantorHasPermissionFeature($validator): void
    {
        $grantorId = $this->input('grantor_id', $this->user()->id);
        $clientId = $this->input('client_id');
        
        if ($grantorId && $clientId) {
            $hasPermissionFeature = UserFeaturePermission::hasPermission(
                $grantorId, 
                $clientId, 
                self::USER_PERMISSION_FEATURE_ID
            );
            
            if (!$hasPermissionFeature) {
                $validator->errors()->add('grantor_id', 'The grantor does not have permission management feature enabled for this client.');
            }
        }
    }

    /**
     * Validate that the manager belongs to the client if specified.
     */
    protected function validateManagerBelongsToClient($validator): void
    {
        $managerId = $this->input('manager_user_id');
        $clientId = $this->input('client_id');
        
        if ($managerId && $clientId) {
            // Check if manager has any permissions for this client
            $hasClientAccess = UserFeaturePermission::forUserAndClient($managerId, $clientId)
                ->exists();
            
            if (!$hasClientAccess) {
                $validator->errors()->add('manager_user_id', 'The selected manager does not have access to this client.');
            }
        }
    }

    /**
     * Validate that the manager has permission management feature if specified.
     */
    protected function validateManagerHasPermissionFeature($validator): void
    {
        $managerId = $this->input('manager_user_id');
        $clientId = $this->input('client_id');
        
        if ($managerId && $clientId) {
            $hasPermissionFeature = UserFeaturePermission::hasPermission(
                $managerId, 
                $clientId, 
                self::USER_PERMISSION_FEATURE_ID
            );
            
            if (!$hasPermissionFeature) {
                $validator->errors()->add('manager_user_id', 'The selected manager does not have permission management feature enabled for this client.');
            }
        }
    }

    /**
     * Validate that the feature can be granted (is active, etc.).
     */
    protected function validateFeatureCanBeGranted($validator): void
    {
        $featureId = $this->input('feature_id');
        
        if ($featureId) {
            $feature = Feature::find($featureId);
            
            if ($feature) {
                // Check if feature is active (assuming Feature model has is_active field)
                if (method_exists($feature, 'isActive') && !$feature->isActive()) {
                    $validator->errors()->add('feature_id', 'The selected feature is not active and cannot be granted.');
                }
                
                // Check if feature can be granted by this user level
                // This would depend on your business logic
                if (method_exists($feature, 'canBeGrantedBy')) {
                    $grantorId = $this->input('grantor_id', $this->user()->id);
                    $grantor = User::find($grantorId);
                    
                    if ($grantor && !$feature->canBeGrantedBy($grantor)) {
                        $validator->errors()->add('feature_id', 'You do not have sufficient privileges to grant this feature.');
                    }
                }
            }
        }
    }

    /**
     * Validate that the permission does not already exist for this combination.
     */
    protected function validatePermissionDoesNotExist($validator): void
    {
        $targetUserId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');
        
        if ($targetUserId && $clientId && $featureId) {
            $existingPermission = UserFeaturePermission::findForUserClientAndFeature(
                $targetUserId, 
                $clientId, 
                $featureId
            );
            
            if ($existingPermission) {
                $status = $existingPermission->isEnabled() ? 'enabled' : 'disabled';
                $validator->errors()->add('user_id', "This user already has this feature permission for this client (currently {$status}). Use the update endpoint to modify existing permissions.");
            }
        }
    }

    /**
     * Validate that the grantor can manage the target user.
     */
    protected function validateGrantorCanManageTargetUser($validator): void
    {
        $grantorId = $this->input('grantor_id', $this->user()->id);
        $targetUserId = $this->input('user_id');
        $clientId = $this->input('client_id');
        
        if ($grantorId && $targetUserId && $clientId && $grantorId !== $targetUserId) {
            $userPermissionPolicy = new UserPermissionPolicy();
            $grantor = User::find($grantorId);
            
            if ($grantor) {
                $canManage = $userPermissionPolicy->grant($grantor, $targetUserId, $clientId, $this->input('feature_id'));
                
                if (!$canManage) {
                    $validator->errors()->add('user_id', 'You do not have permission to grant features to this user.');
                }
            }
        }
    }

    /**
     * Validate that the manager is not the same as the grantor.
     */
    protected function validateManagerCannotBeGrantor($validator): void
    {
        $grantorId = $this->input('grantor_id', $this->user()->id);
        $managerId = $this->input('manager_user_id');
        
        if ($managerId && $grantorId && $managerId === $grantorId) {
            $validator->errors()->add('manager_user_id', 'The manager cannot be the same as the grantor.');
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $data = [];
        
        // Set grantor_id to authenticated user if not provided
        if (!$this->has('grantor_id')) {
            $data['grantor_id'] = $this->user()->id;
        }
        
        // Set default enabled status to true if not provided
        if (!$this->has('is_enabled')) {
            $data['is_enabled'] = true;
        }
        
        // Convert string IDs to integers if provided
        if ($this->has('user_id')) {
            $data['user_id'] = (int) $this->input('user_id');
        }
        
        if ($this->has('client_id')) {
            $data['client_id'] = (int) $this->input('client_id');
        }
        
        if ($this->has('feature_id')) {
            $data['feature_id'] = (int) $this->input('feature_id');
        }
        
        if ($this->has('grantor_id')) {
            $data['grantor_id'] = (int) $this->input('grantor_id');
        }
        
        if ($this->has('manager_user_id') && $this->input('manager_user_id') !== null) {
            $data['manager_user_id'] = (int) $this->input('manager_user_id');
        }
        
        // Ensure is_enabled is boolean
        if ($this->has('is_enabled')) {
            $isEnabled = $this->input('is_enabled');
            if (is_string($isEnabled)) {
                $data['is_enabled'] = filter_var($isEnabled, FILTER_VALIDATE_BOOLEAN);
            } else {
                $data['is_enabled'] = (bool) $isEnabled;
            }
        }
        
        if (!empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Get the validated target user ID.
     */
    public function getValidatedTargetUserId(): int
    {
        return $this->validated()['user_id'];
    }

    /**
     * Get the validated client ID.
     */
    public function getValidatedClientId(): int
    {
        return $this->validated()['client_id'];
    }

    /**
     * Get the validated feature ID.
     */
    public function getValidatedFeatureId(): int
    {
        return $this->validated()['feature_id'];
    }

    /**
     * Get the validated grantor ID.
     */
    public function getValidatedGrantorId(): int
    {
        return $this->validated()['grantor_id'];
    }

    /**
     * Get the validated manager user ID.
     */
    public function getValidatedManagerUserId(): ?int
    {
        return $this->validated()['manager_user_id'] ?? null;
    }

    /**
     * Get the validated enabled status.
     */
    public function getValidatedEnabledStatus(): bool
    {
        return $this->validated()['is_enabled'] ?? true;
    }

    /**
     * Get permission data formatted for creation.
     */
    public function getPermissionData(): array
    {
        return [
            'user_id' => $this->getValidatedTargetUserId(),
            'client_id' => $this->getValidatedClientId(),
            'feature_id' => $this->getValidatedFeatureId(),
            'grantor_id' => $this->getValidatedGrantorId(),
            'manager_user_id' => $this->getValidatedManagerUserId(),
            'is_enabled' => $this->getValidatedEnabledStatus(),
        ];
    }

    /**
     * Check if the permission should be enabled by default.
     */
    public function shouldEnableByDefault(): bool
    {
        return !$this->has('is_enabled') || $this->getValidatedEnabledStatus();
    }

    /**
     * Check if a manager is being assigned.
     */
    public function hasManagerAssignment(): bool
    {
        return $this->has('manager_user_id') && $this->getValidatedManagerUserId() !== null;
    }

    /**
     * Get permission metadata for logging/auditing.
     */
    public function getPermissionMetadata(): array
    {
        return