<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\User;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * PocketExpenseService
 * 
 * Core business logic service for pocket expense CRUD operations.
 * Handles expense creation, updates, deletion, and FX conversion integration.
 * Implements multi-tenant client scoping and comprehensive validation.
 */
class PocketExpenseService
{
    /**
     * FX Conversion Service instance.
     *
     * @var \App\Services\FXConversionService
     */
    protected FXConversionService $fxService;

    /**
     * Maximum expense age in years.
     */
    const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Maximum merchant name length.
     */
    const MAX_MERCHANT_NAME_LENGTH = 180;

    /**
     * Valid currency code pattern (3-letter ISO).
     */
    const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    /**
     * Create a new PocketExpenseService instance.
     *
     * @param \App\Services\FXConversionService $fxService
     */
    public function __construct(FXConversionService $fxService)
    {
        $this->fxService = $fxService;
    }

    /**
     * Create a new pocket expense with FX conversion and validation.
     *
     * @param array $data
     * @return \App\Models\PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function createExpense(array $data): PocketExpense
    {
        // Validate required fields
        $this->validateExpenseData($data);

        // Apply FX conversion if needed
        $data = $this->applyFXConversion($data);

        // Start database transaction
        return DB::transaction(function () use ($data) {
            try {
                // Create the main expense record
                $expense = PocketExpense::createExpense([
                    'user_id' => $data['user_id'],
                    'client_id' => $data['client_id'],
                    'date' => $data['date'],
                    'merchant_name' => $this->sanitizeMerchantName($data['merchant_name']),
                    'merchant_description' => $data['merchant_description'] ?? null,
                    'expense_type' => $data['expense_type'] ?? null,
                    'currency' => strtoupper($data['currency']),
                    'amount' => abs((float) $data['amount']),
                    'merchant_address' => $data['merchant_address'] ?? null,
                    'vat_amount' => isset($data['vat_amount']) ? abs((float) $data['vat_amount']) : null,
                    'notes' => $this->sanitizeNotes($data['notes'] ?? null),
                    'status' => $data['status'] ?? PocketExpense::STATUS_DRAFT,
                    'created_by_user_id' => $data['created_by_user_id'],
                ]);

                // Create associated metadata if provided
                if (!empty($data['metadata'])) {
                    $this->createExpenseMetadata($expense, $data['metadata'], $data['created_by_user_id']);
                }

                // Log expense creation
                Log::info('Pocket expense created', [
                    'expense_id' => $expense->id,
                    'uuid' => $expense->uuid,
                    'user_id' => $expense->user_id,
                    'client_id' => $expense->client_id,
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                    'created_by' => $expense->created_by_user_id,
                ]);

                return $expense;

            } catch (\Exception $e) {
                Log::error('Failed to create pocket expense', [
                    'error' => $e->getMessage(),
                    'user_id' => $data['user_id'] ?? null,
                    'client_id' => $data['client_id'] ?? null,
                    'amount' => $data['amount'] ?? null,
                    'currency' => $data['currency'] ?? null,
                ]);

                throw new RuntimeException('Failed to create expense: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Update an existing pocket expense with validation and FX conversion.
     *
     * @param \App\Models\PocketExpense $expense
     * @param array $data
     * @return \App\Models\PocketExpense
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function updateExpense(PocketExpense $expense, array $data): PocketExpense
    {
        // Check if expense can be edited
        if (!$expense->canEdit()) {
            throw new RuntimeException('Expense cannot be edited in current status: ' . $expense->status);
        }

        // Validate update data
        $this->validateExpenseUpdateData($data);

        // Apply FX conversion if currency or amount changed
        if (isset($data['currency']) || isset($data['amount'])) {
            $data = $this->applyFXConversion(array_merge($expense->toArray(), $data));
        }

        // Start database transaction
        return DB::transaction(function () use ($expense, $data) {
            try {
                // Update expense fields
                $updateFields = [];

                if (isset($data['date'])) {
                    $updateFields['date'] = $data['date'];
                }

                if (isset($data['merchant_name'])) {
                    $updateFields['merchant_name'] = $this->sanitizeMerchantName($data['merchant_name']);
                }

                if (isset($data['merchant_description'])) {
                    $updateFields['merchant_description'] = $data['merchant_description'];
                }

                if (isset($data['expense_type'])) {
                    $updateFields['expense_type'] = $data['expense_type'];
                }

                if (isset($data['currency'])) {
                    $updateFields['currency'] = strtoupper($data['currency']);
                }

                if (isset($data['amount'])) {
                    $updateFields['amount'] = abs((float) $data['amount']);
                }

                if (isset($data['merchant_address'])) {
                    $updateFields['merchant_address'] = $data['merchant_address'];
                }

                if (isset($data['vat_amount'])) {
                    $updateFields['vat_amount'] = isset($data['vat_amount']) ? abs((float) $data['vat_amount']) : null;
                }

                if (isset($data['notes'])) {
                    $updateFields['notes'] = $this->sanitizeNotes($data['notes']);
                }

                if (isset($data['updated_by_user_id'])) {
                    $updateFields['updated_by_user_id'] = $data['updated_by_user_id'];
                }

                // Update the expense
                $expense->update($updateFields);

                // Update metadata if provided
                if (isset($data['metadata'])) {
                    $this->updateExpenseMetadata($expense, $data['metadata'], $data['updated_by_user_id'] ?? $expense->created_by_user_id);
                }

                // Reload to get fresh data
                $expense->refresh();

                // Log expense update
                Log::info('Pocket expense updated', [
                    'expense_id' => $expense->id,
                    'uuid' => $expense->uuid,
                    'user_id' => $expense->user_id,
                    'client_id' => $expense->client_id,
                    'updated_fields' => array_keys($updateFields),
                    'updated_by' => $expense->updated_by_user_id,
                ]);

                return $expense;

            } catch (\Exception $e) {
                Log::error('Failed to update pocket expense', [
                    'expense_id' => $expense->id,
                    'error' => $e->getMessage(),
                    'update_data' => $data,
                ]);

                throw new RuntimeException('Failed to update expense: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Delete a pocket expense (soft delete).
     *
     * @param \App\Models\PocketExpense $expense
     * @return bool
     * @throws \RuntimeException
     */
    public function deleteExpense(PocketExpense $expense): bool
    {
        if (!$expense->canDelete()) {
            throw new RuntimeException('Expense cannot be deleted in current status: ' . $expense->status);
        }

        return DB::transaction(function () use ($expense) {
            try {
                // Soft delete the expense
                $result = $expense->softDelete();

                if ($result) {
                    // Soft delete associated metadata
                    $expense->metadata()->update([
                        'deleted' => true,
                        'delete_time' => now(),
                    ]);

                    Log::info('Pocket expense deleted', [
                        'expense_id' => $expense->id,
                        'uuid' => $expense->uuid,
                        'user_id' => $expense->user_id,
                        'client_id' => $expense->client_id,
                        'deleted_at' => $expense->delete_time,
                    ]);
                }

                return $result;

            } catch (\Exception $e) {
                Log::error('Failed to delete pocket expense', [
                    'expense_id' => $expense->id,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('Failed to delete expense: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Get expenses for a specific user and client with pagination.
     *
     * @param \App\Models\User $user
     * @param \App\Models\Client $client
     * @param array $filters
     * @param int $perPage
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getUserExpenses(User $user, Client $client, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PocketExpense::forUserAndClient($user->id, $client->id)
                              ->with(['expenseType', 'createdBy', 'updatedBy', 'approvedBy', 'metadata']);

        // Apply filters
        $query = $this->applyExpenseFilters($query, $filters);

        // Default ordering by creation date (newest first)
        $query->orderBy('create_time', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * Get all expenses for a client (admin view).
     *
     * @param \App\Models\Client $client
     * @param array $filters
     * @param int $perPage
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getClientExpenses(Client $client, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PocketExpense::active()
                              ->forClient($client->id)
                              ->with(['user', 'expenseType', 'createdBy', 'updatedBy', 'approvedBy', 'metadata']);

        // Apply filters
        $query = $this->applyExpenseFilters($query, $filters);

        // Default ordering by creation date (newest first)
        $query->orderBy('create_time', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * Apply FX conversion to expense data if needed.
     *
     * @param array $data
     * @return array
     */
    public function applyFXConversion(array $data): array
    {
        if (!isset($data['client_id'], $data['currency'], $data['amount'])) {
            return $data;
        }

        try {
            // Get wallet base currency for the client
            $walletInfo = $this->fxService->getWalletBaseCurrency((int) $data['client_id']);
            
            if (empty($walletInfo['currency']) || $walletInfo['currency'] === $data['currency']) {
                // No conversion needed
                return $data;
            }

            $expenseDate = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
            
            // Get FX rate from expense currency to wallet base currency
            $fxRate = $this->fxService->getFXRate(
                $data['currency'],
                $walletInfo['currency'],
                $expenseDate
            );

            if ($fxRate === null) {
                Log::warning('FX rate not available for conversion', [
                    'from_currency' => $data['currency'],
                    'to_currency' => $walletInfo['currency'],
                    'date' => $expenseDate->toDateString(),
                    'client_id' => $data['client_id'],
                ]);
                return $data;
            }

            // Calculate converted amount with commission
            $commission = $walletInfo['commission'] ?? 0.0;
            $convertedAmount = $this->fxService->calculateConvertedAmount(
                (float) $data['amount'],
                $fxRate,
                $commission
            );

            // Add conversion information to data
            $data['converted_amount'] = $convertedAmount;
            $data['converted_currency'] = $walletInfo['currency'];
            $data['fx_rate'] = $fxRate;
            $data['fx_commission'] = $commission;

            Log::info('FX conversion applied to expense', [
                'original_amount' => $data['amount'],
                'original_currency' => $data['currency'],
                'converted_amount' => $convertedAmount,
                'converted_currency' => $walletInfo['currency'],
                'fx_rate' => $fxRate,
                'commission' => $commission,
                'client_id' => $data['client_id'],
            ]);

        } catch (\Exception $e) {
            Log::error('FX conversion failed', [
                'error' => $e->getMessage(),
                'client_id' => $data['client_id'],
                'currency' => $data['currency'],
                'amount' => $data['amount'],
            ]);
            
            // Continue without conversion on FX service errors
        }

        return $data;
    }

    /**
     * Submit an expense for approval.
     *
     * @param \App\Models\PocketExpense $expense
     * @param int $submittedByUserId
     * @return \App\Models\PocketExpense
     * @throws \RuntimeException
     */
    public function submitExpense(PocketExpense $expense, int $submittedByUserId): PocketExpense
    {
        if (!$expense->submit($submittedByUserId)) {
            throw new RuntimeException('Cannot submit expense in current status: ' . $expense->status);
        }

        $expense->refresh();
        return $expense;
    }

    /**
     * Approve an expense.
     *
     * @param \App\Models\PocketExpense $expense
     * @param int $approvedByUserId
     * @return \App\Models\PocketExpense
     * @throws \RuntimeException
     */
    public function approveExpense(PocketExpense $expense, int $approvedByUserId): PocketExpense
    {
        if (!$expense->approve($approvedByUserId)) {
            throw new RuntimeException('Cannot approve expense in current status: ' . $expense->status);
        }

        $expense->refresh();
        return $expense;
    }

    /**
     * Reject an expense.
     *
     * @param \App\Models\PocketExpense $expense
     * @param int $rejectedByUserId
     * @return \App\Models\PocketExpense
     * @throws \RuntimeException
     */
    public function rejectExpense(PocketExpense $expense, int $rejectedByUserId): PocketExpense
    {
        if (!$expense->reject($rejectedByUserId)) {
            throw new RuntimeException('Cannot reject expense in current status: ' . $expense->status);
        }

        $expense->refresh();
        return $expense;
    }

    /**
     * Get expense statistics for a client.
     *
     * @param \App\Models\Client $client
     * @param array $filters
     * @return array
     */
    public function getExpenseStatistics(Client $client, array $filters = []): array
    {
        $query = PocketExpense::active()->forClient($client->id);
        
        // Apply date range filter if provided
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $query->dateRange(
                Carbon::parse($filters['date_from']),
                Carbon::parse($filters['date_to'])
            );
        }

        // Get statistics
        $totalExpenses = $query->count();
        $totalAmount = $query->sum('amount');
        $averageAmount = $totalExpenses > 0 ? ($totalAmount / $totalExpenses) : 0;

        // Status breakdown
        $statusStats = PocketExpense::active()
                                   ->forClient($client->id)
                                   ->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                                   ->groupBy('status')
                                   ->get()
                                   ->keyBy('status');

        // Currency breakdown
        $currencyStats = PocketExpense::active()
                                     ->forClient($client->id)
                                     ->selectRaw('currency, COUNT(*) as count, SUM(amount) as total_amount')
                                     ->groupBy('currency')
                                     ->get()
                                     ->keyBy('currency');

        return [
            'total_expenses' => $totalExpenses,
            'total_amount' => $totalAmount,
            'average_amount' => round($averageAmount, 2),
            'status_breakdown' => $statusStats,
            'currency_breakdown' => $currencyStats,
        ];
    }

    /**
     * Validate expense data for creation.
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    protected function validateExpenseData(array $data): void
    {
        $required = ['user_id', 'client_id', 'date', 'merchant_name', 'currency', 'amount', 'created_by_user_id'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                throw new InvalidArgumentException("Required field '{$field}' is missing or empty.");
            }
        }

        $this->validateCommonExpenseData($data);
    }

    /**
     * Validate expense data for updates.
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    protected function validateExpenseUpdateData(array $data): void
    {
        $this->validateCommonExpenseData($data);
    }

    /**
     * Validate common expense data fields.
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    protected function validateCommonExpenseData(array $data): void
    {
        // Validate date
        if (isset($data['date'])) {
            try {
                $expenseDate = Carbon::parse($data['date']);
                $maxAge = Carbon::now()->subYears(self::MAX_EXPENSE_AGE_YEARS);
                
                if ($expenseDate < $maxAge) {
                    throw new InvalidArgumentException('Expense date cannot be older than ' . self::MAX_EXPENSE_AGE_YEARS . ' years.');
                }
                
                if ($expenseDate > Carbon::now()) {
                    throw new InvalidArgumentException('Expense date cannot be in the future.');
                }
            } catch (\Exception $e) {
                throw new InvalidArgumentException('Invalid expense date format: ' . $data['date']);
            }
        }

        // Validate currency
        if (isset($data['currency'])) {
            $currency = strtoupper($data['currency']);
            if (!preg_match(self::CURRENCY_PATTERN, $currency)) {
                throw new InvalidArgumentException('Currency must be a 3-letter ISO code: ' . $data['currency']);
            }
        }

        // Validate amount
        if (isset($data['amount'])) {
            if (!is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
                throw new InvalidArgumentException('Amount must be a positive number: ' . $data['amount']);
            }
        }

        // Validate VAT amount
        if (isset($data['vat_amount']) && $data['vat_amount'] !== null) {
            if (!is_numeric($data['vat_amount']) || (float) $data['vat_amount'] < 0) {
                throw new InvalidArgumentException('VAT amount must be a non-negative number: ' . $data['vat_amount']);
            }
        }

        // Validate merchant name length
        if (isset($data['merchant_name']) && strlen($data['merchant_name']) > self::MAX_MERCHANT_NAME_LENGTH) {
            throw new InvalidArgumentException('Merchant name cannot exceed ' . self::MAX_MERCHANT_NAME_LENGTH . ' characters.');
        }

        // Validate status
        if (isset($data['status']) && !PocketExpense::isValidStatus($data['status'])) {
            throw new InvalidArgumentException('Invalid expense status: ' . $data['status']);
        }

        // Validate expense type exists
        if (isset($data['expense_type']) && $data['expense_type'] !== null) {
            if (!OptPocketExpenseType::find($data['expense_type'])) {
                throw new InvalidArgumentException('Invalid expense type ID: ' . $data['expense_type']);
            }
        }

        // Validate user and client exist
        if (isset($data['user_id']) && !User::find($data['user_id'])) {
            throw new InvalidArgumentException('Invalid user ID: ' . $data['user_id']);
        }

        if (isset($data['client_id']) && !Client::find($data['client_id'])) {
            throw new InvalidArgumentException('Invalid client ID: ' . $data['client_id']);
        }
    }

    /**
     * Apply filters to expense query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyExpenseFilters($query, array $filters)
    {
        // Status filter
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->withStatus($filters['status']);
            }
        }

        // Date range filter
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $dateFrom = !empty($filters['date_from']) ? Carbon::parse($filters['date_from']) : Carbon::now()->subYear();
            $dateTo = !empty($filters['date_to']) ? Carbon::parse($filters['date_to']) : Carbon::now();
            $query->dateRange($dateFrom, $dateTo);
        }

        // Currency filter
        if (!empty($filters['currency'])) {
            if (is_array($filters['currency'])) {
                $query->whereIn('currency', $filters['currency']);
            } else {
                $query->withCurrency($filters['currency']);
            }
        }

        // Expense type filter
        if (!empty($filters['expense_type'])) {
            $query->withExpenseType($filters['expense_type']);
        }

        // Amount range filter
        if (!empty($filters['amount_min'])) {
            $query->where('amount', '>=', (float) $filters['amount_min']);
        }

        if (!empty($filters['amount_max'])) {
            $query->where('amount', '<=', (float) $filters['amount_max']);
        }

        // Search filter (merchant name, description, notes)
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($subQuery) use ($searchTerm) {
                $subQuery->where('merchant_name', 'like', $searchTerm)
                         ->orWhere('merchant_description', 'like', $searchTerm)
                         ->orWhere('notes', 'like', $searchTerm);
            });
        }

        // User filter (for admin views)
        if (!empty($filters['user_id'])) {
            $query->forUser($filters['user_id']);
        }

        return $query;
    }

    /**
     * Create expense metadata entries.
     *
     * @param \App\Models\PocketExpense $expense
     * @param array $metadataArray
     * @param int $userId
     */
    protected function createExpenseMetadata(PocketExpense $expense, array $metadataArray, int $userId): void
    {
        foreach ($metadataArray as $metadata) {
            if (!isset($metadata['metadata_type'])) {
                continue;
            }

            PocketExpenseMetadata::create([
                'pocket_expense_id' => $expense->id,
                'metadata_type' => $metadata['metadata_type'],
                'transaction_category_id' => $metadata['transaction_category_id'] ?? null,
                'tracking_code_id' => $metadata['tracking_code_id'] ?? null,
                'project_id' => $metadata['project_id'] ?? null,
                'file_store_id' => $metadata['file_store_id'] ?? null,
                'expense_source_id' => $metadata['expense_source_id'] ?? null,
                'additional_field_id' => $metadata['additional_field_id'] ?? null,
                'user_id' => $userId,
                'details_json' => isset($metadata['details_json']) ? json_encode($metadata['details_json']) : null,
                'deleted' => false,
            ]);
        }
    }

    /**
     * Update expense metadata entries.
     *
     * @param \App\Models\PocketExpense $expense
     * @param array $metadataArray
     * @param int $userId
     */
    protected function updateExpenseMetadata(PocketExpense $expense, array $metadataArray, int $userId): void
    {
        // Soft delete existing metadata
        $expense->metadata()->update([
            'deleted' => true,
            'delete_time' => now(),
        ]);

        // Create new metadata entries
        $this->createExpenseMetadata($expense, $metadataArray, $userId);
    }

    /**
     * Sanitize merchant name to fit database constraints.
     *
     * @param string $merchantName
     * @return string
     */
    protected function sanitizeMerchantName(string $merchantName): string
    {
        return substr(trim($merchantName), 0, self::MAX_MERCHANT_NAME_LENGTH);
    }

    /**
     * Sanitize notes to prevent SQL injection and trim whitespace.
     *
     * @param string|null $notes
     * @return string|null
     */
    protected function sanitizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        return trim(strip_tags($notes));
    }
}