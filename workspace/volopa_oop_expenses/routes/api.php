<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\UserFeaturePermissionController;
use App\Http\Controllers\Api\V1\PocketExpenseController;
use App\Http\Controllers\Api\V1\PocketExpenseSourceController;
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

/*
|--------------------------------------------------------------------------
| OOP Expense Management API Routes
|--------------------------------------------------------------------------
|
| All routes use Oauth2UserClient middleware for authentication as per
| system constraints. Controllers MUST NOT call $this->middleware() in
| constructors as Oauth2UserClient is applied at route group level only.
|
*/

// API v1 routes with OAuth2 authentication
Route::middleware(['Oauth2UserClient'])->group(function () {
    
    // User Feature Permission Management Routes
    Route::prefix('v1')->group(function () {
        Route::apiResource('user-feature-permissions', UserFeaturePermissionController::class)
            ->names([
                'index' => 'api.v1.user-feature-permissions.index',
                'store' => 'api.v1.user-feature-permissions.store',
                'show' => 'api.v1.user-feature-permissions.show',
                'update' => 'api.v1.user-feature-permissions.update',
                'destroy' => 'api.v1.user-feature-permissions.destroy',
            ]);

        // Pocket Expense CRUD Routes
        Route::apiResource('pocket-expenses', PocketExpenseController::class)
            ->names([
                'index' => 'api.v1.pocket-expenses.index',
                'store' => 'api.v1.pocket-expenses.store',
                'show' => 'api.v1.pocket-expenses.show',
                'update' => 'api.v1.pocket-expenses.update',
                'destroy' => 'api.v1.pocket-expenses.destroy',
            ]);

        // Pocket Expense Source Configuration Routes
        Route::apiResource('pocket-expense-sources', PocketExpenseSourceController::class)
            ->names([
                'index' => 'api.v1.pocket-expense-sources.index',
                'store' => 'api.v1.pocket-expense-sources.store',
                'show' => 'api.v1.pocket-expense-sources.show',
                'update' => 'api.v1.pocket-expense-sources.update',
                'destroy' => 'api.v1.pocket-expense-sources.destroy',
            ]);
    });

    // CSV Upload Route (omits /v1 prefix per SD-OOP_Pocket_expense.json spec)
    Route::prefix('uploads')->group(function () {
        Route::post('pocket-expense/csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])
            ->name('api.uploads.pocket-expense.csv');
    });
});

/*
|--------------------------------------------------------------------------
| Health Check Route (if needed)
|--------------------------------------------------------------------------
|
| Basic health check route for API monitoring
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'oop-expense-api'
    ]);
})->name('api.health');