<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\TemplateController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Default health check route
Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Volopa Mass Payments API',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
    ]);
})->name('api.health');

// API Version 1 routes
Route::prefix('v1')->name('api.v1.')->group(function () {
    
    // Authentication middleware applied to all v1 routes via controller constructors
    // Individual rate limiting applied per controller
    
    /*
    |--------------------------------------------------------------------------
    | Payment File Management Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('files')->name('files.')->group(function () {
        // List payment files with filtering and pagination
        Route::get('/', [FileController::class, 'index'])
            ->name('index')
            ->middleware('throttle:api,60');
        
        // Upload new CSV payment file
        Route::post('/', [FileController::class, 'upload'])
            ->name('upload')
            ->middleware('throttle:uploads,10');
        
        // Get specific payment file details
        Route::get('{id}', [FileController::class, 'show'])
            ->name('show')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
        
        // Delete payment file
        Route::delete('{id}', [FileController::class, 'destroy'])
            ->name('destroy')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
        
        // Get file processing statistics
        Route::get('{id}/statistics', [FileController::class, 'statistics'])
            ->name('statistics')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
    });

    /*
    |--------------------------------------------------------------------------
    | Approval Workflow Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('approvals')->name('approvals.')->group(function () {
        // List pending approvals for authenticated user
        Route::get('/', [ApprovalController::class, 'index'])
            ->name('index')
            ->middleware('throttle:api,60');
        
        // Get specific approval details
        Route::get('{id}', [ApprovalController::class, 'show'])
            ->name('show')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
        
        // Approve payment file
        Route::post('{id}/approve', [ApprovalController::class, 'approve'])
            ->name('approve')
            ->whereNumber('id')
            ->middleware('throttle:approvals,20');
        
        // Reject payment file
        Route::post('{id}/reject', [ApprovalController::class, 'reject'])
            ->name('reject')
            ->whereNumber('id')
            ->middleware('throttle:approvals,20');
        
        // Get approval statistics for user
        Route::get('statistics', [ApprovalController::class, 'statistics'])
            ->name('statistics')
            ->middleware('throttle:api,60');
    });

    /*
    |--------------------------------------------------------------------------
    | Payment Processing Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('payments')->name('payments.')->group(function () {
        // List payment instructions with filtering
        Route::get('/', [PaymentController::class, 'index'])
            ->name('index')
            ->middleware('throttle:api,60');
        
        // Get specific payment instruction details
        Route::get('{id}', [PaymentController::class, 'show'])
            ->name('show')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
        
        // Process approved payments for a file
        Route::post('{fileId}/process', [PaymentController::class, 'process'])
            ->name('process')
            ->whereNumber('fileId')
            ->middleware('throttle:payment-processing,10');
        
        // Get payment processing statistics for a file
        Route::get('{fileId}/statistics', [PaymentController::class, 'statistics'])
            ->name('statistics')
            ->whereNumber('fileId')
            ->middleware('throttle:api,60');
        
        // Retry failed payments for a file
        Route::post('{fileId}/retry', [PaymentController::class, 'retry'])
            ->name('retry')
            ->whereNumber('fileId')
            ->middleware('throttle:payment-processing,5');
        
        // Cancel specific payment instruction
        Route::post('{id}/cancel', [PaymentController::class, 'cancel'])
            ->name('cancel')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
        
        // Track payment instruction status
        Route::get('{id}/track', [PaymentController::class, 'track'])
            ->name('track')
            ->whereNumber('id')
            ->middleware('throttle:api,60');
    });

    /*
    |--------------------------------------------------------------------------
    | CSV Template Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('templates')->name('templates.')->group(function () {
        // Download CSV template file
        Route::get('csv', [TemplateController::class, 'downloadCsv'])
            ->name('csv')
            ->middleware('throttle:api,60');
        
        // Get template metadata and specifications
        Route::get('metadata', [TemplateController::class, 'getMetadata'])
            ->name('metadata')
            ->middleware('throttle:api,60');
        
        // Get settlement methods for currency
        Route::get('settlement-methods', [TemplateController::class, 'getSettlementMethods'])
            ->name('settlement-methods')
            ->middleware('throttle:api,60');
        
        // Validate CSV row format
        Route::post('validate-row', [TemplateController::class, 'validateRow'])
            ->name('validate-row')
            ->middleware('throttle:api,60');
    });

    /*
    |--------------------------------------------------------------------------
    | System Status and Health Check Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('system')->name('system.')->group(function () {
        // API health check with detailed status
        Route::get('health', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'API is healthy',
                'version' => '1.0.0',
                'timestamp' => now()->toISOString(),
                'environment' => app()->environment(),
                'status' => [
                    'database' => 'connected',
                    'cache' => 'operational',
                    'queue' => 'operational',
                    'storage' => 'available',
                ],
                'uptime' => now()->diffInSeconds(LARAVEL_START ?? now()),
            ]);
        })->name('health')->middleware('throttle:api,60');
        
        // Get API documentation info
        Route::get('info', function (Request $request) {
            return response()->json([
                'success' => true,
                'api' => [
                    'name' => 'Volopa Mass Payments API',
                    'version' => '1.0.0',
                    'description' => 'RESTful API for processing mass payment files with CSV uploads, validation, approval workflows, and payment processing.',
                    'base_url' => url('/api/v1'),
                    'authentication' => [
                        'type' => 'Bearer Token',
                        'description' => 'Laravel Sanctum authentication required for all endpoints',
                    ],
                    'rate_limiting' => [
                        'default' => '60 requests per minute',
                        'uploads' => '10 requests per minute',
                        'approvals' => '20 requests per minute',
                        'payment_processing' => '10 requests per minute',
                    ],
                    'supported_formats' => ['JSON'],
                    'supported_currencies' => ['USD', 'EUR', 'GBP'],
                    'supported_settlement_methods' => ['SEPA', 'SWIFT', 'FASTER_PAYMENTS', 'ACH', 'WIRE'],
                    'max_file_size' => '10MB',
                    'max_records_per_file' => 10000,
                ],
                'endpoints' => [
                    'files' => [
                        'GET /api/v1/files' => 'List payment files',
                        'POST /api/v1/files' => 'Upload CSV payment file',
                        'GET /api/v1/files/{id}' => 'Get payment file details',
                        'DELETE /api/v1/files/{id}' => 'Delete payment file',
                        'GET /api/v1/files/{id}/statistics' => 'Get file statistics',
                    ],
                    'approvals' => [
                        'GET /api/v1/approvals' => 'List pending approvals',
                        'GET /api/v1/approvals/{id}' => 'Get approval details',
                        'POST /api/v1/approvals/{id}/approve' => 'Approve payment file',
                        'POST /api/v1/approvals/{id}/reject' => 'Reject payment file',
                        'GET /api/v1/approvals/statistics' => 'Get approval statistics',
                    ],
                    'payments' => [
                        'GET /api/v1/payments' => 'List payment instructions',
                        'GET /api/v1/payments/{id}' => 'Get payment details',
                        'POST /api/v1/payments/{fileId}/process' => 'Process payments',
                        'GET /api/v1/payments/{fileId}/statistics' => 'Get payment statistics',
                        'POST /api/v1/payments/{fileId}/retry' => 'Retry failed payments',
                        'POST /api/v1/payments/{id}/cancel' => 'Cancel payment',
                        'GET /api/v1/payments/{id}/track' => 'Track payment status',
                    ],
                    'templates' => [
                        'GET /api/v1/templates/csv' => 'Download CSV template',
                        'GET /api/v1/templates/metadata' => 'Get template specifications',
                        'GET /api/v1/templates/settlement-methods' => 'Get settlement methods',
                        'POST /api/v1/templates/validate-row' => 'Validate CSV row format',
                    ],
                ],
                'contact' => [
                    'support' => 'support@volopa.com',
                    'documentation' => url('/docs/api/v1'),
                ],
                'timestamp' => now()->toISOString(),
            ]);
        })->name('info')->middleware('throttle:api,60');
    });
});

/*
|--------------------------------------------------------------------------
| Fallback Routes
|--------------------------------------------------------------------------
*/

// Handle API version not specified
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found',
        'error' => 'The requested API endpoint does not exist',
        'available_versions' => [
            'v1' => url('/api/v1'),
        ],
        'documentation' => url('/docs/api'),
        'timestamp' => now()->toISOString(),
    ], 404);
})->name('api.fallback');

// Catch-all for invalid API versions
Route::prefix('{version}')->where('version', '^(?!v1$).*')->group(function () {
    Route::any('{any?}', function ($version) {
        return response()->json([
            'success' => false,
            'message' => 'Unsupported API version',
            'error' => "API version '{$version}' is not supported",
            'supported_versions' => ['v1'],
            'current_version' => 'v1',
            'upgrade_path' => url('/api/v1'),
            'timestamp' => now()->toISOString(),
        ], 404);
    })->where('any', '.*')->name('api.version.unsupported');
});

/*
|--------------------------------------------------------------------------
| Legacy Route Redirects (if needed)
|--------------------------------------------------------------------------
*/

// Redirect old API paths to new structure (if migrating from existing API)
Route::redirect('/upload', '/api/v1/files', 301);
Route::redirect('/files', '/api/v1/files', 301);
Route::redirect('/approvals', '/api/v1/approvals', 301);
Route::redirect('/payments', '/api/v1/payments', 301);
Route::redirect('/template', '/api/v1/templates/csv', 301);

/*
|--------------------------------------------------------------------------
| Rate Limit Configuration Notes
|--------------------------------------------------------------------------
|
| Throttle middleware configurations:
| - 'api' => 60 requests per minute (default for most endpoints)
| - 'uploads' => 10 requests per minute (file upload endpoints)
| - 'approvals' => 20 requests per minute (approval action endpoints)
| - 'payment-processing' => 10 requests per minute (payment processing)
|
| These can be configured in config/api.php or app/Http/Kernel.php
*/

/*
|--------------------------------------------------------------------------
| Authentication Notes
|--------------------------------------------------------------------------
|
| All API routes require authentication via Laravel Sanctum.
| Authentication middleware is applied in individual controllers.
| 
| Authorization is handled via Laravel Policies:
| - PaymentFilePolicy for file operations
| - Built-in authorization checks in ApprovalRequest and other form requests
|
| OAuth2 and WSSE authentication can be added via custom middleware
| as mentioned in the Volopa conventions.
*/

/*
|--------------------------------------------------------------------------
| CORS Configuration
|--------------------------------------------------------------------------
|
| CORS is handled by Laravel's built-in HandleCors middleware.
| Configuration should be set in config/cors.php to allow:
| - Specific origins for production
| - Appropriate headers for API requests
| - Credentials if using cookie-based authentication
*/