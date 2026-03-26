<?php

namespace Database\Factories;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK'];
        $merchantNames = [
            'Amazon', 'Walmart', 'McDonald\'s', 'Starbucks', 'Shell', 'BP',
            'Target', 'Best Buy', 'Home Depot', 'CVS Pharmacy', 'Walgreens',
            'Uber', 'Lyft', 'Airbnb', 'Hotel Booking', 'Restaurant ABC'
        ];

        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => function () {
                // TODO: Create or reference existing user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'client_id' => function () {
                // TODO: Create or reference existing client - depends on Client model factory
                return \App\Models\Client::factory()->create()->id;
            },
            'date' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'merchant_name' => $this->faker->randomElement($merchantNames),
            'merchant_description' => $this->faker->optional(0.7)->sentence(4),
            'expense_type' => function () {
                // TODO: Create or reference existing expense type - depends on OptPocketExpenseType model factory
                return OptPocketExpenseType::factory()->create()->id;
            },
            'currency' => $this->faker->randomElement($currencies),
            'amount' => $this->faker->randomFloat(2, 1.00, 5000.00),
            'merchant_address' => $this->faker->optional(0.6)->address(),
            'vat_amount' => $this->faker->optional(0.5)->randomFloat(2, 0.00, 100.00),
            'notes' => $this->faker->optional(0.4)->sentence(8),
            'status' => $this->faker->randomElement(['draft', 'submitted', 'approved', 'rejected']),
            'created_by_user_id' => function () {
                // TODO: Create or reference existing creator user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'updated_by_user_id' => function () {
                // TODO: Create or reference existing updater user - depends on User model factory
                return $this->faker->optional(0.7)->passthrough(\App\Models\User::factory()->create()->id);
            },
            'approved_by_user_id' => function () {
                // TODO: Create or reference existing approver user - depends on User model factory
                return $this->faker->optional(0.3)->passthrough(\App\Models\User::factory()->create()->id);
            },
            'create_time' => now(),
            'update_time' => now(),
            'deleted' => false,
            'delete_time' => null,
        ];
    }

    /**
     * Indicate that the expense is in draft status.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function draft(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'draft',
            ];
        });
    }

    /**
     * Indicate that the expense is submitted.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function submitted(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'submitted',
            ];
        });
    }

    /**
     * Indicate that the expense is approved.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function approved(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'approved',
                'approved_by_user_id' => function () {
                    return \App\Models\User::factory()->create()->id;
                },
            ];
        });
    }

    /**
     * Indicate that the expense is rejected.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function rejected(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'rejected',
            ];
        });
    }

    /**
     * Indicate that the expense is soft deleted.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function deleted(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => true,
                'delete_time' => now(),
            ];
        });
    }

    /**
     * Create expense for specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forUserAndClient(int $userId, int $clientId): Factory
    {
        return $this->state(function (array $attributes) use ($userId, $clientId) {
            return [
                'user_id' => $userId,
                'client_id' => $clientId,
            ];
        });
    }

    /**
     * Create expense with specific amount and currency.
     *
     * @param float $amount
     * @param string $currency
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withAmountAndCurrency(float $amount, string $currency): Factory
    {
        return $this->state(function (array $attributes) use ($amount, $currency) {
            return [
                'amount' => $amount,
                'currency' => $currency,
            ];
        });
    }

    /**
     * Create expense with specific expense type.
     *
     * @param int $expenseTypeId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withExpenseType(int $expenseTypeId): Factory
    {
        return $this->state(function (array $attributes) use ($expenseTypeId) {
            return [
                'expense_type' => $expenseTypeId,
            ];
        });
    }

    /**
     * Create expense with specific date.
     *
     * @param string $date
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withDate(string $date): Factory
    {
        return $this->state(function (array $attributes) use ($date) {
            return [
                'date' => $date,
            ];
        });
    }

    /**
     * Create expense with specific merchant name.
     *
     * @param string $merchantName
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withMerchant(string $merchantName): Factory
    {
        return $this->state(function (array $attributes) use ($merchantName) {
            return [
                'merchant_name' => $merchantName,
            ];
        });
    }

    /**
     * Create expense created by specific user.
     *
     * @param int $createdByUserId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function createdBy(int $createdByUserId): Factory
    {
        return $this->state(function (array $attributes) use ($createdByUserId) {
            return [
                'created_by_user_id' => $createdByUserId,
            ];
        });
    }

    /**
     * Create expense with VAT amount.
     *
     * @param float $vatAmount
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withVAT(float $vatAmount): Factory
    {
        return $this->state(function (array $attributes) use ($vatAmount) {
            return [
                'vat_amount' => $vatAmount,
            ];
        });
    }

    /**
     * Create expense within date range (not older than 3 years constraint).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function recentDate(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'date' => $this->faker->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            ];
        });
    }
}