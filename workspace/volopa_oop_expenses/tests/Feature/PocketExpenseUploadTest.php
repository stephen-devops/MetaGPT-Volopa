<?php

namespace Tests\Feature;

use App\Jobs\ProcessExpenseUpload;
use App\Models\Client;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\PocketExpenseUploadsData;
use App\Models\User;
use App\Services\PocketExpenseCSVValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pocket Expense Upload Feature Tests
 * 
 * Tests CSV batch upload functionality including validation, processing,
 * authorization, error handling, and background job queuing.
 * Covers the full upload flow from API request to job processing.
 */
class PocketExpenseUploadTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $adminUser;
    protected User $targetUser;
    protected Client $client;
    protected OptPocketExpenseType $expenseType;
    protected PocketExpenseSourceClientConfig $expenseSource;

    /**
     * Set up test data before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test users
        $this->adminUser = User::factory()->create();
        $this->targetUser = User::factory()->create();
        
        // Create test client
        $this->client = Client::factory()->create();
        
        // Create expense type
        $this->expenseType = OptPocketExpenseType::factory()->pointOfSale()->create();
        
        // Create expense source
        $this->expenseSource = PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->corporateCard()
            ->create();
        
        // Set up fake storage for file testing
        Storage::fake('local');
        
        // Clear queue for testing
        Queue::fake();
    }

    /**
     * Test successful CSV upload with valid data.
     */
    public function test_successful_csv_upload_with_valid_data(): void
    {
        // Arrange: Create valid CSV content
        $csvContent = $this->createValidCSVContent();
        $file = UploadedFile::fake()->createWithContent('test_expenses.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Response structure and success
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'total_rows' => 3, // Header + 2 data rows
                ])
                ->assertJsonStructure([
                    'success',
                    'message',
                    'upload_id',
                    'total_rows'
                ]);
        
        // Assert: Upload record created
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => 'processing',
            'total_records' => 2, // Data rows only
        ]);
        
        // Assert: Job dispatched
        Queue::assertPushed(ProcessExpenseUpload::class);
    }

    /**
     * Test CSV upload validation failure with invalid data.
     */
    public function test_csv_upload_validation_failure(): void
    {
        // Arrange: Create invalid CSV content
        $csvContent = $this->createInvalidCSVContent();
        $file = UploadedFile::fake()->createWithContent('invalid_expenses.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Validation error response
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
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
                            'value'
                        ]
                    ]
                ]);
        
        // Assert: No job dispatched on validation failure
        Queue::assertNothingPushed();
    }

    /**
     * Test file size validation (max 10MB constraint).
     */
    public function test_file_size_validation(): void
    {
        // Arrange: Create oversized file (11MB)
        $file = UploadedFile::fake()->create('large_file.csv', 11000); // 11MB
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: File size validation error
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test file type validation (CSV/TXT only).
     */
    public function test_file_type_validation(): void
    {
        // Arrange: Create invalid file type
        $file = UploadedFile::fake()->create('document.pdf', 1000);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: File type validation error
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test maximum rows validation (200 rows constraint).
     */
    public function test_maximum_rows_validation(): void
    {
        // Arrange: Create CSV with too many rows
        $csvContent = $this->createCSVWithTooManyRows();
        $file = UploadedFile::fake()->createWithContent('too_many_rows.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Row count validation error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'file')
                ->assertJsonPath('errors.0.error', 'Maximum 200 rows allowed');
    }

    /**
     * Test missing header validation.
     */
    public function test_missing_header_validation(): void
    {
        // Arrange: Create CSV without proper headers
        $csvContent = "Invalid,Header,Structure\n10/01/2024,Point of Sale,USD,100.00";
        $file = UploadedFile::fake()->createWithContent('no_headers.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Header validation error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'headers')
                ->assertJsonPath('errors.0.error', 'Invalid CSV headers');
    }

    /**
     * Test user authorization validation.
     */
    public function test_user_authorization_validation(): void
    {
        // Arrange: Create another client
        $otherClient = Client::factory()->create();
        $csvContent = $this->createValidCSVContent();
        $file = UploadedFile::fake()->createWithContent('test_expenses.csv', $csvContent);
        
        // Act: Try to upload for user in different client
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $otherClient->id, // Different client
        ]);
        
        // Assert: Authorization error
        $response->assertStatus(422)
                ->assertJsonValidationErrors(['expense_user_id']);
    }

    /**
     * Test date validation (not older than 3 years constraint).
     */
    public function test_date_validation(): void
    {
        // Arrange: Create CSV with old dates
        $csvContent = $this->createCSVWithOldDates();
        $file = UploadedFile::fake()->createWithContent('old_dates.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Date validation error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'Date')
                ->assertJsonPath('errors.0.error', 'Date cannot be older than 3 years');
    }

    /**
     * Test currency validation.
     */
    public function test_currency_validation(): void
    {
        // Arrange: Create CSV with invalid currency
        $csvContent = $this->createCSVWithInvalidCurrency();
        $file = UploadedFile::fake()->createWithContent('invalid_currency.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Currency validation error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'Currency Code')
                ->assertJsonPath('errors.0.error', 'Invalid currency code');
    }

    /**
     * Test expense type validation.
     */
    public function test_expense_type_validation(): void
    {
        // Arrange: Create CSV with invalid expense type
        $csvContent = $this->createCSVWithInvalidExpenseType();
        $file = UploadedFile::fake()->createWithContent('invalid_type.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Expense type validation error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'Expense Type')
                ->assertJsonPath('errors.0.error', 'Invalid expense type');
    }

    /**
     * Test source validation with "Other" requiring source note.
     */
    public function test_source_other_requires_note(): void
    {
        // Arrange: Create CSV with "Other" source but no note
        $csvContent = $this->createCSVWithSourceOtherNoNote();
        $file = UploadedFile::fake()->createWithContent('source_other.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Source note required error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'Source Note')
                ->assertJsonPath('errors.0.error', 'Source Note is required when Source is Other');
    }

    /**
     * Test VAT percentage validation.
     */
    public function test_vat_percentage_validation(): void
    {
        // Arrange: Create CSV with invalid VAT percentage
        $csvContent = $this->createCSVWithInvalidVAT();
        $file = UploadedFile::fake()->createWithContent('invalid_vat.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: VAT validation error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'VAT %')
                ->assertJsonPath('errors.0.error', 'VAT percentage must be between 0-100');
    }

    /**
     * Test merchant name length validation (180 chars max).
     */
    public function test_merchant_name_length_validation(): void
    {
        // Arrange: Create CSV with too long merchant name
        $csvContent = $this->createCSVWithLongMerchantName();
        $file = UploadedFile::fake()->createWithContent('long_merchant.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Merchant name length error
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ])
                ->assertJsonPath('errors.0.field', 'Merchant Name')
                ->assertJsonPath('errors.0.error', 'Merchant Name cannot exceed 180 characters');
    }

    /**
     * Test upload status tracking.
     */
    public function test_upload_status_tracking(): void
    {
        // Arrange: Create valid CSV and upload
        $csvContent = $this->createValidCSVContent();
        $file = UploadedFile::fake()->createWithContent('test_expenses.csv', $csvContent);
        
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        $uploadId = $response->json('upload_id');
        
        // Act: Check upload status
        $statusResponse = $this->getJson("/api/uploads/pocket-expense/{$uploadId}/status");
        
        // Assert: Status response structure
        $statusResponse->assertStatus(200)
                      ->assertJsonStructure([
                          'id',
                          'status',
                          'total_records',
                          'valid_records',
                          'validation_errors',
                          'uploaded_at',
                          'validated_at',
                          'processed_at'
                      ]);
    }

    /**
     * Test uploads data table population.
     */
    public function test_uploads_data_table_population(): void
    {
        // Arrange: Create valid CSV
        $csvContent = $this->createValidCSVContent();
        $file = UploadedFile::fake()->createWithContent('test_expenses.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        $uploadId = $response->json('upload_id');
        
        // Assert: Data rows created in uploads data table
        $this->assertDatabaseHas('pocket_expense_uploads_data', [
            'upload_id' => $uploadId,
            'line_number' => 2, // First data row (after header)
            'status' => 'pending'
        ]);
        
        $this->assertDatabaseHas('pocket_expense_uploads_data', [
            'upload_id' => $uploadId,
            'line_number' => 3, // Second data row
            'status' => 'pending'
        ]);
    }

    /**
     * Test all-or-nothing validation constraint.
     */
    public function test_all_or_nothing_validation(): void
    {
        // Arrange: Create mixed valid/invalid CSV
        $csvContent = $this->createMixedValidInvalidCSV();
        $file = UploadedFile::fake()->createWithContent('mixed_data.csv', $csvContent);
        
        // Act: Submit upload request
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: All validation failed, no processing
        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                ]);
        
        // Assert: No job dispatched due to validation failure
        Queue::assertNothingPushed();
        
        // Assert: No data rows in pending state
        $this->assertDatabaseMissing('pocket_expense_uploads_data', [
            'status' => 'pending'
        ]);
    }

    /**
     * Test database transaction rollback on error.
     */
    public function test_database_transaction_rollback(): void
    {
        // This test would require mocking database failures
        // TODO: Implement database transaction testing with mocked failures
        $this->markTestIncomplete('Database transaction rollback testing requires DB mocking');
    }

    /**
     * Test authentication middleware.
     */
    public function test_authentication_required(): void
    {
        // Arrange: Create valid CSV without authentication
        $csvContent = $this->createValidCSVContent();
        $file = UploadedFile::fake()->createWithContent('test_expenses.csv', $csvContent);
        
        // Act: Submit request without authentication
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);
        
        // Assert: Authentication required
        // TODO: Implement proper OAuth2 middleware testing
        $response->assertStatus(401);
    }

    /**
     * Test client feature enablement check.
     */
    public function test_client_feature_enablement(): void
    {
        // TODO: Mock ClientFeatures model to test feature_id=16 enablement check
        $this->markTestIncomplete('Client feature enablement requires ClientFeatures model');
    }

    /**
     * Helper: Create valid CSV content.
     */
    private function createValidCSVContent(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '10/01/2024,Point of Sale,USD,100.00,,10,Test Merchant,Test purchase,123 Main St,USA,Corporate Card,,Test note';
        $row2 = '11/01/2024,ATM Withdrawal,EUR,50.00,,0,ATM Bank,Cash withdrawal,456 Bank St,DEU,Cash,,Cash withdrawal';
        
        return implode("\n", [$header, $row1, $row2]);
    }

    /**
     * Helper: Create invalid CSV content.
     */
    private function createInvalidCSVContent(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '2020-01-01,Invalid Type,XXX,not_a_number,,150%,Very Long Merchant Name That Exceeds The Database Limit Of 180 Characters And Should Fail Validation Because It Is Too Long For The Database Field,Test,Test,Invalid Country,Invalid Source,,Test';
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with too many rows.
     */
    private function createCSVWithTooManyRows(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $rows = [$header];
        
        // Create 201 data rows (exceeds 200 limit)
        for ($i = 1; $i <= 201; $i++) {
            $rows[] = "10/01/2024,Point of Sale,USD,{$i}.00,,10,Merchant {$i},Purchase {$i},Address {$i},USA,Cash,,Note {$i}";
        }
        
        return implode("\n", $rows);
    }

    /**
     * Helper: Create CSV with old dates (older than 3 years).
     */
    private function createCSVWithOldDates(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '01/01/2020,Point of Sale,USD,100.00,,10,Test Merchant,Test purchase,123 Main St,USA,Cash,,Old transaction';
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with invalid currency.
     */
    private function createCSVWithInvalidCurrency(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '10/01/2024,Point of Sale,INVALID,100.00,,10,Test Merchant,Test purchase,123 Main St,USA,Cash,,Test note';
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with invalid expense type.
     */
    private function createCSVWithInvalidExpenseType(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '10/01/2024,Invalid Expense Type,USD,100.00,,10,Test Merchant,Test purchase,123 Main St,USA,Cash,,Test note';
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with "Other" source but no note.
     */
    private function createCSVWithSourceOtherNoNote(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '10/01/2024,Point of Sale,USD,100.00,,10,Test Merchant,Test purchase,123 Main St,USA,Other,,Test note';
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with invalid VAT percentage.
     */
    private function createCSVWithInvalidVAT(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = '10/01/2024,Point of Sale,USD,100.00,,150%,Test Merchant,Test purchase,123 Main St,USA,Cash,,Test note';
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with merchant name too long.
     */
    private function createCSVWithLongMerchantName(): string
    {
        $longName = str_repeat('A', 200); // Exceeds 180 char limit
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $row1 = "10/01/2024,Point of Sale,USD,100.00,,10,{$longName},Test purchase,123 Main St,USA,Cash,,Test note";
        
        return implode("\n", [$header, $row1]);
    }

    /**
     * Helper: Create CSV with mixed valid/invalid data.
     */
    private function createMixedValidInvalidCSV(): string
    {
        $header = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';
        $validRow = '10/01/2024,Point of Sale,USD,100.00,,10,Test Merchant,Test purchase,123 Main St,USA,Cash,,Test note';
        $invalidRow = '2020-01-01,Invalid Type,XXX,not_a_number,,150%,Test Merchant,Test,Test,USA,Cash,,Test';
        
        return implode("\n", [$header, $validRow, $invalidRow]);
    }
}