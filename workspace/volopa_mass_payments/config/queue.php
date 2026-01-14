## Code: config/queue.php

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'volopa_mass_payments'),
            'retry_after' => 300,
            'block_for' => null,
            'after_commit' => false,
        ],

        // Mass Payment specific Redis queue for high throughput
        'mass_payments' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => env('MASS_PAYMENT_QUEUE', 'mass_payments'),
            'retry_after' => 600, // 10 minutes for CSV processing
            'block_for' => 5,
            'after_commit' => false,
        ],

        // High priority queue for critical operations
        'high_priority' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => env('HIGH_PRIORITY_QUEUE', 'high_priority'),
            'retry_after' => 180, // 3 minutes
            'block_for' => 1,
            'after_commit' => false,
        ],

        // Low priority queue for background tasks
        'low_priority' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => env('LOW_PRIORITY_QUEUE', 'low_priority'),
            'retry_after' => 900, // 15 minutes
            'block_for' => 10,
            'after_commit' => false,
        ],

        // Validation queue for CSV processing
        'validation' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => env('VALIDATION_QUEUE', 'validation'),
            'retry_after' => 600, // 10 minutes for large files
            'block_for' => 5,
            'after_commit' => false,
        ],

        // Processing queue for payment execution
        'processing' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => env('PROCESSING_QUEUE', 'processing'),
            'retry_after' => 1800, // 30 minutes for payment processing
            'block_for' => 5,
            'after_commit' => false,
        ],

        // Notifications queue for email/webhook delivery
        'notifications' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),
            'retry_after' => 300, // 5 minutes
            'block_for' => 2,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mass Payment Queue Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control the behavior of mass payment processing queues.
    | Configure timeouts, retry attempts, and job priorities based on your
    | infrastructure requirements and processing volumes.
    |
    */

    'mass_payments' => [
        
        // Default queue settings for mass payment jobs
        'default_queue' => env('MASS_PAYMENT_DEFAULT_QUEUE', 'mass_payments'),
        
        // Maximum retry attempts for different job types
        'max_retries' => [
            'validation' => (int) env('MASS_PAYMENT_VALIDATION_RETRIES', 3),
            'processing' => (int) env('MASS_PAYMENT_PROCESSING_RETRIES', 5),
            'notification' => (int) env('MASS_PAYMENT_NOTIFICATION_RETRIES', 3),
        ],

        // Timeout settings in seconds
        'timeouts' => [
            'csv_validation' => (int) env('CSV_VALIDATION_TIMEOUT', 600), // 10 minutes
            'payment_processing' => (int) env('PAYMENT_PROCESSING_TIMEOUT', 1800), // 30 minutes
            'batch_processing' => (int) env('BATCH_PROCESSING_TIMEOUT', 3600), // 1 hour
            'notification_sending' => (int) env('NOTIFICATION_TIMEOUT', 300), // 5 minutes
        ],

        // Memory limits for different operations (in MB)
        'memory_limits' => [
            'csv_parsing' => (int) env('CSV_PARSING_MEMORY_LIMIT', 512),
            'validation' => (int) env('VALIDATION_MEMORY_LIMIT', 256),
            'processing' => (int) env('PROCESSING_MEMORY_LIMIT', 128),
            'notifications' => (int) env('NOTIFICATIONS_MEMORY_LIMIT', 64),
        ],

        // Batch processing configuration
        'batch_config' => [
            'chunk_size' => (int) env('MASS_PAYMENT_CHUNK_SIZE', 100), // Process in chunks of 100
            'max_concurrent_jobs' => (int) env('MAX_CONCURRENT_JOBS', 10),
            'progress_update_interval' => (int) env('PROGRESS_UPDATE_INTERVAL', 50), // Update every 50 records
        ],

        // Queue priorities (higher number = higher priority)
        'priorities' => [
            'urgent' => 10,
            'high' => 7,
            'normal' => 5,
            'low' => 1,
        ],

        // File processing limits
        'file_limits' => [
            'max_file_size_mb' => (int) env('MAX_MASS_PAYMENT_FILE_SIZE', 50),
            'max_rows' => (int) env('MAX_MASS_PAYMENT_ROWS', 10000),
            'processing_chunk_size' => (int) env('FILE_PROCESSING_CHUNK_SIZE', 1000),
        ],

        // Currency-specific processing settings
        'currency_settings' => [
            'high_value_currencies' => ['USD', 'EUR', 'GBP'],
            'regulated_currencies' => ['INR', 'CNY', 'TRY'],
            'high_value_threshold' => (float) env('HIGH_VALUE_THRESHOLD', 100000.00),
            'regulated_processing_delay' => (int) env('REGULATED_PROCESSING_DELAY', 300), // 5 minutes
        ],

        // Monitoring and alerting thresholds
        'monitoring' => [
            'max_queue_size' => (int) env('MAX_QUEUE_SIZE_ALERT', 1000),
            'max_processing_time' => (int) env('MAX_PROCESSING_TIME_ALERT', 3600), // 1 hour
            'failed_job_threshold' => (int) env('FAILED_JOB_THRESHOLD', 10),
            'memory_usage_threshold' => (int) env('MEMORY_USAGE_THRESHOLD', 80), // 80%
        ],

        // Cleanup settings
        'cleanup' => [
            'completed_jobs_retention_days' => (int) env('COMPLETED_JOBS_RETENTION_DAYS', 30),
            'failed_jobs_retention_days' => (int) env('FAILED_JOBS_RETENTION_DAYS', 90),
            'temp_files_retention_hours' => (int) env('TEMP_FILES_RETENTION_HOURS', 24),
        ],

        // Redis-specific settings for mass payments
        'redis' => [
            'database' => (int) env('REDIS_QUEUE_DATABASE', 2),
            'prefix' => env('REDIS_QUEUE_PREFIX', 'volopa:queue:'),
            'serializer' => env('REDIS_QUEUE_SERIALIZER', 'php'), // php, igbinary, json
            'compression' => env('REDIS_QUEUE_COMPRESSION', false),
            'connection_pool_size' => (int) env('REDIS_CONNECTION_POOL_SIZE', 5),
        ],

        // Job scheduling settings
        'scheduling' => [
            'enable_scheduled_processing' => (bool) env('ENABLE_SCHEDULED_PROCESSING', true),
            'business_hours_start' => env('BUSINESS_HOURS_START', '09:00'),
            'business_hours_end' => env('BUSINESS_HOURS_END', '17:00'),
            'business_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'timezone' => env('BUSINESS_TIMEZONE', 'UTC'),
        ],

        // Webhook and notification settings
        'notifications' => [
            'webhook_timeout' => (int) env('WEBHOOK_TIMEOUT', 30),
            'webhook_retries' => (int) env('WEBHOOK_RETRIES', 3),
            'email_batch_size' => (int) env('EMAIL_BATCH_SIZE', 50),
            'notification_delay' => (int) env('NOTIFICATION_DELAY', 0), // seconds
        ],

        // Security and compliance settings
        'security' => [
            'encrypt_job_data' => (bool) env('ENCRYPT_JOB_DATA', true),
            'log_sensitive_data' => (bool) env('LOG_SENSITIVE_DATA', false),
            'audit_trail_enabled' => (bool) env('AUDIT_TRAIL_ENABLED', true),
            'data_retention_days' => (int) env('QUEUE_DATA_RETENTION_DAYS', 365),
        ],

        // Performance optimization settings
        'optimization' => [
            'enable_job_compression' => (bool) env('ENABLE_JOB_COMPRESSION', false),
            'cache_validation_results' => (bool) env('CACHE_VALIDATION_RESULTS', true),
            'cache_ttl_minutes' => (int) env('VALIDATION_CACHE_TTL', 60),
            'parallel_processing' => (bool) env('PARALLEL_PROCESSING', true),
            'max_parallel_workers' => (int) env('MAX_PARALLEL_WORKERS', 4),
        ],

        // Error handling and recovery
        'error_handling' => [
            'auto_retry_on_failure' => (bool) env('AUTO_RETRY_ON_FAILURE', true),
            'exponential_backoff' => (bool) env('EXPONENTIAL_BACKOFF', true),
            'max_backoff_seconds' => (int) env('MAX_BACKOFF_SECONDS', 600),
            'circuit_breaker_enabled' => (bool) env('CIRCUIT_BREAKER_ENABLED', true),
            'circuit_breaker_threshold' => (int) env('CIRCUIT_BREAKER_THRESHOLD', 5),
        ],

        // Development and testing settings
        'development' => [
            'fake_processing_delay' => (int) env('FAKE_PROCESSING_DELAY', 0),
            'enable_debug_logging' => (bool) env('ENABLE_QUEUE_DEBUG_LOGGING', false),
            'test_mode' => (bool) env('QUEUE_TEST_MODE', false),
            'disable_external_calls' => (bool) env('DISABLE_EXTERNAL_CALLS', false),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Workers Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for queue workers processing mass payment jobs.
    | These settings control worker behavior, resource allocation, and
    | process management for optimal performance.
    |
    */

    'workers' => [
        
        // Default worker settings
        'default' => [
            'connection' => 'redis',
            'queue' => 'default',
            'timeout' => 60,
            'memory' => 128,
            'tries' => 3,
            'delay' => 0,
            'sleep' => 3,
            'max_jobs' => 1000,
            'max_time' => 3600,
            'force' => false,
            'stop_when_empty' => false,
        ],

        // Mass payment validation worker
        'mass_payment_validation' => [
            'connection' => 'redis',
            'queue' => 'validation',
            'timeout' => 600, // 10 minutes
            'memory' => 512, // 512MB
            'tries' => 3,
            'delay' => 0,
            'sleep' => 5,
            'max_jobs' => 100, // Process fewer jobs before restart
            'max_time' => 7200, // 2 hours before restart
            'force' => false,
            'stop_when_empty' => false,
        ],

        // Mass payment processing worker
        'mass_payment_processing' => [
            'connection' => 'redis',
            'queue' => 'processing',
            'timeout' => 1800, // 30 minutes
            'memory' => 256, // 256MB
            'tries' => 5,
            'delay' => 10, // 10 second delay between retries
            'sleep' => 3,
            'max_jobs' => 500,
            'max_time' => 3600, // 1 hour before restart
            'force' => false,
            'stop_when_empty' => false,
        ],

        // High priority worker for urgent tasks
        'high_priority' => [
            'connection' => 'redis',
            'queue' => 