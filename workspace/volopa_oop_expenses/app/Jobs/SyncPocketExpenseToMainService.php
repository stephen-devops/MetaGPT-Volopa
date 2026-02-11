<?php

namespace App\Jobs;

use App\Models\PocketExpense;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;
use Exception;
use Throwable;

class SyncPocketExpenseToMainService implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The pocket expense ID to sync.
     *
     * @var int
     */
    private int $pocketExpenseId;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     *
     * @var int
     */
    public int $timeout = 300; // 5 minutes

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public int $uniqueFor = 300;

    /**
     * Delay between retry attempts in seconds.
     *
     * @var int
     */
    public int $backoff = 30;

    /**
     * Main service API configuration.
     *
     * @var array
     */
    private array $apiConfig = [
        'base_url' => '',
        'timeout' => 30,
        'retry_attempts' => 3,
        'retry_delay' => 5,
        'api_version' => 'v1',
        'authentication_type' => 'bearer',
        'api_token' => '',
        'client_id' => '',
        'client_secret' => '',
    ];

    /**
     * API endpoints configuration.
     *
     * @var array
     */
    private array $endpoints = [
        'expenses' => '/api/v1/expenses',
        'expense_metadata' => '/api/v1/expenses/{expense_id}/metadata',
        'health_check' => '/api/v1/health',
        'auth' => '/api/v1/auth/token',
    ];

    /**
     * Create a new job instance.
     *
     * @param int $pocketExpenseId
     */
    public function __construct(int $pocketExpenseId)
    {
        $this->pocketExpenseId = $pocketExpenseId;
        $this->loadApiConfiguration();
    }

    /**
     * Get the unique ID for the job.
     *
     * @return string
     */
    public function uniqueId(): string
    {
        return 'sync_pocket_expense_to_main_service_' . $this->pocketExpenseId;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws Exception
     */
    public function handle(): void
    {
        try {
            Log::info('Starting pocket expense sync to main service', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'job_id' => $this->job->getJobId() ?? 'unknown',
                'attempt' => $this->attempts()
            ]);

            // Get and validate the expense record
            $expense = $this->getAndValidateExpense();
            if (!$expense) {
                return;
            }

            // Check if main service is available
            if (!$this->checkMainServiceHealth()) {
                throw new Exception('Main service is not available');
            }

            // Authenticate with main service if required
            $authToken = $this->authenticateWithMainService();

            // Prepare expense data for sync
            $expenseData = $this->prepareExpenseDataForSync($expense);

            // Sync expense to main service
            $syncResult = $this->syncExpenseToMainService($expenseData, $authToken);

            // Update local expense with sync information
            $this->updateExpenseWithSyncResult($expense, $syncResult);

            // Sync metadata if expense sync was successful
            if ($syncResult['success'] && $expense->metadata()->exists()) {
                $this->syncExpenseMetadata($expense, $syncResult['main_service_expense_id'], $authToken);
            }

            Log::info('Pocket expense sync to main service completed successfully', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'main_service_expense_id' => $syncResult['main_service_expense_id'] ?? null,
                'sync_status' => $syncResult['status'] ?? 'unknown'
            ]);

        } catch (Exception $e) {
            Log::error('Pocket expense sync to main service failed', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->handleSyncFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        try {
            Log::error('Pocket expense sync job failed permanently', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            $expense = PocketExpense::find($this->pocketExpenseId);
            if ($expense) {
                // Update expense metadata with sync failure information
                $metadata = $expense->metadata ?? [];
                $metadata['sync_status'] = 'failed';
                $metadata['sync_error'] = $exception->getMessage();
                $metadata['sync_attempts'] = $this->attempts();
                $metadata['last_sync_attempt'] = now()->toISOString();
                $metadata['next_sync_attempt'] = null; // Manual retry required

                $expense->update(['metadata' => $metadata]);

                Log::info('Updated expense with sync failure information', [
                    'pocket_expense_id' => $this->pocketExpenseId,
                    'sync_status' => 'failed'
                ]);
            }

        } catch (Exception $e) {
            Log::error('Failed to handle sync job failure', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Load API configuration from config/environment.
     *
     * @return void
     */
    private function loadApiConfiguration(): void
    {
        $this->apiConfig = [
            'base_url' => Config::get('services.main_service.base_url', ''),
            'timeout' => Config::get('services.main_service.timeout', 30),
            'retry_attempts' => Config::get('services.main_service.retry_attempts', 3),
            'retry_delay' => Config::get('services.main_service.retry_delay', 5),
            'api_version' => Config::get('services.main_service.api_version', 'v1'),
            'authentication_type' => Config::get('services.main_service.auth_type', 'bearer'),
            'api_token' => Config::get('services.main_service.api_token', ''),
            'client_id' => Config::get('services.main_service.client_id', ''),
            'client_secret' => Config::get('services.main_service.client_secret', ''),
        ];

        // Validate required configuration
        if (empty($this->apiConfig['base_url'])) {
            Log::warning('Main service base URL is not configured');
        }
    }

    /**
     * Get and validate the expense record.
     *
     * @return PocketExpense|null
     */
    private function getAndValidateExpense(): ?PocketExpense
    {
        $expense = PocketExpense::with(['user', 'client', 'expenseType', 'metadata'])
            ->find($this->pocketExpenseId);

        if (!$expense) {
            Log::error('Pocket expense not found for sync', [
                'pocket_expense_id' => $this->pocketExpenseId
            ]);
            return null;
        }

        // Check if expense is in a syncable state
        if (!$this->isExpenseSyncable($expense)) {
            Log::warning('Expense is not in a syncable state', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'status' => $expense->status,
                'deleted_at' => $expense->deleted_at
            ]);
            return null;
        }

        return $expense;
    }

    /**
     * Check if expense is in a syncable state.
     *
     * @param PocketExpense $expense
     * @return bool
     */
    private function isExpenseSyncable(PocketExpense $expense): bool
    {
        // Don't sync deleted expenses
        if ($expense->trashed()) {
            return false;
        }

        // Don't sync rejected expenses
        if ($expense->status === 'rejected') {
            return false;
        }

        // Check if already synced
        $metadata = $expense->metadata ?? [];
        if (isset($metadata['sync_status']) && $metadata['sync_status'] === 'synced') {
            Log::debug('Expense already synced, skipping', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'main_service_expense_id' => $metadata['main_service_expense_id'] ?? null
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check main service health.
     *
     * @return bool
     */
    private function checkMainServiceHealth(): bool
    {
        if (empty($this->apiConfig['base_url'])) {
            Log::warning('Main service base URL not configured, skipping health check');
            return false;
        }

        try {
            $response = Http::timeout($this->apiConfig['timeout'])
                ->get($this->apiConfig['base_url'] . $this->endpoints['health_check']);

            $isHealthy = $response->successful();

            Log::debug('Main service health check', [
                'base_url' => $this->apiConfig['base_url'],
                'status_code' => $response->status(),
                'is_healthy' => $isHealthy
            ]);

            return $isHealthy;

        } catch (Exception $e) {
            Log::error('Main service health check failed', [
                'base_url' => $this->apiConfig['base_url'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Authenticate with main service.
     *
     * @return string|null
     * @throws Exception
     */
    private function authenticateWithMainService(): ?string
    {
        // If using bearer token authentication
        if ($this->apiConfig['authentication_type'] === 'bearer' && !empty($this->apiConfig['api_token'])) {
            return $this->apiConfig['api_token'];
        }

        // If using client credentials authentication
        if ($this->apiConfig['authentication_type'] === 'client_credentials' && 
            !empty($this->apiConfig['client_id']) && 
            !empty($this->apiConfig['client_secret'])) {
            
            return $this->getClientCredentialsToken();
        }

        // No authentication configured
        Log::debug('No authentication configured for main service');
        return null;
    }

    /**
     * Get client credentials token.
     *
     * @return string
     * @throws Exception
     */
    private function getClientCredentialsToken(): string
    {
        try {
            $response = Http::timeout($this->apiConfig['timeout'])
                ->asForm()
                ->post($this->apiConfig['base_url'] . $this->endpoints['auth'], [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->apiConfig['client_id'],
                    'client_secret' => $this->apiConfig['client_secret'],
                ]);

            if (!$response->successful()) {
                throw new Exception('Authentication failed: ' . $response->body());
            }

            $data = $response->json();
            if (empty($data['access_token'])) {
                throw new Exception('No access token received from authentication');
            }

            Log::debug('Successfully authenticated with main service', [
                'token_type' => $data['token_type'] ?? 'bearer',
                'expires_in' => $data['expires_in'] ?? null
            ]);

            return $data['access_token'];

        } catch (Exception $e) {
            Log::error('Failed to authenticate with main service', [
                'error' => $e->getMessage()
            ]);
            throw new Exception('Main service authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Prepare expense data for sync.
     *
     * @param PocketExpense $expense
     * @return array
     */
    private function prepareExpenseDataForSync(PocketExpense $expense): array
    {
        $data = [
            'pocket_expense_id' => $expense->id,
            'user_id' => $expense->user_id,
            'client_id' => $expense->client_id,
            'date' => $expense->date,
            'merchant_name' => $expense->merchant_name,
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'status' => $expense->status,
            'description' => $expense->description,
            'receipt_url' => $expense->receipt_url,
            'is_billable' => $expense->is_billable,
            'project_code' => $expense->project_code,
            'cost_center' => $expense->cost_center,
            'created_at' => $expense->created_at->toISOString(),
            'updated_at' => $expense->updated_at->toISOString(),
        ];

        // Add expense type information
        if ($expense->expenseType) {
            $data['expense_type'] = [
                'id' => $expense->expenseType->id,
                'name' => $expense->expenseType->name,
            ];
        }

        // Add FX conversion information if available
        if ($expense->hasFxConversion()) {
            $data['fx_conversion'] = [
                'converted_amount' => $expense->converted_amount,
                'converted_currency' => $expense->converted_currency,
                'fx_rate' => $expense->fx_rate,
                'fx_commission' => $expense->fx_commission,
            ];
        }

        // Add approval information if available
        if ($expense->isApproved() || $expense->isRejected()) {
            $data['approval'] = [
                'approved_by' => $expense->approved_by,
                'approved_at' => $expense->approved_at ? $expense->approved_at->toISOString() : null,
                'rejection_reason' => $expense->rejection_reason,
            ];
        }

        // Add user information
        if ($expense->user) {
            $data['user'] = [
                'id' => $expense->user->id,
                'name' => $expense->user->name ?? '',
                'email' => $expense->user->email ?? '',
            ];
        }

        // Add client information
        if ($expense->client) {
            $data['client'] = [
                'id' => $expense->client->id,
                'name' => $expense->client->name ?? '',
            ];
        }

        // Add sync metadata
        $data['sync_metadata'] = [
            'source_system' => 'pocket_expense_system',
            'sync_version' => '1.0',
            'sync_timestamp' => now()->toISOString(),
        ];

        return $data;
    }

    /**
     * Sync expense to main service.
     *
     * @param array $expenseData
     * @param string|null $authToken
     * @return array
     * @throws Exception
     */
    private function syncExpenseToMainService(array $expenseData, ?string $authToken): array
    {
        try {
            $request = Http::timeout($this->apiConfig['timeout']);

            // Add authentication if available
            if ($authToken) {
                $request->withToken($authToken);
            }

            // Add headers
            $request->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'PocketExpenseSystem/1.0',
            ]);

            $response = $request->post(
                $this->apiConfig['base_url'] . $this->endpoints['expenses'],
                $expenseData
            );

            if (!$response->successful()) {
                $errorMessage = 'Sync failed with HTTP ' . $response->status();
                $errorBody = $response->body();
                
                if (!empty($errorBody)) {
                    $errorData = $response->json();
                    if (isset($errorData['message'])) {
                        $errorMessage .= ': ' . $errorData['message'];
                    } elseif (isset($errorData['error'])) {
                        $errorMessage .= ': ' . $errorData['error'];
                    }
                }

                throw new Exception($errorMessage);
            }

            $responseData = $response->json();

            $result = [
                'success' => true,
                'status' => 'synced',
                'main_service_expense_id' => $responseData['id'] ?? $responseData['expense_id'] ?? null,
                'main_service_response' => $responseData,
                'synced_at' => now()->toISOString(),
                'http_status' => $response->status(),
            ];

            Log::info('Successfully synced expense to main service', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'main_service_expense_id' => $result['main_service_expense_id'],
                'http_status' => $result['http_status']
            ]);

            return $result;

        } catch (Exception $e) {
            Log::error('Failed to sync expense to main service', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'status' => 'sync_failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString(),
            ];
        }
    }

    /**
     * Update expense with sync result.
     *
     * @param PocketExpense $expense
     * @param array $syncResult
     * @return void
     */
    private function updateExpenseWithSyncResult(PocketExpense $expense, array $syncResult): void
    {
        try {
            $metadata = $expense->metadata ?? [];
            
            // Update sync status
            $metadata['sync_status'] = $syncResult['status'];
            $metadata['last_sync_attempt'] = now()->toISOString();
            
            if ($syncResult['success']) {
                $metadata['main_service_expense_id'] = $syncResult['main_service_expense_id'];
                $metadata['synced_at'] = $syncResult['synced_at'];
                $metadata['sync_error'] = null; // Clear any previous errors
                $metadata['sync_attempts'] = null; // Clear attempt counter
            } else {
                $metadata['sync_error'] = $syncResult['error'];
                $metadata['sync_attempts'] = $this->attempts();
                $metadata['failed_at'] = $syncResult['failed_at'];
            }

            $expense->update(['metadata' => $metadata]);

            Log::debug('Updated expense with sync result', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'sync_status' => $syncResult['status'],
                'success' => $syncResult['success']
            ]);

        } catch (Exception $e) {
            Log::error('Failed to update expense with sync result', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sync expense metadata to main service.
     *
     * @param PocketExpense $expense
     * @param string|null $mainServiceExpenseId
     * @param string|null $authToken
     * @return void
     */
    private function syncExpenseMetadata(PocketExpense $expense, ?string $mainServiceExpenseId, ?string $authToken): void
    {
        if (!$mainServiceExpenseId) {
            Log::warning('Cannot sync metadata - no main service expense ID', [
                'pocket_expense_id' => $this->pocketExpenseId
            ]);
            return;
        }

        try {
            $metadataData = $this->prepareMetadataForSync($expense);
            
            if (empty($metadataData)) {
                Log::debug('No metadata to sync', [
                    'pocket_expense_id' => $this->pocketExpenseId
                ]);
                return;
            }

            $request = Http::timeout($this->apiConfig['timeout']);

            // Add authentication if available
            if ($authToken) {
                $request->withToken($authToken);
            }

            $endpoint = str_replace('{expense_id}', $mainServiceExpenseId, $this->endpoints['expense_metadata']);
            
            $response = $request->post(
                $this->apiConfig['base_url'] . $endpoint,
                ['metadata' => $metadataData]
            );

            if (!$response->successful()) {
                Log::warning('Failed to sync metadata to main service', [
                    'pocket_expense_id' => $this->pocketExpenseId,
                    'main_service_expense_id' => $mainServiceExpenseId,
                    'http_status' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return;
            }

            Log::info('Successfully synced metadata to main service', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'main_service_expense_id' => $mainServiceExpenseId,
                'metadata_count' => count($metadataData)
            ]);

        } catch (Exception $e) {
            Log::error('Failed to sync metadata to main service', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'main_service_expense_id' => $mainServiceExpenseId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Prepare metadata for sync.
     *
     * @param PocketExpense $expense
     * @return array
     */
    private function prepareMetadataForSync(PocketExpense $expense): array
    {
        $metadataData = [];

        foreach ($expense->metadata as $metadata) {
            $item = [
                'pocket_metadata_id' => $metadata->id,
                'type' => $metadata->metadata_type,
                'value' => $metadata->value,
                'label' => $metadata->label,
                'is_required' => $metadata->is_required,
                'is_editable' => $metadata->is_editable,
                'sort_order' => $metadata->sort_order,
                'created_at' => $metadata->created_at->toISOString(),
                'updated_at' => $metadata->updated_at->toISOString(),
            ];

            // Add details JSON if available
            if ($metadata->details_json) {
                $item['details'] = $metadata->details_json;
            }

            // Add reference information if available
            if ($metadata->category_id) {
                $item['category_id'] = $metadata->category_id;
            }

            if ($metadata->source_id) {
                $item['source_id'] = $metadata->source_id;
            }

            if ($metadata->country_id) {
                $item['country_id'] = $metadata->country_id;
            }

            if ($metadata->reference_type && $metadata->reference_id) {
                $item['reference'] = [
                    'type' => $metadata->reference_type,
                    'id' => $metadata->reference_id,
                ];
            }

            $metadataData[] = $item;
        }

        return $metadataData;
    }

    /**
     * Handle sync failure.
     *
     * @param Exception $exception
     * @return void
     */
    private function handleSyncFailure(Exception $exception): void
    {
        try {
            $expense = PocketExpense::find($this->pocketExpenseId);
            if (!$expense) {
                return;
            }

            $metadata = $expense->metadata ?? [];
            $metadata['sync_status'] = 'failed';
            $metadata['sync_error'] = $exception->getMessage();
            $metadata['sync_attempts'] = $this->attempts();
            $metadata['last_sync_attempt'] = now()->toISOString();

            // Calculate next retry time if we haven't exhausted all attempts
            if ($this->attempts() < $this->tries) {
                $nextRetry = now()->addSeconds($this->backoff * $this->attempts());
                $metadata['next_sync_attempt'] = $nextRetry->toISOString();
            } else {
                $metadata['next_sync_attempt'] = null; // Manual intervention required
            }

            $expense->update(['metadata' => $metadata]);

            Log::info('Updated expense with sync failure information', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'attempts' => $this->attempts(),
                'max_attempts' => $this->tries,
                'next_retry' => $metadata['next_sync_attempt']
            ]);

        } catch (Exception $e) {
            Log::error('Failed to handle sync failure', [
                'pocket_expense_id' => $this->pocketExpenseId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Determine the delay before retrying the job.
     *
     * @return int
     */
    public function retryAfter(): int
    {
        return $this->backoff * $this->attempts();
    }

    /**
     * Get job tags for monitoring.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'pocket-expense-sync',
            'expense-id:' . $this->pocketExpenseId,
            'main-service-sync'
        ];
    }

    /**
     * Get the job display name.
     *
     * @return string
     */
    public function displayName(): string
    {
        return 'Sync Pocket Expense #' . $this->pocketExpenseId . ' to Main Service';
    }

    /**
     * Check if the job should be retried.
     *
     * @param Exception $exception
     * @return bool
     */
    public function shouldRetry(Exception $exception): bool
    {
        // Don't retry authentication errors
        if (strpos($exception->getMessage(), 'Authentication failed') !== false) {
            return false;
        }

        // Don't retry validation errors (400 status)
        if (strpos($exception->getMessage(), 'HTTP 400') !== false) {
            return false;
        }

        // Don't retry not found errors (404 status)
        if (strpos($exception->getMessage(), 'HTTP 404') !== false) {
            return false;
        }

        // Retry other errors
        return true;
    }

    /**
     * Get sync statistics for monitoring.
     *
     * @return array
     */
    public static function getSyncStatistics(): array
    {
        try {
            $stats = [
                'total_synced' => 0,
                'sync_pending' => 0,
                'sync_failed' => 0,
                'last_sync_attempt' => null,
            ];

            // Count expenses by sync status
            $syncStatuses = DB::table('pocket_expenses')
                ->whereNull('deleted_at')
                ->selectRaw("JSON_EXTRACT(metadata, '$.sync_status') as sync_status, COUNT(*) as count")
                ->groupBy('sync_status')
                ->get();

            foreach ($syncStatuses as $status) {
                switch ($status->sync_status) {
                    case '"synced"':
                        $stats['total_synced'] = $status->count;
                        break;
                    case '"pending"':
                        $stats['sync_pending'] = $status->count;
                        break;
                    case '"failed"':
                        $stats['sync_failed'] = $status->count;
                        break;
                }
            }

            // Get last sync attempt timestamp
            $lastSync = DB::table('pocket_expenses')
                ->whereNull('deleted_at')
                ->whereRaw("JSON_EXTRACT(metadata, '$.last_sync_attempt') IS NOT NULL")
                ->orderByRaw("JSON_EXTRACT(metadata, '$.last_sync_attempt') DESC")
                ->limit(1)
                ->value(DB::raw("JSON_EXTRACT(metadata, '$.last_sync_attempt')"));

            if ($lastSync) {
                $stats['last_sync_attempt'] = trim($lastSync, '"');
            }

            return $stats;

        } catch (Exception $e) {
            Log::error('Failed to get sync statistics', [
                'error' => $e->getMessage()
            ]);

            return [
                'total_synced' => 0,
                'sync_pending' => 0,
                'sync_failed' => 0,
                'last_sync_attempt' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retry failed sync for a specific expense.
     *
     * @param int $pocketExpenseId
     * @return bool
     */
    public static function retrySyncForExpense(int $pocketExpenseId): bool
    {
        try {
            $expense = PocketExpense::find($pocketExpenseId);
            if (!$expense) {
                Log::error('Cannot retry sync - expense not found', [
                    'pocket_expense_id' => $pocketExpenseId
                ]);
                return false;
            }

            // Reset sync status to allow retry
            $metadata = $expense->metadata ?? [];
            $metadata['sync_status'] = 'pending';
            $metadata['sync_error'] = null;
            $metadata['sync_attempts'] = 0;
            $metadata['next_sync_attempt'] = now()->toISOString();

            $expense->update(['metadata' => $metadata]);

            // Dispatch the job
            self::dispatch($pocketExpenseId);

            Log::info('Retrying sync for expense', [
                'pocket_expense_id' => $pocketExpenseId
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to retry sync for expense', [
                'pocket_expense_id' => $pocketExpenseId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }
}