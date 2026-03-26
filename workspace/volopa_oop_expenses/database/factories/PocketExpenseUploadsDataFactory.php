<?php

namespace Database\Factories;

use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpenseFileUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PocketExpenseUploadsData>
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
        $sampleExpenseData = [
            'date' => $this->faker->dateTimeBetween('-2 years', 'now')->format('d/m/Y'),
            'expense_type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant']),
            'currency_code' => $this->faker->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'amount' => $this->faker->randomFloat(2, 1.00, 5000.00),
            'currency_equivalent_amount' => $this->faker->optional(0.7)->randomFloat(2, 1.00, 5000.00),
            'vat_percent' => $this->faker->optional(0.5)->randomFloat(1, 0, 100),
            'merchant_name' => $this->faker->randomElement([
                'Amazon', 'Walmart', 'McDonald\'s', 'Starbucks', 'Shell', 'BP',
                'Target', 'Best Buy', 'Home Depot', 'CVS Pharmacy'
            ]),
            'description' => $this->faker->optional(0.6)->sentence(4),
            'merchant_address' => $this->faker->optional(0.5)->address(),
            'merchant_country' => $this->faker->optional(0.6)->country(),
            'source' => $this->faker->randomElement(['Cash', 'Corporate Card', 'Personal Card', 'Other']),
            'source_note' => $this->faker->optional(0.3)->sentence(3),
            'notes' => $this->faker->optional(0.4)->sentence(8),
        ];

        return [
            'upload_id' => function () {
                return PocketExpenseFileUpload::factory()->create()->id;
            },
            'line_number' => $this->faker->numberBetween(2, 201), // CSV line including header (starts from 2)
            'status' => $this->faker->randomElement(['pending', 'processed', 'failed']),
            'expense_data' => json_encode($sampleExpenseData),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the upload data is pending processing.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function pending(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'pending',
            ];
        });
    }

    /**
     * Indicate that the upload data is processed.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function processed(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'processed',
            ];
        });
    }

    /**
     * Indicate that the upload data processing failed.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function failed(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'failed',
            ];
        });
    }

    /**
     * Create upload data for specific upload.
     *
     * @param int $uploadId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forUpload(int $uploadId): Factory
    {
        return $this->state(function (array $attributes) use ($uploadId) {
            return [
                'upload_id' => $uploadId,
            ];
        });
    }

    /**
     * Create upload data with specific line number.
     *
     * @param int $lineNumber
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withLineNumber(int $lineNumber): Factory
    {
        return $this->state(function (array $attributes) use ($lineNumber) {
            return [
                'line_number' => $lineNumber,
            ];
        });
    }

    /**
     * Create upload data with specific expense data.
     *
     * @param array $expenseData
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withExpenseData(array $expenseData): Factory
    {
        return $this->state(function (array $attributes) use ($expenseData) {
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with valid CSV structure as per system constraints.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function validCSVData(): Factory
    {
        return $this->state(function (array $attributes) {
            $validExpenseData = [
                'date' => $this->faker->dateTimeBetween('-2 years', 'now')->format('d/m/Y'), // DD/MM/YYYY format
                'expense_type' => 'Point of Sale', // Valid expense type
                'currency_code' => 'USD', // 3-letter ISO currency
                'amount' => $this->faker->randomFloat(2, 10.00, 1000.00), // Valid amount
                'currency_equivalent_amount' => null, // Optional field
                'vat_percent' => $this->faker->numberBetween(0, 25), // Valid VAT percentage without % sign
                'merchant_name' => substr($this->faker->company(), 0, 180), // Within VARCHAR(180) limit
                'description' => $this->faker->optional(0.7)->sentence(4),
                'merchant_address' => $this->faker->optional(0.6)->address(),
                'merchant_country' => $this->faker->optional(0.6)->countryCode(),
                'source' => 'Corporate Card', // Valid source
                'source_note' => null, // Not required unless source is "Other"
                'notes' => $this->faker->optional(0.5)->text(200), // Within reasonable limit
            ];

            return [
                'expense_data' => json_encode($validExpenseData),
                'status' => 'pending',
            ];
        });
    }

    /**
     * Create upload data with invalid CSV structure for testing validation.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function invalidCSVData(): Factory
    {
        return $this->state(function (array $attributes) {
            $invalidExpenseData = [
                'date' => '2020-01-01', // Invalid format and older than 3 years
                'expense_type' => 'Invalid Type', // Invalid expense type
                'currency_code' => 'INVALID', // Invalid currency code
                'amount' => 'not_a_number', // Invalid amount
                'currency_equivalent_amount' => null,
                'vat_percent' => '150%', // Invalid VAT (over 100% and with % sign)
                'merchant_name' => str_repeat('A', 200), // Exceeds VARCHAR(180) limit
                'description' => $this->faker->sentence(4),
                'merchant_address' => $this->faker->address(),
                'merchant_country' => 'Invalid Country',
                'source' => 'Invalid Source', // Invalid source
                'source_note' => null,
                'notes' => $this->faker->text(500),
            ];

            return [
                'expense_data' => json_encode($invalidExpenseData),
                'status' => 'failed',
            ];
        });
    }

    /**
     * Create upload data where source is "Other" (requires source note).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withSourceOther(): Factory
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['source'] = 'Other';
            $expenseData['source_note'] = $this->faker->sentence(5); // Required when source = Other

            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with refund expense type (positive amount).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function refundExpense(): Factory
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['expense_type'] = 'Refund from Merchant';
            $expenseData['amount'] = abs($this->faker->randomFloat(2, 10.00, 1000.00)); // Positive for refund

            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with negative expense type (negative amount).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function negativeExpense(): Factory
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['expense_type'] = $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges']);
            $expenseData['amount'] = -abs($this->faker->randomFloat(2, 10.00, 1000.00)); // Negative for expense

            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data within date constraint (not older than 3 years).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function recentDate(): Factory
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'] ?? '{}', true);
            $expenseData['date'] = $this->faker->dateTimeBetween('-3 years', 'now')->format('d/m/Y');

            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with specific status and upload ID.
     *
     * @param int $uploadId
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forUploadWithStatus(int $uploadId, string $status): Factory
    {
        return $this->state(function (array $attributes) use ($uploadId, $status) {
            return [
                'upload_id' => $uploadId,
                'status' => $status,
            ];
        });
    }

    /**
     * Create upload data simulating a CSV row sequence.
     *
     * @param int $uploadId
     * @param int $startLine
     * @param int $count
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function csvSequence(int $uploadId, int $startLine = 2, int $count = 1): Factory
    {
        return $this->state(function (array $attributes) use ($uploadId, $startLine, $count) {
            static $sequenceCounter = 0;
            
            return [
                'upload_id' => $uploadId,
                'line_number' => $startLine + ($sequenceCounter++ % $count),
            ];
        });
    }
}