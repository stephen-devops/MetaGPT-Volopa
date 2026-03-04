## Code: tests/Feature/PocketExpenseUploadTest.php

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Passport\Passport;
use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\TransactionCategory;
use App\Models\TrackingCode;
use App\Models\Project;
use App\Models\Currency;
use App\Models\Wallet;
use App\Models\Feature;
use App\Models\UserFeaturePermission;
use App\Services\PocketExpenseCSVValidator;
use App\Jobs\ProcessExpenseUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Carbon\Carbon;
use Mockery;

/**
 * PocketExpenseUploadTest
 * 
 * Feature tests for CSV upload functionality and batch expense processing.
 * Tests file upload validation, CSV processing, background job execution,
 * and multi-tenant data isolation for expense batch operations.
 * 
 * Test Coverage:
 * - CSV file upload with validation and authorization
 * - File format validation and structure checking
 * - CSV content validation and error handling
 * - Background job processing and status updates
 * - Multi-tenant data isolation and security
 * - Upload status tracking and progress monitoring
 * - Error handling and validation scenarios
 * - Business rule enforcement for batch processing
 * - User notification and completion handling
 */
class PocketExpenseUploadTest extends TestCase
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
     * Maximum file size in KB for CSV uploads.
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240; // 10MB

    /**
     * Maximum number of rows allowed per CSV file.
     *
     * @var int
     */
    private const MAX_ROWS_PER_FILE = 200;

    /**
     * Required CSV header columns in exact order.
     *
     * @var array<int, string>
     */
    private const REQUIRED_HEADERS = [
        'Date',
        'Merchant Name',
        'Merchant Description',
        'Expense Type',
        'Currency',
        'Amount',
        'VAT Amount',
        'Merchant Address',
        'Notes',
        'Source',
        'Source Note',
        'Category',
        'Tracking Code',
        'Project',
    ];

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
     * Test feature instances.
     *
     * @var array<string, Feature>
     */
    private array $features = [];

    /**
     * Mock CSV validator service.
     *
     * @var \Mockery\MockInterface
     */
    private $mockCsvValidator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Disable actual queue processing for tests
        Queue::fake();

        // Create test storage disk
        Storage::fake('local');

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
        $this->expenseTypes['general'] = OptPocketExpenseType::create([
            'option' => 'General Expense',
            'amount_sign' => 'negative',
        ]);

        $this->expenseTypes['travel'] = OptPocketExpenseType::create([
            'option' => 'Travel Expense',
            'amount_sign' => 'negative',
        ]);

        $this->expenseTypes['refund'] = OptPocketExpenseType::create([
            'option' => 'Refund',
            'amount_sign' => 'positive',
        ]);

        // Create test features
        $this->features['pocket_expense'] = Feature::factory()->create([
            'name' => 'Pocket Expense Management',
            'code' => 'pocket_expense',
            'description' => 'Manage pocket expenses',
            'is_active' => true,
        ]);

        // Create test users for Client A
        $this->users['primary_admin_a'] = User::factory()->create([
            'name' => 'Primary Admin A',
            'email' => 'primary.admin.a@test.com',
            'role' => self::ROLE_PRIMARY_ADMIN,
            'client_id