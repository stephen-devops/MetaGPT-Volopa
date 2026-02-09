## Development Plan

The file to be created for the API routes:

- routes/api.php

## Incremental Change

- routes/api.php: Define API routes with Oauth2UserClient middleware for expense management, CSV upload, user permissions, and FX conversion endpoints

## Code: routes/api.php

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PocketExpenseUploadController;
use App\Http\Controllers\Api\UserPermissionController;
use App\Http\Controllers\Api\FxConversionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Apply middleware to all API routes
Route::middleware(['api', 'Oauth2UserClient'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Expense Management Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing out-of-pocket expenses including CRUD operations,
    | approval workflows, and expense statistics.
    |
    */
    
    Route::prefix('expenses')->name('api.expenses.')->group(function () {
        // Main expense CRUD operations
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::get('/{id}', [ExpenseController::class, 'show'])->name('show')->where('id', '[0-9]+');
        Route::put('/{id}', [ExpenseController::class, 'update'])->name('update')->where('id', '[0-9]+');
        Route::delete('/{id}', [ExpenseController::class, 'destroy'])->name('destroy')->where('id', '[0-9]+');
        
        // Expense approval workflow
        Route::patch('/{id}/approve', [ExpenseController::class, 'approve'])->name('approve')->where('id', '[0-9]+');
        Route::patch('/{id}/reject', [ExpenseController::class, 'reject'])->name('reject')->where('id', '[0-9]+');
        
        // Expense statistics and reporting
        Route::get('/statistics', [ExpenseController::class, 'statistics'])->name('statistics');
        
        // Bulk operations
        Route::post('/bulk/approve', [ExpenseController::class, 'bulkApprove'])->name('bulk.approve');
        Route::post('/bulk/reject', [ExpenseController::class, 'bulkReject'])->name('bulk.reject');
        
        // Expense attachments and receipts
        Route::get('/{id}/receipt', [ExpenseController::class, 'getReceipt'])->name('receipt')->where('id', '[0-9]+');
        Route::post('/{id}/receipt', [ExpenseController::class, 'uploadReceipt'])->name('upload.receipt')->where('id', '[0-9]+');
        Route::delete('/{id}/receipt', [ExpenseController::class, 'deleteReceipt'])->name('delete.receipt')->where('id', '[0-9]+');
    });

    /*
    |--------------------------------------------------------------------------
    | CSV Upload Routes
    |--------------------------------------------------------------------------
    |
    | Routes for handling CSV file uploads for pocket expenses including
    | upload processing, status monitoring, and error management.
    |
    */
    
    Route::prefix('uploads/pocket-expense')->name('api.uploads.pocket-expense.')->group(function () {
        // CSV upload operations
        Route::post('/csv', [PocketExpenseUploadController::class, 'uploadCsv'])->name('csv');
        Route::get('/', [PocketExpenseUploadController::class, 'index'])->name('index');
        Route::get('/{uploadId}', [PocketExpenseUploadController::class, 'getUploadStatus'])->name('status')->where('uploadId', '[0-9]+');
        
        // Upload management
        Route::delete('/{uploadId}', [PocketExpenseUploadController::class, 'deleteUpload'])->name('delete')->where('uploadId', '[0-9]+');
        Route::patch('/{uploadId}/cancel', [PocketExpenseUploadController::class, 'cancelUpload'])->name('cancel')->where('uploadId', '[0-9]+');
        Route::post('/{uploadId}/reprocess', [PocketExpenseUploadController::class, 'reprocessUpload'])->name('reprocess')->where('uploadId', '[0-9]+');
        
        // Error handling and download
        Route::get('/{uploadId}/errors', [PocketExpenseUploadController::class, 'downloadErrors'])->name('download-errors')->where('uploadId', '[0-9]+');
        Route::get('/{uploadId}/details', [PocketExpenseUploadController::class, 'getUploadDetails'])->name('details')->where('uploadId', '[0-9]+');
        
        // Upload data management
        Route::get('/{uploadId}/data', [PocketExpenseUploadController::class, 'getUploadData'])->name('data')->where('uploadId', '[0-9]+');
        Route::get('/{uploadId}/data/{rowId}', [PocketExpenseUploadController::class, 'getUploadRowData'])->name('data.row')->where(['uploadId' => '[0-9]+', 'rowId' => '[0-9]+']);
    });

    /*
    |--------------------------------------------------------------------------
    | User Permission Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing user permissions including granting, revoking,
    | and monitoring permission assignments with hierarchical support.
    |
    */
    
    Route::prefix('permissions')->name('api.permissions.')->group(function () {
        // Permission CRUD operations
        Route::get('/', [UserPermissionController::class, 'index'])->name('index');
        Route::post('/grant', [UserPermissionController::class, 'grantPermission'])->name('grant');
        Route::get('/{id}', [UserPermissionController::class, 'show'])->name('show')->where('id', '[0-9]+');
        Route::put('/{id}', [UserPermissionController::class, 'update'])->name('update')->where('id', '[0-9]+');
        Route::delete('/{id}', [UserPermissionController::class, 'revokePermission'])->name('revoke')->where('id', '[0-9]+');
        
        // Permission status management
        Route::patch('/{id}/enable', [UserPermissionController::class, 'enablePermission'])->name('enable')->where('id', '[0-9]+');
        Route::patch('/{id}/disable', [UserPermissionController::class, 'disablePermission'])->name('disable')->where('id', '[0-9]+');
        Route::patch('/{id}/toggle', [UserPermissionController::class, 'togglePermission'])->name('toggle')->where('id', '[0-9]+');
        
        // Permission manager assignment
        Route::patch('/{id}/manager', [UserPermissionController::class, 'setPermissionManager'])->name('set-manager')->where('id', '[0-9]+');
        Route::delete('/{id}/manager', [UserPermissionController::class, 'removePermissionManager'])->name('remove-manager')->where('id', '[0-9]+');
        
        // Bulk permission operations
        Route::post('/bulk/grant', [UserPermissionController::class, 'bulkGrantPermissions'])->name('bulk.grant');
        Route::post('/bulk/revoke', [UserPermissionController::class, 'bulkRevokePermissions'])->name('bulk.revoke');
        Route::post('/bulk/enable', [UserPermissionController::class, 'bulkEnablePermissions'])->name('bulk.enable');
        Route::post('/bulk/disable', [UserPermissionController::class, 'bulkDisablePermissions'])->name('bulk.disable');
        
        // Permission analytics and reporting
        Route::get('/statistics', [UserPermissionController::class, 'getPermissionStatistics'])->name('statistics');
        Route::get('/audit/{id}', [UserPermissionController::class, 'getPermissionAuditTrail'])->name('audit')->where('id', '[0-9]+');
        Route::get('/usage/{id}', [UserPermissionController::class, 'getPermissionUsageHistory'])->name('usage')->where('id', '[0-9]+');
        
        // Permission templates and management
        Route::get('/templates', [UserPermissionController::class, 'getPermissionTemplates'])->name('templates');
        Route::post('/templates', [UserPermissionController::class, 'createPermissionTemplate'])->name('templates.create');
        Route::post('/templates/{templateId}/apply', [UserPermissionController::class, 'applyPermissionTemplate'])->name('templates.apply')->where('templateId', '[0-9]+');
        
        // Delegation and hierarchy
        Route::get('/granted', [UserPermissionController::class, 'getGrantedPermissions'])->name('granted');
        Route::get('/managed', [UserPermissionController::class, 'getManagedPermissions'])->name('managed');
        Route::get('/hierarchy/{id}', [UserPermissionController::class, 'getPermissionHierarchy'])->name('hierarchy')->where('id', '[0-9]+');
    });

    /*
    |--------------------------------------------------------------------------
    | FX Conversion Routes
    |--------------------------------------------------------------------------
    |
    | Routes for foreign exchange rate conversion including real-time rates,
    | historical data, and currency support information.
    |
    */
    
    Route::prefix('fx')->name('api.fx.')->group(function () {
        // Currency conversion operations
        Route::post('/convert', [FxConversionController::class, 'convert'])->name('convert');
        Route::get('/rates', [FxConversionController::class, 'getRates'])->name('rates');
        
        // Currency information and support
        Route::get('/currencies', [FxConversionController::class, 'getSupportedCurrencies'])->name('currencies');
        Route::get('/currencies/{code}', [FxConversionController::class, 'getCurrencyInfo'])->name('currency.info')->where('code', '[A-Z]{3}');
        
        // Historical data and trends
        Route::get('/history', [FxConversionController::class, 'getHistoricalRates'])->name('history');
        Route::get('/trends', [FxConversionController::class, 'getRateTrends'])->name('trends');
        
        // User conversion history
        Route::get('/conversions', [FxConversionController::class, 'getConversionHistory'])->name('conversions');
        Route::get('/conversions/summary', [FxConversionController::class, 'getConversionSummary'])->name('conversions.summary');
        
        // Rate alerts and notifications
        Route::get('/alerts', [FxConversionController::class, 'getRateAlerts'])->name('alerts');
        Route::post('/alerts', [FxConversionController::class, 'createRateAlert'])->name('alerts.create');
        Route::delete('/alerts/{alertId}', [FxConversionController::class, 'deleteRateAlert'])->name('alerts.delete')->where('alertId', '[0-9]+');
        
        // Cache and data management
        Route::post('/cache/clear', [FxConversionController::class, 'clearCache'])->name('cache.clear');
        Route::get('/cache/status', [FxConversionController::class, 'getCacheStatus'])->name('cache.status');
    });

    /*
    |--------------------------------------------------------------------------
    | Configuration and Reference Data Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing configuration data such as expense sources,
    | expense types, and other reference information.
    |
    */
    
    Route::prefix('config')->name('api.config.')->group(function () {
        // Expense source configuration
        Route::get('/expense-sources', [ExpenseController::class, 'getExpenseSources'])->name('expense-sources');
        Route::post('/expense-sources', [ExpenseController::class, 'createExpenseSource'])->name('expense-sources.create');
        Route::put('/expense-sources/{id}', [ExpenseController::class, 'updateExpenseSource'])->name('expense-sources.update')->where('id', '[0-9]+');
        Route::delete('/expense-sources/{id}', [ExpenseController::class, 'deleteExpenseSource'])->name('expense-sources.delete')->where('id', '[0-9]+');
        
        // Expense type configuration
        Route::get('/expense-types', [ExpenseController::class, 'getExpenseTypes'])->name('expense-types');
        Route::post('/expense-types', [ExpenseController::class, 'createExpenseType'])->name('expense-types.create');
        Route::put('/expense-types/{id}', [ExpenseController::class, 'updateExpenseType'])->name('expense-types.update')->where('id', '[0-9]+');
        Route::delete('/expense-types/{id}', [ExpenseController::class, 'deleteExpenseType'])->name('expense-types.delete')->where('id', '[0-9]+');
        
        // Client configuration
        Route::get('/clients/{clientId}/settings', [ExpenseController::class, 'getClientSettings'])->name('clients.settings')->where('clientId', '[0-9]+');
        Route::put('/clients/{clientId}/settings', [ExpenseController::class, 'updateClientSettings'])->name('clients.settings.update')->where('clientId', '[0-9]+');
        
        // Feature configuration
        Route::get('/features', [UserPermissionController::class, 'getFeatures'])->name('features');
        Route::get('/features/{featureId}', [UserPermissionController::class, 'getFeature'])->name('features.show')->where('featureId', '[0-9]+');
        
        // Validation rules and constraints
        Route::get('/validation-rules', [ExpenseController::class, 'getValidationRules'])->name('validation-rules');
        Route::get('/validation-rules/{context}', [ExpenseController::class, 'getContextValidationRules'])->name('validation-rules.context');
    });

    /*
    |--------------------------------------------------------------------------
    | File Management Routes
    |--------------------------------------------------------------------------
    |
    | Routes for handling file uploads, downloads, and management including
    | receipts, CSV templates, and export operations.
    |
    */
    
    Route::prefix('files')->name('api.files.')->group(function () {
        // File upload and management
        Route::post('/upload', [ExpenseController::class, 'uploadFile'])->name('upload');
        Route::get('/{fileId}', [ExpenseController::class, 'downloadFile'])->name('download')->where('fileId', '[0-9a-f\-]+');
        Route::delete('/{fileId}', [ExpenseController::class, 'deleteFile'])->name('delete')->where('fileId', '[0-9a-f\-]+');
        
        // CSV templates and samples
        Route::get('/templates/expense-csv', [PocketExpenseUploadController::class, 'downloadCsvTemplate'])->name('templates.expense-csv');
        Route::get('/samples/expense-csv', [PocketExpenseUploadController::class, 'downloadCsvSample'])->name('samples.expense-csv');
        
        // Export operations
        Route::post('/export/expenses', [ExpenseController::class, 'exportExpenses'])->name('export.expenses');
        Route::post('/export/permissions', [UserPermissionController::class, 'exportPermissions'])->name('export.permissions');
        Route::get('/export/{exportId}/status', [ExpenseController::class, 'getExportStatus'])->name('export.status')->where('exportId', '[0-9a-f\-]+');
        Route::get('/export/{exportId}/download', [ExpenseController::class, 'downloadExport'])->name('export.download')->where('exportId', '[0-9a-f\-]+');
        
        // File validation and processing
        Route::post('/validate/csv', [PocketExpenseUploadController::class, 'validateCsvFile'])->name('validate.csv');
        Route::post('/preview/csv