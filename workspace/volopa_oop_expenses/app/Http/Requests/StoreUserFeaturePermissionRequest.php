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
 * StoreUserFeaturePermissionRequest
 * 
 * Form request for validating user feature permission creation data.
 * This request handles validation and authorization for granting new
 * feature permissions to users within the multi-tenant system.
 * Includes policy enforcement and comprehensive validation rules.
 */
class StoreUserFeaturePermissionRequest extends FormRequest
{
    /**
     * OOP Feature ID constant for validation
     *
     * @var int
     */
    private const OOP_FEATURE_ID = 16;

    /**
     * Maximum number of permissions a user can have per client
     *
     * @var int
     */
    private const MAX_PERMISSIONS_PER_USER = 50;

    /**
     * Determine if the user is authorized to make this request.
     * Uses UserFeaturePermissionPolicy to check authorization.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $policy = new UserFeaturePermissionPolicy();
        
        // Check basic create permission
        if (!$policy->create($this->user())) {
            return false;
        }

        // For additional validation, check if this is a permission grant operation
        $permissionData = $this->all();
        if (!empty($permissionData['user_id']) && !empty($permissionData['feature_id'])) {
            return $policy->authorizePermissionCreation($this->user(), $permissionData);
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
        $user = $this->user();
        $clientId = $user->client_id;

        return [
            'user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    // Ensure target user is in the same client
                    return $query->where('client_id', $clientId);
                }),
                // Custom rule to prevent self-granting for certain roles
                function ($attribute, $value, $fail) use ($user) {
                    if ($value == $user->id && in_array($user->role, ['Business User', 'Card User'])) {
                        $fail('You cannot grant permissions to yourself.');
                    }
                },
                // Check if user already has this permission
                function ($attribute, $value, $fail) {
                    $featureId = $this->input('feature_id');
                    if ($featureId && $this->permissionAlreadyExists($value, $featureId)) {
                        $fail('This user already has permission for the specified feature.');
                    }
                },
                // Check maximum permissions limit
                function ($attribute, $value, $fail) use ($clientId) {
                    if ($this->userExceedsPermissionLimit($value, $clientId)) {
                        $fail('This user has reached the maximum number of permissions allowed.');
                    }
                }
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('features', 'id'),
                // Validate that the feature is available for the client
                function ($attribute, $value, $fail) use ($clientId) {
                    if (!$this->isFeatureAvailableForClient($value, $clientId)) {
                        $fail('The specified feature is not available for your client.');
                    }
                }
            ],
            'manager_user_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    // Ensure manager is in the same client
                    return $query->where('client_id', $clientId);
                }),
                // Manager cannot be the same as the user receiving permission
                'different:user_id',
                // Manager must have appropriate role or permissions
                function ($attribute, $value, $fail) use ($user) {
                    if ($value && !$this->canUserBeManager($value, $user->client_id)) {
                        $fail('The specified user cannot be assigned as a manager for this permission.');
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
                'date',
                'after:today'
            ],
            'details.notes' => [
                'sometimes',
                'string',
                'max:1000'
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
            'user_id.required' => 'The user ID is required.',
            'user_id.integer' => 'The user ID must be a valid integer.',
            'user_id.exists' => 'The specified user does not exist or is not in your client.',
            'user_id.different' => 'You cannot grant permissions to yourself in this context.',
            
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be a valid integer.',
            'feature_id.exists' => 'The specified feature does not exist.',
            
            'manager_user_id.integer' => 'The manager user ID must be a valid integer.',
            'manager_user_id.exists' => 'The specified manager does not exist or is not in your client.',
            'manager_user_id.different' => 'The manager cannot be the same as the user receiving permission.',
            
            'is_enabled.boolean' => 'The enabled status must be true or false.',
            
            'details.array' => 'The details must be a valid array.',
            'details.can_approve.boolean' => 'The approve permission must be true or false.',
            'details.can_manage.boolean' => 'The manage permission must be true or false.',
            'details.can_delegate.boolean' => 'The delegate permission must be true or false.',
            'details.expiry_date.date' => 'The expiry date must be a valid date.',
            'details.expiry_date.after' => 'The expiry date must be in the future.',
            'details.notes.string' => 'The notes must be a valid string.',
            'details.notes.max' => 'The notes cannot exceed 1000 characters.'
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
            'user_id' => 'target user',
            'feature_id' => 'feature',
            'manager_user_id' => 'manager',
            'is_enabled' => 'enabled status',
            'details.can_approve' => 'approval permission',
            'details.can_manage' => 'management permission',
            'details.can_delegate' => 'delegation permission',
            'details.expiry_date' => 'expiry date',
            'details.notes' => 'notes'
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
        $user = $this->user();

        // Set default values
        $this->merge([
            'is_enabled' => $this->boolean('is_enabled', true),
            'grantor_id' => $user->id,
            'client_id' => $user->client_id
        ]);

        // Clean and normalize input data
        if ($this->has('details')) {
            $details = $this->input('details', []);
            
            // Normalize boolean values in details
            foreach (['can_approve', 'can_manage', 'can_delegate'] as $boolField) {
                if (isset($details[$boolField])) {
                    $details[$boolField] = filter_var($details[$boolField], FILTER_VALIDATE_BOOLEAN);
                }
            }
            
            // Clean notes field
            if (isset($details['notes'])) {
                $details['notes'] = trim(strip_tags($details['notes']));
                if (empty($details['notes'])) {
                    unset($details['notes']);
                }
            }
            
            $this->merge(['details' => $details]);
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
            $this->validatePermissionGrantingRights($validator);
            $this->validateFeatureSpecificRules($validator);
            $this->validateManagerAssignment($validator);
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
            // Add computed fields for model creation
            $validated['grantor_id'] = $this->user()->id;
            $validated['client_id'] = $this->user()->client_id;
            
            // Process details if provided
            if (isset($validated['details']) && is_array($validated['details'])) {
                // Store details as JSON for the model
                $validated['details_json'] = $validated['details'];
                unset($validated['details']);
            }
        }

        return $validated;
    }

    /**
     * Check if a permission already exists for the user and feature.
     *
     * @param int $userId
     * @param int $featureId
     * @return bool
     */
    private function permissionAlreadyExists(int $userId, int $featureId): bool
    {
        return UserFeaturePermission::where('user_id', $userId)
            ->where('feature_id', $featureId)
            ->where('client_id', $this->user()->client_id)
            ->exists();
    }

    /**
     * Check if a user exceeds the permission limit.
     *
     * @param int $userId
     * @param int $clientId
     * @return bool
     */
    private function userExceedsPermissionLimit(int $userId, int $clientId): bool
    {
        $currentCount = UserFeaturePermission::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->count();
            
        return $currentCount >= self::MAX_PERMISSIONS_PER_USER;
    }

    /**
     * Check if a feature is available for the client.
     *
     * @param int $featureId
     * @param int $clientId
     * @return bool
     */
    private function isFeatureAvailableForClient(int $featureId, int $clientId): bool
    {
        // For now, assume all features are available to all clients
        // In production, this would check a client_features table or similar
        // TODO: Implement actual client feature availability check
        return true;
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
     * Validate permission granting rights based on user role and existing permissions.
     *
     * @param Validator $validator
     * @return void
     */
    private function validatePermissionGrantingRights(Validator $validator): void
    {
        $user = $this->user();
        $targetUserId = $this->input('user_id');
        $featureId = $this->input('feature_id');

        // Business Users can only grant permissions they already have
        if ($user->role === 'Business User' && $featureId) {
            $userHasPermission = UserFeaturePermission::where('user_id', $user->id)
                ->where('client_id', $user->client_id)
                ->where('feature_id', $featureId)
                ->where('is_enabled', true)
                ->exists();

            if (!$userHasPermission) {
                $validator->errors()->add('feature_id', 'You can only grant permissions that you already have.');
            }
        }

        // Check if granting user has delegation rights
        if ($user->role === 'Business User' && $targetUserId && $featureId) {
            $canDelegate = UserFeaturePermission::where('user_id', $user->id)
                ->where('client_id', $user->client_id)
                ->where('feature_id', $featureId)
                ->where('is_enabled', true)
                ->whereJsonContains('details_json->can_delegate', true)
                ->exists();

            if (!$canDelegate) {
                $validator->errors()->add('user_id', 'You do not have delegation rights for this feature.');
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
        $featureId = $this->input('feature_id');
        $details = $this->input('details', []);
        
        // Special validation for OOP feature
        if ($featureId === self::OOP_FEATURE_ID) {
            // If granting approval rights, ensure user has appropriate role
            if (isset($details['can_approve']) && $details['can_approve']) {
                $user = $this->user();
                $targetUser = User::find($this->input('user_id'));
                
                if ($targetUser && $targetUser->role === 'Card User') {
                    $validator->errors()->add('details.can_approve', 'Card Users cannot be granted approval rights.');
                }
            }

            // Validate management rights assignment
            if (isset($details['can_manage']) && $details['can_manage']) {
                $targetUser = User::find($this->input('user_id'));
                
                if ($targetUser && $targetUser->role === 'Card User') {
                    $validator->errors()->add('details.can_manage', 'Card Users cannot be granted management rights.');
                }
            }
        }
    }

    /**
     * Validate manager assignment logic and constraints.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateManagerAssignment(Validator $validator): void
    {
        $managerId = $this->input('manager_user_id');
        $targetUserId = $this->input('user_id');
        
        if ($managerId && $targetUserId) {
            // Manager cannot manage themselves
            if ($managerId === $targetUserId) {
                $validator->errors()->add('manager_user_id', 'A user cannot be their own manager.');
            }

            // Check for circular management relationships
            if ($this->wouldCreateCircularManagement($managerId, $targetUserId)) {
                $validator->errors()->add('manager_user_id', 'This manager assignment would create a circular management relationship.');
            }

            // Validate manager has permissions to manage others
            if (!$this->canUserBeManager($managerId, $this->user()->client_id)) {
                $validator->errors()->add('manager_user_id', 'The specified user does not have the required permissions to be a manager.');
            }
        }
    }

    /**
     * Check if assigning a manager would create a circular management relationship.
     *
     * @param int $managerId
     * @param int $targetUserId
     * @return bool
     */
    private function wouldCreateCircularManagement(int $managerId, int $targetUserId): bool
    {
        // Check if the target user is already managing the proposed manager
        return UserFeaturePermission::where('user_id', $managerId)
            ->where('manager_user_id', $targetUserId)
            ->where('client_id', $this->user()->client_id)
            ->exists();
    }

    /**
     * Get sanitized input data for processing.
     *
     * @return array<string, mixed>
     */
    public function getSanitizedData(): array
    {
        $data = $this->validated();
        
        // Ensure all required fields have values
        $data['is_enabled'] = $data['is_enabled'] ?? true;
        $data['grantor_id'] = $this->user()->id;
        $data['client_id'] = $this->user()->client_id;
        
        // Process details JSON
        if (isset($data['details_json'])) {
            // Remove any empty or null values from details
            $data['details_json'] = array_filter($data['details_json'], function($value) {
                return $value !== null && $value !== '';
            });
            
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
        
        return [
            'grantor_id' => $user->id,
            'grantor_role' => $user->role,
            'client_id' => $user->client_id,
            'target_user_id' => $this->input('user_id'),
            'feature_id' => $this->input('feature_id'),
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
        return $this->input('feature_id') === self::OOP_FEATURE_ID;
    }

    /**
     * Get the permission type being granted based on details.
     *
     * @return string
     */
    public function getPermissionType(): string
    {
        $details = $this->input('details', []);
        
        if (isset($details['can_approve']) && $details['can_approve']) {
            return 'approval';
        }
        
        if (isset($details['can_manage']) && $details['can_manage']) {
            return 'management';
        }
        
        if (isset($details['can_delegate']) && $details['can_delegate']) {
            return 'delegation';
        }
        
        return 'basic';
    }

    /**
     * Get a human-readable description of the permission being granted.
     *
     * @return string
     */
    public function getPermissionDescription(): string
    {
        $targetUser = User::find($this->input('user_id'));
        $targetUserName = $targetUser ? $targetUser->name : 'Unknown User';
        $featureId = $this->input('feature_id');
        $permissionType = $this->getPermissionType();
        
        return "Granting {$permissionType} permission for feature {$featureId} to {$targetUserName}";
    }
}