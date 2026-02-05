## Code: tests/Unit/PocketExpenseCSVValidatorTest.php

```php
<?php

namespace Tests\Unit;

use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Services\PocketExpenseCSVValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Carbon\Carbon;
use Exception;

/**
 * PocketExpenseCSVValidatorTest
 * 
 * Unit tests for PocketExpenseCSVValidator service.
 * Tests CSV validation logic, error handling, reference data caching,
 * and all validation rules for pocket expense CSV uploads.
 */
class PocketExpenseCSVValidatorTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * The validator under test.
     *
     * @var PocketExpenseCSVValidator
     */
    private PocketExpenseCSVValidator $validator;

    /**
     * Test user ID.
     *
     * @var int
     */
    private int $targetUserId = 1;

    /**
     * Test client ID.
     *
     * @var int
     */
    private int $clientId = 1;

    /**
     * Test expense types.
     *
     * @var array<OptPocketExpenseType>
     */
    private array $expenseTypes = [];

    /**
     * Test expense sources.
     *
     * @var array<PocketExpenseSourceClientConfig>
     */
    private array $expenseSources = [];

    /**
     * Test CSV file path.
     *
     * @var string
     */
    private string $testCsvPath = '';

    /**
     * Storage disk for testing.
     *
     * @var string
     */
    private string $storageDisk = 'local';

    /**
     * Setup test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Setup fake storage
        Storage::fake($this->storageDisk);
        
        // Clear cache
        Cache::flush();

        // Create test data
        $this->createTestData();

        // Create validator instance
        $this->validator = new PocketExpenseCSVValidator($this->targetUserId, $this->clientId);
    }

    /**
     * Create test data for experiments.
     *
     * @return void
     */
    private function createTestData(): void
    {
        // Create expense types
        $this->expenseTypes = [
            OptPocketExpenseType::create([
                'option' => 'Point of Sale',
                'amount_sign' => '-',
            ]),
            OptPocketExpenseType::create([
                'option' => 'ATM Withdrawal',
                'amount_sign' => '-',
            ]),
            OptPocketExpenseType::create([
                'option' => 'Fee & Charges',
                'amount_sign' => '-',
            ]),
            OptPocketExpenseType::create([
                'option' => 'Refund from Merchant',
                'amount_sign' => '+',
            ]),
        ];

        // Create expense sources
        $this->expenseSources = [
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Cash',
                'is_default' => true,
                'deleted' => false,
            ]),
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Corporate Card',
                'is_default' => true,
                'deleted' => false,
            ]),
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Personal Card',
                'is_default' => true,
                'deleted' => false,
            ]),
            PocketExpenseSourceClientConfig::create([
                'client_id' => null,
                'name' => 'Other',
                'is_default' => false,
                'deleted' => false,
            ]),
            PocketExpenseSourceClientConfig::create([
                'client_id' => $this->clientId,
                'name' => 'Client Specific Card',
                'is_default' => false,
                'deleted' => false,
            ]),
        ];
    }

    /**
     * Create CSV file with given data.
     *
     * @param array<array<string, string>> $rows
     * @param array<string> $headers
     * @return string File path
     */
    private function createCSVFile(array $rows, array $headers = []): string
    {
        if (empty($headers)) {
            $headers = [
                'Date',
                'Expense Type',
                'Currency Code',
                'Amount',
                'Merchant Name',
                'Currency Equivalent Amount',
                'VAT %',
                'Description',
                'Merchant Address',
                'Merchant Country',
                'Source',
                'Source Note',
                'Notes',
            ];
        }

        $csvContent = $this->arrayToCSV($rows, $headers);
        $filename = 'test-csv-' . uniqid() . '.csv';
        $path = storage_path('app/' . $filename);
        
        file_put_contents($path, $csvContent);
        
        return $path;
    }

    /**
     * Convert array to CSV content.
     *
     * @param array<array<string, string>> $rows
     * @param array<string> $headers
     * @return string
     */
    private function arrayToCSV(array $rows, array $headers): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, $headers);
        
        // Write data rows
        foreach ($rows as $row) {
            $csvRow = [];
            foreach ($headers as $header) {
                $csvRow[] = $row[$header] ?? '';
            }
            fputcsv($output, $csvRow);
        }
        
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        return $csvContent;
    }

    /**
     * Get valid CSV row data.
     *
     * @return array<string, string>
     */
    private function getValidRowData(): array
    {
        return [
            'Date' => '15/12/2023',
            'Expense Type' => 'Point of Sale',
            'Currency Code' => 'USD',
            'Amount' => '100.50',
            'Merchant Name' => 'Test Merchant',
            'Currency Equivalent Amount' => '110.25',
            'VAT %' => '10.5',
            'Description' => 'Test purchase',
            'Merchant Address' => '123 Test Street',
            'Merchant Country' => 'US',
            'Source' => 'Corporate Card',
            'Source Note' => '',
            'Notes' => 'Test notes',
        ];
    }

    /**
     * Test validation with valid CSV file.
     */
    public function test_validate_with_valid_csv_returns_success(): void
    {
        $rows = [
            $this->getValidRowData(),
            array_merge($this->getValidRowData(), [
                'Date' => '16/12/2023',
                'Expense Type' => 'ATM Withdrawal',
                'Amount' => '50.00',
                'Merchant Name' => 'ATM Location',
            ]),
        ];

        $csvPath = $this->createCSVFile($rows);

        $result = $this->validator->validate($csvPath);

        $this->assertTrue($result->isValid);
        $this->assertEquals(2, $result->totalRows);
        $this->assertEquals(0, $result->errorCount);
        $this->assertEmpty($result->errors);
        $this->assertCount(2, $result->validatedRows);

        // Cleanup
        unlink($csvPath);
    }

    /**
     * Test validation with non-existent file.
     */
    public function test_validate_with_non_existent_file_returns_error(): void
    {
        $result = $this->validator->validate('/non/existent/file.csv');

        $this->assertFalse($result->isValid);
        $this->assertEquals(1, $result->errorCount);
        $this->assertContains('not accessible', $result->errors[0]['error']);
    }

    /**
     * Test validation with empty CSV file.
     */
    public function test_validate_with_empty_csv_returns_error(): void
    {
        $csvPath = $this->createCSVFile([]);

        $result = $this->validator->validate($csvPath);

        $this->assertFalse($result->isValid);
        $this->assertEquals(1, $result->errorCount);
        $this->assertContains('at least one data row', $result->errors[0]['error']);

        // Cleanup
        unlink($csvPath);
    }

    /**
     * Test validation with missing required columns.
     */
    public function test_validate_with_missing_required_columns_returns_error(): void
    {
        $headers = ['Date', 'Amount']; // Missing required columns
        $rows = [['Date' => '15/12/2023', 'Amount' => '100.50']];

        $csvPath = $this->createCSVFile($rows, $headers);

        $result = $this->validator->validate($csvPath);

        $this->assertFalse($result->isValid);
        $this->assertGreaterThan(0, $result->errorCount);
        $this->assertContains('Missing required columns', $result->errors[0]['error']);

        // Cleanup
        unlink($csvPath);
    }

    /**
     * Test validation with too many rows.
     */
    public function test_validate_with_too_many_rows_returns_error(): void
    {
        $rows = [];
        for ($i = 0; $i < 201; $i++) { // Exceeds 200 row limit
            $rows[] = array_merge($this->getValidRowData(), [
                'Date' => now()->subDays($i)->format('d/m/Y'),
                'Merchant Name' => "Merchant {$i}",
            ]);
        }

        $csvPath = $this->createCSVFile($rows);

        $result = $this->validator->validate($csvPath);

        $this->assertFalse($result->isValid);
        $this->assertEquals(1, $result->errorCount);
        $this->assertContains('201 rows', $result->errors[0]['error']);
        $this->assertContains('maximum allowed is 200', $result->errors[0]['error']);

        // Cleanup
        unlink($csvPath);
    }

    /**
     * Test individual row validation with valid data.
     */
    public function test_validate_row_with_valid_data_returns_no_errors(): void
    {
        $rowData = $this->getValidRowData();

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertEmpty($errors);
    }

    /**
     * Test date validation with invalid format.
     */
    public function test_validate_row_with_invalid_date_format_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Date' => '2023-13-45', // Invalid date
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $dateErrors = array_filter($errors, fn($e) => $e['field'] === 'Date');
        $this->assertNotEmpty($dateErrors);
        $this->assertContains('Invalid', reset($dateErrors)['error']);
    }

    /**
     * Test date validation with future date.
     */
    public function test_validate_row_with_future_date_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Date' => now()->addDays(1)->format('d/m/Y'), // Future date
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $dateErrors = array_filter($errors, fn($e) => $e['field'] === 'Date');
        $this->assertNotEmpty($dateErrors);
        $this->assertContains('future', reset($dateErrors)['error']);
    }

    /**
     * Test date validation with date too old.
     */
    public function test_validate_row_with_old_date_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Date' => now()->subYears(4)->format('d/m/Y'), // More than 3 years old
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $dateErrors = array_filter($errors, fn($e) => $e['field'] === 'Date');
        $this->assertNotEmpty($dateErrors);
        $this->assertContains('3 years', reset($dateErrors)['error']);
    }

    /**
     * Test expense type validation with invalid type.
     */
    public function test_validate_row_with_invalid_expense_type_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Expense Type' => 'Invalid Expense Type',
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $expenseTypeErrors = array_filter($errors, fn($e) => $e['field'] === 'Expense Type');
        $this->assertNotEmpty($expenseTypeErrors);
        $this->assertContains('Invalid', reset($expenseTypeErrors)['error']);
    }

    /**
     * Test currency validation with invalid currency code.
     */
    public function test_validate_row_with_invalid_currency_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Currency Code' => 'XXX', // Invalid currency
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $currencyErrors = array_filter($errors, fn($e) => $e['field'] === 'Currency Code');
        $this->assertNotEmpty($currencyErrors);
        $this->assertContains('supported', reset($currencyErrors)['error']);
    }

    /**
     * Test amount validation with invalid format.
     */
    public function test_validate_row_with_invalid_amount_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Amount' => 'not-a-number',
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $amountErrors = array_filter($errors, fn($e) => $e['field'] === 'Amount');
        $this->assertNotEmpty($amountErrors);
        $this->assertContains('numeric', reset($amountErrors)['error']);
    }

    /**
     * Test amount validation with negative amount.
     */
    public function test_validate_row_with_negative_amount_returns_error(): void
    {
        $rowData = array_merge($this->getValidRowData(), [
            'Amount' => '-50.00',
        ]);

        $errors = $this->validator->validateRow($rowData, 2);

        $this->assertNotEmpty($errors);
        $amountErrors = array_filter($errors, fn($e) => $e['field'] === 'Amount');
        $this->assertNotEmpty($amountErrors);
        $this->assertContains('positive', reset($amountErrors)['error']);
    }

    /**
     * Test VAT percentage validation with invalid percentage.
     