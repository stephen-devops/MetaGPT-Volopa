<?php

namespace Tests\Feature;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\User;
use App\Models\Client;
use App\Models\UserFeaturePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * Feature tests for Pocket Expense API endpoints.
 * 
 * Tests the full HTTP request/response cycle for expense CRUD operations,
 * including authentication, authorization, validation, and business logic.
 * Validates JSON response shapes, status codes, and database state changes.
 */
class PocketExpenseTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;
    protected User $adminUser;
    protected Client $client;
    protected OptPocketExpenseType $expenseType;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test client
        $this->client = Client::factory()->create();

        // Create test users
        $this->user = User::factory()->create();
        $this->adminUser = User::factory()->create();

        // Create expense type for testing
        $this->expenseType = OptPocketExpenseType::factory()->pointOfSale()->create();

        // Grant OOP expense feature permission to admin user
        UserFeaturePermission::factory()
            ->forUserAndClient($this->adminUser->id, $this->client->id)
            ->forFeature(16) // OOP Expense feature ID
            ->grantedBy($this->adminUser->id, $this->adminUser->id)
            ->create();
    }

    /**
     * Test successful expense creation with valid data.
     */
    public function test_can_create_expense_with_valid_data(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => now()->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'merchant_description' => 'Test purchase',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 150.75,
            'merchant_address' => '123 Test Street',
            'vat_amount' => 15.08,
            'notes' => 'Business expense for testing',
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201)
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
                    'created_by_user_id',
                    'create_time',
                    'update_time',
                ]
            ])
            ->assertJson([
                'data' => [
                    'user_id' => $this->user->id,
                    'client_id' => $this->client->id,
                    'merchant_name' => 'Test Merchant',
                    'currency' => 'USD',
                    'amount' => '150.75',
                    'status' => 'draft',
                    'created_by_user_id' => $this->adminUser->id,
                ]
            ]);

        // Verify database state
        $this->assertDatabaseHas('pocket_expense', [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'merchant_name' => 'Test Merchant',
            'currency' => 'USD',
            'amount' => 150.75,
            'status' => 'draft',
            'deleted' => false,
        ]);
    }

    /**
     * Test expense creation fails with invalid data.
     */
    public function test_create_expense_fails_with_invalid_data(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $invalidData = [
            'user_id' => 999999, // Non-existent user
            'client_id' => $this->client->id,
            'date' => 'invalid-date',
            'merchant_name' => '', // Required field
            'expense_type' => 999999, // Non-existent expense type
            'currency' => 'INVALID', // Invalid currency code
            'amount' => 'not-a-number',
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
                'date',
                'merchant_name',
                'expense_type',
                'currency',
                'amount',
            ]);

        // Verify no expense was created in database
        $this->assertDatabaseCount('pocket_expense', 0);
    }

    /**
     * Test successful expense retrieval.
     */
    public function test_can_retrieve_expense(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'user_id',
                    'client_id',
                    'date',
                    'merchant_name',
                    'expense_type',
                    'currency',
                    'amount',
                    'status',
                ]
            ])
            ->assertJson([
                'data' => [
                    'id' => $expense->id,
                    'user_id' => $this->user->id,
                    'client_id' => $this->client->id,
                ]
            ]);
    }

    /**
     * Test expense retrieval fails for non-existent expense.
     */
    public function test_retrieve_expense_fails_for_non_existent(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $response = $this->getJson('/api/v1/pocket-expenses/999999');

        $response->assertStatus(404);
    }

    /**
     * Test successful expense update.
     */
    public function test_can_update_expense(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->draft()
            ->create();

        $updateData = [
            'merchant_name' => 'Updated Merchant',
            'amount' => 200.50,
            'notes' => 'Updated notes',
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $expense->id,
                    'merchant_name' => 'Updated Merchant',
                    'amount' => '200.50',
                    'notes' => 'Updated notes',
                ]
            ]);

        // Verify database state
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'merchant_name' => 'Updated Merchant',
            'amount' => 200.50,
            'notes' => 'Updated notes',
        ]);
    }

    /**
     * Test expense update fails for non-draft status.
     */
    public function test_update_expense_fails_for_submitted_status(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->submitted() // Cannot edit submitted expenses
            ->create();

        $updateData = [
            'merchant_name' => 'Updated Merchant',
            'amount' => 200.50,
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(403); // Forbidden - cannot edit submitted expense
    }

    /**
     * Test successful expense deletion (soft delete).
     */
    public function test_can_delete_expense(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->draft()
            ->create();

        $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(204); // No Content

        // Verify expense is soft deleted
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'deleted' => true,
        ]);

        $this->assertNotNull($expense->fresh()->delete_time);
    }

    /**
     * Test expense deletion fails for non-draft status.
     */
    public function test_delete_expense_fails_for_approved_status(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->approved() // Cannot delete approved expenses
            ->create();

        $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(403); // Forbidden - cannot delete approved expense
    }

    /**
     * Test expense list retrieval with pagination.
     */
    public function test_can_list_expenses_with_pagination(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        // Create multiple expenses
        PocketExpense::factory()
            ->count(25)
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $response = $this->getJson('/api/v1/pocket-expenses?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'client_id',
                        'merchant_name',
                        'amount',
                        'currency',
                        'status',
                    ]
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next',
                ]
            ])
            ->assertJson([
                'meta' => [
                    'per_page' => 10,
                    'total' => 25,
                ]
            ]);
    }

    /**
     * Test expense list filtering by user_id.
     */
    public function test_can_filter_expenses_by_user_id(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $otherUser = User::factory()->create();

        // Create expenses for different users
        PocketExpense::factory()
            ->count(5)
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        PocketExpense::factory()
            ->count(3)
            ->forUserAndClient($otherUser->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $response = $this->getJson("/api/v1/pocket-expenses?user_id={$this->user->id}");

        $response->assertStatus(200)
            ->assertJson([
                'meta' => [
                    'total' => 5,
                ]
            ]);

        // Verify all returned expenses belong to the specified user
        $expenseData = $response->json('data');
        foreach ($expenseData as $expense) {
            $this->assertEquals($this->user->id, $expense['user_id']);
        }
    }

    /**
     * Test expense list filtering by status.
     */
    public function test_can_filter_expenses_by_status(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        // Create expenses with different statuses
        PocketExpense::factory()
            ->count(3)
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->draft()
            ->create();

        PocketExpense::factory()
            ->count(2)
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->submitted()
            ->create();

        PocketExpense::factory()
            ->count(1)
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->approved()
            ->create();

        $response = $this->getJson('/api/v1/pocket-expenses?status=draft');

        $response->assertStatus(200)
            ->assertJson([
                'meta' => [
                    'total' => 3,
                ]
            ]);

        // Verify all returned expenses have draft status
        $expenseData = $response->json('data');
        foreach ($expenseData as $expense) {
            $this->assertEquals('draft', $expense['status']);
        }
    }

    /**
     * Test unauthorized access returns 401.
     */
    public function test_unauthorized_access_returns_401(): void
    {
        $response = $this->getJson('/api/v1/pocket-expenses');

        $response->assertStatus(401);
    }

    /**
     * Test access without proper permissions returns 403.
     */
    public function test_access_without_permissions_returns_403(): void
    {
        $unauthorizedUser = User::factory()->create();
        
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($unauthorizedUser);

        $response = $this->getJson('/api/v1/pocket-expenses');

        $response->assertStatus(403);
    }

    /**
     * Test client scoping - user cannot access expenses from different client.
     */
    public function test_client_scoping_restricts_access(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $otherClient = Client::factory()->create();
        
        // Create expense for different client
        $expense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $otherClient->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(404); // Should not find expense from different client
    }

    /**
     * Test expense creation with FX conversion integration.
     */
    public function test_expense_creation_with_fx_conversion(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        // TODO: Mock PocketExpenseFXService response
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => now()->format('Y-m-d'),
            'merchant_name' => 'International Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'EUR',
            'amount' => 100.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'currency',
                    'amount',
                    // TODO: Add FX-related fields to assertion once FX service is implemented
                ]
            ]);
    }

    /**
     * Test expense amount sign validation based on expense type.
     */
    public function test_expense_amount_sign_validation(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        // Create refund expense type (positive amount sign)
        $refundType = OptPocketExpenseType::factory()->refundFromMerchant()->create();

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => now()->format('Y-m-d'),
            'merchant_name' => 'Refund Merchant',
            'expense_type' => $refundType->id,
            'currency' => 'USD',
            'amount' => 50.00, // Positive amount for refund
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201);

        // Verify amount sign handling is applied correctly
        $expense = PocketExpense::latest()->first();
        $this->assertTrue($expense->amount > 0); // Refund should have positive amount
    }

    /**
     * Test date validation constraint (not older than 3 years).
     */
    public function test_date_validation_constraint(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => now()->subYears(4)->format('Y-m-d'), // Older than 3 years
            'merchant_name' => 'Old Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /**
     * Test merchant name length validation (VARCHAR 180 limit).
     */
    public function test_merchant_name_length_validation(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => now()->format('Y-m-d'),
            'merchant_name' => str_repeat('A', 181), // Exceeds VARCHAR(180) limit
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00,
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_name']);
    }

    /**
     * Test VAT amount validation (0-100 range).
     */
    public function test_vat_amount_validation(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => now()->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00,
            'vat_amount' => 150.00, // Invalid - exceeds 100%
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vat_amount']);
    }

    /**
     * Test expense list excludes soft deleted expenses by default.
     */
    public function test_list_excludes_soft_deleted_expenses(): void
    {
        // TODO: Mock OAuth2 authentication middleware
        $this->actingAs($this->adminUser);

        // Create active expenses
        PocketExpense::factory()
            ->count(3)
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        // Create soft deleted expense
        $deletedExpense = PocketExpense::factory()
            ->forUserAndClient($this->user->id, $this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->deleted()
            ->create();

        $response = $this->getJson('/api/v1/pocket-expenses');

        $response->assertStatus(200)
            ->assertJson([
                'meta' => [
                    'total' => 3, // Should not include deleted expense
                ]
            ]);

        // Verify deleted expense is not in results
        $expenseIds = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($deletedExpense->id, $expenseIds);
    }
}