## Code: app/Services/PocketExpenseService.php

```php
<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\User;
use App\Models\Client;
use App\Services\FXConversionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use InvalidArgumentException;

/**
 * PocketExpenseService
 * 
 * Service class for managing pocket expense operations with business logic.
 * Handles expense CRUD operations, metadata management, approval workflow,
 * and FX conversion integration with multi-tenant support.
 * 
 * Business Rules:
 * - All expenses must be scoped to client_id for multi-tenancy
 * - Amount sign determined by expense type: Refund = positive, others = negative
 * - Backend must recalculate FX on save, do not trust frontend-only values
 * - Date validation: expenses cannot be older than 3 years from current date
 * - All expense metadata stored in pocket_expense_metadata with enum metadata_type
 * - Approval workflow: draft -> submitted -> approved/rejected
 * - Soft delete pattern for expense sources (excluded from dropdowns but visible on historical records)
 */
class PocketExpenseService
{
    /**
     * FX conversion service instance.
     *
     * @var FXConversionService
     */
    private FXConversionService $fxService;

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    private const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Maximum age in years for expense date validation.
     *
     * @var int
     */
    private const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Default status for new expenses.
     *
     * @var string
     */
    private const DEFAULT_STATUS = 'draft';

    /**
     * Valid status values for expenses.
     *
     * @var array<int, string>
     */
    private const VALID_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    /**
     * Statuses that allow updates.
     *
     * @var array<int, string>
     */
    private const UPDATABLE_STATUSES = ['draft', 'submitted'];

    /**
     * Statuses that allow deletion.
     *
     * @var array<int, string>
     */
    private const DELETABLE_STATUSES = ['draft', 'submitted', 'rejected'];

    /**
     * Valid metadata types.
     *
     * @var array<int, string>
     */
    private const VALID_METADATA_TYPES = [
        'category',
        'tracking_code',
        'project',
        'receipt',
        'source',
        'additional_field'
    ];

    /**
     * Create a new PocketExpenseService instance.
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
     * @param array $data Expense data
     * @param int $userId User creating the expense
     * @param int $clientId Client context for multi-tenancy
     * @return PocketExpense The created expense with relationships loaded
     * 
     * @throws InvalidArgumentException If validation fails
     * @throws Exception If expense creation fails
     */
    public function createExpense(array $data, int $userId, int $clientId): PocketExpense
    {
        // Validate input parameters
        $this->validateExpenseData($data, true);
        $this->validateUserContext($userId, $clientId);

        // Get the expense type to determine amount sign
        $expenseType = OptPocketExpenseType::find($data['expense_type']);
        if (!$expenseType) {
            throw new InvalidArgumentException('Invalid expense type specified.');
        }

        // Get the target user (for expense creation on behalf of others)
        $expenseUserId = $data['expense_user_id'] ?? $userId;
        $this->validateExpenseUser($expenseUserId, $clientId);

        try {
            DB::beginTransaction();

            // Apply amount sign based on expense type
            $amount = abs((float) $data['amount']);
            $signedAmount = $expenseType->applyAmountSign($amount);

            // Get base currency for FX conversion
            $baseCurrency = $this->fxService->getBaseCurrency($clientId);
            $expenseCurrency = strtoupper($data['currency']);
            
            // Convert amount to base currency if needed
            $convertedAmount = $signedAmount;
            $fxRate = 1.0;
            
            if ($expenseCurrency !== $baseCurrency) {
                $fxData = $this->fxService->convertAmount(
                    abs($signedAmount),
                    $expenseCurrency,
                    $baseCurrency,
                    Carbon::parse($data['date'])
                );
                
                $convertedAmount = $expenseType->applyAmountSign($fxData['converted_amount']);
                $fxRate = $fxData['fx_rate'];
            }

            // Prepare expense data
            $expenseData = [
                'uuid' => (string) Str::uuid(),
                'user_id' => $expenseUserId,
                'client_id' => $clientId,
                'date' => Carbon::parse($data['date'])->format('Y-m-d'),
                'merchant_name' => trim(substr($data['merchant_name'], 0, self::MERCHANT_NAME_MAX_LENGTH)),
                'merchant_description' => $data['merchant_description'] ? trim($data['merchant_description']) : null,
                'expense_type' => (int) $data['expense_type'],
                'currency' => $expenseCurrency,
                'amount' => $signedAmount,
                'merchant_address' => $data['merchant_address'] ? trim($data['merchant_address']) : null,
                'vat_amount' => $data['vat_amount'] ? abs((float) $data['vat_amount']) : null,
                'notes' => $data['notes'] ? trim($data['notes']) : null,
                'status' => self::DEFAULT_STATUS,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => null,
                'approved_by_user_id' => null,
                'deleted' => false,
                'delete_time' => null,
            ];

            // Create the expense record
            $expense = PocketExpense::create($expenseData);

            // Attach metadata if provided
            $this->attachAllMetadata($expense, $data, $userId);

            // Store FX conversion metadata if conversion was applied
            if ($expenseCurrency !== $baseCurrency) {
                $this->attachMetadata($expense, 'additional_field', [
                    'fx_conversion' => [
                        'original_currency' => $expenseCurrency,
                        'base_currency' => $baseCurrency,
                        'fx_rate' => $fxRate,
                        'converted_amount' => $convertedAmount,
                        'conversion_date' => now()->toISOString(),
                    ]
                ], $userId);
            }

            DB::commit();

            Log::info('Pocket expense created', [
                'expense_id' => $expense->id,
                'expense_uuid' => $expense->uuid,
                'user_id' => $expenseUserId,
                'client_id' => $clientId,
                'created_by' => $userId,
                'amount' => $signedAmount,
                'currency' => $expenseCurrency,
                'converted_amount' => $convertedAmount,
                'fx