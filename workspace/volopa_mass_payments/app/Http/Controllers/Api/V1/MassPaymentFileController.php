## Code: app/Http/Controllers/Api/V1/MassPaymentFileController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMassPaymentFileRequest;
use App\Http\Requests\ApproveMassPaymentFileRequest;
use App\Http\Resources\MassPaymentFileResource;
use App\Models\MassPaymentFile;
use App\Services\MassPaymentFileService;
use App\Policies\MassPaymentFilePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Exception;

class MassPaymentFileController extends Controller
{
    /**
     * The mass payment file service instance.
     */
    private MassPaymentFileService $massPaymentFileService;

    /**
     * Default pagination size.
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Maximum pagination size.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Cache TTL for mass payment file lists in seconds.
     */
    private const CACHE_TTL = 300;

    /**
     * Supported status filters.
     */
    private const SUPPORTED_STATUSES = [
        'uploading',
        'processing',
        'validation_completed',
        'validation_failed',
        'pending_approval',
        'approved',
        'processing_payments',
        'completed',
        'cancelled',
        'failed'
    ];

    /**
     * Supported currencies for filtering.
     */
    private const SUPPORTED_CURRENCIES = [
        'USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'CAD', 'JPY',
        'CNY', 'THB', 'MYR', 'IDR', 'PHP', 'VND'
    ];

    /**
     * Create a new controller instance.
     *
     * @param MassPaymentFileService $massPaymentFileService
     */
    public function __construct(MassPaymentFileService $massPaymentFileService)
    {
        $this->massPaymentFileService = $massPaymentFileService;
        
        // Apply auth middleware to all methods
        $this->middleware('auth:api');
        
        // Apply throttle middleware for rate limiting
        $this->middleware('throttle:120,1');
        
        // Apply client scoping middleware
        $this->middleware('client.scope');
        
        // Apply permissions middleware
        $this->middleware('permission:mass_payments.view')->only(['index', 'show']);
        $this->middleware('permission:mass_payments.create')->only(['store']);
        $this->middleware('permission:mass_payments.approve')->only(['approve']);
        $this->middleware('permission:mass_payments.delete')->only(['destroy']);
    }

    /**
     * Display a listing of mass payment files.
     *
     * @param Request $request
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        Log::info('Mass payment files index requested', [
            'user_id' => $request->user()?->id,
            'client_id' => $request->user()?->client_id,
            'query_params' => $request->query()
        ]);

        try {
            // Authorize the request
            Gate::authorize('viewAny', MassPaymentFile::class);

            // Validate query parameters
            $validated = $this->validateIndexRequest($request);

            // Build query with filters
            $query = $this->buildIndexQuery($request, $validated);

            // Get pagination parameters
            $perPage = min((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
            $page = (int) ($validated['page'] ?? 1);

            // Create cache key for this query
            $cacheKey = $this->generateIndexCacheKey($request, $validated, $perPage, $page);

            // Get cached results or execute query
            $result = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($query, $perPage) {
                return $query->paginate($perPage);
            });

            Log::info('Mass payment files retrieved successfully', [
                'user_id' => $request->user()->id,
                'client_id' => $request->user()->client_id,
                'total_count' => $result->total(),
                'per_page' => $perPage,
                'current_page' => $result->currentPage()
            ]);

            return MassPaymentFileResource::collection($result)
                ->additional([
                    'meta' => [
                        'filters_applied' => $this->getAppliedFilters($validated),
                        'available_statuses' => self::SUPPORTED_STATUSES,
                        'available_currencies' => self::SUPPORTED_CURRENCIES,
                        'timestamp' => now()->toISOString()
                    ]
                ]);

        } catch (ValidationException $e) {
            Log::warning('Mass payment files index validation failed', [
                'user_id' => $request->user()?->id,
                'errors' => $e->errors()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Mass payment files index failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve mass payment files',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created mass payment file.
     *
     * @param UploadMassPaymentFileRequest $request
     * @return JsonResponse
     */
    public function store(UploadMassPaymentFileRequest $request): JsonResponse
    {
        Log::info('Creating new mass payment file', [
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'currency' => $request->input('currency'),
            'tcc_account_id' => $request->input('tcc_account_id'),
            'filename' => $request->file('file')?->getClientOriginalName()
        ]);

        try {
            $validated = $request->validatedWithComputed();
            $file = $request->file('file');

            // Create mass payment file using service
            $massPaymentFile = $this->massPaymentFileService->create($validated, $file);

            // Load relationships for response
            $massPaymentFile->load(['tccAccount']);

            // Clear related caches
            $this->clearRelatedCaches($request->user()->client_id);

            Log::info('Mass payment file created successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $request->user()->id,
                'client_id' => $request->user()->client_id,
                'status' => $massPaymentFile->status,
                'currency' => $massPaymentFile->currency
            ]);

            return (new MassPaymentFileResource($massPaymentFile))
                ->additional([
                    'meta' => [
                        'message' => 'Mass payment file uploaded successfully and is being processed',
                        'processing_status' => 'File is being validated in the background',
                        'timestamp' => now()->toISOString()
                    ]
                ])
                ->response()
                ->setStatusCode(201);

        } catch (Exception $e) {
            Log::error('Failed to create mass payment file', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload mass payment file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified mass payment file.
     *
     * @param Request $request
     * @param MassPaymentFile $massPaymentFile
     * @return JsonResponse
     */
    public function show(Request $request, MassPaymentFile $massPaymentFile): JsonResponse
    {
        Log::info('Mass payment file show requested', [
            'file_id' => $massPaymentFile->id,
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id
        ]);

        try {
            // Authorize the request
            Gate::authorize('view', $massPaymentFile);

            // Load relationships based on request
            $relationships = $this->determineRelationshipsToLoad($request);
            $massPaymentFile->load($relationships);

            // Get current processing status
            $processingStatus = $this->massPaymentFileService->getProcessingStatus($massPaymentFile->id);

            Log::info('Mass payment file retrieved successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $request->user()->id,
                'status' => $massPaymentFile->status,
                'total_amount' => $massPaymentFile->total_amount,
                'currency' => $massPaymentFile->currency
            ]);

            return (new MassPaymentFileResource($massPaymentFile))
                ->additional([
                    'meta' => [
                        'processing_status' => $processingStatus,
                        'timestamp' => now()->toISOString(),
                        'relationships_loaded' => $relationships
                    ]
                ])
                ->response();

        } catch (Exception $e) {
            Log::error('Failed to retrieve mass payment file', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve mass payment file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve the specified mass payment file.
     *
     * @param ApproveMassPaymentFileRequest $request
     * @param MassPaymentFile $massPaymentFile
     * @return JsonResponse
     */
    public function approve(ApproveMassPaymentFileRequest $request, MassPaymentFile $massPaymentFile): JsonResponse
    {
        Log::info('Mass payment file approval requested', [
            'file_id' => $massPaymentFile->id,
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'current_status' => $massPaymentFile->status,
            'total_amount' => $massPaymentFile->total_amount,
            'currency' => $massPaymentFile->currency
        ]);

        try {
            $validated = $request->validated();
            $user = $request->user();

            // Approve mass payment file using service
            $approved = $this->massPaymentFileService->approve($massPaymentFile, $user);

            if (!$approved) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to approve mass payment file',
                    'error' => 'Approval process could not be completed'
                ], 400);
            }

            // Refresh the model to get updated data
            $massPaymentFile->refresh();

            // Load relationships for response
            $massPaymentFile->load(['tccAccount', 'paymentInstructions']);

            // Clear related caches
            $this->clearRelatedCaches($user->client_id);

            Log::info('Mass payment file approved successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $user->id,
                'approved_by' => $massPaymentFile->approved_by,
                'approved_at' => $massPaymentFile->approved_at?->toISOString(),
                'new_status' => $massPaymentFile->status
            ]);

            return (new MassPaymentFileResource($massPaymentFile))
                ->additional([
                    'meta' => [
                        'message' => 'Mass payment file approved successfully and payment processing has begun',
                        'approval_notes' => $validated['approval_notes'] ?? null,
                        'approved_at' => $massPaymentFile->approved_at?->toISOString(),
                        'timestamp' => now()->toISOString()
                    ]
                ])
                ->response();

        } catch (Exception $e) {
            Log::error('Failed to approve mass payment file', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve mass payment file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified mass payment file.
     *
     * @param Request $request
     * @param MassPaymentFile $massPaymentFile
     * @return JsonResponse
     */
    public function destroy(Request $request, MassPaymentFile $massPaymentFile): JsonResponse
    {
        Log::info('Mass payment file deletion requested', [
            'file_id' => $massPaymentFile->id,
            'user_id' => $request->user()->id,
            'client_id' => $request->user()->client_id,
            'current_status' => $massPaymentFile->status
        ]);

        try {
            // Authorize the request
            Gate::authorize('delete', $massPaymentFile);

            $user = $request->user();

            // Delete mass payment file using service
            $deleted = $this->massPaymentFileService->delete($massPaymentFile);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete mass payment file',
                    'error' => 'Deletion process could not be completed'
                ], 400);
            }

            // Clear related caches
            $this->clearRelatedCaches($user->client_id);

            Log::info('Mass payment file deleted successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment file deleted successfully',
                'meta' => [
                    'deleted_at' => now()->toISOString(),
                    'timestamp' => now()->toISOString()
                ]
            ], 204);

        } catch (Exception $e) {
            Log::error('Failed to delete mass payment file', [
                'file_id' => $massPaymentFile->id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete mass payment file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate index request parameters.
     *
     * @param Request $request
     * @return array
     * @throws ValidationException
     */
    private function validateIndex