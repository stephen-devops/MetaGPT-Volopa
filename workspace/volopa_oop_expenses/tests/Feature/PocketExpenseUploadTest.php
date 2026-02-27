## Code: tests/Feature/PocketExpenseUploadTest.php

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Client;
use App\Models\Feature;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadData;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\UserFeaturePermission;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PocketExpenseUploadTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * OOP Expenses feature ID for permission checks.
     *
     * @var int
     */
    private const OOP_EXPENSES_FEATURE_ID = 1;

    /**
     * API base URL for pocket expense uploads.
     *
     * @var string
     */
    private const API_BASE_URL = '/api/uploads/pocket-expense/csv';

    /**
     * Maximum file size in KB (10MB).
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum CSV rows allowed per file.
     *
     * @var int
     */
    private const MAX_CSV_ROWS = 200;

    /**
     * Valid CSV headers for testing.
     *
     * @var array<string>
     */
    private const VALID_CSV_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency Code',
        'Amount',
        'Merchant Address',
        'VAT %',
        'Source',
        'Source Note',
        'Notes'
    ];

    /**
     * Sample valid CSV data for testing.
     *
     * @var array<array<string>>
     */
    private const SAMPLE_CSV_DATA = [
        [
            '01-01-2024',
            'Test Restaurant',
            'Business lunch meeting',
            'Business Expense',
            'USD',
            '45.50',
            '123 Main St, New York, NY',
            '8.5',
            'Corporate Card',
            '',
            'Client meeting expenses'
        ],
        [
            '02-01-2024',
            'Office Store',
            'Monthly supplies',
            'Business Expense',
            'USD',
            '125.75',
            '456 Business Ave, New York, NY',
            '10',
            'Cash',
            '',
            'Office materials'
        ]
    ];

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
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up storage for testing
        Storage::fake('local');
        
        // Enable queue testing
        Queue::fake();

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

        // Grant OOP expenses permissions to test users
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