## Code: app/Http/Requests/UploadPocketExpenseCSVRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseSourceClientConfig;
use App\Policies\PocketExpensePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;

/**
 * UploadPocketExpenseCSVRequest
 * 
 * Form request validation for CSV file upload for batch expense processing.
 * Handles validation rules, authorization checks, and business logic validation
 * for CSV uploads with multi-tenant security and file format restrictions.
 * 
 * Validation Rules:
 * - file: Required, CSV/text file, max 10MB size, max 200 rows
 * - expense_user_id: Required, must exist in users table and belong to same client
 * - Header row mandatory and must exactly match required column names
 * - All data must be scoped to authenticated user's client_id
 * - User must have permission to upload expenses for target user
 */
class UploadPocketExpenseCSVRequest extends FormRequest
{
    /**
     * Maximum file size in KB for CSV uploads.
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240; // 10MB

    /**
     * Maximum number of rows allowed per CSV file.
     *
     * @var int
     */
    private const MAX_ROWS_PER_FILE = 200;

    /**
     * Minimum file size in bytes (must contain at least headers).
     *
     * @var int
     */
    private const MIN_FILE_SIZE_BYTES = 50;

    /**
     * Accepted file MIME types.
     *
     * @var array<int, string>
     */
    private const ACCEPTED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/excel',
        'application/vnd.ms-excel',
        'application/vnd.msexcel',
        'text/comma-separated-values',
    ];

    /**
     * Accepted file extensions.
     *
     * @var array<int, string>
     */
    private const ACCEPTED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Required CSV header columns in exact order.
     *
     * @var array<int, string>
     */
    private const REQUIRED_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'VAT Amount',
        'Merchant Address',
        'Notes',
        'Source',
        'Source Note',
        'Category',
        'Tracking Code',
        'Project',
    ];

    /**
     * Date format expected in CSV.
     *
     * @var string
     */
    private const CSV_DATE_FORMAT = 'DD/MM/YYYY';

    /**
     * The uploaded file being validated.
     *
     * @var UploadedFile|null
     */
    protected ?UploadedFile $uploadedFile = null;

    /**
     * The target user for expense creation.
     *
     * @var int|null
     */
    protected ?int $expenseUserId = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        
        if (!$user || !$user->client_id) {
            return false;
        }

        // Get the target user for expense creation
        $this->expenseUserId = $this->input('expense_user_id');
        
        if (!$this->expenseUserId) {
            return false;
        }

        // Use policy to check if user can create expenses for the target user
        $policy = new PocketExpensePolicy();
        return $policy->createFor($user, $this->expenseUserId, $user->client_id);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = Auth::user();
        $clientId = $user ? $user->client_id : null;

        return [
            'file' => [
                'required',
                'file',
                'max:' . self::MAX_FILE_SIZE_KB, // Size in KB
                'min:' . (self::MIN_FILE_SIZE_BYTES / 1024), // Convert bytes to KB
                'mimes:csv,txt',
                'mimetypes:' . implode(',', self::ACCEPTED_MIME_TYPES),
                function ($attribute, $value, $fail) {
                    if (!($value instanceof UploadedFile)) {
                        $fail('Invalid file upload.');
                        return;
                    }

                    $this->uploadedFile = $value;

                    // Validate file extension
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, self::ACCEPTED_EXTENSIONS)) {
                        $fail('File must be a CSV or TXT file.');
                        return;
                    }

                    // Validate file is readable
                    if (!is_readable($value->getRealPath())) {
                        $fail('File is not readable.');
                        return;
                    }

                    // Validate CSV structure
                    $this->validateCSVStructure($value, $fail);
                },
            ],
            'expense_user_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('users', 'id')->where(function ($query) use ($clientId) {
                    if ($clientId) {
                        return $query->where('client_id', $clientId);
                    }
                    return $query;
                }),
                function ($attribute, $value, $fail) use ($user) {
                    // Target user must belong to same client
                    $targetUser = DB::table('users')
                        ->where('id', $value)
                        ->where('client_id', $user->client_id)
                        ->first();

                    if (!$targetUser) {
                        $fail('Target user does not exist or does not belong to your organization.');
                    }
                },
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
            $user = Auth::user();
            
            if (!$user || !$user->client_id) {
                $validator->errors()->add('authorization', 'Invalid user context.');
                return;
            }

            // Additional authorization checks
            if (!$this->canUploadForUser($user, $this->expenseUserId, $user->client_id)) {
                $validator->errors()->add('expense_user_id', 'You are not authorized to upload expenses for this user.');
            }

            // Check for concurrent uploads
            if ($this->hasActiveUpload($user, $user->client_id)) {
                $validator->errors()->add('file', 'You have an active upload in progress. Please wait for it to complete.');
            }

            // Validate file content if file passed basic validation
            if ($this->uploadedFile && !$validator->errors()->has('file')) {
                $this->validateFileContent($validator);
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'CSV file is required.',
            'file.file' => 'Uploaded file is not valid.',
            'file.max' => 'File size cannot exceed ' . (self::MAX_