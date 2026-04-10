<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseService
 * 
 * Core business logic service for pocket expense operations.
 * Handles CRUD operations with proper transaction management,
 * metadata attachment, and business rule enforcement.
 */
class PocketExpenseService
{
    /**
     * Create a new pocket expense with metadata.
     * 
     * @param array $data Expense data including metadata
     * @param int $userId User ID who is creating the expense
     * @return PocketExpense
     * @throws \Exception
     */
    public function createExpense(array $data, int $userId): PocketExpense
    {
        return DB::transaction(function () use ($data, $userId) {
            // Apply amount sign based on expense type
            $expenseType = OptPocketExpenseType::find($data['expense_type']);
            if ($expenseType) {
                $data['amount'] = abs($data['amount']) * $expenseType->getSignMultiplier();
            }

            // Prepare expense data
            $expenseData = [
                'uuid' => (string) Str::uuid(),
                'user_id' => $data['user_id'] ?? $userId,
                'client_id' => $data['client_id'],
                'date' => $data['date'],
                'merchant_name' => $data['merchant_name'],
                'merchant_description' => $data['merchant_description'] ?? null,
                'expense_type' => $data['expense_type'],
                'currency' => strtoupper($data['currency']),
                'amount' => $data['amount'],
                'merchant_address' => $data['merchant_address'] ?? null,
                'vat_amount' => $data['vat_amount'] ?? null,
                'notes' => trim($data['notes'] ?? ''),
                'status' => $data['status'] ?? 'draft',
                'created_by_user_id' => $userId,
                'updated_by_user_id' => null,
                'approved_by_user_id' => null,
                'create_time' => now(),
                'update_time' => null,
                'deleted' => 0,
                'delete_time' => null,
            ];

            // Create the expense
            $expense = PocketExpense::create($expenseData);

            // Attach metadata if provided
            if (!empty($data['metadata'])) {
                $this->attachMetadata($expense, $data['metadata']);
            }

            return $expense->fresh();
        });
    }

    /**
     * Update an existing pocket expense with metadata.
     * 
     * @param PocketExpense $expense The expense to update
     * @param array $data Updated expense data including metadata
     * @param int $userId User ID who is updating the expense
     * @return PocketExpense
     * @throws \Exception
     */
    public function updateExpense(PocketExpense $expense, array $data, int $userId): PocketExpense
    {
        return DB::transaction(function () use ($expense, $data, $userId) {
            // Apply amount sign based on expense type if expense type is being updated
            if (isset($data['expense_type'])) {
                $expenseType = OptPocketExpenseType::find($data['expense_type']);
                if ($expenseType && isset($data['amount'])) {
                    $data['amount'] = abs($data['amount']) * $expenseType->getSignMultiplier();
                }
            }

            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'date', 'merchant_name', 'merchant_description', 'expense_type',
                'currency', 'amount', 'merchant_address', 'vat_amount', 'notes', 'status'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    if ($field === 'currency') {
                        $updateData[$field] = strtoupper($data[$field]);
                    } elseif ($field === 'notes') {
                        $updateData[$field] = trim($data[$field] ?? '');
                    } else {
                        $updateData[$field] = $data[$field];
                    }
                }
            }

            // Set audit fields
            $updateData['updated_by_user_id'] = $userId;
            $updateData['update_time'] = now();

            // Update the expense
            $expense->update($updateData);

            // Update metadata if provided
            if (!empty($data['metadata'])) {
                // Soft delete existing metadata
                $expense->metadata()->where('deleted', 0)->update([
                    'deleted' => 1,
                    'delete_time' => now(),
                ]);
                
                // Attach new metadata
                $this->attachMetadata($expense, $data['metadata']);
            }

            return $expense->fresh();
        });
    }

    /**
     * Soft delete a pocket expense and its metadata.
     * 
     * @param PocketExpense $expense The expense to delete
     * @param int $userId User ID who is deleting the expense
     * @return bool
     * @throws \Exception
     */
    public function deleteExpense(PocketExpense $expense, int $userId): bool
    {
        return DB::transaction(function () use ($expense, $userId) {
            // Soft delete associated metadata first
            $expense->metadata()->where('deleted', 0)->update([
                'deleted' => 1,
                'delete_time' => now(),
            ]);

            // Soft delete the expense
            $expense->update([
                'deleted' => 1,
                'delete_time' => now(),
                'updated_by_user_id' => $userId,
                'update_time' => now(),
            ]);

            return true;
        });
    }

    /**
     * Approve a pocket expense.
     * 
     * @param PocketExpense $expense The expense to approve
     * @param int $userId User ID who is approving the expense
     * @return PocketExpense
     * @throws \Exception
     */
    public function approveExpense(PocketExpense $expense, int $userId): PocketExpense
    {
        return DB::transaction(function () use ($expense, $userId) {
            $expense->update([
                'status' => 'approved',
                'approved_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'update_time' => now(),
            ]);

            return $expense->fresh();
        });
    }

    /**
     * Get expenses for a specific user within a client context.
     * 
     * @param int $userId User ID to get expenses for
     * @param int $clientId Client ID for multi-tenancy scoping
     * @param array $filters Optional filters (status, date_from, date_to, currency)
     * @return Collection
     */
    public function getExpensesForUser(int $userId, int $clientId, array $filters = []): Collection
    {
        $query = PocketExpense::with(['expenseType', 'metadata' => function ($query) {
            $query->where('deleted', 0);
        }])
        ->where('user_id', $userId)
        ->where('client_id', $clientId)
        ->where('deleted', 0)
        ->orderBy('date', 'desc')
        ->orderBy('create_time', 'desc');

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }

        if (!empty($filters['currency'])) {
            $query->where('currency', strtoupper($filters['currency']));
        }

        return $query->get();
    }

    /**
     * Attach metadata to a pocket expense.
     * 
     * @param PocketExpense $expense The expense to attach metadata to
     * @param array $metadata Array of metadata to attach
     * @return void
     * @throws \Exception
     */
    public function attachMetadata(PocketExpense $expense, array $metadata): void
    {
        foreach ($metadata as $metadataItem) {
            $metadataData = [
                'pocket_expense_id' => $expense->id,
                'metadata_type' => $metadataItem['metadata_type'],
                'user_id' => $expense->user_id,
                'create_time' => now(),
                'update_time' => null,
                'deleted' => 0,
                'delete_time' => null,
            ];

            // Set specific foreign key based on metadata type
            switch ($metadataItem['metadata_type']) {
                case 'category':
                    $metadataData['transaction_category_id'] = $metadataItem['transaction_category_id'] ?? null;
                    break;
                case 'tracking_code_type_1':
                case 'tracking_code_type_2':
                    $metadataData['tracking_code_id'] = $metadataItem['tracking_code_id'] ?? null;
                    break;
                case 'project':
                    $metadataData['project_id'] = $metadataItem['project_id'] ?? null;
                    break;
                case 'file':
                    $metadataData['file_store_id'] = $metadataItem['file_store_id'] ?? null;
                    break;
                case 'expense_source':
                    $metadataData['expense_source_id'] = $metadataItem['expense_source_id'] ?? null;
                    break;
                case 'additional_field':
                    $metadataData['additional_field_id'] = $metadataItem['additional_field_id'] ?? null;
                    break;
            }

            // Add JSON details if provided
            if (!empty($metadataItem['details_json'])) {
                $metadataData['details_json'] = is_array($metadataItem['details_json']) 
                    ? json_encode($metadataItem['details_json']) 
                    : $metadataItem['details_json'];
            }

            PocketExpenseMetadata::create($metadataData);
        }
    }

    /**
     * Get expenses by status for a client.
     * 
     * @param int $clientId Client ID for multi-tenancy scoping
     * @param string $status Status to filter by
     * @param int|null $userId Optional user ID to filter by specific user
     * @return Collection
     */
    public function getExpensesByStatus(int $clientId, string $status, int $userId = null): Collection
    {
        $query = PocketExpense::with(['user', 'expenseType', 'metadata' => function ($query) {
            $query->where('deleted', 0);
        }])
        ->where('client_id', $clientId)
        ->where('status', $status)
        ->where('deleted', 0)
        ->orderBy('date', 'desc');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

    /**
     * Get expense statistics for a client.
     * 
     * @param int $clientId Client ID for multi-tenancy scoping
     * @param int|null $userId Optional user ID to filter by specific user
     * @param string|null $dateFrom Optional start date for filtering
     * @param string|null $dateTo Optional end date for filtering
     * @return array
     */
    public function getExpenseStatistics(int $clientId, int $userId = null, string $dateFrom = null, string $dateTo = null): array
    {
        $query = PocketExpense::where('client_id', $clientId)
            ->where('deleted', 0);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($dateFrom !== null) {
            $query->where('date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('date', '<=', $dateTo);
        }

        $expenses = $query->get();

        return [
            'total_count' => $expenses->count(),
            'total_amount' => $expenses->sum('amount'),
            'by_status' => [
                'draft' => $expenses->where('status', 'draft')->count(),
                'submitted' => $expenses->where('status', 'submitted')->count(),
                'approved' => $expenses->where('status', 'approved')->count(),
                'rejected' => $expenses->where('status', 'rejected')->count(),
            ],
            'by_currency' => $expenses->groupBy('currency')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'total_amount' => $group->sum('amount'),
                ];
            })->toArray(),
            'average_amount' => $expenses->count() > 0 ? $expenses->avg('amount') : 0,
        ];
    }

    /**
     * Bulk create expenses from CSV upload data.
     * Used by ProcessExpenseUpload job for background processing.
     * 
     * @param array $expensesData Array of expense data from CSV processing
     * @param int $clientId Client ID for multi-tenancy scoping
     * @param int $userId Target user ID for expenses
     * @param int $createdByUserId Admin user ID who uploaded the file
     * @return Collection
     * @throws \Exception
     */
    public function bulkCreateExpenses(array $expensesData, int $clientId, int $userId, int $createdByUserId): Collection
    {
        return DB::transaction(function () use ($expensesData, $clientId, $userId, $createdByUserId) {
            $createdExpenses = collect();

            foreach ($expensesData as $expenseData) {
                // TODO: Implement expense type lookup by option name
                // For now, use a placeholder lookup
                $expenseType = OptPocketExpenseType::where('option', $expenseData['expense_type'])->first();
                if (!$expenseType) {
                    continue; // Skip invalid expense types
                }

                // Apply amount sign based on expense type
                $amount = abs($expenseData['amount']) * $expenseType->getSignMultiplier();

                $expense = PocketExpense::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $userId,
                    'client_id' => $clientId,
                    'date' => $expenseData['date'],
                    'merchant_name' => $expenseData['merchant_name'],
                    'merchant_description' => $expenseData['description'] ?? null,
                    'expense_type' => $expenseType->id,
                    'currency' => strtoupper($expenseData['currency_code']),
                    'amount' => $amount,
                    'merchant_address' => $expenseData['merchant_address'] ?? null,
                    'vat_amount' => $expenseData['vat_amount'] ?? null,
                    'notes' => trim($expenseData['notes'] ?? ''),
                    'status' => 'submitted', // CSV uploads default to submitted status
                    'created_by_user_id' => $createdByUserId,
                    'create_time' => now(),
                    'deleted' => 0,
                ]);

                // Attach expense source metadata if provided
                if (!empty($expenseData['source']) && $expenseData['source'] !== 'Other') {
                    // TODO: Implement expense source lookup and metadata attachment
                    // This requires PocketExpenseSourceClientConfig integration
                }

                // Attach source note metadata if source is 'Other'
                if (!empty($expenseData['source_note'])) {
                    PocketExpenseMetadata::create([
                        'pocket_expense_id' => $expense->id,
                        'metadata_type' => 'expense_source',
                        'user_id' => $userId,
                        'details_json' => json_encode(['source_note' => $expenseData['source_note']]),
                        'create_time' => now(),
                        'deleted' => 0,
                    ]);
                }

                $createdExpenses->push($expense);
            }

            return $createdExpenses;
        });
    }

    /**
     * Validate business rules for expense creation/update.
     * 
     * @param array $data Expense data to validate
     * @param int $clientId Client ID for context validation
     * @return array Array of validation errors, empty if valid
     */
    public function validateBusinessRules(array $data, int $clientId): array
    {
        $errors = [];

        // Validate date is not older than 3 years
        if (!empty($data['date'])) {
            $expenseDate = Carbon::parse($data['date']);
            $threeYearsAgo = Carbon::now()->subYears(3);
            
            if ($expenseDate->lt($threeYearsAgo)) {
                $errors[] = 'Expense date cannot be older than 3 years.';
            }
        }

        // Validate currency code format
        if (!empty($data['currency'])) {
            if (strlen($data['currency']) !== 3) {
                $errors[] = 'Currency code must be 3 characters long.';
            }
            // TODO: Validate against platform currency list
        }

        // Validate VAT percentage range
        if (isset($data['vat_amount']) && $data['vat_amount'] !== null) {
            if ($data['vat_amount'] < 0 || $data['vat_amount'] > 100) {
                $errors[] = 'VAT percentage must be between 0 and 100.';
            }
        }

        // Validate merchant name length
        if (!empty($data['merchant_name']) && strlen($data['merchant_name']) > 180) {
            $errors[] = 'Merchant name cannot exceed 180 characters.';
        }

        return $errors;
    }
}