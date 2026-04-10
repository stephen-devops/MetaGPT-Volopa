<?php

namespace Tests\Feature;

use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\PocketExpenseMetadata;
use App\Models\User;
use App\Models\Client;
use App\Services\PocketExpenseService;
use App\Services\PocketExpenseFXService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use Tests\TestCase;

class PocketExpenseTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private User $adminUser;
    private Client $client;
    private OptPocketExpenseType $expenseType;
    private PocketExpenseSourceClientConfig $expenseSource;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'user@example.com'
        ]);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com'
        ]);

        // Create test client
        $this->client = Client::factory()->create([
            'name' => 'Test Client'
        ]);

        // Create test expense type
        $this->expenseType = OptPocketExpenseType::factory()->pointOfSale()->create();

        // Create test expense source
        $this->expenseSource = PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->cash()
            ->create();

        // TODO: Set up OAuth2 authentication for test requests
        // This should authenticate as the adminUser with proper client context
    }

    /** @test */
    public function it_can_list_expenses_for_authenticated_user(): void
    {
        // Create test expenses
        $expense1 = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->submitted()
            ->create();

        $expense2 = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->approved()
            ->create();

        $response = $this->getJson("/api/v1/pocket-expenses?client_id={$this->client->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'uuid',
                        'date',
                        'merchant_name',
                        'currency',
                        'amount',
                        'status',
                        'expense_type',
                        'created_by',
                        'metadata'
                    ]
                ],
                'links',
                'meta'
            ])
            ->assertJsonCount(2, 'data');

        // Assert specific expense data is returned correctly
        $responseData = $response->json('data');
        $this->assertEquals($expense1->id, $responseData[0]['id']);
        $this->assertEquals($expense2->id, $responseData[1]['id']);
    }

    /** @test */
    public function it_can_create_a_new_expense(): void
    {
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'merchant_description' => 'Test purchase',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.50,
            'merchant_address' => '123 Test Street',
            'vat_amount' => 15.75,
            'notes' => 'Test expense notes',
            'metadata' => [
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSource->id
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'date',
                    'merchant_name',
                    'currency',
                    'amount',
                    'status',
                    'expense_type',
                    'created_by',
                    'metadata'
                ]
            ]);

        // Assert expense was created in database
        $this->assertDatabaseHas('pocket_expense', [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'merchant_name' => 'Test Merchant',
            'currency' => 'USD',
            'amount' => 100.50,
            'status' => 'draft',
            'deleted' => 0
        ]);

        // Assert metadata was created
        $expense = PocketExpense::where('merchant_name', 'Test Merchant')->first();
        $this->assertDatabaseHas('pocket_expense_metadata', [
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'expense_source_id' => $this->expenseSource->id,
            'deleted' => 0
        ]);
    }

    /** @test */
    public function it_can_show_a_specific_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        // Create metadata for the expense
        PocketExpenseMetadata::factory()
            ->forPocketExpense($expense->id)
            ->forUser($this->user->id)
            ->expenseSource($this->expenseSource->id)
            ->create();

        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'date',
                    'merchant_name',
                    'currency',
                    'amount',
                    'status',
                    'expense_type',
                    'created_by',
                    'metadata'
                ]
            ]);

        $responseData = $response->json('data');
        $this->assertEquals($expense->id, $responseData['id']);
        $this->assertEquals($expense->uuid, $responseData['uuid']);
        $this->assertEquals($expense->merchant_name, $responseData['merchant_name']);
        $this->assertIsArray($responseData['metadata']);
    }

    /** @test */
    public function it_can_update_an_existing_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $updateData = [
            'merchant_name' => 'Updated Merchant',
            'amount' => 200.00,
            'notes' => 'Updated notes',
            'metadata' => [
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSource->id
                ]
            ]
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'date',
                    'merchant_name',
                    'currency',
                    'amount',
                    'status',
                    'expense_type',
                    'created_by',
                    'metadata'
                ]
            ]);

        // Assert expense was updated in database
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'merchant_name' => 'Updated Merchant',
            'amount' => 200.00,
            'notes' => 'Updated notes',
            'deleted' => 0
        ]);

        $responseData = $response->json('data');
        $this->assertEquals('Updated Merchant', $responseData['merchant_name']);
        $this->assertEquals(200.00, $responseData['amount']);
    }

    /** @test */
    public function it_can_delete_an_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $response = $this->deleteJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(204);

        // Assert expense was soft deleted in database
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'deleted' => 1
        ]);

        // Assert delete_time was set
        $deletedExpense = PocketExpense::find($expense->id);
        $this->assertEquals(1, $deletedExpense->deleted);
        $this->assertNotNull($deletedExpense->delete_time);
    }

    /** @test */
    public function it_can_approve_an_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->submitted()
            ->create();

        $response = $this->patchJson("/api/v1/pocket-expenses/{$expense->id}/approve");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'status',
                    'approved_by'
                ]
            ]);

        // Assert expense status was updated to approved
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'status' => 'approved',
            'approved_by_user_id' => $this->adminUser->id // TODO: Should be authenticated user
        ]);

        $responseData = $response->json('data');
        $this->assertEquals('approved', $responseData['status']);
        $this->assertNotNull($responseData['approved_by']);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_expense(): void
    {
        $incompleteData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            // Missing required fields: date, merchant_name, expense_type, currency, amount
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $incompleteData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'date',
                'merchant_name',
                'expense_type',
                'currency',
                'amount'
            ]);
    }

    /** @test */
    public function it_validates_expense_type_exists(): void
    {
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'expense_type' => 99999, // Non-existent expense type
            'currency' => 'USD',
            'amount' => 100.00
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['expense_type']);
    }

    /** @test */
    public function it_validates_date_not_older_than_3_years(): void
    {
        $oldDate = now()->subYears(4)->format('Y-m-d'); // 4 years ago

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => $oldDate,
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    /** @test */
    public function it_validates_currency_code_format(): void
    {
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'INVALID', // Invalid currency code
            'amount' => 100.00
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    /** @test */
    public function it_validates_merchant_name_length(): void
    {
        $longMerchantName = str_repeat('A', 181); // Exceeds VARCHAR(180) limit

        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => $longMerchantName,
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_name']);
    }

    /** @test */
    public function it_validates_vat_amount_range(): void
    {
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00,
            'vat_amount' => 150.00 // Exceeds 100% limit
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vat_amount']);
    }

    /** @test */
    public function it_enforces_client_scoping(): void
    {
        $otherClient = Client::factory()->create(['name' => 'Other Client']);
        
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($otherClient->id) // Different client
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        // Try to access expense from wrong client context
        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}?client_id={$this->client->id}");

        $response->assertStatus(404); // Should not be found due to client scoping
    }

    /** @test */
    public function it_handles_fx_conversion_when_creating_expense(): void
    {
        // TODO: Mock PocketExpenseFXService to test FX conversion
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'EUR', // Different from base currency
            'amount' => 100.00
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201);

        // Assert FX conversion was applied
        $expense = PocketExpense::where('merchant_name', 'Test Merchant')->first();
        $this->assertNotNull($expense);
        $this->assertEquals('EUR', $expense->currency);
        // TODO: Assert converted amount is calculated correctly based on FX service
    }

    /** @test */
    public function it_applies_amount_sign_based_on_expense_type(): void
    {
        // Test negative expense type
        $negativeExpenseType = OptPocketExpenseType::factory()->negative()->create();
        
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'expense_type' => $negativeExpenseType->id,
            'currency' => 'USD',
            'amount' => 100.00 // Positive input
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201);

        // Assert amount sign was applied correctly
        $expense = PocketExpense::where('merchant_name', 'Test Merchant')->first();
        $this->assertEquals(-100.00, $expense->amount); // Should be negative

        // Test positive expense type (refund)
        $positiveExpenseType = OptPocketExpenseType::factory()->positive()->create();
        
        $refundData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Refund Merchant',
            'expense_type' => $positiveExpenseType->id,
            'currency' => 'USD',
            'amount' => 50.00
        ];

        $refundResponse = $this->postJson('/api/v1/pocket-expenses', $refundData);

        $refundResponse->assertStatus(201);

        $refund = PocketExpense::where('merchant_name', 'Refund Merchant')->first();
        $this->assertEquals(50.00, $refund->amount); // Should remain positive
    }

    /** @test */
    public function it_creates_expense_with_draft_status_by_default(): void
    {
        $expenseData = [
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'date' => '2024-01-15',
            'merchant_name' => 'Test Merchant',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.00
        ];

        $response = $this->postJson('/api/v1/pocket-expenses', $expenseData);

        $response->assertStatus(201);

        $expense = PocketExpense::where('merchant_name', 'Test Merchant')->first();
        $this->assertEquals('draft', $expense->status);
    }

    /** @test */
    public function it_can_change_expense_status_from_draft_to_submitted(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create(); // Default status is 'draft'

        $updateData = [
            'status' => 'submitted'
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(200);

        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'status' => 'submitted'
        ]);
    }

    /** @test */
    public function it_prevents_editing_approved_expenses(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->approved()
            ->create();

        $updateData = [
            'merchant_name' => 'Should Not Update',
            'amount' => 999.99
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $updateData);

        $response->assertStatus(403); // Forbidden to edit approved expense
    }

    /** @test */
    public function it_enforces_authorization_policies(): void
    {
        // TODO: Test that users can only access their own expenses unless they have management permissions
        // TODO: Test that only users with appropriate permissions can approve expenses
        // TODO: Test that Business Users and Card Users cannot approve expenses even with management rights
        
        $otherUser = User::factory()->create(['name' => 'Other User']);
        
        $expense = PocketExpense::factory()
            ->forUser($otherUser->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        // Try to access another user's expense without proper permissions
        $response = $this->getJson("/api/v1/pocket-expenses/{$expense->id}");

        $response->assertStatus(403); // Should be forbidden
    }

    /** @test */
    public function it_handles_metadata_creation_and_updates(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        $metadataData = [
            'metadata' => [
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSource->id
                ],
                [
                    'metadata_type' => 'category',
                    'transaction_category_id' => 1, // TODO: Create actual category for test
                    'details_json' => ['note' => 'Business travel']
                ]
            ]
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $metadataData);

        $response->assertStatus(200);

        // Assert metadata was created
        $this->assertDatabaseHas('pocket_expense_metadata', [
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'expense_source_id' => $this->expenseSource->id,
            'deleted' => 0
        ]);

        $this->assertDatabaseHas('pocket_expense_metadata', [
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'category',
            'transaction_category_id' => 1,
            'deleted' => 0
        ]);
    }

    /** @test */
    public function it_enforces_unique_constraint_on_metadata_type_per_expense(): void
    {
        $expense = PocketExpense::factory()
            ->forUser($this->user->id)
            ->forClient($this->client->id)
            ->withExpenseType($this->expenseType->id)
            ->createdBy($this->adminUser->id)
            ->create();

        // Create initial metadata
        PocketExpenseMetadata::factory()
            ->forPocketExpense($expense->id)
            ->forUser($this->user->id)
            ->expenseSource($this->expenseSource->id)
            ->create();

        // Try to create duplicate metadata type
        $duplicateMetadataData = [
            'metadata' => [
                [
                    'metadata_type' => 'expense_source',
                    'expense_source_id' => $this->expenseSource->id
                ]
            ]
        ];

        $response = $this->putJson("/api/v1/pocket-expenses/{$expense->id}", $duplicateMetadataData);

        // Should handle gracefully (update existing rather than create duplicate)
        $response->assertStatus(200);

        // Assert only one metadata record exists for this type
        $metadataCount = PocketExpenseMetadata::where([
            'pocket_expense_id' => $expense->id,
            'metadata_type' => 'expense_source',
            'deleted' => 0
        ])->count();

        $this->assertEquals(1, $metadataCount);
    }
}