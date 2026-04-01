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
        // Generate a date within the last 3 years as per system constraints
        $maxDate = now();
        $minDate = now()->subYears(3);
        
        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'date' => $this->faker->dateTimeBetween($minDate, $maxDate)->format('Y-m-d'),
            'merchant_name' => $this->faker->company,
            'merchant_description' => $this->faker->optional(0.7)->catchPhrase,
            'expense_type' => OptPocketExpenseType::factory(),
            'currency' => $this->faker->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'merchant_address' => $this->faker->optional(0.6)->address,
            'vat_amount' => $this->faker->optional(0.4)->randomFloat(2, 1, 500),
            'notes' => $this->faker->optional(0.5)->sentence,
            'status' => $this->faker->randomElement(['draft', 'submitted', 'approved', 'rejected']),
            'created_by_user_id' => User::factory(),
            'updated_by_user_id' => $this->faker->optional(0.6)->passthrough(User::factory()),
            'approved_by_user_id' => $this->faker->optional(0.3)->passthrough(User::factory()),
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Indicate that the expense is in draft status.
     *
     * @return static
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'approved_by_user_id' => null,
        ]);
    }

    /**
     * Indicate that the expense is submitted.
     *
     * @return static
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'approved_by_user_id' => null,
        ]);
    }

    /**
     * Indicate that the expense is approved.
     *
     * @return static
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the expense is rejected.
     *
     * @return static
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'approved_by_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the expense is soft deleted.
     *
     * @return static
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => 1,
            'delete_time' => now(),
        ]);
    }

    /**
     * Create an expense for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return static
     */
    public function forUser(int $userId, int $clientId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
        ]);
    }

    /**
     * Create an expense with specific amount and currency.
     *
     * @param float $amount
     * @param string $currency
     * @return static
     */
    public function withAmount(float $amount, string $currency = 'USD'): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    /**
     * Create an expense with VAT amount.
     *
     * @param float|null $vatAmount
     * @return static
     */
    public function withVat(float $vatAmount = null): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_amount' => $vatAmount ?? $this->faker->randomFloat(2, 1, 100),
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
     * Create an expense with a specific date.
     *
     * @param string $date
     * @return static
     */
    public function withDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    /**
     * Create an expense from today.
     *
     * @return static
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * Create an expense from recent dates (within last 30 days).
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
        ]);
    }

    /**
     * Create an expense with specific merchant name.
     *
     * @param string $merchantName
     * @return static
     */
    public function withMerchant(string $merchantName): static
    {
        return $this->state(fn (array $attributes) => [
            'merchant_name' => $merchantName,
        ]);
    }

    /**
     * Create an expense with notes.
     *
     * @param string $notes
     * @return static
     */
    public function withNotes(string $notes): static
    {
        return $this->state(fn (array $attributes) => [
            'notes' => $notes,
        ]);
    }

    /**
     * Create an expense created by a specific user.
     *
     * @param int $createdByUserId
     * @return static
     */
    public function createdBy(int $createdByUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by_user_id' => $createdByUserId,
        ]);
    }
}