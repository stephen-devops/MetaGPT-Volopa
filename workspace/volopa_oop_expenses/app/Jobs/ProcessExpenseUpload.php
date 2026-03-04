## Code: app/Jobs/ProcessExpenseUpload.php

```php
<?php

namespace App\Jobs;

use App\Models\PocketExpenseFileUpload;
use App\Models\PocketExpenseUploadsData;
use App\Models\PocketExpense;
use App\Models\PocketExpenseMetadata;
use App\Models\OptPocketExpenseType;
use App\Models\PocketExpenseSourceClientConfig;
use App\Models\TransactionCategory;
use App\Models\TrackingCode;
use App\Models\Project;
use App\Models\User;
use App\Services\PocketExpenseService;
use App\Services\FXConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use Throwable;

/**
 * ProcessExpenseUpload Job
 * 
 * Background queue job for processing CSV upload data and synchronizing expenses.
 * Handles batch processing with error recovery, status updates, and user notifications
 * following the upload lifecycle: processing -> completed/failed.
 * 
 * Business Rules:
 * - Queue job processing: sync expenses in batches of 100 records
 * - Upload status progression: uploaded → validation_passed → processing → completed/failed
 * - All expense metadata stored in pocket_expense_metadata with enum metadata_type
 * - Background job must update upload status and notify user on completion
 * - Amount sign determined by expense type: Refund = positive, others = negative
 * - Backend must recalculate FX on save, do not trust frontend-only values
 * - Date format conversion from DD/MM/YYYY to Y-m-d for database storage
 * - Trim notes field and prevent SQL injection, respect database limits
 * - All expenses must be scoped to client_id for multi-tenancy
 * - Commission formula: AdjustedRate = BaseRate × (1 - Commission%)
 * - Atomic operations for batch processing with transaction rollback on failure
 */
class ProcessExpenseUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The upload ID to process.
     *
     * @var int
     */
    public int $uploadId;

    /**
     * Batch size for processing upload data records.
     *
     * @var int
     */
    private const BATCH_SIZE = 100;

    /**
     * Maximum number of retry attempts.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Timeout for job execution in seconds.
     *
     * @var int
     */
    public int $timeout = 1800; // 30 minutes

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    private const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Valid metadata types for expense metadata.
     *
     * @var array<int, string>
     */
    private const VALID_METADATA_TYPES = [
        'category',
        'tracking_code',
        'project',
        'receipt',
        'source',
        'additional_field'
    ];

    /**
     * CSV date format for parsing.
     *
     * @var string
     */
    private const CSV_DATE_FORMAT = 'd/m/Y';

    /**
     * Database date format for storage.
     *
     * @var string
     */
    private const DB_DATE_FORMAT = 'Y-m-d';

    /**
     * Global 'Other' source name.
     *
     * @var string
     */
    private const GLOBAL_OTHER_SOURCE = 'Other';

    /**
     * Default status for new expenses.
     *
     * @var string
     */
    private const DEFAULT_EXPENSE_STATUS = 'draft';

    /**
     * Cache for reference data during processing.
     *
     * @var array<string, mixed>
     */
    private array $referenceDataCache = [];

    /**
     * Pocket expense service instance.
     *
     * @var PocketExpenseService|null
     */
    private ?PocketExpenseService $expenseService = null;

    /**
     * FX conversion service instance.
     *
     * @var FXConversionService|null
     */
    private ?FXConversionService $fxService = null;

    /**
     * Create a new job instance.
     *
     * @param int $uploadId The upload ID to process
     */
    public function __construct(int $uploadId)
    {
        $this->uploadId = $uploadId;
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info('Starting expense upload processing', [
                'upload_id' => $this->uploadId,
                'job_id' => $this->job->getJobId() ?? 'unknown'
            ]);

            // Load upload record
            $upload = PocketExpenseFileUpload::find($this->uploadId);
            if (!$upload) {
                Log::error('Upload record not found', ['upload_id' => $this->uploadId]);
                $this->fail(new Exception('Upload record not found'));
                return;
            }

            // Validate upload status
            if ($upload->status !== 'validation_passed') {
                Log::warning('Upload not in validation_passed status', [
                    'upload_id' => $this->uploadId,
                    'current_status' => $upload->status
                ]);
                return;
            }

            // Initialize services
            $this->initializeServices();

            // Update status to processing
            $this->updateUploadStatus('processing');

            // Preload reference data for performance
            $this->preloadReferenceData($upload->client_id);

            // Process expenses in batches
            $this->processExpensesBatched($upload);

            // Update final status
            $this->updateUploadStatus('completed', ['processed_at' => now()]);

            // Notify user of completion
            $this->notifyUser($upload->user_id, 'Your expense upload has been completed successfully.');

            Log::info('Expense upload processing completed successfully', [
                'upload_id' => $this->uploadId,
                'total_records' => $upload->total_records,
                'valid_records' => $upload->valid_records
            ]);

        } catch (Throwable $e) {
            Log::error('Expense upload processing failed', [
                'upload_id' => $this->uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->updateUploadStatus('sync_failed');
            $this->notifyUser($upload->user_id ?? null, 'Your expense upload failed to process. Please contact support.');
            
            throw $e;
        }
    }

    /**
     * Initialize service dependencies.
     *
     * @return void
     */
    private function initializeServices(): void
    {
        $this->fxService = app(FXConversionService::class);
        $this->expenseService = app(PocketExpenseService::class);
    }

    /**
     * Process expenses in batches to avoid memory issues.
     *
     * @param PocketExpenseFileUpload $upload
     * @return void
     * 
     * @throws Exception If batch processing fails
     */
    private function processExpensesBatched(PocketExpenseFileUpload $upload): void
    {
        $totalProcessed = 0;
        $totalFailed = 0;

        // Process data in batches
        PocketExpenseUplo