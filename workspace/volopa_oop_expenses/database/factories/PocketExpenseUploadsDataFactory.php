<?php

namespace Database\Factories;

use App\Models\PocketExpenseUploadsData;
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
        // Available status values from ENUM constraints
        $statusOptions = [
            'pending',
            'processing',
            'synced',
            'failed'
        ];

        // Sample expense data that would come from CSV parsing
        $sampleExpenseData = [
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('d/m/Y'),
            'expense_type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant']),
            'currency_code' => $this->faker->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'amount' => $this->faker->randomFloat(2, 5, 1000),
            'vat_percent' => $this->faker->optional()->randomFloat(1, 0, 100),
            'merchant_name' => $this->faker->company(),
            'description' => $this->faker->optional()->sentence(6),
            'merchant_address' => $this->faker->optional()->address(),
            'merchant_country' => $this->faker->optional()->countryCode(),
            'source' => $this->faker->optional()->randomElement(['Cash', 'Corporate Card', 'Personal Card', 'Other']),
            'source_note' => $this->faker->optional()->sentence(4),
            'notes' => $this->faker->optional()->text(100),
        ];

        return [
            'upload_id' => 1, // Default to upload ID 1, should be overridden in tests with actual PocketExpenseFileUpload ID
            'line_number' => $this->faker->numberBetween(2, 201), // Line numbers start from 2 (header is line 1), max 200 rows + header
            'status' => 'pending', // Default status as per ENUM constraint
            'expense_data' => json_encode($sampleExpenseData),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the upload data is being processed.
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
     * Indicate that the upload data has been synced.
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
     * Indicate that the upload data sync failed.
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
     * Set the upload for this data.
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
     * Set the line number for this data.
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
     * Set the status for this upload data.
     *
     * @param string $status
     * @return static
     */
    public function withStatus(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Set the expense data for this upload data.
     *
     * @param array $expenseData
     * @return static
     */
    public function withExpenseData(array $expenseData): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_data' => json_encode($expenseData),
        ]);
    }

    /**
     * Create upload data with ATM Withdrawal expense type.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['expense_type'] = 'ATM Withdrawal';
            $expenseData['amount'] = -1 * abs($this->faker->randomFloat(2, 20, 500)); // Negative amount for ATM withdrawal
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with Point of Sale expense type.
     *
     * @return static
     */
    public function pointOfSale(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['expense_type'] = 'Point of Sale';
            $expenseData['amount'] = -1 * abs($this->faker->randomFloat(2, 5, 200)); // Negative amount for POS transaction
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with Fee & Charges expense type.
     *
     * @return static
     */
    public function feeAndCharges(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['expense_type'] = 'Fee & Charges';
            $expenseData['amount'] = -1 * abs($this->faker->randomFloat(2, 1, 50)); // Negative amount for fees
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with Refund from Merchant expense type.
     *
     * @return static
     */
    public function refund(): static
    {
        return $this->state(function (array $attributes) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['expense_type'] = 'Refund from Merchant';
            $expenseData['amount'] = abs($this->faker->randomFloat(2, 10, 300)); // Positive amount for refund
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with specific currency.
     *
     * @param string $currency
     * @return static
     */
    public function withCurrency(string $currency): static
    {
        return $this->state(function (array $attributes) use ($currency) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['currency_code'] = strtoupper($currency);
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with VAT amount.
     *
     * @param float $vatPercent
     * @return static
     */
    public function withVat(float $vatPercent): static
    {
        return $this->state(function (array $attributes) use ($vatPercent) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['vat_percent'] = $vatPercent;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with specific merchant.
     *
     * @param string $merchantName
     * @return static
     */
    public function withMerchant(string $merchantName): static
    {
        return $this->state(function (array $attributes) use ($merchantName) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['merchant_name'] = $merchantName;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with specific date.
     *
     * @param string $date
     * @return static
     */
    public function withDate(string $date): static
    {
        return $this->state(function (array $attributes) use ($date) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['date'] = $date;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with expense source.
     *
     * @param string $source
     * @param string|null $sourceNote
     * @return static
     */
    public function withSource(string $source, string $sourceNote = null): static
    {
        return $this->state(function (array $attributes) use ($source, $sourceNote) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['source'] = $source;
            if ($sourceNote !== null || $source === 'Other') {
                $expenseData['source_note'] = $sourceNote ?: $this->faker->sentence(4);
            }
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data with notes.
     *
     * @param string $notes
     * @return static
     */
    public function withNotes(string $notes): static
    {
        return $this->state(function (array $attributes) use ($notes) {
            $expenseData = json_decode($attributes['expense_data'], true);
            $expenseData['notes'] = $notes;
            
            return [
                'expense_data' => json_encode($expenseData),
            ];
        });
    }

    /**
     * Create upload data representing header row (line 1).
     *
     * @return static
     */
    public function headerRow(): static
    {
        $headerData = [
            'date' => 'Date',
            'expense_type' => 'Expense Type',
            'currency_code' => 'Currency Code',
            'amount' => 'Amount',
            'vat_percent' => 'VAT %',
            'merchant_name' => 'Merchant Name',
            'description' => 'Description',
            'merchant_address' => 'Merchant Address',
            'merchant_country' => 'Merchant Country',
            'source' => 'Source',
            'source_note' => 'Source Note',
            'notes' => 'Notes',
        ];

        return $this->state(fn (array $attributes) => [
            'line_number' => 1,
            'expense_data' => json_encode($headerData),
        ]);
    }

    /**
     * Create upload data with minimal required fields only.
     *
     * @return static
     */
    public function minimal(): static
    {
        $minimalData = [
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('d/m/Y'),
            'expense_type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale']),
            'currency_code' => 'USD',
            'amount' => $this->faker->randomFloat(2, 5, 100),
            'merchant_name' => $this->faker->company(),
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => json_encode($minimalData),
        ]);
    }

    /**
     * Create upload data with all optional fields populated.
     *
     * @return static
     */
    public function complete(): static
    {
        $completeData = [
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('d/m/Y'),
            'expense_type' => $this->faker->randomElement(['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant']),
            'currency_code' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
            'amount' => $this->faker->randomFloat(2, 5, 1000),
            'vat_percent' => $this->faker->randomFloat(1, 0, 25),
            'merchant_name' => $this->faker->company(),
            'description' => $this->faker->sentence(6),
            'merchant_address' => $this->faker->address(),
            'merchant_country' => $this->faker->countryCode(),
            'source' => $this->faker->randomElement(['Cash', 'Corporate Card', 'Personal Card']),
            'notes' => $this->faker->text(100),
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => json_encode($completeData),
        ]);
    }

    /**
     * Create upload data with invalid data (for testing validation).
     *
     * @return static
     */
    public function invalid(): static
    {
        $invalidData = [
            'date' => '2020-01-01', // Wrong format (should be DD/MM/YYYY)
            'expense_type' => 'Invalid Type', // Not in allowed list
            'currency_code' => 'XXX', // Invalid currency
            'amount' => 'not-a-number', // Invalid amount
            'vat_percent' => '105%', // Over 100% with % sign
            'merchant_name' => '', // Empty required field
            'source' => 'Invalid Source', // Not in client sources
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => json_encode($invalidData),
            'status' => 'failed',
        ]);
    }

    /**
     * Create upload data for recent timestamp.
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => now()->subMinutes($this->faker->numberBetween(1, 30)),
            'updated_at' => now()->subMinutes($this->faker->numberBetween(1, 30)),
        ]);
    }

    /**
     * Create upload data for older timestamp.
     *
     * @return static
     */
    public function older(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => now()->subHours($this->faker->numberBetween(2, 24)),
            'updated_at' => now()->subHours($this->faker->numberBetween(2, 24)),
        ]);
    }
}