<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\TccAccount;
use App\Policies\MassPaymentFilePolicy;
use App\Models\MassPaymentFile;

class UploadMassPaymentFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user can create mass payment files using policy
        return $this->user()->can('create', MassPaymentFile::class);
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
                'max:10240', // 10MB max file size
            ],
            'tcc_account_id' => [
                'required',
                'integer',
                Rule::exists('tcc_accounts', 'id')->where(function ($query) {
                    // Ensure the TCC account belongs to the authenticated user's client
                    $query->where('client_id', $this->user()->client_id);
                }),
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A CSV file is required for mass payment upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.mimes' => 'The file must be a CSV file with .csv or .txt extension.',
            'file.max' => 'The file size must not exceed 10MB.',
            'tcc_account_id.required' => 'A TCC account must be selected for the mass payment.',
            'tcc_account_id.integer' => 'The TCC account ID must be a valid integer.',
            'tcc_account_id.exists' => 'The selected TCC account is invalid or does not belong to your organization.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'file' => 'CSV file',
            'tcc_account_id' => 'TCC account',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure tcc_account_id is cast to integer if provided
        if ($this->has('tcc_account_id')) {
            $this->merge([
                'tcc_account_id' => (int) $this->tcc_account_id,
            ]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Additional file validation
            if ($this->hasFile('file')) {
                $file = $this->file('file');
                
                // Check if file is readable
                if (!is_readable($file->getRealPath())) {
                    $validator->errors()->add('file', 'The uploaded file is not readable.');
                    return;
                }

                // Check if file has content
                if ($file->getSize() === 0) {
                    $validator->errors()->add('file', 'The uploaded file is empty.');
                    return;
                }

                // Validate CSV structure (basic check for headers)
                try {
                    $handle = fopen($file->getRealPath(), 'r');
                    if ($handle) {
                        $headers = fgetcsv($handle);
                        fclose($handle);
                        
                        if (!$headers || count($headers) < 3) {
                            $validator->errors()->add('file', 'The CSV file must contain proper headers and data columns.');
                        }
                        
                        // Check for required columns (basic validation)
                        $requiredColumns = ['beneficiary_name', 'amount', 'currency'];
                        $missingColumns = [];
                        
                        foreach ($requiredColumns as $column) {
                            if (!in_array($column, array_map('strtolower', $headers))) {
                                $missingColumns[] = $column;
                            }
                        }
                        
                        if (!empty($missingColumns)) {
                            $validator->errors()->add('file', 'The CSV file is missing required columns: ' . implode(', ', $missingColumns));
                        }
                    }
                } catch (\Exception $e) {
                    $validator->errors()->add('file', 'Unable to read the CSV file. Please ensure it is properly formatted.');
                }
            }

            // Validate TCC account access and currency
            if ($this->tcc_account_id && !$validator->errors()->has('tcc_account_id')) {
                $tccAccount = TccAccount::where('id', $this->tcc_account_id)
                                      ->where('client_id', $this->user()->client_id)
                                      ->first();
                
                if (!$tccAccount) {
                    $validator->errors()->add('tcc_account_id', 'The selected TCC account is not accessible.');
                } elseif ($tccAccount->status !== 'active') {
                    $validator->errors()->add('tcc_account_id', 'The selected TCC account is not active.');
                } elseif ($tccAccount->balance <= 0) {
                    $validator->errors()->add('tcc_account_id', 'The selected TCC account has insufficient balance for mass payments.');
                }
            }

            // Check for duplicate filename upload (within last 24 hours)
            if ($this->hasFile('file') && $this->tcc_account_id) {
                $filename = $this->file('file')->getClientOriginalName();
                
                $existingFile = MassPaymentFile::where('tcc_account_id', $this->tcc_account_id)
                                              ->where('filename', $filename)
                                              ->where('created_at', '>=', now()->subDay())
                                              ->whereIn('status', [
                                                  'uploading',
                                                  'uploaded',
                                                  'validating',
                                                  'validated',
                                                  'pending_approval',
                                                  'approved',
                                                  'processing',
                                                  'processed'
                                              ])
                                              ->first();
                
                if ($existingFile) {
                    $validator->errors()->add('file', 'A file with the same name has already been uploaded recently for this account.');
                }
            }
        });
    }

    /**
     * Get validated data with additional processed information.
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated();
        
        // Add additional metadata about the file
        if ($this->hasFile('file')) {
            $file = $this->file('file');
            
            $validated['file_metadata'] = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension(),
            ];
        }
        
        // Add client_id from authenticated user
        $validated['client_id'] = $this->user()->client_id;
        
        // Add uploaded_by from authenticated user
        $validated['uploaded_by'] = $this->user()->id;
        
        return $key ? data_get($validated, $key, $default) : $validated;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        // Log the validation failure for monitoring
        \Log::warning('Mass payment file upload validation failed', [
            'user_id' => $this->user()->id,
            'client_id' => $this->user()->client_id,
            'errors' => $validator->errors()->toArray(),
            'input' => $this->except(['file']), // Don't log file content
        ]);

        parent::failedValidation($validator);
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        // Log the authorization failure for monitoring
        \Log::warning('Mass payment file upload authorization failed', [
            'user_id' => $this->user()->id,
            'client_id' => $this->user()->client_id ?? 'unknown',
        ]);

        parent::failedAuthorization();
    }

    /**
     * Get the error messages for the defined validation rules.
     */
    public function getValidationErrorMessages(): array
    {
        return [
            'file_too_large' => 'The uploaded file exceeds the maximum allowed size of 10MB.',
            'invalid_csv_format' => 'The uploaded file is not a valid CSV format.',
            'missing_required_columns' => 'The CSV file is missing required columns.',
            'empty_file' => 'The uploaded file is empty and cannot be processed.',
            'duplicate_filename' => 'A file with this name has been uploaded recently.',
            'inactive_account' => 'The selected TCC account is not active.',
            'insufficient_balance' => 'The selected TCC account has insufficient balance.',
            'unauthorized_access' => 'You do not have permission to upload files to this account.',
        ];
    }

    /**
     * Get the validated TCC account model.
     */
    public function getTccAccount(): ?TccAccount
    {
        if (!$this->tcc_account_id) {
            return null;
        }

        return TccAccount::where('id', $this->tcc_account_id)
                         ->where('client_id', $this->user()->client_id)
                         ->first();
    }

    /**
     * Check if the uploaded file meets size requirements.
     */
    public function isFileSizeAcceptable(): bool
    {
        if (!$this->hasFile('file')) {
            return false;
        }

        $maxSize = 10 * 1024 * 1024; // 10MB in bytes
        return $this->file('file')->getSize() <= $maxSize;
    }

    /**
     * Get estimated row count from the uploaded CSV file.
     */
    public function getEstimatedRowCount(): int
    {
        if (!$this->hasFile('file')) {
            return 0;
        }

        try {
            $lineCount = 0;
            $handle = fopen($this->file('file')->getRealPath(), 'r');
            
            if ($handle) {
                while (!feof($handle)) {
                    if (fgets($handle) !== false) {
                        $lineCount++;
                    }
                }
                fclose($handle);
                
                // Subtract header row
                return max(0, $lineCount - 1);
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to estimate row count for uploaded file', [
                'error' => $e->getMessage(),
                'user_id' => $this->user()->id,
            ]);
        }

        return 0;
    }

    /**
     * Generate a unique filename for storage.
     */
    public function generateStorageFilename(): string
    {
        if (!$this->hasFile('file')) {
            return '';
        }

        $originalName = pathinfo($this->file('file')->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $this->file('file')->getClientOriginalExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        $userId = $this->user()->id;
        
        return "mass_payment_{$userId}_{$timestamp}_{$originalName}.{$extension}";
    }

    /**
     * Get storage path for the uploaded file.
     */
    public function getStoragePath(): string
    {
        $clientId = $this->user()->client_id;
        $year = now()->year;
        $month = now()->format('m');
        
        return "mass_payments/client_{$clientId}/{$year}/{$month}";
    }
}