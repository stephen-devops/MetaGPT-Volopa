<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for PocketExpenseFileUpload model
 * 
 * Shapes the JSON response for pocket expense file upload data, providing
 * a consistent API output format while hiding internal model details.
 * Includes upload status, processing statistics, validation errors, and
 * timestamp information for client-side upload tracking.
 * 
 * @mixin \App\Models\PocketExpenseFileUpload
 */
class PocketExpenseFileUploadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Primary identifiers
            'id' => $this->id,
            'uuid' => $this->uuid,
            
            // User and client context
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'created_by_user_id' => $this->created_by_user_id,
            
            // File information
            'file_name' => $this->file_name,
            'file_path' => $this->when(
                $request->user()?->can('view', $this->resource),
                $this->file_path,
                'Access restricted'
            ),
            
            // Processing statistics
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'invalid_records' => $this->total_records - $this->valid_records,
            
            // Processing status
            'status' => $this->status,
            'status_display' => $this->getStatusDisplay(),
            
            // Validation errors (only show if validation failed)
            'validation_errors' => $this->when(
                $this->status === 'validation_failed' && !empty($this->validation_errors),
                $this->validation_errors
            ),
            
            // Processing timestamps
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            
            // Laravel standard timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Processing progress information
            'processing_progress' => $this->getProcessingProgress(),
            
            // Relationships (only load when requested to avoid N+1 queries)
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? 'Unknown User',
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name ?? 'Unknown User',
                ];
            }),
            
            // Upload summary for quick overview
            'upload_summary' => [
                'file_name' => $this->file_name,
                'total_records' => $this->total_records,
                'success_rate' => $this->getSuccessRate(),
                'status' => $this->status,
                'uploaded_at' => $this->uploaded_at?->toISOString(),
            ],
            
            // Error summary (only if there are validation errors)
            'error_summary' => $this->when(
                !empty($this->validation_errors),
                $this->getErrorSummary()
            ),
        ];
    }

    /**
     * Get human-readable status display text.
     *
     * @return string
     */
    private function getStatusDisplay(): string
    {
        return match ($this->status) {
            'uploaded' => 'File Uploaded',
            'validating' => 'Validating Data',
            'validation_failed' => 'Validation Failed',
            'processing' => 'Processing Records',
            'completed' => 'Processing Completed',
            'failed' => 'Processing Failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Calculate processing progress as a percentage.
     *
     * @return array<string, mixed>
     */
    private function getProcessingProgress(): array
    {
        $totalRecords = max($this->total_records, 1); // Avoid division by zero
        $validRecords = $this->valid_records;
        $invalidRecords = $totalRecords - $validRecords;
        
        return [
            'total_records' => $totalRecords,
            'valid_records' => $validRecords,
            'invalid_records' => $invalidRecords,
            'validation_progress' => round(($validRecords / $totalRecords) * 100, 2),
            'completion_status' => $this->getCompletionStatus(),
            'is_complete' => in_array($this->status, ['completed', 'failed', 'validation_failed']),
            'can_retry' => $this->status === 'failed',
        ];
    }

    /**
     * Calculate success rate for validation.
     *
     * @return float
     */
    private function getSuccessRate(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }
        
        return round(($this->valid_records / $this->total_records) * 100, 2);
    }

    /**
     * Get completion status description.
     *
     * @return string
     */
    private function getCompletionStatus(): string
    {
        return match ($this->status) {
            'uploaded' => 'Awaiting validation',
            'validating' => 'Validation in progress',
            'validation_failed' => 'Validation completed with errors',
            'processing' => 'Processing expenses',
            'completed' => 'All records processed successfully',
            'failed' => 'Processing failed',
            default => 'Status unknown',
        };
    }

    /**
     * Get summary of validation errors grouped by error type.
     *
     * @return array<string, mixed>|null
     */
    private function getErrorSummary(): ?array
    {
        if (empty($this->validation_errors)) {
            return null;
        }

        $errors = is_string($this->validation_errors) 
            ? json_decode($this->validation_errors, true) 
            : $this->validation_errors;

        if (!is_array($errors)) {
            return null;
        }

        $errorCounts = [];
        $sampleErrors = [];
        $affectedLines = [];

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $errorType = $error['field'] ?? 'unknown_field';
            $lineNumber = $error['line_number'] ?? 0;
            $errorMessage = $error['error'] ?? 'Unknown error';

            // Count errors by type
            if (!isset($errorCounts[$errorType])) {
                $errorCounts[$errorType] = 0;
                $sampleErrors[$errorType] = $errorMessage;
            }
            $errorCounts[$errorType]++;

            // Track affected line numbers
            if ($lineNumber > 0) {
                $affectedLines[] = $lineNumber;
            }
        }

        return [
            'total_errors' => count($errors),
            'error_types_count' => count($errorCounts),
            'errors_by_field' => $errorCounts,
            'sample_errors' => $sampleErrors,
            'affected_lines' => array_unique($affectedLines),
            'lines_with_errors' => count(array_unique($affectedLines)),
            'most_common_error' => $this->getMostCommonError($errorCounts),
        ];
    }

    /**
     * Get the most common validation error type.
     *
     * @param array<string, int> $errorCounts
     * @return array<string, mixed>|null
     */
    private function getMostCommonError(array $errorCounts): ?array
    {
        if (empty($errorCounts)) {
            return null;
        }

        $maxCount = max($errorCounts);
        $mostCommonField = array_search($maxCount, $errorCounts);

        return [
            'field' => $mostCommonField,
            'count' => $maxCount,
            'percentage' => round(($maxCount / array_sum($errorCounts)) * 100, 2),
        ];
    }

    /**
     * Additional data to include in the response for collection views.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'processing_statuses' => [
                    'uploaded' => 'File uploaded, awaiting validation',
                    'validating' => 'Data validation in progress',
                    'validation_failed' => 'Validation failed, check errors',
                    'processing' => 'Creating expense records',
                    'completed' => 'Processing completed successfully',
                    'failed' => 'Processing failed, manual intervention required',
                ],
                'success_threshold' => 100.0, // 100% validation required for processing
                'max_file_size' => 10485760, // 10MB in bytes
                'max_records' => 200,
                'supported_formats' => ['csv', 'txt'],
            ],
        ];
    }

    /**
     * Customize the response for when the resource is collected.
     *
     * @param  Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Add custom headers for CSV upload tracking
        $response->header('X-Upload-Status', $this->status);
        $response->header('X-Processing-Progress', $this->getSuccessRate());
        
        if ($this->status === 'completed') {
            $response->header('X-Records-Processed', $this->valid_records);
        }
    }

    /**
     * Create a minimal resource for list views.
     *
     * @return array<string, mixed>
     */
    public function toMinimal(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'file_name' => $this->file_name,
            'status' => $this->status,
            'status_display' => $this->getStatusDisplay(),
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'success_rate' => $this->getSuccessRate(),
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'is_complete' => in_array($this->status, ['completed', 'failed', 'validation_failed']),
        ];
    }

    /**
     * Create a detailed resource for single item views.
     *
     * @return array<string, mixed>
     */
    public function toDetailed(): array
    {
        return array_merge($this->toArray(request()), [
            'detailed_metrics' => [
                'processing_duration' => $this->getProcessingDuration(),
                'validation_duration' => $this->getValidationDuration(),
                'records_per_second' => $this->getProcessingRate(),
                'file_size_mb' => $this->getFileSizeMB(),
            ],
            'next_actions' => $this->getNextActions(),
        ]);
    }

    /**
     * Calculate processing duration in seconds.
     *
     * @return float|null
     */
    private function getProcessingDuration(): ?float
    {
        if (!$this->uploaded_at || !$this->processed_at) {
            return null;
        }

        return $this->uploaded_at->diffInSeconds($this->processed_at);
    }

    /**
     * Calculate validation duration in seconds.
     *
     * @return float|null
     */
    private function getValidationDuration(): ?float
    {
        if (!$this->uploaded_at || !$this->validated_at) {
            return null;
        }

        return $this->uploaded_at->diffInSeconds($this->validated_at);
    }

    /**
     * Calculate processing rate (records per second).
     *
     * @return float|null
     */
    private function getProcessingRate(): ?float
    {
        $duration = $this->getProcessingDuration();
        
        if (!$duration || $duration <= 0 || $this->total_records <= 0) {
            return null;
        }

        return round($this->total_records / $duration, 2);
    }

    /**
     * Get file size in MB (placeholder - would need actual file size from storage).
     *
     * @return float|null
     */
    private function getFileSizeMB(): ?float
    {
        // This would typically read from file system or store file size in database
        // For now, return null as file size is not stored in the current schema
        return null;
    }

    /**
     * Get suggested next actions based on upload status.
     *
     * @return array<string>
     */
    private function getNextActions(): array
    {
        return match ($this->status) {
            'uploaded' => ['Wait for validation to complete'],
            'validating' => ['Wait for validation to complete'],
            'validation_failed' => [
                'Review validation errors',
                'Fix CSV file issues',
                'Re-upload corrected file'
            ],
            'processing' => ['Wait for processing to complete'],
            'completed' => [
                'Review created expenses',
                'Notify target user of completed upload'
            ],
            'failed' => [
                'Contact system administrator',
                'Review error logs',
                'Consider re-uploading file'
            ],
            default => ['Check upload status'],
        };
    }
}