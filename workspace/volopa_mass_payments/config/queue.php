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
            'table' => env('QUEUE_TABLE', 'jobs'),
            'queue' => env('QUEUE_NAME', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
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
            'endpoint' => env('AWS_SQS_ENDPOINT'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        // High priority queue for urgent mass payment operations
        'mass_payments_high' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_HIGH_PRIORITY', 'mass_payments_high'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 120),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Standard priority queue for normal mass payment operations
        'mass_payments_standard' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_STANDARD', 'mass_payments_standard'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 300),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Low priority queue for background mass payment operations
        'mass_payments_low' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_LOW_PRIORITY', 'mass_payments_low'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 600),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Dedicated queue for file validation operations
        'file_validation' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_VALIDATION', 'file_validation'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 180),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Queue for payment processing operations
        'payment_processing' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_PROCESSING', 'payment_processing'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 600),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Queue for notification operations
        'notifications' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_NOTIFICATIONS', 'notifications'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 60),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Dead letter queue for failed jobs that need investigation
        'failed_jobs' => [
            'driver' => 'redis',
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_FAILED', 'failed_jobs'),
            'retry_after' => (int) env('QUEUE_RETRY_AFTER', 86400), // 24 hours
            'block_for' => null,
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
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the queue worker settings for mass payment
    | processing. These settings control timeouts, memory limits, and
    | retry attempts for different types of jobs.
    |
    */

    'workers' => [
        
        // Configuration for mass payment file validation workers
        'file_validation' => [
            'timeout' => (int) env('QUEUE_WORKER_TIMEOUT_VALIDATION', 300), // 5 minutes
            'memory' => (int) env('QUEUE_WORKER_MEMORY_VALIDATION', 512), // 512MB
            'tries' => (int) env('QUEUE_WORKER_TRIES_VALIDATION', 3),
            'backoff' => [
                (int) env('QUEUE_WORKER_BACKOFF_1', 60),   // 1 minute
                (int) env('QUEUE_WORKER_BACKOFF_2', 300),  // 5 minutes
                (int) env('QUEUE_WORKER_BACKOFF_3', 900),  // 15 minutes
            ],
            'max_jobs' => (int) env('QUEUE_WORKER_MAX_JOBS_VALIDATION', 100),
            'max_time' => (int) env('QUEUE_WORKER_MAX_TIME_VALIDATION', 3600), // 1 hour
            'force' => (bool) env('QUEUE_WORKER_FORCE_VALIDATION', false),
            'stop_when_empty' => (bool) env('QUEUE_WORKER_STOP_WHEN_EMPTY', false),
        ],

        // Configuration for payment processing workers
        'payment_processing' => [
            'timeout' => (int) env('QUEUE_WORKER_TIMEOUT_PROCESSING', 600), // 10 minutes
            'memory' => (int) env('QUEUE_WORKER_MEMORY_PROCESSING', 1024), // 1GB
            'tries' => (int) env('QUEUE_WORKER_TRIES_PROCESSING', 5),
            'backoff' => [
                (int) env('QUEUE_WORKER_BACKOFF_1', 120),   // 2 minutes
                (int) env('QUEUE_WORKER_BACKOFF_2', 600),   // 10 minutes
                (int) env('QUEUE_WORKER_BACKOFF_3', 1800),  // 30 minutes
                (int) env('QUEUE_WORKER_BACKOFF_4', 3600),  // 1 hour
                (int) env('QUEUE_WORKER_BACKOFF_5', 7200),  // 2 hours
            ],
            'max_jobs' => (int) env('QUEUE_WORKER_MAX_JOBS_PROCESSING', 50),
            'max_time' => (int) env('QUEUE_WORKER_MAX_TIME_PROCESSING', 7200), // 2 hours
            'force' => (bool) env('QUEUE_WORKER_FORCE_PROCESSING', false),
            'stop_when_empty' => (bool) env('QUEUE_WORKER_STOP_WHEN_EMPTY', false),
        ],

        // Configuration for notification workers
        'notifications' => [
            'timeout' => (int) env('QUEUE_WORKER_TIMEOUT_NOTIFICATIONS', 60), // 1 minute
            'memory' => (int) env('QUEUE_WORKER_MEMORY_NOTIFICATIONS', 128), // 128MB
            'tries' => (int) env('QUEUE_WORKER_TRIES_NOTIFICATIONS', 3),
            'backoff' => [
                (int) env('QUEUE_WORKER_BACKOFF_1', 30),   // 30 seconds
                (int) env('QUEUE_WORKER_BACKOFF_2', 180),  // 3 minutes
                (int) env('QUEUE_WORKER_BACKOFF_3', 600),  // 10 minutes
            ],
            'max_jobs' => (int) env('QUEUE_WORKER_MAX_JOBS_NOTIFICATIONS', 500),
            'max_time' => (int) env('QUEUE_WORKER_MAX_TIME_NOTIFICATIONS', 1800), // 30 minutes
            'force' => (bool) env('QUEUE_WORKER_FORCE_NOTIFICATIONS', false),
            'stop_when_empty' => (bool) env('QUEUE_WORKER_STOP_WHEN_EMPTY', false),
        ],

        // Default worker configuration
        'default' => [
            'timeout' => (int) env('QUEUE_WORKER_TIMEOUT_DEFAULT', 60),
            'memory' => (int) env('QUEUE_WORKER_MEMORY_DEFAULT', 128),
            'tries' => (int) env('QUEUE_WORKER_TRIES_DEFAULT', 3),
            'backoff' => [
                (int) env('QUEUE_WORKER_BACKOFF_1', 60),
                (int) env('QUEUE_WORKER_BACKOFF_2', 300),
                (int) env('QUEUE_WORKER_BACKOFF_3', 900),
            ],
            'max_jobs' => (int) env('QUEUE_WORKER_MAX_JOBS_DEFAULT', 1000),
            'max_time' => (int) env('QUEUE_WORKER_MAX_TIME_DEFAULT', 3600),
            'force' => (bool) env('QUEUE_WORKER_FORCE_DEFAULT', false),
            'stop_when_empty' => (bool) env('QUEUE_WORKER_STOP_WHEN_EMPTY', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Priorities
    |--------------------------------------------------------------------------
    |
    | Define the priority levels for different types of jobs. Higher numbers
    | indicate higher priority. These priorities are used by job dispatchers
    | to determine which queue to use for specific job types.
    |
    */

    'priorities' => [
        'urgent' => [
            'queue' => 'mass_payments_high',
            'priority' => 100,
            'delay' => 0,
        ],
        'high' => [
            'queue' => 'mass_payments_high',
            'priority' => 80,
            'delay' => 0,
        ],
        'normal' => [
            'queue' => 'mass_payments_standard',
            'priority' => 50,
            'delay' => 0,
        ],
        'low' => [
            'queue' => 'mass_payments_low',
            'priority' => 20,
            'delay' => (int) env('QUEUE_LOW_PRIORITY_DELAY', 300), // 5 minutes
        ],
        'background' => [
            'queue' => 'mass_payments_low',
            'priority' => 10,
            'delay' => (int) env('QUEUE_BACKGROUND_DELAY', 900), // 15 minutes
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for different types of jobs to prevent
    | overwhelming external services and to ensure fair resource usage
    | across different clients and operations.
    |
    */

    'rate_limits' => [
        
        // File validation rate limits
        'file_validation' => [
            'per_client_per_minute' => (int) env('QUEUE_RATE_LIMIT_VALIDATION_CLIENT', 10),
            'per_client_per_hour' => (int) env('QUEUE_RATE_LIMIT_VALIDATION_CLIENT_HOUR', 100),
            'global_per_minute' => (int) env('QUEUE_RATE_LIMIT_VALIDATION_GLOBAL', 50),
            'global_per_hour' => (int) env('QUEUE_RATE_LIMIT_VALIDATION_GLOBAL_HOUR', 1000),
        ],

        // Payment processing rate limits
        'payment_processing' => [
            'per_client_per_minute' => (int) env('QUEUE_RATE_LIMIT_PROCESSING_CLIENT', 5),
            'per_client_per_hour' => (int) env('QUEUE_RATE_LIMIT_PROCESSING_CLIENT_HOUR', 50),
            'global_per_minute' => (int) env('QUEUE_RATE_LIMIT_PROCESSING_GLOBAL', 25),
            'global_per_hour' => (int) env('QUEUE_RATE_LIMIT_PROCESSING_GLOBAL_HOUR', 500),
        ],

        // Notification rate limits
        'notifications' => [
            'per_client_per_minute' => (int) env('QUEUE_RATE_LIMIT_NOTIFICATIONS_CLIENT', 100),
            'per_client_per_hour' => (int) env('QUEUE_RATE_LIMIT_NOTIFICATIONS_CLIENT_HOUR', 1000),
            'global_per_minute' => (int) env('QUEUE_RATE_LIMIT_NOTIFICATIONS_GLOBAL', 500),
            'global_per_hour' => (int) env('