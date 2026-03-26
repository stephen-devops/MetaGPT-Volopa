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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| OOP Expense API Routes
|--------------------------------------------------------------------------
|
| All routes use OAuth2 authentication via Oauth2UserClient middleware
| Routes follow Laravel API naming conventions with consistent patterns
| Multi-tenant client scoping enforced at controller level
|
*/

// User Feature Permission Management Routes
Route::middleware(['Oauth2UserClient'])->prefix('v1')->group(function () {
    Route::apiResource('user-feature-permissions', UserFeaturePermissionController::class)->names([
        'index' => 'api.v1.user-feature-permissions.index',
        'store' => 'api.v1.user-feature-permissions.store',
        'show' => 'api.v1.user-feature-permissions.show',
        'update' => 'api.v1.user-feature-permissions.update',
        'destroy' => 'api.v1.user-feature-permissions.destroy',
    ]);
});

// Pocket Expense CRUD Routes
Route::middleware(['Oauth2UserClient'])->prefix('v1')->group(function () {
    Route::apiResource('pocket-expenses', PocketExpenseController::class)->names([
        'index' => 'api.v1.pocket-expenses.index',
        'store' => 'api.v1.pocket-expenses.store',
        'show' => 'api.v1.pocket-expenses.show',
        'update' => 'api.v1.pocket-expenses.update',
        'destroy' => 'api.v1.pocket-expenses.destroy',
    ]);
});

// Pocket Expense Source Configuration Routes
Route::middleware(['Oauth2UserClient'])->prefix('v1')->group(function () {
    Route::apiResource('pocket-expense-sources', PocketExpenseSourceController::class)->names([
        'index' => 'api.v1.pocket-expense-sources.index',
        'store' => 'api.v1.pocket-expense-sources.store',
        'show' => 'api.v1.pocket-expense-sources.show',
        'update' => 'api.v1.pocket-expense-sources.update',
        'destroy' => 'api.v1.pocket-expense-sources.destroy',
    ]);
});

// CSV Batch Upload Routes (no /v1 prefix per specification)
Route::middleware(['Oauth2UserClient'])->group(function () {
    // CSV upload endpoint - omits /v1 prefix as specified in API routes context
    Route::post('uploads/pocket-expense/csv', [PocketExpenseUploadController::class, 'uploadPocketExpenseCSV'])->name('api.uploads.pocket-expense.csv');
    
    // Upload status check endpoint
    Route::get('uploads/pocket-expense/{uploadId}/status', [PocketExpenseUploadController::class, 'getUploadStatus'])->name('api.uploads.pocket-expense.status');
});