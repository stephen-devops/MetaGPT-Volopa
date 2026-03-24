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
        // Generate realistic CSV row data structure
        $expenseData = $this->generateExpenseData();

        return [
            'upload_id' => PocketExpenseFileUpload::factory(),
            'line_number' => $this->faker->numberBetween(2, 201), // Line 1 is header, max 200 data rows per constraint
            'status' => $this->faker->randomElement(['pending', 'processing', 'synced', 'failed']),
            'expense_data' => $expenseData,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Generate realistic expense data JSON structure that matches CSV column schema.
     *
     * @return array
     */
    private function generateExpenseData(): array
    {
        // Generate date within 3-year constraint (DD/MM/YYYY format as per CSV schema)
        $expenseDate = $this->faker->dateTimeBetween('-3 years', 'now');
        
        $expenseTypes = ['ATM Withdrawal', 'Point of Sale', 'Fee & Charges', 'Refund from Merchant'];
        $expenseType = $this->faker->randomElement($expenseTypes);
        
        // Determine amount sign based on expense type as per system constraints
        $isRefund = str_contains(strtolower($expenseType), 'refund');
        $amount = $isRefund 
            ? $this->faker->randomFloat(2, 10.00, 500.00)  // Positive for refunds
            : $this->faker->randomFloat(2, -500.00, -10.00); // Negative for others
        
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'SGD'];
        $currency = $this->faker->randomElement($currencies);
        
        $merchantNames = [
            'Starbucks Coffee',
            'Amazon.com',
            'Shell Gas Station', 
            'Walmart Supercenter',
            'McDonald\'s Restaurant',
            'Home Depot',
            'Target Corporation',
            'Best Buy Electronics',
            'CVS Pharmacy',
            'Uber Technologies'
        ];
        
        $expenseSources = ['Cash', 'Corporate Card', 'Personal Card', 'Other'];
        $expenseSource = $this->faker->randomElement($expenseSources);
        
        // Source Note required when Source = Other as per validation constraints
        $sourceNote = ($expenseSource === 'Other') 
            ? $this->faker->sentence(8)
            : $this->faker->optional(0.3)->sentence(6);

        return [
            'Date' => $expenseDate->format('d/m/Y'), // DD/MM/YYYY format as per CSV schema
            'Expense Type' => $expenseType,
            'Currency Code' => $currency,
            'Amount' => number_format($amount, 2, '.', ''),
            $currency . ' Equivalent Amount' => $this->faker->optional(0.4)->randomFloat(2, abs($amount) * 0.8, abs($amount) * 1.2),
            'VAT %' => $this->faker->optional(0.4)->numberBetween(0, 25) . '%', // With % sign as per CSV format
            'Merchant Name' => $this->faker->randomElement($merchantNames),
            'Description' => $this->faker->optional(0.7)->sentence(6, 12),
            'Merchant Address' => $this->faker->optional(0.5)->address,
            'Merchant Country' => $this->faker->optional(0.5)->country,
            'Source' => $expenseSource,
            'Source Note' => $sourceNote,
            'Notes' => $this->faker->optional(0.5)->paragraph(2),
        ];
    }

    /**
     * Create upload data for an existing upload.
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
     * Create upload data with a specific line number.
     *
     * @param int $lineNumber
     * @return static
     */
    public function atLine(int $lineNumber): static
    {
        return $this->state(fn (array $attributes) => [
            'line_number' => $lineNumber,
        ]);
    }

    /**
     * Create upload data with pending status.
     *
     * @return static
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'error_message' => null,
        ]);
    }

    /**
     * Create upload data with processing status.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'error_message' => null,
        ]);
    }

    /**
     * Create upload data with synced status.
     *
     * @return static
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'synced',
            'error_message' => null,
        ]);
    }

    /**
     * Create upload data with failed status and error message.
     *
     * @param string|null $errorMessage
     * @return static
     */
    public function failed(string $errorMessage = null): static
    {
        $defaultErrors = [
            'Invalid date format: expected DD/MM/YYYY',
            'Currency code not supported',
            'Amount must be numeric',
            'VAT percentage must be between 0-100',
            'Merchant name exceeds maximum length of 180 characters',
            'Expense type not found in system',
            'Source Note required when Source = Other',
            'Date is older than 3 years',
            'Invalid expense source for client',
        ];

        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => $errorMessage ?? $this->faker->randomElement($defaultErrors),
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
     * Create upload data with ATM withdrawal expense type.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        
        $expenseData = [
            'Date' => $expenseDate->format('d/m/Y'),
            'Expense Type' => 'ATM Withdrawal',
            'Currency Code' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
            'Amount' => number_format($this->faker->randomFloat(2, -500.00, -20.00), 2, '.', ''),
            'VAT %' => '', // ATM withdrawals typically don't have VAT
            'Merchant Name' => $this->faker->randomElement([
                'Bank of America ATM',
                'Chase Bank ATM', 
                'Wells Fargo ATM',
                'Citibank ATM'
            ]),
            'Description' => 'ATM Cash Withdrawal',
            'Merchant Address' => $this->faker->address,
            'Merchant Country' => $this->faker->country,
            'Source' => $this->faker->randomElement(['Cash', 'Corporate Card']),
            'Source Note' => '',
            'Notes' => $this->faker->optional(0.3)->sentence(4),
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data with refund expense type (positive amount).
     *
     * @return static
     */
    public function refund(): static
    {
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        
        $expenseData = [
            'Date' => $expenseDate->format('d/m/Y'),
            'Expense Type' => 'Refund from Merchant',
            'Currency Code' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
            'Amount' => number_format($this->faker->randomFloat(2, 10.00, 300.00), 2, '.', ''), // Positive amount
            'VAT %' => $this->faker->numberBetween(0, 20) . '%',
            'Merchant Name' => $this->faker->randomElement([
                'Amazon.com',
                'Best Buy Electronics',
                'Target Corporation',
                'Walmart Supercenter'
            ]),
            'Description' => 'Product return refund',
            'Merchant Address' => $this->faker->address,
            'Merchant Country' => $this->faker->country,
            'Source' => $this->faker->randomElement(['Personal Card', 'Corporate Card']),
            'Source Note' => $this->faker->optional(0.4)->sentence(6),
            'Notes' => 'Refund processed for returned merchandise',
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data with 'Other' source requiring source note.
     *
     * @return static
     */
    public function otherSource(): static
    {
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        
        $expenseData = [
            'Date' => $expenseDate->format('d/m/Y'),
            'Expense Type' => $this->faker->randomElement(['Point of Sale', 'Fee & Charges']),
            'Currency Code' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
            'Amount' => number_format($this->faker->randomFloat(2, -200.00, -5.00), 2, '.', ''),
            'VAT %' => $this->faker->optional(0.5)->numberBetween(0, 25) . '%',
            'Merchant Name' => $this->faker->company,
            'Description' => $this->faker->sentence(8),
            'Merchant Address' => $this->faker->address,
            'Merchant Country' => $this->faker->country,
            'Source' => 'Other',
            'Source Note' => $this->faker->sentence(10), // Required when Source = Other
            'Notes' => $this->faker->optional(0.6)->paragraph(2),
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data with validation errors (invalid data).
     *
     * @return static
     */
    public function withValidationErrors(): static
    {
        // Generate intentionally invalid data for testing validation
        $invalidData = [
            'Date' => $this->faker->randomElement(['32/13/2023', '2023-12-31', 'invalid-date']), // Invalid date formats
            'Expense Type' => 'Invalid Expense Type',
            'Currency Code' => $this->faker->randomElement(['INVALID', 'US', 'EURO']), // Invalid currency codes
            'Amount' => $this->faker->randomElement(['invalid', 'abc', '']), // Non-numeric amounts
            'VAT %' => $this->faker->randomElement(['invalid%', '150%', '-5%']), // Invalid VAT percentages
            'Merchant Name' => str_repeat('Very long merchant name ', 20), // Exceeds 180 char limit
            'Description' => $this->faker->paragraph(10),
            'Merchant Address' => $this->faker->address,
            'Merchant Country' => 'Invalid Country Name',
            'Source' => 'Invalid Source',
            'Source Note' => '', // Missing when Source = Other
            'Notes' => str_repeat('Very long notes ', 50), // Test DB limits
        ];

        $errorMessages = [
            'Date: Invalid date format, expected DD/MM/YYYY',
            'Expense Type: Unknown expense type',
            'Currency Code: Invalid currency code format',
            'Amount: Must be a valid number',
            'VAT %: Must be between 0-100',
            'Merchant Name: Exceeds maximum length of 180 characters',
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $invalidData,
            'status' => 'failed',
            'error_message' => $this->faker->randomElement($errorMessages),
        ]);
    }

    /**
     * Create upload data with minimal required fields only.
     *
     * @return static
     */
    public function minimal(): static
    {
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        
        $expenseData = [
            'Date' => $expenseDate->format('d/m/Y'),
            'Expense Type' => 'Point of Sale',
            'Currency Code' => 'USD',
            'Amount' => '-25.50',
            'Merchant Name' => 'Test Merchant',
            // Optional fields left empty
            'USD Equivalent Amount' => '',
            'VAT %' => '',
            'Description' => '',
            'Merchant Address' => '',
            'Merchant Country' => '',
            'Source' => '',
            'Source Note' => '',
            'Notes' => '',
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data with maximal field utilization.
     *
     * @return static
     */
    public function maximal(): static
    {
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        $currency = $this->faker->randomElement(['USD', 'EUR', 'GBP']);
        $amount = $this->faker->randomFloat(2, -300.00, -15.00);
        
        $expenseData = [
            'Date' => $expenseDate->format('d/m/Y'),
            'Expense Type' => 'Point of Sale',
            'Currency Code' => $currency,
            'Amount' => number_format($amount, 2, '.', ''),
            $currency . ' Equivalent Amount' => number_format(abs($amount) * 1.1, 2, '.', ''),
            'VAT %' => $this->faker->numberBetween(5, 25) . '%',
            'Merchant Name' => $this->faker->company,
            'Description' => $this->faker->sentence(12),
            'Merchant Address' => $this->faker->address,
            'Merchant Country' => $this->faker->country,
            'Source' => 'Other',
            'Source Note' => $this->faker->sentence(15),
            'Notes' => $this->faker->paragraph(3),
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data with old dates (near 3-year constraint limit).
     *
     * @return static
     */
    public function nearDateLimit(): static
    {
        // Generate date close to 3-year limit
        $expenseDate = $this->faker->dateTimeBetween('-3 years', '-2 years 11 months');
        
        $expenseData = $this->generateExpenseData();
        $expenseData['Date'] = $expenseDate->format('d/m/Y');

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data that would exceed date constraint (for testing validation).
     *
     * @return static
     */
    public function exceedDateLimit(): static
    {
        // Generate date older than 3 years (should fail validation)
        $expenseDate = $this->faker->dateTimeBetween('-5 years', '-3 years 1 day');
        
        $expenseData = $this->generateExpenseData();
        $expenseData['Date'] = $expenseDate->format('d/m/Y');

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
            'status' => 'failed',
            'error_message' => 'Date is older than 3 years from current date',
        ]);
    }

    /**
     * Create upload data with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $createdAt
     * @param \Carbon\Carbon|string|null $updatedAt
     * @return static
     */
    public function withTimestamps($createdAt = null, $updatedAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $createdAt ?? now()->subDays($this->faker->numberBetween(0, 30)),
            'updated_at' => $updatedAt ?? now()->subDays($this->faker->numberBetween(0, 10)),
        ]);
    }

    /**
     * Create multiple upload data records for the same upload with sequential line numbers.
     *
     * @param int $uploadId
     * @param int $startLineNumber
     * @param int $count
     * @return static
     */
    public function sequentialForUpload(int $uploadId, int $startLineNumber = 2, int $count = 5): static
    {
        return $this->state(function (array $attributes) use ($uploadId, &$startLineNumber) {
            return [
                'upload_id' => $uploadId,
                'line_number' => $startLineNumber++,
            ];
        });
    }

    /**
     * Create upload data for testing CSV row limits (max 200 rows per file).
     *
     * @param int $uploadId
     * @param int $lineNumber
     * @return static
     */
    public function forRowLimitTesting(int $uploadId, int $lineNumber): static
    {
        // Ensure line number is within constraint (header = 1, data rows = 2-201)
        $constrainedLineNumber = max(2, min(201, $lineNumber));
        
        return $this->state(fn (array $attributes) => [
            'upload_id' => $uploadId,
            'line_number' => $constrainedLineNumber,
        ]);
    }

    /**
     * Create upload data with various currencies for FX testing.
     *
     * @return static
     */
    public function forFxTesting(): static
    {
        $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'SGD'];
        $currency = $this->faker->randomElement($currencies);
        
        // Adjust amount based on typical currency ranges
        $amount = match ($currency) {
            'JPY' => $this->faker->randomFloat(0, -50000, -1000),
            default => $this->faker->randomFloat(2, -1000.00, -50.00),
        };
        
        $expenseDate = $this->faker->dateTimeBetween('-30 days', 'now'); // Within FX lookup range
        
        $expenseData = $this->generateExpenseData();
        $expenseData['Date'] = $expenseDate->format('d/m/Y');
        $expenseData['Currency Code'] = $currency;
        $expenseData['Amount'] = number_format($amount, 2, '.', '');

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data that represents a complete CSV batch.
     * Generates multiple records with different statuses and line numbers.
     *
     * @param int $uploadId
     * @param int $totalRows
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createBatch(int $uploadId, int $totalRows = 10): \Illuminate\Database\Eloquent\Collection
    {
        $records = collect();
        
        for ($i = 2; $i <= min($totalRows + 1, 201); $i++) { // Line 1 is header
            $status = match (true) {
                $i <= 5 => 'synced',
                $i <= 8 => 'pending', 
                $i <= 9 => 'processing',
                default => 'failed',
            };
            
            $record = $this->forUpload($uploadId)
                ->atLine($i)
                ->withStatus($status)
                ->create();
                
            $records->push($record);
        }
        
        return $records;
    }

    /**
     * Create upload data with a specific status.
     *
     * @param string $status
     * @return static
     */
    public function withStatus(string $status): static
    {
        $errorMessage = null;
        
        if ($status === 'failed') {
            $errorMessage = $this->faker->randomElement([
                'Invalid date format',
                'Currency not supported',
                'Amount validation failed',
                'Merchant name too long',
                'Missing required field',
            ]);
        }

        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Create upload data for testing edge cases.
     *
     * @return static
     */
    public function edgeCase(): static
    {
        $edgeCases = [
            // Boundary amounts
            ['amount' => '-0.01', 'description' => 'Minimum amount'],
            ['amount' => '-999999.99', 'description' => 'Maximum negative amount'],
            ['amount' => '999999.99', 'description' => 'Maximum positive amount'],
            
            // Boundary VAT percentages  
            ['vat' => '0%', 'description' => 'Zero VAT'],
            ['vat' => '100%', 'description' => 'Maximum VAT'],
            
            // Long text fields
            ['merchant' => str_repeat('A', 180), 'description' => 'Maximum merchant name length'],
            
            // Special characters
            ['merchant' => 'Café & Restaurant', 'description' => 'Special characters in merchant name'],
        ];
        
        $edgeCase = $this->faker->randomElement($edgeCases);
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        
        $expenseData = $this->generateExpenseData();
        $expenseData['Date'] = $expenseDate->format('d/m/Y');
        
        if (isset($edgeCase['amount'])) {
            $expenseData['Amount'] = $edgeCase['amount'];
        }
        if (isset($edgeCase['vat'])) {
            $expenseData['VAT %'] = $edgeCase['vat'];
        }
        if (isset($edgeCase['merchant'])) {
            $expenseData['Merchant Name'] = $edgeCase['merchant'];
        }

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
        ]);
    }

    /**
     * Create upload data for performance testing with large datasets.
     *
     * @return static
     */
    public function forPerformanceTesting(): static
    {
        // Generate simplified expense data for performance testing
        $expenseDate = $this->faker->dateTimeBetween('-1 year', 'now');
        
        $expenseData = [
            'Date' => $expenseDate->format('d/m/Y'),
            'Expense Type' => 'Point of Sale',
            'Currency Code' => 'USD',
            'Amount' => number_format($this->faker->randomFloat(2, -100.00, -5.00), 2, '.', ''),
            'VAT %' => '',
            'Merchant Name' => 'Test Merchant ' . $this->faker->numberBetween(1, 1000),
            'Description' => 'Performance test expense',
            'Merchant Address' => '',
            'Merchant Country' => '',
            'Source' => 'Cash',
            'Source Note' => '',
            'Notes' => '',
        ];

        return $this->state(fn (array $attributes) => [
            'expense_data' => $expenseData,
            'status' => 'pending',
        ]);
    }
}