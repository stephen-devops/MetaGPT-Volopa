<?php

namespace App\Http\Requests;

use App\Models\MassPaymentFile;
use App\Models\TccAccount;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UploadMassPaymentFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Use the MassPaymentFilePolicy to check if user can create mass payment files
        return Gate::allows('create', MassPaymentFile::class);
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
                'max:51200', // 50MB max file size
                function ($attribute, $value, $fail) {
                    if ($value instanceof UploadedFile) {
                        $this->validateCsvStructure($value, $fail);
                    }
                }
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(['USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'CAD', 'JPY', 'CNY', 'THB', 'MYR', 'IDR', 'PHP', 'VND'])
            ],
            'tcc_account_id' => [
                'required',
                'uuid',
                'exists:tcc_accounts,id',
                function ($attribute, $value, $fail) {
                    $this->validateTccAccountAccess($value, $fail);
                }
            ],
            'description' => [
                'nullable',
                'string',
                'max:500'
            ],
            'payment_date' => [
                'nullable',
                'date',
                'after_or_equal:today',
                'before_or_equal:' . now()->addDays(30)->format('Y-m-d')
            ]
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to upload.',
            'file.file' => 'The uploaded file is not valid.',
            'file.mimes' => 'The file must be in CSV format (.csv or .txt).',
            'file.max' => 'The file size cannot exceed 50MB.',
            'currency.required' => 'Currency is required.',
            'currency.size' => 'Currency code must be exactly 3 characters.',
            'currency.in' => 'The selected currency is not supported. Supported currencies: USD, EUR, GBP, SGD, HKD, AUD, CAD, JPY, CNY, THB, MYR, IDR, PHP, VND.',
            'tcc_account_id.required' => 'TCC Account is required.',
            'tcc_account_id.uuid' => 'Invalid TCC Account ID format.',
            'tcc_account_id.exists' => 'The selected TCC Account does not exist.',
            'description.max' => 'Description cannot exceed 500 characters.',
            'payment_date.date' => 'Payment date must be a valid date.',
            'payment_date.after_or_equal' => 'Payment date cannot be in the past.',
            'payment_date.before_or_equal' => 'Payment date cannot be more than 30 days in the future.'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'file' => 'CSV file',
            'currency' => 'currency',
            'tcc_account_id' => 'TCC Account',
            'description' => 'description',
            'payment_date' => 'payment date'
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional cross-field validation
            $this->validateFileCurrencyConsistency($validator);
            $this->validateTccAccountCurrency($validator);
            $this->validateFileNameUniqueness($validator);
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency'))
            ]);
        }

        // Set default payment date if not provided
        if (!$this->has('payment_date') || empty($this->input('payment_date'))) {
            $this->merge([
                'payment_date' => now()->addDays(1)->format('Y-m-d')
            ]);
        }

        // Trim description if provided
        if ($this->has('description')) {
            $this->merge([
                'description' => trim($this->input('description'))
            ]);
        }
    }

    /**
     * Validate CSV file structure and basic content.
     */
    private function validateCsvStructure(UploadedFile $file, callable $fail): void
    {
        try {
            $handle = fopen($file->getRealPath(), 'r');
            
            if (!$handle) {
                $fail('Unable to read the CSV file. Please check file permissions.');
                return;
            }

            // Read and validate header row
            $header = fgetcsv($handle, 0, ',');
            
            if (!$header || empty($header)) {
                $fail('The CSV file appears to be empty or corrupted.');
                fclose($handle);
                return;
            }

            // Required CSV columns
            $requiredColumns = [
                'beneficiary_name',
                'account_number',
                'bank_code',
                'amount',
                'currency',
                'purpose_code'
            ];

            // Normalize header columns (trim and lowercase)
            $normalizedHeader = array_map(function ($column) {
                return strtolower(trim($column));
            }, $header);

            // Check for required columns
            $missingColumns = [];
            foreach ($requiredColumns as $requiredColumn) {
                if (!in_array($requiredColumn, $normalizedHeader)) {
                    $missingColumns[] = $requiredColumn;
                }
            }

            if (!empty($missingColumns)) {
                $fail('Missing required columns: ' . implode(', ', $missingColumns));
                fclose($handle);
                return;
            }

            // Validate file has at least one data row
            $rowCount = 0;
            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                if (!empty(array_filter($row))) { // Count non-empty rows
                    $rowCount++;
                }
                
                // Limit check to prevent memory issues during validation
                if ($rowCount > 10000) {
                    $fail('CSV file cannot contain more than 10,000 payment rows.');
                    break;
                }
            }

            fclose($handle);

            if ($rowCount === 0) {
                $fail('The CSV file must contain at least one payment row.');
                return;
            }

            if ($rowCount > 10000) {
                $fail('CSV file cannot contain more than 10,000 payment rows.');
                return;
            }

        } catch (\Exception $e) {
            $fail('Error reading CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Validate that the user has access to the specified TCC Account.
     */
    private function validateTccAccountAccess(string $tccAccountId, callable $fail): void
    {
        $user = $this->user();
        
        if (!$user) {
            $fail('User authentication required.');
            return;
        }

        $tccAccount = TccAccount::find($tccAccountId);
        
        if (!$tccAccount) {
            $fail('The selected TCC Account does not exist.');
            return;
        }

        // Check if user's client has access to this TCC Account
        if ($tccAccount->client_id !== $user->client_id) {
            $fail('You do not have access to the selected TCC Account.');
            return;
        }

        // Check if TCC Account is active
        if (!$tccAccount->is_active) {
            $fail('The selected TCC Account is not active.');
            return;
        }
    }

    /**
     * Validate file and currency consistency.
     */
    private function validateFileCurrencyConsistency($validator): void
    {
        $file = $this->file('file');
        $currency = $this->input('currency');

        if (!$file || !$currency) {
            return;
        }

        try {
            $handle = fopen($file->getRealPath(), 'r');
            
            if (!$handle) {
                return;
            }

            // Skip header row
            fgetcsv($handle, 0, ',');
            
            // Get currency column index from header
            fseek($handle, 0);
            $header = fgetcsv($handle, 0, ',');
            $currencyColumnIndex = array_search('currency', array_map('strtolower', array_map('trim', $header)));
            
            if ($currencyColumnIndex === false) {
                fclose($handle);
                return;
            }

            // Check first few rows for currency consistency
            $inconsistentRows = [];
            $rowNumber = 1; // Start from data rows (after header)
            
            while (($row = fgetcsv($handle, 0, ',')) !== false && $rowNumber <= 10) {
                if (isset($row[$currencyColumnIndex])) {
                    $rowCurrency = strtoupper(trim($row[$currencyColumnIndex]));
                    if ($rowCurrency !== $currency && !empty($rowCurrency)) {
                        $inconsistentRows[] = $rowNumber + 1; // +1 to account for header row
                    }
                }
                $rowNumber++;
            }

            fclose($handle);

            if (!empty($inconsistentRows)) {
                $validator->errors()->add(
                    'currency',
                    'Currency mismatch detected in rows: ' . implode(', ', $inconsistentRows) . 
                    '. All payments must be in ' . $currency . ' currency.'
                );
            }

        } catch (\Exception $e) {
            // Log error but don't fail validation - detailed validation will happen in job
            logger()->warning('CSV currency validation error: ' . $e->getMessage());
        }
    }

    /**
     * Validate TCC Account supports the selected currency.
     */
    private function validateTccAccountCurrency($validator): void
    {
        $tccAccountId = $this->input('tcc_account_id');
        $currency = $this->input('currency');

        if (!$tccAccountId || !$currency) {
            return;
        }

        $tccAccount = TccAccount::find($tccAccountId);
        
        if (!$tccAccount) {
            return;
        }

        // Check if TCC Account supports the selected currency
        $supportedCurrencies = $tccAccount->supported_currencies ?? [];
        
        if (!empty($supportedCurrencies) && !in_array($currency, $supportedCurrencies)) {
            $validator->errors()->add(
                'currency',
                'The selected TCC Account does not support ' . $currency . ' currency. ' .
                'Supported currencies: ' . implode(', ', $supportedCurrencies) . '.'
            );
        }

        // Check account balance if available
        $accountBalance = $tccAccount->getBalanceForCurrency($currency);
        
        if ($accountBalance !== null && $accountBalance <= 0) {
            $validator->errors()->add(
                'tcc_account_id',
                'Insufficient balance in the selected TCC Account for ' . $currency . ' currency.'
            );
        }
    }

    /**
     * Validate filename uniqueness for the client within the last 24 hours.
     */
    private function validateFileNameUniqueness($validator): void
    {
        $file = $this->file('file');
        $user = $this->user();

        if (!$file || !$user) {
            return;
        }

        $originalFilename = $file->getClientOriginalName();
        
        // Check for duplicate filenames in the last 24 hours
        $existingFile = MassPaymentFile::where('client_id', $user->client_id)
            ->where('original_filename', $originalFilename)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotIn('status', [
                MassPaymentFile::STATUS_CANCELLED,
                MassPaymentFile::STATUS_FAILED
            ])
            ->first();

        if ($existingFile) {
            $validator->errors()->add(
                'file',
                'A file with the same name "' . $originalFilename . '" was uploaded in the last 24 hours. ' .
                'Please rename your file or wait before uploading again.'
            );
        }
    }

    /**
     * Get the validated data with additional computed fields.
     */
    public function validatedWithComputed(): array
    {
        $validated = $this->validated();
        $user = $this->user();
        
        return array_merge($validated, [
            'client_id' => $user->client_id,
            'created_by' => $user->id,
            'original_filename' => $this->file('file')->getClientOriginalName(),
            'file_size' => $this->file('file')->getSize(),
            'mime_type' => $this->file('file')->getMimeType()
        ]);
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log validation failures for monitoring
        logger()->info('Mass payment file upload validation failed', [
            'user_id' => $this->user()?->id,
            'client_id' => $this->user()?->client_id,
            'errors' => $validator->errors()->toArray(),
            'input' => $this->except(['file'])
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
        // Log successful validation for monitoring
        logger()->info('Mass payment file upload validation passed', [
            'user_id' => $this->user()?->id,
            'client_id' => $this->user()?->client_id,
            'filename' => $this->file('file')?->getClientOriginalName(),
            'currency' => $this->input('currency'),
            'tcc_account_id' => $this->input('tcc_account_id')
        ]);
    }
}