<?php

namespace Database\Factories;

use App\Models\PocketExpenseFileUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Factory for PocketExpenseFileUpload model
 * 
 * Generates test data for pocket expense file uploads with proper relationships
 * to users, clients, and realistic CSV upload scenarios. Handles all upload
 * status workflows, file processing statistics, and validation error tracking.
 */
class PocketExpenseFileUploadFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PocketExpenseFileUpload::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = Carbon::now();
        $fileName = 'expenses_' . $this->faker->date('Y_m_d') . '_' . $this->faker->numberBetween(1000, 9999) . '.csv';
        $totalRecords = $this->faker->numberBetween(10, 200);
        $validRecords = $this->faker->numberBetween(5, $totalRecords);
        
        return [
            // UUID for external references
            'uuid' => Str::uuid()->toString(),
            
            // User and client context - default to ID 1, override in tests
            'user_id' => 1, // Target user for whom expenses will be created
            'client_id' => 1, // Client context for multi-tenancy
            'created_by_user_id' => 1, // Admin user who performed the upload
            
            // File information
            'file_name' => $fileName,
            'file_path' => 'pocket-expense-uploads/' . date('Y/m/d') . '/' . $fileName,
            
            // Processing statistics
            'total_records' => $totalRecords,
            'valid_records' => $validRecords,
            
            // Validation and error tracking - default to no errors
            'validation_errors' => null,
            
            // Processing status workflow - default to uploaded
            'status' => 'uploaded',
            
            // Processing timestamps
            'uploaded_at' => $now,
            'validated_at' => null,
            'processed_at' => null,
            
            // Laravel standard timestamps
            'created_at' => $now,
            'updated_at' => $now,
            
            // Not soft deleted by default
            'deleted_at' => null,
        ];
    }

    /**
     * Configure the factory for uploaded status.
     *
     * @return static
     */
    public function uploaded(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'uploaded',
                'uploaded_at' => Carbon::now(),
                'validated_at' => null,
                'processed_at' => null,
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Configure the factory for validating status.
     *
     * @return static
     */
    public function validating(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'validating',
                'uploaded_at' => Carbon::now()->subMinutes(2),
                'validated_at' => null,
                'processed_at' => null,
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Configure the factory for validation_failed status.
     *
     * @return static
     */
    public function validationFailed(): static
    {
        return $this->state(function (array $attributes) {
            $validationErrors = [
                [
                    'line_number' => 3,
                    'field' => 'date',
                    'error' => 'Date format must be DD/MM/YYYY',
                    'provided_value' => '2024-01-15'
                ],
                [
                    'line_number' => 5,
                    'field' => 'currency',
                    'error' => 'Currency must be a valid 3-letter ISO code',
                    'provided_value' => 'POUND'
                ],
                [
                    'line_number' => 7,
                    'field' => 'amount',
                    'error' => 'Amount must be a valid number',
                    'provided_value' => 'invalid'
                ]
            ];
            
            return [
                'status' => 'validation_failed',
                'uploaded_at' => Carbon::now()->subMinutes(5),
                'validated_at' => Carbon::now()->subMinutes(2),
                'processed_at' => null,
                'validation_errors' => json_encode($validationErrors),
                'valid_records' => 0, // No valid records when validation failed
            ];
        });
    }

    /**
     * Configure the factory for processing status.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'processing',
                'uploaded_at' => Carbon::now()->subMinutes(10),
                'validated_at' => Carbon::now()->subMinutes(7),
                'processed_at' => null,
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Configure the factory for completed status.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'completed',
                'uploaded_at' => Carbon::now()->subHour(),
                'validated_at' => Carbon::now()->subMinutes(55),
                'processed_at' => Carbon::now()->subMinutes(50),
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Configure the factory for failed status.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            $processingErrors = [
                [
                    'error_type' => 'database_error',
                    'error_message' => 'Failed to create expense record',
                    'line_numbers' => [15, 23, 31],
                    'timestamp' => Carbon::now()->subMinutes(15)->toISOString()
                ]
            ];
            
            return [
                'status' => 'failed',
                'uploaded_at' => Carbon::now()->subMinutes(30),
                'validated_at' => Carbon::now()->subMinutes(25),
                'processed_at' => Carbon::now()->subMinutes(15),
                'validation_errors' => json_encode($processingErrors),
            ];
        });
    }

    /**
     * Configure the factory with a specific target user ID.
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
     * Configure the factory with a specific creating admin user ID.
     *
     * @param int $createdByUserId
     * @return static
     */
    public function createdBy(int $createdByUserId): static
    {
        return $this->state(function (array $attributes) use ($createdByUserId) {
            return [
                'created_by_user_id' => $createdByUserId,
            ];
        });
    }

    /**
     * Configure the factory with a specific file name.
     *
     * @param string $fileName
     * @return static
     */
    public function withFileName(string $fileName): static
    {
        return $this->state(function (array $attributes) use ($fileName) {
            return [
                'file_name' => $fileName,
                'file_path' => 'pocket-expense-uploads/' . date('Y/m/d') . '/' . $fileName,
            ];
        });
    }

    /**
     * Configure the factory with a specific file path.
     *
     * @param string $filePath
     * @return static
     */
    public function withFilePath(string $filePath): static
    {
        return $this->state(function (array $attributes) use ($filePath) {
            return [
                'file_path' => $filePath,
            ];
        });
    }

    /**
     * Configure the factory with specific record counts.
     *
     * @param int $totalRecords
     * @param int|null $validRecords
     * @return static
     */
    public function withRecordCounts(int $totalRecords, ?int $validRecords = null): static
    {
        return $this->state(function (array $attributes) use ($totalRecords, $validRecords) {
            return [
                'total_records' => $totalRecords,
                'valid_records' => $validRecords ?? $totalRecords,
            ];
        });
    }

    /**
     * Configure the factory with specific UUID.
     *
     * @param string $uuid
     * @return static
     */
    public function withUuid(string $uuid): static
    {
        return $this->state(function (array $attributes) use ($uuid) {
            return [
                'uuid' => $uuid,
            ];
        });
    }

    /**
     * Configure the factory without UUID (null).
     *
     * @return static
     */
    public function withoutUuid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'uuid' => null,
            ];
        });
    }

    /**
     * Configure the factory with specific validation errors.
     *
     * @param array $validationErrors
     * @return static
     */
    public function withValidationErrors(array $validationErrors): static
    {
        return $this->state(function (array $attributes) use ($validationErrors) {
            return [
                'validation_errors' => json_encode($validationErrors),
                'status' => 'validation_failed',
                'valid_records' => 0,
            ];
        });
    }

    /**
     * Configure the factory without validation errors.
     *
     * @return static
     */
    public function withoutValidationErrors(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Configure the factory for small CSV files (under 50 records).
     *
     * @return static
     */
    public function smallFile(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $this->faker->numberBetween(5, 50);
            $validRecords = $this->faker->numberBetween(4, $totalRecords);
            
            return [
                'total_records' => $totalRecords,
                'valid_records' => $validRecords,
            ];
        });
    }

    /**
     * Configure the factory for large CSV files (over 100 records).
     *
     * @return static
     */
    public function largeFile(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $this->faker->numberBetween(100, 200);
            $validRecords = $this->faker->numberBetween(80, $totalRecords);
            
            return [
                'total_records' => $totalRecords,
                'valid_records' => $validRecords,
            ];
        });
    }

    /**
     * Configure the factory for maximum size CSV files (200 records).
     *
     * @return static
     */
    public function maximumSize(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'total_records' => 200,
                'valid_records' => $this->faker->numberBetween(180, 200),
            ];
        });
    }

    /**
     * Configure the factory for perfect validation (all records valid).
     *
     * @return static
     */
    public function perfectValidation(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'valid_records' => $attributes['total_records'],
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Configure the factory for partial validation (some invalid records).
     *
     * @return static
     */
    public function partialValidation(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'];
            $validRecords = $this->faker->numberBetween(1, $totalRecords - 1);
            
            $validationErrors = [];
            for ($i = 0; $i < ($totalRecords - $validRecords); $i++) {
                $validationErrors[] = [
                    'line_number' => $this->faker->numberBetween(2, $totalRecords + 1),
                    'field' => $this->faker->randomElement(['date', 'currency', 'amount', 'merchant_name']),
                    'error' => $this->faker->randomElement([
                        'Date format must be DD/MM/YYYY',
                        'Currency must be a valid 3-letter ISO code',
                        'Amount must be a valid number',
                        'Merchant name is required'
                    ]),
                    'provided_value' => $this->faker->word()
                ];
            }
            
            return [
                'valid_records' => $validRecords,
                'validation_errors' => json_encode($validationErrors),
            ];
        });
    }

    /**
     * Configure the factory with specific upload timestamp.
     *
     * @param \DateTimeInterface|string $uploadedAt
     * @return static
     */
    public function uploadedAt($uploadedAt): static
    {
        return $this->state(function (array $attributes) use ($uploadedAt) {
            $timestamp = $uploadedAt instanceof \DateTimeInterface ? $uploadedAt : Carbon::parse($uploadedAt);
            return [
                'uploaded_at' => $timestamp,
                'created_at' => $timestamp,
            ];
        });
    }

    /**
     * Configure the factory with specific validation timestamp.
     *
     * @param \DateTimeInterface|string $validatedAt
     * @return static
     */
    public function validatedAt($validatedAt): static
    {
        return $this->state(function (array $attributes) use ($validatedAt) {
            $timestamp = $validatedAt instanceof \DateTimeInterface ? $validatedAt : Carbon::parse($validatedAt);
            return [
                'validated_at' => $timestamp,
            ];
        });
    }

    /**
     * Configure the factory with specific processing timestamp.
     *
     * @param \DateTimeInterface|string $processedAt
     * @return static
     */
    public function processedAt($processedAt): static
    {
        return $this->state(function (array $attributes) use ($processedAt) {
            $timestamp = $processedAt instanceof \DateTimeInterface ? $processedAt : Carbon::parse($processedAt);
            return [
                'processed_at' => $timestamp,
            ];
        });
    }

    /**
     * Configure the factory for soft deleted uploads.
     *
     * @return static
     */
    public function softDeleted(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted_at' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory for active (non-deleted) uploads.
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted_at' => null,
            ];
        });
    }

    /**
     * Configure the factory with updated timestamps for testing updates.
     *
     * @return static
     */
    public function updated(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'updated_at' => Carbon::now(),
            ];
        });
    }

    /**
     * Configure the factory with realistic CSV processing timeline.
     *
     * @return static
     */
    public function realisticTimeline(): static
    {
        return $this->state(function (array $attributes) {
            $uploadedAt = Carbon::now()->subHour();
            $validatedAt = $uploadedAt->copy()->addMinutes(3);
            $processedAt = $validatedAt->copy()->addMinutes(7);
            
            return [
                'uploaded_at' => $uploadedAt,
                'validated_at' => $validatedAt,
                'processed_at' => $processedAt,
                'created_at' => $uploadedAt,
                'updated_at' => $processedAt,
            ];
        });
    }

    /**
     * Configure the factory with a complete upload scenario.
     * 
     * @param int $userId Target user ID
     * @param int $clientId Client ID
     * @param int $createdByUserId Creating admin user ID
     * @param string $status Upload status
     * @return static
     */
    public function complete(int $userId, int $clientId, int $createdByUserId, string $status = 'uploaded'): static
    {
        return $this->state(function (array $attributes) use ($userId, $clientId, $createdByUserId, $status) {
            return [
                'user_id' => $userId,
                'client_id' => $clientId,
                'created_by_user_id' => $createdByUserId,
                'status' => $status,
                'deleted_at' => null,
            ];
        });
    }

    /**
     * Configure the factory for different upload statuses as a sequence.
     * Useful for testing scenarios that need various upload workflow states.
     *
     * @return static
     */
    public function statusSequence(): static
    {
        return $this->sequence(
            ['status' => 'uploaded', 'validated_at' => null, 'processed_at' => null],
            ['status' => 'validating', 'validated_at' => null, 'processed_at' => null],
            ['status' => 'processing', 'validated_at' => Carbon::now()->subMinutes(5), 'processed_at' => null],
            ['status' => 'completed', 'validated_at' => Carbon::now()->subMinutes(10), 'processed_at' => Carbon::now()->subMinutes(5)],
            ['status' => 'validation_failed', 'validated_at' => Carbon::now()->subMinutes(5), 'processed_at' => null],
            ['status' => 'failed', 'validated_at' => Carbon::now()->subMinutes(10), 'processed_at' => Carbon::now()->subMinutes(5)]
        );
    }

    /**
     * Configure the factory for different file sizes as a sequence.
     * Useful for testing scenarios that need various CSV file sizes.
     *
     * @return static
     */
    public function fileSizeSequence(): static
    {
        return $this->sequence(
            ['total_records' => 10, 'valid_records' => 8],   // Small file
            ['total_records' => 50, 'valid_records' => 45],  // Medium file
            ['total_records' => 100, 'valid_records' => 90], // Large file
            ['total_records' => 200, 'valid_records' => 180] // Maximum size file
        );
    }

    /**
     * Configure the factory for common validation error scenarios.
     *
     * @return static
     */
    public function commonValidationErrors(): static
    {
        return $this->state(function (array $attributes) {
            $validationErrors = [
                [
                    'line_number' => 3,
                    'field' => 'date',
                    'error' => 'Date format must be DD/MM/YYYY',
                    'provided_value' => '2024-01-15'
                ],
                [
                    'line_number' => 5,
                    'field' => 'currency',
                    'error' => 'Currency must be a valid 3-letter ISO code',
                    'provided_value' => 'POUND'
                ],
                [
                    'line_number' => 8,
                    'field' => 'amount',
                    'error' => 'Amount must be a valid number',
                    'provided_value' => 'N/A'
                ],
                [
                    'line_number' => 12,
                    'field' => 'merchant_name',
                    'error' => 'Merchant name exceeds maximum length of 180 characters',
                    'provided_value' => str_repeat('Very Long Merchant Name ', 20)
                ],
                [
                    'line_number' => 15,
                    'field' => 'source',
                    'error' => 'Source Note is required when Source is Other',
                    'provided_value' => 'Other'
                ]
            ];
            
            return [
                'status' => 'validation_failed',
                'validation_errors' => json_encode($validationErrors),
                'valid_records' => max(0, $attributes['total_records'] - count($validationErrors)),
                'validated_at' => Carbon::now()->subMinutes(2),
            ];
        });
    }
}