## Code: app/Http/Resources/ExpenseUploadResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * ExpenseUploadResource
 * 
 * API resource for expense upload status and validation results.
 * Shapes upload data for API responses with processing status, validation errors,
 * and progress information. Used for CSV upload tracking and error reporting.
 */
class ExpenseUploadResource extends JsonResource
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
            // Core identifiers
            'id' => $this->id,
            'uuid' => $this->uuid,
            
            // File information
            'original_filename' => $this->original_filename,
            'stored_filename' => $this->stored_filename,
            'file_path' => $this->file_path,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_size_mb' => $this->getFileSizeMB(),
            'file_size_display' => $this->getFileSizeDisplay(),
            
            // User and client relationships
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'target_user_id' => $this->target_user_id,
            
            // User information
            'uploader' => $this->when($this->relationLoaded('user'), function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'target_user' => $this->when($this->relationLoaded('targetUser'), function () {
                return [
                    'id' => $this->targetUser->id,
                    'name' => $this->targetUser->name,
                    'email' => $this->targetUser->email,
                ];
            }),
            
            'client' => $this->when($this->relationLoaded('client'), function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                ];
            }),
            
            // Processing status
            'status' => $this->status,
            'status_display' => $this->getStatusDisplay(),
            'status_color' => $this->getStatusColor(),
            'status_description' => $this->getStatusDescription(),
            
            // Progress information
            'total_records' => (int) $this->total_records,
            'valid_records' => (int) $this->valid_records,
            'processed_records' => (int) $this->processed_records,
            'failed_records' => (int) $this->failed_records,
            
            // Progress calculations
            'progress_percentage' => $this->getProgressPercentage(),
            'validation_success_rate' => $this->getValidationSuccessRate(),
            'processing_success_rate' => $this->getProcessingSuccessRate(),
            'overall_success_rate' => $this->getOverallSuccessRate(),
            
            // Status flags
            'is_uploaded' => $this->isUploaded(),
            'is_validation_failed' => $this->isValidationFailed(),
            'is_validation_passed' => $this->isValidationPassed(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'is_failed' => $this->isFailed(),
            'is_sync_failed' => $this->isSyncFailed(),
            'has_errors' => $this->hasErrors(),
            'has_processing_errors' => $this->hasProcessingErrors(),
            'is_pending' => $this->isPending(),
            'is_successful' => $this->isSuccessful(),
            'is_finished' => $this->isFinished(),
            'can_retry' => $this->canRetry(),
            'can_cancel' => $this->canCancel(),
            
            // Error information
            'validation_errors' => $this->formatValidationErrors(),
            'processing_errors' => $this->formatProcessingErrors(),
            'error_summary' => $this->getErrorSummary(),
            'top_errors' => $this->getTopErrors(),
            
            // Notes and additional information
            'notes' => $this->notes,
            
            // Timestamps
            'uploaded_at' => $this->create_time?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
            
            // Human readable timestamps
            'uploaded_at_human' => $this->create_time?->diffForHumans(),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'completed_at_human' => $this->completed_at?->diffForHumans(),
            'failed_at_human' => $this->failed_at?->diffForHumans(),
            'updated_at_human' => $this->update_time?->diffForHumans(),
            
            // Duration calculations
            'processing_duration' => $this->getProcessingDuration(),
            'processing_duration_display' => $this->getProcessingDurationDisplay(),
            'total_duration' => $this->getTotalDuration(),
            'total_duration_display' => $this->getTotalDurationDisplay(),
            
            // Upload statistics
            'statistics' => $this->getStatistics(),
            
            // Actions available for this upload
            'available_actions' => $this->getAvailableActions(),
            
            // Related data
            'upload_data' => $this->when($this->relationLoaded('uploadData'), function () {
                return $this->formatUploadData();
            }),
            
            // Additional computed fields
            'batch_info' => $this->getBatchInfo(),
            'performance_metrics' => $this->getPerformanceMetrics(),
            'next_steps' => $this->getNextSteps(),
            'recommendations' => $this->getRecommendations(),
        ];
    }
    
    /**
     * Get file size in megabytes.
     *
     * @return float
     */
    private function getFileSizeMB(): float
    {
        return round($this->file_size / 1024 / 1024, 2);
    }
    
    /**
     * Get human readable file size.
     *
     * @return string
     */
    private function getFileSizeDisplay(): string
    {
        $bytes = $this->file_size;
        
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Get status display name.
     *
     * @return string
     */
    private function getStatusDisplay(): string
    {
        $statusLabels = [
            'uploaded' => 'Uploaded',
            'validation_failed' => 'Validation Failed',
            'validation_passed' => 'Validation Passed',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'sync_failed' => 'Sync Failed',
        ];
        
        return $statusLabels[$this->status] ?? ucfirst($this->status);
    }
    
    /**
     * Get status color for UI.
     *
     * @return string
     */
    private function getStatusColor(): string
    {
        $statusColors = [
            'uploaded' => 'blue',
            'validation_failed' => 'red',
            'validation_passed' => 'green',
            'processing' => 'yellow',
            'completed' => 'green',
            'failed' => 'red',
            'sync_failed' => 'red',
        ];
        
        return $statusColors[$this->status] ?? 'gray';
    }
    
    /**
     * Get status description.
     *
     * @return string
     */
    private function getStatusDescription(): string
    {
        $descriptions = [
            'uploaded' => 'File has been uploaded and is awaiting validation.',
            'validation_failed' => 'File validation failed. Please review and correct the errors.',
            'validation_passed' => 'File validation passed. Processing will begin shortly.',
            'processing' => 'File is currently being processed. Expenses are being created.',
            'completed' => 'File processing completed successfully. All valid expenses have been created.',
            'failed' => 'File processing failed due to system errors.',
            'sync_failed' => 'File validation passed but expense creation failed.',
        ];
        
        return $descriptions[$this->status] ?? 'Status unknown.';
    }
    
    /**
     * Get progress percentage.
     *
     * @return float
     */
    private function getProgressPercentage(): float
    {
        if ($this->total_records <= 0) {
            return 0.0;
        }
        
        switch ($this->status) {
            case 'uploaded':
                return 10.0;
            case 'validation_failed':
                return 20.0;
            case 'validation_passed':
                return 30.0;
            case 'processing':
                $processedRatio = $this->processed_records / $this->total_records;
                return 30.0 + ($processedRatio * 60.0);
            case 'completed':
                return 100.0;
            case 'failed':
            case 'sync_failed':
                return 100.0;
            default:
                return 0.0;
        }
    }
    
    /**
     * Get validation success rate.
     *
     * @return float
     */
    private function getValidationSuccessRate(): float
    {
        if ($this->total_records <= 0) {
            return 0.0;
        }
        
        return round(($this->valid_records / $this->total_records) * 100, 2);
    }
    
    /**
     * Get processing success rate.
     *
     * @return float
     */
    private function getProcessingSuccessRate(): float
    {
        $totalToProcess = $this->processed_records + $this->failed_records;
        
        if ($totalToProcess <= 0) {
            return 0.0;
        }
        
        return round(($this->processed_records / $totalToProcess) * 100, 2);
    }
    
    /**
     * Get overall success rate.
     *
     * @return float
     */
    private function getOverallSuccessRate(): float
    {
        if ($this->total_records <= 0) {
            return 0.0;
        }
        
        return round(($this->processed_records / $this->total_records) * 100, 2);
    }
    
    /**
     * Check if upload is in uploaded status.
     *
     * @return bool
     */
    private function isUploaded(): bool
    {
        return $this->status === 'uploaded';
    }
    
    /**
     * Check if validation failed.
     *
     * @return bool
     */
    private function isValidationFailed(): bool
    {
        return $this->status === 'validation_failed';
    }
    
    /**
     * Check if validation passed.
     *
     * @return bool
     */
    private function isValidationPassed(): bool
    {
        return $this->status === 'validation_passed';
    }
    
    /**
     * Check if currently processing.
     *
     * @return bool
     */
    private function isProcessing(): bool
    {
        return $this->status === 'processing';
    }
    
    /**
     * Check if completed.
     *
     * @return bool
     */
    private function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
    
    /**
     * Check if failed.
     *
     * @return bool
     */
    private function isFailed(): bool
    {
        return $this->status === 'failed';
    }
    
    /**
     * Check if sync failed.
     *
     * @return bool
     */
    private function isSyncFailed(): bool
    {
        return $this->status === 'sync_failed';
    }
    
    /**
     * Check if has validation errors.
     *
     * @return bool
     */
    private function hasErrors(): bool
    {
        return !empty($this->validation_errors) || !empty($this->processing_errors);
    }
    
    /**
     * Check if has processing errors.
     *
     * @return bool
     */
    private function hasProcessingErrors(): bool
    {
        return !empty($this->processing_errors);
    }
    
    /**
     * Check if upload is pending (not finished).
     *
     * @return bool
     */
    private function isPending(): bool
    {
        return in_array($this->status, ['uploaded', 'validation_passed', 'processing']);
    }
    
    /**
     * Check if upload was successful.
     *
     * @return bool
     */
    private function isSuccessful(): bool
    {
        return $this->status === 'completed' && $this->processed_records > 0;
    }
    
    /**
     * Check if upload is finished (any final state).
     *
     * @return bool
     */
    private function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'sync_failed', 'validation_failed']);
    }
    
    /**
     * Check if upload can be retried.
     *
     * @return bool
     */
    private function canRetry(): bool
    {
        return in_array($this->status, ['failed', 'sync_failed']);
    }
    
    /**
     * Check if upload can be cancelled.
     *
     * @return bool
     */
    private function canCancel(): bool
    {
        return in_array($this->status, ['uploaded', 'validation_passed', 'processing']);
    }
    
    /**
     * Format validation errors for display.
     *
     * @return array<array<string, mixed>>|null
     */
    private function formatValidationErrors(): ?array
    {
        if (empty($this->validation_errors)) {
            return null;
        }
        
        $errors = is_array($this->validation_errors) ? $this->validation_errors : [];
        $formatted = [];
        
        foreach ($errors as $error) {
            $formatted[] = [
                'line_number' => $error['line_number'] ?? 0,
                'field' => $error['field'] ?? 'Unknown Field',
                'error' => $error['error'] ?? 'Unknown Error',
                'value' => $error['value'] ?? '',
                'severity' => $error['severity'] ?? 'error',
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Format processing errors for display.
     *
     * @return array<array<string, mixed>>|null
     */
    private function formatProcessingErrors(): ?array
    {
        if (empty($this->processing_errors)) {
            return null;
        }
        
        $errors = is_array($this->processing_errors) ? $this->