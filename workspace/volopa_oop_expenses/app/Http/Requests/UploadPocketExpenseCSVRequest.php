<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UploadPocketExpenseCSVRequest
 * 
 * Handles validation for CSV file uploads for pocket expenses.
 * Validates file format, size, user permissions, and client associations.
 */
class UploadPocketExpenseCSVRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // TODO: Implement proper authorization logic
        // Should check if the authenticated user has permission to upload CSV files
        // for the specified client and expense user
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
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // Max 10MB (10240 KB) as per constraints
            ],
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'expense_user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
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
            'file.required' => 'A CSV file is required.',
            'file.file' => 'The uploaded item must be a file.',
            'file.mimes' => 'The file must be a CSV or TXT file with CSV content.',
            'file.max' => 'The file size must not exceed 10MB.',
            'user_id.required' => 'User ID is required.',
            'user_id.integer' => 'User ID must be an integer.',
            'user_id.exists' => 'The specified user does not exist.',
            'expense_user_id.required' => 'Expense user ID is required.',
            'expense_user_id.integer' => 'Expense user ID must be an integer.',
            'expense_user_id.exists' => 'The specified expense user does not exist.',
            'client_id.required' => 'Client ID is required.',
            'client_id.integer' => 'Client ID must be an integer.',
            'client_id.exists' => 'The specified client does not exist.',
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
            'file' => 'CSV file',
            'user_id' => 'admin user',
            'expense_user_id' => 'target user',
            'client_id' => 'client',
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
            // Additional validation for expense_user_id belonging to client_id
            if ($this->filled(['expense_user_id', 'client_id'])) {
                // TODO: Implement check to verify expense_user_id belongs to client_id
                // This should query the user-client association to ensure the target user
                // belongs to the specified client for multi-tenancy enforcement
            }

            // Additional validation for user_id having permission to manage expense_user_id
            if ($this->filled(['user_id', 'expense_user_id', 'client_id'])) {
                // TODO: Implement check to verify user_id has permission to manage expense_user_id
                // This should check the UserFeaturePermission table to ensure the admin user
                // has management rights for the target user within the client context
            }

            // Additional validation for client having OOP feature enabled
            if ($this->filled('client_id')) {
                // TODO: Implement check to verify client has OOP Expense feature enabled
                // This should query the ClientFeatures or similar table to ensure
                // feature_id = 16 (OOP Expense) is enabled for the specified client
            }
        });
    }

    /**
     * Get the validated data with proper type casting.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $validated = parent::validated();
        
        // Ensure integer fields are properly cast
        $validated['user_id'] = (int) $validated['user_id'];
        $validated['expense_user_id'] = (int) $validated['expense_user_id'];
        $validated['client_id'] = (int) $validated['client_id'];
        
        return $validated;
    }

    /**
     * Get the uploaded file instance.
     *
     * @return \Illuminate\Http\UploadedFile|null
     */
    public function getUploadedFile()
    {
        return $this->file('file');
    }

    /**
     * Get the admin user ID (user performing the upload).
     *
     * @return int
     */
    public function getAdminUserId(): int
    {
        return (int) $this->input('user_id');
    }

    /**
     * Get the target user ID (user for whom expenses are created).
     *
     * @return int
     */
    public function getTargetUserId(): int
    {
        return (int) $this->input('expense_user_id');
    }

    /**
     * Get the client ID.
     *
     * @return int
     */
    public function getClientId(): int
    {
        return (int) $this->input('client_id');
    }
}