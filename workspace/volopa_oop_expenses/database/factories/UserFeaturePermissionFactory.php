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
            'user_id' => function () {
                // TODO: Create or reference existing user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'client_id' => function () {
                // TODO: Create or reference existing client - depends on Client model factory
                return \App\Models\Client::factory()->create()->id;
            },
            'feature_id' => 16, // OOP Expense feature ID as per system constraints
            'grantor_id' => function () {
                // TODO: Create or reference existing grantor user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'manager_user_id' => function () {
                // TODO: Create or reference existing manager user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the permission is disabled.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function disabled(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'is_enabled' => false,
            ];
        });
    }

    /**
     * Create permission with specific feature ID.
     *
     * @param int $featureId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forFeature(int $featureId): Factory
    {
        return $this->state(function (array $attributes) use ($featureId) {
            return [
                'feature_id' => $featureId,
            ];
        });
    }

    /**
     * Create permission with specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forUserAndClient(int $userId, int $clientId): Factory
    {
        return $this->state(function (array $attributes) use ($userId, $clientId) {
            return [
                'user_id' => $userId,
                'client_id' => $clientId,
            ];
        });
    }

    /**
     * Create permission with specific grantor and manager.
     *
     * @param int $grantorId
     * @param int $managerId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function grantedBy(int $grantorId, int $managerId): Factory
    {
        return $this->state(function (array $attributes) use ($grantorId, $managerId) {
            return [
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
            ];
        });
    }
}