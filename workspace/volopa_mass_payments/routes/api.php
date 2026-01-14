Looking at the context and requirements, I need to implement the `routes/api.php` file that defines all the API routes for the mass payment system. Based on the design patterns and controllers provided, this file should define versioned routes with proper middleware for authentication, rate limiting, and authorization.

## Code: routes/api.php

```php
<?php

use App\Http\Controllers\Api\V1\MassPaymentFileController;
use App\Http\Controllers\Api\V1\PaymentInstructionController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Middleware\VolopaAuthMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

/*
|--------------------------------------------------------------------------
| API Version 1 Routes - Mass Payment System
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1 and include authentication,
| rate limiting, and CORS middleware for the mass payment system.
|
*/

// API Version 1 Routes with authentication and rate limiting
Route::prefix('v1')->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Public Routes (No Authentication Required)
    |--------------------------------------------------------------------------
    */
    
    // API Health Check and Status
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'service' => 'volopa-mass-payments-api',
            'version' => '1.0.0',
            'environment' => config('app.env'),
        ]);
    })->name('api.v1.health');

    // API Documentation and OpenAPI Spec
    Route::get('docs', function () {
        return response()->json([
            'message' => 'API Documentation',
            'documentation_url' => config('app.docs_url', 'https://docs.volopa.com/mass-payments'),
            'openapi_spec' => route('api.v1.openapi'),
            'supported_versions' => ['v1'],
            'current_version' => 'v1',
        ]);
    })->name('api.v1.docs');

    // OpenAPI Specification
    Route::get('openapi', function () {
        return response()->json([
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Volopa Mass Payments API',
                'version' => '1.0.0',
                'description' => 'RESTful API for bulk payment processing',
                'contact' => [
                    'name' => 'Volopa API Support',
                    'url' => 'https://support.volopa.com',
                    'email' => 'api-support@volopa.com',
                ],
            ],
            'servers' => [
                [
                    'url' => config('app.url') . '/api/v1',
                    'description' => 'Production API Server',
                ],
            ],
            'paths' => [
                '/mass-payment-files' => [
                    'get' => ['summary' => 'List mass payment files'],
                    'post' => ['summary' => 'Upload mass payment file'],
                ],
                '/mass-payment-files/{id}' => [
                    'get' => ['summary' => 'Get mass payment file details'],
                    'delete' => ['summary' => 'Delete mass payment file'],
                ],
                '/mass-payment-files/{id}/approve' => [
                    'post' => ['summary' => 'Approve mass payment file'],
                ],
                '/payment-instructions' => [
                    'get' => ['summary' => 'List payment instructions'],
                ],
                '/payment-instructions/{id}' => [
                    'get' => ['summary' => 'Get payment instruction details'],
                ],
                '/templates/download' => [
                    'get' => ['summary' => 'Download CSV template'],
                ],
            ],
        ]);
    })->name('api.v1.openapi');

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes (Require Authentication)
    |--------------------------------------------------------------------------
    */
    
    // Apply authentication middleware to all protected routes
    Route::middleware([
        'auth:api',
        VolopaAuthMiddleware::class . ':oauth2',
        'throttle:api'
    ])->group(function () {
        
        /*
        |--------------------------------------------------------------------------
        | Mass Payment File Routes
        |--------------------------------------------------------------------------
        */
        
        Route::prefix('mass-payment-files')->name('mass-payment-files.')->group(function () {
            
            // List mass payment files with filtering and pagination
            Route::get('/', [MassPaymentFileController::class, 'index'])
                ->middleware('throttle:120,1')
                ->name('index');
            
            // Upload new mass payment file
            Route::post('/', [MassPaymentFileController::class, 'store'])
                ->middleware([
                    'throttle:10,1', // Stricter rate limit for uploads
                    'can:create,App\Models\MassPaymentFile'
                ])
                ->name('store');
            
            // Get specific mass payment file details
            Route::get('{id}', [MassPaymentFileController::class, 'show'])
                ->middleware('throttle:120,1')
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('show');
            
            // Approve or reject mass payment file
            Route::post('{id}/approve', [MassPaymentFileController::class, 'approve'])
                ->middleware([
                    'throttle:20,1',
                    'can:approve,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('approve');
            
            // Delete mass payment file
            Route::delete('{id}', [MassPaymentFileController::class, 'destroy'])
                ->middleware([
                    'throttle:30,1',
                    'can:delete,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('destroy');
            
            // Reprocess failed mass payment file
            Route::post('{id}/reprocess', [MassPaymentFileController::class, 'reprocess'])
                ->middleware([
                    'throttle:5,1',
                    'can:update,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('reprocess');
            
            // Cancel processing mass payment file
            Route::post('{id}/cancel', [MassPaymentFileController::class, 'cancel'])
                ->middleware([
                    'throttle:10,1',
                    'can:update,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('cancel');
            
            // Get mass payment file status summary
            Route::get('{id}/status', [MassPaymentFileController::class, 'status'])
                ->middleware('throttle:180,1')
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('status');
            
            // Export mass payment file data
            Route::get('{id}/export', [MassPaymentFileController::class, 'export'])
                ->middleware([
                    'throttle:10,1',
                    'can:export,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('export');
        });

        /*
        |--------------------------------------------------------------------------
        | Payment Instruction Routes
        |--------------------------------------------------------------------------
        */
        
        Route::prefix('payment-instructions')->name('payment-instructions.')->group(function () {
            
            // List payment instructions with filtering and pagination
            Route::get('/', [PaymentInstructionController::class, 'index'])
                ->middleware('throttle:120,1')
                ->name('index');
            
            // Get specific payment instruction details
            Route::get('{id}', [PaymentInstructionController::class, 'show'])
                ->middleware('throttle:120,1')
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('show');
            
            // Retry failed payment instruction
            Route::post('{id}/retry', [PaymentInstructionController::class, 'retry'])
                ->middleware([
                    'throttle:10,1',
                    'can:update,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('retry');
            
            // Cancel pending payment instruction
            Route::post('{id}/cancel', [PaymentInstructionController::class, 'cancel'])
                ->middleware([
                    'throttle:20,1',
                    'can:update,id'
                ])
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('cancel');
            
            // Get payment instruction status
            Route::get('{id}/status', [PaymentInstructionController::class, 'status'])
                ->middleware('throttle:180,1')
                ->where('id', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
                ->name('status');
            
            // Get payment instructions status summary
            Route::get('status/summary', [PaymentInstructionController::class, 'statusSummary'])
                ->middleware('throttle:60,1')
                ->name('status.summary');
            
            // Export payment instructions data
            Route::get('export', [PaymentInstructionController::class, 'export'])
                ->middleware([
                    'throttle:10,1',
                    'can:export,App\Models\PaymentInstruction'
                ])
                ->name('export');
            
            // Bulk operations on payment instructions
            Route::prefix('bulk')->name('bulk.')->group(function () {
                
                // Bulk retry failed payment instructions
                Route::post('retry', [PaymentInstructionController::class, 'bulkRetry'])
                    ->middleware([
                        'throttle:5,1',
                        'can:update,App\Models\PaymentInstruction'
                    ])
                    ->name('retry');
                
                // Bulk cancel payment instructions
                Route::post('cancel', [PaymentInstructionController::class, 'bulkCancel'])
                    ->middleware([
                        'throttle:5,1',
                        'can:update,App\Models\PaymentInstruction'
                    ])
                    ->name('cancel');
            });
        });

        /*
        |--------------------------------------------------------------------------
        | Template Routes
        |--------------------------------------------------------------------------
        */
        
        Route::prefix('templates')->name('templates.')->group(function () {
            
            // Download CSV template for mass payments
            Route::get('download', [TemplateController::class, 'download'])
                ->middleware([
                    'throttle:50,60', // 50 downloads per hour
                    'can:downloadTemplate,App\Models\MassPaymentFile'
                ])
                ->name('download');
            
            // Preview template structure without downloading
            Route::get('preview', [TemplateController::class, 'preview'])
                ->middleware('throttle:120,1')
                ->name('preview');
            
            // Get template headers for a specific currency
            Route::get('headers', [TemplateController::class, 'headers'])
                ->middleware('throttle:120,1')
                ->name('headers');
            
            // Get supported currencies and requirements
            Route::get('currencies', [TemplateController::class, 'currencies'])
                ->middleware('throttle:120,1')
                ->name('currencies');
            
            // Get validation rules for a currency
            Route::get('validation-rules', [TemplateController::class, 'validationRules'])
                ->middleware('throttle:120,1')
                ->name('validation-rules');
            
            // Get field examples for template
            Route::get('examples', [TemplateController::class, 'examples'])
                ->middleware('throttle:120,1')
                ->name('examples');
        });

        /*
        |--------------------------------------------------------------------------
        | Administrative and Utility Routes
        |--------------------------------------------------------------------------
        */
        
        // User profile and permissions
        Route::get('profile', function (Request $request) {
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'client_id' => $request->user()->client_id,
                        'roles' => $request->user()->roles ?? [],
                        'permissions' => $request->user()->permissions ?? [],
                    ],
                    'capabilities' => [
                        'can_create_mass_payments' => $request->user()->can('create', \App\Models\MassPaymentFile::class),
                        'can_approve_mass_payments' => $request->user()->can('approve', \App\Models\MassPaymentFile::class),
                        'can_download_templates' => $request->user()->can('downloadTemplate', \App\Models\MassPaymentFile::class),
                        'can_export_data' => $request->user()->can('export', \App\Models\MassPaymentFile::class),
                    ],
                    'limits' => [
                        'max_file_size_mb' => config('queue.mass_payments.file_limits.max_file_size_mb', 50),
                        'max_instructions' => config('queue.mass_payments.file_limits.max_rows', 10000),
                        'allowed_currencies' => $request->user()->allowed_currencies ?? [],
                    ],
                ],
                'meta' => [
                    'api_version' => 'v1',
                    'timestamp' => now()->toISOString(),
                ],
            ]);
        })->middleware('throttle:60,1')->name('profile');

        // System status and statistics
        Route::get('stats', function (Request $request) {
            // Basic statistics available to authenticated users
            $clientId = $request->user()->client_id;
            
            $stats = \Illuminate\Support\Facades\Cache::remember(
                "client_stats_{$clientId}",
                now()->addMinutes(5),
                function () use ($clientId) {
                    return [
                        'files_uploaded_this_month'