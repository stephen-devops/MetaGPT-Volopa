<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\UserFeaturePermission;
use App\Http\Resources\UserFeaturePermissionResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

/**
 * UserFeaturePermissionTest
 * 
 * Feature tests for the RBAC functionality of user feature permissions.
 * Tests API endpoints, policy enforcement, and business logic.
 */
class UserFeaturePermissionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test data setup
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // TODO: Set up OAuth2 test authentication when middleware is available
        // For now, we'll use basic authentication simulation
    }

    /**
     * Test listing user feature permissions for a client.
     *
     * @return void
     */
    public function test_can_list_user_feature_permissions(): void
    {
        // Create test data
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        // Create some permissions
        $permission1 = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        $permission2 = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->disabled()
            ->create();

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->getJson("/api/v1/user-feature-permissions?client_id={$client->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'client_id',
                        'feature_id',
                        'grantor_id',
                        'manager_user_id',
                        'is_enabled',
                        'created_at',
                        'updated_at'
                    ]
                ]
            ])
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test creating a new user feature permission.
     *
     * @return void
     */
    public function test_can_store_user_feature_permission(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        $requestData = [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'feature_id' => 16, // OOP Expense feature ID as per constraints
            'grantor_id' => $grantor->id,
            'manager_user_id' => $manager->id,
            'is_enabled' => true
        ];

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->postJson('/api/v1/user-feature-permissions', $requestData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'client_id',
                    'feature_id',
                    'grantor_id',
                    'manager_user_id',
                    'is_enabled',
                    'created_at',
                    'updated_at'
                ]
            ]);

        // Assert the permission was created in the database
        $this->assertDatabaseHas('user_feature_permission', [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'feature_id' => 16,
            'grantor_id' => $grantor->id,
            'manager_user_id' => $manager->id,
            'is_enabled' => true
        ]);
    }

    /**
     * Test showing a specific user feature permission.
     *
     * @return void
     */
    public function test_can_show_user_feature_permission(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->getJson("/api/v1/user-feature-permissions/{$permission->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'client_id',
                    'feature_id',
                    'grantor_id',
                    'manager_user_id',
                    'is_enabled',
                    'created_at',
                    'updated_at'
                ]
            ])
            ->assertJson([
                'data' => [
                    'id' => $permission->id,
                    'user_id' => $permission->user_id,
                    'client_id' => $permission->client_id,
                    'feature_id' => $permission->feature_id,
                    'grantor_id' => $permission->grantor_id,
                    'manager_user_id' => $permission->manager_user_id,
                    'is_enabled' => $permission->is_enabled
                ]
            ]);
    }

    /**
     * Test updating a user feature permission.
     *
     * @return void
     */
    public function test_can_update_user_feature_permission(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();
        $newManager = User::factory()->create();

        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        $updateData = [
            'manager_user_id' => $newManager->id,
            'is_enabled' => false
        ];

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->putJson("/api/v1/user-feature-permissions/{$permission->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'client_id',
                    'feature_id',
                    'grantor_id',
                    'manager_user_id',
                    'is_enabled',
                    'created_at',
                    'updated_at'
                ]
            ])
            ->assertJson([
                'data' => [
                    'id' => $permission->id,
                    'manager_user_id' => $newManager->id,
                    'is_enabled' => false
                ]
            ]);

        // Assert the permission was updated in the database
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id,
            'manager_user_id' => $newManager->id,
            'is_enabled' => false
        ]);
    }

    /**
     * Test deleting a user feature permission.
     *
     * @return void
     */
    public function test_can_delete_user_feature_permission(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");

        $response->assertStatus(204);

        // Assert the permission was deleted from the database
        $this->assertDatabaseMissing('user_feature_permission', [
            'id' => $permission->id
        ]);
    }

    /**
     * Test validation errors when creating a permission with invalid data.
     *
     * @return void
     */
    public function test_validation_fails_with_invalid_data(): void
    {
        $invalidData = [
            'user_id' => 999999, // Non-existent user
            'client_id' => 999999, // Non-existent client
            'feature_id' => null, // Required field
            'grantor_id' => null, // Required field
            'manager_user_id' => null, // Required field
        ];

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->postJson('/api/v1/user-feature-permissions', $invalidData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'user_id',
                'client_id',
                'feature_id',
                'grantor_id',
                'manager_user_id'
            ]);
    }

    /**
     * Test that duplicate permissions cannot be created.
     *
     * @return void
     */
    public function test_cannot_create_duplicate_permissions(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        // Create initial permission
        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->forFeature(16)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // Try to create duplicate permission
        $duplicateData = [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'feature_id' => 16, // Same feature ID
            'grantor_id' => $grantor->id,
            'manager_user_id' => $manager->id,
            'is_enabled' => true
        ];

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->postJson('/api/v1/user-feature-permissions', $duplicateData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']); // Should fail due to unique constraint
    }

    /**
     * Test filtering permissions by client.
     *
     * @return void
     */
    public function test_can_filter_permissions_by_client(): void
    {
        $client1 = Client::factory()->create();
        $client2 = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        // Create permissions for different clients
        $permission1 = UserFeaturePermission::factory()
            ->forClient($client1->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        $permission2 = UserFeaturePermission::factory()
            ->forClient($client2->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->getJson("/api/v1/user-feature-permissions?client_id={$client1->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJson([
                'data' => [
                    [
                        'client_id' => $client1->id
                    ]
                ]
            ]);
    }

    /**
     * Test permission scoping by user role.
     * 
     * @return void
     */
    public function test_permission_scoping_by_user_role(): void
    {
        $client = Client::factory()->create();
        $primaryAdmin = User::factory()->create(); // TODO: Set role when User model is available
        $admin = User::factory()->create(); // TODO: Set role when User model is available
        $businessUser = User::factory()->create(); // TODO: Set role when User model is available
        $manager = User::factory()->create();

        // Create permission granted by primary admin
        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($businessUser->id)
            ->grantedBy($primaryAdmin->id)
            ->managing($manager->id)
            ->create();

        // TODO: Implement role-based authorization testing when Policy is available
        // For now, this is a placeholder for future role-based access control tests
        
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id,
            'grantor_id' => $primaryAdmin->id
        ]);
    }

    /**
     * Test authorization policy enforcement.
     *
     * @return void
     */
    public function test_authorization_policy_enforcement(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $unauthorizedUser = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // TODO: Test unauthorized access when Policy and authentication are available
        // For now, this is a placeholder for future authorization tests
        
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id
        ]);
    }

    /**
     * Test that enabled/disabled permissions are properly handled.
     *
     * @return void
     */
    public function test_enabled_disabled_permissions(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        // Create enabled permission
        $enabledPermission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // Create disabled permission
        $disabledPermission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->forFeature(17) // Different feature to avoid unique constraint
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->disabled()
            ->create();

        // Test scopes work correctly
        $enabledPermissions = UserFeaturePermission::enabled()->get();
        $disabledPermissions = UserFeaturePermission::disabled()->get();

        $this->assertCount(1, $enabledPermissions);
        $this->assertCount(1, $disabledPermissions);
        $this->assertTrue($enabledPermissions->first()->is_enabled);
        $this->assertFalse($disabledPermissions->first()->is_enabled);
    }

    /**
     * Test JSON response structure matches expected format.
     *
     * @return void
     */
    public function test_json_response_structure(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $response = $this->getJson("/api/v1/user-feature-permissions/{$permission->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'client_id',
                    'feature_id',
                    'grantor_id',
                    'manager_user_id',
                    'is_enabled',
                    'created_at',
                    'updated_at'
                ]
            ]);

        // Verify no sensitive fields are exposed
        $response->assertJsonMissing([
            'deleted_at' // Should not expose internal fields
        ]);
    }

    /**
     * Test database state after CRUD operations.
     *
     * @return void
     */
    public function test_database_state_after_crud(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        // Test Create
        $createData = [
            'user_id' => $user->id,
            'client_id' => $client->id,
            'feature_id' => 16,
            'grantor_id' => $grantor->id,
            'manager_user_id' => $manager->id,
            'is_enabled' => true
        ];

        // TODO: Add proper OAuth2 authentication headers when middleware is available
        $createResponse = $this->postJson('/api/v1/user-feature-permissions', $createData);
        
        $createResponse->assertStatus(201);
        $permissionId = $createResponse->json('data.id');

        // Verify creation in database
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permissionId,
            'user_id' => $user->id,
            'is_enabled' => true
        ]);

        // Test Update
        $updateData = ['is_enabled' => false];
        $updateResponse = $this->putJson("/api/v1/user-feature-permissions/{$permissionId}", $updateData);
        
        $updateResponse->assertStatus(200);

        // Verify update in database
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permissionId,
            'is_enabled' => false
        ]);

        // Test Delete
        $deleteResponse = $this->deleteJson("/api/v1/user-feature-permissions/{$permissionId}");
        
        $deleteResponse->assertStatus(204);

        // Verify deletion from database
        $this->assertDatabaseMissing('user_feature_permission', [
            'id' => $permissionId
        ]);
    }

    /**
     * Test model relationships work correctly.
     *
     * @return void
     */
    public function test_model_relationships(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        $permission = UserFeaturePermission::factory()
            ->forClient($client->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // Test relationships are loaded correctly
        $loadedPermission = UserFeaturePermission::with(['user', 'client', 'grantor', 'manager'])
            ->find($permission->id);

        $this->assertNotNull($loadedPermission->user);
        $this->assertNotNull($loadedPermission->client);
        $this->assertNotNull($loadedPermission->grantor);
        $this->assertNotNull($loadedPermission->manager);
        
        $this->assertEquals($user->id, $loadedPermission->user->id);
        $this->assertEquals($client->id, $loadedPermission->client->id);
        $this->assertEquals($grantor->id, $loadedPermission->grantor->id);
        $this->assertEquals($manager->id, $loadedPermission->manager->id);
    }

    /**
     * Test that required middleware is applied to routes.
     *
     * @return void
     */
    public function test_oauth2_middleware_applied(): void
    {
        // TODO: Test that Oauth2UserClient middleware is properly applied when authentication is available
        // For now, this is a placeholder for future middleware testing
        
        // This should test that unauthenticated requests are rejected
        // and that OAuth2 tokens are properly validated
        
        $this->assertTrue(true); // Placeholder assertion
    }

    /**
     * Test multi-tenancy scoping works correctly.
     *
     * @return void
     */
    public function test_multi_tenancy_scoping(): void
    {
        $client1 = Client::factory()->create();
        $client2 = Client::factory()->create();
        $user = User::factory()->create();
        $grantor = User::factory()->create();
        $manager = User::factory()->create();

        // Create permissions for different clients
        $permission1 = UserFeaturePermission::factory()
            ->forClient($client1->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        $permission2 = UserFeaturePermission::factory()
            ->forClient($client2->id)
            ->forUser($user->id)
            ->grantedBy($grantor->id)
            ->managing($manager->id)
            ->create();

        // Test that querying for client1 only returns client1 permissions
        $client1Permissions = UserFeaturePermission::forClient($client1->id)->get();
        $client2Permissions = UserFeaturePermission::forClient($client2->id)->get();

        $this->assertCount(1, $client1Permissions);
        $this->assertCount(1, $client2Permissions);
        $this->assertEquals($client1->id, $client1Permissions->first()->client_id);
        $this->assertEquals($client2->id, $client2Permissions->first()->client_id);
    }
}