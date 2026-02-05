## Code: routes/api.php

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PocketExpenseController;
use App\Http\Controllers\Api\PocketExpenseUploadController;
use App\Http\Controllers\Api\ExpenseSourceController;

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

// Apply Oauth2UserClient middleware to all API routes
Route::middleware(['oauth2.user_client'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Pocket Expense Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing pocket expenses with CRUD operations.
    | All routes require authentication and client context validation.
    |
    */
    Route::prefix('pocket-expenses')->name('pocket-expenses.')->group(function () {
        
        // Main CRUD operations for pocket expenses
        Route::get('/', [PocketExpenseController::class, 'index'])
            ->name('index');
            
        Route::post('/', [PocketExpenseController::class, 'store'])
            ->name('store');
            
        Route::get('/{id}', [PocketExpenseController::class, 'show'])
            ->name('show')
            ->where('id', '[0-9]+');
            
        Route::put('/{id}', [PocketExpenseController::class, 'update'])
            ->name('update')
            ->where('id', '[0-9]+');
            
        Route::delete('/{id}', [PocketExpenseController::class, 'destroy'])
            ->name('destroy')
            ->where('id', '[0-9]+');
            
        // Approval/rejection operations
        Route::post('/{id}/approve', [PocketExpenseController::class, 'approve'])
            ->name('approve')
            ->where('id', '[0-9]+');
            
        Route::post('/{id}/reject', [PocketExpenseController::class, 'reject'])
            ->name('reject')
            ->where('id', '[0-9]+');
            
        // Bulk operations
        Route::post('/bulk/approve', [PocketExpenseController::class, 'bulkApprove'])
            ->name('bulk.approve');
            
        Route::post('/bulk/reject', [PocketExpenseController::class, 'bulkReject'])
            ->name('bulk.reject');
            
        Route::post('/bulk/delete', [PocketExpenseController::class, 'bulkDelete'])
            ->name('bulk.delete');
    });

    /*
    |--------------------------------------------------------------------------
    | Pocket Expense Upload Routes
    |--------------------------------------------------------------------------
    |
    | Routes for CSV file uploads and processing status tracking.
    | Handles batch expense creation from CSV files.
    |
    */
    Route::prefix('uploads/pocket-expense')->name('uploads.pocket-expense.')->group(function () {
        
        // CSV upload endpoint
        Route::post('/csv', [PocketExpenseUploadController::class, 'uploadCSV'])
            ->name('csv');
            
        // Upload status and results
        Route::get('/{uploadId}/status', [PocketExpenseUploadController::class, 'getUploadStatus'])
            ->name('status')
            ->where('uploadId', '[0-9]+');
            
        // Download validation errors report
        Route::get('/{uploadId}/errors', [PocketExpenseUploadController::class, 'downloadErrorReport'])
            ->name('errors')
            ->where('uploadId', '[0-9]+');
            
        // Download processed data report
        Route::get('/{uploadId}/report', [PocketExpenseUploadController::class, 'downloadProcessingReport'])
            ->name('report')
            ->where('uploadId', '[0-9]+');
            
        // Retry failed upload processing
        Route::post('/{uploadId}/retry', [PocketExpenseUploadController::class, 'retryProcessing'])
            ->name('retry')
            ->where('uploadId', '[0-9]+');
            
        // Cancel upload processing
        Route::post('/{uploadId}/cancel', [PocketExpenseUploadController::class, 'cancelProcessing'])
            ->name('cancel')
            ->where('uploadId', '[0-9]+');
            
        // List user uploads
        Route::get('/', [PocketExpenseUploadController::class, 'listUploads'])
            ->name('index');
    });

    /*
    |--------------------------------------------------------------------------
    | Expense Source Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing expense source configurations.
    | Handles both global and client-specific expense sources.
    |
    */
    Route::prefix('expense-sources')->name('expense-sources.')->group(function () {
        
        // List expense sources for client
        Route::get('/', [ExpenseSourceController::class, 'index'])
            ->name('index');
            
        // Get specific expense source
        Route::get('/{id}', [ExpenseSourceController::class, 'show'])
            ->name('show')
            ->where('id', '[0-9]+');
            
        // Create new client-specific expense source
        Route::post('/', [ExpenseSourceController::class, 'store'])
            ->name('store');
            
        // Update expense source
        Route::put('/{id}', [ExpenseSourceController::class, 'update'])
            ->name('update')
            ->where('id', '[0-9]+');
            
        // Delete (soft delete) expense source
        Route::delete('/{id}', [ExpenseSourceController::class, 'destroy'])
            ->name('destroy')
            ->where('id', '[0-9]+');
            
        // Restore soft-deleted expense source
        Route::post('/{id}/restore', [ExpenseSourceController::class, 'restore'])
            ->name('restore')
            ->where('id', '[0-9]+');
            
        // Get expense sources for dropdown (simplified format)
        Route::get('/dropdown/{clientId}', [ExpenseSourceController::class, 'getDropdownOptions'])
            ->name('dropdown')
            ->where('clientId', '[0-9]+');
            
        // Initialize default sources for new client
        Route::post('/initialize/{clientId}', [ExpenseSourceController::class, 'initializeDefaults'])
            ->name('initialize')
            ->where('clientId', '[0-9]+');
            
        // Bulk update sources for client
        Route::put('/bulk/{clientId}', [ExpenseSourceController::class, 'bulkUpdate'])
            ->name('bulk.update')
            ->where('clientId', '[0-9]+');
    });

    /*
    |--------------------------------------------------------------------------
    | Reference Data Routes
    |--------------------------------------------------------------------------
    |
    | Routes for retrieving reference data used in expense creation.
    | Includes expense types, currencies, countries, etc.
    |
    */
    Route::prefix('reference-data')->name('reference-data.')->group(function () {
        
        // Get all expense types
        Route::get('/expense-types', [PocketExpenseController::class, 'getExpenseTypes'])
            ->name('expense-types');
            
        // Get supported currencies
        Route::get('/currencies', [PocketExpenseController::class, 'getCurrencies'])
            ->name('currencies');
            
        // Get supported countries
        Route::get('/countries', [PocketExpenseController::class, 'getCountries'])
            ->name('countries');
            
        // Get transaction categories for client
        Route::get('/categories/{clientId}', [PocketExpenseController::class, 'getCategories'])
            ->name('categories')
            ->where('clientId', '[0-9]+');
            
        // Get tracking codes for client
        Route::get('/tracking-codes/{clientId}', [PocketExpenseController::class, 'getTrackingCodes'])
            ->name('tracking-codes')
            ->where('clientId', '[0-9]+');
            
        // Get projects for client
        Route::get('/projects/{clientId}', [PocketExpenseController::class, 'getProjects'])
            ->name('projects')
            ->where('clientId', '[0-9]+');
            
        // Get additional fields for client
        Route::get('/additional-fields/{clientId}', [PocketExpenseController::class, 'getAdditionalFields'])
            ->name('additional-fields')
            ->where('clientId', '[0-9]+');
    });

    /*
    |--------------------------------------------------------------------------
    | FX Conversion Routes
    |--------------------------------------------------------------------------
    |
    | Routes for foreign exchange rate conversion functionality.
    | Used for real-time currency conversion during expense creation.
    |
    */
    Route::prefix('fx')->name('fx.')->group(function () {
        
        // Get FX rate for specific date and currency pair
        Route::get('/rate', [PocketExpenseController::class, 'getFXRate'])
            ->name('rate');
            
        // Convert amount between currencies
        Route::post('/convert', [PocketExpenseController::class, 'convertAmount'])
            ->name('convert');
            
        // Get client base currency
        Route::get('/base-currency/{clientId}', [PocketExpenseController::class, 'getBaseCurrency'])
            ->name('base-currency')
            ->where('clientId', '[0-9]+');
            
        // Get historical rates for currency pair
        Route::get('/history', [PocketExpenseController::class, 'getFXHistory'])
            ->name('history');
    });

    /*
    |--------------------------------------------------------------------------
    | File Management Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing file attachments and receipts.
    | Handles file uploads, downloads, and management.
    |
    */
    Route::prefix('files')->name('files.')->group(function () {
        
        // Upload receipt file
        Route::post('/receipts', [PocketExpenseController::class, 'uploadReceipt'])
            ->name('receipts.upload');
            
        // Download/view file
        Route::get('/{id}', [PocketExpenseController::class, 'downloadFile'])
            ->name('show')
            ->where('id', '[0-9]+');
            
        // Delete file
        Route::delete('/{id}', [PocketExpenseController::class, 'deleteFile'])
            ->name('destroy')
            ->where('id', '[0-9]+');
            
        // Get file metadata
        Route::get('/{id}/metadata', [PocketExpenseController::class, 'getFileMetadata'])
            ->name('metadata')
            ->where('id', '[0-9]+');
    });

    /*
    |--------------------------------------------------------------------------
    | Dashboard and Analytics Routes
    |--------------------------------------------------------------------------
    |
    | Routes for dashboard data and expense analytics.
    | Provides summary statistics and reporting data.
    |
    */
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        
        // Get expense summary for user/client
        Route::get('/summary', [PocketExpenseController::class, 'getDashboardSummary'])
            ->name('summary');
            
        // Get expense statistics
        Route::get('/statistics', [PocketExpenseController::class, 'getExpenseStatistics'])
            ->name('statistics');
            
        // Get recent activities
        Route::get('/activities', [PocketExpenseController::class, 'getRecentActivities'])
            ->name('activities');
            
        // Get pending approvals count
        Route::get('/pending-approvals', [PocketExpenseController::class, 'getPendingApprovals'])
            ->name('pending-approvals');
            
        // Get expense trends
        Route::get('/trends', [PocketExpenseController::class, 'getExpenseTrends'])
            ->name('trends');
    });

    /*
    |--------------------------------------------------------------------------
    | Export Routes
    |--------------------------------------------------------------------------
    |
    | Routes for exporting expense data in various formats.
    | Supports CSV, Excel, and PDF exports with filtering.
    |
    */
    Route::prefix('exports')->name('exports.')->group(function () {
        
        // Export expenses to CSV
        Route::post('/csv', [PocketExpenseController::class, 'exportToCSV'])
            ->name('csv');
            
        // Export expenses to Excel
        Route::post('/excel', [PocketExpenseController::class, 'exportToExcel'])
            ->name('excel');
            
        // Export expenses to PDF
        Route::post('/pdf', [PocketExpenseController::class, 'exportToPDF'])
            ->name('pdf');
            
        // Get export status
        Route::get('/{exportId}/status', [PocketExpenseController::class, 'getExportStatus'])
            ->name('status')
            ->where('exportId', '[0-9]+');
            
        // Download export file
        Route::get('/{exportId}/download', [PocketExpenseController::class, 'downloadExport'])
            ->name('download')
            ->where('exportId', '[0-9]+');
    });

    /*
    |--------------------------------------------------------------------------
    | Notification Routes
    |--------------------------------------------------------------------------
    |
    | Routes for managing expense-related notifications.
    | Handles notification preferences and delivery.
    |
    */
    Route::prefix('notifications')->name('notifications.')->group(function () {
        
        // Get user notifications
        Route::get('/', [PocketExpenseController::class, 'getNotifications'])
            ->name('index');
            
        // Mark notification as read
        Route::post('/{id}/read', [PocketExpenseController::class, 'markNotificationRead'])
            ->name('read')
            ->where('id', '[0-9]+');
            
        // Mark all notifications as read
        Route::post('/mark-all-read', [PocketExpenseController::class, 'markAllNotificationsRead'])
            ->name('mark-all-read');
            
        // Get notification preferences
        Route::get('/preferences', [PocketExpenseController::class, 'getNotificationPreferences'])
            ->name('preferences');
            
        // Update notification preferences
        Route::put('/preferences', [PocketExpenseController::class, 'updateNotificationPreferences'])
            ->name('preferences.update');
    });

    /*
    |--------------------------------------------------------------------------
    | System Routes
    |--------------------------------------------------------------------------
    |
    | Routes for system-level operations and health checks.
    | Used for monitoring and administrative functions.
    |
    */
    Route::prefix('system')->name('system.')->group(function () {
        
        // Health check
        Route::get('/health', [PocketExpenseController::class, 'healthCheck'])
            ->name('health');
            
        // Get system configuration
        Route::get('/config', [PocketExpenseController::class, 'getSystemConfig'])
            ->name('config');
            
        // Get queue status
        Route::get('/queue-status', [PocketExpenseUploadController::class, 'getQueueStatus'])
            ->name('queue-status');
            
        // Get upload limits
        Route::get('/upload-limits', [PocketExpenseUploadController::class, 'getUploadLimits'])
            ->name('upload-limits');
    });

});

/*
|--------------------------------------------------------------------------
| Public Routes (No Authentication Required)
|--------------------------------------------------------------------------
|
| Routes that can be accessed without authentication.
| Limited to basic system information and health checks.
|
*/

// Basic health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'pocket-expense-api',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
    ]);
})->name('health');

// API version information
Route::get('/version', function () {
    return response()->json([
        'version' => '1.0.0',
        'api_name' => 'Pocket Expense API',
        'laravel_version' => app()->version(),
        'php_version' => phpversion(),
        'environment' => app()->environment(),
        'timestamp' => now()->toISOString(),
    ]);
})->name('version');

/*
|--------------------------------------------------------------------------
| Fallback Routes
|--------------------------------------------------------------------------
|
| Handle undefined API routes with proper error responses.
|
*/

// Catch-all route for undefined endpoints
Route::fallback(function () {
    return response()->json([
        'success