<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\UserFeaturePermissionController;
use App\Http\Controllers\Api\V1\PocketExpenseController;
use App\Http\Controllers\PocketExpenseUploadController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| OOP Expense Management API Routes
|--------------------------------------------------------------------------
|
| All routes use Oauth2UserClient middleware for OAuth2 token-based 
| authentication as per platform constraints. Routes are versioned 
| under /v1 and follow RESTful naming conventions.
|
*/

// API Version 1 Routes with OAuth2 Authentication
Route::prefix('v1')->middleware(['Oauth2UserClient'])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | User Feature Permission Management Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing user feature permissions including granting,
    | revoking, and querying permissions. Follows delegation table pattern
    | with role-based hierarchy for Primary Admin, Admin, Business User,
    | and Card User roles.
    |
    */
    Route::prefix('user-feature-permissions')->name('api.v1.user-feature-permissions.')->group(function () {
        // List user feature permissions with filtering
        Route::get('/', [UserFeaturePermissionController::class, 'index'])
            ->name('index');
        
        // Grant new feature permission to user
        Route::post('/', [UserFeaturePermissionController::class, 'store'])
            ->name('store');
        
        // Get specific permission details
        Route::get('{permission}', [UserFeaturePermissionController::class, 'show'])
            ->name('show');
        
        // Update existing permission (enable/disable, change manager)
        Route::put('{permission}', [UserFeaturePermissionController::class, 'update'])
            ->name('update');
        
        // Revoke permission (soft delete or hard delete based on policy)
        Route::delete('{permission}', [UserFeaturePermissionController::class, 'destroy'])
            ->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Pocket Expense CRUD Routes
    |--------------------------------------------------------------------------
    |
    | Routes for single expense data capturing with real-time FX conversion,
    | metadata management, and approval workflow. Supports draft, submitted,
    | approved, and rejected status transitions.
    |
    */
    Route::prefix('pocket-expenses')->name('api.v1.pocket-expenses.')->group(function () {
        // List user expenses with filtering by status, date range, etc.
        Route::get('/', [PocketExpenseController::class, 'index'])
            ->name('index');
        
        // Create new expense with FX conversion and metadata
        Route::post('/', [PocketExpenseController::class, 'store'])
            ->name('store');
        
        // Get specific expense with metadata relationships
        Route::get('{expense}', [PocketExpenseController::class, 'show'])
            ->name('show');
        
        // Update existing expense (recalculate FX, update metadata)
        Route::put('{expense}', [PocketExpenseController::class, 'update'])
            ->name('update');
        
        // Delete expense (soft delete with audit trail)
        Route::delete('{expense}', [PocketExpenseController::class, 'destroy'])
            ->name('destroy');
        
        // Additional expense workflow actions
        Route::patch('{expense}/approve', [PocketExpenseController::class, 'approve'])
            ->name('approve');
        
        Route::patch('{expense}/reject', [PocketExpenseController::class, 'reject'])
            ->name('reject');
        
        Route::patch('{expense}/submit', [PocketExpenseController::class, 'submit'])
            ->name('submit');
        
        Route::patch('{expense}/withdraw', [PocketExpenseController::class, 'withdraw'])
            ->name('withdraw');
    });

    /*
    |--------------------------------------------------------------------------
    | Expense Reference Data Routes
    |--------------------------------------------------------------------------
    |
    | Routes for accessing reference data needed for expense creation
    | including expense types, sources, categories, tracking codes, etc.
    | These are read-only endpoints for populating dropdowns and validation.
    |
    */
    Route::prefix('expense-reference')->name('api.v1.expense-reference.')->group(function () {
        // Get available expense types with amount sign conventions
        Route::get('expense-types', [PocketExpenseController::class, 'getExpenseTypes'])
            ->name('expense-types');
        
        // Get client expense sources (including global 'Other')
        Route::get('expense-sources', [PocketExpenseController::class, 'getExpenseSources'])
            ->name('expense-sources');
        
        // Get transaction categories for client
        Route::get('categories', [PocketExpenseController::class, 'getCategories'])
            ->name('categories');
        
        // Get tracking codes for client and user
        Route::get('tracking-codes', [PocketExpenseController::class, 'getTrackingCodes'])
            ->name('tracking-codes');
        
        // Get configurable projects for client
        Route::get('projects', [PocketExpenseController::class, 'getProjects'])
            ->name('projects');
        
        // Get additional fields configuration for client
        Route::get('additional-fields', [PocketExpenseController::class, 'getAdditionalFields'])
            ->name('additional-fields');
        
        // Get wallet base currency and FX rates for client
        Route::get('fx-info', [PocketExpenseController::class, 'getFXInfo'])
            ->name('fx-info');
        
        // Get real-time FX rate for currency pair and date
        Route::get('fx-rate', [PocketExpenseController::class, 'getFXRate'])
            ->name('fx-rate');
    });
});

/*
|--------------------------------------------------------------------------
| CSV Batch Upload Routes
|--------------------------------------------------------------------------
|
| Routes for CSV file upload and batch processing outside the versioned
| API structure. Uses separate upload controller for file handling with
| synchronous validation and asynchronous processing via Laravel queues.
|
*/
Route::prefix('uploads')->middleware(['Oauth2UserClient'])->name('api.uploads.')->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Pocket Expense CSV Upload Routes
    |--------------------------------------------------------------------------
    |
    | Route for uploading CSV files with expense data for batch processing.
    | Supports up to 200 rows per file with 10MB limit. Uses all-or-nothing
    | validation pattern - if any row fails, no expenses are created.
    |
    */
    Route::prefix('pocket-expense')->name('pocket-expense.')->group(function () {
        // Upload CSV file for batch expense processing
        Route::post('csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])
            ->name('csv');
        
        // Get upload status and progress
        Route::get('upload/{uploadId}/status', [PocketExpenseUploadController::class, 'getUploadStatus'])
            ->name('status');
        
        // Get detailed upload results with validation errors
        Route::get('upload/{uploadId}/results', [PocketExpenseUploadController::class, 'getUploadResults'])
            ->name('results');
        
        // Download CSV template for expenses
        Route::get('template', [PocketExpenseUploadController::class, 'downloadTemplate'])
            ->name('template');
        
        // Retry failed upload processing
        Route::post('upload/{uploadId}/retry', [PocketExpenseUploadController::class, 'retryUpload'])
            ->name('retry');
        
        // Cancel pending upload processing
        Route::delete('upload/{uploadId}', [PocketExpenseUploadController::class, 'cancelUpload'])
            ->name('cancel');
    });
});

/*
|--------------------------------------------------------------------------
| Health Check and System Routes
|--------------------------------------------------------------------------
|
| Basic health check routes that don't require authentication.
| Used for monitoring and system status verification.
|
*/
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'oop-expense-api',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString()
    ]);
})->name('api.health');

Route::get('/ping', function () {
    return response()->json(['message' => 'pong']);
})->name('api.ping');

/*
|--------------------------------------------------------------------------
| Route Model Binding Configuration
|--------------------------------------------------------------------------
|
| Configure route model binding for automatic model resolution based on
| route parameters. This enables automatic injection of model instances
| into controller methods with proper authorization checks.
|
*/

// Bind pocket expense route parameter to PocketExpense model
Route::bind('expense', function ($value) {
    return \App\Models\PocketExpense::where('id', $value)
        ->where('client_id', auth()->user()->client_id ?? 0)
        ->where('deleted', 0)
        ->firstOrFail();
});

// Bind permission route parameter to UserFeaturePermission model
Route::bind('permission', function ($value) {
    return \App\Models\UserFeaturePermission::where('id', $value)
        ->where('client_id', auth()->user()->client_id ?? 0)
        ->firstOrFail();
});

// Bind upload ID parameter to PocketExpenseFileUpload model
Route::bind('uploadId', function ($value) {
    return \App\Models\PocketExpenseFileUpload::where('id', $value)
        ->where('client_id', auth()->user()->client_id ?? 0)
        ->whereNull('deleted_at')
        ->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Route Caching Configuration
|--------------------------------------------------------------------------
|
| Configure route caching to improve performance in production.
| Reference data routes can be cached longer due to infrequent changes.
|
*/

// Apply rate limiting to upload endpoints to prevent abuse
Route::middleware(['throttle:uploads'])->group(function () {
    // Upload routes are already defined above with throttling applied
});

// Apply different rate limiting to API endpoints
Route::middleware(['throttle:api'])->group(function () {
    // API routes are already defined above with throttling applied
});

/*
|--------------------------------------------------------------------------
| API Documentation Routes
|--------------------------------------------------------------------------
|
| Routes for API documentation and OpenAPI specification.
| Available in development and staging environments only.
|
*/
if (config('app.env') !== 'production') {
    Route::get('/docs/openapi.json', function () {
        return response()->file(base_path('docs/openapi.json'));
    })->name('api.docs.openapi');
    
    Route::get('/docs', function () {
        return view('api.docs.index');
    })->name('api.docs');
}

/*
|--------------------------------------------------------------------------
| Fallback Route
|--------------------------------------------------------------------------
|
| This route will catch all undefined API routes and return a consistent
| JSON error response instead of Laravel's default 404 page.
|
*/
Route::fallback(function () {
    return response()->json([
        'error' => 'Not Found',
        'message' => 'The requested API endpoint was not found.',
        'code' => 'ENDPOINT_NOT_FOUND'
    ], 404);
})->name('api.fallback');