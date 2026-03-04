## Code: tests/Feature/PocketExpenseTest.php

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Passport\Passport;
use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\PocketExpenseMetadata;
use App\Models\TransactionCategory;
use App\Models\TrackingCode;
use App\Models\Project;
use App\Models\FileStore;
use App\Models\AdditionalField;
use App\Models\Currency;
use App\Models\Wallet;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use Mockery;

/**
 * PocketExpenseTest
 * 
 * Feature tests for pocket expense operations endpoints.
 * Tests expense CRUD operations, FX conversion, approval workflow,
 * metadata management, and multi-tenant data isolation.
 * 
 * Test Coverage:
 * - Expense listing with filters and pagination
 * - Expense creation with metadata and FX conversion
 * - Expense updates with status-based restrictions
 * - Expense deletion with approval workflow constraints
 * - Expense approval with role-based authorization
 * - FX conversion real-time endpoint
 * - Multi-tenant data isolation and security
 * - Error handling and validation scenarios
 * - Business rule enforcement
 */
class PocketExpenseTest extends TestCase
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
     * Test expense type instances.
     *
     * @var array<string, OptPocketExpenseType>
     */
    private array $expenseTypes = [];

    /**
     * Test currency instances.
     *
     * @var array<string, Currency>
     */
    private array $currencies = [];

    /**
     * Test expense source instances.
     *
     * @var array<string, PocketExpenseSourceClientConfig>
     */
    private array $expenseSources = [];

    /**
     * Test category instances.
     *
     * @var array<string, TransactionCategory>
     */
    private array $categories = [];

    /**
     * Test tracking code instances.
     *
     * @var array<string, TrackingCode>
     */
    private array $trackingCodes = [];

    /**
     * Test project instances.
     *
     * @var array<string, Project>
     */
    private array $projects = [];

    /**
     * Mock FX conversion service.
     *
     * @var \Mockery\MockInterface
     */
    private $mockFxService;

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

        // Create test currencies
        $this->currencies['usd'] = Currency::factory()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'is_active' => true,
        ]);

        $this->currencies['eur'] = Currency::factory()->create([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'is_active' => true,
        ]);

        $this->currencies['gbp'] = Currency::factory()->create([
            'code' => 'GBP',
            'name' => 'British Pound',
            'symbol' => '£',
            'is_active' => true,
        ]);

        // Create wallets for clients with base currencies
        Wallet::factory()->create([
            'client_id' => $this->clients['client_a']->id,
            'currency_id' => $this->currencies['usd']->id,
            'is_primary' => true,
            'balance' => 10000.00,
        ]);

        Wallet::factory()->create([
            'client_id' => $this->clients['client_b']->id,
            'currency_id' => $this->currencies['eur']->id,
            'is_primary' => true,
            'balance' => 8000.00,
        ]);

        // Create test expense types
        $this->expenseTypes['general'] = OptPocketExpenseType::factory()->create([
            'option' => 'General Expense',
            'amount_sign' => 'negative',
        ]);

        $this->expenseTypes['travel'] = OptPocketExpenseType::factory()->create([
            'option' => 'Travel Expense',
            'amount_sign' => 'negative',
        ]);

        $this->expenseTypes['refund'] = OptPocketExpenseType::factory()->create([
            'option' => 'Refund',
            'amount_sign' => 'positive',
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