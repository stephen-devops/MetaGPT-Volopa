{
  "Development Plan": [
    "tests/Feature/MassPaymentFileTest.php"
  ],
  "Incremental Change": [
    "tests/Feature/MassPaymentFileTest.php: Comprehensive feature test suite covering mass payment file upload, validation, approval workflow, status tracking, error handling, and integration with authentication, policies, and services"
  ]
}

## Code: tests/Feature/MassPaymentFileTest.php

```php
<?php

namespace Tests\Feature;

use App\Http\Resources\MassPaymentFileResource;
use App\Jobs\ValidateMassPaymentFileJob;
use App\Models\Beneficiary;
use App\Models\Client;
use App\Models\MassPaymentFile;
use App\Models\PaymentInstruction;
use App\Models\TccAccount;
use App\Models\User;
use App\Notifications\PaymentFileApprovalNotification;
use App\Services\CsvValidationService;
use App\Services\MassPaymentFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Carbon\Carbon;

class MassPaymentFileTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test client instance
     */
    protected Client $testClient;

    /**
     * Test user instance
     */
    protected User $testUser;

    /**
     * Test TCC account instance
     */
    protected TccAccount $testTccAccount;

    /**
     * Test admin user instance
     */
    protected User $adminUser;

    /**
     * Another client for multi-tenant testing
     */
    protected Client $otherClient;

    /**
     * User from other client
     */
    protected User $otherUser;

    /**
     * Setup method run before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Fake storage for file uploads
        Storage::fake('local');

        // Fake queue for job testing
        Queue::fake();

        // Fake notifications
        Notification::fake();

        // Create test client
        $this->testClient = Client::factory()->create([
            'name' => 'Test Client Ltd',
            'code' => 'TEST_CLIENT',
            'is_active' => true,
        ]);

        // Create test user with client association
        $this->testUser = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'client_id' => $this->testClient->id,
            'role' => 'operator',
            'is_active' => true,
        ]);

        // Create admin user
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'client_id' => $this->testClient->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create test TCC account
        $this->testTccAccount = TccAccount::factory()->create([
            'client_id' => $this->testClient->id,
            'account_name' => 'Test Funding Account',
            'account_number' => '1234567890',
            'currency' => 'USD',
            'balance' => 1000000.00,
            'available_balance' => 1000000.00,
            'is_active' => true,
            'account_type' => TccAccount::TYPE_FUNDING,
        ]);

        // Create other client for multi-tenant testing
        $this->otherClient = Client::factory()->create([
            'name' => 'Other Client Ltd',
            'code' => 'OTHER_CLIENT',
            'is_active' => true,
        ]);

        // Create user from other client
        $this->otherUser = User::factory()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'client_id' => $this->otherClient->id,
            'role' => 'operator',
            'is_active' => true,
        ]);

        // Set up test configuration
        Config::set('mass-payments.max_file_size_mb', 10);
        Config::set('mass-payments.max_rows_per_file', 10000);
        Config::set('mass-payments.validation.max_amount_per_instruction', 999999.99);
        Config::set('mass-payments.validation.min_amount_per_instruction', 0.01);
        Config::set('mass-payments.supported_currencies', [
            'USD' => 'United States Dollar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound Sterling',
        ]);
        Config::set('mass-payments.purpose_codes', [
            'SALA' => 'Salary Payment',
            'SUPP' => 'Supplier Payment',
            'SERV' => 'Service Payment',
        ]);
    }

    /**
     * Test successful mass payment file upload
     */
    public function test_can_upload_mass_payment_file_successfully(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        // Create test CSV file
        $csvContent = "amount,currency,beneficiary_name,beneficiary_account,bank_code,reference\n" .
                     "100.50,USD,John Doe,1234567890,ABCDUS33,Payment for services\n" .
                     "250.75,USD,Jane Smith,9876543210,ABCDUS33,Salary payment\n";

        $file = UploadedFile::fake()->createWithContent('test-payments.csv', $csvContent);

        $response = $this->postJson('/api/v1/mass-payment-files', [
            'file' => $file,
            'tcc_account_id' => $this->testTccAccount->id,
            'currency' => 'USD',
            'description' => 'Test payment file',
            'notify_on_completion' => true,
            'notify_on_failure' => true,
        ]);

        $response->assertStatus(Response::HTTP_CREATED)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'id',
                        'client_id',
                        'tcc_account_id',
                        'original_filename',
                        'file_size',
                        'currency',
                        'status',
                        'uploaded_by',
                        'created_at',
                        'can_be_approved',
                        'is_draft',
                    ],
                ])
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'client_id' => $this->testClient->id,
                        'tcc_account_id' => $this->testTccAccount->id,
                        'original_filename' => 'test-payments.csv',
                        'currency' => 'USD',
                        'status' => MassPaymentFile::STATUS_DRAFT,
                        'uploaded_by' => $this->testUser->id,
                        'is_draft' => true,
                    ],
                ]);

        // Verify file was stored in database
        $this->assertDatabaseHas('mass_payment_files', [
            'client_id' => $this->testClient->id,
            'tcc_account_id' => $this->testTccAccount->id,
            'original_filename' => 'test-payments.csv',
            'currency' => 'USD',
            'status' => MassPaymentFile::STATUS_DRAFT,
            'uploaded_by' => $this->testUser->id,
        ]);

        // Verify validation job was dispatched
        Queue::assertPushed(ValidateMassPaymentFileJob::class);
    }

    /**
     * Test upload fails with invalid file format
     */
    public function test_upload_fails_with_invalid_file_format(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        $file = UploadedFile::fake()->create('test-file.txt', 100, 'text/plain');

        $response = $this->postJson('/api/v1/mass-payment-files', [
            'file' => $file,
            'tcc_account_id' => $this->testTccAccount->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test upload fails with missing required fields
     */
    public function test_upload_fails_with_missing_required_fields(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        $response = $this->postJson('/api/v1/mass-payment-files', [
            // Missing file and tcc_account_id
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['file', 'tcc_account_id']);
    }

    /**
     * Test upload fails with invalid TCC account
     */
    public function test_upload_fails_with_invalid_tcc_account(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        $csvContent = "amount,currency,beneficiary_name,beneficiary_account,bank_code\n" .
                     "100.50,USD,John Doe,1234567890,ABCDUS33\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->postJson('/api/v1/mass-payment-files', [
            'file' => $file,
            'tcc_account_id' => 99999, // Non-existent account
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['tcc_account_id']);
    }

    /**
     * Test upload fails with TCC account from different client
     */
    public function test_upload_fails_with_other_client_tcc_account(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        // Create TCC account for other client
        $otherTccAccount = TccAccount::factory()->create([
            'client_id' => $this->otherClient->id,
            'account_name' => 'Other Client Account',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $csvContent = "amount,currency,beneficiary_name,beneficiary_account,bank_code\n" .
                     "100.50,USD,John Doe,1234567890,ABCDUS33\n";

        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->postJson('/api/v1/mass-payment-files', [
            'file' => $file,
            'tcc_account_id' => $otherTccAccount->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['tcc_account_id']);
    }

    /**
     * Test file list retrieval with pagination
     */
    public function test_can_list_mass_payment_files_with_pagination(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        // Create multiple files for testing
        $files = MassPaymentFile::factory()->count(25)->create([
            'client_id' => $this->testClient->id,
            'uploaded_by' => $this->testUser->id,
        ]);

        $response = $this->getJson('/api/v1/mass-payment-files?per_page=10');

        $response->assertStatus(Response::HTTP_OK)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        '*' => [
                            'id',
                            'original_filename',
                            'status',
                            'total_amount',
                            'currency',
                            'created_at',
                        ],
                    ],
                    'meta' => [
                        'pagination' => [
                            'current_page',
                            'last_page',
                            'per_page',
                            'total',
                            'from',
                            'to',
                            'has_more_pages',
                        ],
                    ],
                ])
                ->assertJson([
                    'success' => true,
                    'meta' => [
                        'pagination' => [
                            'current_page' => 1,
                            'per_page' => 10,
                            'total' => 25,
                            'has_more_pages' => true,
                        ],
                    ],
                ]);
    }

    /**
     * Test file list filters by status
     */
    public function test_can_filter_files_by_status(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        // Create files with different statuses
        MassPaymentFile::factory()->create([
            'client_id' => $this->testClient->id,
            'status' => MassPaymentFile::STATUS_DRAFT,
        ]);
        
        MassPaymentFile::factory()->create([
            'client_id' => $this->testClient->id,
            'status' => MassPaymentFile::STATUS_AWAITING_APPROVAL,
        ]);
        
        MassPaymentFile::factory()->create([
            'client_id' => $this->testClient->id,
            'status' => MassPaymentFile::STATUS_COMPLETED,
        ]);

        $response = $this->getJson('/api/v1/mass-payment-files?status=awaiting_approval');

        $response->assertStatus(Response::HTTP_OK)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.status', MassPaymentFile::STATUS_AWAITING_APPROVAL);
    }

    /**
     * Test cannot access files from other clients
     */
    public function test_cannot_access_files_from_other_clients(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        // Create file for other client
        $otherFile = MassPaymentFile::factory()->create([
            'client_id' => $this->otherClient->id,
            'uploaded_by' => $this->otherUser->id,
        ]);

        $response = $this->getJson('/api/v1/mass-payment-files');

        $response->assertStatus(Response::HTTP_OK);
        
        // Should not contain the other client's file
        $fileIds = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($otherFile->id, $fileIds);
    }

    /**
     * Test can view individual file details
     */
    public function test_can_view_file_details(): void
    {
        $this->actingAs($this->testUser, 'sanctum');

        $file = MassPaymentFile::factory()->create([
            'client_id' => $this->testClient->id,
            'tcc_account_id' => $this->testTccAccount->id,
            'uploaded_by' => $this->testUser->id,
            'currency' => 'USD',
            'total_amount' => 1250.75,
            'status' => MassPaymentFile::STATUS_AWAITING_APPROVAL,
        