<?php

namespace Database\Factories;

use App\Models\OptPocketExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for OptPocketExpenseType model
 * 
 * Generates test data for pocket expense types with proper amount sign conventions.
 * Provides predefined expense type options that match platform seeded data.
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
        $now = Carbon::now();
        
        // Default to Point of Sale type with negative amount sign
        return [
            'option' => 'Point of Sale',
            'amount_sign' => 'negative',
            
            // Volopa legacy timestamp pattern
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    /**
     * Configure the factory for ATM Withdrawal expense type.
     *
     * @return static
     */
    public function atmWithdrawal(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'option' => 'ATM Withdrawal',
                'amount_sign' => 'negative',
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
            return [
                'option' => 'Point of Sale',
                'amount_sign' => 'negative',
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
            return [
                'option' => 'Fee & Charges',
                'amount_sign' => 'negative',
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
            return [
                'option' => 'Refund from Merchant',
                'amount_sign' => 'positive',
            ];
        });
    }

    /**
     * Configure the factory for negative amount sign expense types.
     *
     * @return static
     */
    public function negative(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_sign' => 'negative',
            ];
        });
    }

    /**
     * Configure the factory for positive amount sign expense types.
     *
     * @return static
     */
    public function positive(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_sign' => 'positive',
            ];
        });
    }

    /**
     * Configure the factory with a custom expense type option.
     *
     * @param string $option
     * @return static
     */
    public function withOption(string $option): static
    {
        return $this->state(function (array $attributes) use ($option) {
            return [
                'option' => $option,
            ];
        });
    }

    /**
     * Configure the factory with a specific amount sign.
     *
     * @param string $amountSign Either 'positive' or 'negative'
     * @return static
     */
    public function withAmountSign(string $amountSign): static
    {
        return $this->state(function (array $attributes) use ($amountSign) {
            return [
                'amount_sign' => $amountSign,
            ];
        });
    }

    /**
     * Configure the factory to create a custom expense type.
     *
     * @param string $option
     * @param string $amountSign
     * @return static
     */
    public function custom(string $option, string $amountSign): static
    {
        return $this->state(function (array $attributes) use ($option, $amountSign) {
            return [
                'option' => $option,
                'amount_sign' => $amountSign,
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
     * Configure the factory to create all default expense types as a sequence.
     * Useful for testing scenarios that need all predefined types.
     *
     * @return static
     */
    public function sequence(): static
    {
        return $this->sequence(
            ['option' => 'ATM Withdrawal', 'amount_sign' => 'negative'],
            ['option' => 'Point of Sale', 'amount_sign' => 'negative'],
            ['option' => 'Fee & Charges', 'amount_sign' => 'negative'],
            ['option' => 'Refund from Merchant', 'amount_sign' => 'positive']
        );
    }
}