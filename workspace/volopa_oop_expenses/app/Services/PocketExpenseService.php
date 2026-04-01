<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\User;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PocketExpenseService
 * 
 * Business logic service for pocket expense CRUD operations.
 * Handles expense creation, updates, deletion, and retrieval with proper authorization checks.
 * Integrates with FX conversion and maintains audit trails.
 */
class PocketExpenseService
{
    /**
     * Create a new expense record.
     *
     * @param array $data
     * @return PocketExpense
     * @throws \Exception
     */
    public function createExpense(array $data): PocketExpense
    {
        return DB::transaction(function () use ($data) {
            // Generate UUID if not provided
            if (!isset($data['uuid'])) {
                $data['uuid'] = Str::uuid()->toString();
            }

            // Set default status if not provided
            if (!isset($data['status'])) {
                $data['status'] = 'draft';
            }

            // Set created_by_user_id if not provided (should be set from authenticated user)
            if (!isset($data['created_by_user_id'])) {
                $data['created_by_user_id'] = $data['user_id'];
            }

            // Validate required fields
            $this->validateExpenseData($data);

            // Apply amount sign based on expense type
            $data = $this->applyAmountSignByExpenseType($data);

            // Create the expense
            $expense = PocketExpense::create($data);

            return $expense;
        });
    }

    /**
     * Update an existing expense record.
     *
     * @param PocketExpense $expense
     * @param array $data
     * @return PocketExpense
     * @throws \Exception
     */
    public function updateExpense(PocketExpense $expense, array $data): PocketExpense
    {
        return DB::transaction(function () use ($expense, $data) {
            // Set updated_by_user_id if not provided
            if (!isset($data['updated_by_user_id'])) {
                // TODO: Get from authenticated user context
                $data['updated_by_user_id'] = $expense->user_id;
            }

            // Validate updated data
            $this->validateExpenseData($data, $expense);

            // Apply amount sign based on expense type if expense type is being changed
            if (isset($data['expense_type']) || isset($data['amount'])) {
                $data = $this->applyAmountSignByExpenseType($data, $expense);
            }

            // Update the expense
            $expense->update($data);

            return $expense->fresh();
        });
    }

    /**
     * Soft delete an expense record.
     *
     * @param PocketExpense $expense
     * @return bool
     * @throws \Exception
     */
    public function deleteExpense(PocketExpense $expense): bool
    {
        return DB::transaction(function () use ($expense) {
            // Perform soft delete using Volopa pattern
            $expense->deleted = 1;
            $expense->delete_time = now();
            $expense->update_time = now();

            return $expense->save();
        });
    }

    /**
     * Get expenses for a specific user within a client context.
     *
     * @param int $userId
     * @param int $clientId
     * @return Collection
     */
    public function getUserExpenses(int $userId, int $clientId): Collection
    {
        return PocketExpense::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('deleted', 0)
            ->with(['expenseType', 'createdBy', 'updatedBy', 'approvedBy'])
            ->orderBy('date', 'desc')
            ->orderBy('create_time', 'desc')
            ->get();
    }

    /**
     * Approve an expense record.
     *
     * @param PocketExpense $expense
     * @param int $approverId
     * @return PocketExpense
     * @throws \Exception
     */
    public function approveExpense(PocketExpense $expense, int $approverId): PocketExpense
    {
        return DB::transaction(function () use ($expense, $approverId) {
            // Validate that expense can be approved
            if ($expense->status === 'approved') {
                throw new \Exception('Expense is already approved');
            }

            if ($expense->status !== 'submitted') {
                throw new \Exception('Only submitted expenses can be approved');
            }

            // Update expense status and approval details
            $expense->status = 'approved';
            $expense->approved_by_user_id = $approverId;
            $expense->updated_by_user_id = $approverId;
            $expense->update_time = now();

            $expense->save();

            return $expense->fresh();
        });
    }

    /**
     * Reject an expense record.
     *
     * @param PocketExpense $expense
     * @param int $rejectorId
     * @return PocketExpense
     * @throws \Exception
     */
    public function rejectExpense(PocketExpense $expense, int $rejectorId): PocketExpense
    {
        return DB::transaction(function () use ($expense, $rejectorId) {
            // Validate that expense can be rejected
            if ($expense->status === 'rejected') {
                throw new \Exception('Expense is already rejected');
            }

            if ($expense->status !== 'submitted') {
                throw new \Exception('Only submitted expenses can be rejected');
            }

            // Update expense status and rejection details
            $expense->status = 'rejected';
            $expense->approved_by_user_id = $rejectorId; // Store who performed the rejection
            $expense->updated_by_user_id = $rejectorId;
            $expense->update_time = now();

            $expense->save();

            return $expense->fresh();
        });
    }

    /**
     * Submit an expense for approval.
     *
     * @param PocketExpense $expense
     * @param int $submitterId
     * @return PocketExpense
     * @throws \Exception
     */
    public function submitExpense(PocketExpense $expense, int $submitterId): PocketExpense
    {
        return DB::transaction(function () use ($expense, $submitterId) {
            // Validate that expense can be submitted
            if ($expense->status !== 'draft') {
                throw new \Exception('Only draft expenses can be submitted');
            }

            // Update expense status
            $expense->status = 'submitted';
            $expense->updated_by_user_id = $submitterId;
            $expense->update_time = now();

            $expense->save();

            return $expense->fresh();
        });
    }

    /**
     * Get expenses by status for a client.
     *
     * @param int $clientId
     * @param string $status
     * @return Collection
     */
    public function getExpensesByStatus(int $clientId, string $status): Collection
    {
        return PocketExpense::where('client_id', $clientId)
            ->where('status', $status)
            ->where('deleted', 0)
            ->with(['user', 'expenseType', 'createdBy', 'updatedBy', 'approvedBy'])
            ->orderBy('date', 'desc')
            ->orderBy('create_time', 'desc')
            ->get();
    }

    /**
     * Get expenses that require approval (submitted status).
     *
     * @param int $clientId
     * @return Collection
     */
    public function getPendingApprovals(int $clientId): Collection
    {
        return $this->getExpensesByStatus($clientId, 'submitted');
    }

    /**
     * Validate expense data before create/update operations.
     *
     * @param array $data
     * @param PocketExpense|null $existingExpense
     * @throws \Exception
     */
    private function validateExpenseData(array $data, ?PocketExpense $existingExpense = null): void
    {
        // Validate required fields for creation
        if (!$existingExpense) {
            $required = ['user_id', 'client_id', 'date', 'merchant_name', 'expense_type', 'currency', 'amount'];
            
            foreach ($required as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    throw new \Exception("Field {$field} is required");
                }
            }
        }

        // Validate date is not older than 3 years
        if (isset($data['date'])) {
            $expenseDate = \Carbon\Carbon::parse($data['date']);
            $threeYearsAgo = now()->subYears(3);
            
            if ($expenseDate->lt($threeYearsAgo)) {
                throw new \Exception('Expense date cannot be older than 3 years');
            }
        }

        // Validate merchant name length
        if (isset($data['merchant_name']) && strlen($data['merchant_name']) > 180) {
            throw new \Exception('Merchant name cannot exceed 180 characters');
        }

        // Validate currency code format
        if (isset($data['currency'])) {
            if (strlen($data['currency']) !== 3) {
                throw new \Exception('Currency code must be 3 characters');
            }
            
            // TODO: Validate against allowed currency list from platform infrastructure
        }

        // Validate VAT amount if provided
        if (isset($data['vat_amount']) && $data['vat_amount'] !== null) {
            if ($data['vat_amount'] < 0 || $data['vat_amount'] > 100) {
                throw new \Exception('VAT amount must be between 0 and 100');
            }
        }

        // Validate status enum values
        if (isset($data['status'])) {
            $validStatuses = ['draft', 'submitted', 'approved', 'rejected'];
            if (!in_array($data['status'], $validStatuses)) {
                throw new \Exception('Invalid status. Must be one of: ' . implode(', ', $validStatuses));
            }
        }
    }

    /**
     * Apply the correct amount sign based on expense type.
     *
     * @param array $data
     * @param PocketExpense|null $existingExpense
     * @return array
     */
    private function applyAmountSignByExpenseType(array $data, ?PocketExpense $existingExpense = null): array
    {
        $expenseTypeId = $data['expense_type'] ?? $existingExpense?->expense_type;
        $amount = $data['amount'] ?? $existingExpense?->amount;

        if ($expenseTypeId && $amount !== null) {
            // TODO: Load expense type and apply sign based on amount_sign field
            // For now, implement basic logic based on system constraints
            // Refund from Merchant = positive, others = negative
            
            // This is a placeholder - should load from OptPocketExpenseType model
            $expenseType = \App\Models\OptPocketExpenseType::find($expenseTypeId);
            
            if ($expenseType) {
                $data['amount'] = $expenseType->applySignToAmount($amount);
            }
        }

        return $data;
    }

    /**
     * Get expense statistics for a client.
     *
     * @param int $clientId
     * @param string|null $period Optional period filter (e.g., 'this_month', 'last_month', 'this_year')
     * @return array
     */
    public function getExpenseStatistics(int $clientId, ?string $period = null): array
    {
        $query = PocketExpense::where('client_id', $clientId)->where('deleted', 0);

        // Apply period filter if provided
        if ($period) {
            $query = $this->applyPeriodFilter($query, $period);
        }

        return [
            'total_count' => $query->count(),
            'total_amount' => $query->sum('amount'),
            'draft_count' => (clone $query)->where('status', 'draft')->count(),
            'submitted_count' => (clone $query)->where('status', 'submitted')->count(),
            'approved_count' => (clone $query)->where('status', 'approved')->count(),
            'rejected_count' => (clone $query)->where('status', 'rejected')->count(),
            'average_amount' => $query->avg('amount') ?: 0,
        ];
    }

    /**
     * Apply period filter to expense query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $period
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyPeriodFilter($query, string $period)
    {
        switch ($period) {
            case 'this_month':
                return $query->whereMonth('date', now()->month)
                           ->whereYear('date', now()->year);
                           
            case 'last_month':
                $lastMonth = now()->subMonth();
                return $query->whereMonth('date', $lastMonth->month)
                           ->whereYear('date', $lastMonth->year);
                           
            case 'this_year':
                return $query->whereYear('date', now()->year);
                
            case 'last_year':
                return $query->whereYear('date', now()->subYear()->year);
                
            default:
                return $query;
        }
    }

    /**
     * Batch create expenses from validated CSV data.
     *
     * @param array $expensesData Array of validated expense data
     * @param int $clientId
     * @param int $userId Target user for expenses
     * @param int $createdByUserId Admin user creating the expenses
     * @return array Results with success/failure counts
     */
    public function createExpenseBatch(array $expensesData, int $clientId, int $userId, int $createdByUserId): array
    {
        $successCount = 0;
        $failureCount = 0;
        $errors = [];

        return DB::transaction(function () use ($expensesData, $clientId, $userId, $createdByUserId, &$successCount, &$failureCount, &$errors) {
            foreach ($expensesData as $index => $expenseData) {
                try {
                    // Set standard fields
                    $expenseData['client_id'] = $clientId;
                    $expenseData['user_id'] = $userId;
                    $expenseData['created_by_user_id'] = $createdByUserId;
                    $expenseData['status'] = 'submitted'; // CSV uploads default to submitted

                    $this->createExpense($expenseData);
                    $successCount++;
                } catch (\Exception $e) {
                    $failureCount++;
                    $errors[] = [
                        'line_number' => $index + 2, // +2 for header row and 0-based index
                        'error' => $e->getMessage(),
                        'data' => $expenseData,
                    ];
                }
            }

            return [
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'errors' => $errors,
                'total_processed' => count($expensesData),
            ];
        });
    }
}