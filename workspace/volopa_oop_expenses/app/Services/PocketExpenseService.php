## Code: app/Services/PocketExpenseService.php

```php
<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\User;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Exception;
use Carbon\Carbon;

class PocketExpenseService
{
    /**
     * Valid status values for pocket expenses.
     *
     * @var array<string>
     */
    private const VALID_STATUSES = [
        'draft',
        'submitted',
        'approved',
        'rejected',
    ];

    /**
     * Valid metadata types for pocket expense metadata.
     *
     * @var array<string>
     */
    private const VALID_METADATA_TYPES = [
        'category',
        'tracking_code',
        'project',
        'file_store',
        'expense_source',
        'additional_field',
        'other',
    ];

    /**
     * Maximum date lookback in years for expense dates.
     *
     * @var int
     */
    private const MAX_DATE_LOOKBACK_YEARS = 3;

    /**
     * Default pagination limit for expense queries.
     *
     * @var int
     */
    private const DEFAULT_PAGINATION_LIMIT = 50;

    /**
     * Create a new pocket expense.
     *
     * @param array<string, mixed> $data
     * @param int $userId
     * @param int $clientId
     * @return PocketExpense
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function createExpense(array $data, int $userId, int $clientId): PocketExpense
    {
        // Validate input parameters
        if ($userId <= 0 || $clientId <= 0) {
            throw new InvalidArgumentException('User ID and Client ID must be positive integers');
        }

        // Validate required fields
        $requiredFields = ['date', 'merchant_name', 'expense_type', 'currency', 'amount'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new InvalidArgumentException("Required field '{$field}' is missing or empty");
            }
        }

        // Validate expense type exists
        $expenseType = OptPocketExpenseType::find($data['expense_type']);
        if (!$expenseType) {
            throw new InvalidArgumentException('Invalid expense type specified');
        }

        // Validate date is not too old
        $expenseDate = Carbon::parse($data['date']);
        $minDate = Carbon::now()->subYears(self::MAX_DATE_LOOKBACK_YEARS);
        if ($expenseDate->lt($minDate)) {
            throw new InvalidArgumentException('Expense date cannot be older than ' . self::MAX_DATE_LOOKBACK_YEARS . ' years');
        }

        // Validate date is not in the future
        if ($expenseDate->gt(Carbon::now())) {
            throw new InvalidArgumentException('Expense date cannot be in the future');
        }

        // Validate user and client relationship
        $this->validateUserClientRelationship($userId, $clientId);

        try {
            DB::beginTransaction();

            // Prepare expense data
            $expenseData = [
                'user_id' => $userId,
                'client_id' => $clientId,
                'date' => $expenseDate->format('Y-m-d'),
                'merchant_name' => trim($data['merchant_name']),
                'merchant_description' => isset($data['merchant_description']) ? trim($data['merchant_description']) : null,
                'expense_type' => $data['expense_type'],
                'currency' => strtoupper($data['currency']),
                'amount' => round((float) $data['amount'], 2),
                'merchant_address' => isset($data['merchant_address']) ? trim($data['merchant_address']) : null,
                'vat_amount' => isset($data['vat_amount']) ? round((float) $data['vat_amount'], 2) : null,
                'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                'status' => $data['status'] ?? 'draft',
                'created_by_user_id' => Auth::id() ?? $userId,
                'updated_by_user_id' => null,
                'approved_by_user_id' => null,
            ];

            // Validate status
            if (!in_array($expenseData['status'], self::VALID_STATUSES)) {
                throw new InvalidArgumentException('Invalid status specified');
            }

            // Create expense record
            $expense = PocketExpense::create($expenseData);

            // Create metadata records if provided
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $this->createExpenseMetadata($expense->id, $data['metadata']);
            }

            DB::commit();

            return $expense->fresh(['expenseType', 'metadata', 'user', 'client']);
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Failed to create expense: ' . $e->getMessage());
        }
    }

    /**
     * Update an existing pocket expense.
     *
     * @param int $expenseId
     * @param array<string, mixed> $data
     * @param int $userId
     * @return PocketExpense
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws Exception
     */
    public function updateExpense(int $expenseId, array $data, int $userId): PocketExpense
    {
        // Validate input parameters
        if ($expenseId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Expense ID and User ID must be positive integers');
        }

        // Find expense
        $expense = PocketExpense::where('id', $expenseId)
                                ->where('deleted', false)
                                ->first();

        if (!$expense) {
            throw new ModelNotFoundException('Expense not found or has been deleted');
        }

        // Check if expense can be updated (not approved or rejected)
        if (in_array($expense->status, ['approved', 'rejected'])) {
            throw new InvalidArgumentException('Cannot update expense with status: ' . $expense->status);
        }

        // Validate date if provided
        if (isset($data['date'])) {
            $expenseDate = Carbon::parse($data['date']);
            $minDate = Carbon::now()->subYears(self::MAX_DATE_LOOKBACK_YEARS);
            if ($expenseDate->lt($minDate)) {
                throw new InvalidArgumentException('Expense date cannot be older than ' . self::MAX_DATE_LOOKBACK_YEARS . ' years');
            }
            if ($expenseDate->gt(Carbon::now())) {
                throw new InvalidArgumentException('Expense date cannot be in the future');
            }
        }

        // Validate expense type if provided
        if (isset($data['expense_type'])) {
            $expenseType = OptPocketExpenseType::find($data['expense_type']);
            if (!$expenseType) {
                throw new InvalidArgumentException('Invalid expense type specified');
            }
        }

        // Validate status transition if provided
        if (isset($data['status'])) {
            if (!in_array($data['status'], self::VALID_STATUSES)) {
                throw new InvalidArgumentException('Invalid status specified');
            }
            if (!$this->isValidStatusTransition($expense->status, $data['status'])) {
                throw new InvalidArgumentException("Invalid status transition from {$expense->status} to {$data['status']}");
            }
        }

        try {
            DB::beginTransaction();

            // Prepare update data
            $updateData = [];
            
            $allowedFields = [
                'date', 'merchant_name