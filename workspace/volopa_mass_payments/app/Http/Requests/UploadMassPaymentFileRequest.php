<?php

namespace App\Http\Requests;

use App\Models\MassPaymentFile;
use App\Models\TccAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UploadMassPaymentFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Use MassPaymentFilePolicy to check create permission
        return $this->user() && $this->user()->can('create', MassPaymentFile::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // File validation rules
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB max file size
                function ($attribute, $value, $fail) {
                    // Additional file validation
                    if (!$value || !$value->isValid()) {
                        $fail('The uploaded file is invalid.');
                        return;
                    }

                    // Check file extension more strictly
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (!in_array($extension, ['csv', 'txt'])) {
                        $fail('The file must be a CSV file with .csv or .txt extension.');
                        return;
                    }

                    // Check MIME type more strictly
                    $mimeType = $value->getMimeType();
                    $allowedMimeTypes = [
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'text/comma-separated-values',
                        'application/octet-stream'
                    ];
                    
                    if (!in_array($mimeType, $allowedMimeTypes)) {
                        $fail('The file must be a valid CSV file.');
                        return;
                    }

                    // Check file is not empty
                    if ($value->getSize() === 0) {
                        $fail('The uploaded file is empty.');
                        return;
                    }

                    // Validate CSV structure - basic check
                    $handle = fopen($value->getPathname(), 'r');
                    if ($handle === false) {
                        $fail('Unable to read the uploaded file.');
                        return;
                    }

                    // Check for BOM and skip if present
                    $firstLine = fgets($handle);
                    if ($firstLine !== false) {
                        // Remove BOM if present
                        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
                        
                        // Basic CSV structure validation
                        $headers = str_getcsv($firstLine);
                        if (empty($headers) || count($headers) < 3) {
                            $fail('The CSV file must contain at least 3 columns.');
                            fclose($handle);
                            return;
                        }

                        // Check minimum required headers (case-insensitive)
                        $requiredHeaders = ['amount', 'currency', 'beneficiary'];
                        $headerNames = array_map('strtolower', array_map('trim', $headers));
                        
                        foreach ($requiredHeaders as $required) {
                            if (!in_array(strtolower($required), $headerNames)) {
                                $fail("The CSV file is missing required column: {$required}.");
                                fclose($handle);
                                return;
                            }
                        }

                        // Count rows to validate file size limits
                        $rowCount = 0;
                        while (($line = fgets($handle)) !== false) {
                            $rowCount++;
                            if ($rowCount > 10000) {
                                $fail('The CSV file cannot contain more than 10,000 rows.');
                                fclose($handle);
                                return;
                            }
                        }

                        if ($rowCount === 0) {
                            $fail('The CSV file must contain at least one data row.');
                        }
                    } else {
                        $fail('Unable to read the CSV file headers.');
                    }

                    fclose($handle);
                },
            ],

            // TCC Account validation
            'tcc_account_id' => [
                'required',
                'integer',
                'exists:tcc_accounts,id',
                function ($attribute, $value, $fail) {
                    // Validate TCC account belongs to authenticated user's client
                    $user = Auth::user();
                    if (!$user || !$user->client_id) {
                        $fail('User must be associated with a client.');
                        return;
                    }

                    $tccAccount = TccAccount::find($value);
                    if (!$tccAccount) {
                        $fail('The selected TCC account does not exist.');
                        return;
                    }

                    if ($tccAccount->client_id !== $user->client_id) {
                        $fail('The selected TCC account does not belong to your organization.');
                        return;
                    }

                    if (!$tccAccount->is_active) {
                        $fail('The selected TCC account is not active.');
                        return;
                    }

                    if (!$tccAccount->canBeUsedForPayments()) {
                        $fail('The selected TCC account cannot be used for payments.');
                        return;
                    }
                },
            ],

            // Optional currency filter - if provided, file must match
            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                Rule::in(config('mass-payments.supported_currencies', ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY'])),
            ],

            // Optional description
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            // Optional notification preferences
            'notify_on_completion' => [
                'sometimes',
                'boolean',
            ],

            'notify_on_failure' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Get the validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // File validation messages
            'file.required' => 'A CSV file is required for upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The file must be a CSV file (.csv or .txt extension).',
            'file.max' => 'The file size cannot exceed 10MB.',

            // TCC Account validation messages
            'tcc_account_id.required' => 'A TCC account must be selected.',
            'tcc_account_id.integer' => 'The TCC account ID must be a valid integer.',
            'tcc_account_id.exists' => 'The selected TCC account does not exist.',

            // Currency validation messages
            'currency.required' => 'Currency code is required when specified.',
            'currency.string' => 'Currency must be a text value.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.regex' => 'Currency code must contain only uppercase letters.',
            'currency.in' => 'The specified currency is not supported.',

            // Description validation messages
            'description.string' => 'Description must be text.',
            'description.max' => 'Description cannot exceed 500 characters.',

            // Notification validation messages
            'notify_on_completion.boolean' => 'Completion notification preference must be true or false.',
            'notify_on_failure.boolean' => 'Failure notification preference must be true or false.',
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
            'tcc_account_id' => 'TCC account',
            'currency' => 'currency code',
            'description' => 'file description',
            'notify_on_completion' => 'completion notification',
            'notify_on_failure' => 'failure notification',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency to uppercase if provided
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper(trim($this->get('currency', ''))),
            ]);
        }

        // Set default notification preferences
        $this->merge([
            'notify_on_completion' => $this->boolean('notify_on_completion', true),
            'notify_on_failure' => $this->boolean('notify_on_failure', true),
        ]);

        // Trim description if provided
        if ($this->has('description')) {
            $this->merge([
                'description' => trim($this->get('description', '')),
            ]);
        }
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log validation failures for debugging
        \Illuminate\Support\Facades\Log::warning('Mass payment file upload validation failed', [
            'user_id' => $this->user()?->id,
            'client_id' => $this->user()?->client_id,
            'errors' => $validator->errors()->toArray(),
            'input' => $this->except(['file']), // Exclude file from logs
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Additional cross-field validation
            $this->validateFileAndTccAccountCurrency($validator);
            $this->validateFileSizeConstraints($validator);
        });
    }

    /**
     * Validate that file currency matches TCC account currency if specified.
     */
    private function validateFileAndTccAccountCurrency(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $tccAccountId = $this->get('tcc_account_id');
        $currency = $this->get('currency');

        if (!$tccAccountId || !$currency) {
            return;
        }

        $tccAccount = TccAccount::find($tccAccountId);
        if (!$tccAccount) {
            return;
        }

        if ($tccAccount->currency !== $currency) {
            $validator->errors()->add(
                'currency',
                "The currency must match the TCC account currency ({$tccAccount->currency})."
            );
        }
    }

    /**
     * Validate file size constraints based on configuration.
     */
    private function validateFileSizeConstraints(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $file = $this->file('file');
        if (!$file || !$file->isValid()) {
            return;
        }

        // Get configuration limits
        $maxFileSize = config('mass-payments.max_file_size_mb', 10) * 1024 * 1024; // Convert to bytes
        $maxRows = config('mass-payments.max_rows_per_file', 10000);

        // Check file size
        if ($file->getSize() > $maxFileSize) {
            $maxSizeMB = $maxFileSize / 1024 / 1024;
            $validator->errors()->add(
                'file',
                "The file size cannot exceed {$maxSizeMB}MB."
            );
            return;
        }

        // Re-validate row count with configuration
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return;
        }

        // Skip header row
        fgets($handle);
        
        $rowCount = 0;
        while (($line = fgets($handle)) !== false) {
            $rowCount++;
            if ($rowCount > $maxRows) {
                $validator->errors()->add(
                    'file',
                    "The CSV file cannot contain more than {$maxRows} rows."
                );
                break;
            }
        }

        fclose($handle);
    }

    /**
     * Get validated data with additional processing.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        // Add computed fields to validated data
        $validated['client_id'] = $this->user()->client_id;
        $validated['uploaded_by'] = $this->user()->id;
        $validated['original_filename'] = $this->file('file')->getClientOriginalName();
        $validated['file_size'] = $this->file('file')->getSize();

        // Detect currency from file if not provided
        if (!isset($validated['currency'])) {
            $validated['currency'] = $this->detectCurrencyFromFile();
        }

        return $validated;
    }

    /**
     * Detect currency from the uploaded file.
     */
    private function detectCurrencyFromFile(): ?string
    {
        $file = $this->file('file');
        if (!$file || !$file->isValid()) {
            return null;
        }

        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return null;
        }

        // Read and parse header
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            return null;
        }

        // Remove BOM if present
        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
        $headers = str_getcsv($headerLine);
        
        // Find currency column index
        $currencyIndex = null;
        foreach ($headers as $index => $header) {
            if (strtolower(trim($header)) === 'currency') {
                $currencyIndex = $index;
                break;
            }
        }

        if ($currencyIndex === null) {
            fclose($handle);
            return null;
        }

        // Read first data row to get currency
        $dataLine = fgets($handle);
        fclose($handle);
        
        if ($dataLine === false) {
            return null;
        }

        $data = str_getcsv($dataLine);
        if (!isset($data[$currencyIndex])) {
            return null;
        }

        $currency = strtoupper(trim($data[$currencyIndex]));
        
        // Validate detected currency
        $supportedCurrencies = config('mass-payments.supported_currencies', ['USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'HKD', 'JPY']);
        
        return in_array($currency, $supportedCurrencies) ? $currency : null;
    }
}