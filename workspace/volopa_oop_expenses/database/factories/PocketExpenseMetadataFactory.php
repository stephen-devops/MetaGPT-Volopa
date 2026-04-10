<?php

namespace Database\Factories;

use App\Models\PocketExpenseMetadata;
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
        // Available metadata types from ENUM constraints
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
            'pocket_expense_id' => 1, // Default to pocket expense ID 1, should be overridden in tests
            'metadata_type' => $this->faker->randomElement($metadataTypes),
            'transaction_category_id' => null, // Optional FK, can be set via state methods
            'tracking_code_id' => null, // Optional FK, can be set via state methods
            'project_id' => null, // Optional FK, can be set via state methods
            'file_store_id' => null, // Optional FK, can be set via state methods
            'expense_source_id' => null, // Optional FK, can be set via state methods
            'additional_field_id' => null, // Optional FK, can be set via state methods
            'user_id' => 1, // Default to user ID 1, should be overridden in tests
            'details_json' => null, // Optional JSON field for additional metadata
            'create_time' => now(),
            'update_time' => null,
            'deleted' => 0, // Active by default
            'delete_time' => null,
        ];
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
     * Set the pocket expense for this metadata.
     *
     * @param int $pocketExpenseId
     * @return static
     */
    public function forPocketExpense(int $pocketExpenseId): static
    {
        return $this->state(fn (array $attributes) => [
            'pocket_expense_id' => $pocketExpenseId,
        ]);
    }

    /**
     * Set the user for this metadata.
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
     * Create category metadata.
     *
     * @param int|null $transactionCategoryId
     * @return static
     */
    public function category(int $transactionCategoryId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'category',
            'transaction_category_id' => $transactionCategoryId,
        ]);
    }

    /**
     * Create tracking code type 1 metadata.
     *
     * @param int|null $trackingCodeId
     * @return static
     */
    public function trackingCodeType1(int $trackingCodeId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'tracking_code_type_1',
            'tracking_code_id' => $trackingCodeId,
        ]);
    }

    /**
     * Create tracking code type 2 metadata.
     *
     * @param int|null $trackingCodeId
     * @return static
     */
    public function trackingCodeType2(int $trackingCodeId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'tracking_code_type_2',
            'tracking_code_id' => $trackingCodeId,
        ]);
    }

    /**
     * Create project metadata.
     *
     * @param int|null $projectId
     * @return static
     */
    public function project(int $projectId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'project',
            'project_id' => $projectId,
        ]);
    }

    /**
     * Create file metadata.
     *
     * @param int|null $fileStoreId
     * @return static
     */
    public function file(int $fileStoreId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'file',
            'file_store_id' => $fileStoreId,
        ]);
    }

    /**
     * Create expense source metadata.
     *
     * @param int|null $expenseSourceId
     * @return static
     */
    public function expenseSource(int $expenseSourceId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'expense_source',
            'expense_source_id' => $expenseSourceId,
        ]);
    }

    /**
     * Create additional field metadata.
     *
     * @param int|null $additionalFieldId
     * @return static
     */
    public function additionalField(int $additionalFieldId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'additional_field',
            'additional_field_id' => $additionalFieldId,
        ]);
    }

    /**
     * Set JSON details for this metadata.
     *
     * @param array $details
     * @return static
     */
    public function withDetails(array $details): static
    {
        return $this->state(fn (array $attributes) => [
            'details_json' => json_encode($details),
        ]);
    }

    /**
     * Set the metadata type.
     *
     * @param string $metadataType
     * @return static
     */
    public function withType(string $metadataType): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => $metadataType,
        ]);
    }

    /**
     * Set the metadata as updated.
     *
     * @return static
     */
    public function updated(): static
    {
        return $this->state(fn (array $attributes) => [
            'update_time' => now(),
        ]);
    }

    /**
     * Create metadata with transaction category reference.
     *
     * @param int $transactionCategoryId
     * @return static
     */
    public function withTransactionCategory(int $transactionCategoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'transaction_category_id' => $transactionCategoryId,
        ]);
    }

    /**
     * Create metadata with tracking code reference.
     *
     * @param int $trackingCodeId
     * @return static
     */
    public function withTrackingCode(int $trackingCodeId): static
    {
        return $this->state(fn (array $attributes) => [
            'tracking_code_id' => $trackingCodeId,
        ]);
    }

    /**
     * Create metadata with project reference.
     *
     * @param int $projectId
     * @return static
     */
    public function withProject(int $projectId): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $projectId,
        ]);
    }

    /**
     * Create metadata with file store reference.
     *
     * @param int $fileStoreId
     * @return static
     */
    public function withFileStore(int $fileStoreId): static
    {
        return $this->state(fn (array $attributes) => [
            'file_store_id' => $fileStoreId,
        ]);
    }

    /**
     * Create metadata with expense source reference.
     *
     * @param int $expenseSourceId
     * @return static
     */
    public function withExpenseSource(int $expenseSourceId): static
    {
        return $this->state(fn (array $attributes) => [
            'expense_source_id' => $expenseSourceId,
        ]);
    }

    /**
     * Create metadata with additional field reference.
     *
     * @param int $additionalFieldId
     * @return static
     */
    public function withAdditionalField(int $additionalFieldId): static
    {
        return $this->state(fn (array $attributes) => [
            'additional_field_id' => $additionalFieldId,
        ]);
    }
}