<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pocket Expense Service
 * 
 * Core service for managing pocket expense CRUD operations.
 * Handles business logic for expense creation, updates, deletion, and retrieval.
 * Integrates with FX conversion service for currency calculations.
 */
class PocketExpenseService
{
    /**
     * The FX service instance.
     *
     * @var PocketExpenseFXService
     */
    protected PocketExpenseFXService $fxService;

    /**
     * Create a new PocketExpenseService instance.
     *
     * @param PocketExpenseFXService $fxService
     */
    public function __construct(PocketExpenseFXService $fxService)
    {
        $this->fxService = $fxService;
    }

    /**
     * Create a new pocket expense.
     *
     * @param array $data Expense data
     * @return PocketExpense
     * @throws \Exception
     */
    public function create(array $data): PocketExpense
    {
        DB::beginTransaction();
        
        try {
            // Create the base expense record
            $expense = new PocketExpense();
            $expense->fill($data);
            
            // Set default status if not provided
            if (!isset($data['status'])) {
                $expense->status = 'draft';
            }
            
            // Set created_by_user_id from authenticated user or provided data
            if (!isset($data['created_by_user_id']) && isset($data['user_id'])) {
                $expense->created_by_user_id = $data['user_id'];
            }
            
            $expense->save();
            
            // Process FX conversion if needed
            if (isset($data['currency']) && $data['currency'] !== $this->getClientBaseCurrency($data['client_id'])) {
                $fxResult = $this->fxService->convertExpenseAmount($expense);
                
                // TODO: Store FX conversion results in metadata or expense record
                // This depends on the exact FX storage strategy which needs clarification
                Log::info('FX conversion processed', [
                    'expense_id' => $expense->id,
                    'fx_result' => $fxResult
                ]);
            }
            
            // Create metadata records if provided
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $this->createMetadata($expense->id, $data['metadata']);
            }
            
            DB::commit();
            
            // Reload the expense with relationships
            return $expense->fresh(['user', 'client', 'expenseType', 'metadata']);
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to create pocket expense', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing pocket expense.
     *
     * @param int $id Expense ID
     * @param array $data Updated expense data
     * @return PocketExpense
     * @throws \Exception
     */
    public function update(int $id, array $data): PocketExpense
    {
        DB::beginTransaction();
        
        try {
            $expense = $this->findById($id);
            
            // Store original data for comparison
            $originalCurrency = $expense->currency;
            $originalAmount = $expense->amount;
            
            // Update the expense
            $expense->fill($data);
            
            // Set updated_by_user_id if provided
            if (isset($data['updated_by_user_id'])) {
                $expense->updated_by_user_id = $data['updated_by_user_id'];
            }
            
            $expense->save();
            
            // Recalculate FX if currency or amount changed
            if ($expense->currency !== $originalCurrency || $expense->amount != $originalAmount) {
                if ($expense->currency !== $this->getClientBaseCurrency($expense->client_id)) {
                    $fxResult = $this->fxService->convertExpenseAmount($expense);
                    
                    // TODO: Update FX conversion results in metadata or expense record
                    Log::info('FX conversion recalculated on update', [
                        'expense_id' => $expense->id,
                        'fx_result' => $fxResult
                    ]);
                }
            }
            
            // Update metadata if provided
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $this->updateMetadata($expense->id, $data['metadata']);
            }
            
            DB::commit();
            
            // Reload the expense with relationships
            return $expense->fresh(['user', 'client', 'expenseType', 'metadata']);
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to update pocket expense', [
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Soft delete a pocket expense.
     *
     * @param int $id Expense ID
     * @return bool
     * @throws \Exception
     */
    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $expense = $this->findById($id);
            
            // Only allow deletion of draft expenses per business rules
            if (!$expense->canDelete()) {
                throw new \Exception('Cannot delete expense in current status: ' . $expense->status);
            }
            
            // Soft delete the expense using flag-based soft delete
            $result = $expense->softDelete();
            
            // Soft delete associated metadata
            $expense->metadata()->update([
                'deleted' => true,
                'delete_time' => now()
            ]);
            
            DB::commit();
            
            Log::info('Pocket expense deleted', ['expense_id' => $id]);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to delete pocket expense', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Find a pocket expense by ID.
     *
     * @param int $id Expense ID
     * @return PocketExpense
     * @throws \Exception
     */
    public function findById(int $id): PocketExpense
    {
        $expense = PocketExpense::with(['user', 'client', 'expenseType', 'metadata'])
            ->active()
            ->find($id);
            
        if (!$expense) {
            throw new \Exception('Pocket expense not found or deleted: ' . $id);
        }
        
        return $expense;
    }

    /**
     * Get expenses for a specific user and client.
     *
     * @param int $userId User ID
     * @param int $clientId Client ID
     * @return Collection
     */
    public function getByUser(int $userId, int $clientId): Collection
    {
        return PocketExpense::with(['user', 'client', 'expenseType', 'metadata'])
            ->active()
            ->forUser($userId)
            ->forClient($clientId)
            ->orderBy('date', 'desc')
            ->orderBy('create_time', 'desc')
            ->get();
    }

    /**
     * Get expenses for a client with optional filters.
     *
     * @param int $clientId Client ID
     * @param array $filters Optional filters (status, user_id, date_from, date_to)
     * @return Collection
     */
    public function getForClient(int $clientId, array $filters = []): Collection
    {
        $query = PocketExpense::with(['user', 'client', 'expenseType', 'metadata'])
            ->active()
            ->forClient($clientId);
        
        // Apply status filter
        if (isset($filters['status'])) {
            $query->withStatus($filters['status']);
        }
        
        // Apply user filter
        if (isset($filters['user_id'])) {
            $query->forUser($filters['user_id']);
        }
        
        // Apply date range filter
        if (isset($filters['date_from']) && isset($filters['date_to'])) {
            $query->dateRange($filters['date_from'], $filters['date_to']);
        }
        
        // Apply currency filter
        if (isset($filters['currency'])) {
            $query->withCurrency($filters['currency']);
        }
        
        return $query->orderBy('date', 'desc')
            ->orderBy('create_time', 'desc')
            ->get();
    }

    /**
     * Submit an expense (change status from draft to submitted).
     *
     * @param int $id Expense ID
     * @param int $submittedByUserId User ID of submitter
     * @return PocketExpense
     * @throws \Exception
     */
    public function submit(int $id, int $submittedByUserId): PocketExpense
    {
        DB::beginTransaction();
        
        try {
            $expense = $this->findById($id);
            
            if (!$expense->canSubmit()) {
                throw new \Exception('Cannot submit expense in current status: ' . $expense->status);
            }
            
            $expense->status = 'submitted';
            $expense->updated_by_user_id = $submittedByUserId;
            $expense->save();
            
            DB::commit();
            
            Log::info('Pocket expense submitted', [
                'expense_id' => $id,
                'submitted_by' => $submittedByUserId
            ]);
            
            return $expense->fresh(['user', 'client', 'expenseType', 'metadata']);
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to submit pocket expense', [
                'id' => $id,
                'submitted_by' => $submittedByUserId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Approve an expense.
     *
     * @param int $id Expense ID
     * @param int $approvedByUserId User ID of approver
     * @return PocketExpense
     * @throws \Exception
     */
    public function approve(int $id, int $approvedByUserId): PocketExpense
    {
        DB::beginTransaction();
        
        try {
            $expense = $this->findById($id);
            
            if (!$expense->canApprove()) {
                throw new \Exception('Cannot approve expense in current status: ' . $expense->status);
            }
            
            $result = $expense->approve($approvedByUserId);
            
            if (!$result) {
                throw new \Exception('Failed to approve expense');
            }
            
            DB::commit();
            
            Log::info('Pocket expense approved', [
                'expense_id' => $id,
                'approved_by' => $approvedByUserId
            ]);
            
            return $expense->fresh(['user', 'client', 'expenseType', 'metadata']);
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to approve pocket expense', [
                'id' => $id,
                'approved_by' => $approvedByUserId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Reject an expense.
     *
     * @param int $id Expense ID
     * @param int $rejectedByUserId User ID of rejector
     * @return PocketExpense
     * @throws \Exception
     */
    public function reject(int $id, int $rejectedByUserId): PocketExpense
    {
        DB::beginTransaction();
        
        try {
            $expense = $this->findById($id);
            
            if (!$expense->canReject()) {
                throw new \Exception('Cannot reject expense in current status: ' . $expense->status);
            }
            
            $result = $expense->reject();
            $expense->updated_by_user_id = $rejectedByUserId;
            $expense->save();
            
            if (!$result) {
                throw new \Exception('Failed to reject expense');
            }
            
            DB::commit();
            
            Log::info('Pocket expense rejected', [
                'expense_id' => $id,
                'rejected_by' => $rejectedByUserId
            ]);
            
            return $expense->fresh(['user', 'client', 'expenseType', 'metadata']);
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to reject pocket expense', [
                'id' => $id,
                'rejected_by' => $rejectedByUserId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk create expenses (used by CSV upload processing).
     *
     * @param array $expensesData Array of expense data
     * @param int $createdByUserId User ID of creator
     * @return Collection
     * @throws \Exception
     */
    public function bulkCreate(array $expensesData, int $createdByUserId): Collection
    {
        $createdExpenses = collect();
        
        DB::beginTransaction();
        
        try {
            foreach ($expensesData as $expenseData) {
                $expenseData['created_by_user_id'] = $createdByUserId;
                $expenseData['status'] = 'submitted'; // CSV uploaded expenses are automatically submitted
                
                $expense = $this->create($expenseData);
                $createdExpenses->push($expense);
            }
            
            DB::commit();
            
            Log::info('Bulk created pocket expenses', [
                'count' => $createdExpenses->count(),
                'created_by' => $createdByUserId
            ]);
            
            return $createdExpenses;
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Failed to bulk create pocket expenses', [
                'count' => count($expensesData),
                'created_by' => $createdByUserId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create metadata records for an expense.
     *
     * @param int $expenseId Expense ID
     * @param array $metadataList Array of metadata records
     * @return void
     * @throws \Exception
     */
    protected function createMetadata(int $expenseId, array $metadataList): void
    {
        foreach ($metadataList as $metadata) {
            $metadata['pocket_expense_id'] = $expenseId;
            PocketExpenseMetadata::create($metadata);
        }
    }

    /**
     * Update metadata records for an expense.
     *
     * @param int $expenseId Expense ID
     * @param array $metadataList Array of metadata records
     * @return void
     * @throws \Exception
     */
    protected function updateMetadata(int $expenseId, array $metadataList): void
    {
        // TODO: Implement metadata update logic
        // This requires determining the strategy for updating existing metadata:
        // 1. Delete all existing and recreate
        // 2. Update existing records and create new ones
        // 3. Merge approach based on metadata_type
        
        // For now, we'll use the delete-and-recreate approach
        PocketExpenseMetadata::where('pocket_expense_id', $expenseId)
            ->update([
                'deleted' => true,
                'delete_time' => now()
            ]);
        
        $this->createMetadata($expenseId, $metadataList);
    }

    /**
     * Get the base currency for a client.
     *
     * @param int $clientId Client ID
     * @return string Currency code
     */
    protected function getClientBaseCurrency(int $clientId): string
    {
        // TODO: Implement client base currency lookup
        // This depends on the client configuration or wallet base currency service
        // For now, return USD as default
        return 'USD';
    }
}