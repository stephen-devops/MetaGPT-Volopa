<?php

namespace Database\Factories;

use App\Models\PocketExpenseMetadata;
use App\Models\PocketExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PocketExpenseMetadata>
 */
class PocketExpenseMetadataFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PocketExpenseMetadata::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $metadataTypes = [
            'category',
            'tracking_code_type_1',
            'tracking_code_type_2',
            'project',
            'additional_field',
            'file',
            'expense_source'
        ];

        return [
            'pocket_expense_id' => PocketExpense::factory(),
            'metadata_type' => $this->faker->randomElement($metadataTypes),
            'transaction_category_id' => $this->faker->optional(0.3)->passthrough($this->faker->numberBetween(1, 100)),
            'tracking_code_id' => $this->faker->optional(0.2)->passthrough($this->faker->numberBetween(1, 100)),
            'project_id' => $this->faker->optional(0.2)->passthrough($this->faker->numberBetween(1, 100)),
            'file_store_id' => $this->faker->optional(0.1)->passthrough($this->faker->numberBetween(1, 100)),
            'expense_source_id' => $this->faker->optional(0.4)->passthrough($this->faker->numberBetween(1, 100)),
            'additional_field_id' => $this->faker->optional(0.1)->passthrough($this->faker->numberBetween(1, 100)),
            'user_id' => $this->faker->optional(0.3)->passthrough(User::factory()),
            'details_json' => $this->faker->optional(0.4)->passthrough([
                'description' => $this->faker->sentence,
                'reference' => $this->faker->optional()->word,
                'custom_data' => $this->faker->optional()->words(3, true)
            ]),
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => now(),
        ];
    }

    /**
     * Indicate that the metadata is for category type.
     *
     * @return static
     */
    public function category(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'category',
            'transaction_category_id' => $this->faker->numberBetween(1, 100),
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is for tracking code type 1.
     *
     * @return static
     */
    public function trackingCodeType1(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'tracking_code_type_1',
            'tracking_code_id' => $this->faker->numberBetween(1, 100),
            'transaction_category_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is for tracking code type 2.
     *
     * @return static
     */
    public function trackingCodeType2(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'tracking_code_type_2',
            'tracking_code_id' => $this->faker->numberBetween(1, 100),
            'transaction_category_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is for project type.
     *
     * @return static
     */
    public function project(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'project',
            'project_id' => $this->faker->numberBetween(1, 100),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is for additional field type.
     *
     * @return static
     */
    public function additionalField(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'additional_field',
            'additional_field_id' => $this->faker->numberBetween(1, 100),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is for file type.
     *
     * @return static
     */
    public function file(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'file',
            'file_store_id' => $this->faker->numberBetween(1, 100),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is for expense source type.
     *
     * @return static
     */
    public function expenseSource(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'expense_source',
            'expense_source_id' => $this->faker->numberBetween(1, 100),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'additional_field_id' => null,
        ]);
    }

    /**
     * Indicate that the metadata is soft deleted.
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
     * Create metadata for a specific pocket expense.
     *
     * @param int $pocketExpenseId
     * @return static
     */
    public function forExpense(int $pocketExpenseId): static
    {
        return $this->state(fn (array $attributes) => [
            'pocket_expense_id' => $pocketExpenseId,
        ]);
    }

    /**
     * Create metadata with specific details JSON.
     *
     * @param array $details
     * @return static
     */
    public function withDetails(array $details): static
    {
        return $this->state(fn (array $attributes) => [
            'details_json' => $details,
        ]);
    }

    /**
     * Create metadata with specific user.
     *
     * @param int $userId
     * @return static
     */
    public function withUser(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    /**
     * Create metadata with specific metadata type and ID.
     *
     * @param string $metadataType
     * @param int $referenceId
     * @return static
     */
    public function withTypeAndReference(string $metadataType, int $referenceId): static
    {
        $fieldMapping = [
            'category' => 'transaction_category_id',
            'tracking_code_type_1' => 'tracking_code_id',
            'tracking_code_type_2' => 'tracking_code_id',
            'project' => 'project_id',
            'additional_field' => 'additional_field_id',
            'file' => 'file_store_id',
            'expense_source' => 'expense_source_id',
        ];

        $state = [
            'metadata_type' => $metadataType,
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
        ];

        if (isset($fieldMapping[$metadataType])) {
            $state[$fieldMapping[$metadataType]] = $referenceId;
        }

        return $this->state(fn (array $attributes) => $state);
    }
}