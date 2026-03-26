<?php

namespace Tests\Feature;

use App\Models\PocketExpenseSourceClientConfig;
use App\Models\User;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * Feature tests for Pocket Expense Source APIs
 * 
 * Tests CRUD operations for client expense source configuration management.
 * Validates authorization, client scoping, unique constraints, and business rules.
 */
class PocketExpenseSourceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $adminUser;
    private User $businessUser;
    private Client $client;
    private Client $otherClient;
    private PocketExpenseSourceClientConfig $expenseSource;

    /**
     * Set up test data before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test users
        $this->adminUser = User::factory()->create();
        $this->businessUser = User::factory()->create();

        // Create test clients
        $this->client = Client::factory()->create();
        $this->otherClient = Client::factory()->create();

        // Create test expense source
        $this->expenseSource = PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->withName('Test Corporate Card')
            ->create();
    }

    /**
     * Test listing expense sources for a client.
     */
    public function test_can_list_expense_sources_for_client(): void
    {
        // Create multiple sources for the client
        $sources = PocketExpenseSourceClientConfig::factory()
            ->count(3)
            ->forClient($this->client->id)
            ->create();

        // Create sources for other client (should not be included)
        PocketExpenseSourceClientConfig::factory()
            ->count(2)
            ->forClient($this->otherClient->id)
            ->create();

        // Create global 'Other' source (should be included)
        $globalOther = PocketExpenseSourceClientConfig::factory()
            ->globalOther()
            ->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'uuid',
                        'client_id',
                        'name',
                        'is_default',
                        'deleted',
                        'create_time',
                        'update_time'
                    ]
                ],
                'links',
                'meta'
            ])
            ->assertJsonCount(5, 'data'); // 3 client sources + 1 test source + 1 global Other

        // Verify global 'Other' is included
        $response->assertJsonFragment([
            'name' => 'Other',
            'client_id' => null
        ]);
    }

    /**
     * Test creating a new expense source.
     */
    public function test_can_create_expense_source(): void
    {
        $sourceData = [
            'client_id' => $this->client->id,
            'name' => 'New Payment Method',
            'is_default' => false
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'client_id',
                    'name',
                    'is_default',
                    'deleted',
                    'create_time',
                    'update_time'
                ]
            ])
            ->assertJsonFragment([
                'client_id' => $this->client->id,
                'name' => 'New Payment Method',
                'is_default' => false
            ]);

        // Verify source was created in database
        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'client_id' => $this->client->id,
            'name' => 'New Payment Method',
            'is_default' => false,
            'deleted' => false
        ]);
    }

    /**
     * Test creating expense source with default flag.
     */
    public function test_can_create_default_expense_source(): void
    {
        $sourceData = [
            'client_id' => $this->client->id,
            'name' => 'Primary Corporate Card',
            'is_default' => true
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Primary Corporate Card',
                'is_default' => true
            ]);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'client_id' => $this->client->id,
            'name' => 'Primary Corporate Card',
            'is_default' => true
        ]);
    }

    /**
     * Test validation for required fields when creating expense source.
     */
    public function test_validation_required_fields_when_creating(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id', 'name']);
    }

    /**
     * Test validation for invalid client ID.
     */
    public function test_validation_invalid_client_id(): void
    {
        $sourceData = [
            'client_id' => 99999, // Non-existent client ID
            'name' => 'Test Source'
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    /**
     * Test unique constraint validation for source name within client.
     */
    public function test_validation_unique_source_name_per_client(): void
    {
        $sourceData = [
            'client_id' => $this->client->id,
            'name' => $this->expenseSource->name, // Duplicate name for same client
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test that same source name is allowed for different clients.
     */
    public function test_allows_same_source_name_for_different_clients(): void
    {
        $sourceData = [
            'client_id' => $this->otherClient->id,
            'name' => $this->expenseSource->name, // Same name but different client
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'client_id' => $this->otherClient->id,
            'name' => $this->expenseSource->name
        ]);
    }

    /**
     * Test maximum 20 active expense sources per client constraint.
     */
    public function test_validation_maximum_20_sources_per_client(): void
    {
        // Create 19 more sources (we already have 1 from setUp)
        PocketExpenseSourceClientConfig::factory()
            ->count(19)
            ->forClient($this->client->id)
            ->sequence(
                ['name' => 'Source 1'],
                ['name' => 'Source 2'],
                ['name' => 'Source 3'],
                ['name' => 'Source 4'],
                ['name' => 'Source 5'],
                ['name' => 'Source 6'],
                ['name' => 'Source 7'],
                ['name' => 'Source 8'],
                ['name' => 'Source 9'],
                ['name' => 'Source 10'],
                ['name' => 'Source 11'],
                ['name' => 'Source 12'],
                ['name' => 'Source 13'],
                ['name' => 'Source 14'],
                ['name' => 'Source 15'],
                ['name' => 'Source 16'],
                ['name' => 'Source 17'],
                ['name' => 'Source 18'],
                ['name' => 'Source 19']
            )
            ->create();

        // Attempt to create the 21st source
        $sourceData = [
            'client_id' => $this->client->id,
            'name' => 'Source 21',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    /**
     * Test retrieving a specific expense source.
     */
    public function test_can_show_specific_expense_source(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/pocket-expense-sources/{$this->expenseSource->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'client_id',
                    'name',
                    'is_default',
                    'deleted',
                    'create_time',
                    'update_time'
                ]
            ])
            ->assertJsonFragment([
                'id' => $this->expenseSource->id,
                'name' => $this->expenseSource->name
            ]);
    }

    /**
     * Test updating an expense source.
     */
    public function test_can_update_expense_source(): void
    {
        $updateData = [
            'name' => 'Updated Source Name',
            'is_default' => true
        ];

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/pocket-expense-sources/{$this->expenseSource->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Updated Source Name',
                'is_default' => true
            ]);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $this->expenseSource->id,
            'name' => 'Updated Source Name',
            'is_default' => true
        ]);
    }

    /**
     * Test that global 'Other' record cannot be updated.
     */
    public function test_cannot_update_global_other_record(): void
    {
        $globalOther = PocketExpenseSourceClientConfig::factory()
            ->globalOther()
            ->create();

        $updateData = [
            'name' => 'Modified Other',
        ];

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/pocket-expense-sources/{$globalOther->id}", $updateData);

        $response->assertStatus(403); // Forbidden

        // Verify name wasn't changed
        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $globalOther->id,
            'name' => 'Other'
        ]);
    }

    /**
     * Test soft deleting an expense source.
     */
    public function test_can_soft_delete_expense_source(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/pocket-expense-sources/{$this->expenseSource->id}");

        $response->assertStatus(204);

        // Verify source is soft deleted
        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $this->expenseSource->id,
            'deleted' => true
        ]);

        $this->assertDatabaseMissing('pocket_expense_source_client_config', [
            'id' => $this->expenseSource->id,
            'deleted' => false
        ]);
    }

    /**
     * Test that global 'Other' record cannot be deleted.
     */
    public function test_cannot_delete_global_other_record(): void
    {
        $globalOther = PocketExpenseSourceClientConfig::factory()
            ->globalOther()
            ->create();

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/pocket-expense-sources/{$globalOther->id}");

        $response->assertStatus(403); // Forbidden

        // Verify record still exists and is not deleted
        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $globalOther->id,
            'deleted' => false
        ]);
    }

    /**
     * Test that soft-deleted sources are excluded from listings.
     */
    public function test_soft_deleted_sources_excluded_from_listings(): void
    {
        // Soft delete the expense source
        $this->expenseSource->softDelete();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(200);

        // Verify deleted source is not in the response
        $responseData = $response->json('data');
        $sourceIds = collect($responseData)->pluck('id')->toArray();
        
        $this->assertNotContains($this->expenseSource->id, $sourceIds);
    }

    /**
     * Test authorization - unauthorized user cannot access sources.
     */
    public function test_unauthorized_user_cannot_access_sources(): void
    {
        $response = $this->getJson('/api/v1/pocket-expense-sources');

        $response->assertStatus(401); // Unauthorized
    }

    /**
     * Test client scoping - user can only access sources for their clients.
     */
    public function test_user_can_only_access_own_client_sources(): void
    {
        // TODO: Implement authorization policy check
        // This test should verify that users can only access expense sources
        // for clients they have permission to manage
        $this->markTestIncomplete('Authorization policy not yet implemented');
    }

    /**
     * Test creating sources with 3 default types on feature enable.
     */
    public function test_creates_default_sources_on_feature_enable(): void
    {
        // TODO: Implement feature enablement test
        // This should test that when OOP Expense feature is enabled for a client,
        // 3 default sources are created: Cash, Corporate Card, Personal Card
        $this->markTestIncomplete('Feature enablement not yet implemented');
    }

    /**
     * Test pagination for source listings.
     */
    public function test_source_listings_are_paginated(): void
    {
        // Create multiple sources
        PocketExpenseSourceClientConfig::factory()
            ->count(25)
            ->forClient($this->client->id)
            ->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links' => [
                    'first',
                    'last',
                    'prev',
                    'next'
                ],
                'meta' => [
                    'current_page',
                    'from',
                    'last_page',
                    'per_page',
                    'to',
                    'total'
                ]
            ]);
    }

    /**
     * Test filtering active sources only.
     */
    public function test_can_filter_active_sources_only(): void
    {
        // Create active and deleted sources
        $activeSources = PocketExpenseSourceClientConfig::factory()
            ->count(3)
            ->forClient($this->client->id)
            ->create();

        $deletedSources = PocketExpenseSourceClientConfig::factory()
            ->count(2)
            ->forClient($this->client->id)
            ->deleted()
            ->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}&status=active");

        $response->assertStatus(200);

        // Should only return active sources (3 + 1 from setUp)
        $responseData = $response->json('data');
        
        foreach ($responseData as $source) {
            $this->assertEquals(false, $source['deleted']);
        }
    }

    /**
     * Test JSON response format and structure.
     */
    public function test_response_json_structure(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/v1/pocket-expense-sources/{$this->expenseSource->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'client_id',
                    'name',
                    'is_default',
                    'deleted',
                    'create_time',
                    'update_time'
                ]
            ]);

        // Verify data types
        $data = $response->json('data');
        $this->assertIsInt($data['id']);
        $this->assertIsString($data['uuid']);
        $this->assertIsInt($data['client_id']);
        $this->assertIsString($data['name']);
        $this->assertIsBool($data['is_default']);
        $this->assertIsBool($data['deleted']);
    }

    /**
     * Test error handling for non-existent source.
     */
    public function test_returns_404_for_non_existent_source(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/pocket-expense-sources/99999');

        $response->assertStatus(404);
    }

    /**
     * Test validation for source name length constraint.
     */
    public function test_validation_source_name_length(): void
    {
        $sourceData = [
            'client_id' => $this->client->id,
            'name' => str_repeat('A', 256), // Exceeds VARCHAR(255) limit
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test that UUID is auto-generated on creation.
     */
    public function test_uuid_auto_generated_on_creation(): void
    {
        $sourceData = [
            'client_id' => $this->client->id,
            'name' => 'Test Auto UUID',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertNotEmpty($data['uuid']);
        $this->assertTrue(Str::isUuid($data['uuid']));
    }

    /**
     * Test updating timestamps on modification.
     */
    public function test_timestamps_updated_on_modification(): void
    {
        $originalUpdateTime = $this->expenseSource->update_time;

        // Wait a moment to ensure timestamp difference
        sleep(1);

        $updateData = [
            'name' => 'Updated Timestamp Test',
        ];

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/pocket-expense-sources/{$this->expenseSource->id}", $updateData);

        $response->assertStatus(200);

        $this->expenseSource->refresh();
        $this->assertNotEquals($originalUpdateTime, $this->expenseSource->update_time);
    }

    /**
     * Test business logic validation for expense source operations.
     */
    public function test_business_logic_validation(): void
    {
        // Test that deleted sources cannot be updated
        $deletedSource = PocketExpenseSourceClientConfig::factory()
            ->forClient($this->client->id)
            ->deleted()
            ->create();

        $updateData = [
            'name' => 'Should Not Update',
        ];

        $response = $this->actingAs($this->adminUser)
            ->putJson("/api/v1/pocket-expense-sources/{$deletedSource->id}", $updateData);

        // Should return appropriate error status
        $response->assertStatus(403); // Or 422 depending on implementation
    }
}