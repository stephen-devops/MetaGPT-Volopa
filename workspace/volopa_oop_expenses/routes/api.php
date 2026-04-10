<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserFeaturePermissionController;
use App\Http\Controllers\Api\PocketExpenseController;
use App\Http\Controllers\Api\PocketExpenseSourceController;
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

Route::middleware(['Oauth2UserClient'])->group(function () {
    
    // Version 1 API routes
    Route::prefix('v1')->group(function () {
        
        // User Feature Permission Management Routes
        Route::prefix('user-feature-permissions')->group(function () {
            Route::get('/', [UserFeaturePermissionController::class, 'index'])->name('api.v1.user-feature-permissions.index');
            Route::post('/', [UserFeaturePermissionController::class, 'store'])->name('api.v1.user-feature-permissions.store');
            Route::get('/{userFeaturePermission}', [UserFeaturePermissionController::class, 'show'])->name('api.v1.user-feature-permissions.show');
            Route::put('/{userFeaturePermission}', [UserFeaturePermissionController::class, 'update'])->name('api.v1.user-feature-permissions.update');
            Route::delete('/{userFeaturePermission}', [UserFeaturePermissionController::class, 'destroy'])->name('api.v1.user-feature-permissions.destroy');
        });
        
        // Pocket Expense CRUD Routes
        Route::prefix('pocket-expenses')->group(function () {
            Route::get('/', [PocketExpenseController::class, 'index'])->name('api.v1.pocket-expenses.index');
            Route::post('/', [PocketExpenseController::class, 'store'])->name('api.v1.pocket-expenses.store');
            Route::get('/{pocketExpense}', [PocketExpenseController::class, 'show'])->name('api.v1.pocket-expenses.show');
            Route::put('/{pocketExpense}', [PocketExpenseController::class, 'update'])->name('api.v1.pocket-expenses.update');
            Route::delete('/{pocketExpense}', [PocketExpenseController::class, 'destroy'])->name('api.v1.pocket-expenses.destroy');
            
            // Expense approval endpoint
            Route::post('/{pocketExpense}/approve', [PocketExpenseController::class, 'approve'])->name('api.v1.pocket-expenses.approve');
        });
        
        // Pocket Expense Source Configuration Routes
        Route::prefix('pocket-expense-sources')->group(function () {
            Route::get('/', [PocketExpenseSourceController::class, 'index'])->name('api.v1.pocket-expense-sources.index');
            Route::post('/', [PocketExpenseSourceController::class, 'store'])->name('api.v1.pocket-expense-sources.store');
            Route::get('/{pocketExpenseSourceClientConfig}', [PocketExpenseSourceController::class, 'show'])->name('api.v1.pocket-expense-sources.show');
            Route::put('/{pocketExpenseSourceClientConfig}', [PocketExpenseSourceController::class, 'update'])->name('api.v1.pocket-expense-sources.update');
            Route::delete('/{pocketExpenseSourceClientConfig}', [PocketExpenseSourceController::class, 'destroy'])->name('api.v1.pocket-expense-sources.destroy');
        });
    });
    
    // CSV Upload Routes (special case - omits /v1 prefix per SD-OOP_Pocket_expense.json spec)
    Route::prefix('uploads')->group(function () {
        Route::prefix('pocket-expense')->group(function () {
            Route::post('/csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])->name('api.uploads.pocket-expense.csv');
            
            // Upload status check endpoint
            Route::get('/status/{uploadId}', [PocketExpenseUploadController::class, 'getUploadStatus'])->name('api.uploads.pocket-expense.status');
        });
    });
});