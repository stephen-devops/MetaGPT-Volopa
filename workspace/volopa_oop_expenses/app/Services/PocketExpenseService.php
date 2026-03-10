## Code: app/Services/PocketExpenseService.php

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * PocketExpenseService
 * 
 * Service class for managing pocket expenses within the multi-tenant system.
 * Handles expense CRUD operations, metadata management, status transitions,
 * and business logic for OOP expense management. Provides business logic layer
 * between controllers and models with comprehensive validation and audit support.
 * 
 * Key responsibilities:
 * - Create, update, and delete expenses with validation
 * - Manage expense metadata (source, category, project, files)
 * - Handle expense status transitions and approval workflow
 * - Process FX conversions and amount calculations
 * - Provide expense queries and filtering capabilities
 * - Support multi-tenant expense scoping
 * - Maintain audit trails and expense history
 */
class PocketExpenseService
{
    /**
     * Default expense status for new expenses
     *
     * @var string
     */
    private const DEFAULT_STATUS = 'draft';

    /**
     * Maximum number of expenses per query result
     *
     * @var int
     */
    private const MAX_QUERY_LIMIT = 1000;

    /**
     * Default query limit for expense lists
     *
     * @var int
     */
    private const DEFAULT_QUERY_LIMIT = 50;

    /**
     * Maximum file attachments per expense
     *
     * @var int
     */
    private const MAX_FILE_ATTACHMENTS = 5;

    /**
     * Cache timeout for expense data (in minutes)
     *
     * @var int
     */
    private const CACHE_TIMEOUT_MINUTES = 15;

    /**
     * Default metadata types for new expenses
     *
     * @var array<int, string>
     */
    private const DEFAULT_METADATA_TYPES = [
        'expense_source',
        'category',
        'project',
        'tracking_code',
        'file_attachment'
    ];

    /**
     * Valid expense statuses for validation
     *
     * @var array<int, string>
     */
    private const VALID_STATUSES = [
        'draft',
        'submitted',
        'approved',
        'rejected'
    ];

    /**
     * Maximum amount for expense validation
     *
     * @var float
     */
    private const MAX_EXPENSE_AMOUNT = 999999999999.99;

    /**
     * Minimum amount for expense validation
     *
     * @var float
     */
    private const MIN_EXPENSE_AMOUNT = 0.01;

    /**
     * Create a new pocket expense with metadata.
     * 
     * Validates input data, creates expense record, processes metadata,
     * handles FX calculations, and maintains audit trails. Supports
     * multi-tenant scoping and role-based creation permissions.
     *
     * @param array<string, mixed> $expenseData
     * @return PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function createExpense(array $expenseData): PocketExpense
    {
        // Validate required fields
        $this->validateExpenseData($expenseData);

        // Extract and normalize data
        $normalizedData = $this->normalizeExpenseData($expenseData);
        $metadataArray = $normalizedData['_metadata'] ?? [];
        unset($normalizedData['_metadata']);

        try {
            DB::beginTransaction();

            // Create the expense record
            $expense = PocketExpense::create([
                'uuid' => $normalizedData['uuid'] ?? (string) Str::uuid(),
                'user_id' => (int) $normalizedData['user_id'],
                'client_id' => (int) $normalizedData['client_id'],
                'date' => Carbon::parse($normalizedData['date'])->format('Y-m-d'),
                'merchant_name' => $normalizedData['merchant_name'],
                'merchant_description' => $normalizedData['merchant_description'] ?? null,
                'expense_type' => (int) $normalizedData['expense_type'],
                'currency' => strtoupper($normalizedData['currency']),
                'amount' => (float) $normalizedData['amount'],
                'merchant_address' => $normalizedData['merchant_address'] ?? null,
                'vat_amount' => isset($normalizedData['vat_amount']) ? (float) $normalizedData['vat_amount'] : null,
                'notes' => $normalizedData['notes'] ?? null,
                'status' => $normalizedData['status'] ?? self::DEFAULT_STATUS,
                'created_by_user_id' => (int) $normalizedData['created_by_user_id'],
                'updated_by_user_id' => null,
                'approved_by_user_id' => null,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => false,
                'delete_time' => null
            ]);

            // Process metadata if provided
            if (!empty($metadataArray)) {
                $this->createExpenseMetadata($expense, $metadataArray);
            }

            // Log the expense creation
            $this->logExpenseAction('created', $expense, $normalizedData['created_by_user_id'], [
                'initial_status' => $expense->status,
                'amount' => $expense->amount,
                'currency' => $expense->currency,
                'metadata_types' => array_keys($metadataArray)
            ]);

            DB::commit();

            return $expense->fresh(['expenseType', 'user', 'client', 'metadata']);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create expense', [
                'expense_data' => $expenseData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new \RuntimeException('Failed to create expense: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing pocket expense.
     * 
     * Validates permissions, updates expense data, manages metadata changes,
     * handles status transitions, and maintains audit trails. Supports
     * partial updates and metadata synchronization.
     *
     * @param int $expenseId
     * @param array<string, mixed> $updateData
     * @return PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function updateExpense(int $expenseId, array $updateData): PocketExpense
    {
        $expense = PocketExpense::find($expenseId);

        if (!$expense) {
            throw new \InvalidArgumentException("Expense with ID {$expenseId} not found");
        }

        // Check if expense can be updated
        if (!$expense->canBeEdited() && !$this->isStatusOnlyUpdate($updateData)) {
            throw new \InvalidArgumentException('Expense cannot be updated in its current status');
        }

        // Validate update data
        $this->validateExpenseUpdateData($updateData, $expense);

        // Extract and normalize data
        $normalizedData = $this->normalizeExpenseUpdateData($updateData, $expense);
        $metadataArray = $normalizedData['_metadata'] ?? [];
        unset($normalizedData['_metadata']);

        try {
            DB::beginTransaction();

            $originalData = $expense->toArray();

            // Update expense fields
            $expense->fill($normalizedData);
            
            // Always update the update_time and updated_by_user_id
            $expense->update_time = now();
            if (auth()->check()) {
                $expense->updated_by_user_id = auth()->user()->id;
            }

            // Handle status-specific updates
            if (isset($normalizedData['status'])) {
                $this->handleStatusTransition($expense, $expense->status, $normalizedData['status']);
            }

            $expense->save();

            // Process metadata updates if provided
            if (!empty($metadataArray)) {
                $this->updateExpenseMetadata($expense, $metadataArray);
            }

            // Log the expense update
            $this->logExpenseAction('updated', $expense, $expense->updated_by_user_id, [
                'original_data' => $originalData,
                'updated_fields' => array_keys($normalizedData),
                'status_changed' => isset($normalizedData['status']),
                'metadata_updated' => !empty($metadataArray)
            ]);

            DB::commit();

            return $expense->fresh(['expenseType', 'user', 'client', 'metadata']);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update expense', [
                'expense_id' => $expenseId,
                'update_data' => $updateData,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to update expense: ' . $e->getMessage());
        }
    }

    /**
     * Delete a pocket expense (soft delete).
     * 
     * Validates permissions, performs soft delete, handles metadata cleanup,
     * and maintains audit trails. Supports cascade handling for related data.
     *
     * @param int $expenseId
     * @return bool
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function deleteExpense(int $expenseId): bool
    {
        $expense = PocketExpense::find($expenseId);

        if (!$expense) {
            throw new \InvalidArgumentException("Expense with ID {$expenseId} not found");
        }

        // Check if expense can be deleted
        if (!$expense->canBeDeleted()) {
            throw new \InvalidArgumentException('Expense cannot be deleted in its current status');
        }

        try {
            DB::beginTransaction();

            // Soft delete related metadata
            PocketExpenseMetadata::where('pocket_expense_id', $expenseId)
                ->update([
                    'deleted' => true,
                    'delete_time' => now(),
                    'update_time' => now()
                ]);

            // Soft delete the expense
            $expense->deleted = true;
            $expense->delete_time = now();
            $expense->update_time = now();
            
            if (auth()->check()) {
                $expense->updated_by_user_id = auth()->user()->id;
            }
            
            $expense->save();

            // Log the expense deletion
            $this->logExpenseAction('deleted', $expense, $expense->updated_by_user_id, [
                'deletion_reason' => 'User requested deletion',
                'original_status' => $expense->status
            ]);

            DB::commit();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete expense', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to delete expense: ' . $e->getMessage());
        }
    }

    /**
     * Get expenses for a specific user and client with filtering.
     * 
     * Provides comprehensive expense querying with filtering, sorting,
     * pagination, and multi-tenant scoping. Supports role-based access
     * control and performance optimization through eager loading.
     *
     * @param int $userId
     * @param int $clientId
     * @param array<string, mixed> $filters
     * @return Collection<int, PocketExpense>
     */
    public function getUserExpenses(int $userId, int $clientId, array $filters = []): Collection
    {
        $query = PocketExpense::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->with(['expenseType', 'metadata.expenseSource', 'createdBy', 'updatedBy', 'approvedBy']);

        // Apply filters
        $query = $this->applyExpenseFilters($query, $filters);

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'create_time';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Apply limit
        $limit = isset($filters['limit']) ? min((int) $filters['limit'], self::MAX_QUERY_LIMIT) : self::DEFAULT_QUERY_LIMIT;
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Approve a pocket expense.
     * 
     * Handles expense approval workflow with permission validation,
     * status transition, approver assignment, and audit logging.
     * Updates expense status to approved and records approval details.
     *
     * @param int $expenseId
     * @param int $approverId
     * @return PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function approveExpense(int $expenseId, int $approverId): PocketExpense
    {
        $expense = PocketExpense::find($expenseId);

        if (!$expense) {
            throw new \InvalidArgumentException("Expense with ID {$expenseId} not found");
        }

        // Check if expense can be approved
        if (!$expense->canBeApproved()) {
            throw new \InvalidArgumentException('Expense cannot be approved in its current status');
        }

        // Validate approver
        $approver = User::find($approverId);
        if (!$approver || $approver->client_id !== $expense->client_id) {
            throw new \InvalidArgumentException('Invalid approver or approver not in same client');
        }

        // Users cannot approve their own expenses
        if ($expense->user_id === $approverId) {
            throw new \InvalidArgumentException('Users cannot approve their own expenses');
        }

        try {
            DB::beginTransaction();

            $originalStatus = $expense->status;

            // Update expense status and approver
            $expense->status = PocketExpense::STATUS_APPROVED;
            $expense->approved_by_user_id = $approverId;
            $expense->updated_by_user_id = $approverId;
            $expense->update_time = now();
            $expense->save();

            // Log the approval
            $this->logExpenseAction('approved', $expense, $approverId, [
                'original_status' => $originalStatus,
                'approved_at' => now()->toISOString(),
                'approver_name' => $approver->name
            ]);

            DB::commit();

            return $expense->fresh(['expenseType', 'user', 'client', 'approvedBy']);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve expense', [
                'expense_id' => $expenseId,
                'approver_id' => $approverId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to approve expense: ' . $e->getMessage());
        }
    }

    /**
     * Reject a pocket expense.
     *
     * @param int $expenseId
     * @param int $rejectorId
     * @param string|null $rejectionReason
     * @return PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function rejectExpense(int $expenseId, int $rejectorId, ?string $rejectionReason = null): PocketExpense
    {
        $expense = PocketExpense::find($expenseId);

        if (!$expense) {
            throw new \InvalidArgumentException("Expense with ID {$expenseId} not found");
        }

        // Check if expense can be rejected
        if (!$expense->canBeRejected()) {
            throw new \InvalidArgumentException('Expense cannot be rejected in its current status');
        }

        // Validate rejector
        $rejector = User::find($rejectorId);
        if (!$rejector || $rejector->client_id !== $expense->client_id) {
            throw new \InvalidArgumentException('Invalid rejector or rejector not in same client');
        }

        // Users cannot reject their own expenses
        if ($expense->user_id === $rejectorId) {
            throw new \InvalidArgumentException('Users cannot reject their own expenses');
        }

        try {
            DB::beginTransaction();

            $originalStatus = $expense->status;

            // Update expense status
            $expense->status = PocketExpense::STATUS_REJECTED;
            $expense->updated_by_user_id = $rejectorId;
            $expense->update_time = now();
            $expense->save();

            // Add rejection reason to notes if provided
            if ($rejectionReason) {
                $currentNotes = $expense->notes ? $expense->notes . "\n\n" : '';
                $rejectionNote = "REJECTED (" . now()->format('Y-m-d H:i') . "): " . $rejectionReason;
                $expense->notes = $currentNotes . $rejectionNote;
                $expense->save();
            }

            // Log the rejection
            $this->logExpenseAction('rejected', $expense, $rejectorId, [
                'original_status' => $originalStatus,
                'rejected_at' => now()->toISOString(),
                'rejector_name' => $rejector->name,
                'rejection_reason' => $rejectionReason
            ]);

            DB::commit();

            return $expense->fresh(['expenseType', 'user', 'client']);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject expense', [
                'expense_id' => $expenseId,
                'rejector_id' => $rejectorId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to reject expense: ' . $e->getMessage());
        }
    }

    /**
     * Submit expense for approval.
     *
     * @param int $expenseId
     * @return PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function submitExpense(int $expenseId): PocketExpense
    {
        $expense = PocketExpense::find($expenseId);

        if (!$expense) {
            throw new \InvalidArgumentException("Expense with ID {$expenseId} not found");
        }

        // Check if expense can be submitted
        if (!$expense->canBeSubmitted()) {
            throw new \InvalidArgumentException('Expense cannot be submitted in its current status');
        }

        try {
            DB::beginTransaction();

            $originalStatus = $expense->status;

            // Update expense status
            $expense->status = PocketExpense::STATUS_SUBMITTED;
            $expense->update_time = now();
            
            if (auth()->check()) {
                $expense->updated_by_user_id = auth()->user()->id;
            }
            
            $expense->save();

            // Log the submission
            $this->logExpenseAction('submitted', $expense, $expense->updated_by_user_id, [
                'original_status' => $originalStatus,
                'submitted_at' => now()->toISOString()
            ]);

            DB::commit();

            return $expense->fresh(['expenseType', 'user', 'client']);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to submit expense', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to submit expense: ' . $e->getMessage());
        }
    }

    /**
     * Return expense to draft status.
     *
     * @param int $expenseId
     * @return PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function returnToDraft(int $expenseId): PocketExpense
    {
        $expense = PocketExpense::find($expenseId);

        if (!$expense) {
            throw new \InvalidArgumentException("Expense with ID {$expenseId} not found");
        }

        // Check valid status transitions to draft
        if (!in_array($expense->status, [PocketExpense::STATUS_SUBMITTED, PocketExpense::STATUS_REJECTED])) {
            throw new \InvalidArgumentException('Expense cannot be returned to draft from its current status');
        }

        try {
            DB::beginTransaction();

            $originalStatus = $expense->status;

            // Update expense status
            $expense->status = PocketExpense::STATUS_DRAFT;
            $expense->approved_by_user_id = null; // Clear approver if returning from approved
            $expense->update_time = now();
            
            if (auth()->check()) {
                $expense->updated_by_user_id = auth()->user()->id;
            }
            
            $expense->save();

            // Log the status change
            $this->logExpenseAction('returned_to_draft', $expense, $expense->updated_by_user_id, [
                'original_status' => $originalStatus,
                'returned_at' => now()->toISOString()
            ]);

            DB::commit();

            return $expense->fresh(['expenseType', 'user', 'client']);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to return expense to draft', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException('Failed to return expense to draft: ' . $e->getMessage());
        }
    }

    /**
     * Get expense statistics for a client.
     *
     * @param int $clientId
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getExpenseStatistics(int $clientId, array $filters = []): array
    {
        $query = PocketExpense::where('client_id', $clientId);

        // Apply date filters if provided
        if (isset($filters['start_date'])) {
            $query->where('date', '>=', Carbon::parse($filters['start_date']));
        }
        if (isset($filters['end_date'])) {
            $query->where('date', '<=', Carbon::parse($filters['end_date']));
        }

        // Apply user filter if provided
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $totalExpenses = $query->count();
        $totalAmount = $query->sum('amount') ?? 0;

        $statusCounts = $query->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $currencyTotals = $query->select('currency', DB::raw('sum(amount) as total'))
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->toArray();

        $expenseTypeCounts = $query->join('opt_pocket_expense_type', 'pocket_expense.expense_type', '=', 'opt_pocket_expense_type.id')
            ->select('opt_pocket_expense_type.option', DB::raw('count(*) as count'))
            ->groupBy('opt_pocket_expense_type.option')
            ->pluck('count', 'option')
            ->toArray();

        return [
            'total_expenses' => $totalExpenses,
            'total_amount' => $totalAmount,
            'average_amount' => $totalExpenses > 0 ? $totalAmount / $totalExpenses : 0,
            'status_breakdown' => [
                'draft' => $statusCounts['draft'] ?? 0,
                'submitted' => $statusCounts['submitted'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0
            ],
            'currency_totals' => $currencyTotals,
            'expense_type_breakdown' => $expenseTypeCounts,
            'approval_rate' => $this->calculateApprovalRate($clientId, $filters),
            'recent_activity' => $this->getRecentActivity($clientId, $filters)
        ];
    }

    /**
     * Get expenses by status for a client.
     *
     * @param int $clientId
     * @param string $status
     * @param array<string, mixed> $filters
     * @return Collection<int, PocketExpense>
     */
    public function getExpensesByStatus(int $clientId, string $status, array $filters = []): Collection
    {
        if (!in_array($status, self::VALID_STATUSES)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $query = PocketExpense::where('client_id', $clientId)
            ->where('status', $status)
            ->with(['expenseType', 'user', 'metadata']);

        // Apply additional filters
        $query = $this->applyExpenseFilters($query, $filters);

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'create_time';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Apply limit
        $limit = isset($filters['limit']) ? min((int) $filters['limit'], self::MAX_QUERY_LIMIT) : self::DEFAULT_QUERY_LIMIT;
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Search expenses by criteria.
     *
     * @param int $clientId
     * @param string $searchTerm
     * @param array<string, mixed> $filters
     * @return Collection<int, PocketExpense>
     */
    public function searchExpenses(int $clientId, string $searchTerm, array $filters = []): Collection
    {
        $query = PocketExpense::where('client_id', $clientId)
            ->where(function ($q) use ($searchTerm) {
                $q->where('merchant_name', 'like', "%{$searchTerm}%")
                  ->orWhere('merchant_description', 'like', "%{$searchTerm}%")
                  ->orWhere('notes', 'like', "%{$searchTerm}%")
                  ->orWhere('uuid', 'like', "%{$searchTerm}%");
            })
            ->with(['expenseType', 'user', 'metadata']);

        // Apply additional filters
        $query = $this->applyExpenseFilters($query, $filters);

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'create_time';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Apply limit
        $limit = isset($filters['limit']) ? min((int) $filters['limit'], self::MAX_QUERY_LIMIT) : self::DEFAULT_QUERY_LIMIT;
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get pending expenses for approval.
     *
     * @param int $clientId
     * @param array<string, mixed> $filters
     * @return Collection<int, PocketExpense>
     */
    public function getPendingExpenses(int $clientId, array $filters = []): Collection
    {
        return $this->getExpensesByStatus($clientId, PocketExpense::STATUS_SUBMITTED, $filters);
    }

    /**
     * Validate expense data for creation.
     *
     * @param array<string, mixed> $expenseData
     * @throws \InvalidArgumentException
     */
    private function validateExpenseData(array $expenseData): void
    {
        $required = ['user_id', 'client_id', 'date', 'merchant_name', 'expense_type', 'currency', 'amount', 'created_by_user_id'];
        
        foreach ($required as $field) {
            if (!isset($expenseData[$field])) {
                throw new \InvalidArgumentException("Required field '{$field}' is missing");
            }
        }

        // Validate user exists and belongs to client
        $user = User::find($expenseData['user_id']);
        if (!$user || $user->client_id !== (int) $expenseData['client_id']) {
            throw new \InvalidArgumentException('User does not exist or belongs to different client');
        }

        // Validate expense type exists
        $expenseType = OptPocketExpenseType::find($expenseData['expense_type']);
        if (!$expenseType) {
            throw new \InvalidArgumentException('Invalid expense type');
        }

        // Validate amount
        $amount = (float) $expenseData['amount'];
        if ($amount < self::MIN_EXPENSE_AMOUNT || $amount > self::MAX_EXPENSE_AMOUNT) {
            throw new \InvalidArgumentException('Amount must be between ' . self::MIN_EXPENSE_AMOUNT . ' and ' . self::MAX_EXPENSE_AMOUNT);
        }

        // Validate VAT amount if provided
        if (isset($expenseData['vat_amount'])) {
            $vatAmount = (float) $expenseData['vat_amount'];
            if ($vatAmount > $amount) {
                throw new \InvalidArgumentException('VAT amount cannot be greater than expense amount');
            }
        }

        // Validate date
        try {
            $date = Carbon::parse($expenseData['date']);
            if ($date->isFuture()) {
                throw new \InvalidArgumentException('Expense date cannot be in the future');
            }
            if ($date->lt(now()->subYears(3))) {
                throw new \InvalidArgumentException('Expense date cannot be older than 3 years');
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format');
        }

        // Validate status if provided
        if (isset($expenseData['status']) && !in_array($expenseData['status'], self::VALID_STATUSES)) {
            throw new \InvalidArgumentException('Invalid status');
        }
    }

    /**
     * Normalize expense data for database storage.
     *
     * @param array<string, mixed> $expenseData
     * @return array<string, mixed>
     */
    private function normalizeExpenseData(array $expenseData): array
    {
        $normalized = $expenseData;

        // Normalize string fields
        $stringFields = ['merchant_name', 'merchant_description', 'merchant_address', 'notes'];
        foreach ($stringFields as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = trim(strip_tags($normalized[$field]));
                if (empty($normalized[$field])) {
                    $normalized[$field] = null;
                }
            }
        }

        // Normalize currency to uppercase
        if (isset($normalized['currency'])) {
            $normalized['currency'] = strtoupper(trim($normalized['currency']));
        }

        // Normalize numeric fields
        if (isset($normalized['amount'])) {
            $normalized['amount'] = round((float) $normalized['amount'], 2);
        }
        if (isset($normalized['vat_amount'])) {
            $normalized['vat_amount'] = round((float) $normalized['vat_amount'], 2);
        }

        // Set defaults
        $normalized['status'] = $normalized['status'] ?? self::DEFAULT_STATUS;
        $normalized['uuid'] = $normalized['uuid'] ?? (string) Str::uuid();

        return $normalized;
    }

    /**
     * Validate expense update data.
     *
     * @param array<string, mixed> $updateData
     * @param PocketExpense $expense
     * @throws \InvalidArgumentException
     */
    private function validateExpenseUpdateData(array $updateData, PocketExpense $expense): void
    {
        // Validate amount if being updated
        if (isset($updateData['amount'])) {
            $amount = (float) $updateData['amount'];
            if ($amount < self::MIN_EXPENSE_AMOUNT || $amount > self::MAX_EXPENSE_AMOUNT) {
                throw new \InvalidArgumentException('Amount must be between ' . self::MIN_EXPENSE_AMOUNT . ' and ' . self::MAX_EXPENSE_AMOUNT);
            }
        }

        // Validate VAT amount if being updated
        if (isset($updateData['vat_amount'])) {
            $vatAmount = (float) $updateData['vat_amount'];
            $checkAmount = isset($updateData['amount']) ? (float) $updateData['amount'] : $expense->amount;
            if ($vatAmount > $checkAmount) {
                throw new \InvalidArgumentException('VAT amount cannot be greater than expense amount');
            }
        }

        // Validate date if being updated
        if (isset($updateData['date'])) {
            try {
                $date = Carbon::parse($updateData['date']);
                if ($date->isFuture()) {
                    throw new \InvalidArgumentException('Expense date cannot be in the future');
                }
                if ($date->lt(now()->subYears(3))) {
                    throw new \InvalidArgumentException('Expense date cannot be older than 3 years');
                }
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid date format');
            }
        }

        // Validate expense type if being updated
        if (isset($updateData['expense_type'])) {
            $expenseType = OptPocketExpenseType::find($updateData['expense_type']);
            if (!$expenseType) {
                throw new \InvalidArgumentException('Invalid expense type');
            }
        }

        // Validate status transition if being updated
        if (isset($updateData['status'])) {
            if (!in_array($updateData['status'], self::VALID_STATUSES)) {
                throw new \InvalidArgumentException('Invalid status');
            }
            if (!$expense->isValidStatusTransition($expense->status, $updateData['status'])) {
                throw new \InvalidArgumentException("Invalid status transition from '{$expense->status}' to '{$updateData['status']}'");
            }
        }
    }

    /**
     * Normalize expense update data.
     *
     * @param array<string, mixed> $updateData
     * @param PocketExpense $expense
     * @return array<string, mixed>
     */
    private function normalizeExpenseUpdateData(array $updateData, PocketExpense $expense): array
    {
        $normalized = [];

        // Only include fields that are being updated
        $allowedFields = [
            'date', 'merchant_name', 'merchant_description', 'expense_type',
            'currency', 'amount', 'merchant_address', 'vat_amount', 'notes', 'status'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $updateData)) {
                $normalized[$field] = $updateData[$field];
            }
        }

        // Normalize string fields
        $stringFields = ['merchant_name', 'merchant_description', 'merchant_address', 'notes'];
        foreach ($stringFields as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = trim(strip_tags($normalized[$field]));
                if (empty($normalized[$field])) {
                    $normalized[$field] = null;
                }
            }
        }

        // Normalize currency to uppercase
        if (isset($normalized['currency'])) {
            $normalized['currency'] = strtoupper(trim($normalized['currency']));
        }

        // Normalize numeric fields
        if (isset($normalized['amount'])) {
            $normalized['amount'] = round((float) $normalized['amount'], 2);
        }
        if (isset($normalized['vat_amount'])) {
            $normalized['vat_amount'] = round((float) $normalized['vat_amount'], 2);
        }

        // Extract metadata if present
        if (isset($updateData['_metadata'])) {
            $normalized['_metadata'] = $updateData['_metadata'];
        }

        return $normalized;
    }

    /**
     * Create expense metadata records.
     *
     * @param PocketExpense $expense
     * @param array<string, mixed> $metadataArray
     */
    private function createExpenseMetadata(PocketExpense $expense, array $metadataArray): void
    {
        $userId = auth()->check() ? auth()->user()->id : $expense->created_by_user_id;

        // Create expense source metadata
        if (isset($metadataArray['source_id'])) {
            PocketExpenseMetadata::create([
                'pocket_expense_id' => $expense->id,
                'metadata_type' => 'expense_source',
                'expense_source_id' => (int) $metadataArray['source_id'],
                'user_id' => $userId,
                'details_json' => isset($metadataArray['source_note']) ? ['source_note' => $metadataArray['source_note']] : null,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => false,
                'delete_time' => null
            ]);
        }

        // Create category metadata
        if (isset($metadataArray['category_id'])) {
            PocketExpenseMetadata::create([
                'pocket_expense_id' => $expense->id,
                'metadata_type' => 'category',
                'transaction_category_id' => (int) $metadataArray['category_id'],
                'user_id' => $userId,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => false,
                'delete_time' => null
            ]);
        }

        // Create project metadata
        if (isset($metadataArray['project_id'])) {
            PocketExpenseMetadata::create([
                'pocket_expense_id' => $expense->id,
                'metadata_type' => 'project',
                'project_id' => (int) $metadataArray['project_id'],
                'user_id' => $userId,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => false,
                'delete_time' => null
            ]);
        }

        // Create tracking code metadata
        if (isset($metadataArray['tracking_code_id'])) {
            PocketExpenseMetadata::create([
                'pocket_expense_id' => $expense->id,
                'metadata_type' => 'tracking_code',
                'tracking_code_id' => (int) $metadataArray['tracking_code_id'],
                'user_id' => $userId,
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => false,
                'delete_time' => null
            ]);
        }

        // Create file attachment metadata
        if (isset($metadataArray['file_attachments']) && is_array($metadataArray['file_attachments'])) {
            $attachments = array_slice($metadataArray['file_attachments'], 0, self::MAX_FILE_ATTACHMENTS);
            foreach ($attachments as $fileId) {
                PocketExpenseMetadata::create([
                    'pocket_expense_id' => $expense->id,
                    'metadata_type' => 'file_attachment',
                    'file_store_id' => (int) $fileId,
                    'user_id' => $userId,
                    'create_time' => now(),
                    'update_time' => now(),
                    'deleted' => false,
                    'delete_time' => null
                ]);
            }
        }
    }

    /**
     * Update expense metadata records.
     *
     * @param PocketExpense $expense
     * @param array<string, mixed> $metadataArray
     */
    private function updateExpenseMetadata(PocketExpense $expense, array $metadataArray): void
    {
        $userId = auth()->check() ? auth()->user()->id : $expense->updated_by_user_id;

        // Update or create expense source metadata
        if (isset($metadataArray['source_id'])) {
            $sourceMetadata = PocketExpenseMetadata::where('pocket_expense_id', $expense->id)
                ->where('metadata_type', 'expense_source')
                ->first();

            if ($sourceMetadata) {
                $sourceMetadata->expense_source_id = (int) $metadataArray['source_id'];
                $sourceMetadata->details_json = isset($metadataArray['source_note']) ? ['source_note' => $metadataArray['source_note']] : null;
                $sourceMetadata->update_time = now();
                $sourceMetadata->save();
            } else {
                PocketExpenseMetadata::create([
                    'pocket_expense_id' => $expense->id,
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => (int) $metadataArray['source_id'],
                    'user_id' => $userId,
                    'details_json' => isset($metadataArray['source_note']) ? ['source_note' => $metadataArray['source_note']] : null,
                    'create_time' => now(),
                    'update_time' => now(),
                    'deleted' => false,
                    'delete_time' => null
                ]);
            }
        }

        // Handle other metadata types similarly (category, project, etc.)
        $metadataTypes = [
            'category_id' => ['type' => 'category', 'field' => 'transaction_category_id'],
            'project_id' => ['type' => 'project', 'field' => 'project_id'],
            'tracking_code_id' => ['type' => 'tracking_code', 'field' => 'tracking_code_id']
        ];

        foreach ($metadataTypes as $inputField => $config) {
            if (isset($metadataArray[$inputField])) {
                $metadata = PocketExpenseMetadata::where('pocket_expense_id', $expense->id)
                    ->where('metadata_type', $config['type'])
                    ->first();

                if ($metadata) {
                    $metadata->{$config['field']} = (int) $metadataArray[$inputField];
                    $metadata->update_time = now();
                    $metadata->save();
                } else {
                    $createData = [
                        'pocket_expense_id' => $expense->id,
                        'metadata_type' => $config['type'],
                        $config['field'] => (int) $metadataArray[$inputField],
                        'user_id' => $userId,
                        'create_time' => now(),
                        'update_time' => now(),
                        'deleted' => false,
                        'delete_time' => null
                    ];
                    PocketExpenseMetadata::create($createData);
                }
            }
        }

        // Handle file attachments
        if (isset($metadataArray['file_attachments']) && is_array($metadataArray['file_attachments'])) {
            // Remove existing file attachment metadata
            PocketExpenseMetadata::where('pocket_expense_id', $expense->id)
                ->where('metadata_type', 'file_attachment')
                ->update([
                    'deleted' => true,
                    'delete_time' => now(),
                    'update_time' => now()
                ]);

            // Create new file attachment metadata
            $attachments = array_slice($metadataArray['file_attachments'], 0, self::MAX_FILE_ATTACHMENTS);
            foreach ($attachments as $fileId) {
                PocketExpenseMetadata::create([
                    'pocket_expense_id' => $expense->id,
                    'metadata_type' => 'file_attachment',
                    'file_store_id' => (int) $fileId,
                    'user_id' => $userId,
                    'create_time' => now(),
                    'update_time' => now(),
                    'deleted' => false,
                    'delete_time' => null
                ]);
            }
        }
    }

    /**
     * Handle expense status transitions.
     *
     * @param PocketExpense $expense
     * @param string $fromStatus
     * @param string $toStatus
     */
    private function handleStatusTransition(PocketExpense $expense, string $fromStatus, string $toStatus): void
    {
        switch ($toStatus) {
            case PocketExpense::STATUS_APPROVED:
                if (auth()->check()) {
                    $expense->approved_by_user_id = auth()->user()->id;
                }
                break;

            case PocketExpense::STATUS_DRAFT:
                // Clear approver when returning to draft
                $expense->approved_by_user_id = null;
                break;

            case PocketExpense::STATUS_SUBMITTED:
            case PocketExpense::STATUS_REJECTED:
                // No specific handling needed
                break;
        }
    }

    /**
     * Check if update is status-only (allowed even when expense is not editable).
     *
     * @param array<string, mixed> $updateData
     * @return bool
     */
    private function isStatusOnlyUpdate(array $updateData): bool
    {
        $allowedStatusOnlyFields = ['status'];
        $updateFields = array_keys($updateData);
        
        return count($updateFields) === 1 && in_array($updateFields[0], $allowedStatusOnlyFields);
    }

    /**
     * Apply filters to expense query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array<string, mixed> $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyExpenseFilters($query, array $filters)
    {
        // Date range filters
        if (isset($filters['start_date'])) {
            $query->where('date', '>=', Carbon::parse($filters['start_date']));
        }
        if (isset($filters['end_date'])) {
            $query->where('date', '<=', Carbon::parse($filters['end_date']));
        }

        // Status filter
        if (isset($filters['status']) && in_array($filters['status'], self::VALID_STATUSES)) {
            $query->where('status', $filters['status']);
        }

        // Currency filter
        if (isset($filters['currency'])) {
            $query->where('currency', strtoupper($filters['currency']));
        }

        // Amount range filters
        if (isset($filters['min_amount'])) {
            $query->where('amount', '>=', (float) $filters['min_amount']);
        }
        if (isset($filters['max_amount'])) {
            $query->where('amount', '<=', (float) $filters['max_amount']);
        }

        // Expense type filter
        if (isset($filters['expense_type'])) {
            $query->where('expense_type', (int) $filters['expense_type']);
        }

        // Merchant name filter
        if (isset($filters['merchant_name'])) {
            $query->where('merchant_name', 'like', '%' . $filters['merchant_name'] . '%');
        }

        // Created by filter
        if (isset($filters['created_by'])) {
            $query->where('created_by_user_id', (int) $filters['created_by']);
        }

        return $query;
    }

    /**
     * Calculate approval rate for expenses.
     *
     * @param int $clientId
     * @param array<string, mixed> $filters
     * @return float
     */
    private function calculateApprovalRate(int $clientId, array $filters = []): float
    {
        $query = PocketExpense::where('client_id', $clientId)