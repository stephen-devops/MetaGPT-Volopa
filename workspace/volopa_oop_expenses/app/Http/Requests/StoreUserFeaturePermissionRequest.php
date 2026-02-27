## Code: app/Http/Requests/StoreUserFeaturePermissionRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\UserFeaturePermission;
use App\Policies\UserFeaturePermissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreUserFeaturePermissionRequest extends FormRequest
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

        // Use policy to check if user can create permissions
        $policy = new UserFeaturePermissionPolicy();
        
        if (!$policy->create($user)) {
            return false;
        }

        // Additional authorization checks for specific target user and manager
        if ($this->has('user_id') && $this->has('client_id')) {
            if (!$policy->canGrantToUser($user, $this->input('user_id'), $this->input('client_id'))) {
                return false;
            }
        }

        if ($this->has('manager_user_id') && $this->has('client_id')) {
            if (!$policy->canAssignManager($user, $this->input('manager_user_id'), $this->input('client_id'))) {
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
        return [
            'user_id' => [
                'required',
                'integer',
                'min:1',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (!$this->validateUserClientRelationship($value, $this->input('client_id'))) {
                        $fail('The selected user does not belong to the specified client.');
                    }
                },
            ],
            'client_id' => [
                'required',
                'integer',
                'min:1',
                'exists:clients,id',
            ],
            'feature_id' => [
                'required',
                'integer',
                'min:1',
                'exists:features,id',
            ],
            'manager_user_id' => [
                'required',
                'integer',
                'min:1',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (!$this->validateManagerClientRelationship($value, $this->input('client_id'))) {
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
            'user_id.required' => 'The user ID is required.',
            'user_id.integer' => 'The user ID must be an integer.',
            'user_id.min' => 'The user ID must be at least 1.',
            'user_id.exists' => 'The selected user does not exist.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The selected client does not exist.',
            
            'feature_id.required' => 'The feature ID is required.',
            'feature_id.integer' => 'The feature ID must be an integer.',
            'feature_id.min' => 'The feature ID must be at least 1.',
            'feature_id.exists' => 'The selected feature does not exist.',
            
            'manager_user_id.required' => 'The manager user ID is required.',
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
            'user_id' => 'user',
            'client_id' => 'client',
            'feature_id' => 'feature',
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
        // Set default values
        if (!$this->has('is_enabled')) {
            $this->merge([
                'is_enabled' => true,
            ]);
        }

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
        $integerFields = ['user_id', 'client_id', 'feature_id', 'manager_user_id'];
        $mergeData = [];
        
        foreach ($integerFields as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = $this->input($field);
                if (is_numeric($value)) {
                    $mergeData[$field] = (int) $value;
                }
            }
        }
        
        if (!empty($mergeData)) {
            $this->merge($mergeData);
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
            // Check if permission already exists
            if ($this->permissionAlreadyExists()) {
                $validator->errors()->add(
                    'user_id',
                    'A permission for this user, client, and feature combination already exists.'
                );
            }

            // Validate that user cannot be their own manager
            if ($this->input('user_id') === $this->input('manager_user_id')) {
                $validator->errors()->add(
                    'manager_user_id',
                    'The manager cannot be the same as the user being granted permission.'
                );
            }

            // Additional business logic validation
            if (!$this->validateBusinessRules()) {
                $validator->errors()->add(
                    'general',
                    'The permission request does not meet business requirements.'
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
        
        // Add grantor_id from authenticated user
        $user = Auth::user();
        if ($user) {
            $validated['grantor_id'] = $user->id;
        }
        
        return $validated;
    }

    /**
     * Validate that the user belongs to the specified client.
     *
     * @param int $userId
     * @param int|null