<?php

namespace Database\Factories;

use App\Models\PocketExpense;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Factory for PocketExpense model
 * 
 * Generates test data for pocket expenses with proper relationships
 * to users, clients, expense types, and realistic expense scenarios.
 * Handles all expense status workflows and audit trail fields.
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
        $now = Carbon::now();
        $expenseDate = $this->faker->dateTimeBetween('-2 years', 'now');
        $amount = $this->faker->randomFloat(2, 5.00, 500.00);
        
        return [
            // UUID for external references
            'uuid' => Str::uuid()->toString(),
            
            // Foreign key relationships - default to ID 1, override in tests
            'user_id' => 1,
            'client_id' => 1,
            
            // Expense details
            'date' => $expenseDate->format('Y-m-d'),
            'merchant_name' => $this->faker->company(),
            'merchant_description' => $this->faker->optional(0.7)->sentence(8),
            
            // Expense type - default to Point of Sale (ID 2 from seeded data)
            'expense_type' => 2,
            
            // Currency and amounts
            'currency' => $this->faker->randomElement(['GBP', 'EUR', 'USD']),
            'amount' => $amount,
            
            // Additional expense fields
            'merchant_address' => $this->faker->optional(0.6)->address(),
            'vat_amount' => $this->faker->optional(0.4)->randomFloat(2, 1.00, $amount * 0.2),
            'notes' => $this->faker->optional(0.5)->sentence(12),
            
            // Status workflow - default to draft
            'status' => 'draft',
            
            // Audit fields - created by same user initially
            'created_by_user_id' => 1,
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
            
            // Volopa legacy timestamp pattern
            'create_time' => $now,
            'update_time' => $now,
            
            // Active record (not soft deleted)
            'deleted' => 0,
            'delete_time' => null,
        ];
    }

    /**
     * Configure the factory for draft status expenses.
     *
     * @return static
     */
    public function draft(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'draft',
                'approved_by_user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for submitted status expenses.
     *
     * @return static
     */
    public function submitted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'submitted',
                'approved_by_user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for approved status expenses.
     *
     * @return static
     */
    public function approved(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'approved',
                'approved_by_user_id' => 1, // Default approver, override in tests
            ];
        });
    }

    /**
     * Configure the factory for rejected status expenses.
     *
     * @return static
     */
    public function rejected(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'rejected',
                'approved_by_user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a specific user ID.
     *
     * @param int $userId
     * @return static
     */
    public function forUser(int $userId): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'user_id' => $userId,
                'created_by_user_id' => $userId, // Assume user created their own expense
            ];
        });
    }

    /**
     * Configure the factory with a specific client ID.
     *
     * @param int $clientId
     * @return static
     */
    public function forClient(int $clientId): static
    {
        return $this->state(function (array $attributes) use ($clientId) {
            return [
                'client_id' => $clientId,
            ];
        });
    }

    /**
     * Configure the factory with a specific expense type ID.
     *
     * @param int $expenseTypeId
     * @return static
     */
    public function withExpenseType(int $expenseTypeId): static
    {
        return $this->state(function (array $attributes) use ($expenseTypeId) {
            return [
                'expense_type' => $expenseTypeId,
            ];
        });
    }

    /**
     * Configure the factory for ATM Withdrawal expenses (expense type ID 1).
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'expense_type' => 1, // ATM Withdrawal from seeded data
                'merchant_name' => $this->faker->randomElement([
                    'ATM - Barclays Bank',
                    'ATM - HSBC',
                    'ATM - Santander',
                    'ATM - Nationwide',
                    'ATM - Lloyds Bank'
                ]),
                'merchant_description' => 'Cash withdrawal',
                'vat_amount' => null, // ATM withdrawals typically don't have VAT
            ];
        });
    }

    /**
     * Configure the factory for Point of Sale expenses (expense type ID 2).
     *
     * @return static
     */
    public function pointOfSale(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'expense_type' => 2, // Point of Sale from seeded data
                'merchant_name' => $this->faker->company(),
                'merchant_description' => $this->faker->randomElement([
                    'Business meeting lunch',
                    'Office supplies',
                    'Client entertainment',
                    'Travel expenses',
                    'Conference materials'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for Fee & Charges expenses (expense type ID 3).
     *
     * @return static
     */
    public function feeAndCharges(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'expense_type' => 3, // Fee & Charges from seeded data
                'merchant_name' => $this->faker->randomElement([
                    'Foreign Exchange Fee',
                    'Card Processing Fee',
                    'Transaction Charge',
                    'Service Fee',
                    'Administration Charge'
                ]),
                'merchant_description' => 'Bank or card processing fee',
                'vat_amount' => null, // Fees typically don't include VAT
            ];
        });
    }

    /**
     * Configure the factory for Refund from Merchant expenses (expense type ID 4).
     *
     * @return static
     */
    public function refundFromMerchant(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'expense_type' => 4, // Refund from Merchant from seeded data
                'merchant_name' => $this->faker->company(),
                'merchant_description' => $this->faker->randomElement([
                    'Cancelled order refund',
                    'Product return refund',
                    'Overcharge adjustment',
                    'Service cancellation refund',
                    'Duplicate payment refund'
                ]),
                'amount' => $this->faker->randomFloat(2, 5.00, 200.00), // Typically smaller refund amounts
            ];
        });
    }

    /**
     * Configure the factory with a specific currency.
     *
     * @param string $currency 3-letter ISO currency code
     * @return static
     */
    public function withCurrency(string $currency): static
    {
        return $this->state(function (array $attributes) use ($currency) {
            return [
                'currency' => strtoupper($currency),
            ];
        });
    }

    /**
     * Configure the factory with GBP currency.
     *
     * @return static
     */
    public function gbp(): static
    {
        return $this->withCurrency('GBP');
    }

    /**
     * Configure the factory with EUR currency.
     *
     * @return static
     */
    public function eur(): static
    {
        return $this->withCurrency('EUR');
    }

    /**
     * Configure the factory with USD currency.
     *
     * @return static
     */
    public function usd(): static
    {
        return $this->withCurrency('USD');
    }

    /**
     * Configure the factory with a specific amount.
     *
     * @param float $amount
     * @return static
     */
    public function withAmount(float $amount): static
    {
        return $this->state(function (array $attributes) use ($amount) {
            return [
                'amount' => $amount,
            ];
        });
    }

    /**
     * Configure the factory with a specific date.
     *
     * @param string|\DateTimeInterface $date
     * @return static
     */
    public function withDate($date): static
    {
        return $this->state(function (array $attributes) use ($date) {
            $dateObj = $date instanceof \DateTimeInterface ? $date : Carbon::parse($date);
            return [
                'date' => $dateObj->format('Y-m-d'),
            ];
        });
    }

    /**
     * Configure the factory with a specific merchant name.
     *
     * @param string $merchantName
     * @return static
     */
    public function withMerchant(string $merchantName): static
    {
        return $this->state(function (array $attributes) use ($merchantName) {
            return [
                'merchant_name' => $merchantName,
            ];
        });
    }

    /**
     * Configure the factory with VAT amount.
     *
     * @param float $vatAmount
     * @return static
     */
    public function withVat(float $vatAmount): static
    {
        return $this->state(function (array $attributes) use ($vatAmount) {
            return [
                'vat_amount' => $vatAmount,
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
            return [
                'vat_amount' => null,
            ];
        });
    }

    /**
     * Configure the factory with specific notes.
     *
     * @param string $notes
     * @return static
     */
    public function withNotes(string $notes): static
    {
        return $this->state(function (array $attributes) use ($notes) {
            return [
                'notes' => $notes,
            ];
        });
    }

    /**
     * Configure the factory without notes.
     *
     * @return static
     */
    public function withoutNotes(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'notes' => null,
            ];
        });
    }

    /**
     * Configure the factory with specific creator user ID.
     *
     * @param int $createdByUserId
     * @return static
     */
    public function createdBy(int $createdByUserId): static
    {
        return $this->state(function (array $attributes) use ($createdByUserId) {
            return [
                'created_by_user_id' => $createdByUserId,
            ];
        });
    }

    /**
     * Configure the factory with specific updater user ID.
     *
     * @param int $updatedByUserId
     * @return static
     */
    public function updatedBy(int $updatedByUserId): static
    {
        return $this->state(function (array $attributes) use ($updatedByUserId) {
            return [
                'updated_by_user_id' => $updatedByUserId,
                'update_time' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory with specific approver user ID.
     *
     * @param int $approvedByUserId
     * @return static
     */
    public function approvedBy(int $approvedByUserId): static
    {
        return $this->state(function (array $attributes) use ($approvedByUserId) {
            return [
                'status' => 'approved',
                'approved_by_user_id' => $approvedByUserId,
            ];
        });
    }

    /**
     * Configure the factory for soft deleted expenses.
     *
     * @return static
     */
    public function softDeleted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => 1,
                'delete_time' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory for active (non-deleted) expenses.
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => 0,
                'delete_time' => null,
            ];
        });
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
                'update_time' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory with recent expense dates (within last 30 days).
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            ];
        });
    }

    /**
     * Configure the factory with old expense dates (older than 1 year).
     *
     * @return static
     */
    public function old(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'date' => $this->faker->dateTimeBetween('-3 years', '-1 year')->format('Y-m-d'),
            ];
        });
    }

    /**
     * Configure the factory for high-value expenses (over £100).
     *
     * @return static
     */
    public function highValue(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'amount' => $this->faker->randomFloat(2, 100.00, 1000.00),
            ];
        });
    }

    /**
     * Configure the factory for low-value expenses (under £50).
     *
     * @return static
     */
    public function lowValue(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'amount' => $this->faker->randomFloat(2, 1.00, 49.99),
            ];
        });
    }

    /**
     * Configure the factory with a complete expense scenario.
     * 
     * @param int $userId
     * @param int $clientId
     * @param int $expenseTypeId
     * @param string $status
     * @return static
     */
    public function complete(int $userId, int $clientId, int $expenseTypeId, string $status = 'draft'): static
    {
        return $this->state(function (array $attributes) use ($userId, $clientId, $expenseTypeId, $status) {
            return [
                'user_id' => $userId,
                'client_id' => $clientId,
                'expense_type' => $expenseTypeId,
                'status' => $status,
                'created_by_user_id' => $userId,
                'deleted' => 0,
                'delete_time' => null,
            ];
        });
    }

    /**
     * Configure the factory for expenses in different status workflow states as a sequence.
     *
     * @return static
     */
    public function statusSequence(): static
    {
        return $this->sequence(
            ['status' => 'draft', 'approved_by_user_id' => null],
            ['status' => 'submitted', 'approved_by_user_id' => null],
            ['status' => 'approved', 'approved_by_user_id' => 1],
            ['status' => 'rejected', 'approved_by_user_id' => null]
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
            ['expense_type' => 1], // ATM Withdrawal
            ['expense_type' => 2], // Point of Sale
            ['expense_type' => 3], // Fee & Charges
            ['expense_type' => 4]  // Refund from Merchant
        );
    }
}