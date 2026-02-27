## Code: tests/Feature/UserFeaturePermissionTest.php

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use App\Models\UserFeaturePermission;
use App\Services\UserPermissionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserFeaturePermissionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * API base URL for user feature permissions.
     *
     * @var string
     */
    private const API_BASE_URL = '/api/v1/user-feature-permissions';

    /**
     * Test users for different roles.
     *
     * @var array<string, User>
     */
    private array $testUsers = [];

    /**
     * Test client.
     *
     * @var Client|null
     */
    private ?Client $testClient = null;

    /**
     * Test feature.
     *
     * @var Feature|null
     */
    private ?Feature $testFeature = null;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test client
        $this->testClient = Client::factory()->create([
            'name' => 'Test Client Corp',
            'code' => 'TESTCLIENT',
            'active' => true,
        ]);

        // Create test feature
        $this->testFeature = Feature::factory()->create([
            'id' => self::OOP_EXPENSES_FEATURE_ID,
            'name' => 'OOP Expenses',
            'code' => 'oop_expenses',
            'description' => 'Out-of-Pocket Expenses Management',
            'active' => true,
        ]);

        // Create test users with different roles
        $this->testUsers['primary_admin'] = User::factory()->create([
            'name' => 'Primary Admin User',
            'email' => 'primary.admin@test.com',
            'role' => 'primary_admin',
            'client_id' => $this->testClient->id,
            'active' => true,
        ]);

        $this->testUsers['admin'] = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'role' => 'admin',
            'client_id' => $this->testClient->id,
            'active' => true,
        ]);

        $this->testUsers['business_user'] = User::factory()->create([
            'name' => 'Business User',
            'email' => 'business.user@test.com',
            'role' => 'business_user',
            'client_id' => $this->testClient->id,
            'active' => true,
        ]);

        $this->testUsers['card_user'] = User::factory()->create([
            'name' => 'Card User',
            'email' => 'card.user@test.com',
            'role' => 'card_user',
            'client_id' => $this->testClient->id,
            'active' => true,
        ]);

        // Create additional test user for management scenarios
        $this->testUsers['target_user'] = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target.user@test.com',
            'role' => 'business_user',
            'client_id' => $this->testClient->id,
            'active' => true,
        ]);
    }

    /**
     * Test viewing permissions as primary admin.
     *
     * @return void
     */
    public function test_primary_admin_can_view_all_permissions(): void
    {
        // Create some test permissions
        $permission1 = UserFeaturePermission::factory()->create([
            'user_id' => $this->testUsers['business_user']->id,
            'client_id' => $this->testClient->id,
            'feature_id' => $this->testFeature->id,
            'grantor_id' => $this->testUsers['primary_admin']->id,
            'manager_user_id' => $this->testUsers['admin']->id,
            'is_enabled' => true,
        ]);

        $permission2 = UserFeaturePermission::factory()->create([
            'user_id' => $this->testUsers['card_user']->id,
            'client_id' => $this->testClient->id,
            'feature_id' => $this->testFeature->id,
            'grantor_id' => $this->testUsers['admin']->id,
            'manager_user_id' => $this->testUsers['admin']->id,
            'is_enabled' => false,
        ]);

        // Authenticate as primary admin
        $this->actingAs($this->testUsers['primary_admin'], 'api');

        // Make request to view permissions
        $response = $this->getJson(self::API_BASE_URL . '?client_id=' . $this->testClient->id);

        // Assert successful response
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'message',
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
                             'status',
                             'permission_type',
                         ]
                     ],
                     'pagination',
                 ]);

        // Assert both permissions are returned
        $response->assertJsonCount(2, 'data');

        // Assert specific permission data
        $responseData = $response->json('data');
        $this->assertContains($permission1->id, array_column($responseData, 'id'));
        $this->assertContains($permission2->id, array_column($responseData, 'id'));
    }

    /**
     * Test viewing permissions as admin - should see only managed permissions.
     *
     * @return void
     */
    public function test_admin_can_view_only_managed_permissions(): void
    {
        // Create permissions where admin is the grantor
        $adminGrantedPermission = UserFeaturePermission::factory()->create([
            'user_id' => $this->testUsers['business_user']->id,
            'client_id' => $this->testClient->id,
            'feature_id' => $this->testFeature->id,
            'grantor_id' => $this->testUsers['admin']->id,
            'manager_user_id' => $this->testUsers['admin']->id,
            'is_enabled' => true,
        ]);

        // Create permission where admin is not involved
        $otherPermission = UserFeaturePermission::factory()->create([
            'user_id' => $this->testUsers['card_user']->id,
            'client_id' => $this->testClient->id,
            'feature_id' => $this->testFeature->id,
            'grantor_id' => $this->testUsers['primary_admin']->id,
            'manager_user_id' => $this->testUsers['primary_admin']->id,
            'is_enabled' => true,
        ]);

        // Authenticate as admin
        $this->actingAs($this->testUsers['admin'], 'api');

        // Make request to view permissions
        $response = $this->getJson(self::API_BASE_URL . '?client_id=' . $this->testClient->id);

        // Assert successful response
        $