<?php

namespace Database\Factories;

use App\Models\UserFeaturePermission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for UserFeaturePermission model
 * 
 * Generates test data for user feature permissions with proper relationships
 * to users, clients, features, and management hierarchy.
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
        $now = Carbon::now();
        
        return [
            // Foreign key relationships - will be overridden in tests with specific IDs
            'user_id' => 1, // Default to user ID 1, override in tests
            'client_id' => 1, // Default to client ID 1, override in tests  
            'feature_id' => 16, // OOP Expense feature ID as per constraints
            'grantor_id' => 1, // Default to user ID 1 as grantor, override in tests
            'manager_user_id' => null, // Optional manager, can be set in tests
            
            // Permission state
            'is_enabled' => 1, // Default to enabled permission
            
            // Volopa legacy timestamp pattern
            'create_time' => $now,
            'update_time' => $now,
        ];
    }

    /**
     * Configure the factory for enabled permissions.
     *
     * @return static
     */
    public function enabled(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_enabled' => 1,
            ];
        });
    }

    /**
     * Configure the factory for disabled permissions.
     *
     * @return static
     */
    public function disabled(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_enabled' => 0,
            ];
        });
    }

    /**
     * Configure the factory with a specific user ID.
     *
     * @param int $userId
     * @return static
     */
    public function forUser(int $userId): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'user_id' => $userId,
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
     * Configure the factory with a specific feature ID.
     *
     * @param int $featureId
     * @return static
     */
    public function forFeature(int $featureId): static
    {
        return $this->state(function (array $attributes) use ($featureId) {
            return [
                'feature_id' => $featureId,
            ];
        });
    }

    /**
     * Configure the factory with a specific grantor ID.
     *
     * @param int $grantorId
     * @return static
     */
    public function grantedBy(int $grantorId): static
    {
        return $this->state(function (array $attributes) use ($grantorId) {
            return [
                'grantor_id' => $grantorId,
            ];
        });
    }

    /**
     * Configure the factory with a specific manager user ID.
     *
     * @param int $managerUserId
     * @return static
     */
    public function managedBy(int $managerUserId): static
    {
        return $this->state(function (array $attributes) use ($managerUserId) {
            return [
                'manager_user_id' => $managerUserId,
            ];
        });
    }

    /**
     * Configure the factory without a manager (null manager_user_id).
     *
     * @return static
     */
    public function withoutManager(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'manager_user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory for OOP Expense feature specifically.
     *
     * @return static
     */
    public function oopExpenseFeature(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'feature_id' => 16, // OOP Expense feature ID as per constraints
            ];
        });
    }

    /**
     * Configure the factory with a complete permission scenario.
     * 
     * @param int $userId
     * @param int $clientId
     * @param int $grantorId
     * @param int|null $managerId
     * @return static
     */
    public function completePermission(int $userId, int $clientId, int $grantorId, ?int $managerId = null): static
    {
        return $this->state(function (array $attributes) use ($userId, $clientId, $grantorId, $managerId) {
            return [
                'user_id' => $userId,
                'client_id' => $clientId,
                'feature_id' => 16, // OOP Expense
                'grantor_id' => $grantorId,
                'manager_user_id' => $managerId,
                'is_enabled' => 1,
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
}