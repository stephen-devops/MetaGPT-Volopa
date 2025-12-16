## Code: tests/Feature/FileUploadTest.php

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\PaymentFile;
use App\Models\PaymentInstruction;
use App\Models\ValidationError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

class FileUploadTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test user instance.
     */
    private User $user;

    /**
     * Valid CSV content for testing.
     */
    private string $validCsvContent = "beneficiary_name,beneficiary_account,amount,currency,settlement_method,payment_purpose,reference\n" .
        "John Smith Ltd,GB33BUKB20201555555555,1500.00,GBP,FASTER_PAYMENTS,Invoice payment,INV-001\n" .
        "ACME Corp,DE89370400440532013000,2500.50,EUR,SEPA,Supplier payment,SUPP-001\n" .
        "Global Tech Inc,1234567890,5000.00,USD,WIRE,License fee,LIC-001";

    /**
     * Invalid CSV content for testing.
     */
    private string $invalidCsvContent = "beneficiary_name,beneficiary_account,amount,currency,settlement_method\n" .
        "John Smith Ltd,GB33BUKB20201555555555,invalid_amount,GBP,FASTER_PAYMENTS\n" .
        ",DE89370400440532013000,2500.50,EUR,SEPA\n" .
        "Global Tech Inc,1234567890,-5000.00,INVALID,WIRE";

    /**
     * Large CSV content for testing file limits.
     */
    private string $largeCsvContent;

    /**
     * Setup test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::factory()->create([
            'email' => 'test@volopa.com',
            'name' => 'Test User',
            'email_verified_at' => now(),
        ]);

        // Setup storage for testing
        Storage::fake('local');

        // Generate large CSV content for testing limits
        $this->generateLargeCsvContent();

        // Fake queue to prevent actual job processing during tests
        Queue::fake();
    }

    /**
     * Test successful file upload with valid CSV.
     *
     * @return void
     */
    public function test_successful_file_upload_with_valid_csv(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create valid CSV file
        $file = $this->createCsvFile('valid_payments.csv', $this->validCsvContent);

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
            'currency' => 'USD'
        ]);

        // Assert successful response
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'File uploaded successfully and queued for processing'
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'filename',
                    'file_size',
                    'status',
                    'currency',
                    'statistics' => [
                        'total_records',
                        'valid_records',
                        'invalid_records',
                        'success_rate'
                    ],
                    'financial' => [
                        'total_amount',
                        'formatted_amount',
                        'currency'
                    ],
                    'processing' => [
                        'is_processing',
                        'is_completed',
                        'has_failed',
                        'requires_approval'
                    ],
                    'timestamps' => [
                        'uploaded_at',
                        'updated_at'
                    ]
                ],
                'meta' => [
                    'timestamp',
                    'api_version'
                ]
            ]);

        // Assert payment file was created in database
        $this->assertDatabaseHas('payment_files', [
            'user_id' => $this->user->id,
            'original_name' => 'valid_payments.csv',
            'status' => PaymentFile::STATUS_UPLOADED,
            'currency' => 'USD'
        ]);

        // Assert file was stored
        $paymentFile = PaymentFile::where('user_id', $this->user->id)->first();
        $this->assertNotNull($paymentFile);
        
        $storedPath = 'payment-files/' . $paymentFile->filename;
        Storage::disk('local')->assertExists($storedPath);

        // Assert job was dispatched
        Queue::assertPushed(\App\Jobs\ProcessPaymentFileJob::class);
    }

    /**
     * Test file upload without authentication.
     *
     * @return void
     */
    public function test_file_upload_requires_authentication(): void
    {
        // Create valid CSV file
        $file = $this->createCsvFile('test.csv', $this->validCsvContent);

        // Make upload request without authentication
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert unauthorized response
        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Authentication required'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with invalid file type.
     *
     * @return void
     */
    public function test_file_upload_rejects_invalid_file_type(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create invalid file type
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment([
                'The file must be a CSV file with .csv or .txt extension.'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with file too large.
     *
     * @return void
     */
    public function test_file_upload_rejects_oversized_file(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create oversized file (11MB, limit is 10MB)
        $file = UploadedFile::fake()->create('large_file.csv', 11 * 1024, 'text/csv');

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment([
                'The file size cannot exceed 10MB.'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with empty CSV file.
     *
     * @return void
     */
    public function test_file_upload_rejects_empty_csv(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create empty CSV file
        $file = $this->createCsvFile('empty.csv', 'beneficiary_name,beneficiary_account,amount,currency,settlement_method');

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment([
                'The CSV file must contain at least one payment instruction row.'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with missing required CSV headers.
     *
     * @return void
     */
    public function test_file_upload_rejects_missing_required_headers(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create CSV with missing required headers
        $invalidHeaderCsv = "beneficiary_name,amount,currency\n" .
            "John Smith Ltd,1500.00,GBP\n";

        $file = $this->createCsvFile('invalid_headers.csv', $invalidHeaderCsv);

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment([
                'The CSV file is missing required columns: beneficiary_account, settlement_method'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with invalid currency parameter.
     *
     * @return void
     */
    public function test_file_upload_rejects_invalid_currency(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create valid CSV file
        $file = $this->createCsvFile('valid_payments.csv', $this->validCsvContent);

        // Make upload request with invalid currency
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
            'currency' => 'INVALID'
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency'])
            ->assertJsonFragment([
                'The currency code must be one of: USD, EUR, GBP'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with too many rows.
     *
     * @return void
     */
    public function test_file_upload_rejects_too_many_rows(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create CSV file with too many rows (exceeding 10,000 limit)
        $file = $this->createCsvFile('large_file.csv', $this->largeCsvContent);

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment([
                'The CSV file contains too many rows. Maximum allowed is 10,000 payment instructions.'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test file upload with malformed CSV structure.
     *
     * @return void
     */
    public function test_file_upload_rejects_malformed_csv(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create malformed CSV with inconsistent columns
        $malformedCsv = "beneficiary_name,beneficiary_account,amount,currency,settlement_method\n" .
            "John Smith Ltd,GB33BUKB20201555555555,1500.00,GBP\n" . // Missing settlement_method
            "ACME Corp,DE89370400440532013000,2500.50,EUR,SEPA,extra_column\n"; // Extra column

        $file = $this->createCsvFile('malformed.csv', $malformedCsv);

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert validation error
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file'])
            ->assertJsonFragment([
                'The CSV file structure is inconsistent. All rows must have the same number of columns as the header.'
            ]);

        // Assert no file was created
        $this->assertDatabaseCount('payment_files', 0);
    }

    /**
     * Test successful file upload with default currency.
     *
     * @return void
     */
    public function test_file_upload_uses_default_currency_when_not_specified(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create valid CSV file
        $file = $this->createCsvFile('valid_payments.csv', $this->validCsvContent);

        // Make upload request without currency parameter
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
        ]);

        // Assert successful response
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'currency' => 'USD' // Default currency
                ]
            ]);

        // Assert payment file was created with default currency
        $this->assertDatabaseHas('payment_files', [
            'user_id' => $this->user->id,
            'currency' => PaymentFile::CURRENCY_USD
        ]);
    }

    /**
     * Test file upload with rate limiting.
     *
     * @return void
     */
    public function test_file_upload_rate_limiting(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create valid CSV file
        $file = $this->createCsvFile('test.csv', $this->validCsvContent);

        // Make multiple requests to trigger rate limiting (limit is 10 per minute)
        for ($i = 0; $i < 12; $i++) {
            $response = $this->postJson('/api/v1/files', [
                'file' => $file,
            ]);

            if ($i >= 10) {
                // Should be rate limited after 10 requests
                $response->assertStatus(429);
            } else {
                // First 10 should succeed
                $response->assertStatus(201);
            }
        }
    }

    /**
     * Test file upload creates proper audit trail.
     *
     * @return void
     */
    public function test_file_upload_creates_audit_trail(): void
    {
        // Authenticate user
        Sanctum::actingAs($this->user);

        // Create valid CSV file
        $file = $this->createCsvFile('audit_test.csv', $this->validCsvContent);

        // Make upload request
        $response = $this->postJson('/api/v1/files', [
            'file' => $file,
            'currency' => 'EUR'
        ]);

        // Assert successful response
        $response->assertStatus(201);

        // Get created payment file
        $paymentFile = PaymentFile::where('user_id', $this->user->id)->first();
        $this->assertNotNull($paymentFile);

        // Assert proper timestamps were set
        $this->assertNotNull($paymentFile->created_at);
        $this->assertNotNull($paymentFile->updated_at);

        // Assert file metadata is correct
        $this->assertEquals('audit_test.csv', $paymentFile->original_name);
        $this->assertEquals($this->user->id, $paymentFile->user_id);
        $this->assertEquals('EUR', $payment