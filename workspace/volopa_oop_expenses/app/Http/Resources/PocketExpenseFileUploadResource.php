## Code: app/Http/Resources/PocketExpenseFileUploadResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * PocketExpenseFileUploadResource
 * 
 * API Resource for transforming PocketExpenseFileUpload model responses.
 * Shapes output data structure and hides internal model fields for API responses
 * following Laravel best practices with proper data transformation.
 * 
 * Response Structure:
 * - Exposes essential file upload data for frontend consumption
 * - Includes related user, client, and created by information
 * - Formats timestamps consistently
 * - Hides sensitive internal fields and database specifics
 * - Provides clear upload status and processing metadata
 * - Includes validation errors and upload statistics
 */
class PocketExpenseFileUploadResource extends JsonResource
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
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'total_records' => (int) $this->total_records,
            'valid_records' => (int) $this->valid_records,
            'invalid_records' => (int) ($this->total_records - $this->valid_records),
            'validation_errors' => $this->validation_errors ?? [],
            'status' => $this->status,
            
            // Status information
            'status_info' => [
                'current' => $this->status,
                'display_name' => $this->getStatusDisplayName(),
                'is_processing' => $this->isProcessing(),
                'is_completed' => $this->isCompleted(),
                'is_failed' => $this->isFailed(),
                'can_retry' => $this->canRetry(),
                'progress_percentage' => $this->getProgressPercentage(),
            ],
            
            // File information
            'file_info' => [
                'name' => $this->file_name,
                'size' => $this->getFileSize(),
                'type' => $this->getFileType(),
                'extension' => $this->getFileExtension(),
                'is_csv' => $this->isCsvFile(),
            ],
            
            // Processing statistics
            'statistics' => [
                'total_rows' => (int) $this->total_records,
                'valid_rows' => (int) $this->valid_records,
                'invalid_rows' => (int) ($this->total_records - $this->valid_records),
                'success_rate' => $this->getSuccessRate(),
                'error_count' => $this->getErrorCount(),
                'has_errors' => $this->hasValidationErrors(),
            ],
            
            // Related user information
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name ?? 'Unknown User',
                'email' => $this->user?->email,
                'role' => $this->user?->role ?? 'Unknown Role',
            ],
            
            // Related client information
            'client' => [
                'id' => $this->client?->id,
                'name' => $this->client?->name ?? 'Unknown Client',
                'code' => $this->client?->code,
            ],
            
            // Related created by user information
            'created_by' => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name ?? 'Unknown User',
                'email' => $this->createdBy?->email,
                'role' => $this->createdBy?->role ?? 'Unknown Role',
            ],
            
            // Upload data information
            'uploads_data' => $this->when($this->relationLoaded('uploadsData'), function () {
                return [
                    'total_count' => $this->uploadsData->count(),
                    'pending_count' => $this->uploadsData->where('status', 'pending')->count(),
                    'processing_count' => $this->uploadsData->where('status', 'processing')->count(),
                    'synced_count' => $this->uploadsData->where('status', 'synced')->count(),
                    'failed_count' => $this->uploadsData->where('status', 'failed')->count(),
                    'status_breakdown' => [
                        'pending' => $this->uploadsData->where('status', 'pending')->count(),
                        'processing' => $this->uploadsData->where('status', 'processing')->count(),
                        'synced' => $this->uploadsData->where('status', 'synced')->count(),
                        'failed' => $this->uploadsData->where('status', 'failed')->count(),
                    ],
                ];
            }, []),
            
            // Validation error summary
            'error_summary' => $this->when($this->hasValidationErrors(), function () {
                $errors = $this->validation_errors ?? [];
                $errorTypes = [];
                $errorLines = [];
                
                foreach ($errors as $error) {
                    if (isset($error['type'])) {
                        $errorTypes[] = $error['type'];
                    }
                    if (isset($error['line'])) {
                        $errorLines[] = $error['line'];
                    }
                }
                
                return [
                    'total_errors' => count($errors),
                    'unique_error_types' => array_unique($errorTypes),
                    'affected_lines' => array_unique($errorLines),
                    'most_common_error' => $this->getMostCommonError($errors),
                    'first_error' => $errors[0] ?? null,
                ];
            }, null),
            
            // Timestamps
            'uploaded_at' => $this->uploaded_at ? $this->uploaded_at->toISOString() : null,
            'validated_at' => $this->validated_at ? $this->validated_at->toISOString() : null,
            'processed_at' => $this->processed_at ? $this->processed_at->toISOString() : null,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'deleted_at' => $this->deleted_at ? $this->deleted_at->toISOString() : null,
            
            // Processing timeline
            'timeline' => [
                'uploaded' => [
                    'timestamp' => $this->uploaded_at ? $this->uploaded_at->toISOString() : null,
                    'human' => $this->uploaded_at ? $this->uploaded_at->diffForHumans() : null,
                    'completed' => true,
                ],
                'validated' => [
                    'timestamp' => $this->validated_at ? $this->validated_at->toISOString() : null,
                    'human' => $this->validated_at ? $this->validated_at->diffForHumans() : null,
                    'completed' => $this->validated_at !== null,
                ],
                'processed' => [
                    'timestamp' => $this->processed_at ? $this->processed_at->toISOString() : null,
                    'human' => $this->processed_at ? $this->processed_at->diffForHumans() : null,
                    'completed' => $this->processed_at !== null,
                ],
            ],
            
            // Processing duration
            'duration' => [
                'total' => $