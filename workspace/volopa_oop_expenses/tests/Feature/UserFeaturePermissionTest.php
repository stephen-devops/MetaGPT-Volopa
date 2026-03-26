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
 * Feature tests for User Feature Permission API endpoints.
 * 
 * Tests the complete CRUD operations for user permission management
 * including authorization, validation, and proper API responses.
 */
class UserFeaturePermissionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $primaryAdmin;
    private User $admin;
    private User $businessUser;
    private User $cardUser;
    private User $targetUser;
    private Client $client;
    private int $oopFeatureId = 16; // OOP Expense feature ID

    /**
     * Set up test data before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test users with different roles
        $this->primaryAdmin = User::factory()->create(['role' => 'primary_admin']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->businessUser = User::factory()->create(['role' => 'business_user']);
        $this->cardUser = User::factory()->create(['role' => 'card_user']);
        $this->targetUser = User::factory()->create(['role' => 'business_user']);

        // Create test client
        $this->client = Client::factory()->create();

        // TODO: Implement OAuth2 token authentication setup
        // This should simulate the Oauth2UserClient middleware behavior
        $this->actingAs($this->primaryAdmin);
    }

    /**
     * Test listing user permissions with proper pagination.
     */
    public function test_can_list_user_permissions(): void
    {
        // Arrange: Create test permissions
        UserFeaturePermission::factory()
            ->count(3)
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send GET request
        $response = $this->getJson('/api/v1/user-feature-permissions');

        // Assert: Check response structure and status
        $response->assertStatus(200);
        $response->assertJsonStructure([
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
            ],
            'links',
            'meta'
        ]);

        // Assert: Check data count
        $response->assertJsonCount(3, 'data');
    }

    /**
     * Test granting a new user permission.
     */
    public function test_primary_admin_can_grant_user_permission(): void
    {
        // Arrange: Permission data
        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check successful creation
        $response->assertStatus(201);
        $response->assertJsonStructure([
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

        // Assert: Check database state
        $this->assertDatabaseHas('user_feature_permission', [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);
    }

    /**
     * Test admin can only grant permissions for users they manage.
     */
    public function test_admin_can_only_grant_to_managed_users(): void
    {
        // Arrange: Acting as admin (not primary admin)
        $this->actingAs($this->admin);

        // Create permission for admin to manage targetUser
        UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check successful creation (admin can manage this user)
        $response->assertStatus(201);
    }

    /**
     * Test admin cannot grant permissions to users they don't manage.
     */
    public function test_admin_cannot_grant_to_unmanaged_users(): void
    {
        // Arrange: Acting as admin without management rights over targetUser
        $this->actingAs($this->admin);

        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check forbidden access
        $response->assertStatus(403);
    }

    /**
     * Test business user cannot grant permissions.
     */
    public function test_business_user_cannot_grant_permissions(): void
    {
        // Arrange: Acting as business user
        $this->actingAs($this->businessUser);

        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->businessUser->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check forbidden access
        $response->assertStatus(403);
    }

    /**
     * Test card user cannot grant permissions.
     */
    public function test_card_user_cannot_grant_permissions(): void
    {
        // Arrange: Acting as card user
        $this->actingAs($this->cardUser);

        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->cardUser->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check forbidden access
        $response->assertStatus(403);
    }

    /**
     * Test validation fails for duplicate user-client-feature combination.
     */
    public function test_cannot_create_duplicate_permission(): void
    {
        // Arrange: Create existing permission
        UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->forFeature($this->oopFeatureId)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Act: Send POST request for duplicate
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check validation error
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    /**
     * Test validation fails for required fields.
     */
    public function test_validation_fails_for_missing_fields(): void
    {
        // Act: Send POST request with missing fields
        $response = $this->postJson('/api/v1/user-feature-permissions', []);

        // Assert: Check validation errors
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id', 'client_id', 'feature_id', 'manager_user_id']);
    }

    /**
     * Test validation fails for non-existent user.
     */
    public function test_validation_fails_for_nonexistent_user(): void
    {
        // Arrange: Permission data with non-existent user
        $permissionData = [
            'user_id' => 99999,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check validation error
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    /**
     * Test validation fails for non-existent client.
     */
    public function test_validation_fails_for_nonexistent_client(): void
    {
        // Arrange: Permission data with non-existent client
        $permissionData = [
            'user_id' => $this->targetUser->id,
            'client_id' => 99999,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Act: Send POST request
        $response = $this->postJson('/api/v1/user-feature-permissions', $permissionData);

        // Assert: Check validation error
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id']);
    }

    /**
     * Test getting specific permission details.
     */
    public function test_can_show_specific_permission(): void
    {
        // Arrange: Create test permission
        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send GET request
        $response = $this->getJson("/api/v1/user-feature-permissions/{$permission->id}");

        // Assert: Check successful response
        $response->assertStatus(200);
        $response->assertJsonStructure([
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

        // Assert: Check correct data
        $response->assertJson([
            'data' => [
                'id' => $permission->id,
                'user_id' => $this->targetUser->id,
                'client_id' => $this->client->id,
            ]
        ]);
    }

    /**
     * Test 404 error for non-existent permission.
     */
    public function test_returns_404_for_nonexistent_permission(): void
    {
        // Act: Send GET request for non-existent permission
        $response = $this->getJson('/api/v1/user-feature-permissions/99999');

        // Assert: Check 404 error
        $response->assertStatus(404);
    }

    /**
     * Test updating user permission.
     */
    public function test_can_update_user_permission(): void
    {
        // Arrange: Create test permission
        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        $updateData = [
            'is_enabled' => false,
        ];

        // Act: Send PUT request
        $response = $this->putJson("/api/v1/user-feature-permissions/{$permission->id}", $updateData);

        // Assert: Check successful update
        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'id' => $permission->id,
                'is_enabled' => false,
            ]
        ]);

        // Assert: Check database state
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id,
            'is_enabled' => false,
        ]);
    }

    /**
     * Test admin cannot update permissions they don't manage.
     */
    public function test_admin_cannot_update_unmanaged_permissions(): void
    {
        // Arrange: Acting as admin without management rights
        $this->actingAs($this->admin);

        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->primaryAdmin->id) // Managed by primary admin, not current admin
            ->create();

        $updateData = [
            'is_enabled' => false,
        ];

        // Act: Send PUT request
        $response = $this->putJson("/api/v1/user-feature-permissions/{$permission->id}", $updateData);

        // Assert: Check forbidden access
        $response->assertStatus(403);
    }

    /**
     * Test deleting (revoking) user permission.
     */
    public function test_can_revoke_user_permission(): void
    {
        // Arrange: Create test permission
        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send DELETE request
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");

        // Assert: Check successful deletion
        $response->assertStatus(204);

        // Assert: Check database state (soft delete or hard delete based on implementation)
        $this->assertDatabaseMissing('user_feature_permission', [
            'id' => $permission->id,
        ]);
    }

    /**
     * Test admin cannot revoke permissions they don't manage.
     */
    public function test_admin_cannot_revoke_unmanaged_permissions(): void
    {
        // Arrange: Acting as admin without management rights
        $this->actingAs($this->admin);

        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->primaryAdmin->id) // Managed by primary admin, not current admin
            ->create();

        // Act: Send DELETE request
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");

        // Assert: Check forbidden access
        $response->assertStatus(403);
    }

    /**
     * Test business user cannot revoke permissions.
     */
    public function test_business_user_cannot_revoke_permissions(): void
    {
        // Arrange: Acting as business user
        $this->actingAs($this->businessUser);

        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send DELETE request
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");

        // Assert: Check forbidden access
        $response->assertStatus(403);
    }

    /**
     * Test API returns proper error format for validation failures.
     */
    public function test_returns_proper_error_format(): void
    {
        // Act: Send POST request with invalid data
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => 'invalid',
            'client_id' => 'invalid',
            'feature_id' => 'invalid',
            'manager_user_id' => 'invalid',
        ]);

        // Assert: Check error format
        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'user_id',
                'client_id',
                'feature_id',
                'manager_user_id',
            ]
        ]);
    }

    /**
     * Test client scoping - users from different clients cannot interfere.
     */
    public function test_client_scoping_prevents_cross_client_access(): void
    {
        // Arrange: Create another client and user
        $anotherClient = Client::factory()->create();
        $anotherUser = User::factory()->create(['role' => 'business_user']);

        // Create permission for user in another client
        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($anotherUser->id, $anotherClient->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Try to access permission from wrong client context
        $response = $this->getJson("/api/v1/user-feature-permissions/{$permission->id}");

        // Assert: Check authorization (should depend on policy implementation)
        // TODO: Implement proper client scoping validation in policy
        $response->assertStatus(403);
    }

    /**
     * Test pagination works correctly.
     */
    public function test_pagination_works_correctly(): void
    {
        // Arrange: Create many permissions
        UserFeaturePermission::factory()
            ->count(25)
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send GET request with pagination
        $response = $this->getJson('/api/v1/user-feature-permissions?page=1');

        // Assert: Check pagination structure
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'links' => [
                'first',
                'last',
                'prev',
                'next',
            ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total',
            ]
        ]);

        // Assert: Check data is limited per page
        $this->assertLessThanOrEqual(15, count($response->json('data'))); // Assuming default per_page is 15
    }

    /**
     * Test filtering permissions by client.
     */
    public function test_can_filter_permissions_by_client(): void
    {
        // Arrange: Create permissions for different clients
        $anotherClient = Client::factory()->create();
        
        UserFeaturePermission::factory()
            ->count(2)
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        UserFeaturePermission::factory()
            ->count(3)
            ->forUserAndClient($this->targetUser->id, $anotherClient->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send GET request with client filter
        $response = $this->getJson("/api/v1/user-feature-permissions?client_id={$this->client->id}");

        // Assert: Check filtered results
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        
        // Assert: All returned permissions belong to the specified client
        $permissions = $response->json('data');
        foreach ($permissions as $permission) {
            $this->assertEquals($this->client->id, $permission['client_id']);
        }
    }

    /**
     * Test unauthenticated request returns 401.
     */
    public function test_unauthenticated_request_returns_401(): void
    {
        // Arrange: Clear authentication
        // TODO: Implement proper OAuth2 token removal for testing
        $this->app['auth']->forgetGuards();

        // Act: Send request without authentication
        $response = $this->getJson('/api/v1/user-feature-permissions');

        // Assert: Check unauthorized access
        $response->assertStatus(401);
    }

    /**
     * Test API Resource response format matches expected structure.
     */
    public function test_api_resource_response_format(): void
    {
        // Arrange: Create test permission
        $permission = UserFeaturePermission::factory()
            ->forUserAndClient($this->targetUser->id, $this->client->id)
            ->grantedBy($this->primaryAdmin->id, $this->admin->id)
            ->create();

        // Act: Send GET request
        $response = $this->getJson("/api/v1/user-feature-permissions/{$permission->id}");

        // Assert: Check response matches UserFeaturePermissionResource structure
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'client_id',
                'feature_id',
                'grantor_id',
                'manager_user_id',
                'is_enabled',
                'created_at',
                'updated_at',
                // TODO: Add relationships if they're included in the Resource
                // 'user',
                // 'client',
                // 'grantor',
                // 'manager',
            ]
        ]);

        // Assert: Check data types
        $data = $response->json('data');
        $this->assertIsInt($data['id']);
        $this->assertIsInt($data['user_id']);
        $this->assertIsInt($data['client_id']);
        $this->assertIsInt($data['feature_id']);
        $this->assertIsInt($data['grantor_id']);
        $this->assertIsInt($data['manager_user_id']);
        $this->assertIsBool($data['is_enabled']);
        $this->assertIsString($data['created_at']);
        $this->assertIsString($data['updated_at']);
    }
}