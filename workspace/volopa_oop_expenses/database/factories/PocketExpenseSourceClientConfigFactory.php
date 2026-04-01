<?php

namespace Database\Factories;

use App\Models\PocketExpenseSourceClientConfig;
use App\Models\Client;
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
        // Default source names that align with system constraints (3 defaults auto-created)
        $defaultSources = [
            'Cash',
            'Corporate Card',
            'Personal Card',
            'Online Banking',
            'Credit Card',
            'Debit Card',
            'Company Account',
            'Petty Cash',
            'Travel Card',
            'Expense Account'
        ];

        return [
            'uuid' => Str::uuid()->toString(),
            'client_id' => Client::factory(),
            'name' => $this->faker->randomElement($defaultSources),
            'is_default' => $this->faker->boolean(20), // 20% chance of being default
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Indicate that the source is a default source.
     *
     * @return static
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Indicate that the source is not a default source.
     *
     * @return static
     */
    public function notDefault(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => false,
        ]);
    }

    /**
     * Indicate that the source is soft deleted.
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
     * Create a Cash source.
     *
     * @return static
     */
    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Cash',
            'is_default' => true,
        ]);
    }

    /**
     * Create a Corporate Card source.
     *
     * @return static
     */
    public function corporateCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Corporate Card',
            'is_default' => true,
        ]);
    }

    /**
     * Create a Personal Card source.
     *
     * @return static
     */
    public function personalCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Personal Card',
            'is_default' => true,
        ]);
    }

    /**
     * Create the global 'Other' source with null client_id.
     *
     * @return static
     */
    public function globalOther(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
        ]);
    }

    /**
     * Create a source for a specific client.
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
     * Create a source with a specific name.
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
     * Create the three default sources for a client as per system constraints.
     *
     * @param int $clientId
     * @return array<static>
     */
    public function createDefaultSources(int $clientId): array
    {
        return [
            $this->forClient($clientId)->cash(),
            $this->forClient($clientId)->corporateCard(),
            $this->forClient($clientId)->personalCard(),
        ];
    }
}