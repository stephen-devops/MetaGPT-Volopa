## Code: app/Services/ExpenseService.php

```php
<?php

namespace App\Services;

use App\Models\OopExpense;
use App\Models\User;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\FxConversionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ExpenseService
{
    /**
     * FX conversion service instance.
     */
    private FxConversionService $fxConversionService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PAGE_SIZE = 15;

    /**
     * Maximum pagination size.
     */
    private const MAX_PAGE_SIZE = 100;

    /**
     * Constructor.
     */
    public function __construct(FxConversionService $fxConversionService)
    {
        $this->fxConversionService = $fxConversionService;
    }

    /**
     * Create a new expense.
     */
    public function createExpense(array $data, User $user): OopExpense
    {
        return DB::transaction(function () use ($data, $user) {
            try {
                // Validate and process expense data
                $processedData = $this->processExpenseData($data, $user);

                // Apply transaction type amount sign
                $processedData = $this->applyTransactionTypeSign($processedData);

                // Handle FX conversion if needed
                $processedData = $this->handleFxConversion($processedData);

                // Create the expense
                $expense = OopExpense::create($processedData);

                Log::info('Expense created successfully', [
                    'expense_id' => $expense->id,
                    'user_id' => $user->id,
                    'client_id' => $processedData['client_id'],
                    'amount' => $processedData['amount'],
                    'currency' => $processedData['currency'],
                ]);

                return $expense;

            } catch (\Exception $e) {
                Log::error('Error creating expense', [
                    'user_id' => $user->id,
                    'data' => $data,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Update an existing expense.
     */
    public function updateExpense(OopExpense $expense, array $data): OopExpense
    {
        return DB::transaction(function () use ($expense, $data) {
            try {
                // Ensure expense can be updated
                if (!$expense->canBeUpdated()) {
                    throw new \InvalidArgumentException('Only pending expenses can be updated');
                }

                // Process update data
                $processedData = $this->processUpdateData($data, $expense);

                // Apply transaction type amount sign if transaction type changed
                if (isset($processedData['transaction_type'])) {
                    $processedData = $this->applyTransactionTypeSign($processedData);
                }

                // Handle FX conversion if currency or amount changed
                if (isset($processedData['currency']) || isset($processedData['amount'])) {
                    $processedData = $this->handleFxConversion($processedData, $expense);
                }

                // Update the expense
                $expense->update($processedData);

                Log::info('Expense updated successfully', [
                    'expense_id' => $expense->id,
                    'updated_fields' => array_keys($processedData),
                ]);

                return $expense->fresh();

            } catch (\Exception $e) {
                Log::error('Error updating expense', [
                    'expense_id' => $expense->id,
                    'data' => $data,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Approve an expense.
     */
    public function approveExpense(OopExpense $expense, User $approver): OopExpense
    {
        return DB::transaction(function () use ($expense, $approver) {
            try {
                if (!$expense->canBeApproved()) {
                    throw new \InvalidArgumentException('Only pending expenses can be approved');
                }

                $success = $expense->markAsApproved($approver->id);

                if (!$success) {
                    throw new \RuntimeException('Failed to approve expense');
                }

                Log::info('Expense approved successfully', [
                    'expense_id' => $expense->id,
                    'approver_id' => $approver->id,
                    'expense_user_id' => $expense->user_id,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                ]);

                return $expense->fresh();

            } catch (\Exception $e) {
                Log::error('Error approving expense', [
                    'expense_id' => $expense->id,
                    'approver_id' => $approver->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Reject an expense.
     */
    public function rejectExpense(OopExpense $expense, User $rejector): OopExpense
    {
        return DB::transaction(function () use ($expense, $rejector) {
            try {
                if (!$expense->canBeRejected()) {
                    throw new \InvalidArgumentException('Only pending expenses can be rejected');
                }

                $success = $expense->markAsRejected($rejector->id);

                if (!$success) {
                    throw new \RuntimeException('Failed to reject expense');
                }

                Log::info('Expense rejected successfully', [
                    'expense_id' => $expense->id,
                    'rejector_id' => $rejector->id,
                    'expense_user_id' => $expense->user_id,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                ]);

                return $expense->fresh();

            } catch (\Exception $e) {
                Log::error('Error rejecting expense', [
                    'expense_id' => $expense->id,
                    'rejector_id' => $rejector->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Get paginated expenses for a user with filters.
     */
    public function getUserExpenses(User $user, array $filters = []): LengthAwarePaginator
    {
        try {
            $query = OopExpense::forUser($user->id)
                ->with(['approvedBy', 'project'])
                ->latest();

            // Apply filters
            $query = $this->applyExpenseFilters($query, $filters);

            // Get pagination size
            $pageSize = $this->getValidPageSize($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE);

            return $query->paginate($pageSize);

        } catch (\Exception $e) {
            Log::error('Error getting user expenses', [
                'user_id' => $user->id,
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete an expense.
     */
    public function deleteExpense(OopExpense $expense): bool
    {
        return DB::transaction(function () use ($expense) {
            try {
                if (!$expense->canBeDeleted()) {
                    throw new \InvalidArgumentException('Only pending expenses can be deleted');
                }

                $expenseId = $expense->id;
                $userId = $expense->user_id;
                $success = $expense->delete();

                if ($success) {
                    Log::info('Expense deleted successfully', [
                        'expense_id' => $expenseId,
                        'user_id' => $userId,
                    ]);
                }

                return $success;

            } catch (\Exception $e) {
                Log::error('Error deleting expense', [
                    'expense_id' => $expense->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Get expenses for a specific client with filters.
     */
    public function getClientExpenses(int $clientId, array $filters = []): LengthAwarePaginator
    {
        try {
            $query = OopExpense::forClient($clientId)
                ->with(['user', 'approvedBy', 'project'])
                ->latest();

            // Apply filters
            $query = $this->applyExpenseFilters($query, $filters);

            // Get pagination size
            $pageSize = $this->getValidPageSize($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE);

            return $query->paginate($pageSize);

        } catch (\Exception $e) {
            Log::error('Error getting client expenses', [
                'client_id' => $clientId,
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get expense statistics for a user.
     */
    public function getUserExpenseStats(User $user, array $filters = []): array
    {
        try {
            $query = OopExpense::forUser($user->id);
            
            // Apply date filters if provided
            if (isset($filters['start_date']) && isset($filters['end_date'])) {
                $startDate = Carbon::parse($filters['start_date']);
                $endDate = Carbon::parse($filters['end_date']);
                $query->inDateRange($startDate, $endDate);
            }

            $stats = [
                'total_expenses' => $query->count(),
                'pending_expenses' => $query->pending()->count(),
                'approved_expenses' => $query->approved()->count(),
                'rejected_expenses' => $query->rejected()->count(),
                'total_amount_by_currency' => $this->getAmountsByCurrency($query),
                'expenses_by_month' => $this->getExpensesByMonth($query),
                'expenses_by_status' => [
                    'pending' => $query->pending()->count(),
                    'approved' => $query->approved()->count(),
                    'rejected' => $query->rejected()->count(),
                ],
            ];

            return $stats;

        } catch (\Exception $e) {
            Log::error('Error getting user expense stats', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get expense statistics for a client.
     */
    public function getClientExpenseStats(int $clientId, array $filters = []): array
    {
        try {
            $query = OopExpense::forClient($clientId);
            
            // Apply date filters if provided
            if (isset($filters['start_date']) && isset($filters['end_date'])) {
                $startDate = Carbon::parse($filters['start_date']);
                $endDate = Carbon::parse($filters['end_date']);
                $query->inDateRange($startDate, $endDate);
            }

            $stats = [
                'total_expenses' => $query->count(),
                'unique_users' => $query->distinct('user_id')->count('user_id'),
                'pending_expenses' => $query->pending()->count(),
                'approved_expenses' => $query->approved()->count(),
                'rejected_expenses' => $query->rejected()->count(),
                'total_amount_by_currency' => $this->getAmountsByCurrency($query),
                'expenses_by_month' => $this->getExpensesByMonth($query),
                'top_merchants' => $this->getTopMerchants($query),
                'expenses_by_user' => $this->getExpensesByUser($query),
            ];

            return $stats;

        } catch (\Exception $e) {
            Log::error('Error getting client expense stats', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Bulk approve expenses.
     */
    public function bulkApproveExpenses(array $expenseIds, User $approver): array
    {
        return DB::transaction(function () use ($expenseIds, $approver) {
            $results = [
                'approved' => [],
                'failed' => [],
                'skipped' => [],
            ];

            try {
                $expenses = OopExpense::whereIn('id', $expenseIds)->get();

                foreach ($expenses as $expense) {
                    try {
                        if (!$expense->canBeApproved()) {
                            $results['skipped'][] = [
                                'expense_id' => $expense->id,
                                'reason' => 'Expense cannot be approved (not pending)',
                            ];
                            continue;
                        }

                        $this->approveExpense($expense, $approver);
                        $results['approved'][] = $expense->id;

                    } catch (\Exception $e) {
                        $results['failed'][] = [
                            'expense_id' => $expense->id,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                Log::info('Bulk approve completed', [
                    'approver_id' => $approver->id,
                    'approved_count' => count($results['approved']),
                    'failed_count' => count($results['failed']),
                    'skipped_count' => count($results['skipped']),
                ]);

                return $results;

            } catch (\Exception $e) {
                Log::error('Error in bulk approve', [
                    'approver_id' => $approver->id,
                    'expense_ids' => $expenseIds,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Bulk reject expenses.
     */
    public function bulkRejectExpenses(array $expenseIds, User $rejector): array
    {
        return DB::transaction(function () use ($expenseIds, $rejector) {
            $results = [
                'rejected' => [],
                'failed' => [],
                'skipped' => [],
            ];

            try {
                $expenses = OopExpense::whereIn('id', $expenseIds)->get();

                foreach ($expenses as $expense) {
                    try {
                        if (!$expense->canBeRejected()) {
                            $results['skipped'][] = [
                                'expense_id' => $expense->id,
                                'reason' => 'Expense cannot be rejected (not pending)',
                            ];
                            continue;
                        }

                        $this->rejectExpense($expense, $rejector);
                        $results['rejected'][] = $expense->id;

                    } catch (\Exception $e) {
                        $results['failed'][] = [
                            'expense_id' => $expense->id,
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                Log::info('Bulk reject completed', [
                    'rejector_id' => $rejector->id,
                    'rejected_count' => count($results['rejected']),
                    'failed_count' => count($results['failed']),
                    'skipped_count' => count($results['skipped']),
                ]);

                return $results;

            } catch (\Exception $e) {
                Log::error('Error in bulk reject', [
                    'rejector_id' => $rejector->id,
                    'expense_ids' => $expenseIds,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Process expense data