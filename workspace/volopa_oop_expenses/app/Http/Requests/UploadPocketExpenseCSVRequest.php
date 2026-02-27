## Code: app/Http/Requests/UploadPocketExpenseCSVRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Models\PocketExpenseFileUpload;
use App\Policies\PocketExpenseUploadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

class UploadPocketExpenseCSVRequest extends FormRequest
{
    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * Maximum file size in KB (10MB).
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum CSV rows allowed per file.
     *
     * @var int
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Allowed file extensions.
     *
     * @var array<string>
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Allowed MIME types for CSV files.
     *
     * @var array<string>
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
     * Required CSV headers (must match exactly).
     *
     * @var array<string>
     */
    private const REQUIRED_CSV_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Address',
        'VAT %',
        'Source',
        'Source Note',
        'Notes'
    ];

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

        // Use policy to check if user can create uploads
        $policy = new PocketExpenseUploadPolicy();
        
        if (!$policy->create($user)) {
            return false;
        }

        // Additional authorization checks for uploading to specific target user
        if ($this->has('expense_user_id') && $this->has('client_id')) {
            if (!$policy->canUploadForUser($user, $this->input('expense_user_id'), $this->input('client_id'))) {
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
        $maxFileSizeBytes = self::MAX_FILE_SIZE_KB * 1024;

        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'mimetypes:' . implode(',', self::ALLOWED_MIME_TYPES),
                "max:{$maxFileSizeBytes}",
                function ($attribute, $value, $fail) {
                    if (!$this->validateFileExtension($value)) {
                        $fail('The file must have a .csv or .txt extension.');
                    }
                },
                function ($attribute, $value, $fail) {
                    if (!$this->validateFileContent($value)) {
                        $fail('The file content is not valid CSV format or exceeds row limit.');
                    }
                },
                function ($attribute, $value, $fail) {
                    if (!$this->validateCSVHeaders($value)) {
                        $fail('The CSV file must have the correct headers in the first row.');
                    }
                },
            ],
            'client_id' => [
                'required',
                'integer',
                'min:1',
                'exists:clients,id',
                function ($attribute, $value, $fail) {
                    if (!$this->validateUserClientAccess($value)) {
                        $fail('You do not have access to this client.');
                    }
                },
            ],
            'expense_user_id' => [
                'required',
                'integer',
                'min:1',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if (!$this->validateTargetUserClientRelationship($value, $this->input('client_id'))) {
                        $fail('The target user does not belong to the specified client.');
                    }
                },
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
            'file.required' => 'A CSV file is required.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The file must be a CSV or TXT file.',
            'file.mimetypes' => 'The file must have a valid CSV MIME type.',
            'file.max' => 'The file size cannot exceed 10MB.',
            
            'client_id.required' => 'The client ID is required.',
            'client_id.integer' => 'The client ID must be an integer.',
            'client_id.min' => 'The client ID must be at least 1.',
            'client_id.exists' => 'The selected client does not exist.',
            
            'expense_user_id.required' => 'The expense user ID is required.',
            'expense_user_id.integer' => 'The expense user ID must be an integer.',
            'expense_user_id.min' => 'The expense user ID must be at least 1.',
            'expense_user_id.exists' => 'The selected expense user does not exist.',
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
            'client_id' => 'client',
            'expense_user_id' => 'expense user',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Ensure integer values are properly cast
        $integerFields = ['client_id', 'expense_user_id'];
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
            // Additional business logic validation
            if (!$this->validateUploadBusinessRules()) {
                $validator->errors()->add(
                    'general',
                    'The upload request does not meet business requirements.'
                );
            }

            // Validate file uniqueness (prevent duplicate uploads)
            if ($this->hasFile('file') && !$this->validateFileUniqueness()) {
                $validator->errors()->add(
                    'file',
                    'A file with this name has already been uploaded recently.'
                );
            }
        });
    