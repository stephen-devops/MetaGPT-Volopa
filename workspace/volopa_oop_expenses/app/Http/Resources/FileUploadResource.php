## Code: app/Http/Resources/FileUploadResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class FileUploadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'expense_user_id' => $this->expense_user_id,
            'original_filename' => $this->original_filename,
            'stored_filename' => $this->stored_filename,
            'file_path' => $this->file_path,
            'file_size' => $this->file_size,
            'file_size_formatted' => $this->getFormattedFileSize(),
            'mime_type' => $this->mime_type,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'status_description' => $this->getStatusDescription(),
            
            // Record counts
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'invalid_records' => $this->invalid_records,
            'processed_records' => $this->processed_records,
            
            // Progress information
            'progress_percentage' => $this->getProgressPercentage(),
            'validation_progress' => $this->getValidationProgress(),
            'processing_progress' => $this->getProcessingProgress(),
            
            // Status checks
            'is_uploading' => $this->isUploading(),
            'is_validating' => $this->isValidating(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'is_failed' => $this->isFailed(),
            'is_in_progress' => $this->isInProgress(),
            'is_finished' => $this->isFinished(),
            'is_deleted' => $this->isDeleted(),
            
            // Error information
            'has_validation_errors' => $this->hasValidationErrors(),
            'has_processing_errors' => $this->hasProcessingErrors(),
            'validation_error_count' => $this->getValidationErrorCount(),
            'processing_error_count' => $this->getProcessingErrorCount(),
            'total_error_count' => $this->getTotalErrorCount(),
            'error_message' => $this->error_message,
            
            // Validation and processing errors (only if present and requested)
            'validation_errors' => $this->when(
                $this->hasValidationErrors() && $request->boolean('include_errors', false),
                $this->validation_errors ?? []
            ),
            'processing_errors' => $this->when(
                $this->hasProcessingErrors() && $request->boolean('include_errors', false),
                $this->processing_errors ?? []
            ),
            
            // Success rate
            'success_rate' => $this->when(
                $this->total_records > 0,
                $this->getSuccessRateAttribute()
            ),
            'success_rate_formatted' => $this->when(
                $this->total_records > 0,
                number_format($this->getSuccessRateAttribute(), 1) . '%'
            ),
            
            // Timestamps
            'started_at' => $this->started_at ? $this->started_at->toISOString() : null,
            'started_at_formatted' => $this->started_at ? $this->started_at->format('M j, Y g:i A') : null,
            'completed_at' => $this->completed_at ? $this->completed_at->toISOString() : null,
            'completed_at_formatted' => $this->completed_at ? $this->completed_at->format('M j, Y g:i A') : null,
            'failed_at' => $this->failed_at ? $this->failed_at->toISOString() : null,
            'failed_at_formatted' => $this->failed_at ? $this->failed_at->format('M j, Y g:i A') : null,
            'deleted_at' => $this->deleted_at ? $this->deleted_at->toISOString() : null,
            'deleted_at_formatted' => $this->deleted_at ? $this->deleted_at->format('M j, Y g:i A') : null,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'created_at_formatted' => $this->created_at ? $this->created_at->format('M j, Y g:i A') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_at_formatted' => $this->updated_at ? $this->updated_at->format('M j, Y g:i A') : null,
            
            // Processing time information
            'processing_duration' => $this->getProcessingDuration(),
            'processing_duration_formatted' => $this->getProcessingDurationFormatted(),
            'estimated_completion' => $this->getEstimatedCompletion(),
            'estimated_completion_formatted' => $this->getEstimatedCompletionFormatted(),
            
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            'expense_user' => $this->whenLoaded('expenseUser', function () {
                return [
                    'id' => $this->expenseUser->id,
                    'name' => $this->expenseUser->name,
                    'email' => $this->expenseUser->email,
                ];
            }),
            
            // Upload data summary (only if loaded)
            'upload_data_summary' => $this->whenLoaded('uploadData', function () {
                return $this->getUploadDataSummary();
            }),
            
            // File type information
            'file_extension' => $this->getFileExtension(),
            'is_csv_file' => $this->isCsvFile(),
            'file_icon' => $this->getFileIcon(),
            
            // Business logic flags
            'can_be_reprocessed' => $this->canBeReprocessed(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_deleted' => $this->canBeDeleted(),
            'can_download_errors' => $this->canDownloadErrors(),
            'can_view_details' => $this->canViewDetails(),
            
            // Display helpers
            'display_name' => $this->getDisplayName(),
            'display_status' => ucfirst($this->status ?? 'unknown'),
            'display_progress' => $this->getDisplayProgress(),
            
            // Additional metadata
            'meta' => [
                'belongs_to_user' => $this->when(
                    $request->user(),
                    function () use ($request) {
                        return $this->user_id === $request->user()->id;
                    }
                ),
                'belongs_to_client' => $this->when(
                    $request->has('client_id'),
                    function () use ($request) {
                        return $this->client_id === (int) $request->get('client_id');
                    }
                ),
                'is_recent' => $this->created_at && $this->created_at->isAfter(now()->subHours(24)),
                'requires_attention' => $this->requiresAttention(),
                'has_partial_success' => $this->hasPartialSuccess(),
                'upload_source' => 'csv_import',
                'processing_mode' => $this->getProcessingMode(),
                'file_type' => 'pocket_expense_csv',
            ],
            
            // Links for HATEOAS
            'links' => [
                'self' => route('api.uploads.pocket-expense.status', ['upload' => $this->id]),
                'download_errors' => $this->when(
                    $this->canDownloadErrors(),
                    route('api.uploads.pocket-expense.download-errors', ['upload' => $this->id])
                ),
                'reprocess' => $this->when(
                    $this->canBeReprocessed(),
                    route('api.uploads.pocket-expense.reprocess', ['upload' => $this->id])
                ),
                'cancel' => $this->when(
                    $this->canBeCancelled(),
                    route('api.uploads.pocket-expense.cancel', ['upload' => $this->id])
                ),
                'delete' => $this->when(
                    $this->canBeDeleted(),
                    route('api.uploads.pocket-expense.delete', ['upload' => $this->id])
                ),
                'details' => $this->when(
                    $this->canViewDetails(),
                    route('api.uploads.pocket-expense.details', ['upload' => $this->id])
                ),
                'created_expenses' => $this->when(
                    $this->processed_records > 0,
                    route('api.expenses.index', ['upload_id' => $this->id])
                ),
            ],
        ];
    }

    /**
     * Get formatted file size.
     */
    private function getFormattedFileSize(): string
    {
        $bytes = $this->file_size ?? 0;
        
        if ($bytes === 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Get status label for display.
     */
    private function getStatusLabel(): string
    {
        return match ($this->status ?? 'unknown') {
            'uploading' => 'Uploading',
            'validating' => 'Validating',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for UI.
     */
    private function getStatusColor(): string
    {
        return match ($this->status ?? 'unknown') {
            'uploading' => 'info',
            'validating' => 'warning',
            'processing' => 'primary',
            'completed' => 'success',
            'failed' => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get status description.
     */
    private function getStatusDescription(): string
    {
        return match ($this->status ?? 'unknown') {
            'uploading' => 'File is being uploaded to the server',
            'validating' => 'File content is being validated',
            'processing' => 'Valid records are being processed into expenses',
            'completed' => 'All records have been processed successfully',
            'failed' => 'Upload processing failed due to errors',
            default => 'Status unknown',
        };
    }

    /**
     * Get overall progress percentage.
     */
    private function getProgressPercentage(): float
    {
        if ($this->total_records === 0) {
            return match ($this->status ?? 'uploading') {
                'uploading' => 10.0,
                'validating' => 30.0,
                'processing' => 50.0,
                'completed' => 100.0,
                'failed' => 0.0,
                default => 0.0,
            };
        }

        if ($this->isCompleted()) {
            return 100.0;
        }

        if ($this->isFailed()) {
            return 0.0;
        }

        // Calculate based on processed records
        $processedPercentage = ($this->processed_records / $this->total_records) * 100;
        
        // Add base progress for validation/processing stages
        $baseProgress = match ($this->status ?? 'uploading') {
            'uploading' => 0.0,
            'validating' => 20.0,
            'processing' => 40.0,
            default => 0.0,
        };

        return min(100.0, $baseProgress + ($processedPercentage * 0.6));
    }

    /**
     * Get validation progress.
     */
    private function getValidationProgress(): array
    {
        $validRecords = $this->valid_records ?? 0;
        $invalidRecords = $this->invalid_records ?? 0;
        $totalRecords = $this->total_records ?? 0;

        if ($totalRecords === 0) {
            return [
                'total' => 0,
                'validated' => 0,
                'percentage' => 0.0,
                'valid' => 0,
                'invalid' => 0,
            ];
        }

        $validated = $validRecords + $invalidRecords;
        $percentage = ($validated / $totalRecords) * 100;

        return [
            'total' => $totalRecords,
            'validated' => $validated,
            'percentage' => round($percentage, 1),
            'valid' => $validRecords,
            'invalid' => $invalidRecords,
        ];
    }

    /**
     * Get processing progress.
     */
    private function getProcessingProgress(): array
    {
        $processedRecords = $this->processed_records ?? 0;
        $validRecords = $this->valid_records ?? 0;

        if ($validRecords === 0) {
            return [
                'total_to_process' => 0,
                'processed' => 0,
                'percentage' => 0.0,
                'remaining' => 0,
            ];
        }

        $percentage = ($processedRecords / $validRecords) * 100;
        $remaining = max(0, $validRecords - $processedRecords);

        return [
            'total_to_process' => $validRecords,
            'processed' => $processedRecords,
            'percentage' => round($percentage, 1),
            'remaining' => $remaining,
        ];
    }

    /**
     * Get processing duration in seconds.
     */
    private function getProcessingDuration(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $endTime = $this->completed_at ?? $this->failed_at ?? now();
        return $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Get formatted processing duration.
     */
    private function getProcessingDurationFormatted(): ?string
    {
        $duration = $this->getProcessingDuration();
        
        if ($duration === null) {
            return null;
        }

        if ($duration < 60) {
            return $duration . 's';
        }

        if ($duration < 3600) {
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            return $minutes . 'm ' . $seconds . 's';
        }

        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }

    /**
     * Get estimated completion time.
     */
    private function getEstimatedCompletion(): ?Carbon
    {
        if (!$