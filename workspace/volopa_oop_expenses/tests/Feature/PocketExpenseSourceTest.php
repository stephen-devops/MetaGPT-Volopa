<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\User;
use App\Models\UserFeaturePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * Feature tests for expense source management functionality.
 * 
 * Tests the full HTTP request flow for PocketExpenseSourceController
 * including authentication, authorization, validation, and database operations.
 */
class PocketExpenseSourceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test user instance
     */
    protected User $user;

    /**
     * Test client instance  
     */
    protected Client $client;

    /**
     * Test admin user instance
     */
    protected User $admin;

    /**
     * Primary admin user instance
     */
    protected User $primaryAdmin;

    /**
     * Setup test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test users with different roles
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'user@test.com',
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
        ]);

        $this->primaryAdmin = User::factory()->create([
            'name' => 'Primary Admin',
            'email' => 'primary@test.com',
        ]);

        // Create test client
        $this->client = Client::factory()->create([
            'name' => 'Test Client',
        ]);

        // Set up authentication headers for OAuth2
        $this->withHeaders([
            'Authorization' => 'Bearer test-token',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Test that authenticated user can list expense sources for their client.
     */
    public function test_authenticated_user_can_list_expense_sources(): void
    {
        // Create permission for admin to manage sources
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16, // OOP Expense feature
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        // Create test expense sources
        $sources = PocketExpenseSourceClientConfig::factory()->count(3)->create([
            'client_id' => $this->client->id,
        ]);

        // Make authenticated request
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'uuid',
                        'name',
                        'is_default',
                        'client_id',
                    ]
                ]
            ])
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test that unauthenticated user cannot access expense sources.
     */
    public function test_unauthenticated_user_cannot_list_expense_sources(): void
    {
        $response = $this->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(401);
    }

    /**
     * Test that user without permissions cannot access expense sources.
     */
    public function test_user_without_permissions_cannot_list_expense_sources(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(403);
    }

    /**
     * Test that authenticated admin can create a new expense source.
     */
    public function test_authenticated_admin_can_create_expense_source(): void
    {
        // Grant admin permission to manage sources
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $sourceData = [
            'name' => 'Test Credit Card',
            'client_id' => $this->client->id,
            'is_default' => false,
        ];

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/pocket-expense-sources', $sourceData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid', 
                    'name',
                    'is_default',
                    'client_id',
                ]
            ])
            ->assertJson([
                'data' => [
                    'name' => 'Test Credit Card',
                    'is_default' => false,
                    'client_id' => $this->client->id,
                ]
            ]);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'name' => 'Test Credit Card',
            'client_id' => $this->client->id,
            'deleted' => 0,
        ]);
    }

    /**
     * Test that expense source creation validates required fields.
     */
    public function test_expense_source_creation_validates_required_fields(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/pocket-expense-sources', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'client_id']);
    }

    /**
     * Test that expense source creation enforces unique constraint per client.
     */
    public function test_expense_source_creation_enforces_unique_name_per_client(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        // Create existing source
        PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Existing Source',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/pocket-expense-sources', [
                'name' => 'Existing Source',
                'client_id' => $this->client->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test that expense source creation enforces maximum sources per client.
     */
    public function test_expense_source_creation_enforces_maximum_sources_per_client(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        // Create 20 existing sources (maximum allowed)
        PocketExpenseSourceClientConfig::factory()->count(20)->create([
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/pocket-expense-sources', [
                'name' => 'Extra Source',
                'client_id' => $this->client->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Maximum 20 active expense sources per client');
    }

    /**
     * Test that authenticated admin can view a specific expense source.
     */
    public function test_authenticated_admin_can_view_expense_source(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $source = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Test Source',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/pocket-expense-sources/{$source->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'name', 
                    'is_default',
                    'client_id',
                ]
            ])
            ->assertJson([
                'data' => [
                    'id' => $source->id,
                    'name' => 'Test Source',
                    'client_id' => $this->client->id,
                ]
            ]);
    }

    /**
     * Test that authenticated admin can update an expense source.
     */
    public function test_authenticated_admin_can_update_expense_source(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $source = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Original Name',
        ]);

        $updateData = [
            'name' => 'Updated Name',
            'is_default' => true,
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/pocket-expense-sources/{$source->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'uuid',
                    'name',
                    'is_default', 
                    'client_id',
                ]
            ])
            ->assertJson([
                'data' => [
                    'name' => 'Updated Name',
                    'is_default' => true,
                ]
            ]);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $source->id,
            'name' => 'Updated Name',
            'is_default' => 1,
        ]);
    }

    /**
     * Test that global 'Other' source cannot be updated.
     */
    public function test_global_other_source_cannot_be_updated(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $otherSource = PocketExpenseSourceClientConfig::factory()->globalOther()->create();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/pocket-expense-sources/{$otherSource->id}", [
                'name' => 'Updated Other',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test that authenticated admin can delete an expense source.
     */
    public function test_authenticated_admin_can_delete_expense_source(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $source = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/pocket-expense-sources/{$source->id}");

        $response->assertStatus(204);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $source->id,
            'deleted' => 1,
        ]);

        $this->assertDatabaseMissing('pocket_expense_source_client_config', [
            'id' => $source->id,
            'deleted' => 0,
        ]);
    }

    /**
     * Test that global 'Other' source cannot be deleted.
     */
    public function test_global_other_source_cannot_be_deleted(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $otherSource = PocketExpenseSourceClientConfig::factory()->globalOther()->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/pocket-expense-sources/{$otherSource->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'id' => $otherSource->id,
            'deleted' => 0,
        ]);
    }

    /**
     * Test that user cannot access expense source from different client.
     */
    public function test_user_cannot_access_expense_source_from_different_client(): void
    {
        $otherClient = Client::factory()->create();

        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id, // Permission for different client
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $source = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $otherClient->id, // Source belongs to different client
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/pocket-expense-sources/{$source->id}");

        $response->assertStatus(404);
    }

    /**
     * Test that default expense sources are created when client enables OOP feature.
     */
    public function test_default_expense_sources_are_created_when_feature_enabled(): void
    {
        // This test would typically be triggered by feature enablement
        // For now, test the service method that creates default sources
        
        $this->artisan('db:seed', ['--class' => 'PocketExpenseSourceSeeder']);

        // Check that default sources were created
        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'client_id' => $this->client->id,
            'name' => 'Cash',
            'is_default' => 1,
        ]);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'client_id' => $this->client->id,
            'name' => 'Corporate Card',
            'is_default' => 1,
        ]);

        $this->assertDatabaseHas('pocket_expense_source_client_config', [
            'client_id' => $this->client->id,
            'name' => 'Personal Card',
            'is_default' => 1,
        ]);
    }

    /**
     * Test that expense source update validates unique constraint.
     */
    public function test_expense_source_update_validates_unique_constraint(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $existingSource = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Existing Source',
        ]);

        $sourceToUpdate = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Source to Update',
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/pocket-expense-sources/{$sourceToUpdate->id}", [
                'name' => 'Existing Source', // Try to use existing name
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test that expense source listing excludes soft deleted sources.
     */
    public function test_expense_source_listing_excludes_soft_deleted_sources(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        // Create active source
        $activeSource = PocketExpenseSourceClientConfig::factory()->create([
            'client_id' => $this->client->id,
            'name' => 'Active Source',
        ]);

        // Create deleted source
        $deletedSource = PocketExpenseSourceClientConfig::factory()->deleted()->create([
            'client_id' => $this->client->id,
            'name' => 'Deleted Source',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data') // Only active source should be returned
            ->assertJsonPath('data.0.name', 'Active Source');
    }

    /**
     * Test that business user cannot manage expense sources.
     */
    public function test_business_user_cannot_manage_expense_sources(): void
    {
        // Business user should not have permission to manage sources
        $businessUser = User::factory()->create();

        $response = $this->actingAs($businessUser)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}");

        $response->assertStatus(403);

        $response = $this->actingAs($businessUser)
            ->postJson('/api/v1/pocket-expense-sources', [
                'name' => 'New Source',
                'client_id' => $this->client->id,
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test pagination of expense sources.
     */
    public function test_expense_source_listing_supports_pagination(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        // Create multiple sources
        PocketExpenseSourceClientConfig::factory()->count(15)->create([
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$this->client->id}&per_page=10");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => [
                    'current_page',
                    'total',
                    'per_page',
                ]
            ])
            ->assertJsonPath('meta.per_page', 10);
    }

    /**
     * Test that API returns proper error for invalid client_id.
     */
    public function test_expense_source_operations_validate_client_id(): void
    {
        UserFeaturePermission::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => 16,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->user->id,
            'is_enabled' => true,
        ]);

        $invalidClientId = 99999;

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/pocket-expense-sources?client_id={$invalidClientId}");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/pocket-expense-sources', [
                'name' => 'Test Source',
                'client_id' => $invalidClientId,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }
}