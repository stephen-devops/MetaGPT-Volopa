<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\PocketExpenseFileUpload;
use App\Policies\PocketExpenseUploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * PocketExpenseUploadRequest
 * 
 * Form request for validating CSV file uploads in the batch expense upload system.
 * This request handles validation and authorization for uploading CSV files containing
 * expense data within the multi-tenant system. Includes policy enforcement,
 * comprehensive validation rules for file uploads, and CSV format validation.
 * Follows the mental model: Client -> route -> controller -> Form Request -> domain logic.
 */
class PocketExpenseUploadRequest extends FormRequest
{
    /**
     * Maximum file size in KB (10MB)
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum file size in bytes (10MB)
     *
     * @var int
     */
    private const MAX_FILE_SIZE_BYTES = 10485760;

    /**
     * Maximum number of records per CSV file
     *
     * @var int
     */
    private const MAX_RECORDS_PER_FILE = 200;

    /**
     * Maximum length for file name
     *
     * @var int
     */
    private const MAX_FILE_NAME_LENGTH = 255;

    /**
     * Allowed file extensions
     *
     * @var array<int, string>
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Allowed MIME types
     *
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/excel',
        'application/vnd.ms-excel',
        'application/vnd.msexcel'
    ];

    /**
     * Expected CSV column headers (exact match required)
     *
     * @var array<int, string>
     */
    private const EXPECTED_CSV_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'Merchant Address',
        'VAT Amount',
        'VAT %',
        'Notes',
        'Source',
        'Source Note'
    ];

    /**
     * Required CSV columns that must have values
     *
     * @var array<int, string>
     */
    private const REQUIRED_CSV_COLUMNS = [
        'Date',
        'Merchant Name',
        'Expense Type',
        'Currency',
        'Amount'
    ];

    /**
     * Minimum number of data rows (excluding header)
     *
     * @var int
     */
    private const MIN_DATA_ROWS = 1;

    /**
     * Maximum number of header validation errors to report
     *
     * @var int
     */
    private const MAX_HEADER_ERRORS = 10;

    /**
     * Maximum number of row validation errors to report per file
     *
     * @var int
     */
    private const MAX_ROW_ERRORS = 50;

    /**
     * Determine if the user is authorized to make this request.
     * Uses PocketExpenseUploadPolicy to check authorization.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $policy = new PocketExpenseUploadPolicy();
        $user = $this->user();
        $targetClientId = $user->client_id;
        
        // Check basic upload permission
        if (!$policy->upload($user, $targetClientId)) {
            return false;
        }

        // For additional validation, check if this is uploading for another user
        $uploadUserId = $this->input('user_id');
        if ($uploadUserId && $uploadUserId != $user->id) {
            return $policy->viewUserUploads($user, $uploadUserId);
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
        $user = $this->user();
        $clientId = $user->client_id;

        return [
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB,
                'mimes:csv,txt',
                'mimetypes:' . implode(',', self::ALLOWED_MIME_TYPES),
                // Custom validation for file content
                function ($attribute, $value, $fail) {
                    if ($value instanceof UploadedFile) {
                        $this->validateUploadedFile($value, $fail);
                    }
                }
            ],
            'user_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    // Ensure target user is in the same client
                    return $query->where('client_id', $clientId);
                }),
                // Validate user has permission to upload for target user
                function ($attribute, $value, $fail) use ($user) {
                    if ($value && $value != $user->id) {
                        $policy = new PocketExpenseUploadPolicy();
                        if (!$policy->viewUserUploads($user, $value)) {
                            $fail('You do not have permission to upload files for this user.');
                        }
                    }
                }
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500'
            ],
            'auto_process' => [
                'sometimes',
                'boolean'
            ],
            'notification_email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255'
            ],
            'source_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('pocket_expense_source_client_config', 'id')->where(function ($query) use ($clientId) {
                    // Ensure source is available to the client (client-specific or global)
                    return $query->where(function ($q) use ($clientId) {
                        $q->where('client_id', $clientId)
                          ->orWhereNull('client_id');
                    })->where('deleted', false);
                })
            ],
            'default_category_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1'
                // Note: Assuming transaction_category table exists but not implementing validation
                // as the table structure is not provided in the context
            ],
            'default_project_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1'
                // Note: Assuming project table exists but not implementing validation
                // as the table structure is not provided in the context
            ]
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
            'file.required' => 'A CSV file is required for upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size cannot exceed ' . self::MAX_FILE_SIZE_KB . 'KB (' . (self::MAX_FILE_SIZE_KB / 1024) . 'MB).',
            'file.mimes' => 'The file must be a CSV or TXT file.',
            'file.mimetypes' => 'The file must have a valid CSV or text MIME type.',
            
            'user_id.integer' => 'The user ID must be a valid integer.',
            'user_id.exists' => 'The specified user does not exist or is not in your client.',
            
            'description.string' => 'The description must be a valid string.',
            'description.max' => 'The description cannot exceed 500 characters.',
            
            'auto_process.boolean' => 'The auto process flag must be true or false.',
            
            'notification_email.email' => 'The notification email must be a valid email address.',
            'notification_email.max' => 'The notification email cannot exceed 255 characters.',
            
            'source_id.integer' => 'The source ID must be a valid integer.',
            'source_id.exists' => 'The selected expense source is not available.',
            
            'default_category_id.integer' => 'The default category ID must be a valid integer.',
            'default_project_id.integer' => 'The default project ID must be a valid integer.'
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
            'user_id' => 'target user',
            'description' => 'upload description',
            'auto_process' => 'auto process setting',
            'notification_email' => 'notification email',
            'source_id' => 'default expense source',
            'default_category_id' => 'default category',
            'default_project_id' => 'default project'
        ];
    }

    /**
     * Prepare the data for validation.
     * This method is called before validation rules are applied.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        // Set default values
        $defaults = [
            'user_id' => $this->input('user_id', $user->id),
            'client_id' => $user->client_id,
            'auto_process' => $this->boolean('auto_process', true),
            'created_by_user_id' => $user->id
        ];

        $this->merge($defaults);

        // Clean and normalize input data
        $this->normalizeStringFields();
    }

    /**
     * Configure the validator instance.
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Additional cross-field validation
            $this->validateClientPermissions($validator);
            $this->validateUploadLimits($validator);
        });
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param Validator $validator
     * @return void
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY)
        );
    }

    /**
     * Get the validated data with additional computed fields.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($key === null) {
            // Add computed fields for model creation
            $validated['created_by_user_id'] = $this->user()->id;
            $validated['client_id'] = $this->user()->client_id;
            
            // Set user_id if not provided
            if (empty($validated['user_id'])) {
                $validated['user_id'] = $this->user()->id;
            }

            // Generate UUID
            $validated['uuid'] = (string) \Illuminate\Support\Str::uuid();

            // Set initial status
            $validated['status'] = PocketExpenseFileUpload::STATUS_UPLOADED;

            // Set upload timestamp
            $validated['uploaded_at'] = now();

            // Process file information
            $file = $this->file('file');
            if ($file instanceof UploadedFile) {
                $validated['file_name'] = $file->getClientOriginalName();
                $validated['file_path'] = null; // Will be set after file storage
                $validated['total_records'] = 0; // Will be set during processing
                $validated['valid_records'] = 0; // Will be set during processing
            }

            // Set defaults for optional fields
            $validated['auto_process'] = $validated['auto_process'] ?? true;
            $validated['description'] = $validated['description'] ?? null;
            $validated['notification_email'] = $validated['notification_email'] ?? null;
            $validated['validation_errors'] = null;
        }

        return $validated;
    }

    /**
     * Validate the uploaded file content and structure.
     *
     * @param UploadedFile $file
     * @param callable $fail
     * @return void
     */
    private function validateUploadedFile(UploadedFile $file, callable $fail): void
    {
        // Validate file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $fail('The file must have a .csv or .txt extension.');
            return;
        }

        // Validate file name length
        $fileName = $file->getClientOriginalName();
        if (strlen($fileName) > self::MAX_FILE_NAME_LENGTH) {
            $fail('The file name cannot exceed ' . self::MAX_FILE_NAME_LENGTH . ' characters.');
            return;
        }

        // Validate file size
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            $fail('The file size cannot exceed ' . (self::MAX_FILE_SIZE_KB / 1024) . 'MB.');
            return;
        }

        // Validate file is readable
        if (!$file->isValid()) {
            $fail('The uploaded file is corrupted or invalid.');
            return;
        }

        // Validate CSV content structure
        try {
            $this->validateCsvContent($file, $fail);
        } catch (\Exception $e) {
            $fail('Error reading CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Validate CSV file content structure and format.
     *
     * @param UploadedFile $file
     * @param callable $fail
     * @return void
     */
    private function validateCsvContent(UploadedFile $file, callable $fail): void
    {
        $filePath = $file->getRealPath();
        if (!$filePath || !file_exists($filePath)) {
            $fail('Unable to read the uploaded file.');
            return;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $fail('Unable to open the CSV file for reading.');
            return;
        }

        try {
            // Read and validate header row
            $headerRow = fgetcsv($handle);
            if ($headerRow === false || empty($headerRow)) {
                $fail('The CSV file is empty or has no header row.');
                return;
            }

            // Validate header columns
            $headerErrors = $this->validateCsvHeaders($headerRow);
            if (!empty($headerErrors)) {
                $errorMessage = 'CSV header validation failed: ' . implode(', ', array_slice($headerErrors, 0, self::MAX_HEADER_ERRORS));
                if (count($headerErrors) > self::MAX_HEADER_ERRORS) {
                    $errorMessage .= ' and ' . (count($headerErrors) - self::MAX_HEADER_ERRORS) . ' more errors';
                }
                $fail($errorMessage);
                return;
            }

            // Count and validate data rows
            $rowCount = 0;
            $rowErrors = [];
            $lineNumber = 2; // Starting from line 2 (after header)

            while (($row = fgetcsv($handle)) !== false && $rowCount < self::MAX_RECORDS_PER_FILE + 1) {
                $rowCount++;
                
                // Quick validation of row structure
                if (count($row) !== count(self::EXPECTED_CSV_HEADERS)) {
                    $rowErrors[] = "Line {$lineNumber}: Expected " . count(self::EXPECTED_CSV_HEADERS) . " columns, found " . count($row);
                }

                // Validate required fields are not empty
                $this->validateRequiredFields($row, $lineNumber, $rowErrors);

                $lineNumber++;

                // Stop if we have too many errors
                if (count($rowErrors) >= self::MAX_ROW_ERRORS) {
                    break;
                }
            }

            // Validate minimum number of data rows
            if ($rowCount < self::MIN_DATA_ROWS) {
                $fail('The CSV file must contain at least ' . self::MIN_DATA_ROWS . ' data row(s) in addition to the header.');
                return;
            }

            // Validate maximum number of records
            if ($rowCount > self::MAX_RECORDS_PER_FILE) {
                $fail('The CSV file cannot contain more than ' . self::MAX_RECORDS_PER_FILE . ' records.');
                return;
            }

            // Report row validation errors if any
            if (!empty($rowErrors)) {
                $errorMessage = 'CSV data validation failed: ' . implode('; ', array_slice($rowErrors, 0, self::MAX_ROW_ERRORS));
                if (count($rowErrors) > self::MAX_ROW_ERRORS) {
                    $errorMessage .= ' and ' . (count($rowErrors) - self::MAX_ROW_ERRORS) . ' more errors';
                }
                $fail($errorMessage);
                return;
            }

        } finally {
            fclose($handle);
        }
    }

    /**
     * Validate CSV header columns.
     *
     * @param array<int, string> $headerRow
     * @return array<int, string>
     */
    private function validateCsvHeaders(array $headerRow): array
    {
        $errors = [];
        $headerRow = array_map('trim', $headerRow);

        // Check if all expected headers are present
        $missingHeaders = array_diff(self::EXPECTED_CSV_HEADERS, $headerRow);
        foreach ($missingHeaders as $missingHeader) {
            $errors[] = "Missing required column: '{$missingHeader}'";
        }

        // Check for unexpected headers
        $extraHeaders = array_diff($headerRow, self::EXPECTED_CSV_HEADERS);
        foreach ($extraHeaders as $extraHeader) {
            if (!empty($extraHeader)) {
                $errors[] = "Unexpected column: '{$extraHeader}'";
            }
        }

        // Check for duplicate headers
        $duplicateHeaders = array_diff_assoc($headerRow, array_unique($headerRow));
        foreach (array_unique($duplicateHeaders) as $duplicateHeader) {
            if (!empty($duplicateHeader)) {
                $errors[] = "Duplicate column: '{$duplicateHeader}'";
            }
        }

        // Check exact order (optional strict validation)
        if (empty($errors)) {
            for ($i = 0; $i < count(self::EXPECTED_CSV_HEADERS); $i++) {
                if (!isset($headerRow[$i]) || $headerRow[$i] !== self::EXPECTED_CSV_HEADERS[$i]) {
                    $expected = self::EXPECTED_CSV_HEADERS[$i] ?? 'N/A';
                    $actual = $headerRow[$i] ?? 'Missing';
                    $errors[] = "Column " . ($i + 1) . ": expected '{$expected}', found '{$actual}'";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate required fields in a data row.
     *
     * @param array<int, string> $row
     * @param int $lineNumber
     * @param array<int, string> &$errors
     * @return void
     */
    private function validateRequiredFields(array $row, int $lineNumber, array &$errors): void
    {
        $headerMap = array_flip(self::EXPECTED_CSV_HEADERS);

        foreach (self::REQUIRED_CSV_COLUMNS as $requiredColumn) {
            if (isset($headerMap[$requiredColumn])) {
                $columnIndex = $headerMap[$requiredColumn];
                $value = isset($row[$columnIndex]) ? trim($row[$columnIndex]) : '';
                
                if (empty($value)) {
                    $errors[] = "Line {$lineNumber}: Required field '{$requiredColumn}' is empty";
                }
            }
        }
    }

    /**
     * Normalize string fields by trimming and sanitizing.
     *
     * @return void
     */
    private function normalizeStringFields(): void
    {
        $stringFields = ['description', 'notification_email'];
        
        foreach ($stringFields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                if (is_string($value)) {
                    $cleaned = trim(strip_tags($value));
                    $this->merge([$field => !empty($cleaned) ? $cleaned : null]);
                }
            }
        }
    }

    /**
     * Validate client-specific permissions and constraints.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateClientPermissions(Validator $validator): void
    {
        $user = $this->user();
        $targetUserId = $this->input('user_id');
        
        // Validate that target user belongs to the same client
        if ($targetUserId && $targetUserId != $user->id) {
            $targetUser = User::find($targetUserId);
            if (!$targetUser || $targetUser->client_id !== $user->client_id) {
                $validator->errors()->add('user_id', 'You can only upload files for users in your own client.');
            }
        }
        
        // Validate notification email belongs to the client domain (if required)
        $notificationEmail = $this->input('notification_email');
        if ($notificationEmail) {
            // This would typically validate against client's allowed email domains
            // For now, we'll just ensure it's not a potentially malicious address
            $domain = substr(strrchr($notificationEmail, "@"), 1);
            if (in_array($domain, ['example.com', 'test.com', 'localhost'])) {
                $validator->errors()->add('notification_email', 'Invalid notification email domain.');
            }
        }
    }

    /**
     * Validate upload limits and constraints.
     *
     * @param Validator $validator
     * @return void
     */
    private function validateUploadLimits(Validator $validator): void
    {
        $user = $this->user();
        
        // Check daily upload limit per user (example business rule)
        $todayUploads = PocketExpenseFileUpload::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->whereDate('uploaded_at', today())
            ->count();
            
        $dailyLimit = match($user->role) {
            'Primary Administrator', 'Admin' => 50,
            'Business User' => 10,
            'Card User' => 3,
            default => 1
        };
        
        if ($todayUploads >= $dailyLimit) {
            $validator->errors()->add('file', "Daily upload limit of {$dailyLimit} files exceeded.");
        }
        
        // Check pending uploads limit
        $pendingUploads = PocketExpenseFileUpload::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->whereIn('status', [
                PocketExpenseFileUpload::STATUS_UPLOADED,
                PocketExpenseFileUpload::STATUS_VALIDATING,
                PocketExpenseFileUpload::STATUS_PROCESSING
            ])
            ->count();
            
        $pendingLimit = 5; // Maximum 5 pending uploads per user
        
        if ($pendingUploads >= $pendingLimit) {
            $validator->errors()->add('file', "You have {$pendingUploads} uploads currently processing. Please wait for them to complete before uploading more files.");
        }
    }

    /**
     * Get sanitized input data for processing.
     *
     * @return array<string, mixed>
     */
    public function getSanitizedData(): array
    {
        $data = $this->validated();
        
        // Ensure all required fields have values
        $data['user_id'] = $data['user_id'] ?? $this->user()->id;
        $data['client_id'] = $data['client_id'] ?? $this->user()->client_id;
        $data['created_by_user_id'] = $this->user()->id;
        $data['status'] = PocketExpenseFileUpload::STATUS_UPLOADED;
        $data['auto_process'] = $data['auto_process'] ?? true;
        
        // Clean up null/empty values
        foreach (['description', 'notification_email', 'source_id', 'default_category_id', 'default_project_id'] as $field) {
            if (isset($data[$field]) && ($data[$field] === '' || $data[$field] === 0)) {
                $data[$field] = null;
            }
        }
        
        return $data;
    }

    /**
     * Get file information for processing.
     *
     * @return array<string, mixed>|null
     */
    public function getFileInfo(): ?array
    {
        $file = $this->file('file');
        
        if (!($file instanceof UploadedFile)) {
            return null;
        }

        return [
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
            'path' => $file->getRealPath(),
            'is_valid' => $file->isValid(),
            'error' => $file->getError()
        ];
    }

    /**
     * Get CSV parsing configuration.
     *
     * @return array<string, mixed>
     */
    public function getCsvConfig(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
            'expected_headers' => self::EXPECTED_CSV_HEADERS,
            'required_columns' => self::REQUIRED_CSV_COLUMNS,
            'max_records' => self::MAX_RECORDS_PER_FILE,
            'header_row' => true
        ];
    }

    /**
     * Get validation context information for logging and debugging.
     *
     * @return array<string, mixed>
     */
    public function getValidationContext(): array
    {
        $user = $this->user();
        $fileInfo = $this->getFileInfo();
        
        return [
            'uploader_id' => $user->id,
            'uploader_role' => $user->role,
            'client_id' => $user->client_id,
            'target_user_id' => $this->input('user_id'),
            'file_name' => $fileInfo['original_name'] ?? null,
            'file_size' => $fileInfo['size'] ?? null,
            'file_mime_type' => $fileInfo['mime_type'] ?? null,
            'auto_process' => $this->input('auto_process', true),
            'has_description' => !empty($this->input('description')),
            'has_notification_email' => !empty($this->input('notification_email')),
            'has_default_source' => !empty($this->input('source_id')),
            'request_ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Check if the upload should be automatically processed.
     *
     * @return bool
     */
    public function shouldAutoProcess(): bool
    {
        return $this->boolean('auto_process', true);
    }

    /**
     * Check if notification email should be sent.
     *
     * @return bool
     */
    public function shouldSendNotification(): bool
    {
        return !empty($this->input('notification_email'));
    }

    /**
     * Get the uploaded file instance.
     *
     * @return UploadedFile|null
     */
    public function getUploadedFile(): ?UploadedFile
    {
        return $this->file('file');
    }

    /**
     * Get a human-readable description of the upload.
     *
     * @return string
     */
    public function getUploadDescription(): string
    {
        $user = $this->user();
        $fileInfo = $this->getFileInfo();
        $fileName = $fileInfo['original_name'] ?? 'Unknown File';
        $targetUserId = $this->input('user_id');
        
        if ($targetUserId && $targetUserId != $user->id) {
            $targetUser = User::find($targetUserId);
            $targetUserName = $targetUser ? $targetUser->name : 'Unknown User';
            return "Uploading {$fileName} for {$targetUserName}";
        }
        
        return "Uploading {$fileName}";
    }

    /**
     * Get upload processing options.
     *
     * @return array<string, mixed>
     */
    public function getProcessingOptions(): array
    {
        return [
            'auto_process' => $this->shouldAutoProcess(),
            'send_notification' => $this->shouldSendNotification(),
            'notification_email' => $this->input('notification_email'),
            'default_source_id' => $this->input('source_id'),
            'default_category_id' => $this->input('default_category_id'),
            'default_project_id' => $this->input('default_project_id'),
            'description' => $this->input('description')
        ];
    }

    /**
     * Get expected CSV headers.
     *
     * @return array<int, string>
     */
    public static function getExpectedHeaders(): array
    {
        return self::EXPECTED_CSV_HEADERS;
    }

    /**
     * Get required CSV columns.
     *
     * @return array<int, string>
     */
    public static function getRequiredColumns(): array
    {
        return self::REQUIRED_CSV_COLUMNS;
    }

    /**
     * Get file upload constraints.
     *
     * @return array<string, mixed>
     */
    public static function getUploadConstraints(): array
    {
        return [
            'max_file_size_kb' => self::MAX_FILE_SIZE_KB,
            'max_file_size_mb' => self::MAX_FILE_SIZE_KB / 1024,
            'max_records' => self::MAX_RECORDS_PER_FILE,
            'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            'allowed_mime_types' => self::ALLOWED_MIME_TYPES,
            'max_file_name_length' => self::MAX_FILE_NAME_LENGTH,
            'min_data_rows' => self::MIN_DATA_ROWS
        ];
    }

    /**
     * Generate CSV template content.
     *
     * @return string
     */
    public static function generateCsvTemplate(): string
    {
        $headers = self::EXPECTED_CSV_HEADERS;
        $sampleData = [
            '01/01/2024',
            'Sample Merchant',
            'Sample transaction description',
            'Travel',
            'USD',
            '100.00',
            '123 Main St, City, State',
            '10.00',
            '10',
            'Sample notes',
            'Corporate Card',
            ''
        ];
        
        $template = implode(',', array_map(function($header) {
            return '"' . str_replace('"', '""', $header) . '"';
        }, $headers)) . "\n";
        
        $template .= implode(',', array_map(function($value) {
            return '"' . str_replace('"', '""', $value) . '"';
        }, $sampleData)) . "\n";
        
        return $template;
    }

    /**
     * Get validation rules for API documentation.
     *
     * @return array<string, mixed>
     */
    public static function getValidationRulesForDocs(): array
    {
        return [
            'file' => 'required|file|max:' . self::MAX_FILE_SIZE_KB . 'KB|mimes:csv,txt',
            'user_id' => 'integer|exists:users,id (optional, defaults to authenticated user)',
            'description' => 'string|max:500 (optional upload description)',
            'auto_process' => 'boolean (default: true)',
            'notification_email' => 'email|max:255 (optional notification email)',
            'source_id' => 'integer|exists:pocket_expense_source_client_config,id (optional default source)',
            'default_category_id' => 'integer (optional default category)',
            'default_project_id' => 'integer (optional default project)',
            'csv_format' => [
                'headers' => self::EXPECTED_CSV_HEADERS,
                'required_columns' => self::REQUIRED_CSV_COLUMNS,
                'max_records' => self::MAX_RECORDS_PER_FILE,
                'date_format' => 'DD/MM/YYYY',
                'amount_format' => 'Numeric with up to 2 decimal places',
                'currency_format' => '3-letter ISO code (e.g., USD, EUR, GBP)'
            ]
        ];
    }

    /**
     * Validate CSV file format without full processing.
     * Used for quick validation checks.
     *
     * @param UploadedFile $file
     * @return array<string, mixed>
     */
    public static function quickValidateCsv(UploadedFile $file): array
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'info' => []
        ];

        try {
            // Basic file validation
            if (!$file->isValid()) {
                $result['valid'] = false;
                $result['errors'][] = 'File upload failed or file is corrupted';
                return $result;
            }

            // Size validation
            if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
                $result['valid'] = false;
                $result['errors'][] = 'File size exceeds maximum allowed (' . (self::MAX_FILE_SIZE_KB / 1024) . 'MB)';
                return $result;
            }

            // Extension validation
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
                $result['valid'] = false;
                $result['errors'][] = 'File must have .csv or .txt extension';
                return $result;
            }

            // Try to read first few lines
            $filePath = $file->getRealPath();
            if ($filePath && file_exists($filePath)) {
                $handle = fopen($filePath, 'r');
                if ($handle) {
                    $headerRow = fgetcsv($handle);
                    if ($headerRow === false) {
                        $result['valid'] = false;
                        $result['errors'][] = 'Cannot read CSV headers';
                    } else {
                        // Quick header validation
                        $missingHeaders = array_diff(self::EXPECTED_CSV_HEADERS, array_map('trim', $headerRow));
                        if (!empty($missingHeaders)) {
                            $result['warnings'][] = 'Missing headers: ' . implode(', ', $missingHeaders);
                        }
                        
                        // Count rows (estimate)
                        $rowCount = 0;
                        while (fgetcsv($handle) !== false && $rowCount < self::MAX_RECORDS_PER_FILE + 10) {
                            $rowCount++;
                        }
                        
                        $result['info']['estimated_rows'] = $rowCount;
                        $result['info']['headers_found'] = count($headerRow);
                        $result['info']['expected_headers'] = count(self::EXPECTED_CSV_HEADERS);
                    }
                    fclose($handle);
                }
            }

        } catch (\Exception $e) {
            $result['valid'] = false;
            $result['errors'][] = 'Error reading CSV file: ' . $e->getMessage();
        }

        return $result;
    }
}