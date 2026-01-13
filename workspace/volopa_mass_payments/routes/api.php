## Code: routes/api.php

```php
<?php

use App\Http\Controllers\Api\V1\MassPaymentFileController;
use App\Http\Controllers\Api\V1\PaymentInstructionController;
use App\Http\Controllers\Api\V1\TemplateController;
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
| Health Check Route
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'version' => config('app.version', '1.0.0'),
        'service' => 'Mass Payments API',
    ]);
});

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
|
| All V1 API routes with proper middleware, throttling, and authentication.
| Routes follow RESTful conventions and include proper naming for URL generation.
|
*/
Route::prefix('v1')->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Authentication Required Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
        
        /*
        |--------------------------------------------------------------------------
        | Mass Payment Files Management
        |--------------------------------------------------------------------------
        */
        Route::apiResource('mass-payment-files', MassPaymentFileController::class, [
            'parameters' => ['mass-payment-files' => 'mass_payment_file']
        ])->names([
            'index' => 'api.v1.mass-payment-files.index',
            'store' => 'api.v1.mass-payment-files.store', 
            'show' => 'api.v1.mass-payment-files.show',
            'update' => 'api.v1.mass-payment-files.update',
            'destroy' => 'api.v1.mass-payment-files.destroy',
        ]);

        // Additional mass payment file routes
        Route::prefix('mass-payment-files/{mass_payment_file}')->group(function () {
            // Approve payment file
            Route::post('/approve', [MassPaymentFileController::class, 'approve'])
                ->name('api.v1.mass-payment-files.approve')
                ->middleware('throttle:10,1'); // Stricter rate limit for approvals

            // Download original file
            Route::get('/download', [MassPaymentFileController::class, 'download'])
                ->name('api.v1.mass-payment-files.download')
                ->middleware('throttle:30,1');

            // Get validation errors
            Route::get('/validation-errors', [MassPaymentFileController::class, 'validationErrors'])
                ->name('api.v1.mass-payment-files.validation-errors');

            // Retry failed processing
            Route::post('/retry', [MassPaymentFileController::class, 'retry'])
                ->name('api.v1.mass-payment-files.retry')
                ->middleware('throttle:5,1');

            // Cancel processing
            Route::post('/cancel', [MassPaymentFileController::class, 'cancel'])
                ->name('api.v1.mass-payment-files.cancel')
                ->middleware('throttle:5,1');

            // Get processing status
            Route::get('/status', [MassPaymentFileController::class, 'status'])
                ->name('api.v1.mass-payment-files.status');

            // Export processing report
            Route::get('/export', [MassPaymentFileController::class, 'export'])
                ->name('api.v1.mass-payment-files.export')
                ->middleware('throttle:10,1');

            // Get file statistics
            Route::get('/statistics', [MassPaymentFileController::class, 'statistics'])
                ->name('api.v1.mass-payment-files.statistics');
        });

        /*
        |--------------------------------------------------------------------------
        | Payment Instructions Management
        |--------------------------------------------------------------------------
        */
        Route::apiResource('payment-instructions', PaymentInstructionController::class, [
            'only' => ['index', 'show']
        ])->names([
            'index' => 'api.v1.payment-instructions.index',
            'show' => 'api.v1.payment-instructions.show',
        ]);

        // Additional payment instruction routes
        Route::prefix('payment-instructions/{payment_instruction}')->group(function () {
            // Cancel individual payment instruction
            Route::post('/cancel', [PaymentInstructionController::class, 'cancel'])
                ->name('api.v1.payment-instructions.cancel')
                ->middleware('throttle:10,1');

            // Retry individual payment instruction
            Route::post('/retry', [PaymentInstructionController::class, 'retry'])
                ->name('api.v1.payment-instructions.retry')
                ->middleware('throttle:5,1');

            // Get validation errors for instruction
            Route::get('/validation-errors', [PaymentInstructionController::class, 'validationErrors'])
                ->name('api.v1.payment-instructions.validation-errors');

            // Download payment receipt (for completed payments)
            Route::get('/receipt', [PaymentInstructionController::class, 'receipt'])
                ->name('api.v1.payment-instructions.receipt')
                ->middleware('throttle:30,1');

            // Get transaction details
            Route::get('/transaction', [PaymentInstructionController::class, 'transaction'])
                ->name('api.v1.payment-instructions.transaction');
        });

        // Bulk operations on payment instructions
        Route::prefix('payment-instructions/bulk')->group(function () {
            // Cancel multiple payment instructions
            Route::post('/cancel', [PaymentInstructionController::class, 'bulkCancel'])
                ->name('api.v1.payment-instructions.bulk-cancel')
                ->middleware('throttle:5,1');

            // Retry multiple payment instructions
            Route::post('/retry', [PaymentInstructionController::class, 'bulkRetry'])
                ->name('api.v1.payment-instructions.bulk-retry')
                ->middleware('throttle:3,1');

            // Export payment instructions
            Route::post('/export', [PaymentInstructionController::class, 'bulkExport'])
                ->name('api.v1.payment-instructions.bulk-export')
                ->middleware('throttle:10,1');
        });

        /*
        |--------------------------------------------------------------------------
        | Template Downloads
        |--------------------------------------------------------------------------
        */
        Route::prefix('templates')->group(function () {
            // Get template information
            Route::get('/info', [TemplateController::class, 'getTemplateInfo'])
                ->name('api.v1.templates.info');

            // Download recipient template with existing beneficiaries
            Route::get('/recipients/{currency}', [TemplateController::class, 'downloadRecipientTemplate'])
                ->name('api.v1.templates.recipients')
                ->middleware('throttle:20,1')
                ->where('currency', '[A-Z]{3}');

            // Download blank template
            Route::get('/blank/{currency}', [TemplateController::class, 'downloadBlankTemplate'])
                ->name('api.v1.templates.blank')
                ->middleware('throttle:30,1')
                ->where('currency', '[A-Z]{3}');

            // Get template preview (headers and sample data)
            Route::get('/preview/{currency}', [TemplateController::class, 'getTemplatePreview'])
                ->name('api.v1.templates.preview')
                ->where('currency', '[A-Z]{3}');

            // Get currency-specific template requirements
            Route::get('/requirements/{currency}', [TemplateController::class, 'getCurrencyRequirements'])
                ->name('api.v1.templates.requirements')
                ->where('currency', '[A-Z]{3}');
        });

        /*
        |--------------------------------------------------------------------------
        | System Information Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('system')->group(function () {
            // Get supported currencies
            Route::get('/currencies', function () {
                return response()->json([
                    'success' => true,
                    'data' => config('mass-payments.supported_currencies', []),
                ]);
            })->name('api.v1.system.currencies');

            // Get purpose codes
            Route::get('/purpose-codes', function () {
                $user = auth()->user();
                $country = $user->country ?? 'US';
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'all_purpose_codes' => config('mass-payments.purpose_codes', []),
                        'country_specific' => config("mass-payments.country_purpose_codes.{$country}", []),
                        'user_country' => $country,
                    ],
                ]);
            })->name('api.v1.system.purpose-codes');

            // Get system configuration
            Route::get('/config', function () {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'max_file_size_mb' => config('mass-payments.max_file_size_mb', 10),
                        'max_rows_per_file' => config('mass-payments.max_rows_per_file', 10000),
                        'min_rows_per_file' => config('mass-payments.min_rows_per_file', 1),
                        'supported_file_extensions' => config('mass-payments.allowed_file_extensions', ['csv', 'txt']),
                        'max_amount_per_instruction' => config('mass-payments.validation.max_amount_per_instruction', 999999.99),
                        'min_amount_per_instruction' => config('mass-payments.validation.min_amount_per_instruction', 0.01),
                        'approval_threshold' => config('mass-payments.approval.approval_threshold', 100000.00),
                        'auto_approve_threshold' => config('mass-payments.approval.auto_approve_threshold', 1000.00),
                    ],
                ]);
            })->name('api.v1.system.config');

            // Get system limits for current user
            Route::get('/limits', function () {
                $user = auth()->user();
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'daily_upload_limit' => config('mass-payments.security.max_uploads_per_day', 100),
                        'daily_approval_limit' => config('mass-payments.approval.daily_approval_limit', 50),
                        'rate_limit_per_minute' => config('mass-payments.security.rate_limit_per_minute', 60),
                        'concurrent_processing_limit' => config('mass-payments.processing.max_concurrent_jobs', 5),
                        'user_permissions' => [
                            'can_upload' => $user->can('create', \App\Models\MassPaymentFile::class),
                            'can_approve' => method_exists($user, 'hasPermission') ? $user->hasPermission('mass_payments.approve') : false,
                            'can_view_all' => method_exists($user, 'hasPermission') ? $user->hasPermission('mass_payments.view_all') : false,
                        ],
                    ],
                ]);
            })->name('api.v1.system.limits');
        });

        /*
        |--------------------------------------------------------------------------
        | Statistics and Analytics Routes
        |--------------------------------------------------------------------------
        */
        Route::prefix('analytics')->middleware('throttle:30,1')->group(function () {
            // Get dashboard statistics
            Route::get('/dashboard', function (Request $request) {
                $user = auth()->user();
                $clientId = $user->client_id;
                
                if (!$clientId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User must be associated with a client',
                    ], 403);
                }

                // Get date range from request
                $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
                $dateTo = $request->get('date_to', now()->toDateString());

                // Basic statistics query
                $stats = \App\Models\MassPaymentFile::where('client_id', $clientId)
                    ->whereBetween('created_at', [$dateFrom, $dateTo])
                    ->selectRaw('
                        COUNT(*) as total_files,
                        SUM(total_amount) as total_amount,
                        COUNT(CASE WHEN status = "completed" THEN 1 END) as completed_files,
                        COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_files,
                        COUNT(CASE WHEN status = "processing" THEN 1 END) as processing_files,
                        COUNT(CASE WHEN status = "awaiting_approval" THEN 1 END) as pending_approval_files
                    ')
                    ->first();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'period' => [
                            'from' => $dateFrom,
                            'to' => $dateTo,
                        ],
                        'statistics' => $stats,
                        'generated_at' => now()->toISOString(),
                    ],
                ]);
            })->name('api.v1.analytics.dashboard');

            // Get payment volume trends
            Route::get('/trends', function (Request $request) {
                $user = auth()->user();
                $clientId = $user->client_id;
                
                if (!$clientId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User must be associated with a client',
                    ], 403);
                }

                $days = min((int) $request->get('days', 30), 365);
                $dateFrom = now()->subDays($days);

                $trends = \App\Models\MassPaymentFile::where('client_id', $clientId)
                    ->where('created_at', '>=', $dateFrom)
                    ->selectRaw('
                        DATE(created_at) as date,
                        COUNT(*) as files_count,
                        SUM(total_amount) as total_amount,
                        AVG(total_amount) as avg_amount
                    ')
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'period_days' => $days,
                        'trends' => $trends,
                        'generated_at' => now()->toISOString(),
                    ],
                ]);
            })->name('api.v1.analytics.trends');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Public Routes (No Authentication Required)
    |--------------------------------------------------------------------------
    */
    
    // Get supported currencies (public access for form building)
    Route::get('/public/currencies', function () {
        return response()->json([
            'success' => true,
            'data' => array_keys(config('mass-payments.supported_currencies', [])),
            'details' => config('mass-payments.supported_currencies', []),
        ]);
    })->name('api.v1.public.currencies');

    // Get purpose codes (public access)
    Route::get('/public/purpose-codes', function () {
        return response()->json([
            'success' => true,
            'data' => config('mass-payments.purpose_codes', []),
        ]);
    })->name('api.v1.public.purpose-codes');

    // Get API documentation info
    Route::get('/public/info', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'name' => 'Volopa Mass Payments API',
                'version' => 'v1.0.0',
                'description' => 'RESTful API for bulk payment processing with CSV upload support',