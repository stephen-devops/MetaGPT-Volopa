<?php

namespace Database\Factories;

use App\Models\PocketExpenseMetadata;
use App\Models\PocketExpense;
use App\Models\PocketExpenseSourceClientConfig;
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
        // Available metadata types as per enum constraint
        $metadataTypes = [
            'category',
            'tracking_code_type_1',
            'tracking_code_type_2', 
            'project',
            'additional_field',
            'file',
            'expense_source'
        ];

        $metadataType = $this->faker->randomElement($metadataTypes);

        // Generate contextual details_json based on metadata type
        $detailsJson = $this->generateDetailsJson($metadataType);

        return [
            'pocket_expense_id' => PocketExpense::factory(),
            'metadata_type' => $metadataType,
            'transaction_category_id' => $this->faker->optional(0.4)->numberBetween(1, 50),
            'tracking_code_id' => $this->faker->optional(0.3)->numberBetween(1, 100),
            'project_id' => $this->faker->optional(0.3)->numberBetween(1, 25),
            'file_store_id' => $this->faker->optional(0.2)->numberBetween(1, 1000),
            'expense_source_id' => $this->faker->optional(0.6)->numberBetween(1, 20),
            'additional_field_id' => $this->faker->optional(0.2)->numberBetween(1, 15),
            'user_id' => User::factory(),
            'details_json' => $detailsJson,
            'create_time' => now(),
            'update_time' => now(),
            'deleted' => false,
            'delete_time' => null,
        ];
    }

    /**
     * Generate contextual details_json based on metadata type.
     *
     * @param string $metadataType
     * @return array|null
     */
    private function generateDetailsJson(string $metadataType): ?array
    {
        return match ($metadataType) {
            'category' => [
                'category_name' => $this->faker->randomElement(['Travel', 'Meals', 'Office Supplies', 'Transportation', 'Entertainment']),
                'category_code' => strtoupper($this->faker->lexify('???')),
                'subcategory' => $this->faker->optional(0.6)->word,
            ],
            'tracking_code_type_1', 'tracking_code_type_2' => [
                'code' => $this->faker->bothify('TC-####-???'),
                'description' => $this->faker->sentence(4),
                'department' => $this->faker->randomElement(['Finance', 'Marketing', 'Operations', 'HR', 'IT']),
            ],
            'project' => [
                'project_name' => $this->faker->catchPhrase(),
                'project_code' => $this->faker->bothify('PROJ-####'),
                'phase' => $this->faker->randomElement(['Planning', 'Development', 'Testing', 'Deployment', 'Maintenance']),
                'budget_code' => $this->faker->bothify('BUD-####'),
            ],
            'additional_field' => [
                'field_name' => $this->faker->randomElement(['Cost Center', 'GL Account', 'Department Code', 'Approval Level']),
                'field_value' => $this->faker->bothify('???-####'),
                'field_type' => $this->faker->randomElement(['text', 'number', 'select', 'date']),
            ],
            'file' => [
                'file_name' => $this->faker->word . '.' . $this->faker->randomElement(['pdf', 'jpg', 'png', 'doc', 'xls']),
                'file_size' => $this->faker->numberBetween(1024, 5242880), // 1KB to 5MB
                'mime_type' => $this->faker->randomElement(['application/pdf', 'image/jpeg', 'image/png', 'application/msword']),
                'upload_date' => now()->subDays($this->faker->numberBetween(0, 30))->toISOString(),
            ],
            'expense_source' => [
                'source_note' => $this->faker->optional(0.8)->sentence(6),
                'reference_number' => $this->faker->optional(0.5)->bothify('REF-########'),
                'external_id' => $this->faker->optional(0.3)->uuid,
            ],
            default => null,
        };
    }

    /**
     * Create metadata for an existing pocket expense.
     *
     * @param int $pocketExpenseId
     * @param int $userId
     * @return static
     */
    public function forExpense(int $pocketExpenseId, int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'pocket_expense_id' => $pocketExpenseId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Create metadata with a specific type.
     *
     * @param string $metadataType
     * @return static
     */
    public function withType(string $metadataType): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => $metadataType,
            'details_json' => $this->generateDetailsJson($metadataType),
        ]);
    }

    /**
     * Create category metadata.
     *
     * @return static
     */
    public function category(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'category',
            'transaction_category_id' => $this->faker->numberBetween(1, 50),
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
            'details_json' => $this->generateDetailsJson('category'),
        ]);
    }

    /**
     * Create tracking code type 1 metadata.
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
            'details_json' => $this->generateDetailsJson('tracking_code_type_1'),
        ]);
    }

    /**
     * Create tracking code type 2 metadata.
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
            'details_json' => $this->generateDetailsJson('tracking_code_type_2'),
        ]);
    }

    /**
     * Create project metadata.
     *
     * @return static
     */
    public function project(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'project',
            'project_id' => $this->faker->numberBetween(1, 25),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
            'details_json' => $this->generateDetailsJson('project'),
        ]);
    }

    /**
     * Create additional field metadata.
     *
     * @return static
     */
    public function additionalField(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'additional_field',
            'additional_field_id' => $this->faker->numberBetween(1, 15),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'details_json' => $this->generateDetailsJson('additional_field'),
        ]);
    }

    /**
     * Create file metadata.
     *
     * @return static
     */
    public function file(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'file',
            'file_store_id' => $this->faker->numberBetween(1, 1000),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
            'details_json' => $this->generateDetailsJson('file'),
        ]);
    }

    /**
     * Create expense source metadata.
     *
     * @return static
     */
    public function expenseSource(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'expense_source',
            'expense_source_id' => PocketExpenseSourceClientConfig::factory(),
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'additional_field_id' => null,
            'details_json' => $this->generateDetailsJson('expense_source'),
        ]);
    }

    /**
     * Create expense source metadata with existing source.
     *
     * @param int $expenseSourceId
     * @return static
     */
    public function withExpenseSource(int $expenseSourceId): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'expense_source',
            'expense_source_id' => $expenseSourceId,
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'additional_field_id' => null,
            'details_json' => $this->generateDetailsJson('expense_source'),
        ]);
    }

    /**
     * Create expense source metadata for 'Other' source with required source note.
     *
     * @return static
     */
    public function otherSource(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'expense_source',
            'details_json' => [
                'source_note' => $this->faker->sentence(8), // Required when source = Other
                'reference_number' => $this->faker->optional(0.3)->bothify('REF-########'),
                'external_id' => $this->faker->optional(0.2)->uuid,
            ],
        ]);
    }

    /**
     * Create metadata with specific details_json.
     *
     * @param array $detailsJson
     * @return static
     */
    public function withDetails(array $detailsJson): static
    {
        return $this->state(fn (array $attributes) => [
            'details_json' => $detailsJson,
        ]);
    }

    /**
     * Create a soft-deleted metadata record.
     *
     * @return static
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => true,
            'delete_time' => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }

    /**
     * Create an active metadata record (not deleted).
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted' => false,
            'delete_time' => null,
        ]);
    }

    /**
     * Create metadata with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $createTime
     * @param \Carbon\Carbon|string|null $updateTime
     * @return static
     */
    public function withTimestamps($createTime = null, $updateTime = null): static
    {
        return $this->state(fn (array $attributes) => [
            'create_time' => $createTime ?? now()->subDays($this->faker->numberBetween(1, 30)),
            'update_time' => $updateTime ?? now()->subDays($this->faker->numberBetween(0, 10)),
        ]);
    }

    /**
     * Create metadata with all foreign key references populated.
     *
     * @return static
     */
    public function withAllReferences(): static
    {
        return $this->state(fn (array $attributes) => [
            'transaction_category_id' => $this->faker->numberBetween(1, 50),
            'tracking_code_id' => $this->faker->numberBetween(1, 100),
            'project_id' => $this->faker->numberBetween(1, 25),
            'file_store_id' => $this->faker->numberBetween(1, 1000),
            'expense_source_id' => $this->faker->numberBetween(1, 20),
            'additional_field_id' => $this->faker->numberBetween(1, 15),
        ]);
    }

    /**
     * Create metadata with no foreign key references (minimal).
     *
     * @return static
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes) => [
            'transaction_category_id' => null,
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
            'details_json' => null,
        ]);
    }

    /**
     * Create multiple metadata records for the same pocket expense.
     *
     * @param int $pocketExpenseId
     * @param int $userId
     * @param array $metadataTypes
     * @return static
     */
    public function multipleForExpense(int $pocketExpenseId, int $userId, array $metadataTypes = ['category', 'expense_source']): static
    {
        return $this->state(function (array $attributes) use ($pocketExpenseId, $userId, $metadataTypes) {
            $metadataType = $this->faker->randomElement($metadataTypes);
            return [
                'pocket_expense_id' => $pocketExpenseId,
                'user_id' => $userId,
                'metadata_type' => $metadataType,
                'details_json' => $this->generateDetailsJson($metadataType),
            ];
        });
    }

    /**
     * Create metadata for CSV upload context.
     *
     * @return static
     */
    public function fromCsv(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'expense_source',
            'details_json' => [
                'source_note' => 'Imported from CSV upload on ' . now()->format('Y-m-d H:i:s'),
                'import_source' => 'csv_upload',
                'reference_number' => $this->faker->optional(0.4)->bothify('CSV-########'),
            ],
        ]);
    }

    /**
     * Create metadata that represents a receipt attachment.
     *
     * @return static
     */
    public function receiptAttachment(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'file',
            'file_store_id' => $this->faker->numberBetween(1, 1000),
            'details_json' => [
                'file_name' => 'receipt_' . $this->faker->dateTime()->format('Ymd_His') . '.pdf',
                'file_size' => $this->faker->numberBetween(50000, 2000000), // 50KB to 2MB
                'mime_type' => 'application/pdf',
                'upload_date' => now()->subDays($this->faker->numberBetween(0, 7))->toISOString(),
                'file_type' => 'receipt',
                'ocr_processed' => $this->faker->boolean(60),
            ],
        ]);
    }

    /**
     * Create metadata for expense approval workflow.
     *
     * @return static
     */
    public function approvalWorkflow(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'additional_field',
            'additional_field_id' => $this->faker->numberBetween(1, 15),
            'details_json' => [
                'field_name' => 'Approval Workflow',
                'approval_level' => $this->faker->numberBetween(1, 3),
                'requires_manager_approval' => $this->faker->boolean(70),
                'requires_finance_approval' => $this->faker->boolean(30),
                'auto_approve_limit' => $this->faker->optional(0.4)->randomFloat(2, 0, 500.00),
            ],
        ]);
    }

    /**
     * Create metadata for travel-related expenses.
     *
     * @return static
     */
    public function travel(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata_type' => 'category',
            'transaction_category_id' => $this->faker->numberBetween(1, 50),
            'details_json' => [
                'category_name' => 'Travel',
                'category_code' => 'TRV',
                'subcategory' => $this->faker->randomElement(['Accommodation', 'Transportation', 'Meals', 'Incidentals']),
                'trip_purpose' => $this->faker->randomElement(['Business Meeting', 'Conference', 'Training', 'Client Visit']),
                'destination' => $this->faker->city . ', ' . $this->faker->country,
                'trip_dates' => [
                    'start_date' => now()->subDays($this->faker->numberBetween(1, 30))->format('Y-m-d'),
                    'end_date' => now()->subDays($this->faker->numberBetween(0, 20))->format('Y-m-d'),
                ],
            ],
        ]);
    }

    /**
     * Create a complete set of metadata for a fully-featured expense.
     * Creates multiple metadata records for different aspects of the expense.
     *
     * @param int $pocketExpenseId
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createCompleteSet(int $pocketExpenseId, int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return collect([
            $this->forExpense($pocketExpenseId, $userId)->category()->create(),
            $this->forExpense($pocketExpenseId, $userId)->expenseSource()->create(),
            $this->forExpense($pocketExpenseId, $userId)->receiptAttachment()->create(),
            $this->forExpense($pocketExpenseId, $userId)->trackingCodeType1()->create(),
        ]);
    }

    /**
     * Create metadata with realistic business context.
     *
     * @return static
     */
    public function businessContext(): static
    {
        $businessScenarios = [
            [
                'type' => 'category',
                'details' => [
                    'category_name' => 'Client Entertainment',
                    'category_code' => 'ENT',
                    'subcategory' => 'Business Dinner',
                    'attendees_count' => $this->faker->numberBetween(2, 8),
                    'client_name' => $this->faker->company,
                ],
            ],
            [
                'type' => 'project',
                'details' => [
                    'project_name' => 'Q4 Product Launch',
                    'project_code' => 'PROJ-Q4PL',
                    'phase' => 'Marketing Campaign',
                    'budget_code' => 'BUD-2024-MKT',
                    'project_manager' => $this->faker->name,
                ],
            ],
            [
                'type' => 'additional_field',
                'details' => [
                    'field_name' => 'Cost Center',
                    'field_value' => 'CC-' . $this->faker->numberBetween(1000, 9999),
                    'department' => $this->faker->randomElement(['Sales', 'Marketing', 'Engineering', 'Operations']),
                    'gl_account' => 'GL-' . $this->faker->numerify('####'),
                ],
            ],
        ];

        $scenario = $this->faker->randomElement($businessScenarios);

        return $this->state(fn (array $attributes) => [
            'metadata_type' => $scenario['type'],
            'details_json' => $scenario['details'],
        ]);
    }

    /**
     * Create metadata for testing validation scenarios.
     *
     * @return static
     */
    public function forValidationTesting(): static
    {
        return $this->state(fn (array $attributes) => [
            'details_json' => [
                'test_field' => $this->faker->word,
                'numeric_field' => $this->faker->numberBetween(1, 1000),
                'boolean_field' => $this->faker->boolean,
                'date_field' => $this->faker->dateTime->format('Y-m-d H:i:s'),
                'array_field' => $this->faker->words(3),
                'nested_object' => [
                    'sub_field_1' => $this->faker->sentence,
                    'sub_field_2' => $this->faker->randomDigit,
                ],
            ],
        ]);
    }
}