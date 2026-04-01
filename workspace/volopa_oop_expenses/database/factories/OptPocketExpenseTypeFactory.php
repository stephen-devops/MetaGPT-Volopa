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
        // Use seeded expense type options from the migration
        $expenseTypes = [
            ['option' => 'ATM Withdrawal', 'amount_sign' => 'negative'],
            ['option' => 'Point of Sale', 'amount_sign' => 'negative'],
            ['option' => 'Fee & Charges', 'amount_sign' => 'negative'],
            ['option' => 'Refund from Merchant', 'amount_sign' => 'positive'],
        ];

        $selectedType = $this->faker->randomElement($expenseTypes);

        return [
            'option' => $selectedType['option'],
            'amount_sign' => $selectedType['amount_sign'],
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Indicate that the expense type has negative amount sign.
     *
     * @return static
     */
    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_sign' => 'negative',
        ]);
    }

    /**
     * Indicate that the expense type has positive amount sign.
     *
     * @return static
     */
    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_sign' => 'positive',
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
        ]);
    }

    /**
     * Create a Fee & Charges expense type.
     *
     * @return static
     */
    public function feeCharges(): static
    {
        return $this->state(fn (array $attributes) => [
            'option' => 'Fee & Charges',
            'amount_sign' => 'negative',
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
        ]);
    }

    /**
     * Create a custom expense type with specific option.
     *
     * @param string $option
     * @param string $amountSign
     * @return static
     */
    public function withOption(string $option, string $amountSign = 'negative'): static
    {
        return $this->state(fn (array $attributes) => [
            'option' => $option,
            'amount_sign' => $amountSign,
        ]);
    }
}