<?php

namespace Database\Factories;

use App\Models\PocketExpenseFileUpload;
use App\Models\User;
use App\Models\Client;
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
        $uploadDate = now()->subDays($this->faker->numberBetween(0, 30));
        $totalRecords = $this->faker->numberBetween(1, 200); // Within CSV constraint max 200 rows
        $validRecords = $this->faker->numberBetween(0, $totalRecords);
        
        // Generate realistic file names for CSV uploads
        $fileNames = [
            'expenses_' . now()->format('Ymd') . '.csv',
            'pocket_expenses_export.csv',
            'monthly_expenses_' . $this->faker->monthName() . '.csv',
            'team_expenses_' . $this->faker->dateTime()->format('Y_m_d') . '.csv',
            'business_expenses.csv',
            'travel_expenses_Q' . $this->faker->numberBetween(1, 4) . '.csv',
            'corporate_card_expenses.csv',
            'employee_expenses_' . $this->faker->numerify('####') . '.csv'
        ];

        $fileName = $this->faker->randomElement($fileNames);
        $filePath = 'uploads/pocket-expenses/' . date('Y/m/') . Str::uuid() . '_' . $fileName;

        // Generate validation errors array if there are invalid records
        $validationErrors = null;
        if ($validRecords < $totalRecords) {
            $validationErrors = $this->generateValidationErrors($totalRecords - $validRecords);
        }

        return [
            'uuid' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'created_by_user_id' => User::factory(),
            'file_name' => $fileName,
            'file_path' => $filePath,
            'total_records' => $totalRecords,
            'valid_records' => $validRecords,
            'validation_errors' => $validationErrors,
            'status' => $this->faker->randomElement([
                'uploaded',
                'validation_failed',
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ]),
            'uploaded_at' => $uploadDate,
            'validated_at' => $this->faker->optional(0.7)->dateTimeBetween($uploadDate, $uploadDate->copy()->addHours(2)),
            'processed_at' => $this->faker->optional(0.5)->dateTimeBetween($uploadDate->copy()->addHours(2), $uploadDate->copy()->addHours(6)),
            'created_at' => $uploadDate,
            'updated_at' => $uploadDate->copy()->addMinutes($this->faker->numberBetween(1, 180)),
            'deleted_at' => null,
        ];
    }

    /**
     * Generate realistic validation errors for CSV upload testing.
     *
     * @param int $errorCount
     * @return array
     */
    private function generateValidationErrors(int $errorCount): array
    {
        $errors = [];
        $errorTypes = [
            ['field' => 'Date', 'error' => 'Date format must be DD/MM/YYYY', 'value' => '2024-01-15'],
            ['field' => 'Date', 'error' => 'Date cannot be older than 3 years', 'value' => '15/01/2020'],
            ['field' => 'Expense Type', 'error' => 'Invalid expense type', 'value' => 'Invalid Type'],
            ['field' => 'Currency Code', 'error' => 'Currency code must be 3-letter ISO format', 'value' => 'DOLLAR'],
            ['field' => 'Amount', 'error' => 'Amount must be a valid number', 'value' => 'NOT_A_NUMBER'],
            ['field' => 'VAT %', 'error' => 'VAT % must be between 0-100', 'value' => '150%'],
            ['field' => 'Merchant Name', 'error' => 'Merchant name is required', 'value' => ''],
            ['field' => 'Merchant Name', 'error' => 'Merchant name exceeds maximum length of 180 characters', 'value' => str_repeat('A', 200)],
            ['field' => 'Source', 'error' => 'Source must match configured sources for client', 'value' => 'Unknown Source'],
            ['field' => 'Source Note', 'error' => 'Source Note is required when Source = Other', 'value' => ''],
        ];

        for ($i = 0; $i < $errorCount; $i++) {
            $error = $this->faker->randomElement($errorTypes);
            $errors[] = [
                'line_number' => $this->faker->numberBetween(2, 201), // Line 1 is header, so start from 2
                'field' => $error['field'],
                'error' => $error['error'],
                'value' => $error['value']
            ];
        }

        return $errors;
    }

    /**
     * Create an upload for existing user and client.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $createdByUserId
     * @return static
     */
    public function forUser(int $userId, int $clientId, int $createdByUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'client_id' => $clientId,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * Create an upload with a specific status.
     *
     * @param string $status
     * @return static
     */
    public function withStatus(string $status): static
    {
        return $this->state(function (array $attributes) use ($status) {
            $uploadDate = $attributes['uploaded_at'] ?? now();
            $timestamps = $this->generateStatusTimestamps($status, $uploadDate);
            
            return array_merge(['status' => $status], $timestamps);
        });
    }

    /**
     * Generate appropriate timestamps based on upload status.
     *
     * @param string $status
     * @param \Carbon\Carbon $uploadDate
     * @return array
     */
    private function generateStatusTimestamps(string $status, Carbon $uploadDate): array
    {
        $timestamps = [
            'uploaded_at' => $uploadDate,
            'validated_at' => null,
            'processed_at' => null,
        ];

        switch ($status) {
            case 'uploaded':
                // Only upload timestamp
                break;
            case 'validation_failed':
            case 'validation_passed':
                $timestamps['validated_at'] = $uploadDate->copy()->addMinutes($this->faker->numberBetween(1, 30));
                break;
            case 'processing':
                $timestamps['validated_at'] = $uploadDate->copy()->addMinutes($this->faker->numberBetween(1, 30));
                break;
            case 'completed':
            case 'failed':
            case 'sync_failed':
                $timestamps['validated_at'] = $uploadDate->copy()->addMinutes($this->faker->numberBetween(1, 30));
                $timestamps['processed_at'] = $uploadDate->copy()->addHours($this->faker->numberBetween(1, 6));
                break;
        }

        return $timestamps;
    }

    /**
     * Create an upload that just completed uploading.
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
     * Create an upload that failed validation.
     *
     * @return static
     */
    public function validationFailed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            $errorCount = $this->faker->numberBetween(1, $totalRecords);
            
            return [
                'status' => 'validation_failed',
                'valid_records' => $totalRecords - $errorCount,
                'validation_errors' => $this->generateValidationErrors($errorCount),
                'validated_at' => now()->subMinutes($this->faker->numberBetween(5, 60)),
                'processed_at' => null,
            ];
        });
    }

    /**
     * Create an upload that passed validation.
     *
     * @return static
     */
    public function validationPassed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            
            return [
                'status' => 'validation_passed',
                'valid_records' => $totalRecords,
                'validation_errors' => null,
                'validated_at' => now()->subMinutes($this->faker->numberBetween(5, 60)),
                'processed_at' => null,
            ];
        });
    }

    /**
     * Create an upload that is currently processing.
     *
     * @return static
     */
    public function processing(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            
            return [
                'status' => 'processing',
                'valid_records' => $totalRecords,
                'validation_errors' => null,
                'validated_at' => now()->subMinutes($this->faker->numberBetween(10, 120)),
                'processed_at' => null,
            ];
        });
    }

    /**
     * Create an upload that completed successfully.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            
            return [
                'status' => 'completed',
                'valid_records' => $totalRecords,
                'validation_errors' => null,
                'validated_at' => now()->subHours($this->faker->numberBetween(1, 24)),
                'processed_at' => now()->subMinutes($this->faker->numberBetween(5, 60)),
            ];
        });
    }

    /**
     * Create an upload that failed during processing.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            $validRecords = $this->faker->numberBetween(0, $totalRecords);
            
            return [
                'status' => 'failed',
                'valid_records' => $validRecords,
                'validation_errors' => $validRecords < $totalRecords ? $this->generateValidationErrors($totalRecords - $validRecords) : null,
                'validated_at' => now()->subHours($this->faker->numberBetween(1, 24)),
                'processed_at' => now()->subMinutes($this->faker->numberBetween(5, 60)),
            ];
        });
    }

    /**
     * Create an upload that failed during sync.
     *
     * @return static
     */
    public function syncFailed(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            
            return [
                'status' => 'sync_failed',
                'valid_records' => $totalRecords,
                'validation_errors' => null,
                'validated_at' => now()->subHours($this->faker->numberBetween(1, 24)),
                'processed_at' => now()->subMinutes($this->faker->numberBetween(5, 60)),
            ];
        });
    }

    /**
     * Create an upload with specific record counts.
     *
     * @param int $totalRecords
     * @param int|null $validRecords
     * @return static
     */
    public function withRecordCounts(int $totalRecords, int $validRecords = null): static
    {
        $validRecords = $validRecords ?? $totalRecords;
        $errorCount = $totalRecords - $validRecords;
        
        return $this->state(fn (array $attributes) => [
            'total_records' => $totalRecords,
            'valid_records' => $validRecords,
            'validation_errors' => $errorCount > 0 ? $this->generateValidationErrors($errorCount) : null,
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
            'validation_errors' => null,
        ]);
    }

    /**
     * Create an upload with minimal records.
     *
     * @return static
     */
    public function minimalRecords(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => 1,
            'valid_records' => 1,
            'validation_errors' => null,
        ]);
    }

    /**
     * Create an upload with a specific file name.
     *
     * @param string $fileName
     * @return static
     */
    public function withFileName(string $fileName): static
    {
        $filePath = 'uploads/pocket-expenses/' . date('Y/m/') . Str::uuid() . '_' . $fileName;
        
        return $this->state(fn (array $attributes) => [
            'file_name' => $fileName,
            'file_path' => $filePath,
        ]);
    }

    /**
     * Create an upload with a specific file path.
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
     * Create an upload with a specific UUID.
     *
     * @param string $uuid
     * @return static
     */
    public function withUuid(string $uuid): static
    {
        return $this->state(fn (array $attributes) => [
            'uuid' => $uuid,
        ]);
    }

    /**
     * Create an upload from today.
     *
     * @return static
     */
    public function today(): static
    {
        $uploadTime = now()->subHours($this->faker->numberBetween(0, 12));
        
        return $this->state(fn (array $attributes) => [
            'uploaded_at' => $uploadTime,
            'created_at' => $uploadTime,
            'updated_at' => $uploadTime->copy()->addMinutes($this->faker->numberBetween(1, 60)),
        ]);
    }

    /**
     * Create an upload from yesterday.
     *
     * @return static
     */
    public function yesterday(): static
    {
        $uploadTime = now()->subDay()->addHours($this->faker->numberBetween(0, 12));
        
        return $this->state(fn (array $attributes) => [
            'uploaded_at' => $uploadTime,
            'created_at' => $uploadTime,
            'updated_at' => $uploadTime->copy()->addMinutes($this->faker->numberBetween(1, 180)),
        ]);
    }

    /**
     * Create an upload from a specific number of days ago.
     *
     * @param int $daysAgo
     * @return static
     */
    public function daysAgo(int $daysAgo): static
    {
        $uploadTime = now()->subDays($daysAgo)->addHours($this->faker->numberBetween(0, 12));
        
        return $this->state(fn (array $attributes) => [
            'uploaded_at' => $uploadTime,
            'created_at' => $uploadTime,
            'updated_at' => $uploadTime->copy()->addMinutes($this->faker->numberBetween(1, 360)),
        ]);
    }

    /**
     * Create a soft-deleted upload.
     *
     * @return static
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }

    /**
     * Create an active upload (not deleted).
     *
     * @return static
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => null,
        ]);
    }

    /**
     * Create an upload with specific timestamps.
     *
     * @param \Carbon\Carbon|string|null $uploadedAt
     * @param \Carbon\Carbon|string|null $validatedAt
     * @param \Carbon\Carbon|string|null $processedAt
     * @return static
     */
    public function withTimestamps($uploadedAt = null, $validatedAt = null, $processedAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'uploaded_at' => $uploadedAt ?? now()->subHours($this->faker->numberBetween(1, 48)),
            'validated_at' => $validatedAt,
            'processed_at' => $processedAt,
        ]);
    }

    /**
     * Create an upload with validation errors for specific fields.
     *
     * @param array $fields
     * @return static
     */
    public function withValidationErrorsFor(array $fields): static
    {
        $errors = [];
        foreach ($fields as $field) {
            $errors[] = [
                'line_number' => $this->faker->numberBetween(2, 201),
                'field' => $field,
                'error' => "Validation error for field: {$field}",
                'value' => 'Invalid Value'
            ];
        }
        
        return $this->state(function (array $attributes) use ($errors) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(1, 200);
            $errorCount = count($errors);
            
            return [
                'valid_records' => max(0, $totalRecords - $errorCount),
                'validation_errors' => $errors,
                'status' => 'validation_failed',
            ];
        });
    }

    /**
     * Create an upload suitable for testing CSV processing workflows.
     *
     * @return static
     */
    public function forWorkflowTesting(): static
    {
        return $this->state(function (array $attributes) {
            $statuses = ['validation_passed', 'processing', 'completed'];
            $status = $this->faker->randomElement($statuses);
            $totalRecords = $this->faker->numberBetween(5, 50); // Reasonable size for testing
            
            $timestamps = $this->generateStatusTimestamps($status, now()->subHours(2));
            
            return array_merge([
                'status' => $status,
                'total_records' => $totalRecords,
                'valid_records' => $totalRecords,
                'validation_errors' => null,
                'file_name' => 'test_upload_' . now()->format('Ymd_His') . '.csv',
            ], $timestamps);
        });
    }

    /**
     * Create multiple uploads for the same user-client combination.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $createdByUserId
     * @param array $statuses
     * @return static
     */
    public function multipleForUser(int $userId, int $clientId, int $createdByUserId, array $statuses = ['completed', 'failed']): static
    {
        return $this->state(function (array $attributes) use ($userId, $clientId, $createdByUserId, $statuses) {
            $status = $this->faker->randomElement($statuses);
            $totalRecords = $this->faker->numberBetween(1, 100);
            $timestamps = $this->generateStatusTimestamps($status, now()->subDays($this->faker->numberBetween(1, 30)));
            
            return array_merge([
                'user_id' => $userId,
                'client_id' => $clientId,
                'created_by_user_id' => $createdByUserId,
                'status' => $status,
                'total_records' => $totalRecords,
                'valid_records' => $status === 'validation_failed' ? $this->faker->numberBetween(0, $totalRecords - 1) : $totalRecords,
            ], $timestamps);
        });
    }

    /**
     * Create an upload with realistic business file naming.
     *
     * @return static
     */
    public function businessFile(): static
    {
        $businessFiles = [
            'Q1_2024_Travel_Expenses.csv',
            'Corporate_Card_Transactions_March.csv',
            'Employee_Reimbursements_Weekly.csv',
            'Petty_Cash_Expenses_Department_A.csv',
            'Conference_Expenses_TeamBuilding_2024.csv',
            'Client_Entertainment_Q2.csv',
            'Office_Supplies_Monthly_Report.csv',
            'Vehicle_Expenses_Fleet_Management.csv'
        ];
        
        $fileName = $this->faker->randomElement($businessFiles);
        $filePath = 'uploads/pocket-expenses/' . date('Y/m/') . Str::uuid() . '_' . $fileName;
        
        return $this->state(fn (array $attributes) => [
            'file_name' => $fileName,
            'file_path' => $filePath,
        ]);
    }

    /**
     * Create all possible upload statuses for comprehensive testing.
     *
     * @param int $userId
     * @param int $clientId
     * @param int $createdByUserId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function createAllStatuses(int $userId, int $clientId, int $createdByUserId): \Illuminate\Database\Eloquent\Collection
    {
        $statuses = ['uploaded', 'validation_failed', 'validation_passed', 'processing', 'completed', 'failed', 'sync_failed'];
        $uploads = collect();
        
        foreach ($statuses as $status) {
            $uploads->push(
                $this->forUser($userId, $clientId, $createdByUserId)
                     ->withStatus($status)
                     ->create()
            );
        }
        
        return $uploads;
    }

    /**
     * Create an upload that represents a large batch suitable for performance testing.
     *
     * @return static
     */
    public function largeBatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => $this->faker->numberBetween(150, 200), // Near the 200-row limit
            'valid_records' => $this->faker->numberBetween(140, 200),
            'file_name' => 'large_batch_' . now()->format('Ymd_His') . '.csv',
            'status' => 'completed',
        ]);
    }

    /**
     * Create an upload that represents a small test batch.
     *
     * @return static
     */
    public function smallBatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_records' => $this->faker->numberBetween(1, 10),
            'valid_records' => $this->faker->numberBetween(1, 10),
            'file_name' => 'test_batch_' . now()->format('Ymd_His') . '.csv',
            'status' => 'completed',
        ]);
    }

    /**
     * Create an upload with mixed validation results for testing error handling.
     *
     * @return static
     */
    public function mixedValidation(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $this->faker->numberBetween(20, 100);
            $validRecords = $this->faker->numberBetween(10, $totalRecords - 5);
            $errorCount = $totalRecords - $validRecords;
            
            return [
                'total_records' => $totalRecords,
                'valid_records' => $validRecords,
                'validation_errors' => $this->generateValidationErrors($errorCount),
                'status' => 'validation_failed',
                'validated_at' => now()->subMinutes($this->faker->numberBetween(15, 120)),
            ];
        });
    }

    /**
     * Create an upload with no validation errors (perfect upload).
     *
     * @return static
     */
    public function perfect(): static
    {
        return $this->state(function (array $attributes) {
            $totalRecords = $attributes['total_records'] ?? $this->faker->numberBetween(10, 100);
            
            return [
                'total_records' => $totalRecords,
                'valid_records' => $totalRecords,
                'validation_errors' => null,
                'status' => 'completed',
            ];
        });
    }

    /**
     * Create an upload for testing edge cases.
     *
     * @return static
     */
    public function edgeCase(): static
    {
        return $this->state(function (array $attributes) {
            $scenarios = [
                // Exactly at the limit
                ['total_records' => 200, 'valid_records' => 200],
                // All records failed validation
                ['total_records' => 50, 'valid_records' => 0],
                // Single record
                ['total_records' => 1, 'valid_records' => 1],
                // Mixed with many errors
                ['total_records' => 100, 'valid_records' => 10],
            ];
            
            $scenario = $this->faker->randomElement($scenarios);
            $errorCount = $scenario['total_records'] - $scenario['valid_records'];
            
            return [
                'total_records' => $scenario['total_records'],
                'valid_records' => $scenario['valid_records'],
                'validation_errors' => $errorCount > 0 ? $this->generateValidationErrors($errorCount) : null,
                'status' => $errorCount > 0 ? 'validation_failed' : 'completed',
                'file_name' => 'edge_case_test_' . $scenario['total_records'] . '_records.csv',
            ];
        });
    }
}