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
        return [
            'upload_id' => PocketExpenseFileUpload::factory(),
            'line_number' => $this->faker->numberBetween(2, 201), // Line 1 is header, max 200 rows per constraint
            'status' => $this->faker->randomElement(['pending', 'processing', 'synced', 'failed']),
            'expense_data' => $this->generateExpenseData(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Generate sample expense data JSON that matches CSV column schema.
     *
     * @return array
     */
    private function generateExpenseData(): array
    {
        return [
            'Date' => $this->faker->dateTimeBetween('-3 years', 'now')->format('d/m/Y'),
            'Expense Type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant']),
            'Currency Code' => $this->faker->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'Amount' => $this->faker->randomFloat(2, 10, 5000),
            'Currency Equivalent Amount' => $this->faker->optional(0.3)->randomFloat(2, 10, 5000),
            'VAT %' => $this->faker->optional(0.4)->numberBetween(0, 100),
            'Merchant Name' => $this->faker->company,
            'Description' => $this->faker->optional(0.7)->catchPhrase,
            'Merchant Address' => $this->faker->optional(0.6)->address,
            'Merchant Country' => $this->faker->optional(0.5)->country,
            'Source' => $this->faker->optional(0.8)->randomElement(['Cash', 'Corporate Card', 'Personal Card', 'Other']),
            'Source Note' => $this->faker->optional(0.2)->sentence,
            'Notes' => $this->faker->optional(0.5)->sentence,
        ];
    }

    /**
     * Indicate that the upload data is pending.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the upload data is processing.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
        ]);
    }

    /**
     * Indicate that the upload data is synced.
     *
     * @return static
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'synced',
        ]);
    }

    /**
     * Indicate that the upload data failed.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    /**
     * Create upload data for a specific upload.
     *
     * @param int $uploadId
     * @return static
     */
    public function forUpload(int $uploadId): static
    {
        return $this->state(fn (array $attributes) => [
            'upload_id' => $uploadId,
        ]);
    }

    /**
     * Create upload data with specific line number.
     *
     * @param int $lineNumber
     * @return static
     */
    public function withLineNumber(int $lineNumber): static
    {
        return $this->state(fn (array $attributes) => [
            'line_number' => $lineNumber,
        ]);
    }

    /**
     * Create upload data with specific expense data.
     *
     * @param array $expenseData
     * @return static
     */
    public function withExpenseData(array $expenseData): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data with ATM Withdrawal expense type.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Expense Type' => 'ATM Withdrawal',
                'Amount' => abs($attributes['expense_data']['Amount'] ?? $this->faker->randomFloat(2, 10, 5000)) * -1, // Negative for withdrawals
            ]),
        ]);
    }

    /**
     * Create upload data with Refund expense type.
     *
     * @return static
     */
    public function refund(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Expense Type' => 'Refund from Merchant',
                'Amount' => abs($attributes['expense_data']['Amount'] ?? $this->faker->randomFloat(2, 10, 5000)), // Positive for refunds
            ]),
        ]);
    }

    /**
     * Create upload data with specific currency.
     *
     * @param string $currency
     * @return static
     */
    public function withCurrency(string $currency): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Currency Code' => $currency,
            ]),
        ]);
    }

    /**
     * Create upload data with specific merchant.
     *
     * @param string $merchantName
     * @return static
     */
    public function withMerchant(string $merchantName): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Merchant Name' => $merchantName,
            ]),
        ]);
    }

    /**
     * Create upload data with VAT information.
     *
     * @param float $vatPercentage
     * @return static
     */
    public function withVat(float $vatPercentage): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'VAT %' => $vatPercentage,
            ]),
        ]);
    }

    /**
     * Create upload data with 'Other' source requiring source note.
     *
     * @return static
     */
    public function withOtherSource(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Source' => 'Other',
                'Source Note' => $this->faker->sentence,
            ]),
        ]);
    }

    /**
     * Create upload data with specific amount.
     *
     * @param float $amount
     * @return static
     */
    public function withAmount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Amount' => $amount,
            ]),
        ]);
    }

    /**
     * Create upload data with specific date.
     *
     * @param string $date Format: d/m/Y
     * @return static
     */
    public function withDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => array_merge($attributes['expense_data'] ?? $this->generateExpenseData(), [
                'Date' => $date,
            ]),
        ]);
    }

    /**
     * Create upload data with minimal required fields only.
     *
     * @return static
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => [
                'Date' => $this->faker->dateTimeBetween('-3 years', 'now')->format('d/m/Y'),
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => $this->faker->randomFloat(2, 10, 1000),
                'Merchant Name' => $this->faker->company,
                'VAT %' => '',
                'Description' => '',
                'Merchant Address' => '',
                'Merchant Country' => '',
                'Source' => '',
                'Source Note' => '',
                'Notes' => '',
            ],
        ]);
    }

    /**
     * Create upload data with all optional fields populated.
     *
     * @return static
     */
    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => [
                'Date' => $this->faker->dateTimeBetween('-3 years', 'now')->format('d/m/Y'),
                'Expense Type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant']),
                'Currency Code' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
                'Amount' => $this->faker->randomFloat(2, 10, 5000),
                'Currency Equivalent Amount' => $this->faker->randomFloat(2, 10, 5000),
                'VAT %' => $this->faker->numberBetween(5, 25),
                'Merchant Name' => $this->faker->company,
                'Description' => $this->faker->catchPhrase,
                'Merchant Address' => $this->faker->address,
                'Merchant Country' => $this->faker->country,
                'Source' => $this->faker->randomElement(['Cash', 'Corporate Card', 'Personal Card']),
                'Source Note' => $this->faker->sentence,
                'Notes' => $this->faker->sentence,
            ],
        ]);
    }

    /**
     * Create upload data with validation errors (invalid data).
     *
     * @return static
     */
    public function invalidData(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => [
                'Date' => '2021-13-45', // Invalid date
                'Expense Type' => 'Invalid Type', // Invalid expense type
                'Currency Code' => 'INVALID', // Invalid currency code
                'Amount' => 'not-a-number', // Invalid amount
                'VAT %' => '150%', // Invalid VAT percentage
                'Merchant Name' => '', // Missing required field
                'Description' => '',
                'Merchant Address' => '',
                'Merchant Country' => '',
                'Source' => 'Other',
                'Source Note' => '', // Missing when source is Other
                'Notes' => '',
            ],
        ]);
    }

    /**
     * Create sequential upload data entries for batch testing.
     *
     * @param int $uploadId
     * @param int $startLineNumber
     * @param int $count
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createSequential(int $uploadId, int $startLineNumber, int $count): \Illuminate\Database\Eloquent\Collection
    {
        $collection = collect();
        
        for ($i = 0; $i < $count; $i++) {
            $collection->push(
                $this->forUpload($uploadId)
                     ->withLineNumber($startLineNumber + $i)
                     ->pending()
                     ->create()
            );
        }
        
        return $collection;
    }
}