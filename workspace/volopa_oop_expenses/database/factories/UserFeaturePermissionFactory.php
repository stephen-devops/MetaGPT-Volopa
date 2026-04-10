<?php

namespace Database\Factories;

use App\Models\UserFeaturePermission;
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
            'user_id' => 1, // Default to user ID 1, should be overridden in tests with actual user
            'client_id' => 1, // Default to client ID 1, should be overridden in tests with actual client
            'feature_id' => 16, // OOP Expense feature ID as per constraints
            'grantor_id' => 1, // Default to user ID 1 as grantor, should be overridden in tests
            'manager_user_id' => 1, // Default to user ID 1 as manager, should be overridden in tests
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
     * Set the user for this permission.
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
     * Set the client for this permission.
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
     * Set the feature for this permission.
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
     * Set the grantor (user who grants the permission).
     *
     * @param int $grantorId
     * @return static
     */
    public function grantedBy(int $grantorId): static
    {
        return $this->state(fn (array $attributes) => [
            'grantor_id' => $grantorId,
        ]);
    }

    /**
     * Set the manager user (user being managed).
     *
     * @param int $managerUserId
     * @return static
     */
    public function managing(int $managerUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_user_id' => $managerUserId,
        ]);
    }
}