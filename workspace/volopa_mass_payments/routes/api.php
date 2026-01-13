## Code: routes/api.php

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MassPaymentFileController;
use App\Http\Controllers\Api\V1\PaymentInstructionController;
use App\Http\Controllers\Api\V1\TemplateController;

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

// Health check route (no authentication required)
Route::get('/health', function (Request $request) {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'version' => config('app.version', '1.0.0'),
        'environment' => app()->environment(),
        'service' => 'Volopa Mass Payments API'
    ]);
})->name('api.health');

// API v1 routes with versioning, authentication and rate limiting
Route::prefix('v1')->name('api.v1.')->group(function () {
    
    // Apply authentication middleware to all v1 routes
    Route::middleware(['auth:api', 'client.scope'])->group(function () {
        
        // Mass Payment Files API Routes
        Route::prefix('mass-payment-files')->name('mass-payment-files.')->group(function () {
            
            // List mass payment files with filtering and pagination
            Route::get('/', [MassPaymentFileController::class, 'index'])
                ->middleware(['throttle:60,1'])
                ->name('index');
            
            // Upload new mass payment file
            Route::post('/', [MassPaymentFileController::class, 'store'])
                ->middleware(['throttle:10,1'])
                ->name('store');
            
            // Get specific mass payment file details
            Route::get('/{massPaymentFile}', [MassPaymentFileController::class, 'show'])
                ->middleware(['throttle:120,1'])
                ->name('show');
            
            // Delete mass payment file (only if in draft/failed status)
            Route::delete('/{massPaymentFile}', [MassPaymentFileController::class, 'destroy'])
                ->middleware(['throttle:20,1'])
                ->name('destroy');
            
            // Approve mass payment file for processing
            Route::post('/{massPaymentFile}/approve', [MassPaymentFileController::class, 'approve'])
                ->middleware(['throttle:30,1'])
                ->name('approve');
            
            // Cancel mass payment file
            Route::post('/{massPaymentFile}/cancel', [MassPaymentFileController::class, 'cancel'])
                ->middleware(['throttle:20,1'])
                ->name('cancel');
            
            // Download original uploaded file
            Route::get('/{massPaymentFile}/download', [MassPaymentFileController::class, 'download'])
                ->middleware(['throttle:30,1'])
                ->name('download');
            
            // Get validation errors for a file
            Route::get('/{massPaymentFile}/validation-errors', [MassPaymentFileController::class, 'getValidationErrors'])
                ->middleware(['throttle:60,1'])
                ->name('validation-errors');
            
            // Resubmit failed mass payment file
            Route::post('/{massPaymentFile}/resubmit', [MassPaymentFileController::class, 'resubmit'])
                ->middleware(['throttle:10,1'])
                ->name('resubmit');
            
            // Export processed file results
            Route::get('/{massPaymentFile}/export', [MassPaymentFileController::class, 'export'])
                ->middleware(['throttle:20,1'])
                ->name('export');
            
            // Get processing status and progress
            Route::get('/{massPaymentFile}/status', [MassPaymentFileController::class, 'getStatus'])
                ->middleware(['throttle:120,1'])
                ->name('status');
            
            // Get audit trail for file
            Route::get('/{massPaymentFile}/audit-log', [MassPaymentFileController::class, 'getAuditLog'])
                ->middleware(['throttle:30,1', 'permission:mass_payments.audit'])
                ->name('audit-log');
            
            // Force approve file (admin only)
            Route::post('/{massPaymentFile}/force-approve', [MassPaymentFileController::class, 'forceApprove'])
                ->middleware(['throttle:10,1', 'permission:mass_payments.force_approve'])
                ->name('force-approve');
            
            // Bulk operations on multiple files
            Route::post('/bulk/cancel', [MassPaymentFileController::class, 'bulkCancel'])
                ->middleware(['throttle:10,1', 'permission:mass_payments.bulk_operations'])
                ->name('bulk.cancel');
            
            Route::post('/bulk/delete', [MassPaymentFileController::class, 'bulkDelete'])
                ->middleware(['throttle:5,1', 'permission:mass_payments.bulk_operations'])
                ->name('bulk.delete');
            
            Route::post('/bulk/export', [MassPaymentFileController::class, 'bulkExport'])
                ->middleware(['throttle:5,1', 'permission:mass_payments.bulk_operations'])
                ->name('bulk.export');
        });
        
        // Payment Instructions API Routes
        Route::prefix('payment-instructions')->name('payment-instructions.')->group(function () {
            
            // List payment instructions with filtering and pagination
            Route::get('/', [PaymentInstructionController::class, 'index'])
                ->middleware(['throttle:120,1'])
                ->name('index');
            
            // Create individual payment instruction
            Route::post('/', [PaymentInstructionController::class, 'store'])
                ->middleware(['throttle:30,1'])
                ->name('store');
            
            // Get specific payment instruction details
            Route::get('/{paymentInstruction}', [PaymentInstructionController::class, 'show'])
                ->middleware(['throttle:120,1'])
                ->name('show');
            
            // Update payment instruction (only if pending)
            Route::put('/{paymentInstruction}', [PaymentInstructionController::class, 'update'])
                ->middleware(['throttle:30,1'])
                ->name('update');
            
            // Delete payment instruction (only if pending)
            Route::delete('/{paymentInstruction}', [PaymentInstructionController::class, 'destroy'])
                ->middleware(['throttle:20,1'])
                ->name('destroy');
            
            // Retry failed payment instruction
            Route::post('/{paymentInstruction}/retry', [PaymentInstructionController::class, 'retry'])
                ->middleware(['throttle:10,1'])
                ->name('retry');
            
            // Cancel payment instruction
            Route::post('/{paymentInstruction}/cancel', [PaymentInstructionController::class, 'cancel'])
                ->middleware(['throttle:20,1'])
                ->name('cancel');
            
            // Get payment instruction status and tracking info
            Route::get('/{paymentInstruction}/status', [PaymentInstructionController::class, 'getStatus'])
                ->middleware(['throttle:120,1'])
                ->name('status');
            
            // Get payment instruction receipt/confirmation
            Route::get('/{paymentInstruction}/receipt', [PaymentInstructionController::class, 'getReceipt'])
                ->middleware(['throttle:30,1'])
                ->name('receipt');
            
            // Get payment instruction audit trail
            Route::get('/{paymentInstruction}/audit-log', [PaymentInstructionController::class, 'getAuditLog'])
                ->middleware(['throttle:30,1', 'permission:payment_instructions.audit'])
                ->name('audit-log');
            
            // Bulk operations on multiple payment instructions
            Route::post('/bulk/cancel', [PaymentInstructionController::class, 'bulkCancel'])
                ->middleware(['throttle:10,1', 'permission:payment_instructions.bulk_operations'])
                ->name('bulk.cancel');
            
            Route::post('/bulk/retry', [PaymentInstructionController::class, 'bulkRetry'])
                ->middleware(['throttle:5,1', 'permission:payment_instructions.bulk_operations'])
                ->name('bulk.retry');
            
            Route::post('/bulk/export', [PaymentInstructionController::class, 'bulkExport'])
                ->middleware(['throttle:5,1', 'permission:payment_instructions.bulk_operations'])
                ->name('bulk.export');
            
            // Search payment instructions
            Route::get('/search/{query}', [PaymentInstructionController::class, 'search'])
                ->middleware(['throttle:60,1'])
                ->name('search');
        });
        
        // Template Generation API Routes
        Route::prefix('templates')->name('templates.')->group(function () {
            
            // Download CSV template for mass payments
            Route::get('/download', [TemplateController::class, 'download'])
                ->middleware(['throttle:30,1'])
                ->name('download');
            
            // Get available template types
            Route::get('/types', [TemplateController::class, 'getTemplateTypes'])
                ->middleware(['throttle:60,1'])
                ->name('types');
            
            // Get supported currencies for templates
            Route::get('/currencies', [TemplateController::class, 'getSupportedCurrencies'])
                ->middleware(['throttle:60,1'])
                ->name('currencies');
            
            // Get template field definitions for specific currency
            Route::get('/fields', [TemplateController::class, 'getTemplateFields'])
                ->middleware(['throttle:60,1'])
                ->name('fields');
            
            // Download template with pre-populated beneficiary data
            Route::post('/download-with-beneficiaries', [TemplateController::class, 'downloadWithBeneficiaries'])
                ->middleware(['throttle:20,1'])
                ->name('download-with-beneficiaries');
            
            // Get template validation rules for currency
            Route::get('/validation-rules', [TemplateController::class, 'getValidationRules'])
                ->middleware(['throttle:60,1'])
                ->name('validation-rules');
            
            // Preview template structure without download
            Route::get('/preview', [TemplateController::class, 'preview'])
                ->middleware(['throttle:60,1'])
                ->name('preview');
        });
        
        // Dashboard and Statistics Routes
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            
            // Get dashboard summary statistics
            Route::get('/summary', [MassPaymentFileController::class, 'getDashboardSummary'])
                ->middleware(['throttle:60,1'])
                ->name('summary');
            
            // Get recent activity feed
            Route::get('/activity', [MassPaymentFileController::class, 'getRecentActivity'])
                ->middleware(['throttle:60,1'])
                ->name('activity');
            
            // Get payment statistics and analytics
            Route::get('/statistics', [PaymentInstructionController::class, 'getStatistics'])
                ->middleware(['throttle:30,1'])
                ->name('statistics');
            
            // Get pending approvals count
            Route::get('/pending-approvals', [MassPaymentFileController::class, 'getPendingApprovals'])
                ->middleware(['throttle:120,1'])
                ->name('pending-approvals');
            
            // Get processing status overview
            Route::get('/processing-status', [MassPaymentFileController::class, 'getProcessingStatus'])
                ->middleware(['throttle:60,1'])
                ->name('processing-status');
        });
        
        // Reporting and Export Routes
        Route::prefix('reports')->name('reports.')->group(function () {
            
            // Generate payments report
            Route::post('/payments', [PaymentInstructionController::class, 'generatePaymentsReport'])
                ->middleware(['throttle:10,1', 'permission:reports.generate'])
                ->name('payments');
            
            // Generate files report
            Route::post('/files', [MassPaymentFileController::class, 'generateFilesReport'])
                ->middleware(['throttle:10,1', 'permission:reports.generate'])
                ->name('files');
            
            // Generate compliance report
            Route::post('/compliance', [MassPaymentFileController::class, 'generateComplianceReport'])
                ->middleware(['throttle:5,1', 'permission:reports.compliance'])
                ->name('compliance');
            
            // Get available report templates
            Route::get('/templates', [TemplateController::class, 'getReportTemplates'])
                ->middleware(['throttle:30,1'])
                ->name('templates');
            
            // Download generated report
            Route::get('/{reportId}/download', [MassPaymentFileController::class, 'downloadReport'])
                ->middleware(['throttle:30,1'])
                ->name('download');
            
            // Get report generation status
            Route::get('/{reportId}/status', [MassPaymentFileController::class, 'getReportStatus'])
                ->middleware(['throttle:60,1'])
                ->name('status');
        });
        
        // System Information and Configuration Routes
        Route::prefix('system')->name('system.')->group(function () {
            
            // Get supported currencies and limits
            Route::get('/currencies', [TemplateController::class, 'getSystemCurrencies'])
                ->middleware(['throttle:60,1'])
                ->name('currencies');
            
            // Get payment method configurations
            Route::get('/payment-methods', [PaymentInstructionController::class, 'getPaymentMethods'])
                ->middleware(['throttle:60,1'])
                ->name('payment-methods');
            
            // Get user permissions and capabilities
            Route::get('/permissions', function (Request $request) {
                $user = $request->user();
                return response()->json([
                    'success' => true,
                    'data' => [
                        'user_id' => $user->id,
                        'client_id' => $user->client_id,
                        'permissions' => $user->getAllPermissions()->pluck('name'),
                        'roles' => $user->getRoleNames(),
                        'capabilities' => [
                            'can_create_mass_payments' => $user->can('create', \App\Models\MassPaymentFile::class),
                            'can_approve_payments' => $user->hasRole(['admin', 'finance_manager', 'approver']),
                            'can_bulk_operations' => $user->hasPermission('mass_payments.bulk_operations'),
                            'can_view_compliance' => $user->hasPermission('mass_payments.compliance'),
                            'can_generate_reports' => $user->hasPermission('reports.generate')
                        ]
                    ],
                    'meta' => [
                        'timestamp' => now()->toISOString()
                    ]
                ]);
            })
                ->middleware(['throttle:60,1'])
                ->name('permissions');
            
            // Get client configuration and limits
            Route::get('/client-config', [MassPaymentFileController::class, 'getClientConfig'])
                ->middleware(['throttle:30,1'])
                ->name('client-config');
            
            // Get system status and health metrics
            Route::get('/status', function (Request $request) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'api_version' => 'v1',
                        'system_status' => 'operational',
                        'queue_status' => 'healthy',
                        'database_status' => 'connected',
                        'cache_status' => 'operational',
                        'maintenance_mode' => app()->isDownForMaintenance(),
                        'features' => [
                            'mass_payments' => true,
                            'individual_payments' => true,
                            'bulk_operations' => true,
                            'compliance_reporting' => true,
                            'real_time_notifications' => true
                        ]
                    ],
                    'meta' => [
                        'timestamp' => now()->toISOString(),
                        