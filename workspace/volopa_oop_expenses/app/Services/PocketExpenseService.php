<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Exception;

/**
 * PocketExpenseService
 * 
 * Business logic service for pocket expense CRUD operations with proper 
 * transaction handling, client scoping, and metadata management.
 * Follows Volopa platform patterns for multi-tenancy and audit trails.
 */
class PocketExpenseService
{
    /**
     * Create a new expense with metadata in a single transaction.
     * 
     * @param array $data Expense data including metadata
     * @param int $userId User who owns this expense
     * @param int $clientId Client context for multi-tenancy
     * @return PocketExpense Created expense with relationships loaded
     * @throws ValidationException If data validation fails
     * @throws Exception If transaction fails
     */
    public function createExpense(array $data, int $userId, int $clientId): PocketExpense
    {
        return DB::transaction(function () use ($data, $userId, $clientId) {
            $now = Carbon::now();
            
            // Validate expense type exists
            $expenseType = OptPocketExpenseType::find($data['expense_type']);
            if (!$expenseType) {
                throw ValidationException::withMessages([
                    'expense_type' => ['Selected expense type does not exist.']
                ]);
            }
            
            // Apply amount sign based on expense type
            $amount = abs(floatval($data['amount']));
            if ($expenseType->amount_sign === 'negative') {
                $amount = -$amount;
            }
            
            // Create main expense record
            $expense = PocketExpense::create([
                'uuid' => Str::uuid()->toString(),
                'user_id' => $userId,
                'client_id' => $clientId,
                'date' => $data['date'],
                'merchant_name' => trim(substr($data['merchant_name'], 0, 180)),
                'merchant_description' => isset($data['merchant_description']) 
                    ? trim($data['merchant_description']) 
                    : null,
                'expense_type' => $data['expense_type'],
                'currency' => strtoupper($data['currency']),
                'amount' => $amount,
                'merchant_address' => isset($data['merchant_address']) 
                    ? trim($data['merchant_address']) 
                    : null,
                'vat_amount' => isset($data['vat_amount']) 
                    ? floatval($data['vat_amount']) 
                    : null,
                'notes' => isset($data['notes']) 
                    ? trim($data['notes']) 
                    : null,
                'status' => $data['status'] ?? 'draft',
                'created_by_user_id' => $data['created_by_user_id'] ?? $userId,
                'updated_by_user_id' => null,
                'approved_by_user_id' => null,
                'create_time' => $now,
                'update_time' => $now,
                'deleted' => 0,
                'delete_time' => null,
            ]);
            
            // Create metadata records if provided
            $this->createExpenseMetadata($expense->id, $data, $now);
            
            // Load relationships for return
            $expense->load([
                'user',
                'client', 
                'expenseType',
                'createdBy',
                'metadata'
            ]);
            
            return $expense;
        });
    }
    
    /**
     * Update an existing expense with metadata in a single transaction.
     * 
     * @param PocketExpense $expense Expense to update
     * @param array $data Updated expense data
     * @return PocketExpense Updated expense with relationships loaded
     * @throws ValidationException If data validation fails
     * @throws Exception If transaction fails
     */
    public function updateExpense(PocketExpense $expense, array $data): PocketExpense
    {
        return DB::transaction(function () use ($expense, $data) {
            $now = Carbon::now();
            
            // Validate expense type if being changed
            if (isset($data['expense_type']) && $data['expense_type'] !== $expense->expense_type) {
                $expenseType = OptPocketExpenseType::find($data['expense_type']);
                if (!$expenseType) {
                    throw ValidationException::withMessages([
                        'expense_type' => ['Selected expense type does not exist.']
                    ]);
                }
            } else {
                $expenseType = $expense->expenseType;
            }
            
            // Prepare update data
            $updateData = [
                'update_time' => $now,
                'updated_by_user_id' => $data['updated_by_user_id'] ?? $expense->created_by_user_id,
            ];
            
            // Update fields if provided
            if (isset($data['date'])) {
                $updateData['date'] = $data['date'];
            }
            
            if (isset($data['merchant_name'])) {
                $updateData['merchant_name'] = trim(substr($data['merchant_name'], 0, 180));
            }
            
            if (isset($data['merchant_description'])) {
                $updateData['merchant_description'] = trim($data['merchant_description']);
            }
            
            if (isset($data['expense_type'])) {
                $updateData['expense_type'] = $data['expense_type'];
                $expenseType = OptPocketExpenseType::find($data['expense_type']);
            }
            
            if (isset($data['currency'])) {
                $updateData['currency'] = strtoupper($data['currency']);
            }
            
            if (isset($data['amount'])) {
                $amount = abs(floatval($data['amount']));
                if ($expenseType->amount_sign === 'negative') {
                    $amount = -$amount;
                }
                $updateData['amount'] = $amount;
            }
            
            if (isset($data['merchant_address'])) {
                $updateData['merchant_address'] = trim($data['merchant_address']);
            }
            
            if (isset($data['vat_amount'])) {
                $updateData['vat_amount'] = floatval($data['vat_amount']);
            }
            
            if (isset($data['notes'])) {
                $updateData['notes'] = trim($data['notes']);
            }
            
            if (isset($data['status'])) {
                $updateData['status'] = $data['status'];
            }
            
            // Update the expense
            $expense->update($updateData);
            
            // Update metadata if provided
            if (isset($data['metadata']) || isset($data['category_id']) || isset($data['expense_source_id']) || 
                isset($data['tracking_code_id']) || isset($data['project_id']) || isset($data['additional_fields'])) {
                $this->updateExpenseMetadata($expense->id, $data, $now);
            }
            
            // Refresh and load relationships
            $expense->refresh();
            $expense->load([
                'user',
                'client',
                'expenseType', 
                'createdBy',
                'updatedBy',
                'metadata'
            ]);
            
            return $expense;
        });
    }
    
    /**
     * Soft delete an expense and its metadata.
     * 
     * @param PocketExpense $expense Expense to delete
     * @return bool True if deletion successful
     * @throws Exception If transaction fails
     */
    public function deleteExpense(PocketExpense $expense): bool
    {
        return DB::transaction(function () use ($expense) {
            $now = Carbon::now();
            
            // Soft delete metadata records
            PocketExpenseMetadata::where('pocket_expense_id', $expense->id)
                ->where('deleted', 0)
                ->update([
                    'deleted' => 1,
                    'delete_time' => $now,
                    'update_time' => $now,
                ]);
            
            // Soft delete the expense
            $expense->update([
                'deleted' => 1,
                'delete_time' => $now,
                'update_time' => $now,
            ]);
            
            return true;
        });
    }
    
    /**
     * Get user expenses with filtering and client scoping.
     * 
     * @param int $userId User ID to filter expenses
     * @param int $clientId Client ID for multi-tenancy scoping
     * @param array $filters Optional filters (status, date_from, date_to, currency, etc.)
     * @return Collection Filtered expenses with relationships
     */
    public function getUserExpenses(int $userId, int $clientId, array $filters = []): Collection
    {
        $query = PocketExpense::with([
                'user',
                'expenseType',
                'createdBy', 
                'updatedBy',
                'approvedBy',
                'metadata.transactionCategory',
                'metadata.trackingCode',
                'metadata.project',
                'metadata.fileStore',
                'metadata.expenseSource',
                'metadata.additionalField'
            ])
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('deleted', 0);
        
        // Apply status filter
        if (isset($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }
        
        // Apply date range filters
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        // Apply currency filter
        if (isset($filters['currency'])) {
            if (is_array($filters['currency'])) {
                $query->whereIn('currency', $filters['currency']);
            } else {
                $query->where('currency', $filters['currency']);
            }
        }
        
        // Apply expense type filter
        if (isset($filters['expense_type'])) {
            if (is_array($filters['expense_type'])) {
                $query->whereIn('expense_type', $filters['expense_type']);
            } else {
                $query->where('expense_type', $filters['expense_type']);
            }
        }
        
        // Apply amount range filters
        if (isset($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }
        
        if (isset($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }
        
        // Apply merchant name search
        if (isset($filters['merchant_name'])) {
            $query->where('merchant_name', 'like', '%' . $filters['merchant_name'] . '%');
        }
        
        // Apply sorting
        $sortField = $filters['sort_by'] ?? 'date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        
        // Validate sort field to prevent injection
        $allowedSortFields = [
            'date', 'amount', 'currency', 'status', 'merchant_name', 
            'create_time', 'update_time'
        ];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('date', 'desc');
        }
        
        // Apply limit if specified
        if (isset($filters['limit'])) {
            $query->limit(intval($filters['limit']));
        }
        
        return $query->get();
    }
    
    /**
     * Approve an expense with audit trail.
     * 
     * @param PocketExpense $expense Expense to approve
     * @param int $approverId User ID of the approver
     * @return PocketExpense Approved expense with relationships loaded
     * @throws ValidationException If expense cannot be approved
     * @throws Exception If transaction fails
     */
    public function approveExpense(PocketExpense $expense, int $approverId): PocketExpense
    {
        return DB::transaction(function () use ($expense, $approverId) {
            // Validate current status allows approval
            if (!in_array($expense->status, ['submitted', 'draft'])) {
                throw ValidationException::withMessages([
                    'status' => ['Expense cannot be approved from current status: ' . $expense->status]
                ]);
            }
            
            $now = Carbon::now();
            
            // Update expense status to approved
            $expense->update([
                'status' => 'approved',
                'approved_by_user_id' => $approverId,
                'updated_by_user_id' => $approverId,
                'update_time' => $now,
            ]);
            
            // Load relationships for return
            $expense->refresh();
            $expense->load([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy', 
                'approvedBy',
                'metadata'
            ]);
            
            return $expense;
        });
    }
    
    /**
     * Reject an expense with optional reason.
     * 
     * @param PocketExpense $expense Expense to reject
     * @param int $rejectorId User ID of the rejector
     * @param string|null $reason Optional rejection reason
     * @return PocketExpense Rejected expense with relationships loaded
     * @throws ValidationException If expense cannot be rejected
     * @throws Exception If transaction fails
     */
    public function rejectExpense(PocketExpense $expense, int $rejectorId, ?string $reason = null): PocketExpense
    {
        return DB::transaction(function () use ($expense, $rejectorId, $reason) {
            // Validate current status allows rejection
            if (!in_array($expense->status, ['submitted', 'draft', 'approved'])) {
                throw ValidationException::withMessages([
                    'status' => ['Expense cannot be rejected from current status: ' . $expense->status]
                ]);
            }
            
            $now = Carbon::now();
            
            // Update expense status to rejected
            $updateData = [
                'status' => 'rejected',
                'approved_by_user_id' => null, // Clear approver if previously approved
                'updated_by_user_id' => $rejectorId,
                'update_time' => $now,
            ];
            
            // Add rejection reason to notes if provided
            if ($reason) {
                $currentNotes = $expense->notes ? $expense->notes . "\n\n" : '';
                $updateData['notes'] = $currentNotes . "REJECTION REASON: " . trim($reason);
            }
            
            $expense->update($updateData);
            
            // Load relationships for return
            $expense->refresh();
            $expense->load([
                'user',
                'client',
                'expenseType',
                'createdBy',
                'updatedBy',
                'metadata'
            ]);
            
            return $expense;
        });
    }
    
    /**
     * Bulk sync expenses from CSV upload data.
     * Used by ProcessExpenseUpload job for batch processing.
     * 
     * @param Collection $expenseDataCollection Collection of expense data arrays
     * @return array Results with success count and any errors
     * @throws Exception If transaction fails
     */
    public function syncExpenseBatch(Collection $expenseDataCollection): array
    {
        $successCount = 0;
        $errors = [];
        
        return DB::transaction(function () use ($expenseDataCollection, &$successCount, &$errors) {
            foreach ($expenseDataCollection as $expenseData) {
                try {
                    // Each item should have user_id, client_id, and expense data
                    $userId = $expenseData['user_id'];
                    $clientId = $expenseData['client_id'];
                    $data = $expenseData['expense_data'];
                    
                    $this->createExpense($data, $userId, $clientId);
                    $successCount++;
                    
                } catch (Exception $e) {
                    $errors[] = [
                        'line_number' => $expenseData['line_number'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'data' => $expenseData
                    ];
                }
            }
            
            return [
                'success_count' => $successCount,
                'error_count' => count($errors),
                'errors' => $errors
            ];
        });
    }
    
    /**
     * Get expense statistics for a user within a client.
     * 
     * @param int $userId User ID
     * @param int $clientId Client ID
     * @param array $filters Optional filters for date range
     * @return array Statistics including counts and totals by status
     */
    public function getExpenseStatistics(int $userId, int $clientId, array $filters = []): array
    {
        $query = PocketExpense::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('deleted', 0);
        
        // Apply date range filters
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        // Get counts by status
        $statusCounts = $query->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
        
        // Get totals by status and currency
        $statusTotals = $query->select('status', 'currency', DB::raw('sum(amount) as total'))
            ->groupBy(['status', 'currency'])
            ->get()
            ->groupBy('status')
            ->map(function ($items) {
                return $items->pluck('total', 'currency')->toArray();
            })
            ->toArray();
        
        // Get overall totals
        $overallStats = $query->select([
                'currency',
                DB::raw('count(*) as total_count'),
                DB::raw('sum(amount) as total_amount'),
                DB::raw('avg(amount) as average_amount'),
                DB::raw('min(amount) as min_amount'),
                DB::raw('max(amount) as max_amount')
            ])
            ->groupBy('currency')
            ->get()
            ->keyBy('currency')
            ->toArray();
        
        return [
            'status_counts' => $statusCounts,
            'status_totals' => $statusTotals,
            'overall_stats' => $overallStats,
            'total_expenses' => array_sum($statusCounts),
        ];
    }
    
    /**
     * Create metadata records for an expense.
     * 
     * @param int $expenseId Expense ID
     * @param array $data Expense data containing metadata
     * @param Carbon $now Current timestamp
     * @return void
     * @throws Exception If metadata creation fails
     */
    private function createExpenseMetadata(int $expenseId, array $data, Carbon $now): void
    {
        $metadataRecords = [];
        
        // Category metadata
        if (isset($data['category_id']) && $data['category_id']) {
            $metadataRecords[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'category',
                'transaction_category_id' => $data['category_id'],
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => isset($data['category_details']) 
                    ? json_encode($data['category_details']) 
                    : null,
                'create_time' => $now,
                'update_time' => $now,
                'deleted' => 0,
                'delete_time' => null,
            ];
        }
        
        // Expense source metadata
        if (isset($data['expense_source_id']) && $data['expense_source_id']) {
            $sourceDetails = ['source_id' => $data['expense_source_id']];
            
            if (isset($data['source_note'])) {
                $sourceDetails['source_note'] = $data['source_note'];
            }
            
            $metadataRecords[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'expense_source',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => $data['expense_source_id'],
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode($sourceDetails),
                'create_time' => $now,
                'update_time' => $now,
                'deleted' => 0,
                'delete_time' => null,
            ];
        }
        
        // Tracking code type 1 metadata
        if (isset($data['tracking_code_1_id']) && $data['tracking_code_1_id']) {
            $metadataRecords[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'tracking_code_type_1',
                'transaction_category_id' => null,
                'tracking_code_id' => $data['tracking_code_1_id'],
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => isset($data['tracking_code_1_details']) 
                    ? json_encode($data['tracking_code_1_details']) 
                    : null,
                'create_time' => $now,
                'update_time' => $now,
                'deleted' => 0,
                'delete_time' => null,
            ];
        }
        
        // Tracking code type 2 metadata
        if (isset($data['tracking_code_2_id']) && $data['tracking_code_2_id']) {
            $metadataRecords[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'tracking_code_type_2',
                'transaction_category_id' => null,
                'tracking_code_id' => $data['tracking_code_2_id'],
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => isset($data['tracking_code_2_details']) 
                    ? json_encode($data['tracking_code_2_details']) 
                    : null,
                'create_time' => $now,
                'update_time' => $now,
                'deleted' => 0,
                'delete_time' => null,
            ];
        }
        
        // Project metadata
        if (isset($data['project_id']) && $data['project_id']) {
            $metadataRecords[] = [
                'pocket_expense_id' => $expenseId,
                'metadata_type' => 'project',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => $data['project_id'],
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => isset($data['project_details']) 
                    ? json_encode($data['project_details']) 
                    : null,
                'create_time' => $now,
                'update_time' => $now,
                'deleted' => 0,
                'delete_time' => null,
            ];
        }
        
        // File metadata
        if (isset($data['file_ids']) && is_array($data['file_ids'])) {
            foreach ($data['file_ids'] as $fileId) {
                $metadataRecords[] = [
                    'pocket_expense_id' => $expenseId,
                    'metadata_type' => 'file',
                    'transaction_category_id' => null,
                    'tracking_code_id' => null,
                    'project_id' => null,
                    'file_store_id' => $fileId,
                    'expense_source_id' => null,
                    'additional_field_id' => null,
                    'user_id' => null,
                    'details_json' => json_encode(['file_id' => $fileId]),
                    'create_time' => $now,
                    'update_time' => $now,
                    'deleted' => 0,
                    'delete_time' => null,
                ];
            }
        }
        
        // Additional fields metadata
        if (isset($data['additional_fields']) && is_array($data['additional_fields'])) {
            foreach ($data['additional_fields'] as $fieldId => $fieldValue) {
                $metadataRecords[] = [
                    'pocket_expense_id' => $expenseId,
                    'metadata_type' => 'additional_field',
                    'transaction_category_id' => null,
                    'tracking_code_id' => null,
                    'project_id' => null,
                    'file_store_id' => null,
                    'expense_source_id' => null,
                    'additional_field_id' => $fieldId,
                    'user_id' => null,
                    'details_json' => json_encode(['field_value' => $fieldValue]),
                    'create_time' => $now,
                    'update_time' => $now,
                    'deleted' => 0,
                    'delete_time' => null,
                ];
            }
        }
        
        // Bulk insert metadata records
        if (!empty($metadataRecords)) {
            PocketExpenseMetadata::insert($metadataRecords);
        }
    }
    
    /**
     * Update metadata records for an expense.
     * 
     * @param int $expenseId Expense ID
     * @param array $data Updated expense data containing metadata
     * @param Carbon $now Current timestamp
     * @return void
     * @throws Exception If metadata update fails
     */
    private function updateExpenseMetadata(int $expenseId, array $data, Carbon $now): void
    {
        // Soft delete existing metadata that might be updated
        $metadataTypesToUpdate = [];
        
        if (isset($data['category_id'])) {
            $metadataTypesToUpdate[] = 'category';
        }
        
        if (isset($data['expense_source_id'])) {
            $metadataTypesToUpdate[] = 'expense_source';
        }
        
        if (isset($data['tracking_code_1_id'])) {
            $metadataTypesToUpdate[] = 'tracking_code_type_1';
        }
        
        if (isset($data['tracking_code_2_id'])) {
            $metadataTypesToUpdate[] = 'tracking_code_type_2';
        }
        
        if (isset($data['project_id'])) {
            $metadataTypesToUpdate[] = 'project';
        }
        
        if (isset($data['file_ids'])) {
            $metadataTypesToUpdate[] = 'file';
        }
        
        if (isset($data['additional_fields'])) {
            $metadataTypesToUpdate[] = 'additional_field';
        }
        
        // Soft delete existing metadata records for types being updated
        if (!empty($metadataTypesToUpdate)) {
            PocketExpenseMetadata::where('pocket_expense_id', $expenseId)
                ->whereIn('metadata_type', $metadataTypesToUpdate)
                ->where('deleted', 0)
                ->update([
                    'deleted' => 1,
                    'delete_time' => $now,
                    'update_time' => $now,
                ]);
            
            // Create new metadata records
            $this->createExpenseMetadata($expenseId, $data, $now);
        }
    }
    
    /**
     * Get expenses that need approval for a specific approver within a client.
     * 
     * @param int $approverId User ID of the approver
     * @param int $clientId Client ID for scoping
     * @param array $filters Optional filters
     * @return Collection Expenses pending approval
     */
    public function getExpensesPendingApproval(int $approverId, int $clientId, array $filters = []): Collection
    {
        $query = PocketExpense::with([
                'user',
                'expenseType',
                'createdBy',
                'metadata'
            ])
            ->where('client_id', $clientId)
            ->where('deleted', 0)
            ->whereIn('status', ['submitted', 'draft']); // Expenses that can be approved
        
        // Apply user filter if specified (for managers approving specific users)
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        // Apply date range filters
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        // Apply amount range filters
        if (isset($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }
        
        if (isset($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }
        
        // Order by submission date (most recent first)
        $query->orderBy('update_time', 'desc')
              ->orderBy('create_time', 'desc');
        
        return $query->get();
    }
    
    /**
     * Submit an expense for approval.
     * 
     * @param PocketExpense $expense Expense to submit
     * @param int $submitterId User ID of the submitter
     * @return PocketExpense Submitted expense
     * @throws ValidationException If expense cannot be submitted
     */
    public function submitExpense(PocketExpense $expense, int $submitterId): PocketExpense
    {
        // Validate current status allows submission
        if (!in_array($expense->status, ['draft'])) {
            throw ValidationException::withMessages([
                'status' => ['Expense can only be submitted from draft status. Current status: ' . $expense->status]
            ]);
        }
        
        $expense->update([
            'status' => 'submitted',
            'updated_by_user_id' => $submitterId,
            'update_time' => Carbon::now(),
        ]);
        
        $expense->refresh();
        $expense->load(['user', 'client', 'expenseType', 'metadata']);
        
        return $expense;
    }
}