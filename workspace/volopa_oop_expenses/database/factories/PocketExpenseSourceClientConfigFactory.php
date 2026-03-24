<?php

namespace Database\Factories;

use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Factory for PocketExpenseSourceClientConfig model
 * 
 * Generates test data for expense source client configurations with proper
 * client relationships and default source options. Handles both client-specific
 * sources and the global 'Other' source configuration.
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
        $now = Carbon::now();
        
        return [
            // UUID for external references
            'uuid' => Str::uuid()->toString(),
            
            // Client relationship - default to client ID 1, override in tests
            'client_id' => 1,
            
            // Default source name - will be overridden by state methods
            'name' => 'Personal Card',
            
            // Default to non-default source
            'is_default' => 0,
            
            // Active record (not soft deleted)
            'deleted' => 0,
            'delete_time' => null,
            
            // Volopa legacy timestamp pattern
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    /**
     * Configure the factory for Cash expense source.
     *
     * @return static
     */
    public function cash(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Cash',
                'is_default' => 1, // Cash is typically a default source
            ];
        });
    }

    /**
     * Configure the factory for Corporate Card expense source.
     *
     * @return static
     */
    public function corporateCard(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Corporate Card',
                'is_default' => 1, // Corporate Card is typically a default source
            ];
        });
    }

    /**
     * Configure the factory for Personal Card expense source.
     *
     * @return static
     */
    public function personalCard(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Personal Card',
                'is_default' => 1, // Personal Card is typically a default source
            ];
        });
    }

    /**
     * Configure the factory for the global 'Other' expense source.
     * This source has client_id = NULL and cannot be deleted.
     *
     * @return static
     */
    public function globalOther(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'uuid' => null, // Global Other has no UUID
                'client_id' => null, // Global record not tied to specific client
                'name' => 'Other',
                'is_default' => 0, // Other is not a default source
            ];
        });
    }

    /**
     * Configure the factory for a default source.
     *
     * @return static
     */
    public function defaultSource(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_default' => 1,
            ];
        });
    }

    /**
     * Configure the factory for a non-default source.
     *
     * @return static
     */
    public function nonDefaultSource(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_default' => 0,
            ];
        });
    }

    /**
     * Configure the factory with a specific client ID.
     *
     * @param int $clientId
     * @return static
     */
    public function forClient(int $clientId): static
    {
        return $this->state(function (array $attributes) use ($clientId) {
            return [
                'client_id' => $clientId,
            ];
        });
    }

    /**
     * Configure the factory with a specific source name.
     *
     * @param string $name
     * @return static
     */
    public function withName(string $name): static
    {
        return $this->state(function (array $attributes) use ($name) {
            return [
                'name' => $name,
            ];
        });
    }

    /**
     * Configure the factory with a specific UUID.
     *
     * @param string $uuid
     * @return static
     */
    public function withUuid(string $uuid): static
    {
        return $this->state(function (array $attributes) use ($uuid) {
            return [
                'uuid' => $uuid,
            ];
        });
    }

    /**
     * Configure the factory without a UUID (null).
     *
     * @return static
     */
    public function withoutUuid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'uuid' => null,
            ];
        });
    }

    /**
     * Configure the factory for soft deleted sources.
     *
     * @return static
     */
    public function softDeleted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => 1,
                'delete_time' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory for active (non-deleted) sources.
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => 0,
                'delete_time' => null,
            ];
        });
    }

    /**
     * Configure the factory with custom client and source details.
     *
     * @param int $clientId
     * @param string $name
     * @param bool $isDefault
     * @return static
     */
    public function custom(int $clientId, string $name, bool $isDefault = false): static
    {
        return $this->state(function (array $attributes) use ($clientId, $name, $isDefault) {
            return [
                'client_id' => $clientId,
                'name' => $name,
                'is_default' => $isDefault ? 1 : 0,
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
     * Configure the factory to create all three default expense sources as a sequence.
     * Useful for testing scenarios that need the three auto-created default sources.
     *
     * @return static
     */
    public function defaultSequence(): static
    {
        return $this->sequence(
            ['name' => 'Cash', 'is_default' => 1],
            ['name' => 'Corporate Card', 'is_default' => 1],
            ['name' => 'Personal Card', 'is_default' => 1]
        );
    }

    /**
     * Configure the factory to create a complete expense source configuration.
     * 
     * @param int $clientId
     * @param string $name
     * @param bool $isDefault
     * @param string|null $uuid
     * @return static
     */
    public function complete(int $clientId, string $name, bool $isDefault = false, ?string $uuid = null): static
    {
        return $this->state(function (array $attributes) use ($clientId, $name, $isDefault, $uuid) {
            return [
                'uuid' => $uuid ?: Str::uuid()->toString(),
                'client_id' => $clientId,
                'name' => $name,
                'is_default' => $isDefault ? 1 : 0,
                'deleted' => 0,
                'delete_time' => null,
            ];
        });
    }
}