## Code: app/Services/PocketExpenseService.php

```php
<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\User;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

class PocketExpenseService
{
    /**
     * Create a new pocket expense.
     *
     * @param array $data
     * @param int $userId
     * @param int $clientId
     * @return PocketExpense
     * @throws Exception
     */
    public function createExpense(array $data, int $userId, int $clientId): PocketExpense
    {
        try {
            return DB::transaction(function () use ($data, $userId, $clientId) {
                // Validate that user and client exist
                $this->validateUserAndClient($userId, $clientId);

                // Prepare expense data
                $expenseData = array_merge($data, [
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'status' => $data['status'] ?? 'pending',
                    'fx_commission' => $data['fx_commission'] ?? 0.0000,
                    'is_billable' => $data['is_billable'] ?? true,
                ]);

                // Create the expense
                $expense = PocketExpense::create($expenseData);

                Log::info('Pocket expense created', [
                    'expense_id' => $expense->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                    'merchant_name' => $expense->merchant_name
                ]);

                return $expense->load(['user', 'client', 'expenseType']);
            });
        } catch (Exception $e) {
            Log::error('Failed to create pocket expense', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to create expense: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update an existing pocket expense.
     *
     * @param int $id
     * @param array $data
     * @param int $userId
     * @return PocketExpense
     * @throws Exception
     */
    public function updateExpense(int $id, array $data, int $userId): PocketExpense
    {
        try {
            return DB::transaction(function () use ($id, $data, $userId) {
                $expense = PocketExpense::findOrFail($id);

                // Check if expense can be updated
                if (!$expense->canBeEdited()) {
                    throw new Exception('Expense cannot be updated in its current status: ' . $expense->status);
                }

                // Validate user permission (either owner or admin/manager with proper permissions)
                if ($expense->user_id !== $userId) {
                    $user = User::findOrFail($userId);
                    if (!method_exists($user, 'can') || !$user->can('update', $expense)) {
                        throw new Exception('You do not have permission to update this expense');
                    }
                }

                // Update the expense
                $expense->update($data);

                Log::info('Pocket expense updated', [
                    'expense_id' => $expense->id,
                    'updated_by' => $userId,
                    'updated_fields' => array_keys($data)
                ]);

                return $expense->fresh(['user', 'client', 'expenseType', 'approver', 'metadata']);
            });
        } catch (ModelNotFoundException $e) {
            Log::error('Pocket expense not found for update', [
                'expense_id' => $id,
                'user_id' => $userId
            ]);
            
            throw new Exception('Expense not found', 404, $e);
        } catch (Exception $e) {
            Log::error('Failed to update pocket expense', [
                'expense_id' => $id,
                'user_id' => $userId,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to update expense: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a pocket expense.
     *
     * @param int $id
     * @param int $userId
     * @return bool
     * @throws Exception
     */
    public function deleteExpense(int $id, int $userId): bool
    {
        try {
            return DB::transaction(function () use ($id, $userId) {
                $expense = PocketExpense::findOrFail($id);

                // Check if expense can be deleted
                if (!$expense->canBeDeleted()) {
                    throw new Exception('Expense cannot be deleted in its current status: ' . $expense->status);
                }

                // Validate user permission
                if ($expense->user_id !== $userId) {
                    $user = User::findOrFail($userId);
                    if (!method_exists($user, 'can') || !$user->can('delete', $expense)) {
                        throw new Exception('You do not have permission to delete this expense');
                    }
                }

                // Soft delete the expense (this will cascade to metadata via model relationships)
                $result = $expense->delete();

                if ($result) {
                    Log::info('Pocket expense deleted', [
                        'expense_id' => $expense->id,
                        'deleted_by' => $userId,
                        'amount' => $expense->amount,
                        'merchant_name' => $expense->merchant_name
                    ]);
                }

                return $result;
            });
        } catch (ModelNotFoundException $e) {
            Log::error('Pocket expense not found for deletion', [
                'expense_id' => $id,
                'user_id' => $userId
            ]);
            
            throw new Exception('Expense not found', 404, $e);
        } catch (Exception $e) {
            Log::error('Failed to delete pocket expense', [
                'expense_id' => $id,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to delete expense: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Approve a pocket expense.
     *
     * @param int $id
     * @param int $approverId
     * @return PocketExpense
     * @throws Exception
     */
    public function approveExpense(int $id, int $approverId): PocketExpense
    {
        try {
            return DB::transaction(function () use ($id, $approverId) {
                $expense = PocketExpense::findOrFail($id);

                // Check if expense can be approved
                if (!$expense->canBeApproved()) {
                    throw new Exception('Expense cannot be approved in its current status: ' . $expense->status);
                }

                // Validate approver permission
                $approver = User::findOrFail($approverId);
                if (!method_exists($approver, 'can') || !$approver->can('approve', $expense)) {
                    throw new Exception('You do not have permission to approve this expense');
                }

                // Approve the expense
                $expense->approve($approverId);

                Log::info('Pocket expense approved', [
                    'expense_id' => $expense->id,
                    'approved_by' => $approverId,
                    'amount' => $expense->amount,
                    'user_id' => $expense->user_id
                ]);

                return $expense->fresh(['user', 'client', 'expenseType', 'approver', 'metadata']);
            });
        } catch (ModelNotFoundException $e) {
            Log::error('Pocket expense not found for approval', [
                'expense_id' => $id,
                'approver_id' => $approverId
            ]);
            
            throw new Exception('Expense not found', 404, $e);
        } catch (Exception $e) {
            Log::error('Failed to approve pocket expense', [
                'expense_id' => $id,
                'approver_id' => $approverId,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to approve expense: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reject a pocket expense.
     *
     * @param int $id
     * @param int $approverId
     * @param string $reason
     * @return PocketExpense
     * @throws Exception
     */
    public function rejectExpense(int $id, int $approverId, string $reason): PocketExpense
    {
        try {
            return DB::transaction(function () use ($id, $approverId, $reason) {
                $expense = PocketExpense::findOrFail($id);

                // Check if expense can be rejected
                if (!$expense->canBeRejected()) {
                    throw new Exception('Expense cannot be rejected in its current status: ' . $expense->status);
                }

                // Validate approver permission
                $approver = User::findOrFail($approverId);
                if (!method_exists($approver, 'can') || !$approver->can('reject', $expense)) {
                    throw new Exception('You do not have permission to reject this expense');
                }

                // Reject the expense
                $expense->reject($approverId, $reason);

                Log::info('Pocket expense rejected', [
                    'expense_id' => $expense->id,
                    'rejected_by' => $approverId,
                    'reason' => $reason,
                    'amount' => $expense->amount,
                    'user_id' => $expense->user_id
                ]);

                return $expense->fresh(['user', 'client', 'expenseType', 'approver', 'metadata']);
            });
        } catch (ModelNotFoundException $e) {
            Log::error('Pocket expense not found for rejection', [
                'expense_id' => $id,
                'approver_id' => $approverId
            ]);
            
            throw new Exception('Expense not found', 404, $e);
        } catch (Exception $e) {
            Log::error('Failed to reject pocket expense', [
                'expense_id' => $id,
                'approver_id' => $approverId,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to reject expense: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get expenses for a user with filtering and pagination.
     *
     * @param int $userId
     * @param int $clientId
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getUserExpenses(int $userId, int $clientId, array $filters = []): LengthAwarePaginator
    {
        try {
            $query = PocketExpense::with(['user', 'client', 'expenseType', 'approver', 'metadata'])
                ->where('user_id', $userId)
                ->where('client_id', $clientId);

            // Apply filters
            $query = $this->applyFilters($query, $filters);

            // Get pagination parameters
            $perPage = $filters['per_page'] ?? 15;
            $perPage = min(max($perPage, 1), 100); // Limit between 1-100

            $expenses = $query->paginate($perPage);

            Log::debug('Retrieved user pocket expenses', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'total' => $expenses->total(),
                'per_page' => $perPage,
                'filters' => $filters
            ]);

            return $expenses;

        } catch (Exception $e) {
            Log::error('Failed to retrieve user pocket expenses', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to retrieve expenses: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all expenses for a client with filtering and pagination.
     *
     * @param int $clientId
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getClientExpenses(int $clientId, array $filters = []): LengthAwarePaginator
    {
        try {
            $query = PocketExpense::with(['user', 'client', 'expenseType', 'approver', 'metadata'])
                ->where('client_id', $clientId);

            // Apply filters
            $query = $this->applyFilters($query, $filters);

            // Get pagination parameters
            $perPage = $filters['per_page'] ?? 15;
            $perPage = min(max($perPage, 1), 100); // Limit between 1-100

            $expenses = $query->paginate($perPage);

            Log::debug('Retrieved client pocket expenses', [
                'client_id' => $clientId,
                'total' => $expenses->total(),
                'per_page' => $perPage,
                'filters' => $filters
            ]);

            return $expenses;

        } catch (Exception $e) {
            Log::error('Failed to retrieve client pocket expenses', [
                'client_id' => $clientId,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to retrieve client expenses: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a pocket expense with metadata.
     *
     * @param array $data
     * @param int $userId
     * @param int $clientId
     * @return PocketExpense
     * @throws Exception
     */
    public function createExpenseWithMetadata(array $data, int $userId, int $clientId): PocketExpense
    {
        try {
            return DB::transaction(function () use ($data, $userId, $clientId) {
                // Extract metadata from the main data array
                $metadataItems = $data['metadata'] ?? [];
                unset($data['metadata']);

                // Create the main expense
                $expense = $this->createExpense($data, $userId, $clientId);

                // Create associated metadata if provided
                if (!empty($metadataItems) && is_array($metadataItems)) {
                    $this->createMetadataForExpense($expense->id, $metadataItems);
                }

                // Reload with metadata
                $expense = $expense->fresh(['user', 'client', 'expenseType', 'approver', 'metadata']);

                Log::info('Pocket expense created with metadata', [
                    'expense_id' => $expense->id,
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'metadata_count' => count($metadataItems)
                ]);

                return $expense;
            });
        } catch (Exception $e) {
            Log::error('Failed to create pocket expense with metadata', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to create expense with metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Bulk approve expenses.
     *
     * @param array $expenseIds
     * @param int $approverId
     * @param int $clientId
     * @return array
     */
    public function bulkApproveExpenses(array $expenseIds, int $approverId, int $clientId): array
    {
        $results = [
            'successful' => [],
            'failed' => [],
            'total_processed' => 0,
            'total_successful' => 0,
            'total_failed' => 0
        ];

        try {
            return DB::transaction(function () use ($expenseIds, $approverId, $clientId, &$results) {
                $expenses = PocketExpense::whereIn('id', $expenseIds)
                    ->where('client_id', $clientId)
                    ->get();

                foreach ($expenses as $expense) {
                    $results['total_processed']++;
                    
                    try {
                        if (!$expense->canBeApproved()) {
                            throw new Exception('Cannot approve expense in current status: ' . $expense->status);
                        }

                        $expense->approve($approverId);
                        
                        $results['successful'][] = [
                            'expense_id' => $expense->id,
                            'amount' => $expense->amount,
                            'merchant_name' => $expense->merchant_name
                        ];
                        $results['total_successful']++;
                        
                    } catch (Exception $e) {
                        $results['failed'][] = [
                            'expense_id' => $expense->id,
                            'error' => $e->getMessage()
                        ];
                        $results['total_failed']++;
                    }
                }

                Log::info('Bulk approve pocket expenses completed', [
                    'client_id' => $clientId,
                    'approver_id' => $approverId,
                    'total_processed' => $results['total_processed'],
                    'total_successful' => $results['total_successful'],
                    'total_failed' => $results['total_failed']
                ]);

                return $results;
            });

        } catch (Exception $e) {
            Log::error('Bulk approve pocket expenses failed', [
                'client_id' => $clientId,
                'approver_id' => $approverId,
                'expense_ids' => $expenseIds,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Bulk approve failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get expense statistics for a client.
     *
     * @param int $clientId
     * @param array $filters
     * @return array
     */
    public function getExpenseStatistics(int $clientId, array $filters = []): array
    {
        try {
            $query = PocketExpense::where('client_id', $clientId);
            
            // Apply date filters if provided
            if (!empty($filters['start_date'])) {
                $query->where('date', '>=', $filters['start_date']);
            }
            if (!empty($filters['end_date'])) {
                $query->where('date', '<=', $filters['end_date']);
            }

            $expenses = $query->with(['expenseType'])->get();

            $statistics = [
                'total_expenses' => $expenses->count(),
                'total_amount' => $expenses->sum('amount'),
                'average_amount' => $expenses->avg('amount'),
                'by_status' => [
                    'pending' => $expenses->where('status', 'pending')->count(),
                    'approved' => $expenses->where('status', 'approved')->count(),
                    'rejected' => $expenses->where('status', 'rejected')->count(),
                    'processing' => $expenses->where('status', 'processing')->count(),
                ],
                'by_currency' => $expenses->groupBy('currency')->map->count()->toArray(),
                'by_expense_type' => $expenses->groupBy('expenseType.name')->map->count()->toArray(),
                'billable_count' => $expenses->where('is_billable', true)->count(),
                'non_billable_count' => $expenses->where('is_billable', false)->count(),
                'top_merchants' => $expenses->groupBy('merchant_name')
                    ->map(function ($group) {
                        return [
                            'count' => $group->count(),
                            'total_amount' => $group->sum('amount')
                        ];
                    })
                    ->sortByDesc('total_amount')
                    ->take(10)
                    ->toArray(),
            ];

            Log::debug('Generated pocket expense statistics', [
                'client_id' => $clientId,
                'total_expenses' => $statistics['total_expenses'],
                'total_amount' => $statistics['total_amount'],
                'filters' => $filters
            ]);

            return $statistics;

        } catch (Exception $e) {
            Log::error('Failed to generate pocket expense statistics', [
                'client_id' => $clientId,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to generate statistics: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply FX conversion to an expense.
     *
     * @param int $expenseId
     * @param float $convertedAmount
     * @param string $convertedCurrency
     * @param float $fxRate
     * @param float $fxCommission
     * @return PocketExpense
     * @throws Exception
     */
    public function applyFxConversion(int $expenseId, float $convertedAmount, string $convertedCurrency, float $fxRate, float $fxCommission = 0.0000): PocketExpense
    {
        try {
            return DB::transaction(function () use ($expenseId, $convertedAmount, $convertedCurrency, $fxRate, $fxCommission) {
                $expense = PocketExpense::findOrFail($expenseId);

                // Validate that the expense can be updated
                if (!$expense->canBeEdited()) {
                    throw new Exception('Cannot apply FX conversion to expense in current status: ' . $expense->status);
                }

                // Apply FX conversion
                $expense->applyFxConversion($convertedAmount, $convertedCurrency, $fxRate, $fxCommission);

                Log::info('FX conversion applied to pocket expense', [
                    'expense_id' => $expenseId,
                    'original_amount' => $expense->amount,
                    'original_currency' => $expense->currency,
                    'converted_amount' => $convertedAmount,
                    'converted_currency' => $convertedCurrency,
                    'fx_rate' => $fxRate,
                    'fx_commission' => $fxCommission
                ]);

                return $expense->fresh(['user', 'client', 'expenseType']);
            });
        } catch (ModelNotFoundException $e) {
            Log::error('Pocket expense not found for FX conversion', [
                'expense_id' => $expenseId
            ]);
            
            throw new Exception('Expense not found', 404, $e);
        } catch (Exception $e) {
            Log::error('Failed to apply FX conversion to pocket expense', [
                'expense_id' => $expenseId,
                'converted_amount' => $convertedAmount,
                'converted_currency' => $convertedCurrency,
                'fx_rate' => $fxRate,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to apply FX conversion: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create metadata for an expense.
     *
     * @param int $expenseId
     * @param array $metadataItems
     * @return Collection
     * @throws Exception
     */
    private function createMetadataForExpense(int $expenseId, array $metadataItems): Collection
    {
        try {
            $createdMetadata = collect();

            foreach ($metadataItems as $index => $metadataData) {
                // Set defaults
                $metadataData['pocket_expense_id'] = $expenseId;
                $metadataData['is_required'] = $metadataData['is_required'] ?? false;
                $metadataData['is_editable'] = $metadataData['is_editable'] ?? true;
                $metadataData['sort_order'] = $metadataData['sort_order'] ?? $index;

                $metadata = PocketExpenseMetadata::create($metadataData);
                $createdMetadata->push($metadata);
            }

            Log::debug('Created metadata for pocket expense', [
                'expense_id' => $expenseId,
                'metadata_count' => $createdMetadata->count()
            ]);

            return $createdMetadata;

        } catch (Exception $e) {
            Log::error('Failed to create metadata for pocket expense', [
                'expense_id' => $expenseId,
                'metadata_items' => $metadataItems,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to create metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply filters to the expense query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyFilters($query, array $filters)
    {
        // Status filter
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        // Date range filter
        if (!empty($filters['start_date'])) {
            $query->where('date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('date', '<=', $filters['end_date']);
        }

        // Amount range filter
        if (!empty($filters['min_amount'])) {
            $query->where('amount', '>=', $filters['min_amount']);
        }
        if (!empty($filters['max_amount'])) {
            $query->where('amount', '<=', $filters['max_amount']);
        }

        // Currency filter
        if (!empty($filters['currency'])) {
            if (is_array($filters['currency'])) {
                $query->whereIn('currency', $filters['currency']);
            } else {
                $query->where('currency', $filters['currency']);
            }
        }

        // Search filter (merchant name or description)
        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('merchant_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Expense type filter
        if (!empty($filters['expense_type_id'])) {
            $query->where('expense_type_id', $filters['expense_type_id']);
        }

        // Billable filter
        if (isset($filters['is_billable'])) {
            $query->where('is_billable', (bool) $filters['is_billable']);
        }

        // Project code filter
        if (!empty($filters['project_code'])) {
            $query->where('project_code', $filters['project_code']);
        }

        // Cost center filter
        if (!empty($filters['cost_center'])) {
            $query->where('cost_center', $filters['cost_center']);
        }

        // Approved by filter
        if (!empty($filters['approved_by'])) {
            $query->where('approved_by', $filters['approved_by']);
        }

        // Metadata filter
        if (!empty($filters['metadata_type'])) {
            $query->whereHas('metadata', function ($q) use ($filters) {
                $q->where('metadata_type', $filters['metadata_type']);
                
                if (!empty($filters['metadata_value'])) {
                    $q->where('value', 'LIKE', "%{$filters['metadata_value']}%");
                }
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        
        // Validate sort fields
        $allowedSortFields = [
            'id', 'date', 'amount', 'currency', 'status', 'merchant_name',
            'created_at', 'updated_at', 'approved_at'
        ];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return $query;
    }

    /**
     * Validate that user and client exist and are active.
     *
     * @param int $userId
     * @param int $clientId
     * @throws ModelNotFoundException
     */
    private function validateUserAndClient(int $userId, int $clientId): void
    {
        // Validate user exists and is active
        if (!User::where('id', $userId)->whereNull('deleted_at')->exists()) {
            throw new ModelNotFoundException("User with ID {$userId} not found");
        }

        // Validate client exists and is active
        if (!Client::where('id', $clientId)->where('is_active', true)->exists()) {
            throw new ModelNotFoundException("Active client with ID {$clientId} not found");
        }
    }

    /**
     * Update metadata for an expense.
     *
     * @param int $expenseId
     * @param array $metadataItems
     * @return Collection
     * @throws Exception
     */
    public function updateExpenseMetadata(int $expenseId, array $metadataItems): Collection
    {
        try {
            return DB::transaction(function () use ($expenseId, $metadataItems) {
                $expense = PocketExpense::findOrFail($expenseId);

                // Check if expense metadata can be updated
                if (!$expense->canBeEdited()) {
                    throw new Exception('Expense metadata cannot be updated in current status: ' . $expense->status);
                }

                // Delete existing metadata
                PocketExpenseMetadata::where('pocket_expense_id', $expenseId)->delete();

                // Create new metadata
                $createdMetadata = $this->createMetadataForExpense($expenseId, $metadataItems);

                Log::info('Updated metadata for pocket expense', [
                    'expense_id' => $expenseId,
                    'metadata_count' => $createdMetadata->count()
                ]);

                return $createdMetadata;
            });
        } catch (ModelNotFoundException $e) {
            Log::error('Pocket expense not found for metadata update', [
                'expense_id' => $expenseId
            ]);
            
            throw new Exception('Expense not found', 404, $e);
        } catch (Exception $e) {
            Log::error('Failed to update metadata for pocket expense', [
                'expense_id' => $expenseId,
                'metadata_items' => $metadataItems,
                'error' => $e->getMessage()
            ]);
            
            throw new Exception('Failed to update metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get expenses that need approval.
     *
     * @param int $clientId
     * @param int $managerId
     * @return Collection
     */
    public function getExpensesNeedingApproval(int $clientId, int $managerId): Collection
    {
        try {
            $query = PocketExpense::