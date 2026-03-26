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
        $sourcesNames = [
            'Cash',
            'Corporate Card',
            'Personal Card',
            'Bank Transfer',
            'Credit Card',
            'Debit Card',
            'Mobile Payment',
            'Online Banking',
            'Check Payment',
            'Wire Transfer'
        ];

        return [
            'uuid' => Str::uuid()->toString(),
            'client_id' => function () {
                // TODO: Create or reference existing client - depends on Client model factory
                return \App\Models\Client::factory()->create()->id;
            },
            'name' => $this->faker->randomElement($sourcesNames),
            'is_default' => false,
            'deleted' => false,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Indicate that the expense source is marked as default.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function default(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'is_default' => true,
            ];
        });
    }

    /**
     * Indicate that the expense source is soft deleted.
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
     * Create expense source for specific client.
     *
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forClient(int $clientId): Factory
    {
        return $this->state(function (array $attributes) use ($clientId) {
            return [
                'client_id' => $clientId,
            ];
        });
    }

    /**
     * Create expense source with specific name.
     *
     * @param string $name
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withName(string $name): Factory
    {
        return $this->state(function (array $attributes) use ($name) {
            return [
                'name' => $name,
            ];
        });
    }

    /**
     * Create global 'Other' expense source (client_id = null).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function globalOther(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
            ];
        });
    }

    /**
     * Create Cash expense source (one of the 3 defaults).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function cash(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Cash',
                'is_default' => true,
            ];
        });
    }

    /**
     * Create Corporate Card expense source (one of the 3 defaults).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function corporateCard(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Corporate Card',
                'is_default' => true,
            ];
        });
    }

    /**
     * Create Personal Card expense source (one of the 3 defaults).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function personalCard(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Personal Card',
                'is_default' => true,
            ];
        });
    }

    /**
     * Create expense source with specific UUID.
     *
     * @param string $uuid
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withUuid(string $uuid): Factory
    {
        return $this->state(function (array $attributes) use ($uuid) {
            return [
                'uuid' => $uuid,
            ];
        });
    }
}