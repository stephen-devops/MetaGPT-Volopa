<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ApprovalResource;
use Carbon\Carbon;

class FileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->original_name,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplayName(),
            'currency' => $this->currency,
            
            // Statistics
            'statistics' => [
                'total_records' => $this->total_records,
                'valid_records' => $this->valid_records,
                'invalid_records' => $this->invalid_records,
                'success_rate' => $this->success_rate,
            ],
            
            // Financial information
            'financial' => [
                'total_amount' => $this->total_amount,
                'formatted_amount' => $this->getFormattedAmount(),
                'currency' => $this->currency,
            ],
            
            // Processing information
            'processing' => [
                'is_processing' => $this->isProcessing(),
                'is_completed' => $this->isCompleted(),
                'has_failed' => $this->hasFailed(),
                'requires_approval' => $this->requiresApproval(),
                'is_approved' => $this->isApproved(),
                'is_rejected' => $this->isRejected(),
            ],
            
            // Validation information
            'validation' => [
                'has_errors' => $this->validationErrors()->exists(),
                'error_count' => $this->validationErrors()->count(),
                'has_critical_errors' => $this->hasCriticalValidationErrors(),
            ],
            
            // User information
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user->name ?? 'Unknown User',
                'email' => $this->user->email ?? null,
            ],
            
            // Timestamps
            'timestamps' => [
                'uploaded_at' => $this->created_at?->toISOString(),
                'updated_at' => $this->updated_at?->toISOString(),
                'uploaded_at_human' => $this->created_at?->diffForHumans(),
                'updated_at_human' => $this->updated_at?->diffForHumans(),
            ],
            
            // Relationships (conditionally loaded)
            'payment_instructions' => PaymentResource::collection($this->whenLoaded('paymentInstructions')),
            'approvals' => ApprovalResource::collection($this->whenLoaded('approvals')),
            
            // Validation errors (conditionally loaded)
            'validation_errors' => $this->when(
                $this->relationLoaded('validationErrors'),
                function () {
                    return $this->validationErrors->map(function ($error) {
                        return [
                            'id' => $error->id,
                            'row_number' => $error->row_number,
                            'field_name' => $error->field_name,
                            'error_message' => $error->error_message,
                            'error_code' => $error->error_code,
                            'user_friendly_message' => $error->user_friendly_message,
                            'severity' => $error->severity,
                            'is_critical' => $error->isCritical(),
                        ];
                    });
                }
            ),
            
            // Actions available to current user
            'actions' => $this->getAvailableActions($request),
            
            // API links
            'links' => [
                'self' => route('api.v1.files.show', $this->id),
                'download' => $this->when(
                    $this->isCompleted(),
                    route('api.v1.files.download', $this->id)
                ),
                'delete' => route('api.v1.files.destroy', $this->id),
                'approve' => $this->when(
                    $this->requiresApproval(),
                    route('api.v1.approvals.approve', $this->id)
                ),
                'reject' => $this->when(
                    $this->requiresApproval(),
                    route('api.v1.approvals.reject', $this->id)
                ),
                'process' => $this->when(
                    $this->isApproved(),
                    route('api.v1.payments.process', $this->id)
                ),
            ],
            
            // Metadata for frontend
            'meta' => [
                'can_be_deleted' => $this->canBeDeleted($request),
                'can_be_processed' => $this->canBeProcessed($request),
                'can_be_approved' => $this->canBeApproved($request),
                'estimated_processing_time' => $this->getEstimatedProcessingTime(),
                'file_age_days' => $this->created_at?->diffInDays(Carbon::now()) ?? 0,
            ],
        ];
    }

    /**
     * Get display name for the current status.
     *
     * @return string
     */
    private function getStatusDisplayName(): string
    {
        $statusDisplayNames = [
            'uploaded' => 'Uploaded',
            'processing' => 'Processing',
            'validated' => 'Validated',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'ready_for_processing' => 'Ready for Processing',
            'processing_payments' => 'Processing Payments',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ];

        return $statusDisplayNames[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Get formatted amount with currency symbol.
     *
     * @return string
     */
    private function getFormattedAmount(): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
        ];

        $symbol = $symbols[$this->currency] ?? '';
        return $symbol . number_format($this->total_amount, 2);
    }

    /**
     * Check if the file has critical validation errors.
     *
     * @return bool
     */
    private function hasCriticalValidationErrors(): bool
    {
        if (!$this->relationLoaded('validationErrors')) {
            return false;
        }

        $criticalErrorCodes = [
            'REQUIRED_FIELD',
            'INVALID_CURRENCY',
            'INVALID_AMOUNT',
            'INVALID_ACCOUNT',
            'UNSUPPORTED_CURRENCY_SETTLEMENT',
        ];

        return $this->validationErrors->whereIn('error_code', $criticalErrorCodes)->isNotEmpty();
    }

    /**
     * Get available actions for the current user.
     *
     * @param Request $request
     * @return array<string, bool>
     */
    private function getAvailableActions(Request $request): array
    {
        $user = $request->user();
        
        return [
            'can_view' => $user ? $user->can('view', $this->resource) : false,
            'can_update' => $user ? $user->can('update', $this->resource) : false,
            'can_delete' => $user ? $user->can('delete', $this->resource) : false,
            'can_approve' => $user ? $user->can('approve', $this->resource) : false,
            'can_process' => $user ? $user->can('process', $this->resource) : false,
            'can_download' => $user ? $user->can('download', $this->resource) : false,
        ];
    }

    /**
     * Check if the file can be deleted by the current user.
     *
     * @param Request $request
     * @return bool
     */
    private function canBeDeleted(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }

        // Can only delete if not processing payments or completed
        $deletableStatuses = [
            'uploaded',
            'processing',
            'validated',
            'pending_approval',
            'rejected',
            'failed',
        ];

        return in_array($this->status, $deletableStatuses) && $user->can('delete', $this->resource);
    }

    /**
     * Check if the file can be processed.
     *
     * @param Request $request
     * @return bool
     */
    private function canBeProcessed(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }

        $processableStatuses = [
            'approved',
            'ready_for_processing',
        ];

        return in_array($this->status, $processableStatuses) && 
               $user->can('process', $this->resource) &&
               !$this->hasCriticalValidationErrors();
    }

    /**
     * Check if the file can be approved.
     *
     * @param Request $request
     * @return bool
     */
    private function canBeApproved(Request $request): bool
    {
        $user = $request->user();
        
        if (!$user) {
            return false;
        }

        $approvableStatuses = [
            'validated',
            'pending_approval',
        ];

        return in_array($this->status, $approvableStatuses) && 
               $user->can('approve', $this->resource) &&
               !$this->hasCriticalValidationErrors();
    }

    /**
     * Get estimated processing time in minutes.
     *
     * @return int
     */
    private function getEstimatedProcessingTime(): int
    {
        // Base time estimation: 1 minute per 100 records
        $baseTime = ceil($this->valid_records / 100);
        
        // Add time based on file size (1 minute per MB)
        $sizeTime = ceil($this->file_size / 1048576);
        
        // Minimum 1 minute, maximum 60 minutes
        return max(1, min(60, $baseTime + $sizeTime));
    }

    /**
     * Additional resource data for pagination metadata.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'api_version' => '1.0.0',
                'timestamp' => Carbon::now()->toISOString(),
                'request_id' => $request->header('X-Request-ID') ?? uniqid(),
            ],
        ];
    }

    /**
     * Customize the outgoing response for the resource.
     *
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Add custom headers
        $response->headers->set('X-Resource-Type', 'PaymentFile');
        $response->headers->set('X-Resource-Version', '1.0.0');
        
        // Add cache control based on file status
        if ($this->isCompleted() || $this->hasFailed()) {
            $response->headers->set('Cache-Control', 'public, max-age=3600'); // 1 hour
        } else {
            $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        }
    }

    /**
     * Get the resource key to be used in the response.
     *
     * @return string
     */
    public static function wrap($resource): string
    {
        return 'payment_file';
    }

    /**
     * Create a new resource collection.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'total_files' => $resource instanceof \Illuminate\Contracts\Pagination\Paginator 
                    ? $resource->total() 
                    : count($resource),
                'generated_at' => Carbon::now()->toISOString(),
            ],
        ]);
    }
}