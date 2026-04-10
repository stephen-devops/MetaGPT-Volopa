<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseUploadResource
 * 
 * API Resource transformer for pocket expense file uploads.
 * Shapes upload tracking data for consistent API responses.
 * Hides internal fields and formats timestamps properly.
 */
class PocketExpenseUploadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
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
            'status' => $this->status,
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Include validation errors only when present and status is validation_failed
            'validation_errors' => $this->when(
                $this->status === 'validation_failed' && !empty($this->validation_errors),
                $this->validation_errors
            ),
            
            // Calculate error count from validation errors
            'error_count' => $this->when(
                $this->status === 'validation_failed' && !empty($this->validation_errors),
                count(is_string($this->validation_errors) ? json_decode($this->validation_errors, true) ?? [] : $this->validation_errors ?? [])
            ),
            
            // Include processing progress information
            'processing_info' => [
                'is_completed' => $this->status === 'completed',
                'is_failed' => in_array($this->status, ['validation_failed', 'failed', 'sync_failed']),
                'is_processing' => $this->status === 'processing',
                'can_retry' => in_array($this->status, ['validation_failed', 'failed', 'sync_failed']),
            ],
            
            // Related user information when available
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? null,
                ];
            }),
            
            // Related client information when available
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? null,
                ];
            }),
            
            // Related created by user information when available
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name ?? null,
                ];
            }),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'upload_constraints' => [
                    'max_file_size_kb' => 10240, // 10MB
                    'max_rows' => 200,
                    'allowed_formats' => ['csv', 'txt'],
                    'required_headers' => [
                        'Date',
                        'Expense Type', 
                        'Currency Code',
                        'Amount',
                        'Merchant Name',
                    ],
                ],
            ],
        ];
    }
}