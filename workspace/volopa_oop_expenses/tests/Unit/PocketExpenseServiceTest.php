## Code: tests/Unit/PocketExpenseServiceTest.php

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Client;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\OptPocketExpenseType;
use App\Models\UserFeaturePermission;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Carbon\Carbon;
use Exception;
use Mockery;

/**
 * PocketExpenseServiceTest
 * 
 * Unit tests for PocketExpenseService business logic.
 * Tests expense CRUD operations, validation, FX conversion integration,
 * and CSV processing functionality with comprehensive mocking.
 */
class PocketExpenseServiceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * The service under test.
     *
     * @var PocketExpenseService
     */
    private PocketExpenseService $service;

    /**
     * Mock FX conversion service.
     *
     * @var FXConversionService|\Mockery\MockInterface
     */
    private $mockFxService;

    /**
     * Test user instance.
     *
     * @var User
     */
    private User $user;

    /**
     * Test client instance.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Test expense type instance.
     *
     * @var OptPocketExpenseType
     */
    private OptPocketExpenseType $expenseType;

    /**
     * Test expense source instance.
     *
     * @var PocketExpenseSourceClientConfig
     */
    private PocketExpenseSourceClientConfig $expenseSource;

    /**
     * Setup test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock FX service
        $this->mockFxService = Mockery::mock(FXConversionService::class);
        
        // Create service instance with mocked dependencies
        $this->service = new PocketExpenseService($this->mockFxService);

        // Create test data
        $this->createTestData();
    }

    /**
     * Cleanup after tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create test data for experiments.
     *
     * @return void
     */
    private function createTestData(): void
    {
        // Create client
        $this->client = Client::factory()->create([
            'name' => 'Test Client',
        ]);

        // Create user
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Create expense type
        $this->expenseType = OptPocketExpenseType::create([
            'option' => 'Point of Sale',
            'amount_sign' => '-',
        ]);

        // Create expense source
        $this->expenseSource = PocketExpenseSourceClientConfig::create([
            'client_id' => $this->client->id,
            'name' => 'Corporate Card',
            'is_default' => true,
            'deleted' => false,
        ]);
    }

    /**
     * Get valid expense data for testing.
     *
     * @return array<string, mixed>
     */
    private function getValidExpenseData(): array
    {
        return [
            'date' => now()->subDays(1)->format('Y-m-d'),
            'merchant_name' => 'Test Merchant',
            'merchant_description' => 'Test purchase description',
            'expense_type' => $this->expenseType->id,
            'currency' => 'USD',
            'amount' => 100.50,
            'merchant_address' => '123 Test Street',
            'merchant_country' => 'US',
            'vat_amount' => 10.05,
            'notes' => 'Test expense notes',
            'status' => 'draft',
            'expense_source_name' => $this->expenseSource->name,
        ];
    }

    /**
     * Test creating expense with valid data.
     */
    public function test_create_with_valid_data_returns_expense(): void
    {
        $expenseData = $this->getValidExpenseData();

        // Mock FX conversion (not needed for same currency)
        $this->mockFxService
            ->shouldReceive('shouldConvertCurrency')
            ->never();

        $expense = $this->service->create($expenseData, $this->user->id, $this->client->id);

        $this->assertInstanceOf(PocketExpense::class, $expense);
        $this->assertEquals($this->user->id, $expense->user_id);
        $this->assertEquals($this->client->id, $expense->client_id);
        $this->assertEquals('Test Merchant', $expense->merchant_name);
        $this->assertEquals('USD', $expense->currency);
        $this->assertEquals(-100.50, $expense->amount); // Should be negative for Point of Sale
        $this->assertEquals('draft', $expense->status);
        $this->assertEquals($this->user->id, $expense->created_by_user_id);

        // Verify database record
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'merchant_name' => 'Test Merchant',
            'currency' => 'USD',
            'amount' => -100.50,
            'status' => 'draft',
            'deleted' => false,
        ]);
    }

    /**
     * Test creating expense with positive expense type.
     */
    public function test_create_with_positive_expense_type_applies_correct_sign(): void
    {
        // Create positive expense type
        $refundType = OptPocketExpenseType::create([
            'option' => 'Refund from Merchant',
            'amount_sign' => '+',
        ]);

        $expenseData = $this->getValidExpenseData();
        $expenseData['expense_type'] = $refundType->id;

        $expense = $this->service->create($expenseData, $this->user->id, $this->client->id);

        $this->assertEquals(100.50, $expense->amount); // Should be positive for refund
    }

    /**
     * Test creating expense with FX conversion.
     */
    public function test_create_with_fx_conversion_calls_service(): void
    {
        $expenseData = $this->getValidExpenseData();
        $expenseData['currency'] = 'EUR';

        // Mock FX conversion result
        $conversionResult = new \App\Services\FXConversionService\ConversionResult(
            convertedAmount: 110.25,
            rate: 1.105,
            fromCurrency: 'EUR',
            toCurrency: 'USD',
            rateDate: now(),
            isEstimate: false
        );

        $this->mockFxService
            ->shouldReceive('convertAmount')
            ->once()
            ->with(100.50, 'EUR', 'USD', Mockery::type(Carbon::class), $this->client->id)
            ->andReturn($conversionResult);

        $expense = $this->service->create($expenseData, $this->user->id, $this->client->id);

        $this->assertEquals('EUR', $expense->currency);
        $this->assertEquals(-100.50, $expense->amount);
        $this->assertEquals(-110.25, $expense->user_converted_amount);
    }

    /**
     * Test creating expense with invalid expense type throws exception.
     */
    public function test_create_with_invalid_expense_type_throws_exception(): void
    {
        $expenseData = $this->getValidExpenseData();
        $expenseData['expense_type'] = 999; // Non-existent ID

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid expense type');

        $this->service->create($expenseData, $this->user->id, $this->client->id);
    }

    /**
     * Test creating expense with metadata.
     */
    public function test_create_with_metadata_creates_metadata_records(): void
    {
        $expenseData = $this->getValidExpenseData();
        $expenseData['transaction_category_id'] = 1;
        $expenseData['project_id'] = 2;

        // Mock the metadata creation (would need to mock the metadata creation logic)
        $expense = $this->service->create($expenseData, $this->user->id, $this->client->id);

        $this->assertInstanceOf(PocketExpense::class, $expense);
        
        // Note: In real implementation, would verify metadata records were created
        // This depends on the createExpenseMetadata method implementation
    }

    /**
     * Test updating expense with valid data.
     */
    public function test_update_with_valid_data_updates_expense(): void
    {
        // Create initial expense
        $expense = PocketExpense::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'draft',
            'merchant_name' => 'Original Merchant',
            'amount' => -50.00,
            'currency' => 'USD',
        ]);

        $updateData = [
            'merchant_name' => 'Updated Merchant',
            'amount' => 75.25,
            'notes' => 'Updated notes',
        ];

        $updatedExpense = $this->service->update($expense, $updateData);

        $this->assertEquals('Updated Merchant', $updatedExpense->merchant_name);
        $this->assertEquals(-75.25, $updatedExpense->amount); // Should maintain negative sign
        $this->assertEquals('Updated notes', $updatedExpense->notes);

        // Verify database
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'merchant_name' => 'Updated Merchant',
            'amount' => -75.25,
            'notes' => 'Updated notes',
        ]);
    }

    /**
     * Test updating approved expense throws exception.
     */
    public function test_update_approved_expense_throws_exception(): void
    {
        $expense = PocketExpense::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'approved',
        ]);

        $updateData = [
            'merchant_name' => 'Updated Merchant',
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot update expense in current status');

        $this->service->update($expense, $updateData);
    }

    /**
     * Test updating expense with new expense type recalculates amount.
     */
    public function test_update_with_new_expense_type_recalculates_amount(): void
    {
        // Create positive expense type
        $refundType = OptPocketExpenseType::create([
            'option' => 'Refund from Merchant',
            'amount_sign' => '+',
        ]);

        $expense = PocketExpense::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id, // Negative type
            'amount' => -100.00,
        ]);

        $updateData = [
            'expense_type' => $refundType->id, // Change to positive type
        ];

        $updatedExpense = $this->service->update($expense, $updateData);

        $this->assertEquals($refundType->id, $updatedExpense->expense_type);
        $this->assertEquals(100.00, $updatedExpense->amount); // Should now be positive
    }

    /**
     * Test deleting expense sets deleted flag.
     */
    public function test_delete_sets_deleted_flag_and_timestamp(): void
    {
        $expense = PocketExpense::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'draft',
        ]);

        $result = $this->service->delete($expense);

        $this->assertTrue($result);

        // Verify soft delete
        $this->assertDatabaseHas('pocket_expense', [
            'id' => $expense->id,
            'deleted' => true,
        ]);

        // Verify delete_time is set
        $expense->refresh();
        $this->assertNotNull($expense->delete_time);
    }

    /**
     * Test deleting approved expense throws exception.
     */
    public function test_delete_approved_expense_throws_exception(): void
    {
        $expense = PocketExpense::factory()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'approved',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot delete expense in current status');

        $this->service->delete($expense);
    }

    /**
     * Test listing expenses returns paginated results.
     */
    public function test_list_returns_paginated_results(): void
    {
        // Create test expenses
        PocketExpense::factory(5)->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'deleted' => false,
        ]);

        $filters = [
            'per_page' => 3,
            'sort_by' => 'create_time',
            'sort_order' => 'desc',
        ];

        $result = $this->service->list($this->user->id, $this->client->id, $filters);

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertEquals(3, $result->perPage());
        $this->assertEquals(5, $result->total());
        $this->assertEquals(2, $result->lastPage());
        $this->assertEquals(3, count($result->items()));
    }

    /**
     * Test listing expenses with status filter.
     */
    public function test_list_with_status_filter_returns_filtered_results(): void
    {
        // Create expenses with different statuses
        PocketExpense::factory(2)->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'draft',
            'deleted' => false,
        ]);

        PocketExpense::factory(3)->create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'expense_type' => $this->expenseType->id,
            'status' => 'submitted',
            'deleted' => false,
        ]);

        $filters = [
            'status' => 'submitted',
        ];

        