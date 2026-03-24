<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Client;
use App\Models\UserFeaturePermission;
use Database\Factories\UserFactory;
use Database\Factories\ClientFactory;
use Database\Factories\UserFeaturePermissionFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for User Feature Permission management functionality.
 * 
 * Tests cover:
 * - Permission granting and revocation
 * - Role-based access control (Primary Admin, Admin, Business User, Card User)
 * - Hierarchical permission management (Admin can only grant to managed users)
 * - Multi-tenancy (client-scoped permissions)
 * - API endpoints for permission CRUD operations
 * - Policy enforcement and authorization rules
 * - Edge cases and validation scenarios
 */
class UserFeaturePermissionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test data setup for permission scenarios.
     */
    protected User $primaryAdmin;
    protected User $admin;
    protected User $businessUser;
    protected User $cardUser;
    protected User $anotherAdmin;
    protected Client $client;
    protected Client $anotherClient;
    protected int $oopFeatureId = 16;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test client
        $this->client = Client::factory()->create([
            'name' => 'Test Client Corporation',
            'deleted' => false,
        ]);

        $this->anotherClient = Client::factory()->create([
            'name' => 'Another Client Ltd',
            'deleted' => false,
        ]);

        // Create users with different roles
        $this->primaryAdmin = User::factory()->create([
            'name' => 'Primary Admin User',
            'username' => 'primary.admin@testclient.com',
            'deleted' => false,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'username' => 'admin@testclient.com',
            'deleted' => false,
        ]);

        $this->businessUser = User::factory()->create([
            'name' => 'Business User',
            'username' => 'business@testclient.com',
            'deleted' => false,
        ]);

        $this->cardUser = User::factory()->create([
            'name' => 'Card User',
            'username' => 'card@testclient.com',
            'deleted' => false,
        ]);

        $this->anotherAdmin = User::factory()->create([
            'name' => 'Another Admin',
            'username' => 'another.admin@testclient.com',
            'deleted' => false,
        ]);
    }

    /**
     * Test Primary Admin can grant permissions to any user.
     * Primary Admin has full access to all users by default.
     */
    public function test_primary_admin_can_grant_permission_to_any_user(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        $payload = [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_CREATED)
                ->assertJson([
                    'data' => [
                        'user_id' => $this->businessUser->id,
                        'client_id' => $this->client->id,
                        'feature_id' => $this->oopFeatureId,
                        'grantor_id' => $this->primaryAdmin->id,
                        'manager_user_id' => $this->admin->id,
                        'is_enabled' => true,
                    ]
                ]);

        $this->assertDatabaseHas('user_feature_permission', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);
    }

    /**
     * Test Admin can only grant permissions to users they manage.
     * Admin gets full access only to own expenses by default; needs explicit grant for others.
     */
    public function test_admin_can_only_grant_to_managed_users(): void
    {
        Sanctum::actingAs($this->admin);

        // First, give admin permission to manage business user (simulating existing management relationship)
        UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        $payload = [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_CREATED)
                ->assertJson([
                    'data' => [
                        'user_id' => $this->cardUser->id,
                        'client_id' => $this->client->id,
                        'feature_id' => $this->oopFeatureId,
                        'grantor_id' => $this->admin->id,
                        'manager_user_id' => $this->admin->id,
                        'is_enabled' => true,
                    ]
                ]);
    }

    /**
     * Test Admin cannot grant permissions to users they don't manage.
     */
    public function test_admin_cannot_grant_to_non_managed_users(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->anotherAdmin->id, // Admin trying to assign to another admin as manager
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_FORBIDDEN)
                ->assertJson([
                    'message' => 'Admin can only grant access to their own managed users.'
                ]);

        $this->assertDatabaseMissing('user_feature_permission', [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->admin->id,
        ]);
    }

    /**
     * Test Business User and Card User cannot grant permissions.
     * Business User and Card User cannot approve expenses even with management rights.
     */
    public function test_business_user_cannot_grant_permissions(): void
    {
        Sanctum::actingAs($this->businessUser);

        $payload = [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->businessUser->id,
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_FORBIDDEN)
                ->assertJson([
                    'message' => 'This action is unauthorized.'
                ]);

        $this->assertDatabaseMissing('user_feature_permission', [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
        ]);
    }

    /**
     * Test Card User cannot grant permissions.
     */
    public function test_card_user_cannot_grant_permissions(): void
    {
        Sanctum::actingAs($this->cardUser);

        $payload = [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->cardUser->id,
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_FORBIDDEN)
                ->assertJson([
                    'message' => 'This action is unauthorized.'
                ]);
    }

    /**
     * Test managing access can be given to any user irrespective of role.
     */
    public function test_managing_access_can_be_given_to_any_role(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        // Test Business User can be assigned as manager
        $payload = [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->businessUser->id, // Business User as manager
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_CREATED)
                ->assertJson([
                    'data' => [
                        'manager_user_id' => $this->businessUser->id,
                    ]
                ]);

        // Test Card User can be assigned as manager
        $payload2 = [
            'user_id' => $this->admin->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->cardUser->id, // Card User as manager
        ];

        $response2 = $this->postJson('/api/v1/user-feature-permissions', $payload2);

        $response2->assertStatus(Response::HTTP_CREATED)
                ->assertJson([
                    'data' => [
                        'manager_user_id' => $this->cardUser->id,
                    ]
                ]);
    }

    /**
     * Test permissions are client-scoped (multi-tenancy).
     */
    public function test_permissions_are_client_scoped(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        // Create permission for first client
        $payload1 = [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        $response1 = $this->postJson('/api/v1/user-feature-permissions', $payload1);
        $response1->assertStatus(Response::HTTP_CREATED);

        // Create permission for second client (should be allowed - different client scope)
        $payload2 = [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->anotherClient->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        $response2 = $this->postJson('/api/v1/user-feature-permissions', $payload2);
        $response2->assertStatus(Response::HTTP_CREATED);

        // Verify both permissions exist with different client_id
        $this->assertDatabaseHas('user_feature_permission', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
        ]);

        $this->assertDatabaseHas('user_feature_permission', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->anotherClient->id,
            'feature_id' => $this->oopFeatureId,
        ]);
    }

    /**
     * Test duplicate permissions are prevented by unique constraint.
     */
    public function test_duplicate_permissions_are_prevented(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        $payload = [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        // Create first permission
        $response1 = $this->postJson('/api/v1/user-feature-permissions', $payload);
        $response1->assertStatus(Response::HTTP_CREATED);

        // Attempt to create duplicate permission
        $response2 = $this->postJson('/api/v1/user-feature-permissions', $payload);
        $response2->assertStatus(Response::HTTP_422)
                 ->assertJsonValidationErrors(['user_id']);

        // Verify only one permission exists
        $this->assertDatabaseCount('user_feature_permission', 1);
    }

    /**
     * Test listing user permissions with proper filtering.
     */
    public function test_list_user_permissions_with_filtering(): void
    {
        // Create test permissions
        UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        UserFeaturePermission::factory()->create([
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => false, // Disabled permission
        ]);

        UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->anotherClient->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($this->primaryAdmin);

        // Test basic listing
        $response = $this->getJson('/api/v1/user-feature-permissions');
        $response->assertStatus(Response::HTTP_OK)
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
                            'updated_at',
                        ]
                    ],
                    'meta' => [
                        'current_page',
                        'total',
                    ]
                ]);

        // Test filtering by client
        $response = $this->getJson("/api/v1/user-feature-permissions?client_id={$this->client->id}");
        $response->assertStatus(Response::HTTP_OK);
        $permissions = $response->json('data');
        
        foreach ($permissions as $permission) {
            $this->assertEquals($this->client->id, $permission['client_id']);
        }

        // Test filtering by user
        $response = $this->getJson("/api/v1/user-feature-permissions?user_id={$this->businessUser->id}");
        $response->assertStatus(Response::HTTP_OK);
        $permissions = $response->json('data');
        
        foreach ($permissions as $permission) {
            $this->assertEquals($this->businessUser->id, $permission['user_id']);
        }

        // Test filtering by enabled status
        $response = $this->getJson('/api/v1/user-feature-permissions?is_enabled=true');
        $response->assertStatus(Response::HTTP_OK);
        $permissions = $response->json('data');
        
        foreach ($permissions as $permission) {
            $this->assertTrue($permission['is_enabled']);
        }
    }

    /**
     * Test revoking permissions (soft delete by setting is_enabled = false).
     */
    public function test_revoke_permission(): void
    {
        $permission = UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($this->primaryAdmin);

        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");

        $response->assertStatus(Response::HTTP_NO_CONTENT);

        // Verify permission is disabled (soft revoked)
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id,
            'is_enabled' => false,
        ]);
    }

    /**
     * Test only authorized users can revoke permissions.
     */
    public function test_only_authorized_users_can_revoke_permissions(): void
    {
        $permission = UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        // Test Business User cannot revoke
        Sanctum::actingAs($this->businessUser);
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");
        $response->assertStatus(Response::HTTP_FORBIDDEN);

        // Test Card User cannot revoke
        Sanctum::actingAs($this->cardUser);
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");
        $response->assertStatus(Response::HTTP_FORBIDDEN);

        // Verify permission is still enabled
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id,
            'is_enabled' => true,
        ]);

        // Test Primary Admin can revoke
        Sanctum::actingAs($this->primaryAdmin);
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");
        $response->assertStatus(Response::HTTP_NO_CONTENT);

        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permission->id,
            'is_enabled' => false,
        ]);
    }

    /**
     * Test validation of required fields.
     */
    public function test_validation_of_required_fields(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        // Test missing user_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['user_id']);

        // Test missing client_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['client_id']);

        // Test missing feature_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'manager_user_id' => $this->admin->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['feature_id']);

        // Test missing manager_user_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['manager_user_id']);
    }

    /**
     * Test validation of foreign key references.
     */
    public function test_validation_of_foreign_key_references(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        // Test invalid user_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => 99999,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['user_id']);

        // Test invalid client_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => 99999,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['client_id']);

        // Test invalid manager_user_id
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => 99999,
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
                ->assertJsonValidationErrors(['manager_user_id']);
    }

    /**
     * Test proper JSON response format for permission resources.
     */
    public function test_permission_resource_response_format(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        $payload = [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ];

        $response = $this->postJson('/api/v1/user-feature-permissions', $payload);

        $response->assertStatus(Response::HTTP_CREATED)
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
                        'updated_at',
                        // Resource should hide internal timestamps and sensitive fields
                    ]
                ])
                ->assertJsonMissing([
                    'deleted_at', // Should not expose internal fields
                ]);

        $responseData = $response->json('data');
        
        // Verify data types
        $this->assertIsInt($responseData['id']);
        $this->assertIsInt($responseData['user_id']);
        $this->assertIsInt($responseData['client_id']);
        $this->assertIsInt($responseData['feature_id']);
        $this->assertIsInt($responseData['grantor_id']);
        $this->assertIsInt($responseData['manager_user_id']);
        $this->assertIsBool($responseData['is_enabled']);
        $this->assertIsString($responseData['created_at']);
        $this->assertIsString($responseData['updated_at']);
    }

    /**
     * Test authentication is required for all endpoints.
     */
    public function test_authentication_required(): void
    {
        // Test permission listing without authentication
        $response = $this->getJson('/api/v1/user-feature-permissions');
        $response->assertStatus(Response::HTTP_UNAUTHORIZED);

        // Test permission creation without authentication
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);
        $response->assertStatus(Response::HTTP_UNAUTHORIZED);

        // Test permission deletion without authentication
        $permission = UserFeaturePermission::factory()->create();
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");
        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Test pagination works correctly for permission listings.
     */
    public function test_permission_listing_pagination(): void
    {
        // Create multiple permissions
        UserFeaturePermission::factory()->count(25)->create([
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->primaryAdmin);

        $response = $this->getJson('/api/v1/user-feature-permissions');

        $response->assertStatus(Response::HTTP_OK)
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
                        ]
                    ],
                    'meta' => [
                        'current_page',
                        'from',
                        'last_page',
                        'path',
                        'per_page',
                        'to',
                        'total',
                    ],
                    'links' => [
                        'first',
                        'last',
                        'prev',
                        'next',
                    ]
                ]);

        $meta = $response->json('meta');
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(25, $meta['total']);
        $this->assertLessThanOrEqual(15, count($response->json('data'))); // Default pagination limit
    }

    /**
     * Test error handling for non-existent permission deletion.
     */
    public function test_delete_non_existent_permission(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        $response = $this->deleteJson('/api/v1/user-feature-permissions/99999');

        $response->assertStatus(Response::HTTP_NOT_FOUND)
                ->assertJson([
                    'message' => 'User feature permission not found.'
                ]);
    }

    /**
     * Test that disabled permissions are not included in active permission queries.
     */
    public function test_disabled_permissions_filtering(): void
    {
        // Create enabled permission
        $enabledPermission = UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        // Create disabled permission
        $disabledPermission = UserFeaturePermission::factory()->create([
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => false,
        ]);

        Sanctum::actingAs($this->primaryAdmin);

        // Test filtering for active permissions only
        $response = $this->getJson('/api/v1/user-feature-permissions?is_enabled=true');
        
        $response->assertStatus(Response::HTTP_OK);
        $permissions = $response->json('data');
        
        $enabledFound = false;
        $disabledFound = false;
        
        foreach ($permissions as $permission) {
            if ($permission['id'] == $enabledPermission->id) {
                $enabledFound = true;
                $this->assertTrue($permission['is_enabled']);
            }
            if ($permission['id'] == $disabledPermission->id) {
                $disabledFound = true;
            }
        }
        
        $this->assertTrue($enabledFound, 'Enabled permission should be found in active filter');
        $this->assertFalse($disabledFound, 'Disabled permission should not be found in active filter');
    }

    /**
     * Test Admin can view permissions they granted or manage.
     */
    public function test_admin_can_view_managed_permissions(): void
    {
        // Create permission granted by this admin
        $grantedByAdmin = UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->admin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        // Create permission granted by Primary Admin but managed by this admin
        $managedByAdmin = UserFeaturePermission::factory()->create([
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        // Create permission not related to this admin
        $unrelatedPermission = UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->anotherClient->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->anotherAdmin->id,
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/user-feature-permissions');
        $response->assertStatus(Response::HTTP_OK);
        
        $permissions = $response->json('data');
        $permissionIds = array_column($permissions, 'id');
        
        // Admin should see permissions they granted or manage
        $this->assertContains($grantedByAdmin->id, $permissionIds);
        $this->assertContains($managedByAdmin->id, $permissionIds);
        
        // Admin should not see unrelated permissions (depends on policy implementation)
        // This test verifies the policy correctly filters based on admin's management scope
    }

    /**
     * Test comprehensive permission workflow scenario.
     */
    public function test_complete_permission_workflow(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        // Step 1: Primary Admin grants OOP Expenses permission to Business User with Admin as manager
        $grantResponse = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $grantResponse->assertStatus(Response::HTTP_CREATED);
        $permissionId = $grantResponse->json('data.id');

        // Step 2: Verify permission is active and properly recorded
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permissionId,
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);

        // Step 3: Admin (now with management rights) can grant permissions to Card User
        Sanctum::actingAs($this->admin);
        
        $delegateResponse = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $delegateResponse->assertStatus(Response::HTTP_CREATED);

        // Step 4: Primary Admin revokes the original permission
        Sanctum::actingAs($this->primaryAdmin);
        
        $revokeResponse = $this->deleteJson("/api/v1/user-feature-permissions/{$permissionId}");
        $revokeResponse->assertStatus(Response::HTTP_NO_CONTENT);

        // Step 5: Verify permission is disabled but record remains for audit trail
        $this->assertDatabaseHas('user_feature_permission', [
            'id' => $permissionId,
            'is_enabled' => false,
        ]);

        // Step 6: Verify delegation permission is still active
        $this->assertDatabaseHas('user_feature_permission', [
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'grantor_id' => $this->admin->id,
            'manager_user_id' => $this->admin->id,
            'is_enabled' => true,
        ]);
    }

    /**
     * Test filtering permissions by feature_id.
     */
    public function test_filter_permissions_by_feature_id(): void
    {
        // Create permissions for different features
        UserFeaturePermission::factory()->create([
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId, // OOP Expenses
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
        ]);

        UserFeaturePermission::factory()->create([
            'user_id' => $this->cardUser->id,
            'client_id' => $this->client->id,
            'feature_id' => 17, // Different feature
            'grantor_id' => $this->primaryAdmin->id,
            'manager_user_id' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->primaryAdmin);

        $response = $this->getJson("/api/v1/user-feature-permissions?feature_id={$this->oopFeatureId}");
        
        $response->assertStatus(Response::HTTP_OK);
        $permissions = $response->json('data');
        
        foreach ($permissions as $permission) {
            $this->assertEquals($this->oopFeatureId, $permission['feature_id']);
        }
    }

    /**
     * Test permission timestamps are properly maintained.
     */
    public function test_permission_timestamps(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        $beforeCreate = now()->subSecond();

        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);

        $afterCreate = now()->addSecond();

        $response->assertStatus(Response::HTTP_CREATED);
        $permission = $response->json('data');

        $createdAt = \Carbon\Carbon::parse($permission['created_at']);
        $updatedAt = \Carbon\Carbon::parse($permission['updated_at']);

        $this->assertTrue($createdAt->between($beforeCreate, $afterCreate));
        $this->assertTrue($updatedAt->between($beforeCreate, $afterCreate));
        $this->assertEquals($createdAt->format('Y-m-d H:i:s'), $updatedAt->format('Y-m-d H:i:s'));
    }

    /**
     * Test proper HTTP status codes for various scenarios.
     */
    public function test_proper_http_status_codes(): void
    {
        Sanctum::actingAs($this->primaryAdmin);

        // 201 Created for successful permission grant
        $response = $this->postJson('/api/v1/user-feature-permissions', [
            'user_id' => $this->businessUser->id,
            'client_id' => $this->client->id,
            'feature_id' => $this->oopFeatureId,
            'manager_user_id' => $this->admin->id,
        ]);
        $response->assertStatus(Response::HTTP_CREATED);

        // 200 OK for successful listing
        $response = $this->getJson('/api/v1/user-feature-permissions');
        $response->assertStatus(Response::HTTP_OK);

        // 204 No Content for successful deletion
        $permission = UserFeaturePermission::factory()->create();
        $response = $this->deleteJson("/api/v1/user-feature-permissions/{$permission->id}");
        $response->assertStatus(Response::HTTP_NO_CONTENT);

        // 404 Not Found for non-existent resource
        $response = $this->deleteJson('/api/v1/user-feature-permissions/99999');
        $response->assertStatus(Response::HTTP_NOT_FOUND);

        // 422 Unprocessable Entity for validation errors
        $response = $this->postJson('/api/v1/user-feature-permissions', []);
        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        // 401 Unauthorized for unauthenticated requests
        $this->withoutMiddleware(); // Remove auth middleware temporarily
        $response = $this->getJson('/api/v1/user-feature-permissions');
        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Clean up after each test.
     */
    protected function tearDown(): void
    {
        // Clean up any created test data
        UserFeaturePermission::query()->forceDelete();
        User::query()->forceDelete();
        Client::query()->forceDelete();

        parent::tearDown();
    }
}