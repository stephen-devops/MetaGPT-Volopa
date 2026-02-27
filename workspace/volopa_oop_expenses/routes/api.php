## Code: routes/api.php

```php
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

// Health check endpoint
Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Volopa OOP Expenses API is running',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
    ]);
});

// Health check endpoint for monitoring
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0',
        'service' => 'volopa-oop-expenses-api',
    ]);
});

/*
|--------------------------------------------------------------------------
| V1 API Routes - User Feature Permission Management
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->name('api.v1.')->middleware(['auth:api', 'throttle:api'])->group(function () {
    
    // User Feature Permission Routes
    Route::prefix('user-feature-permissions')->name('user-feature-permissions.')->group(function () {
        
        // GET /api/v1/user-feature-permissions - List user feature permissions
        Route::get('/', [UserFeaturePermissionController::class, 'index'])
             ->name('index');
        
        // POST /api/v1/user-feature-permissions - Grant user feature permission
        Route::post('/', [UserFeaturePermissionController::class, 'store'])
             ->name('store');
        
        // GET /api/v1/user-feature-permissions/{userFeaturePermission} - Get specific permission
        Route::get('/{userFeaturePermission}', [UserFeaturePermissionController::class, 'show'])
             ->name('show');
        
        // PUT /api/v1/user-feature-permissions/{userFeaturePermission} - Update permission
        Route::put('/{userFeaturePermission}', [UserFeaturePermissionController::class, 'update'])
             ->name('update');
        
        // DELETE /api/v1/user-feature-permissions/{userFeaturePermission} - Revoke permission
        Route::delete('/{userFeaturePermission}', [UserFeaturePermissionController::class, 'destroy'])
             ->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | V1 API Routes - Pocket Expense Management
    |--------------------------------------------------------------------------
    */
    
    // Pocket Expense Routes
    Route::prefix('pocket-expenses')->name('pocket-expenses.')->group(function () {
        
        // GET /api/v1/pocket-expenses - List user expenses
        Route::get('/', [PocketExpenseController::class, 'index'])
             ->name('index');
        
        // POST /api/v1/pocket-expenses - Create new expense
        Route::post('/', [PocketExpenseController::class, 'store'])
             ->name('store');
        
        // GET /api/v1/pocket-expenses/{pocketExpense} - Get specific expense
        Route::get('/{pocketExpense}', [PocketExpenseController::class, 'show'])
             ->name('show');
        
        // PUT /api/v1/pocket-expenses/{pocketExpense} - Update expense
        Route::put('/{pocketExpense}', [PocketExpenseController::class, 'update'])
             ->name('update');
        
        // DELETE /api/v1/pocket-expenses/{pocketExpense} - Delete expense
        Route::delete('/{pocketExpense}', [PocketExpenseController::class, 'destroy'])
             ->name('destroy');
        
        // POST /api/v1/pocket-expenses/{pocketExpense}/approve - Approve expense
        Route::post('/{pocketExpense}/approve', [PocketExpenseController::class, 'approve'])
             ->name('approve');
        
        // POST /api/v1/pocket-expenses/{pocketExpense}/reject - Reject expense
        Route::post('/{pocketExpense}/reject', [PocketExpenseController::class, 'reject'])
             ->name('reject');
        
        // POST /api/v1/pocket-expenses/{pocketExpense}/submit - Submit expense for approval
        Route::post('/{pocketExpense}/submit', [PocketExpenseController::class, 'submit'])
             ->name('submit');
        
        // GET /api/v1/pocket-expenses/{pocketExpense}/fx-conversion - Get FX conversion details
        Route::get('/{pocketExpense}/fx-conversion', [PocketExpenseController::class, 'getFXConversion'])
             ->name('fx-conversion');
    });

    /*
    |--------------------------------------------------------------------------
    | V1 API Routes - Expense Source Configuration
    |--------------------------------------------------------------------------
    */
    
    // Expense Source Configuration Routes
    Route::prefix('expense-sources')->name('expense-sources.')->group(function () {
        
        // GET /api/v1/expense-sources - List available expense sources for client
        Route::get('/', [PocketExpenseController::class, 'getExpenseSources'])
             ->name('index');
        
        // POST /api/v1/expense-sources - Create new expense source
        Route::post('/', [PocketExpenseController::class, 'createExpenseSource'])
             ->name('store');
        
        // PUT /api/v1/expense-sources/{sourceId} - Update expense source
        Route::put('/{sourceId}', [PocketExpenseController::class, 'updateExpenseSource'])
             ->name('update');
        
        // DELETE /api/v1/expense-sources/{sourceId} - Delete expense source (soft delete)
        Route::delete('/{sourceId}', [PocketExpenseController::class, 'deleteExpenseSource'])
             ->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | V1 API Routes - Reference Data
    |--------------------------------------------------------------------------
    */
    
    // Reference Data Routes
    Route::prefix('reference')->name('reference.')->group(function () {
        
        // GET /api/v1/reference/expense-types - Get available expense types
        Route::get('/expense-types', [PocketExpenseController::class, 'getExpenseTypes'])
             ->name('expense-types');
        
        // GET /api/v1/reference/currencies - Get supported currencies
        Route::get('/currencies', [PocketExpenseController::class, 'getCurrencies'])
             ->name('currencies');
        
        // GET /api/v1/reference/transaction-categories - Get transaction categories
        Route::get('/transaction-categories', [PocketExpenseController::class, 'getTransactionCategories'])
             ->name('transaction-categories');
        
        // GET /api/v1/reference/tracking-codes - Get tracking codes
        Route::get('/tracking-codes', [PocketExpenseController::class, 'getTrackingCodes'])
             ->name('tracking-codes');
        
        // GET /api/v1/reference/projects - Get configurable projects
        Route::get('/projects', [PocketExpenseController::class, 'getProjects'])
             ->name('projects');
    });
});

/*
|--------------------------------------------------------------------------
| CSV Upload Routes - Separate from versioned API
|--------------------------------------------------------------------------
*/

Route::prefix('uploads')->name('api.uploads.')->middleware(['auth:api', 'throttle:uploads'])->group(function () {
    
    // Pocket Expense CSV Upload Routes
    Route::prefix('pocket-expense')->name('pocket-expense.')->group(function () {
        
        // CSV Upload Routes
        Route::prefix('csv')->name('csv.')->group(function () {
            
            // POST /api/uploads/pocket-expense/csv - Upload CSV file for batch expense creation
            Route::post