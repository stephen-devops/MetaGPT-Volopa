<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseFileUploadResource
 * 
 * API resource transformer for PocketExpenseFileUpload model.
 * Shapes the response data for CSV file upload operations and hides internal fields.
 * 
 * @mixin \App\Models\PocketExpenseFileUpload
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
            'total_records' => $this->total_records,
            'valid_records' => $this->valid_records,
            'validation_errors' => $this->validation_errors,
            'status' => $this->status,
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Include relationships when loaded to prevent N+1 queries
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
            
            // Include upload data when loaded for detailed view
            'uploads_data' => $this->whenLoaded('uploadsData', function () {
                return $this->uploadsData->map(function ($uploadData) {
                    return [
                        'id' => $uploadData->id,
                        'line_number' => $uploadData->line_number,
                        'status' => $uploadData->status,
                        'expense_data' => $uploadData->expense_data,
                        'created_at' => $uploadData->created_at?->toISOString(),
                        'updated_at' => $uploadData->updated_at?->toISOString(),
                    ];
                });
            }),
            
            // Computed fields for frontend convenience
            'error_count' => $this->when(
                !is_null($this->validation_errors),
                function () {
                    return is_array($this->validation_errors) && isset($this->validation_errors['errors']) 
                        ? count($this->validation_errors['errors'])
                        : 0;
                }
            ),
            
            'success_rate' => $this->when(
                $this->total_records > 0,
                function () {
                    return round(($this->valid_records / $this->total_records) * 100, 2);
                },
                0
            ),
            
            'is_completed' => $this->status === 'completed',
            'is_failed' => in_array($this->status, ['validation_failed', 'failed', 'sync_failed']),
            'is_processing' => in_array($this->status, ['uploaded', 'validation_passed', 'processing']),
        ];
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
                'version' => '1.0.0',
                'resource_type' => 'pocket_expense_file_upload',
            ],
        ];
    }
}