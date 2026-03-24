<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

/**
 * Service class for handling PocketExpense business logic operations.
 * Provides CRUD operations, FX conversion integration, and data validation.
 */
class PocketExpenseService
{
    /**
     * FX Conversion service for currency operations.
     *
     * @var FXConversionService
     */
    protected FXConversionService $fxService;

    /**
     * Constructor to inject dependencies.
     *
     * @param FXConversionService $fxService
     */
    public function __construct(FXConversionService $fxService)
    {
        $this->fxService = $fxService;
    }

    /**
     * Create a new pocket expense with metadata and FX conversion.
     *
     * @param array $data Expense data including metadata
     * @return PocketExpense
     * @throws Exception
     */
    public function create(array $data): PocketExpense
    {
        // Start database transaction for atomicity
        return DB::transaction(function () use ($data) {
            // Apply FX conversion if needed
            $processedData = $this->applyFXConversion($data);
            
            // Ensure amount sign is correct based on expense type
            $processedData = $this->ensureCorrectAmountSign($processedData);
            
            // Generate UUID for the expense
            $processedData['uuid'] = Str::uuid()->toString();
            
            // Set default status if not provided
            $processedData['status'] = $processedData['status'] ?? 'draft';
            
            // Set timestamps using Volopa legacy format
            $processedData['create_time'] = now();
            $processedData['update_time'] = now();
            
            // Set deletion flags to default values
            $processedData['deleted'] = false;
            $processedData['delete_time'] = null;
            
            // Extract metadata before creating expense
            $metadata = $processedData['metadata'] ?? [];
            unset($processedData['metadata']);
            
            // Create the expense record
            $expense = PocketExpense::create($processedData);
            
            // Create associated metadata if provided
            if (!empty($metadata)) {
                $this->createMetadata($expense, $metadata);
            }
            
            Log::info('Pocket expense created successfully', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id,
                'amount' => $expense->amount,
                'currency' => $expense->currency
            ]);
            
            return $expense->load(['expenseType', 'user', 'client', 'metadata']);
        });
    }

    /**
     * Update an existing pocket expense.
     *
     * @param PocketExpense $expense
     * @param array $data
     * @return PocketExpense
     * @throws Exception
     */
    public function update(PocketExpense $expense, array $data): PocketExpense
    {
        return DB::transaction(function () use ($expense, $data) {
            // Apply FX conversion if currency or amount changed
            $processedData = $this->applyFXConversion($data);
            
            // Ensure amount sign is correct if expense type changed
            $processedData = $this->ensureCorrectAmountSign($processedData);
            
            // Update timestamps
            $processedData['update_time'] = now();
            
            // Extract metadata before updating expense
            $metadata = $processedData['metadata'] ?? null;
            unset($processedData['metadata']);
            
            // Update the expense record
            $expense->update($processedData);
            
            // Update metadata if provided
            if ($metadata !== null) {
                $this->updateMetadata($expense, $metadata);
            }
            
            Log::info('Pocket expense updated successfully', [
                'expense_id' => $expense->id,
                'uuid' => $expense->uuid,
                'user_id' => $expense->user_id,
                'client_id' => $expense->client_id
            ]);
            
            return $expense->fresh(['expenseType', 'user', 'client', 'metadata']);
        });
    }

    /**
     * Soft delete a pocket expense.
     *
     * @param PocketExpense $expense
     * @return bool
     * @throws Exception
     */
    public function delete(PocketExpense $expense): bool
    {
        return DB::transaction(function () use ($expense) {
            // Use flag-based soft delete as per system constraints
            $deleted = $expense->update([
                'deleted' => true,
                'delete_time' => now(),
                'update_time' => now()
            ]);
            
            // Also soft delete associated metadata
            $expense->metadata()->update([
                'deleted' => true,
                'delete_time' => now(),
                'update_time' => now()
            ]);
            
            if ($deleted) {
                Log::info('Pocket expense deleted successfully', [
                    'expense_id' => $expense->id,
                    'uuid' => $expense->uuid,
                    'user_id' => $expense->user_id,
                    'client_id' => $expense->client_id
                ]);
            }
            
            return $deleted;
        });
    }

    /**
     * Find expenses for a specific user.
     *
     * @param User $user
     * @param array $filters Additional filtering options
     * @return Collection
     */
    public function findByUser(User $user, array $filters = []): Collection
    {
        $query = PocketExpense::query()
            ->where('user_id', $user->id)
            ->where('deleted', false)
            ->with(['expenseType', 'client', 'metadata' => function ($query) {
                $query->where('deleted', false);
            }]);
        
        // Apply client filter if provided
        if (isset($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }
        
        // Apply status filter if provided
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        // Apply date range filter if provided
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        // Apply currency filter if provided
        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }
        
        // Apply amount range filters if provided
        if (isset($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }
        
        if (isset($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }
        
        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);
        
        return $query->get();
    }

    /**
     * Find expenses for a client with optional user filtering.
     *
     * @param Client $client
     * @param array $filters Additional filtering options
     * @return Collection
     */
    public function findByClient(Client $client, array $filters = []): Collection
    {
        $query = PocketExpense::query()
            ->where('client_id', $client->id)
            ->where('deleted', false)
            ->with(['expenseType', 'user', 'metadata' => function ($query) {
                $query->where('deleted', false);
            }]);
        
        // Apply user filter if provided (for admin access control)
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        // Apply status filter if provided
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        // Apply date range filter if provided
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'date';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);
        
        return $query->get();
    }

    /**
     * Apply FX conversion to expense data.
     *
     * @param array $data Expense data
     * @return array Processed data with FX conversion
     */
    public function applyFXConversion(array $data): array
    {
        // Only apply FX conversion if we have currency, amount, date, and client_id
        if (!isset($data['currency']) || !isset($data['amount']) || !isset($data['date']) || !isset($data['client_id'])) {
            return $data;
        }
        
        try {
            $fxResult = $this->fxService->convertAmount(
                $data['currency'],
                $data['amount'],
                Carbon::parse($data['date']),
                $data['client_id']
            );
            
            // Store FX information in metadata if conversion was successful
            if ($fxResult['success'] ?? false) {
                $data['metadata'] = $data['metadata'] ?? [];
                $data['metadata']['fx_conversion'] = [
                    'original_currency' => $data['currency'],
                    'original_amount' => $data['amount'],
                    'converted_currency' => $fxResult['base_currency'] ?? null,
                    'converted_amount' => $fxResult['converted_amount'] ?? null,
                    'fx_rate' => $fxResult['fx_rate'] ?? null,
                    'conversion_date' => now()->toISOString(),
                    'fx_available' => true
                ];
            } else {
                // Store FX unavailable information
                $data['metadata'] = $data['metadata'] ?? [];
                $data['metadata']['fx_conversion'] = [
                    'original_currency' => $data['currency'],
                    'original_amount' => $data['amount'],
                    'fx_available' => false,
                    'fx_message' => $fxResult['message'] ?? 'No FX Available',
                    'conversion_date' => now()->toISOString()
                ];
            }
            
        } catch (Exception $e) {
            Log::warning('FX conversion failed for expense', [
                'currency' => $data['currency'],
                'amount' => $data['amount'],
                'date' => $data['date'],
                'client_id' => $data['client_id'],
                'error' => $e->getMessage()
            ]);
            
            // Store FX error information
            $data['metadata'] = $data['metadata'] ?? [];
            $data['metadata']['fx_conversion'] = [
                'original_currency' => $data['currency'],
                'original_amount' => $data['amount'],
                'fx_available' => false,
                'fx_message' => 'FX conversion error',
                'conversion_date' => now()->toISOString()
            ];
        }
        
        return $data;
    }

    /**
     * Ensure amount has correct sign based on expense type.
     *
     * @param array $data Expense data
     * @return array Data with corrected amount sign
     */
    protected function ensureCorrectAmountSign(array $data): array
    {
        if (!isset($data['expense_type']) || !isset($data['amount'])) {
            return $data;
        }
        
        try {
            $expenseType = OptPocketExpenseType::find($data['expense_type']);
            if (!$expenseType) {
                return $data;
            }
            
            $amount = abs((float) $data['amount']);
            
            // Apply correct sign based on expense type
            if ($expenseType->amount_sign === 'positive') {
                $data['amount'] = $amount; // Positive amount (refunds)
            } else {
                $data['amount'] = -$amount; // Negative amount (charges)
            }
            
        } catch (Exception $e) {
            Log::warning('Could not determine expense type amount sign', [
                'expense_type' => $data['expense_type'],
                'error' => $e->getMessage()
            ]);
        }
        
        return $data;
    }

    /**
     * Create metadata records for an expense.
     *
     * @param PocketExpense $expense
     * @param array $metadata
     * @return void
     */
    protected function createMetadata(PocketExpense $expense, array $metadata): void
    {
        foreach ($metadata as $type => $data) {
            if (empty($data)) {
                continue;
            }
            
            $metadataRecord = [
                'pocket_expense_id' => $expense->id,
                'metadata_type' => $type,
                'user_id' => $expense->user_id,
                'details_json' => is_array($data) ? $data : ['value' => $data],
                'create_time' => now(),
                'update_time' => now(),
                'deleted' => false,
                'delete_time' => null
            ];
            
            // Handle specific metadata types with foreign key references
            switch ($type) {
                case 'expense_source':
                    if (isset($data['source_id'])) {
                        $metadataRecord['expense_source_id'] = $data['source_id'];
                    }
                    break;
                case 'category':
                    if (isset($data['category_id'])) {
                        $metadataRecord['transaction_category_id'] = $data['category_id'];
                    }
                    break;
                case 'tracking_code_type_1':
                case 'tracking_code_type_2':
                    if (isset($data['tracking_code_id'])) {
                        $metadataRecord['tracking_code_id'] = $data['tracking_code_id'];
                    }
                    break;
                case 'project':
                    if (isset($data['project_id'])) {
                        $metadataRecord['project_id'] = $data['project_id'];
                    }
                    break;
                case 'file':
                    if (isset($data['file_store_id'])) {
                        $metadataRecord['file_store_id'] = $data['file_store_id'];
                    }
                    break;
                case 'additional_field':
                    if (isset($data['additional_field_id'])) {
                        $metadataRecord['additional_field_id'] = $data['additional_field_id'];
                    }
                    break;
            }
            
            PocketExpenseMetadata::create($metadataRecord);
        }
    }

    /**
     * Update metadata records for an expense.
     *
     * @param PocketExpense $expense
     * @param array $metadata
     * @return void
     */
    protected function updateMetadata(PocketExpense $expense, array $metadata): void
    {
        // For simplicity, we'll delete existing metadata and recreate
        // In production, you might want a more sophisticated merge strategy
        $expense->metadata()->update([
            'deleted' => true,
            'delete_time' => now(),
            'update_time' => now()
        ]);
        
        $this->createMetadata($expense, $metadata);
    }

    /**
     * Get expenses summary for a user or client.
     *
     * @param array $filters Filtering criteria
     * @return array Summary statistics
     */
    public function getExpensesSummary(array $filters = []): array
    {
        $query = PocketExpense::query()->where('deleted', false);
        
        // Apply filters
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        
        if (isset($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (isset($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        
        // Get summary statistics
        $totalExpenses = $query->count();
        $totalAmount = $query->sum('amount');
        $avgAmount = $query->avg('amount');
        
        // Get status breakdown
        $statusBreakdown = $query->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status')
            ->toArray();
        
        // Get currency breakdown
        $currencyBreakdown = $query->selectRaw('currency, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('currency')
            ->get()
            ->keyBy('currency')
            ->toArray();
        
        return [
            'total_expenses' => $totalExpenses,
            'total_amount' => round((float) $totalAmount, 2),
            'average_amount' => round((float) $avgAmount, 2),
            'status_breakdown' => $statusBreakdown,
            'currency_breakdown' => $currencyBreakdown,
            'summary_date' => now()->toISOString()
        ];
    }

    /**
     * Approve an expense (change status to approved).
     *
     * @param PocketExpense $expense
     * @param int $approvedByUserId
     * @return PocketExpense
     * @throws Exception
     */
    public function approve(PocketExpense $expense, int $approvedByUserId): PocketExpense
    {
        if ($expense->status === 'approved') {
            throw new Exception('Expense is already approved');
        }
        
        return $this->update($expense, [
            'status' => 'approved',
            'approved_by_user_id' => $approvedByUserId,
            'updated_by_user_id' => $approvedByUserId
        ]);
    }

    /**
     * Reject an expense (change status to rejected).
     *
     * @param PocketExpense $expense
     * @param int $rejectedByUserId
     * @param string|null $rejectionReason
     * @return PocketExpense
     * @throws Exception
     */
    public function reject(PocketExpense $expense, int $rejectedByUserId, string $rejectionReason = null): PocketExpense
    {
        if ($expense->status === 'rejected') {
            throw new Exception('Expense is already rejected');
        }
        
        $updateData = [
            'status' => 'rejected',
            'approved_by_user_id' => $rejectedByUserId,
            'updated_by_user_id' => $rejectedByUserId
        ];
        
        // Add rejection reason to metadata if provided
        if ($rejectionReason) {
            $updateData['metadata'] = [
                'rejection' => [
                    'reason' => $rejectionReason,
                    'rejected_by' => $rejectedByUserId,
                    'rejected_at' => now()->toISOString()
                ]
            ];
        }
        
        return $this->update($expense, $updateData);
    }

    /**
     * Submit an expense (change status from draft to submitted).
     *
     * @param PocketExpense $expense
     * @param int $submittedByUserId
     * @return PocketExpense
     * @throws Exception
     */
    public function submit(PocketExpense $expense, int $submittedByUserId): PocketExpense
    {
        if ($expense->status !== 'draft') {
            throw new Exception('Only draft expenses can be submitted');
        }
        
        return $this->update($expense, [
            'status' => 'submitted',
            'updated_by_user_id' => $submittedByUserId
        ]);
    }

    /**
     * Validate expense data before creation or update.
     *
     * @param array $data Expense data to validate
     * @return array Validation errors (empty if valid)
     */
    public function validateExpenseData(array $data): array
    {
        $errors = [];
        
        // Date validation - not older than 3 years
        if (isset($data['date'])) {
            $expenseDate = Carbon::parse($data['date']);
            $threeYearsAgo = now()->subYears(3);
            
            if ($expenseDate->lt($threeYearsAgo)) {
                $errors['date'] = 'Expense date cannot be older than 3 years';
            }
        }
        
        // Merchant name length validation
        if (isset($data['merchant_name']) && strlen($data['merchant_name']) > 180) {
            $errors['merchant_name'] = 'Merchant name cannot exceed 180 characters';
        }
        
        // VAT amount validation (0-100)
        if (isset($data['vat_amount'])) {
            $vatAmount = (float) $data['vat_amount'];
            if ($vatAmount < 0 || $vatAmount > 100) {
                $errors['vat_amount'] = 'VAT amount must be between 0 and 100';
            }
        }
        
        // Currency code validation (3 characters)
        if (isset($data['currency']) && strlen($data['currency']) !== 3) {
            $errors['currency'] = 'Currency code must be exactly 3 characters';
        }
        
        // Status validation
        if (isset($data['status'])) {
            $validStatuses = ['draft', 'submitted', 'approved', 'rejected'];
            if (!in_array($data['status'], $validStatuses)) {
                $errors['status'] = 'Invalid status value';
            }
        }
        
        return $errors;
    }

    /**
     * Get expenses requiring approval for a specific client.
     *
     * @param int $clientId
     * @param array $filters Additional filters
     * @return Collection
     */
    public function getExpensesForApproval(int $clientId, array $filters = []): Collection
    {
        $query = PocketExpense::query()
            ->where('client_id', $clientId)
            ->where('status', 'submitted')
            ->where('deleted', false)
            ->with(['expenseType', 'user', 'metadata' => function ($query) {
                $query->where('deleted', false);
            }]);
        
        // Apply user filter if provided (for manager approvals)
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
        
        // Apply amount filters for approval limits
        if (isset($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min']);
        }
        
        if (isset($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max']);
        }
        
        // Order by date (oldest first for approval queue)
        $query->orderBy('date', 'asc');
        
        return $query->get();
    }

    /**
     * Bulk approve multiple expenses.
     *
     * @param array $expenseIds
     * @param int $approvedByUserId
     * @return array Results with successful and failed approvals
     */
    public function bulkApprove(array $expenseIds, int $approvedByUserId): array
    {
        $successful = [];
        $failed = [];
        
        DB::transaction(function () use ($expenseIds, $approvedByUserId, &$successful, &$failed) {
            foreach ($expenseIds as $expenseId) {
                try {
                    $expense = PocketExpense::find($expenseId);
                    if (!$expense) {
                        $failed[] = ['id' => $expenseId, 'error' => 'Expense not found'];
                        continue;
                    }
                    
                    if ($expense->status !== 'submitted') {
                        $failed[] = ['id' => $expenseId, 'error' => 'Expense not in submitted status'];
                        continue;
                    }
                    
                    $this->approve($expense, $approvedByUserId);
                    $successful[] = ['id' => $expenseId, 'uuid' => $expense->uuid];
                    
                } catch (Exception $e) {
                    $failed[] = ['id' => $expenseId, 'error' => $e->getMessage()];
                }
            }
        });
        
        Log::info('Bulk approval completed', [
            'approved_by' => $approvedByUserId,
            'successful_count' => count($successful),
            'failed_count' => count($failed)
        ]);
        
        return [
            'successful' => $successful,
            'failed' => $failed,
            'summary' => [
                'total_processed' => count($expenseIds),
                'successful_count' => count($successful),
                'failed_count' => count($failed)
            ]
        ];
    }
}