<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class PocketExpenseUploadResource extends JsonResource
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
            'uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'created_by_user_id' => $this->created_by_user_id,
            'original_filename' => $this->original_filename,
            'stored_filename' => $this->stored_filename,
            'file_path' => $this->file_path,
            'file_size' => $this->file_size,
            'file_type' => $this->file_type,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'successful_rows' => $this->successful_rows,
            'failed_rows' => $this->failed_rows,
            'validation_errors' => $this->validation_errors,
            'processing_summary' => $this->processing_summary,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),

            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? '',
                    'email' => $this->user->email ?? '',
                ];
            }),

            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? '',
                    'is_active' => $this->client->is_active ?? false,
                ];
            }),

            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name ?? '',
                    'email' => $this->creator->email ?? '',
                ];
            }),

            'upload_data' => $this->whenLoaded('uploadData', function () {
                return $this->uploadData->map(function ($data) {
                    return [
                        'id' => $data->id,
                        'row_number' => $data->row_number,
                        'validation_status' => $data->validation_status,
                        'is_processed' => $data->is_processed,
                        'pocket_expense_id' => $data->pocket_expense_id,
                        'processing_error' => $data->processing_error,
                        'validation_errors' => $data->validation_errors,
                        'processed_at' => $data->processed_at?->toISOString(),
                    ];
                });
            }),

            // Computed fields
            'status_display' => $this->getStatusDisplayText(),
            'formatted_file_size' => $this->getFormattedFileSize(),
            'progress_percentage' => $this->getProgressPercentage(),
            'success_rate' => $this->getSuccessRate(),
            'failure_rate' => $this->getFailureRate(),
            'processing_duration' => $this->getFormattedProcessingDuration(),
            'estimated_time_remaining' => $this->getFormattedEstimatedTimeRemaining(),

            // Status flags
            'is_uploading' => $this->isUploading(),
            'is_validating' => $this->isValidating(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'is_failed' => $this->isFailed(),
            'is_cancelled' => $this->isCancelled(),
            'is_active' => $this->isActive(),
            'is_finished' => $this->isFinished(),

            // File information
            'file_info' => [
                'original_filename' => $this->original_filename,
                'file_size' => $this->file_size,
                'formatted_file_size' => $this->getFormattedFileSize(),
                'file_type' => $this->file_type,
                'is_csv' => $this->isCsv(),
                'is_excel' => $this->isExcel(),
                'upload_date' => $this->created_at?->toISOString(),
            ],

            // Processing statistics
            'processing_stats' => [
                'total_rows' => $this->total_rows,
                'processed_rows' => $this->processed_rows,
                'successful_rows' => $this->successful_rows,
                'failed_rows' => $this->failed_rows,
                'remaining_rows' => max(0, $this->total_rows - $this->processed_rows),
                'progress_percentage' => $this->getProgressPercentage(),
                'success_rate' => $this->getSuccessRate(),
                'failure_rate' => $this->getFailureRate(),
            ],

            // Time information
            'timing' => [
                'created_at' => $this->created_at?->toISOString(),
                'started_at' => $this->started_at?->toISOString(),
                'completed_at' => $this->completed_at?->toISOString(),
                'processing_duration_seconds' => $this->getProcessingDuration(),
                'processing_duration_formatted' => $this->getFormattedProcessingDuration(),
                'estimated_time_remaining_seconds' => $this->getEstimatedTimeRemaining(),
                'estimated_time_remaining_formatted' => $this->getFormattedEstimatedTimeRemaining(),
                'days_since_upload' => $this->getDaysSinceUpload(),
            ],

            // Error information
            'errors' => $this->when($this->hasValidationErrors(), function () {
                return [
                    'has_errors' => $this->hasValidationErrors(),
                    'error_count' => $this->getValidationErrorCount(),
                    'validation_errors' => $this->validation_errors,
                    'last_error' => $this->getLastError(),
                    'common_errors' => $this->getCommonErrors(),
                ];
            }),

            // Processing summary details
            'summary' => $this->when($this->hasProcessingSummary(), function () {
                return array_merge($this->processing_summary ?? [], [
                    'has_summary' => true,
                    'summary_generated_at' => $this->getProcessingSummaryTimestamp(),
                ]);
            }),

            // Upload configuration
            'configuration' => [
                'target_user_id' => $this->user_id,
                'client_id' => $this->client_id,
                'created_by_user_id' => $this->created_by_user_id,
                'file_type' => $this->file_type,
                'upload_method' => 'csv_upload',
                'processing_options' => $this->getProcessingOptions(),
                'validation_options' => $this->getValidationOptions(),
            ],

            // User permissions for current user
            'user_permissions' => [
                'can_view' => $this->userCanView($request),
                'can_cancel' => $this->userCanCancel($request),
                'can_retry' => $this->userCanRetry($request),
                'can_delete' => $this->userCanDelete($request),
                'can_download_errors' => $this->userCanDownloadErrors($request),
                'is_creator' => $this->isCreator($request),
                'is_target_user' => $this->isTargetUser($request),
            ],

            // Action capabilities
            'actions' => [
                'can_be_cancelled' => $this->canBeCancelled(),
                'can_be_retried' => $this->canBeRetried(),
                'can_be_deleted' => $this->canBeDeleted(),
                'can_download_errors' => $this->hasValidationErrors(),
                'can_view_details' => true,
                'can_reset_for_retry' => $this->canBeRetried(),
            ],

            // Statistics summary
            'statistics' => $this->getStatisticsSummary(),
        ];
    }

    /**
     * Get the display text for the upload status.
     *
     * @return string
     */
    private function getStatusDisplayText(): string
    {
        return match ($this->status) {
            'uploading' => 'Uploading',
            'validating' => 'Validating',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status ?? 'Unknown'),
        };
    }

    /**
     * Get the number of days since the upload was created.
     *
     * @return int
     */
    private function getDaysSinceUpload(): int
    {
        if (!$this->created_at) {
            return 0;
        }

        return (int) $this->created_at->diffInDays(Carbon::now());
    }

    /**
     * Get the last error message.
     *
     * @return string|null
     */
    private function getLastError(): ?string
    {
        $errors = $this->validation_errors ?? [];
        if (empty($errors)) {
            return null;
        }

        // Get the last error from the array
        $lastError = end($errors);
        if (is_array($lastError)) {
            return $lastError['message'] ?? $lastError['error'] ?? 'Unknown error';
        }

        return is_string($lastError) ? $lastError : 'Unknown error';
    }

    /**
     * Get common error types from validation errors.
     *
     * @return array
     */
    private function getCommonErrors(): array
    {
        $errors = $this->validation_errors ?? [];
        if (empty($errors)) {
            return [];
        }

        $errorTypes = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $type = $error['type'] ?? $error['code'] ?? 'validation_error';
                $errorTypes[$type] = ($errorTypes[$type] ?? 0) + 1;
            }
        }

        // Sort by frequency and return top 5
        arsort($errorTypes);
        return array_slice($errorTypes, 0, 5, true);
    }

    /**
     * Get the processing summary timestamp.
     *
     * @return string|null
     */
    private function getProcessingSummaryTimestamp(): ?string
    {
        $summary = $this->processing_summary ?? [];
        
        if (isset($summary['processing_completed_at'])) {
            return $summary['processing_completed_at'];
        }
        
        if (isset($summary['last_updated'])) {
            return $summary['last_updated'];
        }
        
        return $this->updated_at?->toISOString();
    }

    /**
     * Get processing options from processing summary.
     *
     * @return array
     */
    private function getProcessingOptions(): array
    {
        $summary = $this->processing_summary ?? [];
        return $summary['processing_options'] ?? [];
    }

    /**
     * Get validation options from processing summary.
     *
     * @return array
     */
    private function getValidationOptions(): array
    {
        $summary = $this->processing_summary ?? [];
        return $summary['validation_options'] ?? [];
    }

    /**
     * Check if the current user can view this upload.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanView(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        // Creator can always view
        if ($this->created_by_user_id === $user->id) {
            return true;
        }

        // Target user can view
        if ($this->user_id === $user->id) {
            return true;
        }

        // Check if user has admin/manager permissions
        return method_exists($user, 'can') ? $user->can('manageUploads', [\App\Models\PocketExpense::class, $this->client_id]) : false;
    }

    /**
     * Check if the current user can cancel this upload.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanCancel(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if (!$this->canBeCancelled()) {
            return false;
        }

        // Creator can cancel
        if ($this->created_by_user_id === $user->id) {
            return true;
        }

        // Admin/manager can cancel
        return method_exists($user, 'can') ? $user->can('manageUploads', [\App\Models\PocketExpense::class, $this->client_id]) : false;
    }

    /**
     * Check if the current user can retry this upload.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanRetry(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if (!$this->canBeRetried()) {
            return false;
        }

        // Creator can retry
        if ($this->created_by_user_id === $user->id) {
            return true;
        }

        // Admin/manager can retry
        return method_exists($user, 'can') ? $user->can('manageUploads', [\App\Models\PocketExpense::class, $this->client_id]) : false;
    }

    /**
     * Check if the current user can delete this upload.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanDelete(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if (!$this->canBeDeleted()) {
            return false;
        }

        // Creator can delete
        if ($this->created_by_user_id === $user->id) {
            return true;
        }

        // Admin/manager can delete
        return method_exists($user, 'can') ? $user->can('manageUploads', [\App\Models\PocketExpense::class, $this->client_id]) : false;
    }

    /**
     * Check if the current user can download error files.
     *
     * @param Request $request
     * @return bool
     */
    private function userCanDownloadErrors(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if (!$this->hasValidationErrors()) {
            return false;
        }

        // Creator can download errors
        if ($this->created_by_user_id === $user->id) {
            return true;
        }

        // Target user can download errors
        if ($this->user_id === $user->id) {
            return true;
        }

        // Admin/manager can download errors
        return method_exists($user, 'can') ? $user->can('downloadUploadErrors', [\App\Models\PocketExpense::class, $this->client_id]) : false;
    }

    /**
     * Check if the current user is the creator of this upload.
     *
     * @param Request $request
     * @return bool
     */
    private function isCreator(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return $this->created_by_user_id === $user->id;
    }

    /**
     * Check if the current user is the target user for this upload.
     *
     * @param Request $request
     * @return bool
     */
    private function isTargetUser(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        return $this->user_id === $user->id;
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
        $response->header('X-Resource-Type', 'PocketExpenseUpload');
        $response->header('X-Resource-Version', '1.0');
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'pocket_expense_upload',
                'version' => '1.0',
                'timestamp' => Carbon::now()->toISOString(),
                'upload_info' => [
                    'uuid' => $this->uuid,
                    'status' => $this->status,
                    'file_type' => $this->file_type,
                    'has_errors' => $this->hasValidationErrors(),
                ],
                'processing_info' => [
                    'progress_percentage' => $this->getProgressPercentage(),
                    'is_active' => $this->isActive(),
                    'is_finished' => $this->isFinished(),
                ],
                'statistics' => [
                    'total_rows' => $this->total_rows,
                    'success_rate' => $this->getSuccessRate(),
                    'processing_duration' => $this->getFormattedProcessingDuration(),
                ],
            ],
        ];
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
                'resource_type' => 'pocket_expense_upload_collection',
                'version' => '1.0',
                'timestamp' => Carbon::now()->toISOString(),
                'collection_summary' => [
                    'total_items' => $resource instanceof \Illuminate\Pagination\LengthAwarePaginator ? 
                        $resource->total() : $resource->count(),
                    'has_pagination' => $resource instanceof \Illuminate\Pagination\LengthAwarePaginator,
                ],
            ],
        ]);
    }
}