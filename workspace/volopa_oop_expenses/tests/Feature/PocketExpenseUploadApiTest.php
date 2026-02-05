## Code: tests/Feature/PocketExpenseUploadApiTest.php

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpense;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadData;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\OptPocketExpenseType;
use App\Models\UserFeaturePermission;
use App\Jobs\ProcessExpenseUpload;
use App\Services\PocketExpenseCSVValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Carbon\Carbon;

/**
 * PocketExpenseUploadApiTest
 * 
 * Feature tests for CSV upload API endpoints for pocket expenses.
 * Tests file upload validation, CSV processing, background job dispatch,
 * upload status tracking, error handling, and authorization checks.
 */
class PocketExpenseUploadApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test user instance.
     *
     * @var User
     */
    private User $user;

    /**
     * Test client instance.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Target user for expense uploads.
     *
     * @var User
     */
    private User $targetUser;

    /**
     * Manager user for authorization tests.
     *
     * @var User
     */
    private User $managerUser;

    /**
     * Unauthorized user for testing access control.
     *
     * @var User
     */
    private User $unauthorizedUser;

    /**
     * Expense types for testing.
     *
     * @var array<OptPocketExpenseType>
     */
    private array $expenseTypes = [];

    /**
     * Expense sources for testing.
     *
     * @var array<PocketExpenseSourceClientConfig>
     */
    private array $expenseSources = [];

    /**
     * API base path for uploads.
     *
     * @var string
     */
    private string $uploadApiPath = '/api/uploads/pocket-expense';

    /**
     * Storage disk for testing.
     *
     * @var string
     */
    private string $storageDisk = 'local';

    /**
     * Setup test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->createTestData();
        
        // Setup fake storage
        Storage::fake($this->storageDisk);
        
        // Fake queue for job testing
        Queue::fake();
        
        // Clear cache
        Cache::flush();
    }

    /**
     * Create test data for experiments.
     *
     * @return void
     */
    private function createTestData(): void
    {
        // Create client
        $this->client = Client::factory()->create([
            'name' => 'Test Client Corp',
        ]);

        // Create users
        $this->user = User::factory()->create([
            'name' => 'Upload User',
            'email' => 'uploader@example.com',
        ]);

        $this->targetUser = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target@example.com',
        ]);

        $this->managerUser = User::factory()->create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
        ]);

        $this->unauthorizedUser = User::factory()->create([
            'name' => 'Unauthorized User',
            'email' => 'unauthorized@example.com',
        ]);

        // Create expense types
        $this->expenseTypes = [
            OptPocketExpenseType::create([
                'option' => 'Point of Sale',
                'amount_sign' => '-',
            ]),
            OptPocketExpenseType::create([
                'option' => 'ATM Withdrawal',
                'amount_sign' => '-',
            ]),
            OptPocketExpenseType::create([
                'option' => 'Refund from Merchant',
                'amount_sign' => '+',
            ]),
        ];

        // Create expense sources
        $this->expenseSources = [
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Cash',
                'is_default' => true,
                'deleted' => false,
            ]),
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Corporate Card',
                'is_default' => true,
                'deleted' => false,
            ]),
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
                'deleted' => false,
            ]),
        ];

        // Create user permissions
        $this->createUserPermissions();
    }

    /**
     * Create user permissions for testing.
     *
     * @return void
     */
    private function createUserPermissions(): void
    {
        $featureId = 1; // Pocket expense feature

        // Grant permission to uploader
        UserFeaturePermission::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'feature_id' => $featureId,
            'is_enabled' => true,
        ]);

        // Grant permission to target user with manager
        UserFeaturePermission::create([
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $featureId,
            'manager_user_id' => $this->managerUser->id,
            'is_enabled' => true,
        ]);

        // Grant permission to manager
        UserFeaturePermission::create([
            'user_id' => $this->managerUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $featureId,
            'is_enabled' => true,
        ]);

        // No permission for unauthorized user
    }

    /**
     * Create valid CSV file for testing.
     *
     * @param array<array<string, string>> $rows
     * @return UploadedFile
     */
    private function createValidCSVFile(array $rows = []): UploadedFile
    {
        if (empty($rows)) {
            $rows = [
                [
                    'Date' => '15/12/2023',
                    'Expense Type' => 'Point of Sale',
                    'Currency Code' => 'USD',
                    'Amount' => '100.50',
                    'Merchant Name' => 'Test Merchant 1',
                    'Description' => 'Test purchase 1',
                    'Source' => 'Corporate Card',
                ],
                [
                    'Date' => '16/12/2023',
                    'Expense Type' => 'ATM Withdrawal',
                    'Currency Code' => 'EUR',
                    'Amount' => '50.00',
                    'Merchant Name' => 'ATM Location',
                    'Description' => 'Cash withdrawal',
                    'Source' => 'Cash',
                ],
            ];
        }

        // Create CSV content
        $csvContent = $this->arrayToCSV($rows);
        
        return UploadedFile::fake()->createWithContent('test-expenses.csv', $csvContent);
    }

    /**
     * Create invalid CSV file for testing validation errors.
     *
     * @return UploadedFile
     */
    private function createInvalidCSVFile(): UploadedFile
    {
        $rows = [
            [
                'Date' => 'invalid-date',
                'Expense Type' => 'Invalid Type',
                'Currency Code' => 'XXX',
                'Amount' => 'not-a-number',
                'Merchant Name' => '',
                'Source' => 'Other',
                'Source Note' => '', // Required when Source is Other
            ],
        ];

        $csvContent = $this->arrayToCSV($rows);
        
        return UploadedFile::fake()->createWithContent('invalid-expenses.csv', $csvContent);
    }

    /**
     * Convert array to CSV content.
     *
     * @param array<array<string, string>> $rows
     * @return string
     */
    private function arrayToCSV(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, array_keys($rows[0]));
        
        // Write data rows
        foreach ($rows as $row) {
            fputcsv($output, array_values($row));
        }
        
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        return $csvContent;
    }

    /**
     * Get valid upload data.
     *
     * @return array<string, mixed>
     */
    private function getValidUploadData(): array
    {
        return [
            'client_id' => $this->client->id,
            'user_id' => $this->targetUser->id,
            'notes' => 'Test upload notes',
            'auto_submit' => false,
            'notification_email' => 'test@example.com',
        ];
    }

    /**
     * Test successful CSV upload with valid file.
     */
    public function test_upload_csv_with_valid_file_creates_upload_record_and_dispatches_job(): void
    {
        $csvFile = $this->createValidCSVFile();
        $uploadData = $this->getValidUploadData();

        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->uploadApiPath . '/csv', array_merge($uploadData, [
                'csv_file' => $csvFile,
            ]));

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonStructure([
                'success',
                'message',
                'upload_id',
                'total_rows',
                'valid_rows',
                'upload_status' => [
                    'id',
                    'uuid',
                    'status',
                    'total_records',
                    'valid_records',
                    'file_size',
                    'original_filename',
                ],
            ])
            ->assertJson([
                'success' => true,
                'total_rows' => 2,
                'valid_rows' => 2,
            ]);

        $uploadId = $response->json('upload_id');

        // Verify upload record created
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'id' => $uploadId,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'target_user_id' => $this->targetUser->id,
            'original_filename' => 'test-expenses.csv',
            'status' => PocketExpenseFileUpload::STATUS_VALIDATION_PASSED,
            'total_records' => 2,
            'valid_records' => 2,
            'deleted' => false,
        ]);

        // Verify upload data records created
        $this->assertDatabaseCount('pocket_expense_upload_data', 2);
        $this->assertDatabaseHas('pocket_expense_upload_data', [
            'upload_id' => $uploadId,
            'line_number' => 2,
            'status' => 'pending',
        ]);

        // Verify job dispatched
        Queue::assertPushed(ProcessExpenseUpload::class, function ($job) use ($uploadId) {
            return $job->uploadId === $uploadId;
        });
    }

    /**
     * Test CSV upload with validation errors returns error response.
     */
    public function test_upload_csv_with_invalid_file_returns_validation_errors(): void
    {
        $csvFile = $this->createInvalidCSVFile();
        $uploadData = $this->getValidUploadData();

        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->uploadApiPath . '/csv', array_merge($uploadData, [
                'csv_file' => $csvFile,
            ]));

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonStructure([
                'success',
                'message',
                'upload_id',
                'total_rows',
                'error_count',
                'errors' => [
                    '*' => [
                        'line_number',
                        'field',
                        'error',
                        'value',
                    ],
                ],
                'upload_status',
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
                'total_rows' => 1,
            ]);

        $uploadId = $response->json('upload_id');
        $errors = $response->json('errors');

        // Verify validation errors are present
        $this->assertNotEmpty($errors);
        $this->assertGreaterThan(0, count($errors));

        // Check specific error types
        $errorFields = array_column($errors, 'field');
        $this->assertContains('Date', $errorFields);
        $this->assertContains('Expense Type', $errorFields);
        $this->assertContains('Currency Code', $errorFields);
        $this->assertContains('Amount', $errorFields);
        $this->assertContains('Merchant Name', $errorFields);

        // Verify upload record marked as validation failed
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'id' => $uploadId,
            'status' => PocketExpenseFileUpload::STATUS_VALIDATION_FAILED,
            'total_records' => 1,
            'valid_records' => 0,
        ]);

        // Verify no job dispatched for failed validation
        Queue::assertNotPushed(ProcessExpenseUpload::class);

        // Verify no upload data records created
        $this->assertDatabaseCount('pocket_expense_upload_data', 0);
    }

    /**
     * Test CSV upload requires authentication.
     */
    public function test_upload_csv_requires_authentication(): void
    {
        $csvFile = $this->createValidCSVFile();
        $uploadData = $this->getValidUploadData();

        $response = $this->postJson($this->uploadApiPath . '/csv', array_merge($uploadData, [
            'csv_file' => $csvFile,
        ]));

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test CSV upload validates authorization for target user.
     */
    public function test_upload_csv_validates_authorization_for_target_user(): void
    {
        $csvFile = $this->createValidCSVFile();
        $uploadData = $this->getValidUploadData();

        $response = $this->actingAs($this->unauthorizedUser, 'api')
            ->postJson($this->uploadApiPath . '/csv', array_merge($uploadData, [
                'csv_file' => $csvFile,
            ]));

        $response->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJson([
                'success' => false,
                'message' => 'User does not have permission to upload pocket expenses for this client.',
            ]);
    }

    /**
     * Test CSV upload validates required parameters.
     */
    public function test_upload_csv_validates_required_parameters(): void
    {
        $response = $this->