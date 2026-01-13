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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Exception;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class MassPaymentFileController extends Controller
{
    /**
     * Mass payment file service instance
     */
    protected MassPaymentFileService $massPaymentFileService;

    /**
     * Items per page for pagination
     */
    protected int $perPage;

    /**
     * Maximum items per page allowed
     */
    protected int $maxPerPage;

    /**
     * Constructor
     *
     * @param MassPaymentFileService $massPaymentFileService
     */
    public function __construct(MassPaymentFileService $massPaymentFileService)
    {
        $this->massPaymentFileService = $massPaymentFileService;
        $this->perPage = config('mass-payments.pagination.per_page', 20);
        $this->maxPerPage = config('mass-payments.pagination.max_per_page', 100);

        // Apply authentication middleware
        $this->middleware('auth:api');
        
        // Apply Volopa authentication middleware
        $this->middleware('volopa.auth');

        // Apply authorization policies
        $this->authorizeResource(MassPaymentFile::class, 'mass_payment_file');
    }

    /**
     * Display a listing of mass payment files.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            Log::info('Mass payment files index request', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'filters' => $request->except(['page', 'per_page']),
            ]);

            // Validate pagination parameters
            $perPage = min(
                (int) $request->get('per_page', $this->perPage),
                $this->maxPerPage
            );

            if ($perPage < 1) {
                $perPage = $this->perPage;
            }

            // Build query with filters and relationships
            $query = MassPaymentFile::query()
                ->with([
                    'client:id,name,code',
                    'tccAccount:id,account_name,currency,balance,available_balance,is_active',
                    'uploader:id,name,email',
                    'approver:id,name,email'
                ])
                ->withCount(['paymentInstructions'])
                ->orderBy('created_at', 'desc');

            // Apply status filter
            if ($request->filled('status')) {
                $status = $request->get('status');
                if (in_array($status, MassPaymentFile::getStatuses())) {
                    $query->where('status', $status);
                }
            }

            // Apply multiple status filter
            if ($request->filled('statuses') && is_array($request->get('statuses'))) {
                $statuses = array_intersect($request->get('statuses'), MassPaymentFile::getStatuses());
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }

            // Apply currency filter
            if ($request->filled('currency')) {
                $currency = strtoupper($request->get('currency'));
                $supportedCurrencies = array_keys(config('mass-payments.supported_currencies', []));
                if (in_array($currency, $supportedCurrencies)) {
                    $query->where('currency', $currency);
                }
            }

            // Apply date range filters
            if ($request->filled('created_from')) {
                try {
                    $createdFrom = \Carbon\Carbon::parse($request->get('created_from'));
                    $query->whereDate('created_at', '>=', $createdFrom);
                } catch (Exception $e) {
                    Log::warning('Invalid created_from date format', [
                        'value' => $request->get('created_from'),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($request->filled('created_to')) {
                try {
                    $createdTo = \Carbon\Carbon::parse($request->get('created_to'));
                    $query->whereDate('created_at', '<=', $createdTo);
                } catch (Exception $e) {
                    Log::warning('Invalid created_to date format', [
                        'value' => $request->get('created_to'),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Apply amount range filters
            if ($request->filled('min_amount')) {
                $minAmount = (float) $request->get('min_amount');
                if ($minAmount >= 0) {
                    $query->where('total_amount', '>=', $minAmount);
                }
            }

            if ($request->filled('max_amount')) {
                $maxAmount = (float) $request->get('max_amount');
                if ($maxAmount >= 0) {
                    $query->where('total_amount', '<=', $maxAmount);
                }
            }

            // Apply uploader filter
            if ($request->filled('uploaded_by')) {
                $uploadedBy = (int) $request->get('uploaded_by');
                if ($uploadedBy > 0) {
                    $query->where('uploaded_by', $uploadedBy);
                }
            }

            // Apply TCC account filter
            if ($request->filled('tcc_account_id')) {
                $tccAccountId = (int) $request->get('tcc_account_id');
                if ($tccAccountId > 0) {
                    $query->where('tcc_account_id', $tccAccountId);
                }
            }

            // Apply search filter
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('original_filename', 'like', "%{$search}%")
                      ->orWhereHas('uploader', function ($userQuery) use ($search) {
                          $userQuery->where('name', 'like', "%{$search}%")
                                   ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');

            $allowedSortFields = [
                'created_at',
                'updated_at',
                'original_filename',
                'total_amount',
                'currency',
                'status',
                'approved_at'
            ];

            if (in_array($sortBy, $allowedSortFields)) {
                $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) 
                    ? strtolower($sortDirection) 
                    : 'desc';
                $query->orderBy($sortBy, $sortDirection);
            }

            // Paginate results
            $files = $query->paginate($perPage);

            Log::info('Mass payment files retrieved successfully', [
                'user_id' => Auth::id(),
                'total_files' => $files->total(),
                'current_page' => $files->currentPage(),
                'per_page' => $files->perPage(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment files retrieved successfully',
                'data' => MassPaymentFileResource::collection($files),
                'meta' => [
                    'pagination' => [
                        'current_page' => $files->currentPage(),
                        'last_page' => $files->lastPage(),
                        'per_page' => $files->perPage(),
                        'total' => $files->total(),
                        'from' => $files->firstItem(),
                        'to' => $files->lastItem(),
                        'has_more_pages' => $files->hasMorePages(),
                    ],
                    'filters_applied' => $this->getAppliedFilters($request),
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Failed to retrieve mass payment files', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve mass payment files',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly uploaded mass payment file.
     *
     * @param UploadMassPaymentFileRequest $request
     * @return JsonResponse
     */
    public function store(UploadMassPaymentFileRequest $request): JsonResponse
    {
        try {
            Log::info('Mass payment file upload started', [
                'user_id' => Auth::id(),
                'client_id' => Auth::user()->client_id ?? null,
                'original_filename' => $request->file('file')->getClientOriginalName(),
                'file_size' => $request->file('file')->getSize(),
                'tcc_account_id' => $request->get('tcc_account_id'),
            ]);

            // Get validated data
            $validatedData = $request->validated();

            // Upload and process the file
            $massPaymentFile = $this->massPaymentFileService->uploadFile(
                $request->file('file'),
                $validatedData['client_id'],
                $validatedData['tcc_account_id'],
                [
                    'currency' => $validatedData['currency'] ?? null,
                    'description' => $validatedData['description'] ?? null,
                    'notify_on_completion' => $validatedData['notify_on_completion'] ?? true,
                    'notify_on_failure' => $validatedData['notify_on_failure'] ?? true,
                ]
            );

            Log::info('Mass payment file uploaded successfully', [
                'file_id' => $massPaymentFile->id,
                'user_id' => Auth::id(),
                'original_filename' => $massPaymentFile->original_filename,
                'status' => $massPaymentFile->status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment file uploaded successfully',
                'data' => new MassPaymentFileResource($massPaymentFile->load([
                    'client:id,name,code',
                    'tccAccount:id,account_name,currency,balance,available_balance',
                    'uploader:id,name,email'
                ])),
            ], Response::HTTP_CREATED);

        } catch (ValidationException $e) {
            Log::warning('Mass payment file upload validation failed', [
                'user_id' => Auth::id(),
                'validation_errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (Exception $e) {
            Log::error('Mass payment file upload failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload mass payment file',
                'error' => config('app.debug') ? $e->getMessage() : 'Upload failed. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified mass payment file.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            Log::info('Mass payment file show request', [
                'file_id' => $id,
                'user_id' => Auth::id(),
            ]);

            // Find the mass payment file with relationships
            $massPaymentFile = MassPaymentFile::with([
                'client:id,name,code',
                'tccAccount:id,account_name,currency,balance,available_balance,is_active',
                'uploader:id,name,email',
                'approver:id,name,email',
                'paymentInstructions' => function ($query) {
                    $query->select([
                        'id', 'mass_payment_file_id', 'amount', 'currency', 
                        'status', 'row_number', 'created_at', 'updated_at'
                    ])->orderBy('row_number');
                }
            ])
            ->withCount([
                'paymentInstructions',
                'paymentInstructions as successful_payments_count' => function ($query) {
                    $query->where('status', 'completed');
                },
                'paymentInstructions as failed_payments_count' => function ($query) {
                    $query->where('status', 'failed');
                }
            ])
            ->findOrFail($id);

            // Authorization is handled by the authorizeResource middleware
            Log::info('Mass payment file retrieved successfully', [
                'file_id' => $id,
                'user_id' => Auth::id(),
                'status' => $massPaymentFile->status,
                'total_amount' => $massPaymentFile->total_amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mass payment file retrieved successfully',
                'data' => new MassPaymentFileResource($massPaymentFile),
            ], Response::HTTP_OK);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Mass payment file not found', [
                'file_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Mass payment file not found',
            ], Response::HTTP_NOT_FOUND);

        } catch (Exception $e) {
            Log::error('Failed to retrieve mass payment file', [
                'file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve mass payment file',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Approve the specified mass payment file.
     *
     * @param ApproveMassPaymentFileRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function approve(ApproveMassPaymentFileRequest $request, string $id): JsonResponse
    {
        try {
            Log::info('Mass payment file approval started', [
                'file_id' => $id,
                'user_id' => Auth::id(),
                'approval_data' => $request->except(['confirm_approval']),