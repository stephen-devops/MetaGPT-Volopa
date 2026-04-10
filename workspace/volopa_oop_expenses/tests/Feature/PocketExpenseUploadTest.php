<?php

namespace Tests\Feature;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\OptPocketExpenseType;
use App\Models\User;
use App\Models\Client;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Carbon\Carbon;

class PocketExpenseUploadTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $adminUser;
    private User $targetUser;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test client
        $this->client = Client::factory()->create([
            'name' => 'Test Client',
            'deleted' => 0,
        ]);

        // Create admin user who uploads files
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'username' => 'admin@test.com',
            'deleted' => 0,
        ]);

        // Create target user for whom expenses are created
        $this->targetUser = User::factory()->create([
            'name' => 'Target User',
            'username' => 'target@test.com',
            'deleted' => 0,
        ]);

        // Seed expense types
        OptPocketExpenseType::factory()->atmWithdrawal()->create();
        OptPocketExpenseType::factory()->pointOfSale()->create();
        OptPocketExpenseType::factory()->feeAndCharges()->create();
        OptPocketExpenseType::factory()->refund()->create();

        // Create default expense sources for client
        PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->cash()
            ->create();
            
        PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->corporateCard()
            ->create();
            
        PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->personalCard()
            ->create();

        // Fake storage for file uploads
        Storage::fake('local');
    }

    /** @test */
    public function it_can_upload_valid_csv_file_successfully()
    {
        Queue::fake();

        // Create valid CSV content
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Cash,,Test notes'
        ]);

        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'upload_id',
                    'total_rows'
                ])
                ->assertJson([
                    'success' => true,
                    'total_rows' => 1
                ]);

        // Assert upload record was created
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'created_by_user_id' => $this->adminUser->id,
            'total_records' => 1,
            'status' => 'processing'
        ]);

        // Assert upload data was created
        $upload = PocketExpenseFileUpload::first();
        $this->assertDatabaseHas('pocket_expense_uploads_data', [
            'upload_id' => $upload->id,
            'line_number' => 2, // Header is line 1
            'status' => 'pending'
        ]);

        // Assert job was dispatched
        Queue::assertPushed(ProcessExpenseUpload::class, function ($job) use ($upload) {
            return $job->upload_id === $upload->id;
        });
    }

    /** @test */
    public function it_validates_file_format_and_size()
    {
        // Test invalid file type
        $invalidFile = UploadedFile::fake()->create('test.pdf', 100);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $invalidFile,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);

        // Test oversized file (over 10MB)
        $largeFile = UploadedFile::fake()->create('large.csv', 11000); // 11MB

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $largeFile,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
    }

    /** @test */
    public function it_validates_required_form_fields()
    {
        $file = UploadedFile::fake()->createWithContent('test.csv', 'test content');

        // Test missing user_id
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['user_id']);

        // Test missing expense_user_id
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['expense_user_id']);

        // Test missing client_id
        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['client_id']);
    }

    /** @test */
    public function it_validates_csv_headers()
    {
        // Create CSV with invalid headers
        $csvContent = implode("\n", [
            'Wrong,Headers,Here',
            '01/01/2024,Point of Sale,USD'
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'upload_id',
                    'total_rows',
                    'error_count',
                    'errors'
                ])
                ->assertJson([
                    'success' => false
                ]);

        // Assert upload record was created with validation_failed status
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'status' => 'validation_failed'
        ]);
    }

    /** @test */
    public function it_validates_csv_row_data()
    {
        // Create CSV with invalid row data
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '2020-01-01,Invalid Type,XXX,not-a-number,,150%,,,,,Invalid Source,,' // Multiple validation errors
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
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
                ])
                ->assertJson([
                    'success' => false,
                    'error_count' => 6 // Multiple validation errors expected
                ]);

        // Assert upload record shows validation errors
        $upload = PocketExpenseFileUpload::first();
        $this->assertNotNull($upload->validation_errors);
        $this->assertEquals('validation_failed', $upload->status);
    }

    /** @test */
    public function it_validates_date_format_and_age()
    {
        // Test invalid date format
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '2024-01-01,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Cash,,' // Wrong format (YYYY-MM-DD instead of DD/MM/YYYY)
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid_date.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);

        // Test date older than 3 years
        $oldDate = Carbon::now()->subYears(4)->format('d/m/Y');
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            "{$oldDate},Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Cash,,"
        ]);

        $file = UploadedFile::fake()->createWithContent('old_date.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_validates_amount_sign_based_on_expense_type()
    {
        // Test that refund amount is positive
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Refund from Merchant,USD,50.00,,5%,Test Merchant,Test refund,123 Main St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('refund.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);

        // Test that negative expense types work with negative amounts
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,ATM Withdrawal,USD,50.00,,5%,Test ATM,Cash withdrawal,123 Main St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('withdrawal.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function it_validates_vat_percentage()
    {
        // Test VAT percentage over 100
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,150%,Test Merchant,Test purchase,123 Main St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid_vat.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);

        // Test valid VAT percentage
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,20%,Test Merchant,Test purchase,123 Main St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('valid_vat.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function it_validates_expense_source()
    {
        // Test invalid source
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Invalid Source,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid_source.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);

        // Test 'Other' source without source note (should fail)
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Other,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('other_no_note.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);

        // Test 'Other' source with source note (should pass)
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Other,Custom source note,'
        ]);

        $file = UploadedFile::fake()->createWithContent('other_with_note.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function it_validates_merchant_name_length()
    {
        // Test merchant name exceeding VARCHAR(180) limit
        $longMerchantName = str_repeat('A', 181);
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            "01/01/2024,Point of Sale,USD,50.00,,5%,{$longMerchantName},Test purchase,123 Main St,US,Cash,,"
        ]);

        $file = UploadedFile::fake()->createWithContent('long_merchant.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_enforces_maximum_200_rows_constraint()
    {
        // Create CSV with 201 data rows (exceeds 200 limit)
        $csvLines = ['Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes'];
        
        for ($i = 1; $i <= 201; $i++) {
            $csvLines[] = "01/01/2024,Point of Sale,USD,{$i}.00,,5%,Merchant {$i},Purchase {$i},123 Main St,US,Cash,,";
        }

        $csvContent = implode("\n", $csvLines);
        $file = UploadedFile::fake()->createWithContent('too_many_rows.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_handles_all_or_nothing_validation()
    {
        // Mix of valid and invalid rows - should fail all
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Valid Merchant,Valid purchase,123 Main St,US,Cash,,', // Valid row
            'invalid-date,Invalid Type,XXX,not-a-number,,150%,,,,,Invalid Source,,' // Invalid row
        ]);

        $file = UploadedFile::fake()->createWithContent('mixed_validity.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422);

        // No expenses should be created due to all-or-nothing constraint
        $this->assertDatabaseMissing('pocket_expense_uploads_data', [
            'status' => 'synced'
        ]);

        // No background job should be dispatched
        Queue::assertNotPushed(ProcessExpenseUpload::class);
    }

    /** @test */
    public function it_validates_user_belongs_to_client()
    {
        // Create another client
        $otherClient = Client::factory()->create();

        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $otherClient->id, // Target user doesn't belong to this client
        ]);

        $response->assertStatus(422)
                ->assertJsonStructure(['message']);
    }

    /** @test */
    public function it_stores_file_in_correct_directory()
    {
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('expense_upload.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);

        $upload = PocketExpenseFileUpload::first();
        
        // Assert file path follows the expected pattern
        $this->assertStringContains('pocket-expense-uploads', $upload->file_path);
        $this->assertEquals('expense_upload.csv', $upload->file_name);

        // Assert file actually exists in storage
        $this->assertTrue(Storage::disk('local')->exists(str_replace('storage/app/', '', $upload->file_path)));
    }

    /** @test */
    public function it_creates_upload_record_with_correct_metadata()
    {
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant 1,Test purchase 1,123 Main St,US,Cash,,',
            '02/01/2024,ATM Withdrawal,EUR,75.00,,0%,Test ATM,Cash withdrawal,456 Bank St,US,Cash,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('multi_row.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);

        $upload = PocketExpenseFileUpload::first();

        // Assert upload metadata is correct
        $this->assertEquals($this->targetUser->id, $upload->user_id);
        $this->assertEquals($this->client->id, $upload->client_id);
        $this->assertEquals($this->adminUser->id, $upload->created_by_user_id);
        $this->assertEquals(2, $upload->total_records);
        $this->assertEquals(2, $upload->valid_records);
        $this->assertEquals('processing', $upload->status);
        $this->assertNotNull($upload->uploaded_at);
        $this->assertNotNull($upload->validated_at);
        $this->assertNull($upload->validation_errors);
    }

    /** @test */
    public function it_creates_upload_data_records_for_valid_rows()
    {
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            '01/01/2024,Point of Sale,USD,50.00,,5%,Test Merchant,Test purchase,123 Main St,US,Cash,,Test note'
        ]);

        $file = UploadedFile::fake()->createWithContent('test.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);

        $upload = PocketExpenseFileUpload::first();
        $uploadData = PocketExpenseUploadsData::where('upload_id', $upload->id)->first();

        $this->assertNotNull($uploadData);
        $this->assertEquals(2, $uploadData->line_number); // Header is line 1
        $this->assertEquals('pending', $uploadData->status);

        $expenseData = json_decode($uploadData->expense_data, true);
        $this->assertEquals('01/01/2024', $expenseData['date']);
        $this->assertEquals('Point of Sale', $expenseData['expense_type']);
        $this->assertEquals('USD', $expenseData['currency_code']);
        $this->assertEquals('50.00', $expenseData['amount']);
        $this->assertEquals('5%', $expenseData['vat_percent']);
        $this->assertEquals('Test Merchant', $expenseData['merchant_name']);
        $this->assertEquals('Test note', $expenseData['notes']);
    }

    /** @test */
    public function it_returns_proper_error_response_structure()
    {
        // Create CSV with validation errors
        $csvContent = implode("\n", [
            'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes',
            'invalid-date,Invalid Type,XXX,not-a-number,,150%,,,,,Invalid Source,,'
        ]);

        $file = UploadedFile::fake()->createWithContent('invalid.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
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
                ])
                ->assertJson([
                    'success' => false,
                    'total_rows' => 1
                ]);

        $responseData = $response->json();
        $this->assertGreaterThan(0, $responseData['error_count']);
        $this->assertIsArray($responseData['errors']);
        $this->assertNotEmpty($responseData['errors']);
    }

    /** @test */
    public function it_handles_empty_csv_file()
    {
        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false
                ]);
    }

    /** @test */
    public function it_handles_csv_with_only_headers()
    {
        $csvContent = 'Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes';

        $file = UploadedFile::fake()->createWithContent('headers_only.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'success',
                    'message',
                ])
                ->assertJson([
                    'success' => false
                ]);
    }
}