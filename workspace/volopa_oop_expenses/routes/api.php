## Code: routes/api.php

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\UserFeaturePermissionController;
use App\Http\Controllers\Api\V1\PocketExpenseController;
use App\Http\Controllers\Api\V1\PocketExpenseUploadController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
| All routes require OAuth2 authentication via middleware and are scoped
| to authenticated user's client context for multi-tenant data isolation.
| Routes follow REST conventions with proper HTTP status codes and 
| structured error responses using API Resources.
*/

/*
|--------------------------------------------------------------------------
| V1 API Routes with OAuth2 Authentication
|--------------------------------------------------------------------------
|
| All V1 routes are protected by OAuth2 middleware and follow Laravel
| REST conventions. Routes are versioned under /v1 prefix with exception
| of upload routes which follow /api/uploads pattern per specification.
*/

Route::prefix('v1')->group(function () {
    
    /*
    |--------------------------------------------------------------------------
    | User Feature Permission Management Routes
    |--------------------------------------------------------------------------
    |
    | Routes for delegation-based RBAC system managing user feature permissions.
    | Implements role hierarchy with Primary Admin, Admin, Business User, Card User.
    | 
    | Business Rules:
    | - Primary Administrator has full access to all users' permissions
    | - Administrator requires explicit delegation to manage other users' permissions
    | - Permission delegation can be granted by Primary Admin to any user regardless of role
    | - Admin can only grant access to their own managed users, not all users
    | - Revoked users fall back to Primary Administrator management until reassigned
    */
    Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
        
        // List user feature permissions with filtering support
        // GET /api/v1/user-feature-permissions?user_id=1&feature_id=1&is_enabled=true
        Route::get('user-feature-permissions', [UserFeaturePermissionController::class, 'index'])
            ->name('api.v1.user-feature-permissions.index');
        
        // Grant new user feature permission with delegation management
        // POST /api/v1/user-feature-permissions
        // Body: {"user_id": 1, "feature_id": 1, "manager_user_id": 2}
        Route::post('user-feature-permissions', [UserFeaturePermissionController::class, 'store'])
            ->name('api.v1.user-feature-permissions.store');
        
        // Revoke specific user feature permission
        // DELETE /api/v1/user-feature-permissions/{id}
        // Body: {"reason": "No longer needed", "reassign_to_primary_admin": false}
        Route::delete('user-feature-permissions/{id}', [UserFeaturePermissionController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->name('api.v1.user-feature-permissions.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Pocket Expense CRUD Routes
    |--------------------------------------------------------------------------
    |
    | Routes for single expense management with real-time FX conversion,
    | metadata support, and approval workflow. All expenses scoped to 
    | authenticated user's client context for multi-tenancy.
    |
    | Business Rules:
    | - All queries and mutations must be scoped by client_id for multi-tenancy
    | - user_id must match authenticated user (server-side validation)
    | - Target entities (expense_user_id) must belong to the same client_id
    | - Only Primary Administrator has full access to all users' expenses by default
    | - Administrator requires explicit delegation to manage other users' expenses
    | - Business User and Card User cannot approve expenses even with management rights
    | - Backend must recalculate FX on save, do not trust frontend-only values
    | - Date validation: expenses cannot be older than 3 years from current date
    | - Amount sign determined by expense type: Refund = positive, others = negative
    */
    Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
        
        // List pocket expenses with filtering and pagination
        // GET /api/v1/pocket-expenses?status=draft&date_from=2024-01-01&date_to=2024-12-31
        Route::get('pocket-expenses', [PocketExpenseController::class, 'index'])
            ->name('api.v1.pocket-expenses.index');
        
        // Create new pocket expense with metadata and FX conversion
        // POST /api/v1/pocket-expenses
        // Body: {"date": "2024-01-01", "merchant_name": "Test Merchant", "expense_type": 1, "currency": "USD", "amount": 100.00}
        Route::post('pocket-expenses', [PocketExpenseController::class, 'store'])
            ->name('api.v1.pocket-expenses.store');
        
        // Get single pocket expense with relationships
        // GET /api/v1/pocket-expenses/{id}
        Route::get('pocket-expenses/{id}', [PocketExpenseController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('api.v1.pocket-expenses.show');
        
        // Update pocket expense (only draft and submitted status allowed)
        // PUT /api/v1/pocket-expenses/{id}
        // Body: {"merchant_name": "Updated Merchant", "amount": 150.00}
        Route::put('pocket-expenses/{id}', [PocketExpenseController::class, 'update'])
            ->where('id', '[0-9]+')
            ->name('api.v1.pocket-expenses.update');
        
        // Soft delete pocket expense (cannot delete approved expenses)
        // DELETE /api/v1/pocket-expenses/{id}
        Route::delete('pocket-expenses/{id}', [PocketExpenseController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->name('api.v1.pocket-expenses.destroy');
        
        // Approve pocket expense (only submitted status, role-based authorization)
        // POST /api/v1/pocket-expenses/{id}/approve
        // Body: {} (empty body, approval is action-based)
        Route::post('pocket-expenses/{id}/approve', [PocketExpenseController::class, 'approve'])
            ->where('id', '[0-9]+')
            ->name('api.v1.pocket-expenses.approve');
        
        // Real-time FX conversion for expense amounts
        // POST /api/v1/pocket-expenses/convert-fx
        // Body: {"amount": 100.00, "from_currency": "USD", "expense_date": "2024-01-01"}
        Route::post('pocket-expenses/convert-fx', [PocketExpenseController::class, 'convertFX'])
            ->name('api.v1.pocket-expenses.convert-fx');
    });
});

/*
|--------------------------------------------------------------------------
| CSV Upload Routes (Non-versioned per specification)
|--------------------------------------------------------------------------
|
| Routes for CSV batch upload with synchronous validation and asynchronous
| background processing using Laravel queues. These routes follow the
| /api/uploads pattern as specified in the requirements.
|
| Business Rules:
| - Maximum 200 rows per CSV file for batch upload processing
| - All-or-nothing validation: if any CSV row fails validation, no expense records are created
| - Header row mandatory in CSV and must exactly match required column names
| - Date format for CSV: DD/MM/YYYY (DD-MM-YYYY in template)
| - Currency Code must be 3-letter ISO format and validated against platform list
| - VAT percentage must be numeric between 0-100 with % sign stripped
| - Expense source must match configured sources for client including global 'Other'
| - Source Note required when expense source equals 'Other'
| - Queue job processing: sync expenses in batches of 100 records
| - Upload status progression: uploaded → validation_passed/validation_failed → processing → completed/failed
| - Background job must update upload status and notify user on completion
*/
Route::prefix('uploads