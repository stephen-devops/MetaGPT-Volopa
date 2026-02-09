## Code: app/Jobs/SyncExpensesToMainServiceJob.php

```php
<?php

namespace App\Jobs;

use App\Models\OopExpense;
use App\Services\ExpenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class SyncExpensesToMainServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The expense IDs to sync to main service.
     */
    private array $expenseIds;

    /**
     * The sync action to perform.
     */
    private string $syncAction;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * Batch size for processing expenses.
     */
    private const BATCH_SIZE = 50;

    /**
     * HTTP timeout for API calls in seconds.
     */
    private const HTTP_TIMEOUT = 30;

    /**
     * Maximum retry attempts for API calls.
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Valid sync actions.
     */
    const SYNC_ACTION_CREATE = 'create';
    const SYNC_ACTION_UPDATE = 'update';
    const SYNC_ACTION_DELETE = 'delete';
    const SYNC_ACTION_APPROVE = 'approve';
    const SYNC_ACTION_REJECT = 'reject';

    /**
     * Main service API configuration.
     */
    private array $apiConfig;

    /**
     * Create a new job instance.
     */
    public function __construct(array $expenseIds, string $syncAction = self::SYNC_ACTION_CREATE)
    {
        $this->expenseIds = $expenseIds;
        $this->syncAction = $syncAction;
        $this->queue = 'expense-sync';
        $this->delay = now()->addSeconds(5);
        
        $this->apiConfig = [
            'base_url' => config('services.main_expense_service.base_url', 'https://api.main-service.com'),
            'api_key' => config('services.main_expense_service.api_key', ''),
            'timeout' => self::HTTP_TIMEOUT,
            'retry_attempts' => self::MAX_RETRY_ATTEMPTS,
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting expense sync to main service', [
                'expense_ids' => $this->expenseIds,
                'sync_action' => $this->syncAction,
                'job_id' => $this->job->getJobId(),
                'batch_size' => count($this->expenseIds),
            ]);

            // Validate sync action
            if (!$this->isValidSyncAction($this->syncAction)) {
                throw new \InvalidArgumentException('Invalid sync action: ' . $this->syncAction);
            }

            // Process expenses in batches
            $this->processExpensesInBatches();

            Log::info('Expense sync to main service completed', [
                'expense_ids' => $this->expenseIds,
                'sync_action' => $this->syncAction,
                'total_expenses' => count($this->expenseIds),
            ]);

        } catch (\Exception $e) {
            Log::error('Error syncing expenses to main service', [
                'expense_ids' => $this->expenseIds,
                'sync_action' => $this->syncAction,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->handleFailure($e);
        }
    }

    /**
     * Process expenses in batches to avoid overwhelming the main service.
     */
    private function processExpensesInBatches(): void
    {
        $batches = array_chunk($this->expenseIds, self::BATCH_SIZE);
        $successCount = 0;
        $failureCount = 0;

        foreach ($batches as $batchIndex => $batchExpenseIds) {
            try {
                Log::info('Processing expense batch', [
                    'batch_index' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'batch_size' => count($batchExpenseIds),
                    'expense_ids' => $batchExpenseIds,
                    'sync_action' => $this->syncAction,
                ]);

                $batchResult = $this->processBatch($batchExpenseIds);
                $successCount += $batchResult['success_count'];
                $failureCount += $batchResult['failure_count'];

                // Add small delay between batches to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    sleep(2);
                }

            } catch (\Exception $e) {
                Log::error('Error processing expense batch', [
                    'batch_index' => $batchIndex + 1,
                    'expense_ids' => $batchExpenseIds,
                    'sync_action' => $this->syncAction,
                    'error' => $e->getMessage(),
                ]);

                $failureCount += count($batchExpenseIds);
            }
        }

        Log::info('Batch processing completed', [
            'total_expenses' => count($this->expenseIds),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'sync_action' => $this->syncAction,
        ]);
    }

    /**
     * Process a single batch of expenses.
     */
    private function processBatch(array $expenseIds): array
    {
        $successCount = 0;
        $failureCount = 0;
        $results = [];

        try {
            // Get expense data for the batch
            $expenses = OopExpense::whereIn('id', $expenseIds)
                ->with(['user', 'client', 'approvedBy', 'project'])
                ->get();

            foreach ($expenses as $expense) {
                try {
                    $result = $this->syncSingleExpense($expense);
                    
                    if ($result['success']) {
                        $successCount++;
                        $this->markExpenseAsSynced($expense, $result['sync_id'] ?? null);
                    } else {
                        $failureCount++;
                        $this->markExpenseAsSyncFailed($expense, $result['error'] ?? 'Unknown error');
                    }

                    $results[] = $result;

                } catch (\Exception $e) {
                    $failureCount++;
                    $this->markExpenseAsSyncFailed($expense, $e->getMessage());
                    
                    $results[] = [
                        'success' => false,
                        'expense_id' => $expense->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return [
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'results' => $results,
            ];

        } catch (\Exception $e) {
            Log::error('Error processing batch', [
                'expense_ids' => $expenseIds,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync a single expense to the main service.
     */
    private function syncSingleExpense(OopExpense $expense): array
    {
        try {
            $expenseData = $this->prepareExpenseDataForSync($expense);
            $apiResponse = null;

            switch ($this->syncAction) {
                case self::SYNC_ACTION_CREATE:
                    $apiResponse = $this->createExpenseInMainService($expenseData);
                    break;

                case self::SYNC_ACTION_UPDATE:
                    $apiResponse = $this->updateExpenseInMainService($expense, $expenseData);
                    break;

                case self::SYNC_ACTION_DELETE:
                    $apiResponse = $this->deleteExpenseInMainService($expense);
                    break;

                case self::SYNC_ACTION_APPROVE:
                    $apiResponse = $this->approveExpenseInMainService($expense);
                    break;

                case self::SYNC_ACTION_REJECT:
                    $apiResponse = $this->rejectExpenseInMainService($expense);
                    break;

                default:
                    throw new \InvalidArgumentException('Invalid sync action: ' . $this->syncAction);
            }

            if ($apiResponse['success']) {
                Log::info('Expense synced successfully', [
                    'expense_id' => $expense->id,
                    'sync_action' => $this->syncAction,
                    'sync_id' => $apiResponse['sync_id'] ?? null,
                ]);

                return [
                    'success' => true,
                    'expense_id' => $expense->id,
                    'sync_id' => $apiResponse['sync_id'] ?? null,
                    'message' => 'Expense synced successfully',
                ];
            } else {
                Log::warning('Failed to sync expense', [
                    'expense_id' => $expense->id,
                    'sync_action' => $this->syncAction,
                    'error' => $apiResponse['error'] ?? 'Unknown error',
                ]);

                return [
                    'success' => false,
                    'expense_id' => $expense->id,
                    'error' => $apiResponse['error'] ?? 'Unknown error',
                ];
            }

        } catch (\Exception $e) {
            Log::error('Error syncing single expense', [
                'expense_id' => $expense->id,
                'sync_action' => $this->syncAction,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare expense data for sync with main service.
     */
    private function prepareExpenseDataForSync(OopExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'user_id' => $expense->user_id,
            'client_id' => $expense->client_id,
            'date' => $expense->date->format('Y-m-d'),
            'merchant_name' => $expense->merchant_name,
            'description' => $expense->description,
            'transaction_type' => $expense->transaction_type,
            'currency' => $expense->currency,
            'amount' => $expense->amount,
            'merchant_address' => $expense->merchant_address,
            'country' => $expense->country,
            'source' => $expense->source,
            'category' => $expense->category,
            'custom_fields' => $expense->custom_fields,
            'tracking_code_i' => $expense->tracking_code_i,
            'tracking_code_ii' => $expense->tracking_code_ii,
            'project_id' => $expense->project_id,
            'vat' => $expense->vat,
            'status' => $expense->status,
            'approved_by' => $expense->approved_by,
            'approved_at' => $expense->approved_at ? $expense->approved_at->toISOString() : null,
            'receipt_path' => $expense->receipt_path,
            'notes' => $expense->notes,
            'created_at' => $expense->created_at->toISOString(),
            'updated_at' => $expense->updated_at->toISOString(),
            // Additional metadata
            'user_email' => $expense->user->email ?? null,
            'user_name' => $expense->user->name ?? null,
            'client_name' => $expense->client->name ?? null,
            'approver_email' => $expense->approvedBy->email ?? null,
            'approver_name' => $expense->approvedBy->name ?? null,
            'project_name' => $expense->project->name ?? null,
        ];
    }

    /**
     * Create expense in main service.
     */
    private function createExpenseInMainService(array $expenseData): array
    {
        return $this->makeApiRequest('POST', '/api/expenses', $expenseData);
    }

    /**
     * Update expense in main service.
     */
    private function updateExpenseInMainService(OopExpense $expense, array $expenseData): array
    {
        $syncId = $this->getExpenseSyncId($expense);
        
        if ($syncId) {
            return $this->makeApiRequest('PUT', "/api/expenses/{$syncId}", $expenseData);
        } else {
            // If no sync ID exists, create the expense instead
            return $this->createExpenseInMainService($expenseData);
        }
    }

    /**
     * Delete expense in main service.
     */
    private function deleteExpenseInMainService(OopExpense $expense): array
    {
        $syncId = $this->getExpenseSyncId($expense);
        
        if ($syncId) {
            return $this->makeApiRequest('DELETE', "/api/expenses/{$syncId}");
        } else {
            return [
                'success' => true,
                'message' => 'Expense not found in main service, considering as deleted',
            ];
        }
    }

    /**
     * Approve expense in main service.
     */
    private function approveExpenseInMainService(OopExpense $expense): array
    {
        $syncId = $this->getExpenseSyncId($expense);
        
        if ($syncId) {
            $approvalData = [
                'approved_by' => $expense->approved_by,
                'approved_at' => $expense->approved_at ? $expense->approved_at->toISOString() : now()->toISOString(),
                'notes' => 'Approved via OOP expense system',
            ];

            return $this->makeApiRequest('PATCH', "/api/expenses/{$syncId}/approve", $approvalData);
        } else {
            return [
                'success' => false,
                'error' => 'Expense not found in main service',
            ];
        }
    }

    /**
     * Reject expense in main service.
     */
    private function rejectExpenseInMainService(OopExpense $expense): array
    {
        $syncId = $this->getExpenseSyncId($expense);
        
        if ($syncId) {
            $rejectionData = [
                'approved_by' => $expense->approved_by,
                'approved_at' => $expense->approved_at ? $expense->approved_at->toISOString() : now()->toISOString(),
                'notes' => 'Rejected via OOP expense system',
            ];

            return $this->makeApiRequest('PATCH', "/api/expenses/{$syncId}/reject", $rejectionData);
        } else {
            return [
                'success' => false,
                'error' => 'Expense not found in main service',
            ];
        }
    }

    /**
     * Make HTTP API request to main service.
     */
    private function makeApiRequest(string $method, string $endpoint, array $data = []): array
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $this->apiConfig['retry_attempts']) {
            try {
                $attempts++;

                $url = rtrim($this->apiConfig['base_url'], '/') . $endpoint;

                Log::info('Making API request to main service', [
                    'method' => $method,
                    'url' => $