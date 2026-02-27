## Code: tests/Feature/PocketExpenseTest.php

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\PocketExpenseMetadata;
use App\Models\TransactionCategory;
use App\Models\TrackingCode;
use App\Models\ConfigurableProject;
use App\Models\UserFeaturePermission;
use App\Services\PocketExpenseService;
use App\Services\PocketExpenseFXService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PocketExpenseTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * API base URL for pocket expenses.
     *
     * @var string
     */
    private const API_BASE_URL = '/api/v1/pocket-expenses';

    /**
     * Valid currency codes for testing.
     *
     * @var array<string>
     */
    private const VALID_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CAD'];

    /**
     * Valid status values for pocket expenses.
     *
     * @var array<string>
     */
    private const VALID_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

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
     * Test expense types.
     *
     * @var array<OptPocketExpenseType>
     */
    private array $testExpenseTypes = [];

    /**
     * Test expense sources.
     *
     * @var array<PocketExpenseSourceClientConfig>
     */
    private array $testExpenseSources = [];

    /**
     * Test reference data.
     *
     * @var array<string, mixed>
     */
    private array $testReferenceData = [];

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
        $this->createTestUsers();

        // Create test expense types
        $this->createTestExpenseTypes();

        // Create test expense sources
        $this->createTestExpenseSources();

        // Create test reference data
        $this->createTestReferenceData();

        // Grant OOP expenses permission to test users
        $this->grantOopExpensesPermissions();
    }

    /**
     * Create test users with different roles.
     *
     * @return void
     */
    private function createTestUsers(): void
    {
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

        $this->testUsers['target_user'] = User::factory()->create([
            'name' => 'Target User',
            'email' => 'target.user@test.com',
            'role' => 'business_user',
            'client_id' => $this->testClient->id,
            'active' => true,
        ]);
    }

    /**
     * Create test expense types.
     *
     * @return void
     */
    private function createTestExpenseTypes(): void
    {
        $this->testExpenseTypes['business_expense'] = OptPocketExpenseType::create([
            'option' => 'Business Expense',
            'amount_sign' => 'negative',
        ]);

        $this->testExpenseTypes['travel_expense'] = OptPocketExpenseType::create([
            'option' => 'Travel Expense',
            'amount_sign' => 'negative',
        ]);

        $this->testExpenseTypes['meal_entertainment'] = OptPocketExpenseType::create([
            'option' => 'Meal & Entertainment',
            'amount_sign' => 'negative',
        ]);

        $this->testExpenseTypes['refund'] = OptPocketExpenseType::create([
            'option' => 'Refund',
            'amount_sign' => 'positive',
        ]);
    }

    /**
     * Create test expense sources.
     *
     * @return void
     */
    private function createTestExpenseSources(): void
    {
        // Global "Other" source
        $this->testExpenseSources['other'] = PocketExpenseSourceClientConfig::create([
            'uuid' => Str::uuid()->toString(),
            'client_id' => null,
            'name' => 'Other',
            'is_default' => false,
            'deleted' => false,
        ]);

        // Client-specific sources
        $this->testExpenseSources['cash'] = PocketExpenseSourceClientConfig::create([
            'uuid' => Str::uuid()->toString(),
            'client_id' => $this->testClient->id,
            'name' => 'Cash',
            'is_default' => true,
            'deleted' => false,
        ]);

        $this->testExpenseSources['corporate_card'] = PocketExpenseSourceClientConfig::create([
            'uuid' => Str::uuid()->toString(),
            'client_id' => $this->testClient->id,
            'name' => 'Corporate Card',
            'is_default' => true,
            'deleted' => false,
        ]);

        $this->testExpenseSources['personal_card'] = Pocket