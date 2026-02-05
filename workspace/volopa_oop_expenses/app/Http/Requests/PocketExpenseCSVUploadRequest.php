## Code: app/Http/Requests/PocketExpenseCSVUploadRequest.php

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PocketExpense;
use App\Policies\PocketExpensePolicy;
use Illuminate\Http\UploadedFile;

/**
 * PocketExpenseCSVUploadRequest
 * 
 * Handles validation and authorization for CSV file uploads for pocket expenses.
 * Validates file format, size, user permissions, and client access.
 * Implements policy-based authorization using PocketExpensePolicy.
 * Ensures CSV files meet requirements before processing.
 */
class PocketExpenseCSVUploadRequest extends FormRequest
{
    /**
     * Maximum file size in bytes (10MB).
     */
    private const MAX_FILE_SIZE = 10485760;

    /**
     * Maximum number of rows allowed in CSV file.
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Allowed MIME types for CSV uploads.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/octet-stream',
        'application/csv',
        'application/excel',
        'application/vnd.ms-excel',
        'application/vnd.msexcel',
    ];

    /**
     * Allowed file extensions.
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $clientId = $this->input('client_id');
        $targetUserId = $this->input('user_id', $user->id);

        if (!$user || !$clientId) {
            return false;
        }

        // Use policy to check authorization for CSV upload
        return $user->can('uploadCSV', [PocketExpense::class, $clientId, $targetUserId]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $clientId = $this->input('client_id');
        
        return [
            // Required fields
            'csv_file' => [
                'required',
                'file',
                'max:' . (self::MAX_FILE_SIZE / 1024), // Convert to KB for Laravel validation
                function ($attribute, $value, $fail) {
                    if (!($value instanceof UploadedFile)) {
                        $fail('The uploaded file is not valid.');
                        return;
                    }

                    // Check file extension
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                        $fail('The file must be a CSV file with .csv or .txt extension.');
                        return;
                    }

                    // Check MIME type
                    $mimeType = $value->getMimeType();
                    if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
                        $fail('The file must be a valid CSV file.');
                        return;
                    }

                    // Check if file is readable
                    if (!$value->isValid()) {
                        $fail('The uploaded file is corrupted or invalid.');
                        return;
                    }

                    // Basic CSV format validation
                    $this->validateCSVStructure($value, $fail);
                },
            ],
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($user, $clientId) {
                    // Additional authorization check for target user
                    if (!$user->can('uploadCSV', [PocketExpense::class, $clientId, $value])) {
                        $fail('You do not have permission to upload expenses for this user.');
                    }
                },
            ],
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'auto_submit' => [
                'nullable',
                'boolean',
            ],
            'notification_email' => [
                'nullable',
                'email',
                'max:255',
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
        $maxSizeMB = self::MAX_FILE_SIZE / (1024 * 1024);
        
        return [
            'csv_file.required' => 'A CSV file is required for upload.',
            'csv_file.file' => 'The uploaded item must be a valid file.',
            'csv_file.max' => "The CSV file may not be larger than {$maxSizeMB}MB.",
            
            'user_id.required' => 'The target user is required.',
            'user_id.integer' => 'The user must be a valid user ID.',
            'user_id.exists' => 'The selected user does not exist.',
            
            'client_id.required' => 'The client is required.',
            'client_id.integer' => 'The client must be a valid client ID.',
            'client_id.exists' => 'The selected client does not exist.',
            
            'notes.string' => 'The notes must be a string.',
            'notes.max' => 'The notes may not be greater than 2000 characters.',
            
            'auto_submit.boolean' => 'The auto submit flag must be true or false.',
            
            'notification_email.email' => 'The notification email must be a valid email address.',
            'notification_email.max' => 'The notification email may not be greater than 255 characters.',
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
            'csv_file' => 'CSV file',
            'user_id' => 'target user',
            'client_id' => 'client',
            'auto_submit' => 'auto submit',
            'notification_email' => 'notification email',
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
        $this->merge([
            'auto_submit' => $this->boolean('auto_submit', false),
            'user_id' => $this->input('user_id', $this->user()->id ?? null),
        ]);

        // Clean up notes
        if ($this->has('notes') && is_string($this->input('notes'))) {
            $this->merge([
                'notes' => trim($this->input('notes')),
            ]);
        }

        // Clean up notification email
        if ($this->has('notification_email') && is_string($this->input('notification_email'))) {
            $this->merge([
                'notification_email' => strtolower(trim($this->input('notification_email'))),
            ]);
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
            // Additional validation after main rules
            $this->validateClientUserRelationship($validator);
            $this->validateFilePermissions($validator);
        });
    }

    /**
     * Validate CSV file structure and basic format.
     *
     * @param UploadedFile $file
     * @param callable $fail
     * @return void
     */
    private function validateCSVStructure(UploadedFile $file, callable $fail): void
    {
        try {
            // Open file for reading
            $handle = fopen($file->getRealPath(), 'r');
            if (!$handle) {
                $fail('Unable to read the CSV file.');
                return;
            }

            // Check if file is empty
            if (feof($handle)) {
                fclose($handle);
                $fail('The CSV file is empty.');
                return;
            }

            // Read header row
            $header = fgetcsv($handle);
            if (!$header || empty($header)) {
                fclose($handle);
                $fail('The CSV file must have a header row.');
                return;
            }

            // Count total rows (excluding header)
            $rowCount = 0;
            while (($row = fgetcsv($handle)) !== false && $rowCount < self::MAX_CSV_ROWS + 1) {
                if (!empty(array_filter($row))) { // Skip completely empty rows
                    $rowCount++;
                }
            }

            fclose($handle);

            // Check row count limit
            if ($rowCount > self::MAX_CSV_ROWS) {
                $fail("The CSV file contains {$rowCount} rows, but the maximum allowed is " . self::MAX_CSV_ROWS . " rows.");
                return;
            }

            // Check minimum rows
            if ($rowCount === 0) {
                $fail('The CSV file must contain at least one data row besides the header.');
                return;
            }

            // Validate required columns are present
            $this->validateRequiredColumns($header, $fail);

        } catch (\Exception $e) {
            $fail('Error reading CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Validate that required CSV columns are present.
     *
     * @param array $header
     * @param callable $fail
     * @return void
     */
    private function validateRequiredColumns(array $header, callable $fail): void
    {
        // Required columns based on CSV mapping
        $requiredColumns = [
            'Date',
            'Expense Type',
            'Currency Code',
            'Amount',
            'Merchant Name',
        ];

        // Normalize header for case-insensitive comparison
        $normalizedHeader = array_map('strtolower', array_map('trim', $header));
        
        $missingColumns = [];
        
        foreach ($requiredColumns as $required) {
            $normalizedRequired = strtolower($required);
            if (!in_array($normalizedRequired, $normalizedHeader)) {
                $missingColumns[] = $required;
            }
        }

        if (!empty($missingColumns)) {
            $fail('The CSV file is missing required columns: ' . implode(', ', $missingColumns));
        }
    }

    /**
     * Validate client-user relationship.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    private function validateClientUserRelationship($validator): void
    {
        $clientId = $this->input('client_id');
        $userId = $this->input('user_id');

        if ($clientId && $userId) {
            // Check if user belongs to client or if current user can manage target user
            $user = $this->user();
            if (!$user->can('create', [PocketExpense::class, $clientId, $userId])) {
                $validator->errors()->add('user_id', 'The selected user does not belong to this client or you do not have permission to manage their expenses.');
            }
        }
    }

    /**
     * Validate file upload permissions and limits.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    private function validateFilePermissions($validator): void
    {
        $user = $this->user();
        $clientId = $this->input('client_id');

        if (!$user || !$clientId) {
            return;
        }

        // Check if client has out-of-pocket expense feature enabled
        // This would typically check a client features table
        // For now, we'll assume it's part of the policy check

        // Check if user has reached upload limits (daily/monthly)
        // This could be implemented based on business requirements
        $todayUploads = \App\Models\PocketExpenseFileUpload::active()
            ->forUser($user->id)
            ->forClient($clientId)
            ->whereDate('create_time', today())
            ->count();

        if ($todayUploads >= 10) { // Example limit
            $validator->errors()->add('csv_file', 'You have reached the daily upload limit of 10 files.');
        }
    }

    /**
     * Get the error messages for defined validation rules.
     *
     * @return array<string, string>
     */
    public function getValidationErrorMessages(): array
    {
        return [
            'file_too_large' => 'The CSV file is too large. Maximum size is ' . (self::MAX_FILE_SIZE / (1024 * 1024)) . 'MB.',
            'invalid_format' => 'The file must be a valid CSV file.',
            'too_many_rows' => 'The CSV file contains too many rows. Maximum allowed is ' . self::MAX_CSV_ROWS . ' rows.',
            'empty_file' => 'The CSV file is empty or contains no valid data rows.',
            'missing_columns' => 'The CSV file is missing required columns.',
            'permission_denied' => 'You do not have permission to upload expenses for this user.',
            'invalid_client' => 'The selected client is invalid or you do not have access to it.',
            'upload_limit_exceeded' => 'You have exceeded the upload limits for today.',
        ];
    }

    /**
     * Get the expected CSV column headers.
     *
     * @return array<string>
     */
    public function getExpectedCSVColumns(): array
    {
        return [
            // Required columns
            'Date',
            'Expense Type',
            'Currency Code',
            'Amount',
            'Merchant Name',
            
            // Optional columns
            'Currency Equivalent Amount',
            'VAT %',
            'Description',
            'Merchant Address',
            'Merchant Country',
            'Source',
            'Source Note',
            'Notes',
        ];
    }

    /**
     * Get file upload configuration.
     *
     * @return array<string, mixed>
     */
    public function getFileUploadConfig(): array
    {
        return [
            'max_file_size' => self::MAX_FILE_SIZE,
            'max_csv_rows' => self::MAX_CSV_ROWS,
            'allowed_mime_types' => self::ALLOWED_MIME_TYPES,
            'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            'required_columns' => [
                'Date',
                'Expense Type',
                'Currency Code',
                'Amount',
                'Merchant Name',
            ],
            'optional_columns' => [
                'Currency Equivalent Amount',
                'VAT %',
                'Description',
                'Merchant Address',
                'Merchant Country',
                'Source',
                'Source Note',
                'Notes',
            ],
        ];
    }

    /**
     * Check if the uploaded file is valid CSV.
     *
     * @return bool
     */
    public function hasValidCSVFile(): bool
    {
        $file = $this->file('csv_file');
        
        if (!$file || !$file->isValid()) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();

        return in_array($extension, self::ALLOWED_EXTENSIONS) && 
               in_array($mimeType, self::ALLOWED_MIME_TYPES);
    }

    /**
     * Get the uploaded CSV file.
     *
     * @return UploadedFile|null
     */
    public function getCSVFile(): ?UploadedFile
    {
        return $this->file('csv_file');
    }