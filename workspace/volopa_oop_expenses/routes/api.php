<?php

use App\Http\Controllers\Api\V1\PocketExpenseController;
use App\Http\Controllers\Api\V1\UserFeaturePermissionController;
use App\Http\Controllers\PocketExpenseUploadController;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Volopa OOP Expenses API Routes
|--------------------------------------------------------------------------
|
| All routes use OAuth2 authentication via Oauth2UserClient middleware
| Routes follow versioning pattern with /v1 prefix where applicable
| File upload endpoint omits /v1 prefix as per specification
|
*/

// CSV File Upload Endpoint (omits /v1 prefix per specification)
Route::middleware(['Oauth2UserClient'])->group(function () {
    // POST /api/uploads/pocket-expense/csv
    Route::post('uploads/pocket-expense/csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])
        ->name('api.uploads.pocket-expense.csv.store');
});

// Versioned API Routes (v1)
Route::prefix('v1')->middleware(['Oauth2UserClient'])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Pocket Expense CRUD Routes
    |--------------------------------------------------------------------------
    |
    | Standard RESTful resource routes for individual expense management
    | Includes index, store, show, update, destroy operations
    |
    */
    Route::apiResource('pocket-expenses', PocketExpenseController::class, [
        'names' => [
            'index' => 'api.v1.pocket-expenses.index',
            'store' => 'api.v1.pocket-expenses.store',
            'show' => 'api.v1.pocket-expenses.show',
            'update' => 'api.v1.pocket-expenses.update',
            'destroy' => 'api.v1.pocket-expenses.destroy',
        ]
    ]);

    /*
    |--------------------------------------------------------------------------
    | User Feature Permission Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing user permissions and delegation
    | Supports granting and revoking feature access permissions
    |
    */
    Route::apiResource('user-feature-permissions', UserFeaturePermissionController::class, [
        'only' => ['index', 'store', 'destroy'],
        'names' => [
            'index' => 'api.v1.user-feature-permissions.index',
            'store' => 'api.v1.user-feature-permissions.store',
            'destroy' => 'api.v1.user-feature-permissions.destroy',
        ]
    ]);

    /*
    |--------------------------------------------------------------------------
    | Additional Expense Management Routes
    |--------------------------------------------------------------------------
    |
    | Specialized routes for expense operations beyond basic CRUD
    |
    */
    
    // Expense approval/rejection routes
    Route::patch('pocket-expenses/{pocketExpense}/approve', [PocketExpenseController::class, 'approve'])
        ->name('api.v1.pocket-expenses.approve');
    
    Route::patch('pocket-expenses/{pocketExpense}/reject', [PocketExpenseController::class, 'reject'])
        ->name('api.v1.pocket-expenses.reject');
    
    Route::patch('pocket-expenses/{pocketExpense}/submit', [PocketExpenseController::class, 'submit'])
        ->name('api.v1.pocket-expenses.submit');

    /*
    |--------------------------------------------------------------------------
    | Expense Source Configuration Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing client-specific expense source configurations
    |
    */
    Route::get('expense-sources', [PocketExpenseController::class, 'getSources'])
        ->name('api.v1.expense-sources.index');

    /*
    |--------------------------------------------------------------------------
    | FX Conversion Utility Routes
    |--------------------------------------------------------------------------
    |
    | Routes for currency conversion and FX rate information
    |
    */
    Route::post('fx-conversion', [PocketExpenseController::class, 'convertCurrency'])
        ->name('api.v1.fx-conversion.convert');

    /*
    |--------------------------------------------------------------------------
    | User Management Permission Routes
    |--------------------------------------------------------------------------
    |
    | Additional routes for advanced permission management
    |
    */
    
    // Get permissions for a specific user
    Route::get('users/{user}/permissions', [UserFeaturePermissionController::class, 'getUserPermissions'])
        ->name('api.v1.users.permissions.index');
    
    // Get users that the authenticated user can manage
    Route::get('manageable-users', [UserFeaturePermissionController::class, 'getManageableUsers'])
        ->name('api.v1.manageable-users.index');
    
    // Check if user can manage another user
    Route::get('can-manage-user/{user}', [UserFeaturePermissionController::class, 'canManageUser'])
        ->name('api.v1.can-manage-user.check');

    /*
    |--------------------------------------------------------------------------
    | Bulk Operations Routes
    |--------------------------------------------------------------------------
    |
    | Routes for bulk operations on expenses
    |
    */
    
    // Bulk approve expenses
    Route::patch('pocket-expenses/bulk/approve', [PocketExpenseController::class, 'bulkApprove'])
        ->name('api.v1.pocket-expenses.bulk.approve');
    
    // Bulk reject expenses
    Route::patch('pocket-expenses/bulk/reject', [PocketExpenseController::class, 'bulkReject'])
        ->name('api.v1.pocket-expenses.bulk.reject');
    
    // Bulk delete expenses
    Route::delete('pocket-expenses/bulk', [PocketExpenseController::class, 'bulkDestroy'])
        ->name('api.v1.pocket-expenses.bulk.destroy');

    /*
    |--------------------------------------------------------------------------
    | File Upload Status Routes
    |--------------------------------------------------------------------------
    |
    | Routes for checking CSV upload status and results
    |
    */
    
    // Get upload status and results
    Route::get('uploads/pocket-expense/{upload}', [PocketExpenseUploadController::class, 'getUploadStatus'])
        ->name('api.v1.uploads.pocket-expense.show');
    
    // List user's uploads
    Route::get('uploads/pocket-expense', [PocketExpenseUploadController::class, 'index'])
        ->name('api.v1.uploads.pocket-expense.index');
    
    // Cancel/abort upload processing
    Route::patch('uploads/pocket-expense/{upload}/cancel', [PocketExpenseUploadController::class, 'cancelUpload'])
        ->name('api.v1.uploads.pocket-expense.cancel');

    /*
    |--------------------------------------------------------------------------
    | Reporting and Analytics Routes
    |--------------------------------------------------------------------------
    |
    | Routes for expense reporting and analytics
    |
    */
    
    // Expense summary statistics
    Route::get('pocket-expenses/reports/summary', [PocketExpenseController::class, 'getSummary'])
        ->name('api.v1.pocket-expenses.reports.summary');
    
    // Expense trends by period
    Route::get('pocket-expenses/reports/trends', [PocketExpenseController::class, 'getTrends'])
        ->name('api.v1.pocket-expenses.reports.trends');
    
    // Export expenses to CSV
    Route::get('pocket-expenses/export', [PocketExpenseController::class, 'export'])
        ->name('api.v1.pocket-expenses.export');

    /*
    |--------------------------------------------------------------------------
    | System Configuration Routes
    |--------------------------------------------------------------------------
    |
    | Routes for accessing system configuration and reference data
    |
    */
    
    // Get expense types
    Route::get('expense-types', [PocketExpenseController::class, 'getExpenseTypes'])
        ->name('api.v1.expense-types.index');
    
    // Get supported currencies
    Route::get('currencies', [PocketExpenseController::class, 'getCurrencies'])
        ->name('api.v1.currencies.index');
    
    // Get client features and permissions
    Route::get('client-features', [UserFeaturePermissionController::class, 'getClientFeatures'])
        ->name('api.v1.client-features.index');

});

/*
|--------------------------------------------------------------------------
| Rate Limiting
|--------------------------------------------------------------------------
|
| Apply appropriate rate limiting to API endpoints
| Upload endpoints have lower limits due to processing overhead
|
*/

// Apply throttling to upload endpoints
Route::middleware(['throttle:5,1'])->group(function () {
    Route::post('uploads/pocket-expense/csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])
        ->middleware(['Oauth2UserClient'])
        ->name('api.uploads.pocket-expense.csv.throttled');
});

// Standard API throttling for other endpoints
Route::middleware(['throttle:60,1', 'Oauth2UserClient'])->prefix('v1')->group(function () {
    // High-frequency endpoints get additional throttling
    Route::get('pocket-expenses', [PocketExpenseController::class, 'index'])
        ->name('api.v1.pocket-expenses.index.throttled');
});

/*
|--------------------------------------------------------------------------
| Health Check Routes
|--------------------------------------------------------------------------
|
| System health and status endpoints for monitoring
|
*/

Route::middleware(['Oauth2UserClient'])->group(function () {
    // API health check
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
            'version' => config('app.version', '1.0.0'),
            'services' => [
                'database' => 'ok',
                'queue' => 'ok',
                'storage' => 'ok',
            ]
        ]);
    })->name('api.health');
    
    // Feature availability check
    Route::get('features/availability', [UserFeaturePermissionController::class, 'checkFeatureAvailability'])
        ->name('api.features.availability');
});

/*
|--------------------------------------------------------------------------
| Route Model Binding
|--------------------------------------------------------------------------
|
| Configure route model binding for consistent model resolution
|
*/

// Bind pocket expenses by UUID instead of ID for public-facing routes
Route::bind('pocketExpense', function ($value) {
    return \App\Models\PocketExpense::where('uuid', $value)
        ->orWhere('id', $value)
        ->firstOrFail();
});

// Bind user feature permissions
Route::bind('userFeaturePermission', function ($value) {
    return \App\Models\UserFeaturePermission::findOrFail($value);
});

// Bind upload records by UUID
Route::bind('upload', function ($value) {
    return \App\Models\PocketExpenseFileUpload::where('uuid', $value)
        ->orWhere('id', $value)
        ->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| Fallback Routes
|--------------------------------------------------------------------------
|
| Handle undefined API routes with appropriate error responses
|
*/

Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'API endpoint not found. Please check the URL and HTTP method.',
        'available_endpoints' => [
            'POST /api/uploads/pocket-expense/csv' => 'Upload expense CSV file',
            'GET /api/v1/pocket-expenses' => 'List expenses',
            'POST /api/v1/pocket-expenses' => 'Create expense',
            'GET /api/v1/pocket-expenses/{id}' => 'Get expense details',
            'PUT /api/v1/pocket-expenses/{id}' => 'Update expense',
            'DELETE /api/v1/pocket-expenses/{id}' => 'Delete expense',
            'GET /api/v1/user-feature-permissions' => 'List permissions',
            'POST /api/v1/user-feature-permissions' => 'Grant permission',
            'DELETE /api/v1/user-feature-permissions/{id}' => 'Revoke permission',
        ]
    ], 404);
});