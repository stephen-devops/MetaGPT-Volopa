Looking at the context and requirements, I need to implement the `UploadMassPaymentFileRequest.php` FormRequest class that handles validation and authorization for mass payment file uploads. Based on the design patterns and requirements, this class needs to validate file uploads, currency parameters, TCC account access, and integrate with the MassPaymentFilePolicy for authorization.

## Code: app/Http/Requests/UploadMassPaymentFileRequest.php

```php
<?php

namespace App\Http\Requests;

use App\Models\TccAccount;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadMassPaymentFileRequest extends FormRequest
{
    /**
     * Maximum file size in bytes (50MB).
     */
    private const MAX_FILE_SIZE = 52428800; // 50MB in bytes

    /**
     * Maximum number of rows allowed in CSV.
     */
    private const MAX_CSV_ROWS = 10000;

    /**
     * Supported file MIME types.
     */
    private const ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'text/comma-separated-values',
    ];

    /**
     * Supported file extensions.
     */
    private const ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * Supported currencies for mass payments.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'CNY',
        'SEK', 'NZD', 'MXN', 'SGD', 'HKD', 'NOK', 'TRY', 'ZAR',
        'INR', 'BRL', 'PLN', 'CZK', 'HUF', 'ILS', 'KRW', 'TWD',
        'THB', 'MYR', 'PHP', 'IDR', 'VND', 'AED', 'SAR', 'EGP',
    ];

    /**
     * Currencies that require invoice details.
     */
    private const INVOICE_REQUIRED_CURRENCIES = ['INR'];

    /**
     * Currencies that require incorporation number for business recipients.
     */
    private const INCORPORATION_REQUIRED_CURRENCIES = ['TRY'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $policy = new MassPaymentFilePolicy();
        
        // Check basic create permission
        if (!$policy->create(Auth::user())) {
            return false;
        }

        // Check currency-specific permissions
        $currency = $this->input('currency');
        if ($currency && !$policy->processCurrency(Auth::user(), $currency)) {
            return false;
        }

        // Check TCC account access
        $tccAccountId = $this->input('tcc_account_id');
        if ($tccAccountId && !$policy->accessTccAccount(Auth::user(), $tccAccountId)) {
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
            'file' => [
                'required',
                'file',
                'max:' . (self::MAX_FILE_SIZE / 1024), // Laravel expects KB
                function ($attribute, $value, $fail) {
                    $this->validateFileType($value, $fail);
                },
                function ($attribute, $value, $fail) {
                    $this->validateFileSize($value, $fail);
                },
                function ($attribute, $value, $fail) {
                    $this->validateCsvStructure($value, $fail);
                },
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(self::SUPPORTED_CURRENCIES),
                function ($attribute, $value, $fail) {
                    $this->validateCurrencyPermissions($value, $fail);
                },
            ],
            'tcc_account_id' => [
                'required',
                'string',
                'uuid',
                function ($attribute, $value, $fail) {
                    $this->validateTccAccount($value, $fail);
                },
            ],
            'description' => [
                'nullable',
                'string',
                'max:500',
            ],
            'auto_approve' => [
                'nullable',
                'boolean',
            ],
            'notification_settings' => [
                'nullable',
                'array',
            ],
            'notification_settings.email' => [
                'nullable',
                'boolean',
            ],
            'notification_settings.webhook' => [
                'nullable',
                'boolean',
            ],
            'notification_settings.dashboard' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please select a CSV file to upload.',
            'file.file' => 'The uploaded file is not valid.',
            'file.max' => 'The file size cannot exceed ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB.',
            
            'currency.required' => 'Please specify the payment currency.',
            'currency.string' => 'Currency must be a valid string.',
            'currency.size' => 'Currency must be exactly 3 characters (e.g., USD, EUR).',
            'currency.in' => 'The selected currency is not supported for mass payments.',
            
            'tcc_account_id.required' => 'Please select a TCC account for funding.',
            'tcc_account_id.string' => 'TCC account ID must be a valid string.',
            'tcc_account_id.uuid' => 'TCC account ID must be a valid UUID.',
            
            'description.string' => 'Description must be a valid string.',
            'description.max' => 'Description cannot exceed 500 characters.',
            
            'auto_approve.boolean' => 'Auto approve setting must be true or false.',
            
            'notification_settings.array' => 'Notification settings must be a valid array.',
            'notification_settings.email.boolean' => 'Email notification setting must be true or false.',
            'notification_settings.webhook.boolean' => 'Webhook notification setting must be true or false.',
            'notification_settings.dashboard.boolean' => 'Dashboard notification setting must be true or false.',
        ];
    }

    /**
     * Get custom attribute names for validation messages.
     */
    public function attributes(): array
    {
        return [
            'file' => 'CSV file',
            'currency' => 'currency',
            'tcc_account_id' => 'TCC account',
            'description' => 'description',
            'auto_approve' => 'auto approval',
            'notification_settings' => 'notification settings',
            'notification_settings.email' => 'email notifications',
            'notification_settings.webhook' => 'webhook notifications',
            'notification_settings.dashboard' => 'dashboard notifications',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->input('currency')),
            ]);
        }

        // Set default notification settings
        if (!$this->has('notification_settings')) {
            $this->merge([
                'notification_settings' => [
                    'email' => true,
                    'webhook' => false,
                    'dashboard' => true,
                ],
            ]);
        }

        // Set default auto_approve
        if (!$this->has('auto_approve')) {
            $this->merge([
                'auto_approve' => false,
            ]);
        }
    }

    /**
     * Validate file type and extension.
     */
    private function validateFileType(UploadedFile $file, callable $fail): void
    {
        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            $fail('The file must be a valid CSV file. Supported types: ' . implode(', ', self::ALLOWED_MIME_TYPES));
            return;
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $fail('The file must have a valid extension. Supported extensions: ' . implode(', ', self::ALLOWED_EXTENSIONS));
            return;
        }

        // Additional check: ensure file is readable
        if (!is_readable($file->getPathname())) {
            $fail('The uploaded file cannot be read. Please try uploading again.');
        }
    }

    /**
     * Validate file size constraints.
     */
    private function validateFileSize(UploadedFile $file, callable $fail): void
    {
        $fileSize = $file->getSize();
        
        if ($fileSize === false || $fileSize === 0) {
            $fail('The uploaded file appears to be empty or corrupted.');
            return;
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            $sizeMB = round($fileSize / 1024 / 1024, 2);
            $maxMB = round(self::MAX_FILE_SIZE / 1024 / 1024, 2);
            $fail("The file size ({$sizeMB}MB) exceeds the maximum allowed size of {$maxMB}MB.");
        }
    }

    /**
     * Validate CSV structure and row count.
     */
    private function validateCsvStructure(UploadedFile $file, callable $fail): void
    {
        try {
            $handle = fopen($file->getPathname(), 'r');
            
            if ($handle === false) {
                $fail('Cannot read the uploaded CSV file. Please ensure the file is not corrupted.');
                return;
            }

            // Check if file has content
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                fclose($handle);
                $fail('The CSV file appears to be empty.');
                return;
            }

            // Reset file pointer
            rewind($handle);

            // Validate CSV format and count rows
            $rowCount = 0;
            $headerChecked = false;
            $requiredHeaders = $this->getRequiredHeaders();

            while (($row = fgetcsv($handle)) !== false) {
                $rowCount++;

                // Validate header row
                if (!$headerChecked) {
                    $this->validateCsvHeaders($row, $requiredHeaders, $fail);
                    $headerChecked = true;
                    continue;
                }

                // Check row count limit (excluding header)
                if ($rowCount > self::MAX_CSV_ROWS + 1) { // +1 for header
                    fclose($handle);
                    $fail("The CSV file contains too many rows. Maximum allowed: " . number_format(self::MAX_CSV_ROWS) . " payment instructions.");
                    return;
                }

                // Basic row validation
                if (empty(array_filter($row))) {
                    continue; // Skip empty rows
                }

                // Check minimum required columns
                if (count($row) < count($requiredHeaders)) {
                    fclose($handle);
                    $fail("Row {$rowCount} does not have enough columns. Expected: " . count($requiredHeaders) . ", Found: " . count($row));
                    return;
                }
            }

            fclose($handle);

            // Check if file has data rows
            if ($rowCount <= 1) {
                $fail('The CSV file must contain at least one payment instruction row in addition to the header.');
            }

        } catch (\Exception $e) {
            $fail('Error processing CSV file: ' . $e->getMessage());
        }
    }

    /**
     * Validate CSV headers.
     */
    private function validateCsvHeaders(array $headers, array $requiredHeaders, callable $fail): void
    {
        // Normalize headers (trim and lowercase)
        $normalizedHeaders = array_map(function ($header) {
            return strtolower(trim($header));
        }, $headers);

        $normalizedRequired = array_map('strtolower', $requiredHeaders);

        // Check for required headers
        $missingHeaders = array_diff($normalizedRequired, $normalizedHeaders);
        
        if (!empty($missingHeaders)) {
            $fail('Missing required columns in CSV header: ' . implode(', ', $missingHeaders));
        }
    }

    /**
     * Get required CSV headers based on currency.
     */
    private function getRequiredHeaders(): array
    {
        $baseHeaders = [
            'beneficiary_name',
            'amount',
            'reference',
            'purpose_code',
            'beneficiary_email',
            'beneficiary_country',
            'beneficiary_type',
        ];

        $currency = $this->input('currency');

        // Add currency-specific required headers
        if (in_array($currency, self::INVOICE_REQUIRED_CURRENCIES)) {
            $baseHeaders[] = 'invoice_number';
            $baseHeaders[] = 'invoice_date';
        }

        if (in_array($currency, self::INCORPORATION_REQUIRED_CURRENCIES)) {
            $baseHeaders[] = 'incorporation_number';
        }

        return $baseHeaders;
    }

    /**
     * Validate currency-specific permissions.
     */
    private function validateCurrencyPermissions(string $currency, callable $fail): void
    {
        $user = Auth::user();
        $policy = new MassPaymentFilePolicy();

        if (!$policy->processCurrency($user, $currency)) {
            $fail("You do not have permission to process payments in {$currency}.");
        }
    }

    /**
     * Validate TCC account access and currency support.
     */
    private function validateTccAccount(string $tccAccountId, callable $fail): void
    {
        $user = Auth::user();
        
        try {
            // Check if TCC account exists and belongs to client
            $tccAccount = TccAccount::where('id', $tccAccountId)
                ->where('client_id', $user->client_id)
                ->first();

            if (!$tccAccount) {
                $fail('The selected TCC account does not exist or you do not have access to it.');
                return;
            }

            // Check if account is active
            if (!$tccAccount->isActive()) {
                $fail('The selected TCC account is not active and cannot be used for mass payments.');
                return;
            }

            // Check if account supports the requested currency
            $currency = $this->input('currency');
            if ($currency && !$tccAccount->supportsCurrency($currency)) {
                $fail("The selected TCC account does not support payments in {$currency}.");
                return;
            }

            // Check if account can transact
            if (!$tccAccount->canTransact()) {
                $fail('The selected TCC account cannot be used for transactions at this time.');
            }

        } catch (\Exception $e) {
            $fail('Error validating TCC account: Unable to verify account details.');
        }
    }

    /**
     * Get the validated data with normalized values.
     */
    public function validated(): array
    {
        $validated = parent::validated();
        
        // Ensure currency is uppercase
        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        // Set defaults for optional fields
        $validated['description'] = $validated['description