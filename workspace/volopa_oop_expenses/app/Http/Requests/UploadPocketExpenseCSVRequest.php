<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Policies\PocketExpenseUploadPolicy;

/**
 * Upload Pocket Expense CSV Request
 * 
 * Handles validation for CSV batch upload operations.
 * Validates file format, size, user permissions, and client scoping.
 * Implements all-or-nothing validation approach per system constraints.
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
        // TODO: Implement authorization check using PocketExpenseUploadPolicy
        // Check if authenticated user has permission to upload expenses for the target user
        // Verify client context and OOP feature enablement
        return true; // Placeholder - implement policy check
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            // File validation as per system constraints
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB in KB
            ],
            
            // User ID validation (authenticated admin)
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            
            // Expense User ID validation (target user for expenses)
            'expense_user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            
            // Client ID validation
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
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
            // Additional server-side validation checks as per system constraints
            
            // Check if expense_user_id belongs to client_id
            if ($this->filled(['expense_user_id', 'client_id'])) {
                if (!$this->validateExpenseUserBelongsToClient()) {
                    $validator->errors()->add('expense_user_id', 'The expense user must belong to the specified client.');
                }
            }
            
            // Check if client has OOP feature enabled
            if ($this->filled('client_id')) {
                if (!$this->validateClientHasOOPFeature()) {
                    $validator->errors()->add('client_id', 'The client does not have OOP expense feature enabled.');
                }
            }
            
            // Check if user_id has permission to manage expense_user_id
            if ($this->filled(['user_id', 'expense_user_id', 'client_id'])) {
                if (!$this->validateUserHasManagementPermission()) {
                    $validator->errors()->add('user_id', 'You do not have permission to manage expenses for this user.');
                }
            }
            
            // Validate CSV file structure if file is present and valid
            if ($this->hasFile('file') && $this->file('file')->isValid()) {
                $this->validateCSVStructure($validator);
            }
        });
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A CSV file is required for upload.',
            'file.file' => 'The uploaded file must be a valid file.',
            'file.mimes' => 'The file must be a CSV or TXT file with CSV content.',
            'file.max' => 'The file size cannot exceed 10MB.',
            'user_id.required' => 'User ID is required.',
            'user_id.exists' => 'The specified user does not exist.',
            'expense_user_id.required' => 'Expense user ID is required.',
            'expense_user_id.exists' => 'The specified expense user does not exist.',
            'client_id.required' => 'Client ID is required.',
            'client_id.exists' => 'The specified client does not exist.',
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => 'CSV file',
            'user_id' => 'admin user',
            'expense_user_id' => 'expense user',
            'client_id' => 'client',
        ];
    }

    /**
     * Validate that expense_user_id belongs to client_id.
     * 
     * @return bool
     */
    protected function validateExpenseUserBelongsToClient(): bool
    {
        // TODO: Implement client-user relationship check
        // Query user-client association to ensure expense_user_id belongs to client_id
        // This depends on the platform's user-client relationship structure
        return true; // Placeholder - implement actual validation
    }

    /**
     * Validate that client has OOP expense feature enabled.
     * 
     * @return bool
     */
    protected function validateClientHasOOPFeature(): bool
    {
        // TODO: Implement feature enablement check
        // Query ClientFeatures or equivalent to check if client_id has feature_id=16 (OOP Expenses) enabled
        // This depends on the platform's feature enablement infrastructure
        return true; // Placeholder - implement actual validation
    }

    /**
     * Validate that user_id has permission to manage expense_user_id.
     * 
     * @return bool
     */
    protected function validateUserHasManagementPermission(): bool
    {
        // TODO: Implement permission check
        // Check UserFeaturePermission table for management rights
        // Verify role-based permissions per system constraints
        return true; // Placeholder - implement actual validation
    }

    /**
     * Validate CSV file structure and headers.
     * 
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateCSVStructure($validator): void
    {
        // TODO: Implement CSV structure validation
        // Check for mandatory header row presence
        // Validate exact column names match expected CSV schema
        // Verify file does not exceed maximum 200 rows constraint
        // This validation should be lightweight - detailed validation happens in PocketExpenseCSVValidator service
        
        $file = $this->file('file');
        
        try {
            $handle = fopen($file->getPathname(), 'r');
            
            if ($handle === false) {
                $validator->errors()->add('file', 'Unable to read the CSV file.');
                return;
            }
            
            // Check for header row
            $headers = fgetcsv($handle);
            if (empty($headers)) {
                $validator->errors()->add('file', 'CSV file must contain a header row.');
                fclose($handle);
                return;
            }
            
            // Count total rows (excluding header)
            $rowCount = 0;
            while (fgetcsv($handle) !== false) {
                $rowCount++;
                if ($rowCount > 200) { // Maximum 200 rows per CSV file constraint
                    $validator->errors()->add('file', 'CSV file cannot contain more than 200 data rows.');
                    break;
                }
            }
            
            fclose($handle);
            
            // Validate required headers exist
            $requiredHeaders = [
                'Date',
                'Expense Type', 
                'Currency Code',
                'Amount',
                'Merchant Name'
            ];
            
            $missingHeaders = array_diff($requiredHeaders, $headers);
            if (!empty($missingHeaders)) {
                $validator->errors()->add('file', 'CSV file is missing required headers: ' . implode(', ', $missingHeaders));
            }
            
        } catch (\Exception $e) {
            $validator->errors()->add('file', 'Error reading CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Get the validated data from the request.
     * 
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);
        
        // Ensure all required fields are present with proper types
        return [
            'file' => $validated['file'],
            'user_id' => (int) $validated['user_id'],
            'expense_user_id' => (int) $validated['expense_user_id'],
            'client_id' => (int) $validated['client_id'],
        ];
    }

    /**
     * Prepare the data for validation.
     * 
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure numeric fields are properly cast
        $this->merge([
            'user_id' => $this->integer('user_id'),
            'expense_user_id' => $this->integer('expense_user_id'), 
            'client_id' => $this->integer('client_id'),
        ]);
    }
}