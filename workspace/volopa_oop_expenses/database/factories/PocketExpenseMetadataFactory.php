<?php

namespace Database\Factories;

use App\Models\PocketExpenseMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for PocketExpenseMetadata model
 * 
 * Generates test data for pocket expense metadata with proper relationships
 * to pocket expenses and various reference data tables. Handles all metadata
 * types including category, tracking codes, projects, files, expense sources,
 * and additional fields with appropriate JSON details.
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
        $now = Carbon::now();
        
        return [
            // Foreign key to parent expense - default to ID 1, override in tests
            'pocket_expense_id' => 1,
            
            // Default metadata type - most common is category
            'metadata_type' => 'category',
            
            // Optional foreign key relationships - only one should be populated per record
            'transaction_category_id' => 1, // Default category ID, override in tests
            'tracking_code_id' => null,
            'project_id' => null,
            'file_store_id' => null,
            'expense_source_id' => null,
            'additional_field_id' => null,
            'user_id' => null,
            
            // JSON field for flexible metadata storage
            'details_json' => null,
            
            // Volopa legacy timestamp pattern
            'create_time' => $now,
            'update_time' => $now,
            
            // Active record (not soft deleted)
            'deleted' => 0,
            'delete_time' => null,
        ];
    }

    /**
     * Configure the factory for category metadata type.
     *
     * @return static
     */
    public function category(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'category',
                'transaction_category_id' => 1, // Default category, override in tests
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'category_name' => 'Business Travel',
                    'category_code' => 'BT001',
                    'description' => 'Travel expenses for business purposes'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for tracking_code_type_1 metadata type.
     *
     * @return static
     */
    public function trackingCodeType1(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'tracking_code_type_1',
                'transaction_category_id' => null,
                'tracking_code_id' => 1, // Default tracking code, override in tests
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'tracking_type' => 'Department',
                    'tracking_code' => 'DEPT-001',
                    'tracking_unit' => 'Marketing Department',
                    'description' => 'Marketing department cost allocation'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for tracking_code_type_2 metadata type.
     *
     * @return static
     */
    public function trackingCodeType2(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'tracking_code_type_2',
                'transaction_category_id' => null,
                'tracking_code_id' => 1, // Default tracking code, override in tests
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'tracking_type' => 'Cost Centre',
                    'tracking_code' => 'CC-001',
                    'tracking_unit' => 'Operations Cost Centre',
                    'description' => 'Operations cost centre allocation'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for project metadata type.
     *
     * @return static
     */
    public function project(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'project',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => 1, // Default project, override in tests
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'project_name' => 'Digital Transformation Initiative',
                    'project_code' => 'DTI-2024',
                    'project_phase' => 'Implementation',
                    'description' => 'Expense related to digital transformation project'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for additional_field metadata type.
     *
     * @return static
     */
    public function additionalField(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'additional_field',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => 1, // Default additional field, override in tests
                'user_id' => null,
                'details_json' => json_encode([
                    'field_label' => 'Purpose of Expense',
                    'field_type' => 'text',
                    'field_value' => 'Client meeting expenses',
                    'is_required' => true,
                    'description' => 'Additional context for expense purpose'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for file metadata type.
     *
     * @return static
     */
    public function file(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'file',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => 1, // Default file store, override in tests
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'file_type' => 'receipt',
                    'file_name' => 'receipt_2024_001.pdf',
                    'file_size' => 245678,
                    'file_extension' => 'pdf',
                    'upload_timestamp' => Carbon::now()->toISOString(),
                    'description' => 'Receipt attachment for expense verification'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for expense_source metadata type.
     *
     * @return static
     */
    public function expenseSource(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'metadata_type' => 'expense_source',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => 1, // Default expense source, override in tests
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'source_name' => 'Corporate Card',
                    'source_type' => 'card',
                    'is_default_source' => true,
                    'source_note' => null,
                    'description' => 'Expense paid using corporate credit card'
                ]),
            ];
        });
    }

    /**
     * Configure the factory with a specific pocket expense ID.
     *
     * @param int $pocketExpenseId
     * @return static
     */
    public function forExpense(int $pocketExpenseId): static
    {
        return $this->state(function (array $attributes) use ($pocketExpenseId) {
            return [
                'pocket_expense_id' => $pocketExpenseId,
            ];
        });
    }

    /**
     * Configure the factory with a specific metadata type.
     *
     * @param string $metadataType
     * @return static
     */
    public function withType(string $metadataType): static
    {
        return $this->state(function (array $attributes) use ($metadataType) {
            return [
                'metadata_type' => $metadataType,
            ];
        });
    }

    /**
     * Configure the factory with a specific transaction category ID.
     *
     * @param int $categoryId
     * @return static
     */
    public function withCategory(int $categoryId): static
    {
        return $this->state(function (array $attributes) use ($categoryId) {
            return [
                'metadata_type' => 'category',
                'transaction_category_id' => $categoryId,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a specific tracking code ID.
     *
     * @param int $trackingCodeId
     * @param string $trackingType Either 'tracking_code_type_1' or 'tracking_code_type_2'
     * @return static
     */
    public function withTrackingCode(int $trackingCodeId, string $trackingType = 'tracking_code_type_1'): static
    {
        return $this->state(function (array $attributes) use ($trackingCodeId, $trackingType) {
            return [
                'metadata_type' => $trackingType,
                'transaction_category_id' => null,
                'tracking_code_id' => $trackingCodeId,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a specific project ID.
     *
     * @param int $projectId
     * @return static
     */
    public function withProject(int $projectId): static
    {
        return $this->state(function (array $attributes) use ($projectId) {
            return [
                'metadata_type' => 'project',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => $projectId,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a specific file store ID.
     *
     * @param int $fileStoreId
     * @return static
     */
    public function withFile(int $fileStoreId): static
    {
        return $this->state(function (array $attributes) use ($fileStoreId) {
            return [
                'metadata_type' => 'file',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => $fileStoreId,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a specific expense source ID.
     *
     * @param int $expenseSourceId
     * @return static
     */
    public function withExpenseSource(int $expenseSourceId): static
    {
        return $this->state(function (array $attributes) use ($expenseSourceId) {
            return [
                'metadata_type' => 'expense_source',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => $expenseSourceId,
                'additional_field_id' => null,
                'user_id' => null,
            ];
        });
    }

    /**
     * Configure the factory with a specific additional field ID.
     *
     * @param int $additionalFieldId
     * @return static
     */
    public function withAdditionalField(int $additionalFieldId): static
    {
        return $this->state(function (array $attributes) use ($additionalFieldId) {
            return [
                'metadata_type' => 'additional_field',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => $additionalFieldId,
                'user_id' => null,
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
     * Configure the factory with specific JSON details.
     *
     * @param array $details
     * @return static
     */
    public function withDetails(array $details): static
    {
        return $this->state(function (array $attributes) use ($details) {
            return [
                'details_json' => json_encode($details),
            ];
        });
    }

    /**
     * Configure the factory without JSON details.
     *
     * @return static
     */
    public function withoutDetails(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'details_json' => null,
            ];
        });
    }

    /**
     * Configure the factory for expense source metadata with "Other" source and required note.
     *
     * @param string $sourceNote
     * @return static
     */
    public function expenseSourceOther(string $sourceNote): static
    {
        return $this->state(function (array $attributes) use ($sourceNote) {
            return [
                'metadata_type' => 'expense_source',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null, // Global "Other" source has no specific client ID
                'additional_field_id' => null,
                'user_id' => null,
                'details_json' => json_encode([
                    'source_name' => 'Other',
                    'source_type' => 'other',
                    'is_default_source' => false,
                    'source_note' => $sourceNote,
                    'description' => 'Custom expense source with user-provided note'
                ]),
            ];
        });
    }

    /**
     * Configure the factory for soft deleted metadata.
     *
     * @return static
     */
    public function softDeleted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => 1,
                'delete_time' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory for active (non-deleted) metadata.
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted' => 0,
                'delete_time' => null,
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

    /**
     * Configure the factory with a complete metadata scenario.
     * 
     * @param int $pocketExpenseId
     * @param string $metadataType
     * @param int|null $referenceId
     * @param array|null $details
     * @return static
     */
    public function complete(int $pocketExpenseId, string $metadataType, ?int $referenceId = null, ?array $details = null): static
    {
        return $this->state(function (array $attributes) use ($pocketExpenseId, $metadataType, $referenceId, $details) {
            $state = [
                'pocket_expense_id' => $pocketExpenseId,
                'metadata_type' => $metadataType,
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
                'user_id' => null,
                'deleted' => 0,
                'delete_time' => null,
            ];

            // Set the appropriate foreign key based on metadata type
            switch ($metadataType) {
                case 'category':
                    $state['transaction_category_id'] = $referenceId;
                    break;
                case 'tracking_code_type_1':
                case 'tracking_code_type_2':
                    $state['tracking_code_id'] = $referenceId;
                    break;
                case 'project':
                    $state['project_id'] = $referenceId;
                    break;
                case 'file':
                    $state['file_store_id'] = $referenceId;
                    break;
                case 'expense_source':
                    $state['expense_source_id'] = $referenceId;
                    break;
                case 'additional_field':
                    $state['additional_field_id'] = $referenceId;
                    break;
            }

            // Set JSON details if provided
            if ($details !== null) {
                $state['details_json'] = json_encode($details);
            }

            return $state;
        });
    }

    /**
     * Configure the factory for different metadata types as a sequence.
     * Useful for testing scenarios that need various metadata types.
     *
     * @return static
     */
    public function metadataTypeSequence(): static
    {
        return $this->sequence(
            [
                'metadata_type' => 'category',
                'transaction_category_id' => 1,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ],
            [
                'metadata_type' => 'tracking_code_type_1',
                'transaction_category_id' => null,
                'tracking_code_id' => 1,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ],
            [
                'metadata_type' => 'project',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => 1,
                'file_store_id' => null,
                'expense_source_id' => null,
                'additional_field_id' => null,
            ],
            [
                'metadata_type' => 'expense_source',
                'transaction_category_id' => null,
                'tracking_code_id' => null,
                'project_id' => null,
                'file_store_id' => null,
                'expense_source_id' => 1,
                'additional_field_id' => null,
            ]
        );
    }

    /**
     * Configure the factory for common expense metadata combinations.
     * Creates category, tracking code, and expense source metadata.
     *
     * @return static
     */
    public function commonMetadataSequence(): static
    {
        return $this->sequence(
            // Category metadata
            [
                'metadata_type' => 'category',
                'transaction_category_id' => 1,
                'details_json' => json_encode([
                    'category_name' => 'Business Travel',
                    'category_code' => 'BT001'
                ]),
            ],
            // Expense source metadata
            [
                'metadata_type' => 'expense_source',
                'expense_source_id' => 1,
                'details_json' => json_encode([
                    'source_name' => 'Corporate Card',
                    'source_type' => 'card'
                ]),
            ],
            // Tracking code metadata
            [
                'metadata_type' => 'tracking_code_type_1',
                'tracking_code_id' => 1,
                'details_json' => json_encode([
                    'tracking_type' => 'Department',
                    'tracking_code' => 'DEPT-001'
                ]),
            ]
        );
    }
}