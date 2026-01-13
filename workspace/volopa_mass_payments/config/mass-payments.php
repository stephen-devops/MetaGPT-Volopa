{
  "Development Plan": [
    "config/mass-payments.php"
  ],
  "Incremental Change": [
    "config/mass-payments.php: Configuration file with file limits, validation rules, currency settings, purpose codes, and system constraints"
  ]
}

## Code: config/mass-payments.php
```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mass Payments System Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the mass payments system
    | including file upload limits, validation rules, supported currencies,
    | purpose codes, and system constraints.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    */

    'max_file_size_mb' => env('MASS_PAYMENTS_MAX_FILE_SIZE', 10),
    'max_rows_per_file' => env('MASS_PAYMENTS_MAX_ROWS', 10000),
    'min_rows_per_file' => env('MASS_PAYMENTS_MIN_ROWS', 1),
    'allowed_file_extensions' => ['csv', 'txt'],
    'allowed_mime_types' => [
        'text/csv',
        'text/plain',
        'application/csv',
        'text/comma-separated-values',
        'application/octet-stream',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Storage Settings
    |--------------------------------------------------------------------------
    */

    'storage_disk' => env('MASS_PAYMENTS_STORAGE_DISK', 'local'),
    'storage_path' => env('MASS_PAYMENTS_STORAGE_PATH', 'mass-payment-files'),
    'retention_days' => env('MASS_PAYMENTS_RETENTION_DAYS', 90),
    'archive_after_days' => env('MASS_PAYMENTS_ARCHIVE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    */

    'validation' => [
        'max_amount_per_instruction' => env('MASS_PAYMENTS_MAX_AMOUNT', 999999.99),
        'min_amount_per_instruction' => env('MASS_PAYMENTS_MIN_AMOUNT', 0.01),
        'max_total_file_amount' => env('MASS_PAYMENTS_MAX_TOTAL', 10000000.00),
        'required_csv_headers' => [
            'amount',
            'currency',
            'beneficiary_name',
            'beneficiary_account',
            'bank_code',
        ],
        'optional_csv_headers' => [
            'reference',
            'purpose_code',
            'beneficiary_address',
            'beneficiary_country',
            'beneficiary_city',
            'intermediary_bank',
            'special_instructions',
        ],
        'allow_duplicate_beneficiaries' => env('MASS_PAYMENTS_ALLOW_DUPLICATES', true),
        'strict_currency_validation' => env('MASS_PAYMENTS_STRICT_CURRENCY', true),
        'validate_bank_codes' => env('MASS_PAYMENTS_VALIDATE_BANKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    */

    'supported_currencies' => [
        'USD' => 'United States Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound Sterling',
        'AUD' => 'Australian Dollar',
        'CAD' => 'Canadian Dollar',
        'SGD' => 'Singapore Dollar',
        'HKD' => 'Hong Kong Dollar',
        'JPY' => 'Japanese Yen',
        'CHF' => 'Swiss Franc',
        'NOK' => 'Norwegian Krone',
        'SEK' => 'Swedish Krona',
        'DKK' => 'Danish Krone',
        'NZD' => 'New Zealand Dollar',
        'MXN' => 'Mexican Peso',
        'ZAR' => 'South African Rand',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Specific Settings
    |--------------------------------------------------------------------------
    */

    'currency_settings' => [
        'USD' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_swift_code' => true,
            'supported_countries' => ['US', 'CA', 'MX'],
        ],
        'EUR' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_iban' => true,
            'supported_countries' => ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT'],
        ],
        'GBP' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_sort_code' => true,
            'supported_countries' => ['GB'],
        ],
        'JPY' => [
            'decimal_places' => 0,
            'max_amount' => 99999999,
            'min_amount' => 1,
            'requires_purpose_code' => true,
            'requires_swift_code' => true,
            'supported_countries' => ['JP'],
        ],
        'SGD' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_swift_code' => true,
            'supported_countries' => ['SG'],
        ],
        'HKD' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_swift_code' => true,
            'supported_countries' => ['HK'],
        ],
        'AUD' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_bsb' => true,
            'supported_countries' => ['AU'],
        ],
        'CAD' => [
            'decimal_places' => 2,
            'max_amount' => 999999.99,
            'min_amount' => 0.01,
            'requires_purpose_code' => true,
            'requires_swift_code' => true,
            'supported_countries' => ['CA'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Purpose of Payment Codes
    |--------------------------------------------------------------------------
    */

    'purpose_codes' => [
        'SALA' => 'Salary Payment',
        'TRAD' => 'Trade Payment',
        'SUPP' => 'Supplier Payment',
        'SERV' => 'Service Payment',
        'DIVD' => 'Dividend Payment',
        'INSU' => 'Insurance Payment',
        'LOAN' => 'Loan Payment',
        'RENT' => 'Rent Payment',
        'UTIL' => 'Utility Payment',
        'FEES' => 'Fee Payment',
        'ROYA' => 'Royalty Payment',
        'COMM' => 'Commission Payment',
        'REIM' => 'Reimbursement',
        'REFU' => 'Refund',
        'DONA' => 'Donation',
        'GIFT' => 'Gift Payment',
        'PENS' => 'Pension Payment',
        'BONU' => 'Bonus Payment',
        'INVE' => 'Investment',
        'OTHR' => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Country Specific Purpose Codes
    |--------------------------------------------------------------------------
    */

    'country_purpose_codes' => [
        'US' => [
            'SALA', 'TRAD', 'SUPP', 'SERV', 'DIVD', 'INSU', 'LOAN', 
            'FEES', 'ROYA', 'COMM', 'REIM', 'REFU', 'INVE', 'OTHR'
        ],
        'GB' => [
            'SALA', 'TRAD', 'SUPP', 'SERV', 'DIVD', 'INSU', 'LOAN', 
            'RENT', 'UTIL', 'FEES', 'ROYA', 'COMM', 'REIM', 'REFU', 'OTHR'
        ],
        'SG' => [
            'SALA', 'TRAD', 'SUPP', 'SERV', 'DIVD', 'INSU', 'LOAN', 
            'FEES', 'ROYA', 'COMM', 'REIM', 'REFU', 'INVE', 'OTHR'
        ],
        'AU' => [
            'SALA', 'TRAD', 'SUPP', 'SERV', 'DIVD', 'INSU', 'LOAN', 
            'FEES', 'ROYA', 'COMM', 'REIM', 'REFU', 'PENS', 'BONU', 'OTHR'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval Settings
    |--------------------------------------------------------------------------
    */

    'approval' => [
        'approval_threshold' => env('MASS_PAYMENTS_APPROVAL_THRESHOLD', 100000.00),
        'auto_approve_threshold' => env('MASS_PAYMENTS_AUTO_APPROVE_THRESHOLD', 1000.00),
        'max_approval_age_hours' => env('MASS_PAYMENTS_MAX_APPROVAL_AGE', 72),
        'daily_approval_limit' => env('MASS_PAYMENTS_DAILY_APPROVAL_LIMIT', 50),
        'require_dual_approval' => env('MASS_PAYMENTS_DUAL_APPROVAL', false),
        'dual_approval_threshold' => env('MASS_PAYMENTS_DUAL_THRESHOLD', 500000.00),
        'allow_self_approval' => env('MASS_PAYMENTS_SELF_APPROVAL', false),
        'approval_timeout_hours' => env('MASS_PAYMENTS_APPROVAL_TIMEOUT', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Settings
    |--------------------------------------------------------------------------
    */

    'processing' => [
        'max_concurrent_jobs' => env('MASS_PAYMENTS_MAX_JOBS', 5),
        'batch_size' => env('MASS_PAYMENTS_BATCH_SIZE', 100),
        'retry_attempts' => env('MASS_PAYMENTS_RETRY_ATTEMPTS', 3),
        'retry_delay_seconds' => env('MASS_PAYMENTS_RETRY_DELAY', 300),
        'timeout_minutes' => env('MASS_PAYMENTS_TIMEOUT', 30),
        'enable_notifications' => env('MASS_PAYMENTS_NOTIFICATIONS', true),
        'notification_channels' => ['mail', 'database'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'validation_queue' => env('MASS_PAYMENTS_VALIDATION_QUEUE', 'validation'),
        'processing_queue' => env('MASS_PAYMENTS_PROCESSING_QUEUE', 'payments'),
        'notification_queue' => env('MASS_PAYMENTS_NOTIFICATION_QUEUE', 'notifications'),
        'validation_timeout' => env('MASS_PAYMENTS_VALIDATION_TIMEOUT', 600),
        'processing_timeout' => env('MASS_PAYMENTS_PROCESSING_TIMEOUT', 1800),
        'max_validation_attempts' => env('MASS_PAYMENTS_MAX_VALIDATION_ATTEMPTS', 3),
        'max_processing_attempts' => env('MASS_PAYMENTS_MAX_PROCESSING_ATTEMPTS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */

    'security' => [
        'encrypt_files' => env('MASS_PAYMENTS_ENCRYPT_FILES', true),
        'virus_scan' => env('MASS_PAYMENTS_VIRUS_SCAN', false),
        'ip_whitelist' => env('MASS_PAYMENTS_IP_WHITELIST', null),
        'rate_limit_per_minute' => env('MASS_PAYMENTS_RATE_LIMIT', 60),
        'max_uploads_per_day' => env('MASS_PAYMENTS_MAX_UPLOADS_DAILY', 100),
        'audit_all_actions' => env('MASS_PAYMENTS_AUDIT_ALL', true),
        'log_sensitive_data' => env('MASS_PAYMENTS_LOG_SENSITIVE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance Settings
    |--------------------------------------------------------------------------
    */

    'compliance' => [
        'compliance_required_currencies' => ['USD', 'EUR'],
        'aml_check_threshold' => env('MASS_PAYMENTS_AML_THRESHOLD', 10000.00),
        'sanctions_check' => env('MASS_PAYMENTS_SANCTIONS_CHECK', true),
        'require_compliance_notes' => env('MASS_PAYMENTS_COMPLIANCE_NOTES', false),
        'compliance_timeout_hours' => env('MASS_PAYMENTS_COMPLIANCE_TIMEOUT', 48),
        'auto_compliance_threshold' => env('MASS_PAYMENTS_AUTO_COMPLIANCE', 1000.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Template Settings
    |--------------------------------------------------------------------------
    */

    'templates' => [
        'enable_recipient_templates' => env('MASS_PAYMENTS_RECIPIENT_TEMPLATES', true),
        'max_recipients_in_template' => env('MASS_PAYMENTS_MAX_TEMPLATE_RECIPIENTS', 1000),
        'template_cache_minutes' => env('MASS_PAYMENTS_TEMPLATE_CACHE', 60),
        'include_sample_data' => env('MASS_PAYMENTS_TEMPLATE_SAMPLES', true),
        'template_formats' => ['csv', 'xlsx'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'upload_success' => env('MASS_PAYMENTS_NOTIFY_UPLOAD', true),
        'validation_complete' => env('MASS_PAYMENTS_NOTIFY_VALIDATION', true),
        'approval_required' => env('MASS_PAYMENTS_NOTIFY_APPROVAL', true),
        'processing_complete' => env('MASS_PAYMENTS_NOTIFY_PROCESSING', true),
        'processing_failed' => env('MASS_PAYMENTS_NOTIFY_FAILURES', true),
        'daily_summary' => env('MASS_PAYMENTS_DAILY_SUMMARY', false),
        'weekly_report' => env('MASS_PAYMENTS_WEEKLY_REPORT', false),
        'email_from' => env('MASS_PAYMENTS_EMAIL_FROM', 'noreply@volopa.com'),
        'email_reply_to' => env('MASS_PAYMENTS_EMAIL_REPLY', 'support@volopa.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enable_caching' => env('MASS_PAYMENTS_CACHE_ENABLED', true),
        'currency_cache_minutes' => env('MASS_PAYMENTS_CURRENCY_CACHE', 60),
        'purpose_codes_cache_minutes' => env('MASS_PAYMENTS_PURPOSE_CACHE', 120),
        'beneficiary_cache_minutes' => env('MASS_PAYMENTS_BENEFICIARY