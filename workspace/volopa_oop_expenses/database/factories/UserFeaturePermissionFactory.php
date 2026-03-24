<?php

namespace Database\Factories;

use App\Models\UserFeaturePermission;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'feature_id' => 16, // Default to OOP Expenses feature as per system constraints
            'grantor_id' => User::factory(),
            'manager_user_id' => User::factory(),
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Create a permission with existing user and client IDs.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $grantorId
     * @param int $managerId
     * @return static
     */
    public function forUser(int $userId, int $clientId, int $grantorId, int $managerId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'grantor_id' => $grantorId,
            'manager_user_id' => $managerId,
        ]);
    }

    /**
     * Create a permission for a specific feature.
     *
     * @param int $featureId
     * @return static
     */
    public function forFeature(int $featureId): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => $featureId,
        ]);
    }

    /**
     * Create a disabled permission.
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
     * Create a permission for OOP Expenses feature (feature_id = 16).
     *
     * @return static
     */
    public function forOopExpenses(): static
    {
        return $this->state(fn (array $attributes) => [
            'feature_id' => 16,
        ]);
    }

    /**
     * Create multiple permissions for the same user-client combination with different features.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $grantorId
     * @param int $managerId
     * @param array $featureIds
     * @return static
     */
    public function multipleFeatures(int $userId, int $clientId, int $grantorId, int $managerId, array $featureIds = [16]): static
    {
        return $this->state(function (array $attributes) use ($userId, $clientId, $grantorId, $managerId, $featureIds) {
            $featureId = $this->faker->randomElement($featureIds);
            return [
                'user_id' => $userId,
                'client_id' => $clientId,
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'feature_id' => $featureId,
            ];
        });
    }

    /**
     * Create a permission with the same user as both grantor and manager (self-granted).
     *
     * @param int $userId
     * @param int $clientId
     * @param int $adminId
     * @return static
     */
    public function selfGranted(int $userId, int $clientId, int $adminId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'grantor_id' => $adminId,
            'manager_user_id' => $adminId,
        ]);
    }

    /**
     * Create a permission with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $createdAt
     * @param \Carbon\Carbon|string|null $updatedAt
     * @return static
     */
    public function withTimestamps($createdAt = null, $updatedAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $createdAt ?? now()->subDays($this->faker->numberBetween(1, 30)),
            'updated_at' => $updatedAt ?? now()->subDays($this->faker->numberBetween(0, 10)),
        ]);
    }
}