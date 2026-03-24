<?php

namespace Database\Factories;

use App\Models\PocketExpense;
use App\Models\User;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PocketExpense>
 */
class PocketExpenseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PocketExpense::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate expense date within the last 2 years (within 3-year constraint)
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d');
        
        // Common currency codes as per platform constraints
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'SGD'];
        
        // Generate merchant data
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
            'Uber Technologies',
            'Delta Air Lines',
            'Marriott Hotels',
            'Microsoft Store',
            'Apple Store',
            'Google Cloud Services'
        ];

        $merchantName = $this->faker->randomElement($merchantNames);
        
        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'date' => $expenseDate,
            'merchant_name' => $merchantName,
            'merchant_description' => $this->faker->optional(0.7)->sentence(6, 12),
            'expense_type' => OptPocketExpenseType::factory(),
            'currency' => $this->faker->randomElement($currencies),
            'amount' => $this->faker->randomFloat(2, -500.00, -10.00), // Default to negative amount (most expense types)
            'merchant_address' => $this->faker->optional(0.6)->address,
            'vat_amount' => $this->faker->optional(0.4)->randomFloat(2, 0, 25.00), // VAT % between 0-25%
            'notes' => $this->faker->optional(0.5)->paragraph(2),
            'status' => $this->faker->randomElement(['draft', 'submitted', 'approved', 'rejected']),
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
            'create_time' => now(),
            'update_time' => now(),
            'deleted' => false,
            'delete_time' => null,
        ];
    }

    /**
     * Create an expense for an existing user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $createdByUserId
     * @return static
     */
    public function forUser(int $userId, int $clientId, int $createdByUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Create an expense with a specific status.
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
     * Create a draft expense.
     *
     * @return static
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
        ]);
    }

    /**
     * Create a submitted expense.
     *
     * @return static
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'updated_by_user_id' => User::factory(),
            'approved_by_user_id' => null,
        ]);
    }

    /**
     * Create an approved expense.
     *
     * @return static
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'updated_by_user_id' => User::factory(),
            'approved_by_user_id' => User::factory(),
            'update_time' => now()->subDays($this->faker->numberBetween(1, 7)),
        ]);
    }

    /**
     * Create a rejected expense.
     *
     * @return static
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'updated_by_user_id' => User::factory(),
            'approved_by_user_id' => User::factory(),
            'update_time' => now()->subDays($this->faker->numberBetween(1, 7)),
        ]);
    }

    /**
     * Create an expense with a positive amount (for refunds).
     *
     * @return static
     */
    public function refund(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $this->faker->randomFloat(2, 10.00, 500.00),
            'expense_type' => OptPocketExpenseType::factory()->positive(),
        ]);
    }

    /**
     * Create an expense with a negative amount.
     *
     * @return static
     */
    public function charge(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $this->faker->randomFloat(2, -500.00, -10.00),
            'expense_type' => OptPocketExpenseType::factory()->negative(),
        ]);
    }

    /**
     * Create an expense with a specific currency.
     *
     * @param string $currency
     * @return static
     */
    public function withCurrency(string $currency): static
    {
        return $this->state(fn (array $attributes) => [
            'currency' => strtoupper($currency),
        ]);
    }

    /**
     * Create an expense with a specific amount.
     *
     * @param float $amount
     * @return static
     */
    public function withAmount(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    /**
     * Create an expense for a specific date.
     *
     * @param string|\Carbon\Carbon $date
     * @return static
     */
    public function onDate($date): static
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $this->state(fn (array $attributes) => [
            'date' => $date->format('Y-m-d'),
        ]);
    }

    /**
     * Create an expense within the last N days.
     *
     * @param int $days
     * @return static
     */
    public function recent(int $days = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $this->faker->dateTimeBetween("-{$days} days", 'now')->format('Y-m-d'),
        ]);
    }

    /**
     * Create an old expense (within 3-year constraint).
     *
     * @param int $minDaysAgo
     * @param int $maxDaysAgo
     * @return static
     */
    public function old(int $minDaysAgo = 365, int $maxDaysAgo = 1095): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $this->faker->dateTimeBetween("-{$maxDaysAgo} days", "-{$minDaysAgo} days")->format('Y-m-d'),
        ]);
    }

    /**
     * Create a soft-deleted expense.
     *
     * @return static
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => true,
            'delete_time' => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }

    /**
     * Create an expense with VAT.
     *
     * @param float|null $vatPercentage
     * @return static
     */
    public function withVat(float $vatPercentage = null): static
    {
        $vat = $vatPercentage ?? $this->faker->randomFloat(2, 5.00, 25.00);
        
        return $this->state(fn (array $attributes) => [
            'vat_amount' => $vat,
        ]);
    }

    /**
     * Create an expense without VAT.
     *
     * @return static
     */
    public function withoutVat(): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_amount' => null,
        ]);
    }

    /**
     * Create an expense with detailed notes.
     *
     * @param string|null $notes
     * @return static
     */
    public function withNotes(string $notes = null): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes ?? $this->faker->paragraph(3),
        ]);
    }

    /**
     * Create an expense for a specific merchant.
     *
     * @param string $merchantName
     * @param string|null $merchantDescription
     * @param string|null $merchantAddress
     * @return static
     */
    public function atMerchant(string $merchantName, string $merchantDescription = null, string $merchantAddress = null): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_name' => $merchantName,
            'merchant_description' => $merchantDescription ?? $this->faker->sentence(8),
            'merchant_address' => $merchantAddress ?? $this->faker->address,
        ]);
    }

    /**
     * Create an expense with specific expense type.
     *
     * @param int $expenseTypeId
     * @return static
     */
    public function withExpenseType(int $expenseTypeId): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => $expenseTypeId,
        ]);
    }

    /**
     * Create an expense for ATM withdrawal.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => OptPocketExpenseType::factory()->atmWithdrawal(),
            'merchant_name' => $this->faker->randomElement([
                'Bank of America ATM',
                'Chase Bank ATM',
                'Wells Fargo ATM',
                'Citibank ATM',
                'Capital One ATM'
            ]),
            'merchant_description' => 'ATM Cash Withdrawal',
            'amount' => $this->faker->randomFloat(2, -500.00, -20.00),
            'vat_amount' => null, // ATM withdrawals typically don't have VAT
        ]);
    }

    /**
     * Create an expense for point of sale transaction.
     *
     * @return static
     */
    public function pointOfSale(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => OptPocketExpenseType::factory()->pointOfSale(),
            'amount' => $this->faker->randomFloat(2, -200.00, -5.00),
            'vat_amount' => $this->faker->randomFloat(2, 5.00, 20.00), // POS transactions often have VAT
        ]);
    }

    /**
     * Create an expense for fees and charges.
     *
     * @return static
     */
    public function feeAndCharges(): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_type' => OptPocketExpenseType::factory()->feeAndCharges(),
            'merchant_name' => $this->faker->randomElement([
                'Bank Service Fee',
                'Transaction Fee',
                'Monthly Maintenance',
                'Overdraft Fee',
                'Wire Transfer Fee'
            ]),
            'amount' => $this->faker->randomFloat(2, -50.00, -1.00),
            'vat_amount' => null, // Bank fees typically don't have VAT
        ]);
    }

    /**
     * Create multiple expenses for the same user/client combination.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $createdByUserId
     * @param int $count
     * @return static
     */
    public function multipleForUser(int $userId, int $clientId, int $createdByUserId, int $count = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Create an expense with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $createTime
     * @param \Carbon\Carbon|string|null $updateTime
     * @return static
     */
    public function withTimestamps($createTime = null, $updateTime = null): static
    {
        return $this->state(fn (array $attributes) => [
            'create_time' => $createTime ?? now()->subDays($this->faker->numberBetween(1, 30)),
            'update_time' => $updateTime ?? now()->subDays($this->faker->numberBetween(0, 10)),
        ]);
    }

    /**
     * Create an expense with audit trail (updated and approved by users).
     *
     * @param int|null $updatedByUserId
     * @param int|null $approvedByUserId
     * @return static
     */
    public function withAuditTrail(int $updatedByUserId = null, int $approvedByUserId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'updated_by_user_id' => $updatedByUserId ?? User::factory()->create()->id,
            'approved_by_user_id' => $approvedByUserId,
            'update_time' => now()->subDays($this->faker->numberBetween(0, 5)),
        ]);
    }

    /**
     * Create an active expense (not deleted).
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => false,
            'delete_time' => null,
        ]);
    }

    /**
     * Create expenses with various statuses for testing workflows.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createAllStatuses(): \Illuminate\Database\Eloquent\Collection
    {
        return collect([
            $this->draft()->create(),
            $this->submitted()->create(),
            $this->approved()->create(),
            $this->rejected()->create(),
        ]);
    }

    /**
     * Create an expense that mimics CSV upload data.
     *
     * @return static
     */
    public function fromCsv(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted', // CSV uploads typically go straight to submitted
            'notes' => 'Imported from CSV upload on ' . now()->format('Y-m-d H:i:s'),
            'merchant_description' => $this->faker->sentence(6),
            'merchant_address' => $this->faker->optional(0.3)->address,
            'vat_amount' => $this->faker->optional(0.3)->randomFloat(2, 0, 25.00),
        ]);
    }

    /**
     * Create an expense with minimum required fields only.
     *
     * @return static
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_description' => null,
            'merchant_address' => null,
            'vat_amount' => null,
            'notes' => null,
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
        ]);
    }

    /**
     * Create an expense with maximum field utilization for testing.
     *
     * @return static
     */
    public function maximal(): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_description' => $this->faker->paragraph(3),
            'merchant_address' => $this->faker->address,
            'vat_amount' => $this->faker->randomFloat(2, 5.00, 25.00),
            'notes' => $this->faker->paragraph(5),
            'updated_by_user_id' => User::factory(),
            'approved_by_user_id' => User::factory(),
            'status' => 'approved',
        ]);
    }

    /**
     * Create expenses with amounts suitable for testing FX conversion.
     * Uses various currencies and significant amounts.
     *
     * @return static
     */
    public function forFxTesting(): static
    {
        $currencies = ['USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF'];
        $currency = $this->faker->randomElement($currencies);
        
        // Adjust amount ranges based on typical currency values
        $amount = match ($currency) {
            'JPY' => $this->faker->randomFloat(0, -50000, -1000), // JPY has no decimal places typically
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF' => $this->faker->randomFloat(2, -1000.00, -50.00),
            default => $this->faker->randomFloat(2, -500.00, -25.00),
        };

        return $this->state(fn (array $attributes) => [
            'currency' => $currency,
            'amount' => $amount,
        ]);
    }

    /**
     * Create expenses within date constraint (not older than 3 years).
     *
     * @return static
     */
    public function withinDateConstraint(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $this->faker->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
        ]);
    }

    /**
     * Create an expense with a UUID suitable for testing.
     *
     * @param string|null $uuid
     * @return static
     */
    public function withUuid(string $uuid = null): static
    {
        return $this->state(fn (array $attributes) => [
            'uuid' => $uuid ?? Str::uuid()->toString(),
        ]);
    }
}