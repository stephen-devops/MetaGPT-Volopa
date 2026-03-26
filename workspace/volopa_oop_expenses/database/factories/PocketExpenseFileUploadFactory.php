<?php

namespace Database\Factories;

use App\Models\PocketExpenseFileUpload;
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
        $fileNames = [
            'expenses_january_2024.csv',
            'monthly_expenses.csv',
            'business_expenses.csv',
            'pocket_expenses_batch.csv',
            'expense_upload.csv',
            'company_expenses.csv',
            'quarterly_expenses.csv',
            'team_expenses.csv'
        ];

        $filePaths = [
            'uploads/csv/expenses/',
            'storage/uploads/pocket-expenses/',
            'temp/csv-uploads/',
            'files/expense-uploads/'
        ];

        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => function () {
                // TODO: Create or reference existing user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'client_id' => function () {
                // TODO: Create or reference existing client - depends on Client model factory
                return \App\Models\Client::factory()->create()->id;
            },
            'created_by_user_id' => function () {
                // TODO: Create or reference existing creator user - depends on User model factory
                return \App\Models\User::factory()->create()->id;
            },
            'file_name' => $this->faker->randomElement($fileNames),
            'file_path' => function () use ($filePaths) {
                $basePath = $this->faker->randomElement($filePaths);
                $fileName = $this->faker->uuid() . '.csv';
                return $basePath . $fileName;
            },
            'total_records' => $this->faker->numberBetween(1, 200), // Max 200 rows per CSV file constraint
            'valid_records' => function (array $attributes) {
                return $this->faker->numberBetween(0, $attributes['total_records']);
            },
            'validation_errors' => $this->faker->optional(0.3)->passthrough(
                json_encode([
                    [
                        'line_number' => $this->faker->numberBetween(2, 50),
                        'field' => 'Date',
                        'error' => 'Invalid date format',
                        'value' => '2024-13-45'
                    ],
                    [
                        'line_number' => $this->faker->numberBetween(2, 50),
                        'field' => 'Amount',
                        'error' => 'Amount must be numeric',
                        'value' => 'invalid_amount'
                    ]
                ])
            ),
            'status' => $this->faker->randomElement(['uploaded', 'validating', 'processing', 'completed', 'failed']),
            'uploaded_at' => now(),
            'validated_at' => $this->faker->optional(0.7)->passthrough(now()->addMinutes($this->faker->numberBetween(1, 10))),
            'processed_at' => $this->faker->optional(0.5)->passthrough(now()->addMinutes($this->faker->numberBetween(10, 60))),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    /**
     * Indicate that the upload is in uploaded status.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function uploaded(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'uploaded',
                'validated_at' => null,
                'processed_at' => null,
            ];
        });
    }

    /**
     * Indicate that the upload is in validating status.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function validating(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'validating',
                'validated_at' => null,
                'processed_at' => null,
            ];
        });
    }

    /**
     * Indicate that the upload is in processing status.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function processing(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'processing',
                'validated_at' => now()->subMinutes(5),
                'processed_at' => null,
            ];
        });
    }

    /**
     * Indicate that the upload is completed.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function completed(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'completed',
                'validated_at' => now()->subMinutes(10),
                'processed_at' => now()->subMinutes(2),
                'validation_errors' => null,
            ];
        });
    }

    /**
     * Indicate that the upload failed.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function failed(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'failed',
                'validated_at' => now()->subMinutes(5),
                'processed_at' => null,
                'validation_errors' => json_encode([
                    [
                        'line_number' => 2,
                        'field' => 'Date',
                        'error' => 'Date cannot be older than 3 years',
                        'value' => '01/01/2020'
                    ],
                    [
                        'line_number' => 3,
                        'field' => 'Currency Code',
                        'error' => 'Invalid currency code',
                        'value' => 'XYZ'
                    ]
                ]),
            ];
        });
    }

    /**
     * Indicate that the upload is soft deleted.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function deleted(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'deleted_at' => now(),
            ];
        });
    }

    /**
     * Create upload for specific user and client.
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
     * Create upload created by specific user.
     *
     * @param int $createdByUserId
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function createdBy(int $createdByUserId): Factory
    {
        return $this->state(function (array $attributes) use ($createdByUserId) {
            return [
                'created_by_user_id' => $createdByUserId,
            ];
        });
    }

    /**
     * Create upload with specific file details.
     *
     * @param string $fileName
     * @param string $filePath
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withFile(string $fileName, string $filePath): Factory
    {
        return $this->state(function (array $attributes) use ($fileName, $filePath) {
            return [
                'file_name' => $fileName,
                'file_path' => $filePath,
            ];
        });
    }

    /**
     * Create upload with specific record counts.
     *
     * @param int $totalRecords
     * @param int $validRecords
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withRecordCounts(int $totalRecords, int $validRecords): Factory
    {
        return $this->state(function (array $attributes) use ($totalRecords, $validRecords) {
            return [
                'total_records' => $totalRecords,
                'valid_records' => $validRecords,
            ];
        });
    }

    /**
     * Create upload with validation errors.
     *
     * @param array $errors
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withValidationErrors(array $errors): Factory
    {
        return $this->state(function (array $attributes) use ($errors) {
            return [
                'validation_errors' => json_encode($errors),
                'status' => 'failed',
            ];
        });
    }

    /**
     * Create upload with specific UUID.
     *
     * @param string $uuid
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withUuid(string $uuid): Factory
    {
        return $this->state(function (array $attributes) use ($uuid) {
            return [
                'uuid' => $uuid,
            ];
        });
    }

    /**
     * Create upload with maximum allowed records (constraint: 200 rows max).
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function maxRecords(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'total_records' => 200,
                'valid_records' => $this->faker->numberBetween(180, 200),
            ];
        });
    }

    /**
     * Create upload with no validation errors.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withoutErrors(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'validation_errors' => null,
                'valid_records' => $attributes['total_records'] ?? $this->faker->numberBetween(1, 200),
            ];
        });
    }

    /**
     * Create upload with specific timestamps.
     *
     * @param \Carbon\Carbon|null $uploadedAt
     * @param \Carbon\Carbon|null $validatedAt
     * @param \Carbon\Carbon|null $processedAt
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withTimestamps(?\Carbon\Carbon $uploadedAt = null, ?\Carbon\Carbon $validatedAt = null, ?\Carbon\Carbon $processedAt = null): Factory
    {
        return $this->state(function (array $attributes) use ($uploadedAt, $validatedAt, $processedAt) {
            return [
                'uploaded_at' => $uploadedAt ?? now(),
                'validated_at' => $validatedAt,
                'processed_at' => $processedAt,
            ];
        });
    }
}