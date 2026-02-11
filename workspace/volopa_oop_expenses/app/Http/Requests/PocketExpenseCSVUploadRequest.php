## Code: app/Http/Requests/PocketExpenseCSVUploadRequest.php

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpense;
use App\Models\PocketExpenseFileUpload;
use Illuminate\Support\Facades\Auth;

class PocketExpenseCSVUploadRequest extends FormRequest
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

        // Check if user can upload CSV files for pocket expenses
        $clientId = $this->input('client_id');
        if (!$user->can('uploadCsv', [PocketExpense::class, $clientId])) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB max
                'min:1',
            ],
            'expense_user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->whereNull('deleted_at');
                }),
            ],
            'client_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('clients', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'has_headers' => [
                'sometimes',
                'boolean',
            ],
            'date_format' => [
                'sometimes',
                'string',
                'max:50',
                Rule::in([
                    'Y-m-d',
                    'd/m/Y',
                    'm/d/Y',
                    'd-m-Y',
                    'm-d-Y',
                    'Y/m/d',
                    'd.m.Y',
                    'm.d.Y',
                    'Y.m.d'
                ]),
            ],
            'delimiter' => [
                'sometimes',
                'string',
                'size:1',
                Rule::in([',', ';', '\t', '|']),
            ],
            'enclosure' => [
                'sometimes',
                'string',
                'size:1',
                Rule::in(['"', "'"]),
            ],
            'skip_rows' => [
                'sometimes',
                'integer',
                'min:0',
                'max:10',
            ],
            'column_mapping' => [
                'sometimes',
                'array',
            ],
            'column_mapping.date' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.merchant_name' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.amount' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.currency' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.description' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.expense_type' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.category' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.source' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.project_code' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.cost_center' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.location' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.tax_amount' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.tax_rate' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'column_mapping.receipt_reference' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],
            'default_values' => [
                'sometimes',
                'array',
            ],
            'default_values.currency' => [
                'nullable',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::exists('currency', 'code')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'default_values.expense_type' => [
                'nullable',
                'string',
                'max:200',
            ],
            'default_values.category' => [
                'nullable',
                'string',
                'max:200',
            ],
            'default_values.source' => [
                'nullable',
                'string',
                'max:200',
            ],
            'default_values.project_code' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[A-Z0-9_-]+$/i',
            ],
            'default_values.cost_center' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[A-Z0-9_-]+$/i',
            ],
            'validation_options' => [
                'sometimes',
                'array',
            ],
            'validation_options.ignore_duplicates' => [
                'sometimes',
                'boolean',
            ],
            'validation_options.strict_mode' => [
                'sometimes',
                'boolean',
            ],
            'validation_options.auto_fix_dates' => [
                'sometimes',
                'boolean',
            ],
            'validation_options.auto_fix_amounts' => [
                'sometimes',
                'boolean',
            ],
            'processing_options' => [
                'sometimes',
                'array',
            ],
            'processing_options.batch_size' => [
                'sometimes',
                'integer',
                'min:10',
                'max:1000',
            ],
            'processing_options.auto_approve' => [
                'sometimes',
                'boolean',
            ],
            'processing_options.notification_email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'csv_file.required' => 'A CSV file is required for upload.',
            'csv_file.file' => 'The uploaded file must be a valid file.',
            'csv_file.mimes' => 'The file must be a CSV or TXT file.',
            'csv_file.max' => 'The file size must not exceed 10MB.',
            'csv_file.min' => 'The file must not be empty.',
            
            'expense_user_id.required' => 'The target user ID is required.',
            'expense_user_id.integer' => 'The target user ID must be an integer.',
            'expense_user_id.min' => 'The target user ID must be at least 1.',
            'expense_user_id.exists' => 'The selected target user does not exist or is inactive.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The selected client does not exist or is inactive.',
            
            'has_headers.boolean' => 'The has headers flag must be true or false.',
            
            'date_format.string' => 'The date format must be a string.',
            'date_format.max' => 'The date format may not be greater than 50 characters.',
            'date_format.in' => 'The selected date format is invalid.',
            
            'delimiter.string' => 'The delimiter must be a string.',
            'delimiter.size' => 'The delimiter must be exactly 1 character.',
            'delimiter.in' => 'The delimiter must be one of: comma, semicolon, tab, or pipe.',
            
            'enclosure.string' => 'The enclosure must be a string.',
            'enclosure.size' => 'The enclosure must be exactly 1 character.',
            'enclosure.in' => 'The enclosure must be either double quote or single quote.',
            
            'skip_rows.integer' => 'The skip rows must be an integer.',
            'skip_rows.min' => 'The skip rows must be at least 0.',
            'skip_rows.max' => 'The skip rows may not be greater than 10.',
            
            'column_mapping.array' => 'The column mapping must be an array.',
            'column_mapping.*.integer' => 'Column mapping values must be integers.',
            'column_mapping.*.min' => 'Column mapping values must be at least 0.',
            'column_mapping.*.max' => 'Column mapping values may not be greater than 50.',
            
            'default_values.array' => 'The default values must be an array.',
            'default_values.currency.string' => 'The default currency must be a string.',
            'default_values.currency.size' => 'The default currency must be exactly 3 characters.',
            'default_values.currency.regex' => 'The default currency must be in uppercase 3-letter format (e.g., USD).',
            'default_values.currency.exists' => 'The selected default currency is not valid or inactive.',
            'default_values.expense_type.string' => 'The default expense type must be a string.',
            'default_values.expense_type.max' => 'The default expense type may not be greater than 200 characters.',
            'default_values.category.string' => 'The default category must be a string.',
            'default_values.category.max' => 'The default category may not be greater than 200 characters.',
            'default_values.source.string' => 'The default source must be a string.',
            'default_values.source.max' => 'The default source may not be greater than 200 characters.',
            'default_values.project_code.string' => 'The default project code must be a string.',
            'default_values.project_code.max' => 'The default project code may not be greater than 100 characters.',
            'default_values.project_code.regex' => 'The default project code may only contain letters, numbers, underscores, and hyphens.',
            'default_values.cost_center.string' => 'The default cost center must be a string.',
            'default_values.cost_center.max' => 'The default cost center may not be greater than 100 characters.',
            'default_values.cost_center.regex' => 'The default cost center may only contain letters, numbers, underscores, and hyphens.',
            
            'validation_options.array' => 'The validation options must be an array.',
            'validation_options.ignore_duplicates.boolean' => 'The ignore duplicates option must be true or false.',
            'validation_options.strict_mode.boolean' => 'The strict mode option must be true or false.',
            'validation_options.auto_fix_dates.boolean' => 'The auto fix dates option must be true or false.',
            'validation_options.auto_fix_amounts.boolean' => 'The auto fix amounts option must be true or false.',
            
            'processing_options.array' => 'The processing options must be an array.',
            'processing_options.batch_size.integer' => 'The batch size must be an integer.',
            'processing_options.batch_size.min' => 'The batch size must be at least 10.',
            'processing_options.batch_size.max' => 'The batch size may not be greater than 1000.',
            'processing_options.auto_approve.boolean' => 'The auto approve option must be true or false.',
            'processing_options.notification_email.email' => 'The notification email must be a valid email address.',
            'processing_options.notification_email.max' => 'The notification email may not be greater than 255 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'csv_file' => 'CSV file',
            'expense_user_id' => 'target user',
            'client_id' => 'client',
            'has_headers' => 'has headers',
            'date_format' => 'date format',
            'delimiter' => 'delimiter',
            'enclosure' => 'enclosure',
            'skip_rows' => 'skip rows',
            'column_mapping' => 'column mapping',
            'default_values' => 'default values',
            'validation_options' => 'validation options',
            'processing_options' => 'processing options',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateUserClientRelationship($validator);
            $this->validateFileContent($validator);
            $this->validateColumnMapping($validator);
            $this->validateUploadLimits($validator);
            $this->validateBusinessRules($validator);
        });
    }

    /**
     * Validate that the target user belongs to the specified client.
     */
    protected function validateUserClientRelationship($validator): void
    {
        $expenseUserId = $this->input('expense_user_id');
        $clientId = $this->input('client_id');

        if ($expenseUserId && $clientId) {
            $user = User::find($expenseUserId);
            $client = Client::find($clientId);

            if ($user && $client) {
                $belongsToClient = false;

                // Direct client_id relationship
                if (isset($user->client_id) && $user->client_id === $clientId) {
                    $belongsToClient = true;
                }

                // Many-to-many relationship through pivot table
                if (!$belongsToClient && method_exists($user, 'clients')) {
                    $belongsToClient = $user->clients()->where('client_id', $clientId)->exists();
                }

                if (!$belongsToClient) {
                    $validator->errors()->add('expense_user_id', 'The selected target user does not belong to the specified client.');
                }
            }
        }

        // Validate that the authenticated user can upload for the target user
        $authUser = Auth::user();
        if ($authUser && $expenseUserId && $clientId) {
            if ($expenseUserId !== $authUser->id && !$authUser->can('createForUser', [PocketExpense::class, $expenseUserId, $clientId])) {
                $validator->errors()->add('expense_user_id', 'You do not have permission to upload expenses for this user.');
            }
        }
    }

    /**
     * Validate file content and structure.
     */
    protected function validateFileContent($validator): void
    {
        $file = $this->file('csv_file');

        if ($file && $file->isValid()) {
            // Check if file is readable
            if (!$file->isReadable()) {
                $validator->errors()->add('csv_file', 'The uploaded file is not readable.');
                return;
            }

            // Basic file content validation
            $handle = fopen($file->getRealPath(), 'r');
            if (!$handle) {
                $validator->errors()->add('csv_file', 'Unable to read the uploaded file.');
                return;
            }

            $delimiter = $this->input('delimiter', ',');
            $enclosure = $this->input('enclosure', '"');
            $skipRows = $this->input('skip_rows', 0);
            
            // Convert delimiter shorthand
            if ($delimiter === '\t') {
                $delimiter = "\t";
            }

            $rowCount = 0;
            $emptyRowCount = 0;
            $validRowsFound = false;

            // Skip specified rows
            for ($i = 0; $i < $skipRows; $i++) {
                if (fgetcsv($handle, 0, $delimiter, $enclosure) === false) {
                    break;
                }
            }

            // Check first few rows for content
            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false && $rowCount < 20) {
                $rowCount++;
                
                if (empty(array_filter($row, function($cell) { return trim($cell) !== ''; }))) {
                    $emptyRowCount++;
                } else {
                    $validRowsFound = true;
                    
                    // Validate row has minimum expected columns
                    if (count($row) < 3) {
                        $validator->errors()->add('csv_file', "Row {$rowCount} has too few columns. Expected at least 3 columns (date, merchant, amount).");
                        break;
                    }
                }
            }

            fclose($handle);

            if (!$validRowsFound) {
                $validator->errors()->add('csv_file', 'The uploaded file contains no valid data rows.');
            }

            if ($emptyRowCount > 0 && $emptyRowCount === $rowCount) {
                $validator->errors()->add('csv_file', 'The uploaded file appears to contain only empty rows.');
            }

            // Check for reasonable file size vs content ratio
            $fileSize = $file->getSize();
            if ($fileSize > 1000 && $rowCount < 2) {
                $validator->errors()->add('csv_file', 'The file size seems too large for the amount of data detected. Please check the file format.');
            }
        }
    }

    /**
     * Validate column mapping configuration.
     */
    protected function validateColumnMapping($validator): void
    {
        $columnMapping = $this->input('column_mapping', []);
        
        if (!empty($columnMapping)) {
            // Check for required column mappings
            $requiredColumns = ['date', 'merchant_name', 'amount'];
            $missingRequired = [];

            foreach ($requiredColumns as $column) {
                if (!isset($columnMapping[$column]) || $columnMapping[$column] === null) {
                    $missingRequired[] = $column;
                }
            }

            if (!empty($missingRequired)) {
                $validator->errors()->add('column_mapping', 'The following required columns must be mapped: ' . implode(', ', $missingRequired));
            }

            // Check for duplicate column mappings
            $mappedColumns = array_filter($columnMapping, function($value) { return $value !== null; });
            $duplicates = array_diff_assoc($mappedColumns, array_unique($mappedColumns));

            if (!empty($duplicates)) {
                $validator->errors()->add('column_mapping', 'Column mappings cannot use the same column index multiple times.');
            }

            // Validate column indices are reasonable
            foreach ($columnMapping as $field => $columnIndex) {
                if ($columnIndex !== null && ($columnIndex < 0 || $columnIndex > 50)) {
                    $validator->errors()->add("column_mapping.{$field}", "Column index for {$field} must be between 0 and 50.");
                }
            }
        }
    }

    /**
     * Validate upload limits and restrictions.
     */
    protected function validateUploadLimits($validator): void
    {
        $authUser = Auth::user();
        $clientId = $this->input('client_id');
        $expenseUserId = $this->input('expense_user_id');

        if ($authUser && $clientId && $expenseUserId) {
            // Check active upload limits per user
            $maxActiveUploads = 5;
            if (!PocketExpenseFileUpload::canUserCreateUpload($expenseUserId, $clientId, $maxActiveUploads)) {
                $validator->errors()->add('expense_user_id', "The target user has reached the maximum number of active uploads ({$maxActiveUploads}).");
            }

            // Check daily upload limits
            $dailyUploads = PocketExpenseFileUpload::forUser($expenseUserId)
                ->forClient($clientId)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();

            $dailyLimit = 10; // Max 10 uploads per user per day
            if ($dailyUploads >= $dailyLimit) {
                $validator->errors()->add('expense_user_id', "The daily upload limit of {$dailyLimit} has been reached for this user.");
            }

            // Check file size limits based on user role
            $file = $this->file('csv_file');
            if ($file) {
                $fileSizeKB = $file->getSize() / 1024;
                $userRole = $this->getUserHighestRole($authUser);
                
                $sizeLimits = [
                    'user' => 5120,      // 5MB
                    'manager' => 8192,   // 8MB
                    'admin' => 10240,    // 10MB
                    'super_admin' => 10240 // 10MB
                ];

                $limit = $sizeLimits[$userRole] ?? $sizeLimits['user'];
                if ($fileSizeKB > $limit) {
                    $validator->errors()->add('csv_file', "File size exceeds the limit of " . ($limit / 1024) . "MB for your role.");
                }
            }
        }
    }

    /**
     * Validate business rules and configurations.
     */
    protected function validateBusinessRules($validator): void
    {
        $clientId = $this->input('client_id');
        $validationOptions = $this->input('validation_options', []);
        $processingOptions = $this->input('processing_options', []);

        // Validate batch size limits based on file size
        $file = $this->file('csv_file');
        if ($file && isset($processingOptions['batch_size'])) {
            $fileSizeKB = $file->getSize() / 1024;
            $batchSize = $processingOptions['batch_size'];

            // Larger files should use smaller batch sizes to avoid memory issues
            if ($fileSizeKB > 5000 && $batchSize > 100) {
                $validator->errors()->add('processing_options.batch_size', 'For large files (>5MB), batch size should not exceed 100 rows.');
            }
        }

        // Validate auto-approve permissions
        if (isset($processingOptions['auto_approve']) && $processingOptions['auto_approve']) {
            $authUser = Auth::user();
            if ($authUser && !$authUser->can('approve', [PocketExpense::class])) {
                $validator->errors()->add('processing_options.auto_approve', 'You do not have permission to auto-approve expenses.');
            }
        }

        // Validate notification email belongs to authenticated user or is authorized
        if (isset($processingOptions['notification_email']) && !empty($processingOptions['notification_email'])) {
            $authUser = Auth::user();
            $notificationEmail = $processingOptions['notification_email'];
            
            if ($authUser && $authUser->email !== $notificationEmail) {
                // Check if user can send notifications to other emails
                if (!$authUser->hasRole('admin') && !$authUser->hasRole('super_admin')) {
                    $validator->errors()->add('processing_options.notification_email', 'You can only use your own email address for notifications.');
                }
            }
        }

        // Validate default values are reasonable
        $defaultValues = $this->input('default_values', []);
        if (isset($defaultValues['currency'])) {
            // Ensure default currency is commonly used
            $restrictedCurrencies = ['XTS', 'XXX']; // Test and unknown currencies
            if (in_array(strtoupper($defaultValues['currency']), $restrictedCurrencies)) {
                $validator->errors()->add('default_values.currency', 'The selected default currency is not allowed for expenses.');
            }
        }
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set default values
        $defaults = [
            'has_headers' => true,
            'date_format' => 'Y-m-d',
            'delimiter' => ',',
            'enclosure' => '"',
            'skip_rows' => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (!$this->has($key)) {
                $this->merge([$key => $value]);
            }
        }

        // Ensure boolean conversion
        $booleanFields = ['has_headers'];
        foreach ($booleanFields as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        // Convert validation options booleans
        $validationOptions = $this->input('validation_options', []);
        if (is_array($validationOptions)) {
            $validationBooleans = ['ignore_duplicates', 'strict_mode', 'auto_fix_dates', 'auto_fix_amounts'];
            foreach ($validationBooleans as $option) {
                if (isset($validationOptions[$option])) {
                    $validationOptions[$option] = filter_var($validationOptions[$option], FILTER_VALIDATE_BOOLEAN);
                }
            }
            
            // Set defaults for validation options
            $validationDefaults = [
                'ignore_duplicates' => false,
                'strict_mode' => false,
                'auto_fix_dates' => true,
                'auto_fix_amounts' => true,
            ];
            
            foreach ($validationDefaults as $key => $value) {
                if (!isset($validationOptions[$key])) {
                    $validationOptions[$key] = $value;
                }
            }
            
            $this->merge(['validation_options' => $validationOptions]);
        }

        // Convert processing options booleans and set defaults
        $processingOptions = $this->input('processing_options', []);
        if (is_array($processingOptions)) {
            $processingBooleans = ['auto_approve'];
            foreach ($processingBooleans as $option) {
                if (isset($processingOptions[$option])) {
                    $processingOptions[$option] = filter_var($processingOptions[$option], FILTER_VALIDATE_BOOLEAN);
                }
            }
            
            // Set defaults for processing options
            $processingDefaults = [
                'batch_size' => 100,
                'auto_approve' => false,
            ];
            
            foreach ($processingDefaults as $key => $value) {
                if (!isset($processingOptions[$key])) {
                    $processingOptions[$key] = $value;
                }
            }
            
            $this->merge(['processing_options' => $processingOptions]);
        }

        // Normalize default values
        $defaultValues = $this->input('default_values', []);
        if (is_array($defaultValues) && isset($defaultValues['currency'])) {
            $defaultValues['currency'] = strtoupper($defaultValues['currency']);
            $this->merge(['default_values' => $defaultValues]);
        }
    }

    /**
     * Get the validated data with defaults applied.
     */
    public function validatedWithDefaults(): array
    {
        $validated = $this->validated();
        
        // Ensure defaults are applied
        $validated['has_headers'] = $validated['has_headers'] ?? true;
        $validated['date_format'] = $validated['date_format'] ?? 'Y-m-d';
        $validated['delimiter'] = $validated['delimiter'] ?? ',';
        $validated['enclosure'] = $validated['enclosure'] ?? '"';
        $validated['skip_rows'] = $validated['skip_rows'] ?? 0;
        
        // Add authenticated user as the creator
        $validated['user_id'] = Auth::id();
        
        return $validated;
    }

    /**
     * Get user's highest role for limit checking.
     */
    private function getUserHighestRole(User $user): string
    {
        if ($user->hasRole('super_admin')) {
            return 'super_admin';
        }
        if ($user->hasRole('admin')) {
            return 'admin';
        }
        if ($user->hasRole('manager')) {
            return 'manager';
        }
        return 'user';
    }

    /**
     * Get the uploaded file instance.
     */
    public function getUploadedFile(): ?\Illuminate\Http\UploadedFile
    {
        return $this->file('csv_file');
    }

    /**
     * Get column mapping configuration.
     */
    public function getColumnMapping(): array
    {
        return $this->input('column_mapping', []);
    }

    /**
     * Get default values configuration.
     */
    public function getDefaultValues(): array
    {
        return $this->input('default_values', []);
    }

    /**
     * Get validation options configuration.
     */
    public function getValidationOptions(): array
    {
        return $this->input('validation_options', [
            'ignore_duplicates' => false,
            'strict_mode' => false,
            'auto_fix_dates' => true,
            'auto_fix_amounts' => true,
        ]);
    }

    /**
     * Get processing options configuration.
     */
    public function getProcessingOptions(): array
    {
        return $this->input('processing_options', [
            'batch_size' => 100,
            'auto_approve' => false,
        ]);
    }

    /**
     * Get CSV parsing configuration.
     */
    public function getCsvConfiguration(): array
    {
        return [
            'delimiter' => $this->input('delimiter', ',') === '\t' ? "\t" : $this->input('delimiter', ','),
            'enclosure' => $this->input('enclosure', '"'),
            'has_headers' => $this->input('has_headers', true),
            'skip_rows' => $this->input('skip_rows', 0),
            'date_format' => $this->input('date_format', 'Y-m-d'),
        ];
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
            'You do not have permission to upload pocket expense CSV files.'
        );
    }

    /**
     * Get the validator instance for the request.
     */
    public function getValidatorInstance(): \Illuminate\Validation\Validator
    {
        $factory = $this->container->make(\Illuminate\Validation\Factory::class);
        
        if (method_exists($this, '