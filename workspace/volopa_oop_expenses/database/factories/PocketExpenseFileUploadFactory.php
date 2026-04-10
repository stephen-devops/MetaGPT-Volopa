<?php

namespace Database\Factories;

use App\Models\PocketExpenseFileUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon;

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
        // Available status values from ENUM constraints
        $statusOptions = [
            'uploaded',
            'validation_failed',
            'validation_passed',
            'processing',
            'completed',
            'failed',
            'sync_failed'
        ];

        // Common CSV file names
        $fileNames = [
            'expenses_2024_01.csv',
            'pocket_expenses.csv',
            'monthly_expenses.csv',
            'expense_upload.csv',
            'out_of_pocket_expenses.csv'
        ];

        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => 1, // Default to user ID 1, should be overridden in tests with actual user
            'client_id' => 1, // Default to client ID 1, should be overridden in tests with actual client
            'created_by_user_id' => 1, // Default to user ID 1, should be overridden in tests with actual admin user
            'file_name' => $this->faker->randomElement($fileNames),
            'file_path' => 'storage/app/pocket-expense-uploads/' . $this->faker->uuid() . '.csv',
            'total_records' => $this->faker->numberBetween(1, 200), // Max 200 rows per CSV constraint
            'valid_records' => 0, // Default to 0, will be calculated during validation
            'validation_errors' => null, // No validation errors by default
            'status' => 'uploaded', // Default status as per ENUM constraint
            'uploaded_at' => now(),
            'validated_at' => null,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    /**
     * Indicate that the upload has validation errors.
     *
     * @return static
     */
    public function validationFailed(): static
    {
        $sampleErrors = [
            [
                'line_number' => 2,
                'field' => 'Date',
                'error' => 'Date format must be DD/MM/YYYY',
                'value' => '2024-01-01'
            ],
            [
                'line_number' => 3,
                'field' => 'Currency Code',
                'error' => 'Invalid currency code',
                'value' => 'XXX'
            ]
        ];

        return $this->state(fn (array $attributes) => [
            'status' => 'validation_failed',
            'validation_errors' => json_encode($sampleErrors),
            'validated_at' => now(),
            'valid_records' => 0,
        ]);
    }

    /**
     * Indicate that the upload passed validation.
     *
     * @return static
     */
    public function validationPassed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            return [
                'status' => 'validation_passed',
                'validation_errors' => null,
                'validated_at' => now(),
                'valid_records' => $totalRecords, // All records are valid
            ];
        });
    }

    /**
     * Indicate that the upload is being processed.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'validated_at' => now(),
        ]);
    }

    /**
     * Indicate that the upload has completed successfully.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            return [
                'status' => 'completed',
                'validated_at' => now()->subMinutes(10),
                'processed_at' => now(),
                'valid_records' => $totalRecords,
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Indicate that the upload failed during processing.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'validated_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Indicate that the upload failed during sync.
     *
     * @return static
     */
    public function syncFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sync_failed',
            'validated_at' => now()->subMinutes(10),
            'processed_at' => now()->subMinutes(5),
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
     * Set the user for this upload (target user for expenses).
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
     * Set the client for this upload.
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
     * Set the admin user who created this upload.
     *
     * @param int $adminUserId
     * @return static
     */
    public function createdBy(int $adminUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by_user_id' => $adminUserId,
        ]);
    }

    /**
     * Set the file name for this upload.
     *
     * @param string $fileName
     * @return static
     */
    public function withFileName(string $fileName): static
    {
        return $this->state(fn (array $attributes) => [
            'file_name' => $fileName,
        ]);
    }

    /**
     * Set the file path for this upload.
     *
     * @param string $filePath
     * @return static
     */
    public function withFilePath(string $filePath): static
    {
        return $this->state(fn (array $attributes) => [
            'file_path' => $filePath,
        ]);
    }

    /**
     * Set the total records for this upload.
     *
     * @param int $totalRecords
     * @return static
     */
    public function withTotalRecords(int $totalRecords): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => $totalRecords,
        ]);
    }

    /**
     * Set the valid records for this upload.
     *
     * @param int $validRecords
     * @return static
     */
    public function withValidRecords(int $validRecords): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_records' => $validRecords,
        ]);
    }

    /**
     * Set validation errors for this upload.
     *
     * @param array $errors
     * @return static
     */
    public function withValidationErrors(array $errors): static
    {
        return $this->state(fn (array $attributes) => [
            'validation_errors' => json_encode($errors),
            'status' => 'validation_failed',
            'validated_at' => now(),
        ]);
    }

    /**
     * Set the upload status.
     *
     * @param string $status
     * @return static
     */
    public function withStatus(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Create upload with small file (under 50 records).
     *
     * @return static
     */
    public function smallFile(): static
    {
        $totalRecords = $this->faker->numberBetween(1, 50);
        return $this->state(fn (array $attributes) => [
            'total_records' => $totalRecords,
            'valid_records' => $totalRecords,
        ]);
    }

    /**
     * Create upload with large file (close to 200 record limit).
     *
     * @return static
     */
    public function largeFile(): static
    {
        $totalRecords = $this->faker->numberBetween(150, 200);
        return $this->state(fn (array $attributes) => [
            'total_records' => $totalRecords,
            'valid_records' => $totalRecords,
        ]);
    }

    /**
     * Create upload with partial validation errors.
     *
     * @return static
     */
    public function partialErrors(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $this->faker->numberBetween(10, 50);
            $validRecords = $this->faker->numberBetween(5, $totalRecords - 1);
            $errorCount = $totalRecords - $validRecords;
            
            $sampleErrors = [];
            for ($i = 0; $i < $errorCount; $i++) {
                $sampleErrors[] = [
                    'line_number' => $this->faker->numberBetween(2, $totalRecords + 1),
                    'field' => $this->faker->randomElement(['Date', 'Currency Code', 'Amount', 'Merchant Name']),
                    'error' => $this->faker->sentence(3),
                    'value' => $this->faker->word()
                ];
            }
            
            return [
                'total_records' => $totalRecords,
                'valid_records' => $validRecords,
                'validation_errors' => json_encode($sampleErrors),
                'status' => 'validation_failed',
                'validated_at' => now(),
            ];
        });
    }

    /**
     * Create upload that was uploaded recently (within last hour).
     *
     * @return static
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'uploaded_at' => now()->subMinutes($this->faker->numberBetween(1, 60)),
            'created_at' => now()->subMinutes($this->faker->numberBetween(1, 60)),
        ]);
    }

    /**
     * Create upload that was uploaded earlier (within last 24 hours).
     *
     * @return static
     */
    public function older(): static
    {
        return $this->state(fn (array $attributes) => [
            'uploaded_at' => now()->subHours($this->faker->numberBetween(2, 24)),
            'created_at' => now()->subHours($this->faker->numberBetween(2, 24)),
        ]);
    }
}