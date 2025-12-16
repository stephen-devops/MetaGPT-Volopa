<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PaymentFile;
use App\Policies\PaymentFilePolicy;

class FileUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user can upload payment files using policy
        return $this->user() && $this->user()->can('create', PaymentFile::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240', // 10MB max file size
                'min:1', // At least 1KB
            ],
            'currency' => [
                'sometimes',
                'string',
                'size:3',
                Rule::in(PaymentFile::getValidCurrencies()),
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
        return [
            'file.required' => 'A CSV file is required for upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The file must be a CSV file with .csv or .txt extension.',
            'file.max' => 'The file size cannot exceed 10MB.',
            'file.min' => 'The file must be at least 1KB in size.',
            'currency.size' => 'The currency code must be exactly 3 characters long.',
            'currency.in' => 'The currency code must be one of: ' . implode(', ', PaymentFile::getValidCurrencies()),
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
            'currency' => 'currency code',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert currency to uppercase if provided
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->currency),
            ]);
        }
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional custom validation for file content structure
            if ($this->hasFile('file') && $this->file('file')->isValid()) {
                $this->validateCsvStructure($validator);
            }
        });
    }

    /**
     * Validate the CSV file structure and headers.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    protected function validateCsvStructure($validator): void
    {
        try {
            $file = $this->file('file');
            $path = $file->getRealPath();
            
            // Open and read the first few lines to validate structure
            if (($handle = fopen($path, 'r')) !== false) {
                // Read the header row
                $header = fgetcsv($handle);
                
                if ($header === false) {
                    $validator->errors()->add('file', 'The CSV file appears to be empty or corrupted.');
                    fclose($handle);
                    return;
                }

                // Expected CSV headers (case-insensitive)
                $requiredHeaders = [
                    'beneficiary_name',
                    'beneficiary_account',
                    'amount',
                    'currency',
                    'settlement_method',
                ];

                $optionalHeaders = [
                    'payment_purpose',
                    'reference',
                ];

                // Normalize headers for comparison
                $normalizedHeaders = array_map('strtolower', array_map('trim', $header));
                $normalizedRequired = array_map('strtolower', $requiredHeaders);

                // Check for required headers
                $missingHeaders = array_diff($normalizedRequired, $normalizedHeaders);
                if (!empty($missingHeaders)) {
                    $validator->errors()->add('file', 
                        'The CSV file is missing required columns: ' . implode(', ', $missingHeaders)
                    );
                }

                // Check if we have at least one data row
                $firstDataRow = fgetcsv($handle);
                if ($firstDataRow === false) {
                    $validator->errors()->add('file', 'The CSV file must contain at least one payment instruction row.');
                } else {
                    // Validate that the data row has the same number of columns as the header
                    if (count($firstDataRow) !== count($header)) {
                        $validator->errors()->add('file', 'The CSV file structure is inconsistent. All rows must have the same number of columns as the header.');
                    }
                }

                // Check file size constraints (row count estimate)
                rewind($handle);
                $rowCount = 0;
                while (fgetcsv($handle) !== false) {
                    $rowCount++;
                    // Prevent memory issues by limiting initial validation
                    if ($rowCount > 10001) { // 1 header + 10000 max data rows
                        $validator->errors()->add('file', 'The CSV file contains too many rows. Maximum allowed is 10,000 payment instructions.');
                        break;
                    }
                }

                fclose($handle);
            } else {
                $validator->errors()->add('file', 'Unable to read the uploaded CSV file. Please ensure it is not corrupted.');
            }
        } catch (\Exception $e) {
            $validator->errors()->add('file', 'Error validating CSV file structure. Please ensure the file is a valid CSV format.');
        }
    }

    /**
     * Get the validated data with additional processing.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);
        
        // Set default currency if not provided
        if (!isset($validated['currency'])) {
            $validated['currency'] = PaymentFile::CURRENCY_USD;
        }

        return $validated;
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'You do not have permission to upload payment files.'
        );
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors();
        
        // Add contextual information to file validation errors
        if ($errors->has('file')) {
            $fileErrors = $errors->get('file');
            $contextualErrors = [];
            
            foreach ($fileErrors as $error) {
                if (strpos($error, 'structure') !== false || strpos($error, 'columns') !== false) {
                    $contextualErrors[] = $error . ' Please download the CSV template for the correct format.';
                } else {
                    $contextualErrors[] = $error;
                }
            }
            
            $errors->forget('file');
            foreach ($contextualErrors as $error) {
                $errors->add('file', $error);
            }
        }

        parent::failedValidation($validator);
    }

    /**
     * Get the file instance from the request.
     *
     * @return \Illuminate\Http\UploadedFile|null
     */
    public function getFile()
    {
        return $this->file('file');
    }

    /**
     * Get the original filename.
     *
     * @return string
     */
    public function getOriginalFilename(): string
    {
        return $this->file('file')->getClientOriginalName();
    }

    /**
     * Get the file size in bytes.
     *
     * @return int
     */
    public function getFileSize(): int
    {
        return $this->file('file')->getSize();
    }

    /**
     * Get the currency code (with fallback to default).
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->input('currency', PaymentFile::CURRENCY_USD);
    }

    /**
     * Check if the uploaded file is valid.
     *
     * @return bool
     */
    public function hasValidFile(): bool
    {
        return $this->hasFile('file') && $this->file('file')->isValid();
    }

    /**
     * Get validation rules for testing purposes.
     *
     * @return array<string, array>
     */
    public static function getValidationRules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:10240',
                'min:1',
            ],
            'currency' => [
                'sometimes',
                'string',
                'size:3',
                Rule::in(PaymentFile::getValidCurrencies()),
            ],
        ];
    }
}