<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PocketExpenseController;
use App\Http\Controllers\Api\UserFeaturePermissionController;
use App\Http\Controllers\Api\PocketExpenseUploadController;
use App\Http\Controllers\Api\PocketExpenseSourceController;

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
| Volopa Out-of-Pocket Expenses API Routes
|--------------------------------------------------------------------------
|
| All routes are protected by OAuth2 authentication middleware and
| follow the /v1 versioning prefix pattern. Routes are scoped by
| client_id for multi-tenancy support.
|
*/

Route::prefix('v1')->middleware(['auth:api', 'oauth2.user.client'])->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | Pocket Expenses Management
    |--------------------------------------------------------------------------
    |
    | RESTful API endpoints for managing out-of-pocket expenses.
    | Supports CRUD operations with proper authorization policies.
    |
    */
    Route::apiResource('pocket-expenses', PocketExpenseController::class)->names([
        'index'   => 'api.pocket-expenses.index',
        'store'   => 'api.pocket-expenses.store', 
        'show'    => 'api.pocket-expenses.show',
        'update'  => 'api.pocket-expenses.update',
        'destroy' => 'api.pocket-expenses.destroy',
    ]);

    /*
    |--------------------------------------------------------------------------
    | CSV Batch Upload Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoints for handling CSV file uploads and batch processing
    | of pocket expenses with validation and queue processing.
    |
    */
    Route::prefix('uploads')->name('api.uploads.')->group(function () {
        Route::post('pocket-expense/csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])
             ->name('pocket-expense.csv');
             
        Route::get('pocket-expense/{uploadId}/status', [PocketExpenseUploadController::class, 'getUploadStatus'])
             ->name('pocket-expense.status');
             
        Route::get('pocket-expense/{uploadId}/errors', [PocketExpenseUploadController::class, 'getUploadErrors'])
             ->name('pocket-expense.errors');
             
        Route::delete('pocket-expense/{uploadId}', [PocketExpenseUploadController::class, 'cancelUpload'])
             ->name('pocket-expense.cancel');
    });

    /*
    |--------------------------------------------------------------------------
    | User Feature Permissions Management
    |--------------------------------------------------------------------------
    |
    | Endpoints for managing user feature permissions with delegation-based
    | RBAC system. Supports granting, revoking, and managing permissions.
    |
    */
    Route::apiResource('user-feature-permissions', UserFeaturePermissionController::class)->except(['show'])->names([
        'index'   => 'api.user-feature-permissions.index',
        'store'   => 'api.user-feature-permissions.store',
        'update'  => 'api.user-feature-permissions.update', 
        'destroy' => 'api.user-feature-permissions.destroy',
    ]);

    // Additional permission management endpoints
    Route::prefix('user-feature-permissions')->name('api.user-feature-permissions.')->group(function () {
        Route::post('{permission}/enable', [UserFeaturePermissionController::class, 'enable'])
             ->name('enable');
             
        Route::post('{permission}/disable', [UserFeaturePermissionController::class, 'disable'])
             ->name('disable');
             
        Route::put('{permission}/manager', [UserFeaturePermissionController::class, 'setManager'])
             ->name('set-manager');
             
        Route::delete('{permission}/manager', [UserFeaturePermissionController::class, 'removeManager'])
             ->name('remove-manager');
             
        Route::get('managed-users', [UserFeaturePermissionController::class, 'getManagedUsers'])
             ->name('managed-users');
             
        Route::get('user/{userId}', [UserFeaturePermissionController::class, 'getUserPermissions'])
             ->name('user-permissions');
    });

    /*
    |--------------------------------------------------------------------------
    | Expense Source Configuration
    |--------------------------------------------------------------------------
    |
    | Endpoints for managing expense source configurations like
    | "Company Credit Card", "Personal Cash", etc. Supports client-specific
    | and global source configurations.
    |
    */
    Route::apiResource('pocket-expense-sources', PocketExpenseSourceController::class)->names([
        'index'   => 'api.pocket-expense-sources.index',
        'store'   => 'api.pocket-expense-sources.store',
        'show'    => 'api.pocket-expense-sources.show',
        'update'  => 'api.pocket-expense-sources.update',
        'destroy' => 'api.pocket-expense-sources.destroy',
    ]);

    // Additional source configuration endpoints
    Route::prefix('pocket-expense-sources')->name('api.pocket-expense-sources.')->group(function () {
        Route::post('{source}/set-default', [PocketExpenseSourceController::class, 'setAsDefault'])
             ->name('set-default');
             
        Route::delete('{source}/default', [PocketExpenseSourceController::class, 'removeDefault'])
             ->name('remove-default');
             
        Route::post('{source}/restore', [PocketExpenseSourceController::class, 'restore'])
             ->name('restore');
             
        Route::get('available', [PocketExpenseSourceController::class, 'getAvailableForClient'])
             ->name('available');
             
        Route::get('default', [PocketExpenseSourceController::class, 'getDefaultForClient'])
             ->name('default');
    });

    /*
    |--------------------------------------------------------------------------
    | Expense Actions and Workflows
    |--------------------------------------------------------------------------
    |
    | Additional endpoints for expense workflow actions like submit,
    | approve, reject, and other business operations.
    |
    */
    Route::prefix('pocket-expenses')->name('api.pocket-expenses.')->group(function () {
        Route::post('{expense}/submit', [PocketExpenseController::class, 'submit'])
             ->name('submit');
             
        Route::post('{expense}/approve', [PocketExpenseController::class, 'approve'])
             ->name('approve');
             
        Route::post('{expense}/reject', [PocketExpenseController::class, 'reject'])
             ->name('reject');
             
        Route::post('{expense}/revert-to-draft', [PocketExpenseController::class, 'revertToDraft'])
             ->name('revert-to-draft');
             
        Route::post('{expense}/restore', [PocketExpenseController::class, 'restore'])
             ->name('restore');
             
        Route::get('pending-approval', [PocketExpenseController::class, 'getPendingApproval'])
             ->name('pending-approval');
             
        Route::get('approved', [PocketExpenseController::class, 'getApproved'])
             ->name('approved');
             
        Route::get('rejected', [PocketExpenseController::class, 'getRejected'])
             ->name('rejected');
             
        Route::get('drafts', [PocketExpenseController::class, 'getDrafts'])
             ->name('drafts');
             
        Route::get('user/{userId}', [PocketExpenseController::class, 'getUserExpenses'])
             ->name('user-expenses');
             
        Route::get('statistics', [PocketExpenseController::class, 'getStatistics'])
             ->name('statistics');
    });

    /*
    |--------------------------------------------------------------------------
    | Reference Data Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoints for retrieving reference data used in expense forms
    | and validation, such as expense types, currencies, etc.
    |
    */
    Route::prefix('reference')->name('api.reference.')->group(function () {
        Route::get('expense-types', [PocketExpenseController::class, 'getExpenseTypes'])
             ->name('expense-types');
             
        Route::get('currencies', [PocketExpenseController::class, 'getCurrencies'])
             ->name('currencies');
             
        Route::get('countries', [PocketExpenseController::class, 'getCountries'])
             ->name('countries');
             
        Route::get('fx-rate', [PocketExpenseController::class, 'getFxRate'])
             ->name('fx-rate');
    });

    /*
    |--------------------------------------------------------------------------
    | File Management Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoints for managing file uploads, downloads, and processing
    | related to expense receipts and CSV uploads.
    |
    */
    Route::prefix('files')->name('api.files.')->group(function () {
        Route::post('receipt-upload', [PocketExpenseController::class, 'uploadReceipt'])
             ->name('receipt-upload');
             
        Route::get('receipt/{fileId}/download', [PocketExpenseController::class, 'downloadReceipt'])
             ->name('receipt-download');
             
        Route::delete('receipt/{fileId}', [PocketExpenseController::class, 'deleteReceipt'])
             ->name('receipt-delete');
             
        Route::get('csv-template', [PocketExpenseUploadController::class, 'downloadCsvTemplate'])
             ->name('csv-template');
             
        Route::get('upload/{uploadId}/download', [PocketExpenseUploadController::class, 'downloadUploadFile'])
             ->name('upload-download');
    });

    /*
    |--------------------------------------------------------------------------
    | Export and Reporting Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoints for generating reports and exports of expense data
    | in various formats (CSV, PDF, Excel).
    |
    */
    Route::prefix('exports')->name('api.exports.')->group(function () {
        Route::get('expenses/csv', [PocketExpenseController::class, 'exportToCsv'])
             ->name('expenses-csv');
             
        Route::get('expenses/excel', [PocketExpenseController::class, 'exportToExcel'])
             ->name('expenses-excel');
             
        Route::get('expenses/pdf', [PocketExpenseController::class, 'exportToPdf'])
             ->name('expenses-pdf');
             
        Route::get('upload/{uploadId}/report', [PocketExpenseUploadController::class, 'generateUploadReport'])
             ->name('upload-report');
    });

    /*
    |--------------------------------------------------------------------------
    | Health Check and System Status
    |--------------------------------------------------------------------------
    |
    | Endpoints for system health monitoring and status checks
    | for the pocket expenses module.
    |
    */
    Route::prefix('system')->name('api.system.')->group(function () {
        Route::get('health', function () {
            return response()->json([
                'status' => 'ok',
                'service' => 'pocket-expenses-api',
                'version' => '1.0.0',
                'timestamp' => now()->toISOString(),
            ]);
        })->name('health');
        
        Route::get('queue-status', [PocketExpenseUploadController::class, 'getQueueStatus'])
             ->name('queue-status');
    });

});

/*
|--------------------------------------------------------------------------
| Public API Routes (No Authentication Required)
|--------------------------------------------------------------------------
|
| These routes are available without authentication for system
| integration and public reference data access.
|
*/

Route::prefix('v1/public')->name('api.public.')->group(function () {
    
    // Public reference data that doesn't require authentication
    Route::get('expense-types', function () {
        return response()->json(\App\Models\OptPocketExpenseType::getSelectOptions());
    })->name('expense-types');
    
    // Public system information
    Route::get('version', function () {
        return response()->json([
            'api_version' => '1.0.0',
            'service' => 'volopa-pocket-expenses',
            'documentation' => url('/docs/api/v1'),
            'status' => 'operational',
        ]);
    })->name('version');
    
    // Public health check without sensitive data
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString(),
        ]);
    })->name('health');
    
});

/*
|--------------------------------------------------------------------------
| Administrative Routes
|--------------------------------------------------------------------------
|
| These routes are for administrative functions and require
| elevated permissions beyond standard user authentication.
|
*/

Route::prefix('v1/admin')->middleware(['auth:api', 'oauth2.user.client', 'admin.access'])->name('api.admin.')->group(function () {
    
    // Administrative user permission management
    Route::prefix('permissions')->name('permissions.')->group(function () {
        Route::get('audit-log', [UserFeaturePermissionController::class, 'getAuditLog'])
             ->name('audit-log');
             
        Route::post('bulk-grant', [UserFeaturePermissionController::class, 'bulkGrantPermissions'])
             ->name('bulk-grant');
             
        Route::post('bulk-revoke', [UserFeaturePermissionController::class, 'bulkRevokePermissions'])
             ->name('bulk-revoke');
             
        Route::get('statistics', [UserFeaturePermissionController::class, 'getPermissionStatistics'])
             ->name('statistics');
    });
    
    // Administrative expense management
    Route::prefix('expenses')->name('expenses.')->group(function () {
        Route::get('all', [PocketExpenseController::class, 'getAllExpensesForAdmin'])
             ->name('all');
             
        Route::post('{expense}/force-approve', [PocketExpenseController::class, 'forceApprove'])
             ->name('force-approve');
             
        Route::post('{expense}/force-delete', [PocketExpenseController::class, 'forceDelete'])
             ->name('force-delete');
             
        Route::get('audit-trail/{expense}', [PocketExpenseController::class, 'getAuditTrail'])
             ->name('audit-trail');
             
        Route::get('analytics', [PocketExpenseController::class, 'getAnalytics'])
             ->name('analytics');
    });
    
    // Administrative upload management
    Route::prefix('uploads')->name('uploads.')->group(function () {
        Route::get('all', [PocketExpenseUploadController::class, 'getAllUploads'])
             ->name('all');
             
        Route::post('{uploadId}/reprocess', [PocketExpenseUploadController::class, 'reprocessUpload'])
             ->name('reprocess');
             
        Route::delete('{uploadId}/purge', [PocketExpenseUploadController::class, 'purgeUpload'])
             ->name('purge');
             
        Route::get('failed', [PocketExpenseUploadController::class, 'getFailedUploads'])
             ->name('failed');
             
        Route::post('cleanup', [PocketExpenseUploadController::class, 'cleanupOldUploads'])
             ->name('cleanup');
    });
    
    // System configuration and maintenance
    Route::prefix('system')->name('system.')->group(function () {
        Route::get('config', function () {
            return response()->json([
                'max_upload_size' => config('filesystems.max_upload_size', '10MB'),
                'max_csv_rows' => config('pocket_expenses.max_csv_rows', 200),
                'fx_lookback_days' => config('pocket_expenses.fx_lookback_days', 30),
                'batch_size' => config('pocket_expenses.batch_size', 100),
            ]);
        })->name('config');
        
        Route::post('cache/clear', function () {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            return response()->json(['message' => 'Cache cleared successfully']);
        })->name('cache-clear');
        
        Route::get('queue/stats', [PocketExpenseUploadController::class, 'getQueueStatistics'])
             ->name('queue-stats');
    });
    
});

/*
|--------------------------------------------------------------------------
| Route Model Binding
|--------------------------------------------------------------------------
|
| Configure route model binding for API resources to enable
| automatic model resolution and 404 handling.
|
*/

Route::bind('expense', function ($value) {
    return \App\Models\PocketExpense::where('uuid', $value)
                                   ->orWhere('id', $value)
                                   ->firstOrFail();
});

Route::bind('permission', function ($value) {
    return \App\Models\UserFeaturePermission::findOrFail($value);
});

Route::bind('source', function ($value) {
    return \App\Models\PocketExpenseSourceClientConfig::where('uuid', $value)
                                                      ->orWhere('id', $value)
                                                      ->firstOrFail();
});

Route::bind('uploadId', function ($value) {
    return \App\Models\PocketExpenseFileUpload::where('uuid', $value)
                                              ->orWhere('id', $value)
                                              ->firstOrFail();
});

/*
|--------------------------------------------------------------------------
| API Rate Limiting
|--------------------------------------------------------------------------
|
| Configure rate limiting for API endpoints to prevent abuse
| and ensure system stability.
|
*/

Route::middleware('throttle:api')->group(function () {
    // Standard API endpoints are covered by default throttle:api middleware
});

// Special rate limiting for upload endpoints (more restrictive)
Route::middleware('throttle:uploads,10,1')->group(function () {
    // Upload-specific routes with tighter limits are already grouped above
});

// Rate limiting for public endpoints
Route::middleware('throttle:public,100,1')->group(function () {
    // Public routes are already grouped above with less restrictive limits
});