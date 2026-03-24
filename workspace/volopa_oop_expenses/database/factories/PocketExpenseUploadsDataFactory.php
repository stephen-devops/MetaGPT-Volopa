<?php

namespace Database\Factories;

use App\Models\PocketExpenseUploadsData;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for PocketExpenseUploadsData model
 * 
 * Generates test data for pocket expense CSV upload row data with proper relationships
 * to upload batches and created expense records. Handles all processing statuses
 * and realistic CSV row data scenarios including validation errors.
 */
class PocketExpenseUploadsDataFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PocketExpenseUploadsData::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = Carbon::now();
        $lineNumber = $this->faker->numberBetween(2, 200); // CSV lines start from 2 (after header)
        
        // Generate realistic expense data that would come from CSV
        $expenseData = [
            'date' => $this->faker->dateTimeBetween('-2 years', 'now')->format('d/m/Y'), // DD/MM/YYYY format from CSV
            'merchant_name' => $this->faker->company(),
            'merchant_description' => $this->faker->optional(0.7)->sentence(8),
            'expense_type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant']),
            'currency' => $this->faker->randomElement(['GBP', 'EUR', 'USD']),
            'amount' => $this->faker->randomFloat(2, 5.00, 500.00),
            'merchant_address' => $this->faker->optional(0.6)->address(),
            'vat_percentage' => $this->faker->optional(0.4)->numberBetween(0, 20) . '%', // With % sign as from CSV
            'notes' => $this->faker->optional(0.5)->sentence(12),
            'source' => $this->faker->randomElement(['Cash', 'Corporate Card', 'Personal Card', 'Other']),
            'source_note' => null, // Will be set if source is 'Other'
            'category' => $this->faker->optional(0.8)->randomElement(['Business Travel', 'Office Supplies', 'Client Entertainment', 'Training']),
            'tracking_code_1' => $this->faker->optional(0.6)->randomElement(['DEPT-001', 'DEPT-002', 'DEPT-003']),
            'tracking_code_2' => $this->faker->optional(0.4)->randomElement(['CC-001', 'CC-002', 'CC-003']),
            'project' => $this->faker->optional(0.3)->randomElement(['Project Alpha', 'Project Beta', 'Project Gamma']),
        ];
        
        // Add source note if source is 'Other'
        if ($expenseData['source'] === 'Other') {
            $expenseData['source_note'] = $this->faker->sentence(6);
        }
        
        return [
            // Foreign key to parent upload batch - default to ID 1, override in tests
            'upload_id' => 1,
            
            // CSV row tracking
            'line_number' => $lineNumber,
            
            // Processing status - default to pending
            'status' => 'pending',
            
            // Raw expense data from CSV row as JSON
            'expense_data' => json_encode($expenseData),
            
            // Processing error details - null for successful rows
            'processing_errors' => null,
            
            // Reference to created expense - null until processed
            'created_expense_id' => null,
            
            // Laravel standard timestamps
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Configure the factory for pending status rows.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'pending',
                'processing_errors' => null,
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for processing status rows.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'processing',
                'processing_errors' => null,
                'created_expense_id' => null,
                'updated_at' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory for completed status rows.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'completed',
                'processing_errors' => null,
                'created_expense_id' => 1, // Default expense ID, override in tests
                'updated_at' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory for failed status rows.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            $processingErrors = [
                [
                    'field' => 'currency',
                    'error' => 'Invalid currency code',
                    'provided_value' => 'INVALID',
                    'description' => 'Currency code must be 3-letter ISO format'
                ],
                [
                    'field' => 'amount',
                    'error' => 'Invalid amount format',
                    'provided_value' => 'abc.def',
                    'description' => 'Amount must be a valid decimal number'
                ]
            ];
            
            return [
                'status' => 'failed',
                'processing_errors' => json_encode($processingErrors),
                'created_expense_id' => null,
                'updated_at' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory with a specific upload ID.
     *
     * @param int $uploadId
     * @return static
     */
    public function forUpload(int $uploadId): static
    {
        return $this->state(function (array $attributes) use ($uploadId) {
            return [
                'upload_id' => $uploadId,
            ];
        });
    }

    /**
     * Configure the factory with a specific line number.
     *
     * @param int $lineNumber
     * @return static
     */
    public function withLineNumber(int $lineNumber): static
    {
        return $this->state(function (array $attributes) use ($lineNumber) {
            return [
                'line_number' => $lineNumber,
            ];
        });
    }

    /**
     * Configure the factory with a specific status.
     *
     * @param string $status
     * @return static
     */
    public function withStatus(string $status): static
    {
        return $this->state(function (array $attributes) use ($status) {
            return [
                'status' => $status,
            ];
        });
    }

    /**
     * Configure the factory with specific expense data.
     *
     * @param array $expenseData
     * @return static
     */
    public function withExpenseData(array $expenseData): static
    {
        return $this->state(function (array $attributes) use ($expenseData) {
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with specific processing errors.
     *
     * @param array $errors
     * @return static
     */
    public function withErrors(array $errors): static
    {
        return $this->state(function (array $attributes) use ($errors) {
            return [
                'status' => 'failed',
                'processing_errors' => json_encode($errors),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a created expense ID.
     *
     * @param int $expenseId
     * @return static
     */
    public function withCreatedExpense(int $expenseId): static
    {
        return $this->state(function (array $attributes) use ($expenseId) {
            return [
                'status' => 'completed',
                'processing_errors' => null,
                'created_expense_id' => $expenseId,
            ];
        });
    }

    /**
     * Configure the factory for ATM Withdrawal expense type.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['expense_type'] = 'ATM Withdrawal';
            $expenseData['merchant_name'] = $this->faker->randomElement([
                'ATM - Barclays Bank',
                'ATM - HSBC',
                'ATM - Santander',
                'ATM - Nationwide'
            ]);
            $expenseData['merchant_description'] = 'Cash withdrawal';
            $expenseData['vat_percentage'] = null; // ATM withdrawals don't have VAT
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory for Point of Sale expense type.
     *
     * @return static
     */
    public function pointOfSale(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['expense_type'] = 'Point of Sale';
            $expenseData['merchant_name'] = $this->faker->company();
            $expenseData['merchant_description'] = $this->faker->randomElement([
                'Business meeting lunch',
                'Office supplies purchase',
                'Client entertainment',
                'Travel expenses'
            ]);
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory for Fee & Charges expense type.
     *
     * @return static
     */
    public function feeAndCharges(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['expense_type'] = 'Fee & Charges';
            $expenseData['merchant_name'] = $this->faker->randomElement([
                'Foreign Exchange Fee',
                'Card Processing Fee',
                'Transaction Charge',
                'Service Fee'
            ]);
            $expenseData['merchant_description'] = 'Bank or card processing fee';
            $expenseData['vat_percentage'] = null; // Fees typically don't have VAT
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory for Refund from Merchant expense type.
     *
     * @return static
     */
    public function refundFromMerchant(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['expense_type'] = 'Refund from Merchant';
            $expenseData['merchant_name'] = $this->faker->company();
            $expenseData['merchant_description'] = $this->faker->randomElement([
                'Cancelled order refund',
                'Product return refund',
                'Overcharge adjustment',
                'Service cancellation refund'
            ]);
            $expenseData['amount'] = $this->faker->randomFloat(2, 5.00, 200.00); // Smaller refund amounts
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with 'Other' expense source and required note.
     *
     * @param string $sourceNote
     * @return static
     */
    public function withOtherSource(string $sourceNote): static
    {
        return $this->state(function (array $attributes) use ($sourceNote) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['source'] = 'Other';
            $expenseData['source_note'] = $sourceNote;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with specific currency.
     *
     * @param string $currency
     * @return static
     */
    public function withCurrency(string $currency): static
    {
        return $this->state(function (array $attributes) use ($currency) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['currency'] = strtoupper($currency);
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with specific amount.
     *
     * @param float $amount
     * @return static
     */
    public function withAmount(float $amount): static
    {
        return $this->state(function (array $attributes) use ($amount) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['amount'] = $amount;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with specific date.
     *
     * @param string|\DateTimeInterface $date
     * @return static
     */
    public function withDate($date): static
    {
        return $this->state(function (array $attributes) use ($date) {
            $dateObj = $date instanceof \DateTimeInterface ? $date : Carbon::parse($date);
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['date'] = $dateObj->format('d/m/Y'); // CSV format DD/MM/YYYY
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with specific merchant name.
     *
     * @param string $merchantName
     * @return static
     */
    public function withMerchant(string $merchantName): static
    {
        return $this->state(function (array $attributes) use ($merchantName) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['merchant_name'] = $merchantName;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with VAT percentage.
     *
     * @param int $vatPercentage
     * @return static
     */
    public function withVat(int $vatPercentage): static
    {
        return $this->state(function (array $attributes) use ($vatPercentage) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['vat_percentage'] = $vatPercentage . '%'; // CSV format with % sign
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory without VAT.
     *
     * @return static
     */
    public function withoutVat(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['vat_percentage'] = null;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Configure the factory with validation error scenario.
     *
     * @param string $field
     * @param string $error
     * @param mixed $providedValue
     * @return static
     */
    public function withValidationError(string $field, string $error, $providedValue): static
    {
        return $this->state(function (array $attributes) use ($field, $error, $providedValue) {
            $validationError = [
                'line_number' => $attributes['line_number'],
                'field' => $field,
                'error' => $error,
                'provided_value' => $providedValue,
                'description' => "Validation failed for field '{$field}'"
            ];
            
            return [
                'status' => 'failed',
                'processing_errors' => json_encode([$validationError]),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for old expense dates (older than 3 years - should fail validation).
     *
     * @return static
     */
    public function withOldDate(): static
    {
        return $this->state(function (array $attributes) {
            $oldDate = Carbon::now()->subYears(4)->format('d/m/Y');
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['date'] = $oldDate;
            
            $validationError = [
                'line_number' => $attributes['line_number'],
                'field' => 'date',
                'error' => 'Date is older than 3 years',
                'provided_value' => $oldDate,
                'description' => 'Date must not be older than 3 years from today'
            ];
            
            return [
                'expense_data' => json_encode($expenseData),
                'status' => 'failed',
                'processing_errors' => json_encode([$validationError]),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for invalid currency code scenario.
     *
     * @return static
     */
    public function withInvalidCurrency(): static
    {
        return $this->state(function (array $attributes) {
            $invalidCurrency = 'INVALID';
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['currency'] = $invalidCurrency;
            
            $validationError = [
                'line_number' => $attributes['line_number'],
                'field' => 'currency',
                'error' => 'Invalid currency code format',
                'provided_value' => $invalidCurrency,
                'description' => 'Currency code must be 3-letter ISO format (e.g., GBP, EUR, USD)'
            ];
            
            return [
                'expense_data' => json_encode($expenseData),
                'status' => 'failed',
                'processing_errors' => json_encode([$validationError]),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for invalid amount format scenario.
     *
     * @return static
     */
    public function withInvalidAmount(): static
    {
        return $this->state(function (array $attributes) {
            $invalidAmount = 'abc.def';
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['amount'] = $invalidAmount;
            
            $validationError = [
                'line_number' => $attributes['line_number'],
                'field' => 'amount',
                'error' => 'Invalid amount format',
                'provided_value' => $invalidAmount,
                'description' => 'Amount must be a valid decimal number'
            ];
            
            return [
                'expense_data' => json_encode($expenseData),
                'status' => 'failed',
                'processing_errors' => json_encode([$validationError]),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for merchant name too long scenario.
     *
     * @return static
     */
    public function withLongMerchantName(): static
    {
        return $this->state(function (array $attributes) {
            $longMerchantName = str_repeat('A', 200); // Exceeds VARCHAR(180) limit
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['merchant_name'] = $longMerchantName;
            
            $validationError = [
                'line_number' => $attributes['line_number'],
                'field' => 'merchant_name',
                'error' => 'Merchant name exceeds maximum length',
                'provided_value' => substr($longMerchantName, 0, 50) . '...',
                'description' => 'Merchant name must not exceed 180 characters'
            ];
            
            return [
                'expense_data' => json_encode($expenseData),
                'status' => 'failed',
                'processing_errors' => json_encode([$validationError]),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for missing source note when source is 'Other'.
     *
     * @return static
     */
    public function withMissingSourceNote(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['source'] = 'Other';
            $expenseData['source_note'] = null;
            
            $validationError = [
                'line_number' => $attributes['line_number'],
                'field' => 'source_note',
                'error' => 'Source Note required when Source is Other',
                'provided_value' => null,
                'description' => 'Source Note must be provided when Source = Other'
            ];
            
            return [
                'expense_data' => json_encode($expenseData),
                'status' => 'failed',
                'processing_errors' => json_encode([$validationError]),
                'created_expense_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for sequential line numbers in a batch.
     *
     * @param int $startingLineNumber
     * @return static
     */
    public function sequentialLines(int $startingLineNumber = 2): static
    {
        return $this->sequence(
            ['line_number' => $startingLineNumber],
            ['line_number' => $startingLineNumber + 1],
            ['line_number' => $startingLineNumber + 2],
            ['line_number' => $startingLineNumber + 3],
            ['line_number' => $startingLineNumber + 4]
        );
    }

    /**
     * Configure the factory for different processing statuses as a sequence.
     *
     * @return static
     */
    public function statusSequence(): static
    {
        return $this->sequence(
            ['status' => 'pending', 'processing_errors' => null, 'created_expense_id' => null],
            ['status' => 'processing', 'processing_errors' => null, 'created_expense_id' => null],
            ['status' => 'completed', 'processing_errors' => null, 'created_expense_id' => 1],
            ['status' => 'failed', 'processing_errors' => json_encode([['field' => 'amount', 'error' => 'Invalid format']]), 'created_expense_id' => null]
        );
    }

    /**
     * Configure the factory for different expense types as a sequence.
     *
     * @return static
     */
    public function expenseTypeSequence(): static
    {
        return $this->sequence(
            ['expense_data' => json_encode(array_merge(json_decode($this->make()->expense_data, true), ['expense_type' => 'ATM Withdrawal']))],
            ['expense_data' => json_encode(array_merge(json_decode($this->make()->expense_data, true), ['expense_type' => 'Point of Sale']))],
            ['expense_data' => json_encode(array_merge(json_decode($this->make()->expense_data, true), ['expense_type' => 'Fee & Charges']))],
            ['expense_data' => json_encode(array_merge(json_decode($this->make()->expense_data, true), ['expense_type' => 'Refund from Merchant']))]
        );
    }

    /**
     * Configure the factory with a complete upload data scenario.
     * 
     * @param int $uploadId
     * @param int $lineNumber
     * @param string $status
     * @param array $expenseData
     * @return static
     */
    public function complete(int $uploadId, int $lineNumber, string $status = 'pending', array $expenseData = []): static
    {
        return $this->state(function (array $attributes) use ($uploadId, $lineNumber, $status, $expenseData) {
            $defaultExpenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $finalExpenseData = array_merge($defaultExpenseData, $expenseData);
            
            return [
                'upload_id' => $uploadId,
                'line_number' => $lineNumber,
                'status' => $status,
                'expense_data' => json_encode($finalExpenseData),
                'processing_errors' => $status === 'failed' ? json_encode([['field' => 'general', 'error' => 'Processing failed']]) : null,
                'created_expense_id' => $status === 'completed' ? 1 : null,
            ];
        });
    }

    /**
     * Configure the factory with realistic CSV batch data.
     * Creates multiple rows with sequential line numbers for testing batch processing.
     *
     * @param int $uploadId
     * @param int $numberOfRows
     * @param int $startLineNumber
     * @return static
     */
    public function batchData(int $uploadId, int $numberOfRows = 5, int $startLineNumber = 2): static
    {
        $states = [];
        for ($i = 0; $i < $numberOfRows; $i++) {
            $states[] = [
                'upload_id' => $uploadId,
                'line_number' => $startLineNumber + $i,
                'status' => 'pending',
                'processing_errors' => null,
                'created_expense_id' => null,
            ];
        }
        
        return $this->sequence(...$states);
    }

    /**
     * Configure the factory to update timestamps for testing updates.
     *
     * @return static
     */
    public function updated(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'updated_at' => Carbon::now(),
            ];
        });
    }
}