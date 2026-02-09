## Code: app/Http/Requests/UploadPocketExpenseCsvRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\Client;
use App\Models\UserFeaturePermission;
use App\Policies\ExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;

class UploadPocketExpenseCsvRequest extends FormRequest
{
    /**
     * Feature ID for out-of-pocket expenses.
     */
    const OOP_EXPENSE_FEATURE_ID = 1;

    /**
     * Maximum file size in bytes (10MB).
     */
    const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum number of rows allowed in CSV.
     */
    const MAX_CSV_ROWS = 1000;

    /**
     * Minimum number of rows required in CSV (excluding header).
     */
    const MIN_CSV_ROWS = 1;

    /**
     * Allowed MIME types for CSV files.
     */
    const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/octet-stream',
    ];

    /**
     * Required CSV headers in the exact order.
     */
    const REQUIRED_CSV_HEADERS = [
        'Date',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Name',
    ];

    /**
     * Optional CSV headers.
     */
    const OPTIONAL_CSV_HEADERS = [
        'Currency Equivalent Amount',
        'VAT %',
        'Description',
        'Merchant Address',
        'Merchant Country',
        'Source',
        'Source Note',
        'Notes',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $expensePolicy = new ExpensePolicy();
        
        // Check if user can upload CSV files in general
        if (!$expensePolicy->uploadCsv($this->user())) {
            return false;
        }
        
        // If uploading for another user, check specific authorization
        $expenseUserId = $this->input('expense_user_id');
        $clientId = $this->input('client_id');
        
        if ($expenseUserId && $expenseUserId !== $this->user()->id) {
            return $expensePolicy->uploadCsvForUser($this->user(), $expenseUserId, $clientId);
        }
        
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:' . (self::MAX_FILE_SIZE / 1024), // Convert to KB for Laravel validation
                function ($attribute, $value, $fail) {
                    $this->validateFileStructure($value, $fail);
                },
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
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A CSV file is required for upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The file must be a CSV file (csv, txt).',
            'file.max' => 'The file size may not be greater than ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB.',
            'expense_user_id.required' => 'The expense user is required.',
            'expense_user_id.integer' => 'The expense user must be a valid user ID.',
            'expense_user_id.exists' => 'The selected expense user does not exist.',
            'client_id.required' => 'The client is required.',
            'client_id.integer' => 'The client must be a valid client ID.',
            'client_id.exists' => 'The selected client does not exist.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'file' => 'CSV file',
            'expense_user_id' => 'expense user',
            'client_id' => 'client',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateUserBelongsToClient($validator);
            $this->validateUserHasOopFeature($validator);
            $this->validateUploadPermissions($validator);
            $this->validateClientFeatureEnabled($validator);
        });
    }

    /**
     * Validate CSV file structure and content.
     */
    protected function validateFileStructure(UploadedFile $file, $fail): void
    {
        if (!$file->isValid()) {
            $fail('The uploaded file is not valid.');
            return;
        }

        // Check MIME type
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            $fail('The file must be a valid CSV file.');
            return;
        }

        // Check file extension
        $allowedExtensions = ['csv', 'txt'];
        if (!in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions)) {
            $fail('The file must have a .csv or .txt extension.');
            return;
        }

        // Validate CSV structure
        try {
            $this->validateCsvStructure($file, $fail);
        } catch (\Exception $e) {
            $fail('Error reading CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Validate CSV structure including headers and row count.
     */
    protected function validateCsvStructure(UploadedFile $file, $fail): void
    {
        $filePath = $file->getRealPath();
        
        if (!$filePath || !file_exists($filePath)) {
            $fail('Unable to read the uploaded file.');
            return;
        }

        $handle = fopen($filePath, 'r');
        
        if (!$handle) {
            $fail('Unable to open the CSV file for reading.');
            return;
        }

        try {
            // Read and validate header row
            $headerRow = fgetcsv($handle);
            
            if ($headerRow === false || empty($headerRow)) {
                $fail('The CSV file must contain a header row.');
                fclose($handle);
                return;
            }

            // Trim whitespace from headers
            $headerRow = array_map('trim', $headerRow);

            // Check for required headers
            $missingHeaders = [];
            foreach (self::REQUIRED_CSV_HEADERS as $requiredHeader) {
                if (!in_array($requiredHeader, $headerRow)) {
                    $missingHeaders[] = $requiredHeader;
                }
            }

            if (!empty($missingHeaders)) {
                $fail('The CSV file is missing required headers: ' . implode(', ', $missingHeaders));
                fclose($handle);
                return;
            }

            // Check for unknown headers
            $allValidHeaders = array_merge(self::REQUIRED_CSV_HEADERS, self::OPTIONAL_CSV_HEADERS);
            $unknownHeaders = [];
            foreach ($headerRow as $header) {
                if (!in_array($header, $allValidHeaders) && !empty($header)) {
                    $unknownHeaders[] = $header;
                }
            }

            if (!empty($unknownHeaders)) {
                $fail('The CSV file contains unknown headers: ' . implode(', ', $unknownHeaders));
                fclose($handle);
                return;
            }

            // Count data rows
            $rowCount = 0;
            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty rows
                if (array_filter($row, function($value) { return trim($value) !== ''; })) {
                    $rowCount++;
                }
                
                // Check maximum row limit
                if ($rowCount > self::MAX_CSV_ROWS) {
                    $fail('The CSV file contains too many rows. Maximum allowed: ' . self::MAX_CSV_ROWS . ' rows.');
                    fclose($handle);
                    return;
                }
            }

            // Check minimum row requirement
            if ($rowCount < self::MIN_CSV_ROWS) {
                $fail('The CSV file must contain at least ' . self::MIN_CSV_ROWS . ' data row(s).');
                fclose($handle);
                return;
            }

            fclose($handle);
            
        } catch (\Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $fail('Error validating CSV structure: ' . $e->getMessage());
        }
    }

    /**
     * Validate that the expense user belongs to the specified client.
     */
    protected function validateUserBelongsToClient($validator): void
    {
        $expenseUserId = $this->input('expense_user_id');
        $clientId = $this->input('client_id');
        
        if ($expenseUserId && $clientId) {
            // Check if user exists and is associated with the client
            $user = User::find($expenseUserId);
            $client = Client::find($clientId);
            
            if ($user && $client) {
                // Check if user has any permissions for this client
                $hasClientAccess = UserFeaturePermission::forUserAndClient($expenseUserId, $clientId)
                    ->exists();
                
                if (!$hasClientAccess) {
                    $validator->errors()->add('expense_user_id', 'The selected user does not have access to this client.');
                }
            }
        }
    }

    /**
     * Validate that the expense user has the out-of-pocket expense feature enabled.
     */
    protected function validateUserHasOopFeature($validator): void
    {
        $expenseUserId = $this->input('expense_user_id');
        $clientId = $this->input('client_id');
        
        if ($expenseUserId && $clientId) {
            $hasOopFeature = UserFeaturePermission::hasPermission(
                $expenseUserId, 
                $clientId, 
                self::OOP_EXPENSE_FEATURE_ID
            );
            
            if (!$hasOopFeature) {
                $validator->errors()->add('expense_user_id', 'The selected user does not have out-of-pocket expense feature enabled for this client.');
            }
        }
    }

    /**
     * Validate upload permissions for the current user.
     */
    protected function validateUploadPermissions($validator): void
    {
        $expenseUserId = $this->input('expense_user_id');
        $clientId = $this->input('client_id');
        $currentUserId = $this->user()->id;
        
        if ($expenseUserId && $clientId && $expenseUserId !== $currentUserId) {
            // Current user is trying to upload for another user
            // Check if current user has permission to manage the expense user
            $expensePolicy = new ExpensePolicy();
            
            if (!$expensePolicy->createForUser($this->user(), $expenseUserId, $clientId)) {
                $validator->errors()->add('expense_user_id', 'You do not have permission to upload expenses for this user.');
            }
        }
    }

    /**
     * Validate that the client has the out-of-pocket expense feature enabled.
     */
    protected function validateClientFeatureEnabled($validator): void
    {
        $clientId = $this->input('client_id');
        
        if ($clientId) {
            // Check if any user in this client has the OOP expense feature enabled
            // This indicates the client has the feature available
            $clientHasFeature = UserFeaturePermission::forClient($clientId)
                ->forFeature(self::OOP_EXPENSE_FEATURE_ID)
                ->enabled()
                ->exists();
            
            if (!$clientHasFeature) {
                $validator->errors()->add('client_id', 'The out-of-pocket expense feature is not enabled for this client.');
            }
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $data = [];
        
        // Ensure expense_user_id is set to current user if not provided
        if (!$this->has('expense_user_id')) {
            $data['expense_user_id'] = $this->user()->id;
        }
        
        // Convert string IDs to integers if provided
        if ($this->has('expense_user_id')) {
            $data['expense_user_id'] = (int) $this->input('expense_user_id');
        }
        
        if ($this->has('client_id')) {
            $data['client_id'] = (int) $this->input('client_id');
        }
        
        if (!empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Get the validated file for upload processing.
     */
    public function getValidatedFile(): UploadedFile
    {
        return $this->file('file');
    }

    /**
     * Get the validated expense user ID.
     */
    public function getValidatedExpenseUserId(): int
    {
        return $this->validated()['expense_user_id'];
    }

    /**
     * Get the validated client ID.
     */
    public function getValidatedClientId(): int
    {
        return $this->validated()['client_id'];
    }

    /**
     * Get file upload metadata.
     */
    public function getFileMetadata(): array
    {
        $file = $this->file('file');
        
        return [
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
        ];
    }

    /**
     * Check if the file exceeds the recommended size for synchronous processing.
     */
    public function shouldProcessAsync(): bool
    {
        $file = $this->file('file');
        
        if (!$file) {
            return false;
        }
        
        // Process async for files larger than 1MB or estimated to have more than 100 rows
        $asyncThreshold = 1048576; // 1MB
        
        return $file->getSize() > $asyncThreshold;
    }

    /**
     * Get estimated number of rows in the CSV file.
     */
    public function getEstimatedRowCount(): int
    {
        $file = $this->file('file');
        
        if (!$file) {
            return 0;
        }
        
        try {
            $handle = fopen($file->getRealPath(), 'r');
            
            if (!$handle) {
                return 0;
            }
            
            $rowCount = 0;
            
            // Skip header row
            fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== false) {
                if (array_filter($row, function($value) { return trim($value) !== ''; })) {
                    $rowCount++;
                }
            }
            
            fclose($handle);
            
            return $rowCount;
            
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get CSV headers from the uploaded file.
     */
    public function getCsvHeaders(): array
    {
        $file = $this->file('file');
        
        if (!$file) {
            return [];
        