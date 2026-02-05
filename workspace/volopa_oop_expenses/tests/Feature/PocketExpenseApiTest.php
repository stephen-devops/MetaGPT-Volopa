## Code: tests/Feature/PocketExpenseApiTest.php

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\OptPocketExpenseType;
use App\Models\UserFeaturePermission;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
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
 * PocketExpenseApiTest
 * 
 * Feature tests for pocket expense API endpoints.
 * Tests CRUD operations, authorization, validation, CSV uploads, and error handling.
 * Verifies JSON response shapes, status codes, database state, and policy enforcement.
 */
class PocketExpenseApiTest extends TestCase
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
     * Target user for expense operations.
     *
     * @var User
     */
    private User $targetUser;

    /**
     * Manager user for approval operations.
     *
     * @var User
     */
    private User $managerUser;

    /**
     * Expense type for testing.
     *
     * @var OptPocketExpenseType
     */
    private OptPocketExpenseType $expenseType;

    /**
     * Expense source for testing.
     *
     * @var PocketExpenseSourceClientConfig
     */
    private PocketExpenseSourceClientConfig $expenseSource;

    /**
     * API base path.
     *
     * @var string
     */
    private string $apiPath = '/api/pocket-expenses';

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
        
        // Setup storage for file uploads
        Storage::fake('local');
        
        // Prevent jobs from being dispatched
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
            'name' => 'Test Client',
        ]);

        // Create users
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->targetUser = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target@example.com',
        ]);

        $this->managerUser = User::factory()->create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
        ]);

        // Create expense type
        $this->expenseType = OptPocketExpenseType::create([
            'option' => 'Point of Sale',
            'amount_sign' => '-',
        ]);

        // Create expense source
        $this->expenseSource = PocketExpenseSourceClientConfig::create([
            'client_id' => $this->client->id,
            'name' => 'Corporate Card',
            'is_default' => true,
            'deleted' => false,
        ]);

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

        // Grant permission to main user
        UserFeaturePermission::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'feature_id' => $featureId,
            'is_enabled' => true,
        ]);

        // Grant permission to target user
        UserFeaturePermission::create([
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $featureId,
            'manager_user_id' => $this->managerUser->id,
            'is_enabled' => true,
        ]);

        // Grant permission to manager user
        UserFeaturePermission::create([
            'user_id' => $this->managerUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $featureId,
            'is_enabled' => true,
        ]);
    }

    /**
     * Get valid expense data for creation.
     *
     * @return array<string, mixed>
     */
    private function getValidExpenseData(): array
    {
        return [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => now()->subDays(1)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'merchant_description' => 'Test purchase description',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.50,
            'merchant_address' => '123 Test Street',
            'merchant_country' => 'US',
            'vat_amount' => 10.05,
            'notes' => 'Test expense notes',
            'status' => 'draft',
            'expense_source_name' => $this->expenseSource->name,
        ];
    }

    /**
     * Test expense list endpoint with valid parameters.
     */
    public function test_index_returns_paginated_expenses_with_valid_parameters(): void
    {
        // Create test expenses
        $expenses = PocketExpense::factory(3)->create([
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->apiPath . '?' . http_build_query([
                'client_id' => $this->client->id,
                'user_id' => $this->targetUser->id,
                'per_page' => 10,
            ]));

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'uuid',
                        'user_id',
                        'client_id',
                        'date',
                        'merchant_name',
                        'expense_type_id',
                        'currency',
                        'amount',
                        'amount_display',
                        'status',
                        'status_display',
                        'created_at',
                        'updated_at',
                    ]
                ],
                'meta' => [
                    'current_page',
                    'total',
                    'per_page',
                ],
                'summary' => [
                    'total_count',
                    'total_amount',
                    'currency_breakdown',
                    'status_breakdown',
                ],
                'filters' => [
                    'applied',
                    'count',
                ],
            ])
            ->assertJson([
                'meta' => [
                    'total' => 3,
                ],
                'summary' => [
                    'total_count' => 3,
                ],
            ]);

        // Verify all expenses are returned
        $this->assertEquals(3, count($response->json('data')));
    }

    /**
     * Test expense list with filters.
     */
    public function test_index_applies_filters_correctly(): void
    {
        // Create expenses with different statuses
        PocketExpense::factory()->create([
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        PocketExpense::factory()->create([
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'submitted',
            'currency' => 'EUR',
        ]);

        // Filter by status
        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->apiPath . '?' . http_build_query([
                'client_id' => $this->client->id,
                'user_id' => $this->targetUser->id,
                'status' => 'draft',
            ]));

        $response->assertStatus(Response::HTTP_OK);
        $this->assertEquals(1, count($response->json('data')));
        $this->assertEquals('draft', $response->json('data.0.status'));

        // Filter by currency
        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->apiPath . '?' . http_build_query([
                'client_id' => $this->client->id,
                'user_id' => $this->targetUser->id,
                'currency' => 'EUR',
            ]));

        $response->assertStatus(Response::HTTP_OK);
        $this->assertEquals(1, count($response->json('data')));
        $this->assertEquals('EUR', $response->json('data.0.currency'));
    }

    /**
     * Test expense list requires authentication.
     */
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson($this->apiPath . '?' . http_build_query([
            'client_id' => $this->client->id,
        ]));

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test expense list validates required parameters.
     */
    public function test_index_validates_required_parameters(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson($this->apiPath);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['client_id']);
    }

    /**
     * Test expense creation with valid data.
     */
    public function test_store_creates_expense_with_valid_data(): void
    {
        $expenseData = $this->getValidExpenseData();

        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'uuid',
                    'user_id',
                    'client_id',
                    'date',
                    'merchant_name',
                    'expense_type_id',
                    'currency',
                    'amount',
                    'status',
                    'created_at',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => $this->targetUser->id,
                    'client_id' => $this->client->id,
                    'merchant_name' => 'Test Merchant',
                    'currency' => 'USD',
                    'status' => 'draft',
                ],
            ]);

        // Verify database
        $this->assertDatabaseHas('pocket_expense', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'merchant_name' => 'Test Merchant',
            'currency' => 'USD',
            'amount' => -100.50, // Should be negative for Point of Sale
            'status' => 'draft',
            'created_by_user_id' => $this->user->id,
            'deleted' => false,
        ]);
    }

    /**
     * Test expense creation validates required fields.
     */
    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, []);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors([
                'user_id',
                'client_id',
                'date',
                'merchant_name',
                'expense_type',
                'currency',
                'amount',
            ]);
    }

    /**
     * Test expense creation validates date format and age.
     */
    public function test_store_validates_date_constraints(): void
    {
        $expenseData = $this->getValidExpenseData();

        // Test future date
        $expenseData['date'] = now()->addDays(1)->format('Y-m-d');
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['date']);

        // Test date too old (more than 3 years)
        $expenseData['date'] = now()->subYears(4)->format('Y-m-d');
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['date']);
    }

    /**
     * Test expense creation validates amount constraints.
     */
    public function test_store_validates_amount_constraints(): void
    {
        $expenseData = $this->getValidExpenseData();

        // Test negative amount
        $expenseData['amount'] = -50.00;
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['amount']);

        // Test zero amount
        $expenseData['amount'] = 0;
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['amount']);

        // Test amount too large
        $expenseData['amount'] = 1000000;
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['amount']);
    }

    /**
     * Test expense creation validates currency code.
     */
    public function test_store_validates_currency_code(): void
    {
        $expenseData = $this->getValidExpenseData();

        // Test invalid currency
        $expenseData['currency'] = 'XXX';
        $response = $this->actingAs($this->user, 'api')
            ->postJson($this->apiPath, $expenseData);

        $response->assertStatus(Response::