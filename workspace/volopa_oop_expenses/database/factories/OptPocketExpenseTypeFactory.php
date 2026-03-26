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
            'Service Fee',
            'Transfer Fee',
            'Merchant Refund',
            'Chargeback'
        ];

        return [
            'option' => $this->faker->randomElement($expenseTypes),
            'amount_sign' => $this->faker->randomElement(['positive', 'negative']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the expense type has negative amount sign.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function negative(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_sign' => 'negative',
            ];
        });
    }

    /**
     * Indicate that the expense type has positive amount sign.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function positive(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_sign' => 'positive',
            ];
        });
    }

    /**
     * Create specific expense type with custom option.
     *
     * @param string $option
     * @param string $amountSign
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withOption(string $option, string $amountSign = 'negative'): Factory
    {
        return $this->state(function (array $attributes) use ($option, $amountSign) {
            return [
                'option' => $option,
                'amount_sign' => $amountSign,
            ];
        });
    }

    /**
     * Create ATM Withdrawal expense type (default negative).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function atmWithdrawal(): Factory
    {
        return $this->withOption('ATM Withdrawal', 'negative');
    }

    /**
     * Create Point of Sale expense type (default negative).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function pointOfSale(): Factory
    {
        return $this->withOption('Point of Sale', 'negative');
    }

    /**
     * Create Fee & Charges expense type (default negative).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function feeAndCharges(): Factory
    {
        return $this->withOption('Fee & Charges', 'negative');
    }

    /**
     * Create Refund from Merchant expense type (default positive).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function refundFromMerchant(): Factory
    {
        return $this->withOption('Refund from Merchant', 'positive');
    }
}