<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Support\Facades\Auth;

class RevokeUserFeaturePermissionRequest extends FormRequest
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

        // Get the permission to be revoked from route parameter or input
        $permissionId = $this->route('permission') ?? $this->input('permission_id');
        
        if (!$permissionId) {
            return false;
        }

        $userFeaturePermission = UserFeaturePermission::find($permissionId);
        
        if (!$userFeaturePermission) {
            return false;
        }

        // Use the policy to check if user can revoke this permission
        return $user->can('revokePermission', [$userFeaturePermission]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'permission_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('user_feature_permissions', 'id')->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
            ],
            'user_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
            ],
            'client_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'feature_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('features', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'reason' => [
                'sometimes',
                'string',
                'max:500',
                'nullable',
            ],
            'permanent' => [
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
            'permission_id.required' => 'The permission ID is required.',
            'permission_id.integer' => 'The permission ID must be an integer.',
            'permission_id.min' => 'The permission ID must be at least 1.',
            'permission_id.exists' => 'The selected permission does not exist or has been deleted.',
            
            'user_id.required' => 'The user ID is required.',
            'user_id.integer' => 'The user ID must be an integer.',
            'user_id.min' => 'The user ID must be at least 1.',
            'user_id.exists' => 'The selected user does not exist or is inactive.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The selected client does not exist or is inactive.',
            
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be an integer.',
            'feature_id.min' => 'The feature ID must be at least 1.',
            'feature_id.exists' => 'The selected feature does not exist or is inactive.',
            
            'reason.string' => 'The reason must be a string.',
            'reason.max' => 'The reason may not be greater than 500 characters.',
            
            'permanent.boolean' => 'The permanent flag must be true or false.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'permission_id' => 'permission',
            'user_id' => 'user',
            'client_id' => 'client',
            'feature_id' => 'feature',
            'reason' => 'revocation reason',
            'permanent' => 'permanent deletion flag',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validatePermissionExists($validator);
            $this->validatePermissionNotAlreadyRevoked($validator);
            $this->validateAlternativeIdentification($validator);
            $this->validateRevocationRights($validator);
        });
    }

    /**
     * Validate that the permission exists and can be identified.
     */
    protected function validatePermissionExists($validator): void
    {
        $permissionId = $this->route('permission') ?? $this->input('permission_id');
        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');

        $permission = null;

        // Try to find permission by ID first
        if ($permissionId) {
            $permission = UserFeaturePermission::find($permissionId);
        }
        // If no ID provided, try to find by user, client, and feature combination
        elseif ($userId && $clientId && $featureId) {
            $permission = UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();
        }

        if (!$permission) {
            if ($permissionId) {
                $validator->errors()->add('permission_id', 'The specified permission does not exist.');
            } else {
                $validator->errors()->add('user_id', 'No permission found for the specified user, client, and feature combination.');
            }
        }

        // Store the permission for later use
        $this->merge(['_permission_instance' => $permission]);
    }

    /**
     * Validate that the permission is not already revoked.
     */
    protected function validatePermissionNotAlreadyRevoked($validator): void
    {
        $permission = $this->input('_permission_instance');

        if ($permission && $permission->trashed()) {
            $validator->errors()->add('permission_id', 'This permission has already been revoked.');
        }

        if ($permission && !$permission->is_enabled) {
            $validator->errors()->add('permission_id', 'This permission is already disabled.');
        }
    }

    /**
     * Validate alternative identification methods.
     */
    protected function validateAlternativeIdentification($validator): void
    {
        $permissionId = $this->route('permission') ?? $this->input('permission_id');
        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');

        // If no permission ID provided, require user_id, client_id, and feature_id
        if (!$permissionId) {
            if (!$userId) {
                $validator->errors()->add('user_id', 'The user ID is required when permission ID is not provided.');
            }
            if (!$clientId) {
                $validator->errors()->add('client_id', 'The client ID is required when permission ID is not provided.');
            }
            if (!$featureId) {
                $validator->errors()->add('feature_id', 'The feature ID is required when permission ID is not provided.');
            }
        }

        // If permission ID is provided, validate consistency with other fields if they're provided
        if ($permissionId && ($userId || $clientId || $featureId)) {
            $permission = UserFeaturePermission::find($permissionId);
            if ($permission) {
                if ($userId && $permission->user_id !== (int) $userId) {
                    $validator->errors()->add('user_id', 'The provided user ID does not match the permission record.');
                }
                if ($clientId && $permission->client_id !== (int) $clientId) {
                    $validator->errors()->add('client_id', 'The provided client ID does not match the permission record.');
                }
                if ($featureId && $permission->feature_id !== (int) $featureId) {
                    $validator->errors()->add('feature_id', 'The provided feature ID does not match the permission record.');
                }
            }
        }
    }

    /**
     * Validate that the authenticated user has rights to revoke this permission.
     */
    protected function validateRevocationRights($validator): void
    {
        $permission = $this->input('_permission_instance');
        $authUser = Auth::user();

        if ($permission && $authUser) {
            // Check if the authenticated user is the grantor or manager of this permission
            $isGrantor = $permission->grantor_id === $authUser->id;
            $isManager = $permission->manager_user_id === $authUser->id;

            if (!$isGrantor && !$isManager) {
                // Additional check through policy (this is also checked in authorize(), but double-check here)
                if (!$authUser->can('revokePermission', $permission)) {
                    $validator->errors()->add('permission_id', 'You do not have the authority to revoke this permission.');
                }
            }

            // Validate permanent deletion rights
            $permanent = $this->input('permanent', false);
            if ($permanent && !$isGrantor && !$authUser->hasRole('super_admin')) {
                $validator->errors()->add('permanent', 'Only the original grantor or super admin can permanently delete permissions.');
            }
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Get permission ID from route if not in request body
        $routePermissionId = $this->route('permission');
        if ($routePermissionId && !$this->has('permission_id')) {
            $this->merge([
                'permission_id' => $routePermissionId,
            ]);
        }

        // Set default values
        if (!$this->has('permanent')) {
            $this->merge([
                'permanent' => false,
            ]);
        }

        // Ensure boolean conversion for permanent
        if ($this->has('permanent')) {
            $this->merge([
                'permanent' => filter_var($this->input('permanent'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        // Clean up reason field
        if ($this->has('reason') && empty(trim($this->input('reason')))) {
            $this->merge([
                'reason' => null,
            ]);
        }
    }

    /**
     * Get the validated data with defaults and computed values.
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Remove internal fields that shouldn't be in the final data
        unset($validated['_permission_instance']);
        
        // Apply default values
        $validated['permanent'] = $validated['permanent'] ?? false;
        
        // Add metadata
        $validated['revoked_by'] = Auth::id();
        $validated['revoked_at'] = now();
        
        return $validated;
    }

    /**
     * Get the permission instance being revoked.
     */
    public function getPermissionInstance(): ?UserFeaturePermission
    {
        // Try to get from internal state first
        $permission = $this->input('_permission_instance');
        if ($permission instanceof UserFeaturePermission) {
            return $permission;
        }

        // Fallback to finding by ID or combination
        $permissionId = $this->route('permission') ?? $this->input('permission_id');
        if ($permissionId) {
            return UserFeaturePermission::find($permissionId);
        }

        $userId = $this->input('user_id');
        $clientId = $this->input('client_id');
        $featureId = $this->input('feature_id');

        if ($userId && $clientId && $featureId) {
            return UserFeaturePermission::where('user_id', $userId)
                ->where('client_id', $clientId)
                ->where('feature_id', $featureId)
                ->first();
        }

        return null;
    }

    /**
     * Check if this is a permanent deletion request.
     */
    public function isPermanentDeletion(): bool
    {
        return filter_var($this->input('permanent', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the revocation reason.
     */
    public function getRevocationReason(): ?string
    {
        $reason = $this->input('reason');
        return empty(trim($reason ?? '')) ? null : trim($reason);
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
            'You do not have permission to revoke user feature permissions.'
        );
    }

    /**
     * Get the validator instance for the request.
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

    /**
     * Determine if the request passes the authorization check.
     */
    protected function passesAuthorization(): bool
    {
        if (method_exists($this, 'authorize')) {
            return $this->container->call([$this, 'authorize']);
        }

        return true;
    }

    /**
     * Get the URL to redirect to on a validation error.
     */
    protected function getRedirectUrl(): string
    {
        return $this->redirector->getUrlGenerator()->previous();
    }

    /**
     * Format the errors from the given Validator instance.
     */
    protected function formatErrors(\Illuminate\Validation\Validator $validator): array
    {
        return $validator->getMessageBag()->toArray();
    }

    /**
     * Get the input source for the request.
     */
    protected function getInputSource(): \Symfony\Component\HttpFoundation\ParameterBag
    {
        if ($this->isJson()) {
            return $this->json();
        }

        return in_array($this->getRealMethod(), ['GET', 'HEAD']) ? $this->query : $this->request;
    }

    /**
     * Merge new input into the current request's input array.
     */
    public function merge(array $input): self
    {
        $this->getInputSource()->add($input);

        return $this;
    }

    /**
     * Replace the input for the current request.
     */
    public function replace(array $input): self
    {
        $this->getInputSource()->replace($input);

        return $this;
    }

    /**
     * Get all of the input and files for the request.
     */
    public function all($keys = null): array
    {
        $input = array_replace_recursive($this->input(), $this->allFiles());

        if (!$keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            \Illuminate\Support\Arr::set($results, $key, \Illuminate\Support\Arr::get($input, $key));
        }

        return $results;
    }
}