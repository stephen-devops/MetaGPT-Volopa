## Code: app/Services/PocketExpenseService.php

```php
<?php

namespace App\Services;

use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

/**
 * PocketExpenseService
 * 
 * Main business logic service for pocket expense CRUD operations.
 * Handles expense creation, updates, deletion, and CSV processing.
 * Integrates with FXConversionService for currency conversion.
 * Uses DB transactions for data integrity.
 */
class PocketExpenseService
{
    /**
     * The FX conversion service instance.
     *
     * @var FXConversionService
     */
    private FXConversionService $fxService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Maximum pagination size.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Batch size for CSV processing.
     */
    private const CSV_BATCH_SIZE = 100;

    /**
     * Create a new pocket expense service instance.
     *
     * @param FXConversionService $fxService
     */
    public function __construct(FXConversionService $fxService)
    {
        $this->fxService = $fxService;
    }

    /**
     * Create a new pocket expense.
     *
     * @param array<string, mixed> $data
     * @param int $userId
     * @param int $clientId
     * @return PocketExpense
     * @throws Exception
     */
    public function create(array $data, int $userId, int $clientId): PocketExpense
    {
        DB::beginTransaction();

        try {
            // Get expense type for amount sign calculation
            $expenseType = OptPocketExpenseType::find($data['expense_type']);
            if (!$expenseType) {
                throw new Exception('Invalid expense type');
            }

            // Apply expense type sign to amount
            $amount = $expenseType->applySign(abs((float) $data['amount']));

            // Handle user converted amount if not provided
            $userConvertedAmount = null;
            if (isset($data['user_converted_amount'])) {
                $userConvertedAmount = $expenseType->applySign(abs((float) $data['user_converted_amount']));
            } elseif ($this->shouldConvertCurrency($data['currency'], $clientId)) {
                // Attempt FX conversion
                $conversion = $this->fxService->convertAmount(
                    abs((float) $data['amount']),
                    $data['currency'],
                    $this->getClientBaseCurrency($clientId),
                    Carbon::parse($data['date']),
                    $clientId
                );

                if ($conversion->isSuccessful()) {
                    $userConvertedAmount = $expenseType->applySign($conversion->convertedAmount);
                }
            }

            // Create the expense record
            $expenseData = [
                'user_id' => $userId,
                'client_id' => $clientId,
                'date' => Carbon::parse($data['date']),
                'merchant_name' => trim($data['merchant_name']),
                'merchant_description' => isset($data['merchant_description']) ? trim($data['merchant_description']) : null,
                'expense_type' => $data['expense_type'],
                'currency' => strtoupper($data['currency']),
                'amount' => $amount,
                'merchant_address' => isset($data['merchant_address']) ? trim($data['merchant_address']) : null,
                'merchant_country' => isset($data['merchant_country']) ? strtoupper($data['merchant_country']) : null,
                'vat_amount' => isset($data['vat_amount']) ? (float) $data['vat_amount'] : null,
                'user_converted_amount' => $userConvertedAmount,
                'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                'status' => $data['status'] ?? PocketExpense::STATUS_DRAFT,
                'created_by_user_id' => auth()->id() ?? $userId,
                'updated_by_user_id' => null,
                'approved_by_user_id' => null,
                'approved_at' => null,
            ];

            $expense = PocketExpense::create($expenseData);

            // Create metadata records
            $this->createExpenseMetadata($expense, $data);

            DB::commit();

            Log::info('Pocket expense created', [
                'expense_id' => $expense->id,
                'user_id' => $userId,
                'client_id' => $clientId,
                'amount' => $amount,
                'currency' => $data['currency'],
                'status' => $expense->status,
            ]);

            return $expense->fresh(['expenseType', 'metadata', 'user', 'client']);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to create pocket expense', [
                'user_id' => $userId,
                'client_id' => $clientId,
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Update an existing pocket expense.
     *
     * @param PocketExpense $expense
     * @param array<string, mixed> $data
     * @return PocketExpense
     * @throws Exception
     */
    public function update(PocketExpense $expense, array $data): PocketExpense
    {
        // Check if expense can be updated
        if (!$this->canUpdateExpense($expense)) {
            throw new Exception('Cannot update expense in current status');
        }

        DB::beginTransaction();

        try {
            $updateData = [];
            $needsMetadataUpdate = false;

            // Handle amount and expense type changes
            if (isset($data['amount']) || isset($data['expense_type'])) {
                $expenseTypeId = $data['expense_type'] ?? $expense->expense_type;
                $expenseType = OptPocketExpenseType::find($expenseTypeId);
                
                if (!$expenseType) {
                    throw new Exception('Invalid expense type');
                }

                if (isset($data['amount'])) {
                    $updateData['amount'] = $expenseType->applySign(abs((float) $data['amount']));
                }

                if (isset($data['expense_type'])) {
                    $updateData['expense_type'] = $expenseTypeId;
                    // Recalculate amount with new expense type sign
                    $currentAmount = isset($data['amount']) ? abs((float) $data['amount']) : abs($expense->amount);
                    $updateData['amount'] = $expenseType->applySign($currentAmount);
                }
            }

            // Handle other field updates
            $updateableFields = [
                'date' => 'date',
                'merchant_name' => 'string',
                'merchant_description' => 'string',
                'currency' => 'upper',
                'merchant_address' => 'string',
                'merchant_country' => 'upper',
                'vat_amount' => 'float',
                'user_converted_amount' => 'float',
                'notes' => 'string',
                'status' => 'string',
            ];

            foreach ($updateableFields as $field => $type) {
                if (isset($data[$field])) {
                    switch ($type) {
                        case 'date':
                            $updateData[$field] = Carbon::parse($data[$field]);
                            break;
                        case 'string':
                            $updateData[$field] = $data[$field] ? trim($data[$field]) : null;
                            break;
                        case 'upper':
                            $updateData[$field] = $data[$field] ? strtoupper(trim($data[$field])) : null;
                            break;
                        case 'float':
                            $updateData[$field] = $data[$field] !== null ? (float) $data[$field] : null;
                            break;
                        default:
                            $updateData[$field] = $data[$field];
                    }
                }
            }

            // Handle user converted amount with FX conversion
            if (isset($data['user_converted_amount']) && $data['user_converted_amount'] === null) {
                // User wants to use automatic conversion
                $currency = $updateData['currency'] ?? $expense->currency;
                $amount = isset($updateData['amount']) ? abs($updateData['amount']) : abs($expense->amount);
                $date = isset($updateData['date']) ? $updateData['date'] : $expense->date;

                if ($this->shouldConvertCurrency($currency, $expense->client_id)) {
                    $conversion = $this->fxService->convertAmount(
                        $amount,
                        $currency,
                        $this->getClientBaseCurrency($expense->client_id),
                        $date,
                        $expense->client_id
                    );

                    if ($conversion->isSuccessful()) {
                        $expenseType = OptPocketExpenseType::find($updateData['expense_type'] ?? $expense->expense_type);
                        $updateData['user_converted_amount'] = $expenseType->applySign($conversion->convertedAmount);
                    }
                }
            }

            // Set updated by user
            $updateData['updated_by_user_id'] = auth()->id();

            // Update the expense
            $expense->update($updateData);

            // Update metadata if needed
            if ($this->hasMetadataChanges($data)) {
                $this->updateExpenseMetadata($expense, $data);
                $needsMetadataUpdate = true;
            }

            DB::commit();

            Log::info('Pocket expense updated', [
                'expense_id' => $expense->id,
                'updated_fields' => array_keys($updateData),
                'metadata_updated' => $needsMetadataUpdate,
                'updated_by' => auth()->id(),
            ]);

            return $expense->fresh(['expenseType', 'metadata', 'user', 'client']);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to update pocket expense', [
                'expense_id' => $expense->id,
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a pocket expense (soft delete).
     *
     * @param PocketExpense $expense
     * @return bool
     * @throws Exception
     */
    public function delete(PocketExpense $expense): bool
    {
        // Check if expense can be deleted
        if (!$this->canDeleteExpense($expense)) {
            throw new Exception('Cannot delete expense in current status');
        }

        DB::beginTransaction();

        try {
            // Soft delete the expense
            $expense->deleted = true;
            $expense->delete_time = now();
            $expense->updated_by_user_id = auth()->id();
            $expense->save();

            // Soft delete associated metadata
            $expense->metadata()->update([
                'deleted' => true,
                'delete_time' => now(),
            ]);

            DB::commit();

            Log::info('Pocket expense deleted', [
                'expense_id' => $expense->id,
                'deleted_by' => auth()->id(),
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete pocket expense', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get paginated list of expenses with filters.
     *
     * @param int $userId
     * @param int $clientId
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator
     */
    public function list(int $userId, int $clientId, array $filters = []): LengthAwarePaginator
    {
        $query = PocketExpense::active()
            ->forUser($userId)
            ->forClient($clientId)
            ->with(['expenseType', 'user', 'creator', 'approver', 'metadata.expenseSource']);

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'create_time';
        $sortOrder = $filters['sort_order'] ?? 'desc';
        
        $allowedSortFields = [
            'create_time', 'date', 'amount', 'merchant_name', 'currency', 'status'
        ];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('create_time', 'desc');
        }

        // Get pagination parameters
        $perPage = $this->getPerPageValue($filters['per_page'] ?? null);

        return $query->paginate($perPage);
    }

    /**
     * Process validated CSV data and create expenses.
     *
     * @param array<array<string, mixed>> $validatedRows
     * @param int $uploadId
     * @return bool
     * @throws Exception
     */
    public function processCSVData(array $validatedRows, int $uploadId): bool
    {
        $upload = PocketExpenseFileUpload::findOrFail($uploadId);
        $processed = 0;
        $failed = 0;

        // Process in batches
        $batches = array_chunk($validatedRows, self::CSV_BATCH_SIZE);

        foreach ($batches as $batch) {
            DB::beginTransaction();

            try {
                $batchResults = $this->processBatch($batch, $upload);
                $processed += $batchResults['processed'];
                $failed += $batchResults['failed'];

                DB::commit();

            } catch (Exception $e) {
                DB::rollBack();

                Log::error('CSV batch processing failed', [
                    'upload_id' => $uploadId,
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                ]);

                // Mark all rows in this batch as failed
                foreach ($batch as $row) {
                    PocketExpenseUploadData::where('upload_id', $uploadId)
                        ->where('line_number', $row['line_number'])
                        ->update([
                            'status' => PocketExpenseUploadData::STATUS_FAILED,
                            'processing_errors' => [[
                                'error' => 'Batch processing failed',
                                'message' => $e->getMessage(),
                            ]],
                            'failed_at' => now(),
                        ]);
                }

                $failed += count($batch);
            }
        }

        // Update upload statistics
        $upload->processed_records = $processed;
        $upload->failed_records = $failed;

        if ($failed === 0) {
            $upload->status = PocketExpenseFileUpload::STATUS_COMPLETED;
            $upload->completed_at = now();
        } else {
            $upload->status = $failed < count($validatedRows) 
                ? PocketExpenseFileUpload::STATUS_COMPLETED 
                : PocketExpenseFileUpload::STATUS_SYNC_FAILED;
            
            if ($upload->status === PocketExpenseFileUpload::STATUS_SYNC_FAILED) {
                $upload->