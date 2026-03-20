<?php

namespace App\Http\Requests;

use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * UploadPocketExpenseCSVRequest
 * 
 * Form request for CSV file upload with validation rules and policy authorization.
 * Handles file validation, user permission checks, and target user validation.
 * Maximum 200 rows per CSV file, supports CSV and TXT formats up to 10MB.
 */
class UploadPocketExpenseCSVRequest extends FormRequest
{
    /**
     * Maximum allowed file size in bytes (10MB).
     */
    const MAX_FILE_SIZE = 10485760; // 10MB in bytes

    /**
     * Maximum allowed rows per CSV file.
     */
    const MAX_ROWS = 200;

    /**
     * Allowed file MIME types for CSV upload.
     *
     * @var array<string>
     */
    protected array $allowedMimeTypes = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'text/comma-separated-values',
    ];

    /**
     * Allowed file extensions.
     *
     * @var array<string>
     */
    protected array $allowedExtensions = [
        'csv',
        'txt',
    ];

    /**
     * Determine if the user is authorized to make this request.
     * Uses PocketExpensePolicy to check if user can upload CSV files.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        // Check if user has permission to create pocket expenses
        // This implies they can also upload CSV files for expense creation
        $policy = new PocketExpensePolicy();
        return $policy->create($user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // CSV file validation
            'csv_file' => [
                'required',
                'file',
                'max:' . (self::MAX_FILE_SIZE / 1024), // Laravel expects KB, convert from bytes
                'mimes:csv,txt',
                'mimetypes:' . implode(',', $this->allowedMimeTypes),
                function ($attribute, $value, $fail) {
                    $this->validateFileExtension($attribute, $value, $fail);
                },
                function ($attribute, $value, $fail) {
                    $this->validateFileContent($attribute, $value, $fail);
                },
                function ($attribute, $value, $fail) {
                    $this->validateRowCount($attribute, $value, $fail);
                },
            ],

            // Target user validation
            'target_user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id'),
                function ($attribute, $value, $fail) {
                    $this->validateTargetUserClientScope($attribute, $value, $fail);
                },
                function ($attribute, $value, $fail) {
                    $this->validateUserManagementPermission($attribute, $value, $fail);
                },
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'csv_file.required' => 'A CSV file is required for upload.',
            'csv_file.file' => 'The uploaded item must be a valid file.',
            'csv_file.max' => 'The CSV file size cannot exceed ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB.',
            'csv_file.mimes' => 'The file must be a CSV or TXT file.',
            'csv_file.mimetypes' => 'The file must have a valid CSV MIME type.',
            
            'target_user_id.required' => 'Target user ID is required for expense assignment.',
            'target_user_id.integer' => 'Target user ID must be a valid integer.',
            'target_user_id.min' => 'Target user ID must be a positive integer.',
            'target_user_id.exists' => 'The specified target user does not exist.',
        ];
    }

    /**
     * Get custom attribute names for validation error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'csv_file' => 'CSV file',
            'target_user_id' => 'target user',
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
            // Additional cross-field validation can be added here
            $this->validateOverallFileIntegrity($validator);
        });
    }

    /**
     * Validate that the file has the correct extension.
     *
     * @param string $attribute
     * @param \Illuminate\Http\UploadedFile $value
     * @param \Closure $fail
     * @return void
     */
    protected function validateFileExtension(string $attribute, $value, \Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        
        if (!in_array($extension, $this->allowedExtensions)) {
            $fail("The {$attribute} must have a valid extension: " . implode(', ', $this->allowedExtensions));
        }
    }

    /**
     * Validate file content is readable and appears to be CSV format.
     *
     * @param string $attribute
     * @param \Illuminate\Http\UploadedFile $value
     * @param \Closure $fail
     * @return void
     */
    protected function validateFileContent(string $attribute, $value, \Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return;
        }

        try {
            // Attempt to read the first few lines to validate it's readable
            $handle = fopen($value->getPathname(), 'r');
            
            if ($handle === false) {
                $fail("The {$attribute} could not be read. Please ensure it's a valid CSV file.");
                return;
            }

            // Read first line to check if it looks like a CSV header
            $firstLine = fgets($handle);
            fclose($handle);

            if ($firstLine === false || empty(trim($firstLine))) {
                $fail("The {$attribute} appears to be empty or corrupted.");
                return;
            }

            // Basic CSV format validation - should contain commas or semicolons
            if (!preg_match('/[,;]/', $firstLine)) {
                $fail("The {$attribute} does not appear to be in CSV format. Expected comma or semicolon separated values.");
            }

        } catch (\Exception $e) {
            $fail("The {$attribute} could not be processed. Please ensure it's a valid CSV file.");
        }
    }

    /**
     * Validate that the CSV file doesn't exceed the maximum row count.
     *
     * @param string $attribute
     * @param \Illuminate\Http\UploadedFile $value
     * @param \Closure $fail
     * @return void
     */
    protected function validateRowCount(string $attribute, $value, \Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return;
        }

        try {
            $handle = fopen($value->getPathname(), 'r');
            
            if ($handle === false) {
                return; // Let other validators handle file read errors
            }

            $rowCount = 0;
            $isFirstLine = true;

            while (($line = fgets($handle)) !== false) {
                // Skip empty lines
                if (empty(trim($line))) {
                    continue;
                }

                // Skip header row
                if ($isFirstLine) {
                    $isFirstLine = false;
                    continue;
                }

                $rowCount++;

                // Early termination if we exceed the limit
                if ($rowCount > self::MAX_ROWS) {
                    fclose($handle);
                    $fail("The {$attribute} contains too many rows. Maximum allowed is " . self::MAX_ROWS . " rows (excluding header).");
                    return;
                }
            }

            fclose($handle);

            // Check minimum rows
            if ($rowCount === 0) {
                $fail("The {$attribute} must contain at least one data row (excluding header).");
            }

        } catch (\Exception $e) {
            $fail("Unable to validate row count in the {$attribute}. Please ensure it's a valid CSV file.");
        }
    }

    /**
     * Validate that target user belongs to the same client as the authenticated user.
     *
     * @param string $attribute
     * @param mixed $value
     * @param \Closure $fail
     * @return void
     */
    protected function validateTargetUserClientScope(string $attribute, $value, \Closure $fail): void
    {
        $authenticatedUser = Auth::user();
        
        if (!$authenticatedUser || !isset($authenticatedUser->client_id)) {
            $fail("Unable to verify client scope for {$attribute}.");
            return;
        }

        // Get the target user and check client_id
        $targetUser = \App\Models\User::find($value);
        
        if (!$targetUser) {
            return; // Let exists validation handle this
        }

        if ($targetUser->client_id !== $authenticatedUser->client_id) {
            $fail("The {$attribute} must belong to the same client as the authenticated user.");
        }
    }

    /**
     * Validate that authenticated user has permission to manage the target user.
     *
     * @param string $attribute
     * @param mixed $value
     * @param \Closure $fail
     * @return void
     */
    protected function validateUserManagementPermission(string $attribute, $value, \Closure $fail): void
    {
        $authenticatedUser = Auth::user();
        
        if (!$authenticatedUser) {
            $fail("Authentication required to validate {$attribute}.");
            return;
        }

        // If target user is the same as authenticated user, always allow
        if ((int)$value === $authenticatedUser->id) {
            return;
        }

        // Check if authenticated user has permission to manage other users
        // This would typically check user roles or specific permissions
        // For now, we'll implement basic role-based checks

        $userRole = $authenticatedUser->role ?? 'Card User';
        
        // Primary Admin can manage all users
        if ($userRole === 'Primary Admin') {
            return;
        }

        // Admin can manage users within their client
        if ($userRole === 'Admin') {
            return; // Client scope is already validated above
        }

        // Business Users and Card Users can only upload for themselves
        if (in_array($userRole, ['Business User', 'Card User'])) {
            $fail("You do not have permission to upload expenses for other users.");
            return;
        }

        // Unknown role - deny by default
        $fail("Insufficient permissions to upload expenses for the specified {$attribute}.");
    }

    /**
     * Perform overall file integrity validation.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateOverallFileIntegrity(\Illuminate\Validation\Validator $validator): void
    {
        $csvFile = $this->file('csv_file');
        
        if (!$csvFile || !$csvFile->isValid()) {
            return;
        }

        try {
            // Additional integrity checks
            $fileSize = $csvFile->getSize();
            
            // Check if file is suspiciously small (less than 50 bytes)
            if ($fileSize < 50) {
                $validator->errors()->add('csv_file', 'The CSV file appears to be too small to contain valid data.');
                return;
            }

            // Check if file is exactly at the size limit (might be truncated)
            if ($fileSize >= self::MAX_FILE_SIZE) {
                $validator->errors()->add('csv_file', 'The CSV file is at the maximum size limit and may be truncated.');
                return;
            }

            // Additional encoding validation
            $this->validateFileEncoding($csvFile, $validator);

        } catch (\Exception $e) {
            $validator->errors()->add('csv_file', 'Unable to validate file integrity. Please try uploading again.');
        }
    }

    /**
     * Validate that the file encoding is compatible.
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    protected function validateFileEncoding(UploadedFile $file, \Illuminate\Validation\Validator $validator): void
    {
        try {
            $handle = fopen($file->getPathname(), 'r');
            
            if ($handle === false) {
                return;
            }

            // Read a sample of the file to check encoding
            $sample = fread($handle, 1024);
            fclose($handle);

            // Check if the file contains valid UTF-8 or ASCII characters
            if (!mb_check_encoding($sample, 'UTF-8') && !mb_check_encoding($sample, 'ASCII')) {
                // Try to detect encoding
                $encoding = mb_detect_encoding($sample, ['UTF-8', 'ASCII', 'ISO-8859-1', 'Windows-1252'], true);
                
                if ($encoding === false) {
                    $validator->errors()->add('csv_file', 'The CSV file contains invalid characters. Please save the file in UTF-8 format.');
                }
            }

        } catch (\Exception $e) {
            // Don't fail validation for encoding issues, just log
            \Log::warning('Unable to validate CSV file encoding', [
                'file_name' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the validated input data after successful validation.
     *
     * @return array<string, mixed>
     */
    public function getValidatedData(): array
    {
        return [
            'csv_file' => $this->file('csv_file'),
            'target_user_id' => (int) $this->input('target_user_id'),
            'authenticated_user_id' => Auth::id(),
            'client_id' => Auth::user()->client_id ?? null,
        ];
    }

    /**
     * Get file information for logging and processing.
     *
     * @return array<string, mixed>
     */
    public function getFileInfo(): array
    {
        $csvFile = $this->file('csv_file');
        
        if (!$csvFile) {
            return [];
        }

        return [
            'original_name' => $csvFile->getClientOriginalName(),
            'size' => $csvFile->getSize(),
            'mime_type' => $csvFile->getMimeType(),
            'extension' => $csvFile->getClientOriginalExtension(),
            'temp_path' => $csvFile->getPathname(),
        ];
    }

    /**
     * Get processing configuration based on validated input.
     *
     * @return array<string, mixed>
     */
    public function getProcessingConfig(): array
    {
        return [
            'max_rows' => self::MAX_ROWS,
            'max_file_size' => self::MAX_FILE_SIZE,
            'allowed_mime_types' => $this->allowedMimeTypes,
            'allowed_extensions' => $this->allowedExtensions,
            'validation_strategy' => 'all_or_nothing', // From system constraints
            'batch_size' => 100, // For queue processing
        ];
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @return void
     */
    protected function failedAuthorization(): void
    {
        \Log::warning('CSV upload authorization failed', [
            'user_id' => Auth::id(),
            'target_user_id' => $this->input('target_user_id'),
            'client_id' => Auth::user()->client_id ?? null,
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
        ]);

        parent::failedAuthorization();
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param \Illuminate\Contracts\Validation\Validator $validator
     * @return void
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        \Log::info('CSV upload validation failed', [
            'user_id' => Auth::id(),
            'target_user_id' => $this->input('target_user_id'),
            'client_id' => Auth::user()->client_id ?? null,
            'file_name' => $this->file('csv_file')?->getClientOriginalName(),
            'errors' => $validator->errors()->toArray(),
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Sanitize target_user_id input
        if ($this->has('target_user_id')) {
            $this->merge([
                'target_user_id' => filter_var($this->input('target_user_id'), FILTER_SANITIZE_NUMBER_INT)
            ]);
        }

        // If target_user_id is not provided, default to authenticated user
        if (!$this->has('target_user_id') || empty($this->input('target_user_id'))) {
            $this->merge([
                'target_user_id' => Auth::id()
            ]);
        }
    }
}