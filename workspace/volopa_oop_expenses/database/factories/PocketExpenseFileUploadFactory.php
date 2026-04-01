<?php

namespace Database\Factories;

use App\Models\PocketExpenseFileUpload;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PocketExpenseFileUpload>
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
        $statusOptions = [
            'uploaded',
            'validation_failed',
            'validation_passed',
            'processing',
            'completed',
            'failed',
            'sync_failed'
        ];

        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'created_by_user_id' => User::factory(),
            'file_name' => $this->faker->word . '_expenses_' . $this->faker->date() . '.csv',
            'file_path' => 'storage/app/pocket-expense-uploads/' . Str::uuid() . '.csv',
            'total_records' => $this->faker->numberBetween(1, 200), // Max 200 rows per CSV as per constraints
            'valid_records' => function (array $attributes) {
                return $this->faker->numberBetween(0, $attributes['total_records']);
            },
            'validation_errors' => $this->faker->optional(0.3)->passthrough([
                'errors' => [
                    [
                        'line_number' => $this->faker->numberBetween(2, 201),
                        'field' => $this->faker->randomElement(['Date', 'Amount', 'Currency Code', 'Merchant Name']),
                        'error' => $this->faker->sentence,
                        'value' => $this->faker->word
                    ]
                ]
            ]),
            'status' => $this->faker->randomElement($statusOptions),
            'uploaded_at' => now(),
            'validated_at' => $this->faker->optional(0.7)->passthrough(now()->addMinutes($this->faker->numberBetween(1, 10))),
            'processed_at' => $this->faker->optional(0.5)->passthrough(now()->addMinutes($this->faker->numberBetween(5, 30))),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    /**
     * Indicate that the upload is in uploaded status.
     *
     * @return static
     */
    public function uploaded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'uploaded',
            'validated_at' => null,
            'processed_at' => null,
            'validation_errors' => null,
        ]);
    }

    /**
     * Indicate that the upload validation failed.
     *
     * @return static
     */
    public function validationFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'validation_failed',
            'validated_at' => now(),
            'processed_at' => null,
            'valid_records' => 0,
            'validation_errors' => [
                'errors' => [
                    [
                        'line_number' => 2,
                        'field' => 'Date',
                        'error' => 'Date format is invalid',
                        'value' => '2021-13-45'
                    ]
                ]
            ],
        ]);
    }

    /**
     * Indicate that the upload validation passed.
     *
     * @return static
     */
    public function validationPassed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'validation_passed',
            'validated_at' => now(),
            'processed_at' => null,
            'valid_records' => $attributes['total_records'],
            'validation_errors' => null,
        ]);
    }

    /**
     * Indicate that the upload is processing.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'validated_at' => now()->subMinutes(5),
            'processed_at' => null,
            'valid_records' => $attributes['total_records'],
            'validation_errors' => null,
        ]);
    }

    /**
     * Indicate that the upload is completed.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'validated_at' => now()->subMinutes(10),
            'processed_at' => now(),
            'valid_records' => $attributes['total_records'],
            'validation_errors' => null,
        ]);
    }

    /**
     * Indicate that the upload processing failed.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'validated_at' => now()->subMinutes(10),
            'processed_at' => now(),
            'validation_errors' => [
                'processing_error' => 'Failed to process expenses due to system error'
            ],
        ]);
    }

    /**
     * Indicate that the upload sync failed.
     *
     * @return static
     */
    public function syncFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sync_failed',
            'validated_at' => now()->subMinutes(15),
            'processed_at' => now()->subMinutes(5),
            'validation_errors' => [
                'sync_error' => 'Failed to sync with main service'
            ],
        ]);
    }

    /**
     * Indicate that the upload is soft deleted.
     *
     * @return static
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * Create an upload for a specific user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @param int|null $createdByUserId
     * @return static
     */
    public function forUser(int $userId, int $clientId, int $createdByUserId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'created_by_user_id' => $createdByUserId ?? $userId,
        ]);
    }

    /**
     * Create an upload with specific file details.
     *
     * @param string $fileName
     * @param string $filePath
     * @return static
     */
    public function withFile(string $fileName, string $filePath): static
    {
        return $this->state(fn (array $attributes) => [
            'file_name' => $fileName,
            'file_path' => $filePath,
        ]);
    }

    /**
     * Create an upload with specific record counts.
     *
     * @param int $totalRecords
     * @param int|null $validRecords
     * @return static
     */
    public function withRecords(int $totalRecords, int $validRecords = null): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => $totalRecords,
            'valid_records' => $validRecords ?? $totalRecords,
        ]);
    }

    /**
     * Create an upload with validation errors.
     *
     * @param array $validationErrors
     * @return static
     */
    public function withValidationErrors(array $validationErrors): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_errors' => $validationErrors,
            'status' => 'validation_failed',
        ]);
    }

    /**
     * Create an upload with maximum allowed records (200).
     *
     * @return static
     */
    public function maxRecords(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => 200,
            'valid_records' => 200,
        ]);
    }

    /**
     * Create an upload with minimal records.
     *
     * @return static
     */
    public function minRecords(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => 1,
            'valid_records' => 1,
        ]);
    }

    /**
     * Create an upload created by a specific admin user.
     *
     * @param int $adminUserId
     * @return static
     */
    public function createdByAdmin(int $adminUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by_user_id' => $adminUserId,
        ]);
    }

    /**
     * Create an upload with timestamps for testing workflow.
     *
     * @return static
     */
    public function withWorkflowTimestamps(): static
    {
        $uploadTime = now()->subMinutes(20);
        $validateTime = $uploadTime->copy()->addMinutes(2);
        $processTime = $validateTime->copy()->addMinutes(5);

        return $this->state(fn (array $attributes) => [
            'uploaded_at' => $uploadTime,
            'validated_at' => $validateTime,
            'processed_at' => $processTime,
            'created_at' => $uploadTime,
            'updated_at' => $processTime,
        ]);
    }
}