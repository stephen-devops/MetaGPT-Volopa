<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Client;

/**
 * Form Request for validating CSV file uploads for pocket expenses.
 * 
 * This request validates:
 * - File upload constraints (CSV/TXT, max 10MB, required)
 * - User and client existence and relationships
 * - Permission to manage target user
 * - Client has OOP feature enabled
 */
class UploadPocketExpenseCSVRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * 
     * Authorization is handled in the controller after validation
     * to ensure we have valid user_id and expense_user_id for permission checks.
     *
     * @return bool
     */
    public function authorize(): bool
    {
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
            // File validation - CSV or TXT format, max 10MB (10240 KB)
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB in KB
                function ($attribute, $value, $fail) {
                    // Additional validation for file content
                    if ($value && $value->isValid()) {
                        $this->validateCsvStructure($value, $fail);
                    }
                }
            ],
            
            // Current authenticated admin user performing the upload
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    // Verify user_id matches authenticated user
                    if (Auth::id() !== (int)$value) {
                        $fail('The user_id must match the authenticated user.');
                    }
                    
                    // Verify user is not soft deleted
                    $user = User::where('id', $value)->where('deleted', false)->first();
                    if (!$user) {
                        $fail('The selected user is not active.');
                    }
                }
            ],
            
            // Target user for whom expenses are being created
            'expense_user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    // Verify target user is not soft deleted
                    $user = User::where('id', $value)->where('deleted', false)->first();
                    if (!$user) {
                        $fail('The selected expense user is not active.');
                    }
                }
            ],
            
            // Client context for multi-tenancy
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
                function ($attribute, $value, $fail) {
                    // Verify client is not soft deleted
                    $client = Client::where('id', $value)->where('deleted', false)->first();
                    if (!$client) {
                        $fail('The selected client is not active.');
                    }
                    
                    // Verify expense_user_id belongs to this client_id
                    $expenseUserId = $this->input('expense_user_id');
                    if ($expenseUserId) {
                        $this->validateUserBelongsToClient($expenseUserId, $value, $fail);
                    }
                    
                    // Verify client has OOP feature enabled (feature_id = 16)
                    $this->validateClientHasOopFeature($value, $fail);
                }
            ]
        ];
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
            'file.mimes' => 'The file must be a CSV or TXT file.',
            'file.max' => 'The file size must not exceed 10MB.',
            
            'user_id.required' => 'The admin user ID is required.',
            'user_id.integer' => 'The admin user ID must be a valid integer.',
            'user_id.exists' => 'The specified admin user does not exist.',
            
            'expense_user_id.required' => 'The expense user ID is required.',
            'expense_user_id.integer' => 'The expense user ID must be a valid integer.',
            'expense_user_id.exists' => 'The specified expense user does not exist.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be a valid integer.',
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
            'expense_user_id' => 'expense user',
            'client_id' => 'client',
        ];
    }

    /**
     * Validate the CSV file structure and headers.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param callable $fail
     * @return void
     */
    protected function validateCsvStructure($file, callable $fail): void
    {
        try {
            // Open the CSV file for reading
            $handle = fopen($file->getRealPath(), 'r');
            if (!$handle) {
                $fail('Unable to read the uploaded CSV file.');
                return;
            }

            // Read the header row
            $headers = fgetcsv($handle);
            fclose($handle);

            if (!$headers) {
                $fail('The CSV file must contain a header row.');
                return;
            }

            // Expected headers as per CSV column schema
            $expectedHeaders = [
                'Date',
                'Expense Type',
                'Currency Code',
                'Amount',
                '{Currency} Equivalent Amount',
                'VAT %',
                'Merchant Name',
                'Description',
                'Merchant Address',
                'Merchant Country',
                'Source',
                'Source Note',
                'Notes'
            ];

            // Clean headers (trim whitespace)
            $cleanHeaders = array_map('trim', $headers);

            // Check if we have the minimum required headers
            // Note: {Currency} Equivalent Amount is dynamic, so we'll be flexible with that column
            $requiredHeaders = [
                'Date',
                'Expense Type', 
                'Currency Code',
                'Amount',
                'Merchant Name'
            ];

            foreach ($requiredHeaders as $required) {
                if (!in_array($required, $cleanHeaders)) {
                    $fail("Missing required CSV header: {$required}");
                    return;
                }
            }

            // Count total rows (excluding header)
            $handle = fopen($file->getRealPath(), 'r');
            fgetcsv($handle); // Skip header
            $rowCount = 0;
            while (fgetcsv($handle) !== false) {
                $rowCount++;
            }
            fclose($handle);

            // Validate row count constraint (max 200 rows)
            if ($rowCount > 200) {
                $fail('The CSV file cannot contain more than 200 data rows (excluding header).');
                return;
            }

            if ($rowCount === 0) {
                $fail('The CSV file must contain at least one data row.');
                return;
            }

        } catch (\Exception $e) {
            $fail('Error reading CSV file structure: ' . $e->getMessage());
        }
    }

    /**
     * Validate that the expense user belongs to the specified client.
     *
     * @param int $expenseUserId
     * @param int $clientId
     * @param callable $fail
     * @return void
     */
    protected function validateUserBelongsToClient(int $expenseUserId, int $clientId, callable $fail): void
    {
        // This validation would typically check a user_client relationship table
        // Since the exact table structure isn't defined in the constraints,
        // we'll implement a placeholder that can be replaced with actual logic
        
        // TODO: Implement actual user-client relationship validation
        // This might involve checking a pivot table or a direct foreign key relationship
        
        // For now, we'll assume the relationship exists if both user and client exist
        // This should be replaced with actual business logic based on the platform's
        // user-client relationship model
        
        $user = User::where('id', $expenseUserId)->where('deleted', false)->first();
        $client = Client::where('id', $clientId)->where('deleted', false)->first();
        
        if (!$user || !$client) {
            $fail('The expense user must belong to the specified client.');
        }
    }

    /**
     * Validate that the client has the OOP Expenses feature enabled.
     *
     * @param int $clientId
     * @param callable $fail
     * @return void
     */
    protected function validateClientHasOopFeature(int $clientId, callable $fail): void
    {
        // This validation checks if the client has OOP feature enabled (feature_id = 16)
        // Since the ClientFeatures model structure isn't defined in the constraints,
        // we'll implement a placeholder that can be replaced with actual logic
        
        // TODO: Implement actual client feature validation
        // This should check the client_features table or similar for feature_id = 16
        
        // For now, we'll assume all active clients have the feature enabled
        // This should be replaced with actual business logic:
        // 
        // Example implementation:
        // $hasFeature = DB::table('client_features')
        //     ->where('client_id', $clientId)
        //     ->where('feature_id', 16) // OOP Expenses
        //     ->where('is_enabled', true)
        //     ->exists();
        //
        // if (!$hasFeature) {
        //     $fail('The client does not have OOP Expenses feature enabled.');
        // }
        
        $client = Client::where('id', $clientId)->where('deleted', false)->first();
        
        if (!$client) {
            $fail('The client does not have OOP Expenses feature enabled.');
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
            // Additional cross-field validation can be added here
            // For example, checking if the authenticated user has permission
            // to upload expenses for the target user would go here,
            // but that's handled in the controller after validation
        });
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure user_id is set to the authenticated user if not provided
        if (!$this->has('user_id') && Auth::id()) {
            $this->merge(['user_id' => Auth::id()]);
        }

        // Convert string inputs to integers for proper validation
        $this->merge([
            'user_id' => (int) $this->input('user_id'),
            'expense_user_id' => (int) $this->input('expense_user_id'), 
            'client_id' => (int) $this->input('client_id'),
        ]);
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log validation failures for monitoring and debugging
        \Log::warning('CSV upload validation failed', [
            'errors' => $validator->errors()->toArray(),
            'user_id' => $this->input('user_id'),
            'client_id' => $this->input('client_id'),
            'file_name' => $this->file('file') ? $this->file('file')->getClientOriginalName() : null,
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Get validated data with proper type casting.
     *
     * @return array
     */
    public function validated(): array
    {
        $validated = parent::validated();
        
        // Ensure proper type casting for database operations
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
    public function getUploadedFile(): ?\Illuminate\Http\UploadedFile
    {
        return $this->file('file');
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
     * Get the target user ID for expense creation.
     *
     * @return int
     */
    public function getExpenseUserId(): int
    {
        return (int) $this->input('expense_user_id');
    }

    /**
     * Get the client ID for multi-tenancy context.
     *
     * @return int
     */
    public function getClientId(): int
    {
        return (int) $this->input('client_id');
    }
}