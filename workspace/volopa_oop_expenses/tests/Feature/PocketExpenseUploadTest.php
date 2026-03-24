## CHECK PLAN

- PocketExpenseUploadTest (test_class) -> NEW -> rag_collision_check("PocketExpenseUploadTest") -> 0 hits, create at tests/Feature/PocketExpenseUploadTest.php
- User (eloquent_model) -> EXISTING -> rag_symbol_lookup("User", "eloquent_model") -> 1 hit, table: users
- Client (eloquent_model) -> EXISTING -> rag_symbol_lookup("Client", "eloquent_model") -> 1 hit, table: clients
- PocketExpenseFileUpload (eloquent_model) -> NEW -> rag_collision_check("PocketExpenseFileUpload") -> 0 hits, create at app/Models/PocketExpenseFileUpload.php
- PocketExpenseUploadsData (eloquent_model) -> NEW -> rag_collision_check("PocketExpenseUploadsData") -> 0 hits, create at app/Models/PocketExpenseUploadsData.php
- PocketExpense (eloquent_model) -> NEW -> rag_collision_check("PocketExpense") -> 0 hits, create at app/Models/PocketExpense.php
- OptPocketExpenseType (eloquent_model) -> NEW -> rag_collision_check("OptPocketExpenseType") -> 0 hits, create at app/Models/OptPocketExpenseType.php
- PocketExpenseSourceClientConfig (eloquent_model) -> NEW -> rag_collision_check("PocketExpenseSourceClientConfig") -> 0 hits, create at app/Models/PocketExpenseSourceClientConfig.php
- UserFeaturePermission (eloquent_model) -> NEW -> rag_collision_check("UserFeaturePermission") -> 0 hits, create at app/Models/UserFeaturePermission.php
- PocketExpenseUploadController (controller) -> NEW -> rag_collision_check("PocketExpenseUploadController") -> 0 hits, create at app/Http/Controllers/PocketExpenseUploadController.php
- ProcessExpenseUpload (job) -> NEW -> rag_collision_check("ProcessExpenseUpload") -> 0 hits, create at app/Jobs/ProcessExpenseUpload.php
- Oauth2UserClient (middleware) -> EXISTING -> rag_symbol_lookup("Oauth2UserClient", "middleware") -> 1 hit, applied_to: routes/api.php

## Code: tests/Feature/PocketExpenseUploadTest.php

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\UserFeaturePermission;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Carbon\Carbon;

class PocketExpenseUploadTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * The authenticated admin user for testing.
     *
     * @var User
     */
    protected User $adminUser;

    /**
     * The target user for expense uploads.
     *
     * @var User
     */
    protected User $targetUser;

    /**
     * The client context for testing.
     *
     * @var Client
     */
    protected Client $client;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test client
        $this->client = Client::factory()->create([
            'name' => 'Test Client Corp',
            'deleted' => false,
        ]);

        // Create admin user with proper permissions
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'deleted' => false,
        ]);

        // Create target user for expense uploads
        $this->targetUser = User::factory()->create([
            'name' => 'Target User',
            'deleted' => false,
        ]);

        // Create user feature permission allowing admin to manage target user
        UserFeaturePermission::factory()->create([
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => 16, // OOP Expenses feature
            'grantor_id' => $this->adminUser->id,
            'manager_user_id' => $this->adminUser->id,
            'is_enabled' => true,
        ]);

        // Seed required expense types
        $this->seedExpenseTypes();

        // Create default expense sources for client
        $this->seedExpenseSources();

        // Set up fake storage for file uploads
        Storage::fake('local');

        // Set up queue for background job testing
        Queue::fake();

        // Authenticate as admin user for API requests
        $this->actingAs($this->adminUser, 'api');
    }

    /**
     * Seed required expense types for testing.
     *
     * @return void
     */
    protected function seedExpenseTypes(): void
    {
        OptPocketExpenseType::factory()->create([
            'option' => 'ATM Withdrawal',
            'amount_sign' => 'negative',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        OptPocketExpenseType::factory()->create([
            'option' => 'Point of Sale',
            'amount_sign' => 'negative',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        OptPocketExpenseType::factory()->create([
            'option' => 'Fee & Charges',
            'amount_sign' => 'negative',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        OptPocketExpenseType::factory()->create([
            'option' => 'Refund from Merchant',
            'amount_sign' => 'positive',
            'is_active' => true,
            'sort_order' => 4,
        ]);
    }

    /**
     * Seed required expense sources for testing.
     *
     * @return void
     */
    protected function seedExpenseSources(): void
    {
        // Create default sources for client
        PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Cash',
            'is_default' => true,
            'deleted' => false,
        ]);

        PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Corporate Card',
            'is_default' => false,
            'deleted' => false,
        ]);

        PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Personal Card',
            'is_default' => false,
            'deleted' => false,
        ]);

        // Global 'Other' source should already exist from migration seeder
        if (!PocketExpenseSourceClientConfig::where('client_id', null)->where('name', 'Other')->exists()) {
            PocketExpenseSourceClientConfig::factory()->create([
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
                'deleted' => false,
            ]);
        }
    }

    /**
     * Create a valid CSV file for testing.
     *
     * @param array $rows
     * @return UploadedFile
     */
    protected function createValidCsv(array $rows = []): UploadedFile
    {
        if (empty($rows)) {
            $rows = [
                [
                    'Date' => '15/12/2023',
                    'Expense Type' => 'Point of Sale',
                    'Currency Code' => 'USD',
                    'Amount' => '50.00',
                    'USD Equivalent Amount' => '50.00',
                    'VAT %' => '10%',
                    'Merchant Name' => 'Test Merchant',
                    'Description' => 'Test purchase',
                    'Merchant Address' => '123 Test St',
                    'Merchant Country' => 'United States',
                    'Source' => 'Cash',
                    'Source Note' => '',
                    'Notes' => 'Test expense note',
                ],
                [
                    'Date' => '14/12/2023',
                    'Expense Type' => 'ATM Withdrawal',
                    'Currency Code' => 'EUR',
                    'Amount' => '100.00',
                    'USD Equivalent Amount' => '108.50',
                    'VAT %' => '',
                    'Merchant Name' => 'ATM Machine',
                    'Description' => 'Cash withdrawal',
                    'Merchant Address' => '',
                    'Merchant Country' => '',
                    'Source' => 'Corporate Card',
                    'Source Note' => '',
                    'Notes' => 'Emergency cash',
                ],
            ];
        }

        $csvContent = $this->arrayToCsv($rows);
        return UploadedFile::fake()->createWithContent('expenses.csv', $csvContent);
    }

    /**
     * Create a CSV file with validation errors.
     *
     * @return UploadedFile
     */
    protected function createInvalidCsv(): UploadedFile
    {
        $rows = [
            [
                'Date' => '32/13/2023', // Invalid date
                'Expense Type' => 'Invalid Type', // Non-existent expense type
                'Currency Code' => 'INVALID', // Invalid currency
                'Amount' => 'not-a-number', // Invalid amount
                'USD Equivalent Amount' => '',
                'VAT %' => '150%', // Invalid VAT percentage
                'Merchant Name' => '', // Missing required field
                'Description' => 'Test purchase',
                'Merchant Address' => '',
                'Merchant Country' => '',
                'Source' => 'Non-existent Source', // Invalid source
                'Source Note' => '',
                'Notes' => 'Test expense note',
            ],
        ];

        $csvContent = $this->arrayToCsv($rows);
        return UploadedFile::fake()->createWithContent('invalid_expenses.csv', $csvContent);
    }

    /**
     * Create a CSV file that's too large.
     *
     * @return UploadedFile
     */
    protected function createOversizedCsv(): UploadedFile
    {
        // Create a large CSV that exceeds 10MB limit
        $rows = [];
        $header = [
            'Date', 'Expense Type', 'Currency Code', 'Amount', 'USD Equivalent Amount',
            'VAT %', 'Merchant Name', 'Description', 'Merchant Address', 'Merchant Country',
            'Source', 'Source Note', 'Notes'
        ];

        // Add header
        $rows[] = $header;

        // Generate many rows to exceed size limit
        for ($i = 0; $i < 50000; $i++) {
            $rows[] = [
                '15/12/2023',
                'Point of Sale',
                'USD',
                '50.00',
                '50.00',
                '10%',
                'Test Merchant ' . $i,
                'Test purchase description that is quite long to increase file size',
                '123 Test Street, City, State, Country with very long address',
                'United States',
                'Cash',
                '',
                'Very long notes field with lots of text to increase the overall file size significantly',
            ];
        }

        $csvContent = $this->arrayToCsv($rows);
        return UploadedFile::fake()->createWithContent('oversized_expenses.csv', $csvContent);
    }

    /**
     * Create a CSV file with too many rows.
     *
     * @return UploadedFile
     */
    protected function createTooManyRowsCsv(): UploadedFile
    {
        $rows = [];
        
        // Generate more than 200 rows (exceeding constraint)
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => "Test Merchant {$i}",
                'Description' => "Test purchase {$i}",
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Cash',
                'Source Note' => '',
                'Notes' => "Test expense note {$i}",
            ];
        }

        $csvContent = $this->arrayToCsv($rows);
        return UploadedFile::fake()->createWithContent('too_many_rows.csv', $csvContent);
    }

    /**
     * Create a CSV with missing header row.
     *
     * @return UploadedFile
     */
    protected function createCsvWithoutHeader(): UploadedFile
    {
        $csvContent = "15/12/2023,Point of Sale,USD,50.00,50.00,10%,Test Merchant,Test purchase,123 Test St,United States,Cash,,Test note\n";
        return UploadedFile::fake()->createWithContent('no_header.csv', $csvContent);
    }

    /**
     * Create a CSV with 'Other' source requiring source note.
     *
     * @return UploadedFile
     */
    protected function createCsvWithOtherSource(): UploadedFile
    {
        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => 'Test Merchant',
                'Description' => 'Test purchase',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Other',
                'Source Note' => 'Custom payment method via mobile app',
                'Notes' => 'Test expense note',
            ],
        ];

        $csvContent = $this->arrayToCsv($rows);
        return UploadedFile::fake()->createWithContent('other_source.csv', $csvContent);
    }

    /**
     * Create a CSV with old dates (older than 3 years).
     *
     * @return UploadedFile
     */
    protected function createCsvWithOldDates(): UploadedFile
    {
        $oldDate = Carbon::now()->subYears(4)->format('d/m/Y');
        
        $rows = [
            [
                'Date' => $oldDate,
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => 'Test Merchant',
                'Description' => 'Old purchase',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Cash',
                'Source Note' => '',
                'Notes' => 'Old expense',
            ],
        ];

        $csvContent = $this->arrayToCsv($rows);
        return UploadedFile::fake()->createWithContent('old_dates.csv', $csvContent);
    }

    /**
     * Convert array data to CSV format.
     *
     * @param array $rows
     * @return string
     */
    protected function arrayToCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $output = '';
        
        // Add header row
        $header = [
            'Date', 'Expense Type', 'Currency Code', 'Amount', 'USD Equivalent Amount',
            'VAT %', 'Merchant Name', 'Description', 'Merchant Address', 'Merchant Country',
            'Source', 'Source Note', 'Notes'
        ];
        $output .= implode(',', array_map([$this, 'escapeCsvField'], $header)) . "\n";

        // Add data rows
        foreach ($rows as $row) {
            $csvRow = [];
            foreach ($header as $column) {
                $csvRow[] = $this->escapeCsvField($row[$column] ?? '');
            }
            $output .= implode(',', $csvRow) . "\n";
        }

        return $output;
    }

    /**
     * Escape CSV field for proper formatting.
     *
     * @param string $field
     * @return string
     */
    protected function escapeCsvField(string $field): string
    {
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }

    /**
     * Test successful CSV upload with valid data.
     *
     * @return void
     */
    public function testSuccessfulCsvUpload(): void
    {
        $file = $this->createValidCsv();

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
                'total_rows',
            ])
            ->assertJson([
                'success' => true,
                'total_rows' => 2,
            ]);

        // Verify upload record was created
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => 'validation_passed',
            'total_records' => 2,
            'valid_records' => 2,
        ]);

        // Verify staging data was created
        $this->assertDatabaseCount('pocket_expense_uploads_data', 2);

        // Verify background job was dispatched
        Queue::assertPushed(ProcessExpenseUpload::class);
    }

    /**
     * Test CSV upload with validation errors.
     *
     * @return void
     */
    public function testCsvUploadWithValidationErrors(): void
    {
        $file = $this->createInvalidCsv();

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
                        'value',
                    ],
                ],
            ])
            ->assertJson([
                'success' => false,
            ]);

        // Verify upload record was created with errors
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'created_by_user_id' => $this->adminUser->id,
            'status' => 'validation_failed',
            'total_records' => 1,
            'valid_records' => 0,
        ]);

        // Verify no expenses were created due to all-or-nothing validation
        $this->assertDatabaseCount('pocket_expense', 0);

        // Verify no background job was dispatched
        Queue::assertNotPushed(ProcessExpenseUpload::class);
    }

    /**
     * Test file size validation (max 10MB).
     *
     * @return void
     */
    public function testFileSizeValidation(): void
    {
        $file = $this->createOversizedCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test maximum rows validation (max 200 rows).
     *
     * @return void
     */
    public function testMaxRowsValidation(): void
    {
        $file = $this->createTooManyRowsCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);

        $responseData = $response->json();
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertStringContainsString('exceeds maximum', $responseData['message']);
    }

    /**
     * Test file format validation (CSV/TXT only).
     *
     * @return void
     */
    public function testFileFormatValidation(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test missing required fields validation.
     *
     * @return void
     */
    public function testMissingRequiredFieldsValidation(): void
    {
        $file = $this->createValidCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            // Missing user_id, expense_user_id, client_id
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'expense_user_id', 'client_id']);
    }

    /**
     * Test user permission validation.
     *
     * @return void
     */
    public function testUserPermissionValidation(): void
    {
        // Create another user without permission to manage target user
        $unauthorizedUser = User::factory()->create();
        $this->actingAs($unauthorizedUser, 'api');

        $file = $this->createValidCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $unauthorizedUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test client validation (user must belong to client).
     *
     * @return void
     */
    public function testClientValidation(): void
    {
        $otherClient = Client::factory()->create();

        $file = $this->createValidCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $otherClient->id,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test CSV header validation.
     *
     * @return void
     */
    public function testCsvHeaderValidation(): void
    {
        $file = $this->createCsvWithoutHeader();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);

        $responseData = $response->json();
        $this->assertStringContainsString('header', strtolower($responseData['message']));
    }

    /**
     * Test date format and age validation.
     *
     * @return void
     */
    public function testDateValidation(): void
    {
        $file = $this->createCsvWithOldDates();

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
                'errors',
            ])
            ->assertJson([
                'success' => false,
                'error_count' => 1,
            ]);

        $responseData = $response->json();
        $this->assertArrayHasKey('errors', $responseData);
        $errors = $responseData['errors'];
        $this->assertCount(1, $errors);
        $this->assertEquals('Date', $errors[0]['field']);
        $this->assertStringContainsString('3 years', $errors[0]['error']);
    }

    /**
     * Test 'Other' source with source note requirement.
     *
     * @return void
     */
    public function testOtherSourceWithSourceNote(): void
    {
        $file = $this->createCsvWithOtherSource();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'total_rows' => 1,
            ]);

        // Verify upload was successful
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'status' => 'validation_passed',
            'valid_records' => 1,
        ]);
    }

    /**
     * Test 'Other' source without required source note.
     *
     * @return void
     */
    public function testOtherSourceWithoutSourceNote(): void
    {
        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => 'Test Merchant',
                'Description' => 'Test purchase',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Other',
                'Source Note' => '', // Empty source note should trigger validation error
                'Notes' => 'Test expense note',
            ],
        ];

        $file = $this->createValidCsv($rows);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_count' => 1,
            ]);

        $responseData = $response->json();
        $errors = $responseData['errors'];
        $this->assertCount(1, $errors);
        $this->assertEquals('Source Note', $errors[0]['field']);
        $this->assertStringContainsString('required', strtolower($errors[0]['error']));
    }

    /**
     * Test amount sign based on expense type.
     *
     * @return void
     */
    public function testAmountSignValidation(): void
    {
        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Refund from Merchant', // Should be positive
                'Currency Code' => 'USD',
                'Amount' => '50.00', // Positive amount for refund
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => 'Test Merchant',
                'Description' => 'Refund received',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Cash',
                'Source Note' => '',
                'Notes' => 'Refund processed',
            ],
            [
                'Date' => '14/12/2023',
                'Expense Type' => 'Point of Sale', // Should be negative
                'Currency Code' => 'USD',
                'Amount' => '75.00', // Will be converted to negative
                'USD Equivalent Amount' => '75.00',
                'VAT %' => '5%',
                'Merchant Name' => 'Store Purchase',
                'Description' => 'Regular purchase',
                'Merchant Address' => '456 Store Ave',
                'Merchant Country' => 'United States',
                'Source' => 'Corporate Card',
                'Source Note' => '',
                'Notes' => 'Business expense',
            ],
        ];

        $file = $this->createValidCsv($rows);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'total_rows' => 2,
            ]);

        // Verify upload was successful
        $this->assertDatabaseHas('pocket_expense_file_uploads', [
            'status' => 'validation_passed',
            'valid_records' => 2,
        ]);
    }

    /**
     * Test VAT percentage validation.
     *
     * @return void
     */
    public function testVatPercentageValidation(): void
    {
        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '150%', // Invalid VAT percentage > 100%
                'Merchant Name' => 'Test Merchant',
                'Description' => 'Test purchase',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Cash',
                'Source Note' => '',
                'Notes' => 'Test expense note',
            ],
        ];

        $file = $this->createValidCsv($rows);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_count' => 1,
            ]);

        $responseData = $response->json();
        $errors = $responseData['errors'];
        $this->assertCount(1, $errors);
        $this->assertEquals('VAT %', $errors[0]['field']);
        $this->assertStringContainsString('0-100', $errors[0]['error']);
    }

    /**
     * Test merchant name length validation.
     *
     * @return void
     */
    public function testMerchantNameLengthValidation(): void
    {
        $longMerchantName = str_repeat('A', 200); // Exceeds VARCHAR(180) limit

        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => $longMerchantName,
                'Description' => 'Test purchase',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Cash',
                'Source Note' => '',
                'Notes' => 'Test expense note',
            ],
        ];

        $file = $this->createValidCsv($rows);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_count' => 1,
            ]);

        $responseData = $response->json();
        $errors = $responseData['errors'];
        $this->assertCount(1, $errors);
        $this->assertEquals('Merchant Name', $errors[0]['field']);
        $this->assertStringContainsString('180', $errors[0]['error']);
    }

    /**
     * Test authentication requirement.
     *
     * @return void
     */
    public function testAuthenticationRequired(): void
    {
        // Make request without authentication
        $this->app['auth']->forgetGuards();

        $file = $this->createValidCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test upload status tracking.
     *
     * @return void
     */
    public function testUploadStatusTracking(): void
    {
        $file = $this->createValidCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);
        $uploadId = $response->json('upload_id');

        // Verify upload record has correct initial status
        $upload = PocketExpenseFileUpload::find($uploadId);
        $this->assertNotNull($upload);
        $this->assertEquals('validation_passed', $upload->status);
        $this->assertNotNull($upload->uploaded_at);
        $this->assertNotNull($upload->validated_at);
        $this->assertNull($upload->processed_at);

        // Verify staging data records exist
        $stagingData = PocketExpenseUploadsData::where('upload_id', $uploadId)->get();
        $this->assertCount(2, $stagingData);
        $this->assertTrue($stagingData->every(fn($record) => $record->status === 'pending'));
    }

    /**
     * Test file storage after upload.
     *
     * @return void
     */
    public function testFileStorageAfterUpload(): void
    {
        $file = $this->createValidCsv();

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);
        $uploadId = $response->json('upload_id');

        // Verify upload record contains file information
        $upload = PocketExpenseFileUpload::find($uploadId);
        $this->assertNotNull($upload);
        $this->assertEquals('expenses.csv', $upload->file_name);
        $this->assertNotEmpty($upload->file_path);

        // Verify file was stored
        $this->assertTrue(Storage::disk('local')->exists($upload->file_path));
    }

    /**
     * Test expense data parsing and staging.
     *
     * @return void
     */
    public function testExpenseDataParsing(): void
    {
        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'EUR',
                'Amount' => '75.50',
                'USD Equivalent Amount' => '82.15',
                'VAT %' => '20%',
                'Merchant Name' => 'European Store',
                'Description' => 'Business supplies',
                'Merchant Address' => '456 Europe St, Berlin',
                'Merchant Country' => 'Germany',
                'Source' => 'Corporate Card',
                'Source Note' => '',
                'Notes' => 'Quarterly supplies purchase',
            ],
        ];

        $file = $this->createValidCsv($rows);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(200);
        $uploadId = $response->json('upload_id');

        // Verify staging data contains parsed expense data
        $stagingData = PocketExpenseUploadsData::where('upload_id', $uploadId)->first();
        $this->assertNotNull($stagingData);
        
        $expenseData = $stagingData->expense_data;
        $this->assertIsArray($expenseData);
        $this->assertEquals('15/12/2023', $expenseData['Date']);
        $this->assertEquals('Point of Sale', $expenseData['Expense Type']);
        $this->assertEquals('EUR', $expenseData['Currency Code']);
        $this->assertEquals('75.50', $expenseData['Amount']);
        $this->assertEquals('20%', $expenseData['VAT %']);
        $this->assertEquals('European Store', $expenseData['Merchant Name']);
    }

    /**
     * Test all-or-nothing validation behavior.
     *
     * @return void
     */
    public function testAllOrNothingValidation(): void
    {
        // Create CSV with mix of valid and invalid rows
        $rows = [
            [
                'Date' => '15/12/2023',
                'Expense Type' => 'Point of Sale',
                'Currency Code' => 'USD',
                'Amount' => '50.00',
                'USD Equivalent Amount' => '50.00',
                'VAT %' => '10%',
                'Merchant Name' => 'Valid Merchant',
                'Description' => 'Valid purchase',
                'Merchant Address' => '123 Test St',
                'Merchant Country' => 'United States',
                'Source' => 'Cash',
                'Source Note' => '',
                'Notes' => 'Valid expense',
            ],
            [
                'Date' => '32/13/2023', // Invalid date
                'Expense Type' => 'Invalid Type',
                'Currency Code' => 'BAD',
                'Amount' => 'not-number',
                'USD Equivalent Amount' => '',
                'VAT %' => '150%',
                'Merchant Name' => '',
                'Description' => 'Invalid row',
                'Merchant Address' => '',
                'Merchant Country' => '',
                'Source' => 'Bad Source',
                'Source Note' => '',
                'Notes' => 'Invalid',
            ],
        ];

        $file = $this->createValidCsv($rows);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'total_rows' => 2,
                'error_count' => 1, // Only 1 row has errors, but affects entire upload
            ]);

        // Verify no expenses were created due to validation failure
        $this->assertDatabaseCount('pocket_expense', 0);

        // Verify no staging data was created due to validation failure
        $this->assertDatabaseCount('pocket_expense_uploads_data', 0);

        // Verify no background job was dispatched
        Queue::assertNotPushed(ProcessExpenseUpload::class);
    }

    /**
     * Test concurrent upload handling.
     *
     * @return void
     */
    public function testConcurrentUploads(): void
    {
        $file1 = $this->createValidCsv();
        $file2 = $this->createValidCsv();

        // Submit two uploads simultaneously
        $response1 = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file1,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response2 = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file2,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Verify both uploads were processed independently
        $this->assertDatabaseCount('pocket_expense_file_uploads', 2);
        $this->assertDatabaseCount('pocket_expense_uploads_data', 4); // 2 rows per upload

        // Verify background jobs were dispatched for both
        Queue::assertPushed(ProcessExpenseUpload::class, 2);
    }

    /**
     * Test empty CSV file handling.
     *
     * @return void
     */
    public function testEmptyCsvFile(): void
    {
        // Create CSV with only header, no data rows
        $csvContent = "Date,Expense Type,Currency Code,Amount,USD Equivalent Amount,VAT %,Merchant Name,Description,Merchant Address,Merchant Country,Source,Source Note,Notes\n";
        $file = UploadedFile::fake()->createWithContent('empty.csv', $csvContent);

        $response = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'success' => false,
            ]);

        $responseData = $response->json();
        $this->assertStringContainsString('empty', strtolower($responseData['message']));
    }

    /**
     * Test UUID generation and uniqueness.
     *
     * @return void
     */
    public function testUuidGenerationAndUniqueness(): void
    {
        $file1 = $this->createValidCsv();
        $file2 = $this->createValidCsv();

        $response1 = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file1,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response2 = $this->postJson('/api/uploads/pocket-expense/csv', [
            'file' => $file2,
            'user_id' => $this->adminUser->id,
            'expense_user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
        ]);

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        $upload1 = PocketExpenseFileUpload::find($response1->json('upload_id'));
        $upload2 = PocketExpenseFileUpload::find($response2->json('upload_id'));

        $this->assertNotEquals($upload1->uuid, $upload2->uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $upload1->uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $upload2->uuid);
    }

    /**
     * Test upload with maximum valid complexity.
     *
     * @return void
     */
    public function testMaximumValid