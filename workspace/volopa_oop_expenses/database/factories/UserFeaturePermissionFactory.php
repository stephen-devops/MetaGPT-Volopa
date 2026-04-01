<?php

namespace Database\Factories;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserFeaturePermission>
 */
class UserFeaturePermissionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = UserFeaturePermission::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'feature_id' => function () {
                // TODO: Replace with actual feature_id reference when features table structure is confirmed
                // For now, assuming OOP Expense feature has id = 16 based on system constraints
                return 16;
            },
            'grantor_id' => User::factory(),
            'manager_user_id' => function () {
                return $this->faker->boolean(70) ? User::factory() : null;
            },
            'is_enabled' => $this->faker->boolean(85),
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Indicate that the permission is enabled.
     *
     * @return static
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
        ]);
    }

    /**
     * Indicate that the permission is disabled.
     *
     * @return static
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }

    /**
     * Indicate that the permission has a manager.
     *
     * @return static
     */
    public function withManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_user_id' => User::factory(),
        ]);
    }

    /**
     * Indicate that the permission has no manager.
     *
     * @return static
     */
    public function withoutManager(): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_user_id' => null,
        ]);
    }

    /**
     * Create a permission for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @param int|null $grantorId
     * @return static
     */
    public function forUser(int $userId, int $clientId, int $grantorId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'grantor_id' => $grantorId ?? User::factory(),
        ]);
    }

    /**
     * Create a permission for OOP Expense feature specifically.
     *
     * @return static
     */
    public function oopExpenseFeature(): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => 16, // OOP Expense feature ID as per system constraints
        ]);
    }
}