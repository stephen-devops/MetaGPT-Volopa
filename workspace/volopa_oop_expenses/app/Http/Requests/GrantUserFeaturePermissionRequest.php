<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use App\Models\UserFeaturePermission;
use Illuminate\Support\Facades\Auth;

class GrantUserFeaturePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Use the policy to check if user can grant permissions
        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');

        return $user->can('grantPermission', [
            UserFeaturePermission::class,
            $userId,
            $clientId,
            $featureId
        ]);
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
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
            ],
            'client_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('features', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'manager_user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
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
            'user_id.required' => 'The target user ID is required.',
            'user_id.integer' => 'The target user ID must be an integer.',
            'user_id.min' => 'The target user ID must be at least 1.',
            'user_id.exists' => 'The selected target user does not exist or is inactive.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The selected client does not exist or is inactive.',
            
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be an integer.',
            'feature_id.min' => 'The feature ID must be at least 1.',
            'feature_id.exists' => 'The selected feature does not exist or is inactive.',
            
            'manager_user_id.required' => 'The manager user ID is required.',
            'manager_user_id.integer' => 'The manager user ID must be an integer.',
            'manager_user_id.min' => 'The manager user ID must be at least 1.',
            'manager_user_id.exists' => 'The selected manager user does not exist or is inactive.',
            
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
            $this->validateUniquePermission($validator);
            $this->validateUserClientRelationship($validator);
            $this->validateManagerClientRelationship($validator);
            $this->validateSelfManagement($validator);
            $this->validateUserHierarchy($validator);
        });
    }

    /**
     * Validate that the permission doesn't already exist.
     */
    protected function validateUniquePermission($validator): void
    {
        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');

        if ($userId && $clientId && $featureId) {
            $existingPermission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();

            if ($existingPermission) {
                if ($existingPermission->trashed()) {
                    $validator->errors()->add('user_id', 'A soft-deleted permission already exists for this user, client, and feature combination. Please restore it instead of creating a new one.');
                } else {
                    $validator->errors()->add('user_id', 'A permission already exists for this user, client, and feature combination.');
                }
            }
        }
    }

    /**
     * Validate that the target user belongs to the specified client.
     */
    protected function validateUserClientRelationship($validator): void
    {
        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');

        if ($userId && $clientId) {
            $user = User::find($userId);
            $client = Client::find($clientId);

            if ($user && $client) {
                // Check if user belongs to client (implementation depends on your user-client relationship)
                $belongsToClient = false;

                // Direct client_id relationship
                if (isset($user->client_id) && $user->client_id === $clientId) {
                    $belongsToClient = true;
                }

                // Many-to-many relationship through pivot table
                if (!$belongsToClient && method_exists($user, 'clients')) {
                    $belongsToClient = $user->clients()->where('client_id', $clientId)->exists();
                }

                if (!$belongsToClient) {
                    $validator->errors()->add('user_id', 'The selected user does not belong to the specified client.');
                }
            }
        }
    }

    /**
     * Validate that the manager user belongs to the specified client.
     */
    protected function validateManagerClientRelationship($validator): void
    {
        $managerUserId = $this->input('manager_user_id');
        $clientId = $this->input('client_id');

        if ($managerUserId && $clientId) {
            $managerUser = User::find($managerUserId);
            $client = Client::find($clientId);

            if ($managerUser && $client) {
                // Check if manager belongs to client
                $belongsToClient = false;

                // Direct client_id relationship
                if (isset($managerUser->client_id) && $managerUser->client_id === $clientId) {
                    $belongsToClient = true;
                }

                // Many-to-many relationship through pivot table
                if (!$belongsToClient && method_exists($managerUser, 'clients')) {
                    $belongsToClient = $managerUser->clients()->where('client_id', $clientId)->exists();
                }

                if (!$belongsToClient) {
                    $validator->errors()->add('manager_user_id', 'The selected manager does not belong to the specified client.');
                }
            }
        }
    }

    /**
     * Validate that user is not trying to manage themselves.
     */
    protected function validateSelfManagement($validator): void
    {
        $userId = $this->input('user_id');
        $managerUserId = $this->input('manager_user_id');

        if ($userId && $managerUserId && $userId === $managerUserId) {
            $validator->errors()->add('manager_user_id', 'A user cannot be their own manager.');
        }

        // Also check against the authenticated user (grantor)
        $authUser = Auth::user();
        if ($authUser && $userId && $userId === $authUser->id) {
            $validator->errors()->add('user_id', 'You cannot grant permissions to yourself.');
        }
    }

    /**
     * Validate user hierarchy and role permissions.
     */
    protected function validateUserHierarchy($validator): void
    {
        $userId = $this->input('user_id');
        $managerUserId = $this->input('manager_user_id');
        $authUser = Auth::user();

        if ($userId && $managerUserId && $authUser) {
            $targetUser = User::find($userId);
            $managerUser = User::find($managerUserId);

            if ($targetUser && $managerUser) {
                // Prevent granting permissions to users with higher roles
                if ($this->hasHigherRole($targetUser, $authUser)) {
                    $validator->errors()->add('user_id', 'You cannot grant permissions to a user with a higher role than yours.');
                }

                // Prevent assigning managers with lower roles than the target user
                if ($this->hasHigherRole($targetUser, $managerUser)) {
                    $validator->errors()->add('manager_user_id', 'The manager must have a role equal to or higher than the target user.');
                }
            }
        }
    }

    /**
     * Check if user1 has a higher role than user2.
     */
    protected function hasHigherRole(User $user1, User $user2): bool
    {
        $roleHierarchy = [
            'super_admin' => 100,
            'admin' => 80,
            'manager' => 60,
            'user' => 40,
            'guest' => 20,
        ];

        $user1Level = $this->getUserRoleLevel($user1, $roleHierarchy);
        $user2Level = $this->getUserRoleLevel($user2, $roleHierarchy);

        return $user1Level > $user2Level;
    }

    /**
     * Get the highest role level for a user.
     */
    protected function getUserRoleLevel(User $user, array $roleHierarchy): int
    {
        $maxLevel = 0;

        foreach ($roleHierarchy as $role => $level) {
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                $maxLevel = max($maxLevel, $level);
            }
        }

        return $maxLevel;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default value for is_enabled if not provided
        if (!$this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => true,
            ]);
        }

        // Ensure boolean conversion for is_enabled
        if ($this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => filter_var($this->input('is_enabled'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    /**
     * Get the validated data with defaults applied.
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Apply default values
        $validated['is_enabled'] = $validated['is_enabled'] ?? true;
        
        // Add grantor_id from authenticated user
        $validated['grantor_id'] = Auth::id();
        
        return $validated;
    }

    /**
     * Get the error bag for the form request.
     */
    protected function getRedirectUrl(): string
    {
        // For API requests, this won't be used, but we override to ensure consistency
        return $this->redirector->getUrlGenerator()->previous();
    }

    /**
     * Get the proper failed validation response for the request.
     */
    public function response(array $errors): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $errors,
        ], 422);
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You do not have permission to grant user feature permissions.'
        );
    }

    /**
     * Get the URL to redirect to on a validation error.
     */
    public function getValidatorInstance(): \Illuminate\Validation\Validator
    {
        $factory = $this->container->make(\Illuminate\Validation\Factory::class);
        
        if (method_exists($this, 'validator')) {
            $validator = $this->container->call([$this, 'validator'], compact('factory'));
        } else {
            $validator = $this->createDefaultValidator($factory);
        }

        if (method_exists($this, 'withValidator')) {
            $this->withValidator($validator);
        }

        return $validator;
    }

    /**
     * Create the default validator instance.
     */
    protected function createDefaultValidator(\Illuminate\Validation\Factory $factory): \Illuminate\Validation\Validator
    {
        return $factory->make(
            $this->validationData(),
            $this->container->call([$this, 'rules']),
            $this->messages(),
            $this->attributes()
        );
    }

    /**
     * Get data to be validated from the request.
     */
    public function validationData(): array
    {
        return $this->all();
    }
}