<?php

namespace Database\Factories;

use App\Models\OptPocketExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OptPocketExpenseType>
 */
class OptPocketExpenseTypeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OptPocketExpenseType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $expenseTypes = [
            'ATM Withdrawal',
            'Point of Sale',
            'Fee & Charges',
            'Refund from Merchant',
            'Online Purchase',
            'Cash Advance',
            'Service Charge',
            'Monthly Fee',
            'Transaction Fee',
            'Currency Exchange'
        ];

        $option = $this->faker->randomElement($expenseTypes);
        
        // Determine amount sign based on expense type logic
        // Refunds are positive, all others are negative as per system constraints
        $amountSign = (str_contains(strtolower($option), 'refund')) ? 'positive' : 'negative';

        return [
            'option' => $option,
            'amount_sign' => $amountSign,
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 100),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Create an expense type with positive amount sign (for refunds).
     *
     * @return static
     */
    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_sign' => 'positive',
            'option' => $this->faker->randomElement([
                'Refund from Merchant',
                'Cash Refund',
                'Credit Adjustment',
                'Cashback',
                'Reward Credit'
            ]),
        ]);
    }

    /**
     * Create an expense type with negative amount sign.
     *
     * @return static
     */
    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_sign' => 'negative',
            'option' => $this->faker->randomElement([
                'ATM Withdrawal',
                'Point of Sale',
                'Fee & Charges',
                'Online Purchase',
                'Service Charge'
            ]),
        ]);
    }

    /**
     * Create an inactive expense type.
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create an expense type with a specific option name.
     *
     * @param string $option
     * @return static
     */
    public function withOption(string $option): static
    {
        $amountSign = (str_contains(strtolower($option), 'refund')) ? 'positive' : 'negative';
        
        return $this->state(fn (array $attributes) => [
            'option' => $option,
            'amount_sign' => $amountSign,
        ]);
    }

    /**
     * Create an expense type with a specific sort order.
     *
     * @param int $sortOrder
     * @return static
     */
    public function withSortOrder(int $sortOrder): static
    {
        return $this->state(fn (array $attributes) => [
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * Create the default system expense types as per constraints.
     * These match the seeded data in the migration.
     *
     * @return static
     */
    public function systemDefault(): static
    {
        $systemTypes = [
            ['option' => 'ATM Withdrawal', 'amount_sign' => 'negative', 'sort_order' => 1],
            ['option' => 'Point of Sale', 'amount_sign' => 'negative', 'sort_order' => 2],
            ['option' => 'Fee & Charges', 'amount_sign' => 'negative', 'sort_order' => 3],
            ['option' => 'Refund from Merchant', 'amount_sign' => 'positive', 'sort_order' => 4],
        ];

        $randomType = $this->faker->randomElement($systemTypes);

        return $this->state(fn (array $attributes) => [
            'option' => $randomType['option'],
            'amount_sign' => $randomType['amount_sign'],
            'sort_order' => $randomType['sort_order'],
            'is_active' => true,
        ]);
    }

    /**
     * Create an ATM Withdrawal expense type.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(fn (array $attributes) => [
            'option' => 'ATM Withdrawal',
            'amount_sign' => 'negative',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * Create a Point of Sale expense type.
     *
     * @return static
     */
    public function pointOfSale(): static
    {
        return $this->state(fn (array $attributes) => [
            'option' => 'Point of Sale',
            'amount_sign' => 'negative',
            'sort_order' => 2,
            'is_active' => true,
        ]);
    }

    /**
     * Create a Fee & Charges expense type.
     *
     * @return static
     */
    public function feeAndCharges(): static
    {
        return $this->state(fn (array $attributes) => [
            'option' => 'Fee & Charges',
            'amount_sign' => 'negative',
            'sort_order' => 3,
            'is_active' => true,
        ]);
    }

    /**
     * Create a Refund from Merchant expense type.
     *
     * @return static
     */
    public function refundFromMerchant(): static
    {
        return $this->state(fn (array $attributes) => [
            'option' => 'Refund from Merchant',
            'amount_sign' => 'positive',
            'sort_order' => 4,
            'is_active' => true,
        ]);
    }

    /**
     * Create an expense type with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $createdAt
     * @param \Carbon\Carbon|string|null $updatedAt
     * @return static
     */
    public function withTimestamps($createdAt = null, $updatedAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $createdAt ?? now()->subDays($this->faker->numberBetween(1, 30)),
            'updated_at' => $updatedAt ?? now()->subDays($this->faker->numberBetween(0, 10)),
        ]);
    }

    /**
     * Create expense types that cover all amount sign combinations for testing.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createBothSigns(): \Illuminate\Database\Eloquent\Collection
    {
        return collect([
            $this->positive()->create(),
            $this->negative()->create(),
        ]);
    }

    /**
     * Create a sequence of expense types with incremental sort orders.
     *
     * @param int $count
     * @param int $startingSortOrder
     * @return static
     */
    public function sequence(int $count = 3, int $startingSortOrder = 1): static
    {
        return $this->state(function (array $attributes) use (&$startingSortOrder) {
            return [
                'sort_order' => $startingSortOrder++,
                'is_active' => true,
            ];
        });
    }
}