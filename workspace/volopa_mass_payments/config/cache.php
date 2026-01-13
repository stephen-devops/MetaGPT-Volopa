{
  "Development Plan": [
    "config/cache.php"
  ],
  "Incremental Change": [
    "config/cache.php: Cache configuration for reference data, session storage, and performance optimization with Redis stores, file fallbacks, and mass payment specific cache settings"
  ]
}

## Code: config/cache.php

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */

    'default' => env('CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "apc", "array", "database", "file",
    |                    "memcached", "redis", "dynamodb", "octane", "null"
    |
    */

    'stores' => [

        'apc' => [
            'driver' => 'apc',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'table' => env('CACHE_DATABASE_TABLE', 'cache'),
            'connection' => env('CACHE_DATABASE_CONNECTION', null),
            'lock_connection' => env('CACHE_DATABASE_LOCK_CONNECTION', null),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/locks'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        // Mass Payment specific cache stores
        'reference_data' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_REFERENCE', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_REFERENCE', 'volopa:ref'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'beneficiaries' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_BENEFICIARIES', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_BENEFICIARIES', 'volopa:ben'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'tcc_accounts' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_TCC', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_TCC', 'volopa:tcc'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'validation_rules' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_VALIDATION', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_VALIDATION', 'volopa:val'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'currency_rates' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_CURRENCY', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_CURRENCY', 'volopa:cur'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'bank_codes' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_BANKS', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_BANKS', 'volopa:bank'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'user_sessions' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_SESSIONS', 'sessions'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_SESSIONS', 'volopa:sess'),
            'serializer' => 'igbinary',
            'compression' => 'none',
        ],

        'file_processing' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_FILES', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_FILES', 'volopa:files'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

        'rate_limiting' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_RATE_LIMIT', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_RATE_LIMIT', 'volopa:rate'),
            'serializer' => 'none',
            'compression' => 'none',
        ],

        // Fallback file-based cache for when Redis is unavailable
        'file_fallback' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/fallback'),
            'lock_path' => storage_path('framework/cache/fallback/locks'),
        ],

        // High-performance cache for frequently accessed data
        'hot_cache' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_HOT', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_HOT', 'volopa:hot'),
            'serializer' => 'igbinary',
            'compression' => 'none', // No compression for speed
        ],

        // Cold storage cache for less frequently accessed data
        'cold_cache' => [
            'driver' => 'redis',
            'connection' => env('CACHE_REDIS_CONNECTION_COLD', 'cache'),
            'lock_connection' => env('CACHE_REDIS_LOCK_CONNECTION', 'default'),
            'prefix' => env('CACHE_PREFIX_COLD', 'volopa:cold'),
            'serializer' => 'igbinary',
            'compression' => 'lz4',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, or DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', 'volopa_mass_payments_laravel_cache_'),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Settings
    |--------------------------------------------------------------------------
    |
    | Define default TTL (Time To Live) values for different types of cached
    | data. These values are used when no explicit TTL is provided.
    |
    */

    'ttl' => [
        
        // Default TTL values in seconds
        'default' => (int) env('CACHE_TTL_DEFAULT', 3600), // 1 hour

        // Reference data TTL (changes infrequently)
        'reference_data' => [
            'countries' => (int) env('CACHE_TTL_COUNTRIES', 86400), // 24 hours
            'currencies' => (int) env('CACHE_TTL_CURRENCIES', 43200), // 12 hours
            'purpose_codes' => (int) env('CACHE_TTL_PURPOSE_CODES', 86400), // 24 hours
            'bank_codes' => (int) env('CACHE_TTL_BANK_CODES', 21600), // 6 hours
            'compliance_rules' => (int) env('CACHE_TTL_COMPLIANCE', 3600), // 1 hour
        ],

        // User and session related TTL
        'user_data' => [
            'permissions' => (int) env('CACHE_TTL_PERMISSIONS', 1800), // 30 minutes
            'roles' => (int) env('CACHE_TTL_ROLES', 3600), // 1 hour
            'client_settings' => (int) env('CACHE_TTL_CLIENT_SETTINGS', 7200), // 2 hours
            'user_preferences' => (int) env('CACHE_TTL_USER_PREFERENCES', 3600), // 1 hour
        ],

        // Financial data TTL
        'financial_data' => [
            'exchange_rates' => (int) env('CACHE_TTL_EXCHANGE_RATES', 300), // 5 minutes
            'account_balances' => (int) env('CACHE_TTL_ACCOUNT_BALANCES', 600), // 10 minutes
            'currency_limits' => (int) env('CACHE_TTL_CURRENCY_LIMITS', 7200), // 2 hours
            'approval_limits' => (int) env('CACHE_TTL_APPROVAL_LIMITS', 3600), // 1 hour
        ],

        // Validation and processing TTL
        'validation' => [
            'csv_headers' => (int) env('CACHE_TTL_CSV_HEADERS', 1800), // 30 minutes
            'validation_rules' => (int) env('CACHE_TTL_VALIDATION_RULES', 3600), // 1 hour
            'beneficiary_validation' => (int) env('CACHE_TTL_BENEFICIARY_VALIDATION', 900), // 15 minutes
            'duplicate_checks' => (int) env('CACHE_TTL_DUPLICATE_CHECKS', 300), // 5 minutes
        ],

        // File processing TTL
        'file_processing' => [
            'upload_status' => (int) env('CACHE_TTL_UPLOAD_STATUS', 1800), // 30 minutes
            'processing_progress' => (int) env('CACHE_TTL_PROCESSING_PROGRESS', 300), // 5 minutes
            'validation_results' => (int) env('CACHE_TTL_VALIDATION_RESULTS', 7200), // 2 hours
            'file_metadata' => (int) env('CACHE_TTL_FILE_METADATA', 3600), // 1 hour
        ],

        // Rate limiting TTL
        'rate_limiting' => [
            'api_requests' => (int) env('CACHE_TTL_API_RATE_LIMIT', 60), // 1 minute
            'file_uploads' => (int) env('CACHE_TTL_FILE_UPLOAD_RATE_LIMIT', 300), // 5 minutes
            'approval_attempts' => (int) env('CACHE_TTL_APPROVAL_RATE_LIMIT', 3600), // 1 hour
        ],

        // Temporary data TTL
        'temporary' => [
            'csrf_tokens' => (int) env('CACHE_TTL_CSRF_TOKENS', 7200), // 2 hours
            'nonces' => (int) env('CACHE_TTL_NONCES', 600), // 10 minutes
            'temp_files' => (int) env('CACHE_TTL_TEMP_FILES', 1800), // 30 minutes
            'session_data' => (int) env('CACHE_TTL_SESSION_DATA', 7200), // 2 hours
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Tags Configuration
    |--------------------------------------------------------------------------
    |
    | Configure cache tags for efficient cache invalidation. Tags allow
    | you to invalidate related cache entries together.
    |
    */

    'tags' => [
        
        // Enable cache tagging (requires Redis or Memcached)
        'enabled' => (bool) env('CACHE_TAGS_ENABLED', true),

        // Tag definitions for mass payment operations
        'definitions' => [
            'client' => 'client:{client_id}',
            'user' => 'user:{user_id}',
            'tcc_account' => 'tcc_account:{tcc_account_id}',
            'beneficiary' => 'beneficiary:{beneficiary_id}',
            'mass_payment_file' => 'mass_payment_file:{file_id}',
            'payment_instruction' => 'payment_instruction:{instruction_id}',
            'currency' => 'currency:{currency_code}',
            'bank' => 'bank:{bank_code}',
            'country' => 'country:{country_code}',
            'validation_rule' => 'validation:{rule_type}',
            'approval_level' => 'approval:{level}',
            'processing_status' => 'processing:{status}',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configure performance-related cache settings for optimal operation
    | in the mass payment processing environment.
    |
    */

    'performance' => [
        
        // Enable cache compression to save memory (requires igbinary extension)
        'compression' => [
            'enabled' => (bool) env('CACHE_COMPRESSION_ENABLED', true),
            'algorithm' => env('CACHE_COMPRESSION_ALGORITHM', 'lz4'), // lz4, gzip, snappy
            'threshold' => (int) env('CACHE_COMPRESSION_THRESHOLD', 1024), // Compress items larger than 1KB
        ],

        // Serialization settings
        'serialization' => [