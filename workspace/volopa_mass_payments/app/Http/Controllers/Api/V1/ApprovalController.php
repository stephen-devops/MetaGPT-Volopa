## Code: app/Http/Controllers/Api/V1/ApprovalController.php

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovalRequest;
use App\Http\Resources\ApprovalResource;
use App\Models\Approval;
use App\Models\PaymentFile;
use App\Services\ApprovalWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use Symfony\Http\Response;

class ApprovalController extends Controller
{
    /**
     * Approval workflow service instance.
     */
    private ApprovalWorkflowService $approvalService;

    /**
     * Default pagination limit.
     */
    private const DEFAULT_PAGINATION_LIMIT = 15;

    /**
     * Maximum pagination limit.
     */
    private const MAX_PAGINATION_LIMIT = 100;

    /**
     * Create a new ApprovalController instance.
     *
     * @param ApprovalWorkflowService $approvalService
     */
    public function __construct(ApprovalWorkflowService $approvalService)
    {
        $this->approvalService = $approvalService;
        
        // Apply authentication middleware
        $this->middleware('auth:sanctum');
        
        // Apply throttling middleware
        $this->middleware('throttle:approvals,20')->only(['approve', 'reject']);
        $this->middleware('throttle:api,60')->except(['approve', 'reject']);
    }

    /**
     * Display a listing of pending approvals for the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        Log::info('Approval index request received', [
            'user_id' => Auth::id(),
            'query_params' => $request->query(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Validate query parameters
            $validated = $request->validate([
                'status' => 'sometimes|string|in:pending,approved,rejected',
                'priority' => 'sometimes|string|in:high,medium,low',
                'per_page' => 'sometimes|integer|min:1|max:' . self::MAX_PAGINATION_LIMIT,
                'sort_by' => 'sometimes|string|in:created_at,updated_at,approved_at',
                'sort_direction' => 'sometimes|string|in:asc,desc',
                'payment_file_id' => 'sometimes|integer|exists:payment_files,id',
                'include_expired' => 'sometimes|boolean',
            ]);

            // Build query with authorization
            $query = Approval::query()
                ->with([
                    'paymentFile' => function ($query) {
                        $query->with('user');
                    },
                    'approver'
                ])
                ->where('approver_id', $user->id);

            // Apply filters
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (isset($validated['payment_file_id'])) {
                $query->where('payment_file_id', $validated['payment_file_id']);
            }

            // Filter out expired approvals unless explicitly requested
            if (!($validated['include_expired'] ?? false)) {
                $query->where(function ($q) {
                    $q->where('status', '!=', Approval::STATUS_PENDING)
                      ->orWhere('created_at', '>', now()->subHours(72));
                });
            }

            // Apply priority filter (requires calculation)
            if (isset($validated['priority'])) {
                $priority = $validated['priority'];
                $query->whereHas('paymentFile', function ($q) use ($priority) {
                    switch ($priority) {
                        case 'high':
                            $q->where('total_amount', '>=', 50000.0);
                            break;
                        case 'medium':
                            $q->whereBetween('total_amount', [10000.0, 49999.99]);
                            break;
                        case 'low':
                            $q->where('total_amount', '<', 10000.0);
                            break;
                    }
                });
            }

            // Apply sorting
            $sortBy = $validated['sort_by'] ?? 'created_at';
            $sortDirection = $validated['sort_direction'] ?? 'asc';
            
            if ($sortBy === 'approved_at') {
                $query->orderBy('approved_at', $sortDirection);
            } else {
                $query->orderBy($sortBy, $sortDirection);
            }

            // Paginate results
            $perPage = min(
                $validated['per_page'] ?? self::DEFAULT_PAGINATION_LIMIT,
                self::MAX_PAGINATION_LIMIT
            );

            $approvals = $query->paginate($perPage);

            Log::info('Approval index completed', [
                'user_id' => $user->id,
                'total_approvals' => $approvals->total(),
                'current_page' => $approvals->currentPage(),
                'per_page' => $perPage,
            ]);

            return $this->paginatedResponse(
                ApprovalResource::collection($approvals),
                'Pending approvals retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Approval index failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve approvals',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Display the specified approval.
     *
     * @param int $id Approval ID
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        Log::info('Approval show request received', [
            'approval_id' => $id,
            'user_id' => Auth::id(),
        ]);

        try {
            // Find approval with relationships
            $approval = Approval::with([
                'paymentFile' => function ($query) {
                    $query->with(['user', 'validationErrors' => function ($q) {
                        $q->orderBy('row_number')->orderBy('field_name');
                    }]);
                },
                'approver'
            ])->find($id);

            if (!$approval) {
                return $this->errorResponse('Approval not found', Response::HTTP_NOT_FOUND);
            }

            // Check authorization
            $user = Auth::user();
            if (!$user || !$user->can('view', $approval)) {
                return $this->errorResponse('Access denied', Response::HTTP_FORBIDDEN);
            }

            Log::info('Approval retrieved successfully', [
                'approval_id' => $id,
                'user_id' => $user->id,
                'approval_status' => $approval->status,
                'payment_file_id' => $approval->payment_file_id,
            ]);

            return $this->successResponse(
                new ApprovalResource($approval),
                'Approval retrieved successfully'
            );

        } catch (Exception $e) {
            Log::error('Approval show failed', [
                'approval_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve approval',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Approve a payment file.
     *
     * @param ApprovalRequest $request
     * @param int $id Payment file ID
     * @return JsonResponse
     */
    public function approve(ApprovalRequest $request, int $id): JsonResponse
    {
        Log::info('Approval approve request received', [
            'payment_file_id' => $id,
            'user_id' => Auth::id(),
            'action' => $request->getAction(),
            'has_comments' => $request->hasComments(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Find payment file
            $paymentFile = PaymentFile::find($id);
            
            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            // Find pending approval for this user and payment file
            $approval = Approval::where('payment_file_id', $id)
                ->where('approver_id', $user->id)
                ->where('status', Approval::STATUS_PENDING)
                ->first();

            if (!$approval) {
                return $this->errorResponse(
                    'No pending approval found for this payment file',
                    Response::HTTP_NOT_FOUND
                );
            }

            // Process the approval using service
            $success = DB::transaction(function () use ($approval, $request, $user) {
                return $this->approvalService->processApproval(
                    $approval,
                    $request->getAction(),
                    $user
                );
            });

            if (!$success) {
                return $this->errorResponse(
                    'Failed to process approval',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Update approval comments if provided
            if ($request->hasComments()) {
                $approval->update(['comments' => $request->getComments()]);
            }

            // Refresh approval to get updated data
            $approval->refresh();
            $approval->load(['paymentFile.user', 'approver']);

            Log::info('Payment file approved successfully', [
                'approval_id' => $approval->id,
                'payment_file_id' => $id,
                'user_id' => $user->id,
                'action' => $request->getAction(),
            ]);

            return $this->successResponse(
                new ApprovalResource($approval),
                'Payment file approved successfully'
            );

        } catch (Exception $e) {
            Log::error('Approval approve failed', [
                'payment_file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to approve payment file: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Reject a payment file.
     *
     * @param ApprovalRequest $request
     * @param int $id Payment file ID
     * @return JsonResponse
     */
    public function reject(ApprovalRequest $request, int $id): JsonResponse
    {
        Log::info('Approval reject request received', [
            'payment_file_id' => $id,
            'user_id' => Auth::id(),
            'action' => $request->getAction(),
            'has_comments' => $request->hasComments(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Find payment file
            $paymentFile = PaymentFile::find($id);
            
            if (!$paymentFile) {
                return $this->errorResponse('Payment file not found', Response::HTTP_NOT_FOUND);
            }

            // Find pending approval for this user and payment file
            $approval = Approval::where('payment_file_id', $id)
                ->where('approver_id', $user->id)
                ->where('status', Approval::STATUS_PENDING)
                ->first();

            if (!$approval) {
                return $this->errorResponse(
                    'No pending approval found for this payment file',
                    Response::HTTP_NOT_FOUND
                );
            }

            // Process the rejection using service
            $success = DB::transaction(function () use ($approval, $request, $user) {
                return $this->approvalService->processApproval(
                    $approval,
                    $request->getAction(),
                    $user
                );
            });

            if (!$success) {
                return $this->errorResponse(
                    'Failed to process rejection',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            // Update approval comments (required for rejection)
            if ($request->hasComments()) {
                $approval->update(['comments' => $request->getComments()]);
            }

            // Refresh approval to get updated data
            $approval->refresh();
            $approval->load(['paymentFile.user', 'approver']);

            Log::info('Payment file rejected successfully', [
                'approval_id' => $approval->id,
                'payment_file_id' => $id,
                'user_id' => $user->id,
                'action' => $request->getAction(),
                'comments' => $request->getComments(),
            ]);

            return $this->successResponse(
                new ApprovalResource($approval),
                'Payment file rejected successfully'
            );

        } catch (Exception $e) {
            Log::error('Approval reject failed', [
                'payment_file_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to reject payment file: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get approval statistics for the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        Log::info('Approval statistics request received', [
            'user_id' => Auth::id(),
        ]);

        try {
            $user = Auth::user();
            
            if (!$user) {
                return $this->errorResponse('Authentication required', Response::HTTP_UNAUTHORIZED);
            }

            // Get approval statistics
            $statistics = [
                'total_approvals' => Approval::where('approver_id', $user->id)->count(),
                'pending_approvals' => Approval::where('approver_id', $user->id)
                    ->where('status', Approval::STATUS_PENDING)
                    ->count(),
                'approved_count' => Approval::where('approver_id', $user->id)
                    ->where('status', Approval::STATUS_APPROVED)
                    ->count(),
                'rejected_count' => Approval::where('approver_id', $user->id)
                    ->where('status', Approval::STATUS_REJECTED)
                    ->count(),
                'expired_approvals' => Approval::where('approver_id', $user->id)
                    ->where('status', Approval::STATUS_PENDING)
                    ->where('created_at', '<', now()->subHours(72))
                    ->count(),
            ];

            // Calculate approval rates
            $totalProcessed = $statistics['approved_count'] + $statistics['rejected_count'];
            $statistics['approval_rate'] = $totalProcessed > 0 ? 
                round(($statistics['approved_count'] / $totalProcessed) * 100, 2) : 0;

            // Get recent activity (last 30 days)
            $recentApprovals = Approval::where('approver_id', $user->id)
                ->where('approved_at', '>=', now()->subDays(30))
                ->selectRaw('DATE(approved_at) as date, COUNT(*) as count, status')
                ->groupBy('date', 'status')
                ->orderBy('date', 'desc')
                ->get();

            $statistics['recent_activity'] = $recentApprovals->groupBy('date')->map(function ($dayAp