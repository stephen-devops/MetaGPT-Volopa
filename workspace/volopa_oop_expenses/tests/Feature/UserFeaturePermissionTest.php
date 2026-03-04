## Code: tests/Feature/UserFeaturePermissionTest.php

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Passport\Passport;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * UserFeaturePermissionTest
 * 
 * Feature tests for user feature permission management endpoints.
 * Tests delegation-based RBAC system with role hierarchy enforcement
 * and multi-tenant data isolation.
 * 
 * Test Coverage:
 * - Permission listing with filters and pagination
 * - Permission granting with validation and authorization
 * - Permission revoking with business rule enforcement
 * - Role-based access control across all user roles
 * - Multi-tenant data isolation and security
 * - Error handling and validation scenarios
 */
class UserFeaturePermissionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Primary Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role identifier.
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Administrator';

    /**
     * Business User role identifier.
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role identifier.
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Test client instances.
     *
     * @var array<string, Client>
     */
    private array $clients = [];

    /**
     * Test user instances.
     *
     * @var array<string, User>
     */
    private array $users = [];

    /**
     * Test feature instances.
     *
     * @var array<string, Feature>
     */
    private array $features = [];

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create test clients
        $this->clients['client_a'] = Client::factory()->create([
            'name' => 'Test Client A',
            'code' => 'CLIENT_A',
            'is_active' => true,
        ]);

        $this->clients['client_b'] = Client::factory()->create([
            'name' => 'Test Client B',
            'code' => 'CLIENT_B',
            'is_active' => true,
        ]);

        // Create test features
        $this->features['pocket_expense'] = Feature::factory()->create([
            'name' => 'Pocket Expense Management',
            'code' => 'pocket_expense',
            'description' => 'Manage pocket expenses',
            'is_active' => true,
        ]);

        $this->features['user_management'] = Feature::factory()->create([
            'name' => 'User Management',
            'code' => 'user_management',
            'description' => 'Manage users',
            'is_active' => true,
        ]);

        // Create test users for Client A
        $this->users['primary_admin_a'] = User::factory()->create([
            'name' => 'Primary Admin A',
            'email' => 'primary.admin.a@test.com',
            'role' => self::ROLE_PRIMARY_ADMIN,
            'client_id' => $this->clients['client_a']->id,
            'is_active' => true,
        ]);

        $this->users['admin_a'] = User::factory()->create([
            'name' => 'Admin A',
            'email' => 'admin.a@test.com',
            'role' => self::ROLE_ADMIN,
            'client_id' => $this->clients['client_a']->id,
            'is_active' => true,
        ]);

        $this->users['business_user_a'] = User::factory()->create([
            'name' => 'Business User A',
            'email' => 'business.user.a@test.com',
            'role' => self::ROLE_BUSINESS_USER,
            'client_id' => $this->clients['client_a']->id,
            'is_active' => true,
        ]);

        $this->users['card_user_a'] = User::factory()->create([
            'name' => 'Card User A',
            'email' => 'card.user.a@test.com',
            'role' => self::ROLE_CARD_USER,
            'client_id' => $this->clients['client_a']->id,
            'is_active' => true,
        ]);

        // Create test users for Client B
        $this->users['primary_admin_b'] = User::factory()->create([
            'name' => 'Primary Admin B',
            'email' => 'primary.admin.b@test.com',
            'role' => self::ROLE_PRIMARY_ADMIN,
            'client_id' => $this->clients['client_b']->id,
            'is_active' => true,
        ]);

        $this->users['admin_b'] = User::factory()->create([
            'name' => 'Admin B',
            'email' => 'admin.b@test.com',
            'role' => self::ROLE_ADMIN,
            'client_id' => $this->clients['client_b']->id,
            'is_active' => true,
        ]);
    }

    /**
     * Test that Primary Administrator can list all permissions within client.
     *
     * @return void
     */
    public function test_primary_administrator_can_list_all_permissions(): void
    {
        // Create test permissions
        $permission1 = UserFeaturePermission::factory()->create([
            'user_id' => $this->users['admin_a']->id,
            'client_id' => $this->clients['client_a']->id,
            'feature_id' => $this->features['pocket_expense']->id,
            'grantor_id' => $this->users['primary_admin_a']->id,
            'manager_user_id' => $this->users['primary_admin_a']->id,
            'is_enabled' => true,
        ]);

        $permission2 = UserFeaturePermission::factory()->create([
            'user_id' => $this->users['business_user_a']->id,
            'client_id' => $this->clients['client_a']->id,
            'feature_id' => $this->features['user_management']->id,
            'grantor_id' => $this->users['primary_admin_a']->id,
            'manager_user_id' => $this->users['admin_a']->id,
            'is_enabled' => true,
        ]);

        // Authenticate as Primary Administrator
        Passport::actingAs($this->users['primary_admin_a']);

        // Make request
        $response = $this->getJson('/api/v1/user-feature-permissions');

        // Assert response
        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'User feature permissions retrieved successfully'
                ])
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'data' => [
                            '*' => [
                                'id',
                                'user_id',
                                'client_id',
                                'feature_id',
                                'grantor_id',
                                'manager_user_id',
                                'is_enabled',
                                'status',
                                'created_at',
                                'updated_at',
                                'user' => [
                                    'id',
                                    'name',
                                    'email',
                