<?php

namespace Database\Factories;

use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PocketExpenseSourceClientConfig>
 */
class PocketExpenseSourceClientConfigFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PocketExpenseSourceClientConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Default expense source names that would be commonly used
        $sourceNames = [
            'Cash',
            'Corporate Card',
            'Personal Card',
            'Bank Transfer',
            'Debit Card',
            'Credit Card',
            'Petty Cash',
            'Company Account',
            'Personal Account',
            'Travel Card'
        ];

        return [
            'uuid' => (string) Str::uuid(),
            'client_id' => 1, // Default to client ID 1, should be overridden in tests with actual client
            'name' => $this->faker->randomElement($sourceNames),
            'is_default' => 0, // Default to non-default source
            'deleted' => 0, // Active by default
            'delete_time' => null, // Not deleted
            'create_time' => now(),
            'update_time' => null,
        ];
    }

    /**
     * Indicate that the expense source is a default source.
     *
     * @return static
     */
    public function isDefault(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => 1,
        ]);
    }

    /**
     * Indicate that the expense source is soft deleted.
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
     * Set the client for this expense source.
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
     * Create a global "Other" source (client_id = null).
     *
     * @return static
     */
    public function globalOther(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => null,
            'name' => 'Other',
            'is_default' => 0,
        ]);
    }

    /**
     * Create Cash expense source.
     *
     * @return static
     */
    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Cash',
            'is_default' => 1,
        ]);
    }

    /**
     * Create Corporate Card expense source.
     *
     * @return static
     */
    public function corporateCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Corporate Card',
            'is_default' => 1,
        ]);
    }

    /**
     * Create Personal Card expense source.
     *
     * @return static
     */
    public function personalCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Personal Card',
            'is_default' => 1,
        ]);
    }

    /**
     * Create expense source with custom name.
     *
     * @param string $name
     * @return static
     */
    public function withName(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $name,
        ]);
    }

    /**
     * Set the expense source as updated.
     *
     * @return static
     */
    public function updated(): static
    {
        return $this->state(fn (array $attributes) => [
            'update_time' => now(),
        ]);
    }
}