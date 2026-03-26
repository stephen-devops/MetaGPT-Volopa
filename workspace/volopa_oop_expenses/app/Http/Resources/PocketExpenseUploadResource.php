<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pocket Expense Upload Resource
 * 
 * API Resource for transforming PocketExpenseFileUpload model instances
 * into consistent JSON responses for CSV upload status and progress.
 * Shapes response data and hides internal fields per system constraints.
 */
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
            'file_name' => $this->file_name,
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'error_count' => $this->getErrorCount(),
            'status' => $this->status,
            'validation_errors' => $this->when(
                !is_null($this->validation_errors) && $this->status === 'failed',
                $this->getFormattedValidationErrors()
            ),
            'progress_percentage' => $this->getProgressPercentage(),
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
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
        ];
    }

    /**
     * Get the error count from validation errors.
     *
     * @return int
     */
    private function getErrorCount(): int
    {
        if (is_null($this->validation_errors)) {
            return 0;
        }

        $errors = json_decode($this->validation_errors, true);
        return is_array($errors) ? count($errors) : 0;
    }

    /**
     * Get formatted validation errors for response.
     *
     * @return array|null
     */
    private function getFormattedValidationErrors(): ?array
    {
        if (is_null($this->validation_errors)) {
            return null;
        }

        $errors = json_decode($this->validation_errors, true);
        
        if (!is_array($errors)) {
            return null;
        }

        // Return formatted error structure as per response schema
        return array_map(function ($error) {
            return [
                'line_number' => $error['line_number'] ?? 0,
                'field' => $error['field'] ?? 'Unknown',
                'error' => $error['error'] ?? 'Validation error',
                'value' => $error['value'] ?? null,
            ];
        }, $errors);
    }

    /**
     * Calculate processing progress percentage.
     *
     * @return float
     */
    private function getProgressPercentage(): float
    {
        if ($this->total_records === 0) {
            return 0.0;
        }

        switch ($this->status) {
            case 'uploaded':
                return 10.0;
            case 'validating':
                return 30.0;
            case 'processing':
                return 70.0;
            case 'completed':
                return 100.0;
            case 'failed':
                return 0.0;
            default:
                return 0.0;
        }
    }

    /**
     * Get additional data that should be included with the resource array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'file_size_constraint' => '10MB max',
                'record_limit' => 200,
                'supported_formats' => ['CSV', 'TXT'],
            ],
        ];
    }

    /**
     * Customize the response for a successful upload.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toSuccessResponse(Request $request): array
    {
        return [
            'success' => true,
            'message' => 'File uploaded and queued for processing.',
            'upload_id' => $this->id,
            'total_rows' => $this->total_records,
            'data' => $this->toArray($request),
        ];
    }

    /**
     * Customize the response for a failed upload.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toErrorResponse(Request $request): array
    {
        return [
            'success' => false,
            'message' => 'File validation failed.',
            'upload_id' => $this->id,
            'total_rows' => $this->total_records,
            'error_count' => $this->getErrorCount(),
            'errors' => $this->getFormattedValidationErrors() ?? [],
            'data' => $this->toArray($request),
        ];
    }

    /**
     * Get the status display name for human-readable format.
     *
     * @return string
     */
    private function getStatusDisplayName(): string
    {
        $statusMap = [
            'uploaded' => 'Uploaded',
            'validating' => 'Validating',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ];

        return $statusMap[$this->status] ?? 'Unknown';
    }

    /**
     * Determine if the upload is in a final state.
     *
     * @return bool
     */
    private function isFinalState(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    /**
     * Determine if the upload can be retried.
     *
     * @return bool
     */
    private function canRetry(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get estimated completion time based on current status.
     *
     * @return string|null
     */
    private function getEstimatedCompletion(): ?string
    {
        if ($this->isFinalState()) {
            return null;
        }

        // TODO: Implement estimated completion time calculation based on:
        // - Current queue length
        // - Average processing time per record
        // - System load metrics
        // This would integrate with the queue system and monitoring infrastructure
        
        switch ($this->status) {
            case 'uploaded':
            case 'validating':
                return 'Within 5 minutes';
            case 'processing':
                return 'Within 10 minutes';
            default:
                return null;
        }
    }

    /**
     * Get processing summary for completed uploads.
     *
     * @return array|null
     */
    private function getProcessingSummary(): ?array
    {
        if ($this->status !== 'completed') {
            return null;
        }

        return [
            'total_processed' => $this->valid_records,
            'success_rate' => $this->total_records > 0 
                ? round(($this->valid_records / $this->total_records) * 100, 2) 
                : 0,
            'processing_duration' => $this->getProcessingDuration(),
        ];
    }

    /**
     * Calculate processing duration.
     *
     * @return string|null
     */
    private function getProcessingDuration(): ?string
    {
        if (is_null($this->uploaded_at) || is_null($this->processed_at)) {
            return null;
        }

        $duration = $this->processed_at->diffInSeconds($this->uploaded_at);
        
        if ($duration < 60) {
            return $duration . ' seconds';
        } elseif ($duration < 3600) {
            return round($duration / 60, 1) . ' minutes';
        } else {
            return round($duration / 3600, 1) . ' hours';
        }
    }
}