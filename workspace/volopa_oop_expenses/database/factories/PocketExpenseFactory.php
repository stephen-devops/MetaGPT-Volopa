<?php

namespace Database\Factories;

use App\Models\PocketExpense;
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
        // Common currencies
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'];
        
        // Default to past 30 days to avoid 3-year constraint issues
        $expenseDate = $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d');
        
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => 1, // Default to user ID 1, should be overridden in tests with actual user
            'client_id' => 1, // Default to client ID 1, should be overridden in tests with actual client
            'date' => $expenseDate,
            'merchant_name' => $this->faker->company(),
            'merchant_description' => $this->faker->optional()->sentence(6),
            'expense_type' => 1, // Default to first expense type, should be overridden with actual OptPocketExpenseType ID
            'currency' => $this->faker->randomElement($currencies),
            'amount' => $this->faker->randomFloat(2, 5, 1000), // Random amount between 5 and 1000
            'merchant_address' => $this->faker->optional()->address(),
            'vat_amount' => $this->faker->optional()->randomFloat(2, 0, 100),
            'notes' => $this->faker->optional()->text(200),
            'status' => 'draft',
            'created_by_user_id' => 1, // Default to user ID 1, should be overridden in tests
            'updated_by_user_id' => null,
            'approved_by_user_id' => null,
            'create_time' => now(),
            'update_time' => null,
            'deleted' => 0,
            'delete_time' => null,
        ];
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
            'approved_by_user_id' => 1, // Should be overridden with actual approver user ID
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
     * Set the user for this expense.
     *
     * @param int $userId
     * @return static
     */
    public function forUser(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    /**
     * Set the client for this expense.
     *
     * @param int $clientId
     * @return static
     */
    public function forClient(int $clientId): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $clientId,
        ]);
    }

    /**
     * Set the expense type.
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
     * Set the currency for this expense.
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
     * Set the amount for this expense.
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
     * Set the merchant name.
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
     * Set the expense date.
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
     * Set the created by user.
     *
     * @param int $userId
     * @return static
     */
    public function createdBy(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Set the updated by user.
     *
     * @param int $userId
     * @return static
     */
    public function updatedBy(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'updated_by_user_id' => $userId,
            'update_time' => now(),
        ]);
    }

    /**
     * Set the approved by user.
     *
     * @param int $userId
     * @return static
     */
    public function approvedBy(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_by_user_id' => $userId,
            'status' => 'approved',
        ]);
    }

    /**
     * Create expense with VAT amount.
     *
     * @param float $vatAmount
     * @return static
     */
    public function withVat(float $vatAmount): static
    {
        return $this->state(fn (array $attributes) => [
            'vat_amount' => $vatAmount,
        ]);
    }

    /**
     * Create expense with notes.
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
     * Create expense from recent date (within 30 days).
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
     * Create expense from older date (within 3 years but older than 30 days).
     *
     * @return static
     */
    public function older(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $this->faker->dateTimeBetween('-3 years', '-31 days')->format('Y-m-d'),
        ]);
    }
}