<?php

namespace Database\Factories;

use App\Models\PocketExpenseMetadata;
use App\Models\PocketExpense;
use App\Models\PocketExpenseSourceClientConfig;
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
            'transaction_category',
            'tracking_code',
            'project',
            'file_store',
            'expense_source',
            'additional_field',
            'source_note'
        ];

        return [
            'pocket_expense_id' => function () {
                return PocketExpense::factory()->create()->id;
            },
            'metadata_type' => $this->faker->randomElement($metadataTypes),
            'transaction_category_id' => $this->faker->optional(0.3)->randomNumber(),
            'tracking_code_id' => $this->faker->optional(0.2)->randomNumber(),
            'project_id' => $this->faker->optional(0.4)->randomNumber(),
            'file_store_id' => $this->faker->optional(0.1)->randomNumber(),
            'expense_source_id' => function () {
                return $this->faker->optional(0.6)->passthrough(
                    PocketExpenseSourceClientConfig::factory()->create()->id
                );
            },
            'additional_field_id' => $this->faker->optional(0.1)->randomNumber(),
            'user_id' => function () {
                return $this->faker->optional(0.5)->passthrough(
                    \App\Models\User::factory()->create()->id
                );
            },
            'details_json' => $this->faker->optional(0.7)->passthrough(
                json_encode([
                    'description' => $this->faker->sentence(),
                    'reference' => $this->faker->optional()->word(),
                    'additional_info' => $this->faker->optional()->text(100)
                ])
            ),
            'create_time' => now(),
            'update_time' => now(),
            'deleted' => false,
            'delete_time' => null,
        ];
    }

    /**
     * Indicate that the metadata is for transaction category.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function transactionCategory(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'transaction_category',
                'transaction_category_id' => $this->faker->numberBetween(1, 100),
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ];
        });
    }

    /**
     * Indicate that the metadata is for tracking code.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function trackingCode(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'tracking_code',
                'transaction_category_id' => null,
                'tracking_code_id' => $this->faker->numberBetween(1, 50),
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ];
        });
    }

    /**
     * Indicate that the metadata is for project.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function project(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'project',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => $this->faker->numberBetween(1, 200),
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ];
        });
    }

    /**
     * Indicate that the metadata is for file store.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function fileStore(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'file_store',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => $this->faker->numberBetween(1, 1000),
                'expense_source_id' => null,
                'additional_field_id' => null,
            ];
        });
    }

    /**
     * Indicate that the metadata is for expense source.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function expenseSource(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'expense_source',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => function () {
                    return PocketExpenseSourceClientConfig::factory()->create()->id;
                },
                'additional_field_id' => null,
            ];
        });
    }

    /**
     * Indicate that the metadata is for source note.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function sourceNote(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'source_note',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'details_json' => json_encode([
                    'note' => $this->faker->sentence(),
                    'required_when_source_other' => true
                ]),
            ];
        });
    }

    /**
     * Indicate that the metadata is for additional field.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function additionalField(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'additional_field',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => $this->faker->numberBetween(1, 100),
            ];
        });
    }

    /**
     * Indicate that the metadata is soft deleted.
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
     * Create metadata for specific pocket expense.
     *
     * @param int $pocketExpenseId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forExpense(int $pocketExpenseId): Factory
    {
        return $this->state(function (array $attributes) use ($pocketExpenseId) {
            return [
                'pocket_expense_id' => $pocketExpenseId,
            ];
        });
    }

    /**
     * Create metadata with specific details JSON.
     *
     * @param array $details
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withDetails(array $details): Factory
    {
        return $this->state(function (array $attributes) use ($details) {
            return [
                'details_json' => json_encode($details),
            ];
        });
    }

    /**
     * Create metadata with specific user.
     *
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forUser(int $userId): Factory
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'user_id' => $userId,
            ];
        });
    }

    /**
     * Create metadata with specific metadata type and related ID.
     *
     * @param string $metadataType
     * @param int|null $relatedId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withTypeAndId(string $metadataType, ?int $relatedId = null): Factory
    {
        return $this->state(function (array $attributes) use ($metadataType, $relatedId) {
            $state = [
                'metadata_type' => $metadataType,
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ];

            if ($relatedId !== null) {
                switch ($metadataType) {
                    case 'transaction_category':
                        $state['transaction_category_id'] = $relatedId;
                        break;
                    case 'tracking_code':
                        $state['tracking_code_id'] = $relatedId;
                        break;
                    case 'project':
                        $state['project_id'] = $relatedId;
                        break;
                    case 'file_store':
                        $state['file_store_id'] = $relatedId;
                        break;
                    case 'expense_source':
                        $state['expense_source_id'] = $relatedId;
                        break;
                    case 'additional_field':
                        $state['additional_field_id'] = $relatedId;
                        break;
                }
            }

            return $state;
        });
    }
}