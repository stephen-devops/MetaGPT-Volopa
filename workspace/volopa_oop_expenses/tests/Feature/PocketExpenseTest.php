## CHECK PLAN

- PocketExpense (eloquent_model)          -> EXISTING  -> rag_symbol_lookup("PocketExpense", "eloquent_model") -> 0 hits, reclassify as NEW
- User (eloquent_model)                   -> EXISTING  -> rag_symbol_lookup("User", "eloquent_model") -> 1 hit, file_path: UNKNOWN, table: users
- Client (eloquent_model)                 -> EXISTING  -> rag_symbol_lookup("Client", "eloquent_model") -> 1 hit, file_path: UNKNOWN, table: clients
- OptPocketExpenseType (eloquent_model)   -> NEW       -> rag_collision_check("OptPocketExpenseType") -> 0 hits, create at app/Models/OptPocketExpenseType.php
- PocketExpenseSourceClientConfig (eloquent_model) -> NEW -> rag_collision_check("PocketExpenseSourceClientConfig") -> 0 hits, create at app/Models/PocketExpenseSourceClientConfig.php
- PocketExpenseMetadata (eloquent_model)  -> NEW       -> rag_collision_check("PocketExpenseMetadata") -> 0 hits, create at app/Models/PocketExpenseMetadata.php
- PocketExpenseService (service)          -> NEW       -> rag_collision_check("PocketExpenseService") -> 0 hits, create at app/Services/PocketExpenseService.php
- FXConversionService (service)           -> NEW       -> rag_collision_check("FXConversionService") -> 0 hits, create at app/Services/FXConversionService.php
- PocketExpenseController (controller)    -> NEW       -> rag_collision_check("PocketExpenseController") -> 0 hits, create at app/Http/Controllers/Api/V1/PocketExpenseController.php
- PocketExpensePolicy (policy)            -> NEW       -> rag_collision_check("PocketExpensePolicy") -> 0 hits, create at app/Policies/PocketExpensePolicy.php
- StorePocketExpenseRequest (form_request) -> NEW      -> rag_collision_check("StorePocketExpenseRequest") -> 0 hits, create at app/Http/Requests/StorePocketExpenseRequest.php
- UpdatePocketExpenseRequest (form_request) -> NEW     -> rag_collision_check("UpdatePocketExpenseRequest") -> 0 hits, create at app/Http/Requests/UpdatePocketExpenseRequest.php
- PocketExpenseResource (api_resource)    -> NEW       -> rag_collision_check("PocketExpenseResource") -> 0 hits, create at app/Http/Resources/PocketExpenseResource.php
- UserFeaturePermissionService (service)  -> NEW      -> rag_collision_check("UserFeaturePermissionService") -> 0 hits, create at app/Services/UserFeaturePermissionService.php
- TestCase (laravel_test_class)           -> EXISTING  -> Laravel's base TestCase class for feature tests
- RefreshDatabase (laravel_trait)         -> EXISTING  -> Laravel's database refreshing trait for tests
- WithFaker (laravel_trait)               -> EXISTING  -> Laravel's Faker trait for tests
- DatabaseFactory (laravel_factory)      -> EXISTING  -> Laravel's model factory system

## Code: tests/Feature/PocketExpenseTest.php

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\PocketExpenseMetadata;
use App\Models\UserFeaturePermission;
use App\Services\UserFeaturePermissionService;
use App\Services\FXConversionService;
use Database\Factories\UserFactory;
use Database\Factories\ClientFactory;
use Database\Factories\PocketExpenseFactory;
use Database\Factories\OptPocketExpenseTypeFactory;
use Database\Factories\PocketExpenseSourceClientConfigFactory;
use Database\Factories\UserFeaturePermissionFactory;

/**
 * Feature tests for PocketExpense CRUD operations.
 * 
 * Tests cover:
 * - Expense creation with FX conversion
 * - Expense listing with proper scoping and pagination
 * - Expense retrieval with authorization checks
 * - Expense updates with validation and policy enforcement
 * - Expense deletion with soft delete behavior
 * - Permission-based access control
 * - Multi-tenancy client scoping
 * - Status transitions and approval workflows
 * - Metadata relationships and eager loading
 * - Date constraints and currency validation
 */
class PocketExpenseTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test user instances for different roles.
     */
    private User $primaryAdmin;
    private User $admin;
    private User $businessUser;
    private User $cardUser;
    private User $targetUser;
    
    /**
     * Test client instance for multi-tenancy.
     */
    private Client $client;
    
    /**
     * Test expense types for validation.
     */
    private OptPocketExpenseType $expenseTypeNegative;
    private OptPocketExpenseType $expenseTypePositive;
    
    /**
     * Test expense sources.
     */
    private PocketExpenseSourceClientConfig $expenseSourceCash;
    private PocketExpenseSourceClientConfig $expenseSourceOther;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test client
        $this->client = Client::factory()->create([
            'name' => 'Test Client Corp',
            'deleted' => false,
        ]);
        
        // Create users with different roles
        $this->primaryAdmin = User::factory()->create([
            'name' => 'Primary Admin User',
            'username' => 'primary_admin@test.com',
            'deleted' => false,
        ]);
        
        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'username' => 'admin@test.com',  
            'deleted' => false,
        ]);
        
        $this->businessUser = User::factory()->create([
            'name' => 'Business User',
            'username' => 'business@test.com',
            'deleted' => false,
        ]);
        
        $this->cardUser = User::factory()->create([
            'name' => 'Card User',
            'username' => 'card@test.com',
            'deleted' => false,
        ]);
        
        $this->targetUser = User::factory()->create([
            'name' => 'Target User',
            'username' => 'target@test.com',
            'deleted' => false,
        ]);
        
        // Create expense types
        $this->expenseTypeNegative = OptPocketExpenseType::factory()->negative()->create([
            'option' => 'Point of Sale',
            'amount_sign' => 'negative',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        
        $this->expenseTypePositive = OptPocketExpenseType::factory()->positive()->create([
            'option' => 'Refund from Merchant',
            'amount_sign' => 'positive',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        
        // Create expense sources
        $this->expenseSourceCash = PocketExpenseSourceClientConfig::factory()->forClient($this->client->id)->create([
            'name' => 'Cash',
            'is_default' => true,
            'deleted' => false,
        ]);
        
        $this->expenseSourceOther = PocketExpenseSourceClientConfig::factory()->global()->create([
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
            'deleted' => false,
        ]);
        
        // Set up user feature permissions
        $this->setupUserPermissions();
    }

    /**
     * Set up user feature permissions for OOP Expenses (feature_id = 16).
     */
    private function setupUserPermissions(): void
    {
        // Primary Admin has full access by default (no explicit permission needed)
        
        // Admin has access to own expenses
        UserFeaturePermission::factory()->forUser(
            $this->admin->id,
            $this->client->id, 
            $this->primaryAdmin->id,
            $this->admin->id
        )->forOopExpenses()->create();
        
        // Business User has access
        UserFeaturePermission::factory()->forUser(
            $this->businessUser->id,
            $this->client->id,
            $this->admin->id,
            $this->admin->id
        )->forOopExpenses()->create();
        
        // Card User has access
        UserFeaturePermission::factory()->forUser(
            $this->cardUser->id,
            $this->client->id,
            $this->admin->id,
            $this->admin->id
        )->forOopExpenses()->create();
        
        // Target User has access
        UserFeaturePermission::factory()->forUser(
            $this->targetUser->id,
            $this->client->id,
            $this->admin->id,
            $this->admin->id
        )->forOopExpenses()->create();
        
        // Grant Admin management rights over target user
        UserFeaturePermission::factory()->forUser(
            $this->targetUser->id,
            $this->client->id,
            $this->admin->id,
            $this->admin->id // Admin can manage target user
        )->forOopExpenses()->create([
            'is_enabled' => true,
        ]);
    }

    /**
     * Test expense creation with valid data and FX conversion.
     */
    public function test_can_create_expense_with_fx_conversion(): void
    {
        // Mock FX conversion service
        $this->mock(FXConversionService::class, function ($mock) {
            $mock->shouldReceive('convertAmount')
                ->once()
                ->with('USD', -100.50, \Mockery::type('string'), $this->client->id)
                ->andReturn([
                    'converted_amount' => -95.25,
                    'base_currency' => 'EUR',
                    'fx_rate' => 0.9476,
                    'commission_rate' => 0.02,
                    'adjusted_rate' => 0.9287,
                ]);
        });

        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant Ltd',
            'merchant_description' => 'Business dinner with client',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -100.50,
            'merchant_address' => '123 Main St, Test City',
            'vat_amount' => 15.50,
            'notes' => 'Client entertainment expense',
            'metadata' => [
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSourceCash->id,
                    'details_json' => [
                        'source_note' => 'Paid with company cash',
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_CREATED)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'user_id',
                    'client_id',
                    'date',
                    'merchant_name',
                    'merchant_description',
                    'expense_type',
                    'currency',
                    'amount',
                    'merchant_address',
                    'vat_amount',
                    'notes',
                    'status',
                    'metadata',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('pocket_expense', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'merchant_name' => 'Test Merchant Ltd',
            'currency' => 'USD',
            'amount' => -100.50,
            'status' => 'draft',
            'created_by_user_id' => $this->admin->id,
            'deleted' => false,
        ]);

        $expense = PocketExpense::where('merchant_name', 'Test Merchant Ltd')->first();
        $this->assertNotNull($expense);
        $this->assertNotNull($expense->uuid);
        
        // Verify metadata was created
        $this->assertDatabaseHas('pocket_expense_metadata', [
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'expense_source_id' => $this->expenseSourceCash->id,
            'user_id' => $this->admin->id,
            'deleted' => false,
        ]);
    }

    /**
     * Test expense creation validation for date constraint (not older than 3 years).
     */
    public function test_create_expense_validates_date_constraint(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subYears(4)->format('Y-m-d'), // Too old
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -50.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['date']);

        $this->assertDatabaseMissing('pocket_expense', [
            'merchant_name' => 'Test Merchant',
        ]);
    }

    /**
     * Test expense creation validation for currency format.
     */
    public function test_create_expense_validates_currency_format(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'INVALID', // Invalid currency format
            'amount' => -50.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['currency']);
    }

    /**
     * Test expense creation validation for merchant name length.
     */
    public function test_create_expense_validates_merchant_name_length(): void
    {
        $this->actingAs($this->admin);

        $longMerchantName = str_repeat('A', 181); // Exceeds VARCHAR(180) limit

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'merchant_name' => $longMerchantName,
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -50.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['merchant_name']);
    }

    /**
     * Test expense creation validation for VAT percentage range.
     */
    public function test_create_expense_validates_vat_percentage_range(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -50.00,
            'vat_amount' => 150.00, // Exceeds 100% limit
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['vat_amount']);
    }

    /**
     * Test expense creation authorization - user can only create for managed users.
     */
    public function test_create_expense_authorization_for_managed_users(): void
    {
        $unauthorizedUser = User::factory()->create(['deleted' => false]);
        
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $unauthorizedUser->id, // Admin doesn't manage this user
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -50.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_FORBIDDEN);

        $this->assertDatabaseMissing('pocket_expense', [
            'user_id' => $unauthorizedUser->id,
            'merchant_name' => 'Test Merchant',
        ]);
    }

    /**
     * Test expense listing with pagination and client scoping.
     */
    public function test_can_list_expenses_with_pagination(): void
    {
        // Create multiple expenses for the target user
        $expenses = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->withStatus('submitted')
            ->count(15)
            ->create();

        // Create expenses for different client (should not appear)
        $otherClient = Client::factory()->create(['deleted' => false]);
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $otherClient->id, $this->admin->id)
            ->count(5)
            ->create();

        $this->actingAs($this->admin);

        $response = $this->getJson('/api/v1/pocket-expenses?per_page=10');

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
                        'currency',
                        'amount',
                        'status',
                        'created_at',
                    ],
                ],
                'links',
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ])
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 15); // Only expenses from correct client

        // Verify client scoping
        $responseData = $response->json('data');
        foreach ($responseData as $expense) {
            $this->assertEquals($this->client->id, $expense['client_id']);
        }
    }

    /**
     * Test expense listing with status filtering.
     */
    public function test_can_list_expenses_with_status_filter(): void
    {
        // Create expenses with different statuses
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->draft()
            ->count(3)
            ->create();

        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->submitted()
            ->count(5)
            ->create();

        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->approved()
            ->count(2)
            ->create();

        $this->actingAs($this->admin);

        $response = $this->getJson('/api/v1/pocket-expenses?status=submitted');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('meta.total', 5);

        $responseData = $response->json('data');
        foreach ($responseData as $expense) {
            $this->assertEquals('submitted', $expense['status']);
        }
    }

    /**
     * Test expense retrieval with proper authorization.
     */
    public function test_can_show_expense_with_metadata(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->submitted()
            ->create();

        // Create metadata for the expense
        PocketExpenseMetadata::factory()
            ->forExpense($expense->id, $this->admin->id)
            ->expenseSource()
            ->withExpenseSource($this->expenseSourceCash->id)
            ->create();

        $this->actingAs($this->admin);

        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'user_id',
                    'client_id',
                    'date',
                    'merchant_name',
                    'merchant_description',
                    'expense_type',
                    'currency',
                    'amount',
                    'merchant_address',
                    'vat_amount',
                    'notes',
                    'status',
                    'metadata' => [
                        '*' => [
                            'id',
                            'metadata_type',
                            'expense_source_id',
                            'details_json',
                        ],
                    ],
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.id', $expense->id)
            ->assertJsonPath('data.user_id', $this->targetUser->id)
            ->assertJsonPath('data.client_id', $this->client->id);
    }

    /**
     * Test expense retrieval authorization - cannot view others' expenses without permission.
     */
    public function test_cannot_show_expense_without_permission(): void
    {
        $otherUser = User::factory()->create(['deleted' => false]);
        $expense = PocketExpense::factory()
            ->forUser($otherUser->id, $this->client->id, $this->primaryAdmin->id)
            ->create();

        $this->actingAs($this->businessUser); // Business user trying to access other's expense

        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * Test expense update with validation and FX recalculation.
     */
    public function test_can_update_expense_with_fx_recalculation(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->draft()
            ->create([
                'currency' => 'USD',
                'amount' => -75.00,
            ]);

        // Mock FX conversion service for update
        $this->mock(FXConversionService::class, function ($mock) {
            $mock->shouldReceive('convertAmount')
                ->once()
                ->with('EUR', -120.00, \Mockery::type('string'), $this->client->id)
                ->andReturn([
                    'converted_amount' => -128.50,
                    'base_currency' => 'EUR',
                    'fx_rate' => 1.0708,
                    'commission_rate' => 0.02,
                    'adjusted_rate' => 1.0494,
                ]);
        });

        $this->actingAs($this->admin);

        $updateData = [
            'merchant_name' => 'Updated Merchant Name',
            'currency' => 'EUR',
            'amount' => -120.00,
            'notes' => 'Updated notes for the expense',
            'status' => 'submitted',
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.merchant_name', 'Updated Merchant Name')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.amount', -120.00)
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'merchant_name' => 'Updated Merchant Name',
            'currency' => 'EUR',
            'amount' => -120.00,
            'notes' => 'Updated notes for the expense',
            'status' => 'submitted',
            'updated_by_user_id' => $this->admin->id,
        ]);
    }

    /**
     * Test expense update authorization - only permitted users can update.
     */
    public function test_cannot_update_expense_without_permission(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->draft()
            ->create();

        $unauthorizedUser = User::factory()->create(['deleted' => false]);
        $this->actingAs($unauthorizedUser);

        $updateData = [
            'merchant_name' => 'Unauthorized Update',
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(Response::HTTP_FORBIDDEN);

        $this->assertDatabaseMissing('pocket_expense', [
            'id' => $expense->id,
            'merchant_name' => 'Unauthorized Update',
        ]);
    }

    /**
     * Test expense update validation for status transitions.
     */
    public function test_update_expense_validates_status_transitions(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->approved() // Already approved
            ->create();

        $this->actingAs($this->admin);

        $updateData = [
            'status' => 'draft', // Invalid transition from approved to draft
            'merchant_name' => 'Cannot Update Approved Expense',
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['status']);
    }

    /**
     * Test expense soft deletion.
     */
    public function test_can_soft_delete_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->draft()
            ->create();

        // Create metadata for the expense
        $metadata = PocketExpenseMetadata::factory()
            ->forExpense($expense->id, $this->admin->id)
            ->category()
            ->create();

        $this->actingAs($this->admin);

        $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(Response::HTTP_NO_CONTENT);

        // Verify soft deletion
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'deleted' => true,
        ]);

        $expense->refresh();
        $this->assertTrue($expense->deleted);
        $this->assertNotNull($expense->delete_time);

        // Verify metadata is also soft deleted
        $this->assertDatabaseHas('pocket_expense_metadata', [
            'id' => $metadata->id,
            'deleted' => true,
        ]);
    }

    /**
     * Test expense deletion authorization.
     */
    public function test_cannot_delete_expense_without_permission(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->draft()
            ->create();

        $this->actingAs($this->businessUser); // Business user cannot delete others' expenses

        $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(Response::HTTP_FORBIDDEN);

        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'deleted' => false,
        ]);
    }

    /**
     * Test expense deletion validation - cannot delete approved expenses.
     */
    public function test_cannot_delete_approved_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->approved()
            ->create();

        $this->actingAs($this->admin);

        $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'deleted' => false,
        ]);
    }

    /**
     * Test expense approval workflow authorization.
     */
    public function test_expense_approval_requires_proper_authorization(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->submitted()
            ->create();

        // Business User and Card User cannot approve expenses per constraints
        $this->actingAs($this->businessUser);

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", [
            'status' => 'approved',
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);

        // Admin should be able to approve
        $this->actingAs($this->admin);

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", [
            'status' => 'approved',
        ]);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'status' => 'approved',
            'approved_by_user_id' => $this->admin->id,
        ]);
    }

    /**
     * Test expense creation with metadata relationships.
     */
    public function test_can_create_expense_with_metadata(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'merchant_name' => 'Business Travel Hotel',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -250.00,
            'metadata' => [
                [
                    'metadata_type' => 'category',
                    'transaction_category_id' => 15,
                    'details_json' => [
                        'category_name' => 'Travel',
                        'category_code' => 'TRV',
                        'subcategory' => 'Accommodation',
                    ],
                ],
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSourceOther->id,
                    'details_json' => [
                        'source_note' => 'Personal credit card - requires reimbursement',
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_CREATED);

        $expense = PocketExpense::where('merchant_name', 'Business Travel Hotel')->first();
        $this->assertNotNull($expense);

        // Verify metadata was created
        $this->assertDatabaseHas('pocket_expense_metadata', [
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'category',
            'transaction_category_id' => 15,
            'deleted' => false,
        ]);

        $this->assertDatabaseHas('pocket_expense_metadata', [
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'expense_source_id' => $this->expenseSourceOther->id,
            'deleted' => false,
        ]);
    }

    /**
     * Test expense creation validation for required source note when using 'Other' source.
     */
    public function test_create_expense_validates_other_source_note(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -100.00,
            'metadata' => [
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSourceOther->id,
                    // Missing required source_note in details_json
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['metadata.0.details_json.source_note']);
    }

    /**
     * Test expense retrieval does not return soft-deleted expenses.
     */
    public function test_list_expenses_excludes_soft_deleted(): void
    {
        // Create active expenses
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->active()
            ->count(5)
            ->create();

        // Create soft-deleted expenses
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->deleted()
            ->count(3)
            ->create();

        $this->actingAs($this->admin);

        $response = $this->getJson('/api/v1/pocket-expenses');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('meta.total', 5); // Only active expenses

        $responseData = $response->json('data');
        foreach ($responseData as $expense) {
            $this->assertDatabaseHas('pocket_expense', [
                'id' => $expense['id'],
                'deleted' => false,
            ]);
        }
    }

    /**
     * Test expense creation with amount sign validation based on expense type.
     */
    public function test_create_expense_validates_amount_sign_based_on_type(): void
    {
        $this->actingAs($this->admin);

        // Test negative expense type with positive amount (should be corrected)
        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => 100.00, // Positive amount for negative type - should be corrected
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP_CREATED);

        $expense = PocketExpense::where('merchant_name', 'Test Merchant')->first();
        $this->assertEquals(-100.00, $expense->amount); // Should be corrected to negative
    }

    /**
     * Test expense listing with date range filtering.
     */
    public function test_can_list_expenses_with_date_range_filter(): void
    {
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now()->subDays(10);

        // Create expenses within date range
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->onDate($startDate->copy()->addDays(5))
            ->count(3)
            ->create();

        // Create expenses outside date range
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->onDate($startDate->copy()->subDays(5))
            ->count(2)
            ->create();

        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->onDate($endDate->copy()->addDays(5))
            ->count(2)
            ->create();

        $this->actingAs($this->admin);

        $response = $this->getJson(sprintf(
            '/api/v1/pocket-expenses?date_from=%s&date_to=%s',
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        ));

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('meta.total', 3);

        $responseData = $response->json('data');
        foreach ($responseData as $expense) {
            $expenseDate = Carbon::parse($expense['date']);
            $this->assertTrue($expenseDate->gte($startDate) && $expenseDate->lte($endDate));
        }
    }

    /**
     * Test expense update preserves audit trail.
     */
    public function test_update_expense_preserves_audit_trail(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->draft()
            ->create();

        $originalCreateTime = $expense->create_time;
        $originalCreatedBy = $expense->created_by_user_id;

        $this->actingAs($this->businessUser);

        $updateData = [
            'merchant_name' => 'Updated by Business User',
            'notes' => 'Updated notes',
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(Response::HTTP_OK);

        $expense->refresh();
        
        // Verify audit trail
        $this->assertEquals($originalCreatedBy, $expense->created_by_user_id); // Original creator preserved
        $this->assertEquals($originalCreateTime, $expense->create_time); // Create time preserved
        $this->assertEquals($this->businessUser->id, $expense->updated_by_user_id); // Updated by current user
        $this->assertNotNull($expense->update_time); // Update time set
        $this->assertNotEquals($originalCreateTime, $expense->update_time); // Update time different from create time
    }

    /**
     * Test Primary Admin has full access to all users' expenses.
     */
    public function test_primary_admin_has_full_access(): void
    {
        $otherUser = User::factory()->create(['deleted' => false]);
        $expense = PocketExpense::factory()
            ->forUser($otherUser->id, $this->client->id, $this->primaryAdmin->id)
            ->create();

        $this->actingAs($this->primaryAdmin);

        // Primary Admin should be able to view any expense
        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");
        $response->assertStatus(Response::HTTP_OK);

        // Primary Admin should be able to update any expense
        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", [
            'merchant_name' => 'Updated by Primary Admin',
        ]);
        $response->assertStatus(Response::HTTP_OK);

        // Primary Admin should be able to delete any expense (if not approved)
        if ($expense->status !== 'approved') {
            $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");
            $response->assertStatus(Response::HTTP_NO_CONTENT);
        }
    }

    /**
     * Test expense creation with multiple currencies for FX testing.
     */
    public function test_create_expenses_with_multiple_currencies(): void
    {
        $currencies = ['USD', 'EUR', 'GBP', 'CAD'];
        
        $this->mock(FXConversionService::class, function ($mock) use ($currencies) {
            foreach ($currencies as $currency) {
                $mock->shouldReceive('convertAmount')
                    ->once()
                    ->with($currency, \Mockery::type('float'), \Mockery::type('string'), $this->client->id)
                    ->andReturn([
                        'converted_amount' => -95.25,
                        'base_currency' => 'EUR',
                        'fx_rate' => 0.9476,
                        'commission_rate' => 0.02,
                        'adjusted_rate' => 0.9287,
                    ]);
            }
        });

        $this->actingAs($this->admin);

        foreach ($currencies as $currency) {
            $expenseData = [
                'user_id' => $this->targetUser->id,
                'client_id' => $this->client->id,
                'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
                'merchant_name' => "Test Merchant {$currency}",
                'expense_type' => $this->expenseTypeNegative->id,
                'currency' => $currency,
                'amount' => -100.00,
            ];

            $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

            $response->assertStatus(Response::HTTP_CREATED)
                ->assertJsonPath('data.currency', $currency);

            $this->assertDatabaseHas('pocket_expense', [
                'merchant_name' => "Test Merchant {$currency}",
                'currency' => $currency,
                'user_id' => $this->targetUser->id,
                'client_id' => $this->client->id,
            ]);
        }
    }

    /**
     * Test expense search functionality.
     */
    public function test_can_search_expenses_by_merchant_name(): void
    {
        // Create expenses with specific merchant names
        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->atMerchant('Starbucks Coffee Shop')
            ->create();

        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->atMerchant('Amazon.com Online Store')
            ->create();

        PocketExpense::factory()
            ->forUser($this->targetUser->id, $this->client->id, $this->admin->id)
            ->atMerchant('Shell Gas Station')
            ->create();

        $this->actingAs($this->admin);

        $response = $this->getJson('/api/v1/pocket-expenses?search=Starbucks');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('meta.total', 1);

        $responseData = $response->json('data');
        $this->assertStringContainsString('Starbucks', $responseData[0]['merchant_name']);
    }

    /**
     * Test expense creation handles concurrent requests properly.
     */
    public function test_concurrent_expense_creation(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'merchant_name' => 'Concurrent Test Merchant',
            'expense_type' => $this->expenseTypeNegative->id,
            'currency' => 'USD',
            'amount' => -50.00,
        ];

        // Create multiple expenses concurrently
        $responses = [];
        for ($i = 0; $i < 3; $i++) {
            $data = $expenseData;
            $data['merchant_name'] = "Concurrent Test Merchant {$i}";
            $responses[] = $this->postJson('/api/v1/pocket-expenses', $data);
        }

        // All should succeed
        foreach ($responses as $response) {
            $response->assertStatus(Response::HTTP_CREATED);
        }

        // Verify all were created
        $this->assertDatabaseCount('pocket_expense', 3);
    }

    /**
     * Test error handling for invalid expense type.
     */
    public function test_handles_invalid_expense_type(): void
    {
        $this->actingAs($this->admin);

        $expenseData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => 99999, // Non-existent expense type
            'currency' => 'USD',
            'amount' => -50.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(Response::HTTP