## Code: config/pocket_expense.php

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pocket Expense Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration options for the pocket expense system
    | including validation rules, limits, file upload settings, and feature flags.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Feature Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for pocket expense feature enablement and permissions.
    |
    */
    'feature' => [
        'enabled' => env('POCKET_EXPENSE_ENABLED', true),
        'feature_id' => env('POCKET_EXPENSE_FEATURE_ID', 1),
        'require_approval' => env('POCKET_EXPENSE_REQUIRE_APPROVAL', true),
        'allow_self_approval' => env('POCKET_EXPENSE_ALLOW_SELF_APPROVAL', false),
        'auto_submit_on_create' => env('POCKET_EXPENSE_AUTO_SUBMIT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Default validation settings for pocket expense data.
    |
    */
    'validation' => [
        'date' => [
            'max_age_years' => env('POCKET_EXPENSE_MAX_DATE_AGE_YEARS', 3),
            'allow_future_dates' => env('POCKET_EXPENSE_ALLOW_FUTURE_DATES', false),
            'format' => env('POCKET_EXPENSE_DATE_FORMAT', 'Y-m-d'),
        ],
        'amount' => [
            'min' => env('POCKET_EXPENSE_MIN_AMOUNT', 0.01),
            'max' => env('POCKET_EXPENSE_MAX_AMOUNT', 999999.99),
            'decimal_places' => env('POCKET_EXPENSE_AMOUNT_DECIMAL_PLACES', 2),
        ],
        'vat' => [
            'min_percentage' => env('POCKET_EXPENSE_VAT_MIN', 0),
            'max_percentage' => env('POCKET_EXPENSE_VAT_MAX', 99.99),
            'decimal_places' => env('POCKET_EXPENSE_VAT_DECIMAL_PLACES', 2),
        ],
        'merchant_name' => [
            'min_length' => env('POCKET_EXPENSE_MERCHANT_NAME_MIN_LENGTH', 1),
            'max_length' => env('POCKET_EXPENSE_MERCHANT_NAME_MAX_LENGTH', 255),
        ],
        'description' => [
            'max_length' => env('POCKET_EXPENSE_DESCRIPTION_MAX_LENGTH', 1000),
        ],
        'address' => [
            'max_length' => env('POCKET_EXPENSE_ADDRESS_MAX_LENGTH', 500),
        ],
        'notes' => [
            'max_length' => env('POCKET_EXPENSE_NOTES_MAX_LENGTH', 2000),
        ],
        'source_note' => [
            'max_length' => env('POCKET_EXPENSE_SOURCE_NOTE_MAX_LENGTH', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    |
    | Supported currencies and conversion settings.
    |
    */
    'currencies' => [
        'supported' => env('POCKET_EXPENSE_SUPPORTED_CURRENCIES', 'USD,EUR,GBP,CAD,AUD,JPY,CHF,SEK,NOK,DKK'),
        'default' => env('POCKET_EXPENSE_DEFAULT_CURRENCY', 'USD'),
        'format_decimal_places' => env('POCKET_EXPENSE_CURRENCY_DECIMAL_PLACES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Country Settings
    |--------------------------------------------------------------------------
    |
    | Supported countries for merchant locations.
    |
    */
    'countries' => [
        'supported' => env('POCKET_EXPENSE_SUPPORTED_COUNTRIES', 'US,GB,CA,AU,FR,DE,IT,ES,NL,BE,CH,AT,SE,NO,DK,FI,IE,PT,LU,JP'),
        'default' => env('POCKET_EXPENSE_DEFAULT_COUNTRY', 'US'),
        'require_for_address' => env('POCKET_EXPENSE_REQUIRE_COUNTRY_FOR_ADDRESS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for file attachments and CSV uploads.
    |
    */
    'files' => [
        'receipts' => [
            'enabled' => env('POCKET_EXPENSE_RECEIPTS_ENABLED', true),
            'max_files_per_expense' => env('POCKET_EXPENSE_MAX_RECEIPT_FILES', 5),
            'max_file_size' => env('POCKET_EXPENSE_MAX_RECEIPT_SIZE', 5242880), // 5MB in bytes
            'allowed_mimes' => env('POCKET_EXPENSE_RECEIPT_MIMES', 'pdf,jpg,jpeg,png,gif'),
            'allowed_extensions' => env('POCKET_EXPENSE_RECEIPT_EXTENSIONS', 'pdf,jpg,jpeg,png,gif'),
            'storage_disk' => env('POCKET_EXPENSE_RECEIPT_STORAGE_DISK', 'local'),
            'storage_path' => env('POCKET_EXPENSE_RECEIPT_STORAGE_PATH', 'pocket-expenses/receipts'),
        ],
        'csv_upload' => [
            'enabled' => env('POCKET_EXPENSE_CSV_UPLOAD_ENABLED', true),
            'max_file_size' => env('POCKET_EXPENSE_CSV_MAX_SIZE', 10485760), // 10MB in bytes
            'max_rows' => env('POCKET_EXPENSE_CSV_MAX_ROWS', 200),
            'allowed_mimes' => env('POCKET_EXPENSE_CSV_MIMES', 'text/csv,text/plain,application/octet-stream,application/csv'),
            'allowed_extensions' => env('POCKET_EXPENSE_CSV_EXTENSIONS', 'csv,txt'),
            'storage_disk' => env('POCKET_EXPENSE_CSV_STORAGE_DISK', 'local'),
            'storage_path' => env('POCKET_EXPENSE_CSV_STORAGE_PATH', 'pocket-expenses/uploads'),
            'processing_batch_size' => env('POCKET_EXPENSE_CSV_BATCH_SIZE', 100),
            'validation_timeout' => env('POCKET_EXPENSE_CSV_VALIDATION_TIMEOUT', 300), // 5 minutes
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CSV Column Mapping
    |--------------------------------------------------------------------------
    |
    | Column mappings and validation rules for CSV import.
    |
    */
    'csv_mapping' => [
        'required_columns' => [
            'Date' => [
                'field' => 'date',
                'format' => 'DD/MM/YYYY',
                'validation' => 'required|date|date_format:d/m/Y',
            ],
            'Expense Type' => [
                'field' => 'expense_type',
                'validation' => 'required|exists:opt_pocket_expense_type,option',
            ],
            'Currency Code' => [
                'field' => 'currency',
                'format' => '3-letter ISO',
                'validation' => 'required|string|size:3|regex:/^[A-Z]{3}$/',
            ],
            'Amount' => [
                'field' => 'amount',
                'validation' => 'required|numeric|min:0.01',
                'apply_expense_type_sign' => true,
            ],
            'Merchant Name' => [
                'field' => 'merchant_name',
                'validation' => 'required|string|max:255',
            ],
        ],
        'optional_columns' => [
            'Currency Equivalent Amount' => [
                'field' => 'user_converted_amount',
                'validation' => 'nullable|numeric|min:0.01',
                'apply_expense_type_sign' => true,
            ],
            'VAT %' => [
                'field' => 'vat_amount',
                'validation' => 'nullable|numeric|min:0|max:100',
                'strip_percentage_sign' => true,
            ],
            'Description' => [
                'field' => 'merchant_description',
                'validation' => 'nullable|string|max:1000',
            ],
            'Merchant Address' => [
                'field' => 'merchant_address',
                'validation' => 'nullable|string|max:500',
            ],
            'Merchant Country' => [
                'field' => 'merchant_country',
                'validation' => 'nullable|string|size:2|regex:/^[A-Z]{2}$/',
            ],
            'Source' => [
                'field' => 'expense_source_name',
                'validation' => 'nullable|string|max:100',
                'requires_source_note_if_other' => true,
            ],
            'Source Note' => [
                'field' => 'source_note',
                'validation' => 'nullable|string|max:500',
                'required_when_source_other' => true,
            ],
            'Notes' => [
                'field' => 'notes',
                'validation' => 'nullable|string|max:2000',
            ],
        ],
        'system_fields' => [
            'user_id' => 'from_ui_selection',
            'status' => 'submitted',
            'created_by_user_id' => 'authenticated_user',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Expense Source Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for expense source management.
    |
    */
    'sources' => [
        'default_sources' => [
            'Cash' => ['is_default' => true, 'client_id' => null],
            'Corporate Card' => ['is_default' => true, 'client_id' => null],
            'Personal Card' => ['is_default' => true, 'client_id' => null],
            'Other' => ['is_default' => false, 'client_id' => null, 'requires_note' => true],
        ],
        'max_active_per_client' => env('POCKET_EXPENSE_MAX_SOURCES_PER_CLIENT', 20),
        'allow_custom_sources' => env('POCKET_EXPENSE_ALLOW_CUSTOM_SOURCES', true),
        'require_note_for_other' => env('POCKET_EXPENSE_REQUIRE_NOTE_FOR_OTHER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Configuration
    |--------------------------------------------------------------------------
    |
    | Expense status workflow settings.
    |
    */
    'statuses' => [
        'available' => [
            'draft' => ['label' => 'Draft', 'color' => 'gray', 'editable' => true],
            'submitted' => ['label' => 'Submitted', 'color' => 'blue', 'editable' => false],
            'approved' => ['label' => 'Approved', 'color' => 'green', 'editable' => false],
            'rejected' => ['label' => 'Rejected', 'color' => 'red', 'editable' => true],
        ],
        'default_status' => env('POCKET_EXPENSE_DEFAULT_STATUS', 'draft'),
        'auto_submit_from_csv' => env('POCKET_EXPENSE_CSV_AUTO_SUBMIT', true),
        'transitions' => [
            'draft' => ['submitted'],
            'submitted' => ['approved', 'rejected'],
            'rejected' => ['draft', 'submitted'],
            'approved' => [], // Cannot transition from approved
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FX Conversion Settings
    |--------------------------------------------------------------------------
    |
    | Foreign exchange conversion configuration.
    |
    */
    'fx_conversion' => [
        'enabled' => env('POCKET_EXPENSE_FX_ENABLED', true),
        'provider' => env('POCKET_EXPENSE_FX_PROVIDER', 'volopa_api'),
        'api_endpoint' => env('POCKET_EXPENSE_FX_API_ENDPOINT', '/api/wallet-ccy-value'),
        'cache_duration' => env('POCKET_EXPENSE_FX_CACHE_DURATION', 3600), // 1 hour in seconds
        'max_lookback_days' => env('POCKET_EXPENSE_FX_LOOKBACK_DAYS', 30),
        'debounce_seconds' => env('POCKET_EXPENSE_FX_DEBOUNCE', 2),
        'timeout_seconds' => env('POCKET_EXPENSE_FX_TIMEOUT', 10),
        'fallback_enabled' => env('POCKET_EXPENSE_FX_FALLBACK_ENABLED', true),
        'user_override_allowed' => env('POCKET_EXPENSE_FX_USER_OVERRIDE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination Settings
    |--------------------------------------------------------------------------
    |
    | Default pagination settings for expense listings.
    |
    */
    'pagination' => [
        'default_per_page' => env('POCKET_EXPENSE_DEFAULT_PER_PAGE', 15),
        'max_per_page' => env('POCKET_EXPENSE_MAX_PER_PAGE', 100),
        'allowed_per_page' => env('POCKET_EXPENSE_ALLOWED_PER_PAGE', '10,15,25,50,100'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Caching configuration for performance optimization.
    |
    */
    'cache' => [
        'enabled' => env('POCKET_EXPENSE_CACHE_ENABLED', true),
        'ttl' => [
            'expense_types' => env('POCKET_EXPENSE_CACHE_TYPES_TTL', 3600), // 1 hour
            'expense_sources' => env('POCKET_EXPENSE_CACHE_SOURCES_TTL', 1800), // 30 minutes
            'currencies' => env('POCKET_EXPENSE_CACHE_CURRENCIES_TTL', 7200), // 2 hours
            'countries' => env('POCKET_EXPENSE_CACHE_COUNTRIES_TTL', 86400), // 24 hours
            'user_permissions' => env('POCKET_EXPENSE_CACHE_PERMISSIONS_TTL', 900), // 15 minutes
        ],
        'tags' => [
            'expense_types' => 'pocket_expense_types',
            'expense_sources' => 'pocket_expense_sources',
            'currencies' => 'pocket_expense_currencies',
            'countries' => 'pocket_expense_countries',
            'permissions' => 'pocket_expense_permissions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    |
    | Background job processing configuration.
    |
    */
    'queue' => [
        'enabled' => env('POCKET_EXPENSE_QUEUE_ENABLED', true),
        'connection' => env('POCKET_EXPENSE_QUEUE_CONNECTION', 'redis'),
        'csv_processing_queue' => env('POCKET_EXPENSE_CSV_QUEUE', 'pocket-expense-csv'),
        'notification_queue' => env('POCKET_EXPENSE_NOTIFICATION_QUEUE', 'notifications'),
        'retry_times' => env('POCKET_EXPENSE_QUEUE_RETRY_TIMES', 3),
        'timeout' => env('POCKET_EXPENSE_QUEUE_TIMEOUT', 300), // 5 minutes
        'batch_size' => env('POCKET_EXPENSE_PROCESSING_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for expense-related notifications.
    |
    */
    'notifications' => [
        'enabled' => env('POCKET_EXPENSE_NOTIFICATIONS_ENABLED', true),
        'channels' => [