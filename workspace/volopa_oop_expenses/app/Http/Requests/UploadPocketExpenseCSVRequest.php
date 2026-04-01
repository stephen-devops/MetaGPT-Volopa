<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

/**
 * UploadPocketExpenseCSVRequest
 * 
 * Form request validation for CSV file upload with comprehensive validation rules.
 * Handles multipart/form-data uploads with file size, type, and business logic validation.
 * 
 * Form fields:
 * - file: CSV file containing expenses (required|file|mimes:csv,txt|max:10240)
 * - user_id: Current authenticated admin user (required|exists:users,id)
 * - expense_user_id: Target user for whom expenses are created (required|exists:users,id)
 * - client_id: Client context id (required|exists:clients,id)
 */
class UploadPocketExpenseCSVRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * 
     * Authorization is handled by controller policies and middleware.
     * This method focuses on request validation only.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     * 
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            // File validation - max 10MB (10240 KB), CSV or TXT format only
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB limit as per system constraints
            ],
            
            // User ID validation - current authenticated admin user
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            
            // Target user for expense creation
            'expense_user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            
            // Client context validation
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     * 
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A CSV file is required for upload.',
            'file.file' => 'The uploaded file is not valid.',
            'file.mimes' => 'The file must be a CSV or TXT file.',
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
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Additional business logic validation will be performed here
            
            // Validate expense_user_id belongs to client_id
            if ($this->has('expense_user_id') && $this->has('client_id')) {
                // TODO: Implement user-client relationship validation
                // This should check if expense_user_id belongs to the specified client_id
                // $this->validateUserBelongsToClient($validator);
            }
            
            // Validate client has OOP feature enabled
            if ($this->has('client_id')) {
                // TODO: Implement client feature enablement check
                // This should verify that client_id has feature_id = 16 (OOP Expense) enabled
                // $this->validateClientHasOOPFeature($validator);
            }
            
            // Validate user_id has permission to manage expense_user_id
            if ($this->has('user_id') && $this->has('expense_user_id') && $this->has('client_id')) {
                // TODO: Implement permission validation
                // This should check if user_id has management rights over expense_user_id for client_id
                // $this->validateManagementPermission($validator);
            }
            
            // Validate CSV file structure (header row presence)
            if ($this->hasFile('file') && $this->file('file')->isValid()) {
                $this->validateCSVStructure($validator);
            }
        });
    }

    /**
     * Validate CSV file structure and header row.
     * 
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateCSVStructure(Validator $validator): void
    {
        $file = $this->file('file');
        
        try {
            // Open file for reading
            $handle = fopen($file->getPathname(), 'r');
            
            if ($handle === false) {
                $validator->errors()->add('file', 'Unable to read the uploaded CSV file.');
                return;
            }
            
            // Read first row (header)
            $header = fgetcsv($handle);
            fclose($handle);
            
            if ($header === false || empty($header)) {
                $validator->errors()->add('file', 'CSV file must contain a header row.');
                return;
            }
            
            // Expected CSV columns as per system constraints
            $expectedColumns = [
                'Date',
                'Expense Type',
                'Currency Code',
                'Amount',
                'USD Equivalent Amount', // Dynamic currency equivalent
                'VAT %',
                'Merchant Name',
                'Description',
                'Merchant Address',
                'Merchant Country',
                'Source',
                'Source Note',
                'Notes'
            ];
            
            // Validate header row contains all expected columns
            $missingColumns = array_diff($expectedColumns, $header);
            if (!empty($missingColumns)) {
                $validator->errors()->add(
                    'file', 
                    'CSV header is missing required columns: ' . implode(', ', $missingColumns)
                );
                return;
            }
            
            // Check for maximum 200 rows constraint (header + data rows)
            $lineCount = $this->countCSVLines($file->getPathname());
            if ($lineCount > 201) { // 1 header + 200 data rows max
                $validator->errors()->add(
                    'file', 
                    'CSV file exceeds maximum allowed rows. Maximum 200 data rows allowed (excluding header).'
                );
                return;
            }
            
            // Minimum 1 data row required (header + at least 1 data row)
            if ($lineCount < 2) {
                $validator->errors()->add('file', 'CSV file must contain at least one data row besides the header.');
                return;
            }
            
        } catch (\Exception $e) {
            $validator->errors()->add('file', 'Error reading CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Count total lines in CSV file efficiently.
     * 
     * @param string $filePath
     * @return int
     */
    protected function countCSVLines(string $filePath): int
    {
        $lineCount = 0;
        $handle = fopen($filePath, 'r');
        
        if ($handle === false) {
            return 0;
        }
        
        while (fgets($handle) !== false) {
            $lineCount++;
        }
        
        fclose($handle);
        return $lineCount;
    }

    /**
     * TODO: Validate that expense_user_id belongs to the specified client_id.
     * 
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateUserBelongsToClient(Validator $validator): void
    {
        // TODO: Implement user-client relationship validation
        // This should query the user-client relationship to ensure expense_user_id
        // belongs to the specified client_id for multi-tenancy security
        
        /*
        $userBelongsToClient = User::where('id', $this->expense_user_id)
            ->where('client_id', $this->client_id)
            ->exists();
            
        if (!$userBelongsToClient) {
            $validator->errors()->add('expense_user_id', 'The specified user does not belong to the selected client.');
        }
        */
    }

    /**
     * TODO: Validate that client has OOP Expense feature enabled.
     * 
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateClientHasOOPFeature(Validator $validator): void
    {
        // TODO: Implement client feature enablement check
        // This should verify that client_id has feature_id = 16 (OOP Expense) enabled
        // through the ClientFeatures or similar relationship
        
        /*
        $hasOOPFeature = ClientFeatures::where('client_id', $this->client_id)
            ->where('feature_id', 16) // OOP Expense feature ID
            ->where('is_enabled', true)
            ->exists();
            
        if (!$hasOOPFeature) {
            $validator->errors()->add('client_id', 'OOP Expense feature is not enabled for this client.');
        }
        */
    }

    /**
     * TODO: Validate that user_id has permission to manage expense_user_id.
     * 
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateManagementPermission(Validator $validator): void
    {
        // TODO: Implement permission validation using UserFeaturePermissionService
        // This should check if user_id has management rights over expense_user_id 
        // for the specified client_id based on role and delegation rules
        
        /*
        $permissionService = app(UserFeaturePermissionService::class);
        $canManage = $permissionService->canUserManageTarget(
            $this->user_id, 
            $this->expense_user_id, 
            $this->client_id
        );
        
        if (!$canManage) {
            $validator->errors()->add('expense_user_id', 'You do not have permission to manage expenses for this user.');
        }
        */
    }

    /**
     * Handle a failed validation attempt.
     * 
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     * 
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        // Return 422 status with validation errors in expected format
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'upload_id' => null,
                'total_rows' => 0,
                'error_count' => $validator->errors()->count(),
                'errors' => $this->formatValidationErrors($validator->errors())
            ], 422)
        );
    }

    /**
     * Format validation errors to match expected CSV upload error format.
     * 
     * @param \Illuminate\Support\MessageBag $errors
     * @return array
     */
    protected function formatValidationErrors($errors): array
    {
        $formattedErrors = [];
        
        foreach ($errors->getMessages() as $field => $messages) {
            foreach ($messages as $message) {
                $formattedErrors[] = [
                    'line_number' => 1, // Form validation errors are considered header/form level
                    'field' => $field,
                    'error' => $message,
                    'value' => $this->input($field, '')
                ];
            }
        }
        
        return $formattedErrors;
    }

    /**
     * Get the validated file instance.
     * 
     * @return \Illuminate\Http\UploadedFile|null
     */
    public function getValidatedFile()
    {
        return $this->file('file');
    }

    /**
     * Get all validated form data as array.
     * 
     * @return array
     */
    public function getValidatedData(): array
    {
        return $this->validated();
    }

    /**
     * Get the target user ID for expense creation.
     * 
     * @return int
     */
    public function getExpenseUserId(): int
    {
        return (int) $this->input('expense_user_id');
    }

    /**
     * Get the admin user ID performing the upload.
     * 
     * @return int
     */
    public function getAdminUserId(): int
    {
        return (int) $this->input('user_id');
    }

    /**
     * Get the client ID for the upload context.
     * 
     * @return int
     */
    public function getClientId(): int
    {
        return (int) $this->input('client_id');
    }
}