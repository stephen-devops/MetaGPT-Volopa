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
        $sourceName = $this->faker->randomElement([
            'Cash',
            'Corporate Card',
            'Personal Card',
            'Bank Transfer',
            'Petty Cash',
            'Company Credit Card',
            'Employee Reimbursement',
            'Travel Advance',
            'Procurement Card',
            'Digital Wallet'
        ]);

        return [
            'uuid' => Str::uuid()->toString(),
            'client_id' => Client::factory(),
            'name' => $sourceName,
            'is_default' => false,
            'deleted' => false,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Create an expense source for an existing client.
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
     * Create a global expense source (client_id = null).
     * Used for sources like 'Other' that are available to all clients.
     *
     * @return static
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => null,
        ]);
    }

    /**
     * Create a default expense source for a client.
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
     * Create a soft-deleted expense source.
     *
     * @return static
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => true,
            'delete_time' => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }

    /**
     * Create an expense source with a specific name.
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
     * Create the system default 'Cash' expense source.
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
     * Create the system default 'Corporate Card' expense source.
     *
     * @return static
     */
    public function corporateCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Corporate Card',
            'is_default' => false,
        ]);
    }

    /**
     * Create the system default 'Personal Card' expense source.
     *
     * @return static
     */
    public function personalCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Personal Card',
            'is_default' => false,
        ]);
    }

    /**
     * Create the global 'Other' expense source (cannot be deleted or edited).
     * This matches the seeded data from the migration.
     *
     * @return static
     */
    public function other(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
            'deleted' => false,
        ]);
    }

    /**
     * Create the three default expense sources for a client as per system constraints.
     * These are auto-created when OOP feature is enabled for a client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createDefaultsForClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return collect([
            $this->forClient($clientId)->cash()->create(),
            $this->forClient($clientId)->corporateCard()->create(),
            $this->forClient($clientId)->personalCard()->create(),
        ]);
    }

    /**
     * Create an expense source with a specific UUID.
     *
     * @param string $uuid
     * @return static
     */
    public function withUuid(string $uuid): static
    {
        return $this->state(fn (array $attributes) => [
            'uuid' => $uuid,
        ]);
    }

    /**
     * Create an expense source with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $createTime
     * @param \Carbon\Carbon|string|null $updateTime
     * @return static
     */
    public function withTimestamps($createTime = null, $updateTime = null): static
    {
        return $this->state(fn (array $attributes) => [
            'create_time' => $createTime ?? now()->subDays($this->faker->numberBetween(1, 30)),
            'update_time' => $updateTime ?? now()->subDays($this->faker->numberBetween(0, 10)),
        ]);
    }

    /**
     * Create an active expense source (not deleted).
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => false,
            'delete_time' => null,
        ]);
    }

    /**
     * Create multiple unique expense sources for the same client.
     * Ensures names are unique per client as per system constraints.
     *
     * @param int $clientId
     * @param int $count
     * @return static
     */
    public function uniqueForClient(int $clientId, int $count = 3): static
    {
        $sourceNames = [
            'Cash',
            'Corporate Card',
            'Personal Card',
            'Bank Transfer',
            'Petty Cash',
            'Company Credit Card',
            'Employee Reimbursement',
            'Travel Advance',
            'Procurement Card',
            'Digital Wallet',
            'Wire Transfer',
            'Check Payment',
            'Mobile Payment',
            'Gift Card',
            'Voucher'
        ];

        return $this->state(function (array $attributes) use ($clientId, $sourceNames) {
            static $usedNames = [];
            
            if (!isset($usedNames[$clientId])) {
                $usedNames[$clientId] = [];
            }

            $availableNames = array_diff($sourceNames, $usedNames[$clientId]);
            
            if (empty($availableNames)) {
                // If we run out of predefined names, generate a unique one
                $name = 'Source ' . $this->faker->unique()->word . ' ' . $this->faker->numberBetween(1000, 9999);
            } else {
                $name = $this->faker->randomElement($availableNames);
                $usedNames[$clientId][] = $name;
            }

            return [
                'client_id' => $clientId,
                'name' => $name,
            ];
        });
    }

    /**
     * Create an expense source with active status suitable for testing maximum limits.
     * As per constraints, maximum 20 active expense sources per client.
     *
     * @param int $clientId
     * @return static
     */
    public function activeForLimitTesting(int $clientId): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $clientId,
            'name' => 'Test Source ' . $this->faker->unique()->numberBetween(1, 20),
            'deleted' => false,
            'delete_time' => null,
            'is_default' => false,
        ]);
    }

    /**
     * Create an expense source that was recently soft-deleted for testing historical records.
     *
     * @param int $daysAgo
     * @return static
     */
    public function recentlyDeleted(int $daysAgo = 7): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => true,
            'delete_time' => now()->subDays($daysAgo),
            'update_time' => now()->subDays($daysAgo),
        ]);
    }

    /**
     * Create expense sources with a range of creation dates for testing historical data.
     *
     * @param int $minDaysAgo
     * @param int $maxDaysAgo
     * @return static
     */
    public function withRandomAge(int $minDaysAgo = 1, int $maxDaysAgo = 365): static
    {
        return $this->state(function (array $attributes) use ($minDaysAgo, $maxDaysAgo) {
            $createdDaysAgo = $this->faker->numberBetween($minDaysAgo, $maxDaysAgo);
            $updatedDaysAgo = $this->faker->numberBetween(0, $createdDaysAgo);
            
            return [
                'create_time' => now()->subDays($createdDaysAgo),
                'update_time' => now()->subDays($updatedDaysAgo),
            ];
        });
    }
}