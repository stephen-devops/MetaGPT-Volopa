## Code: app/Http/Resources/PocketExpenseFileUploadResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

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
            'total_records' => $this->total_records ?? 0,
            'valid_records' => $this->valid_records ?? 0,
            'invalid_records' => $this->total_records - $this->valid_records,
            'validation_errors' => $this->validation_errors,
            'status' => $this->status,
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'validated_at' => $this->validated_at?->toISOString(),
            'processed_at' => $this->processed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
            
            // Relationship data (loaded when available)
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? 'Unknown User',
                    'email' => $this->user->email ?? 'no-email@example.com',
                    'role' => $this->user->role ?? 'user',
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                    'code' => $this->client->code ?? 'N/A',
                ];
            }),
            
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name ?? 'Unknown User',
                    'email' => $this->createdBy->email ?? 'no-email@example.com',
                    'role' => $this->createdBy->role ?? 'user',
                ];
            }),
            
            'upload_data' => $this->whenLoaded('uploadData', function () {
                return $this->uploadData->map(function ($data) {
                    return [
                        'id' => $data->id,
                        'line_number' => $data->line_number,
                        'status' => $data->status,
                        'expense_data' => $data->expense_data,
                        'created_at' => $data->created_at?->toISOString(),
                        'updated_at' => $data->updated_at?->toISOString(),
                    ];
                });
            }),
            
            // Additional computed fields
            'file_size_display' => $this->getFileSizeDisplay(),
            'status_display' => $this->getStatusDisplay(),
            'processing_progress' => $this->getProcessingProgress(),
            'validation_summary' => $this->getValidationSummary(),
            'processing_duration' => $this->getProcessingDuration(),
            'error_summary' => $this->getErrorSummary(),
            
            // Upload statistics
            'statistics' => [
                'total_records' => $this->total_records ?? 0,
                'valid_records' => $this->valid_records ?? 0,
                'invalid_records' => ($this->total_records ?? 0) - ($this->valid_records ?? 0),
                'success_rate' => $this->getSuccessRate(),
                'has_errors' => $this->hasValidationErrors(),
                'error_count' => $this->getErrorCount(),
            ],
            
            // Status information
            'status_info' => [
                'current_status' => $this->status,
                'is_processing' => $this->isProcessing(),
                'is_completed' => $this->isCompleted(),
                'is_failed' => $this->isFailed(),
                'is_validation_failed' => $this->isValidationFailed(),
                'can_retry' => $this->canRetry(),
                'can_download_errors' => $this->canDownloadErrors(),
            ],
            
            // Timing information
            'timing' => [
                'uploaded_at' => $this->uploaded_at?->toISOString(),
                'validated_at' => $this->validated_at?->toISOString(),
                'processed_at' => $this->processed_at?->toISOString(),
                'processing_started_at' => $this->getProcessingStartTime(),
                'processing_completed_at' => $this->getProcessingCompleteTime(),
                'total_processing_time' => $this->getTotalProcessingTime(),
            ],
            
            // File information
            'file_info' => [
                'name' => $this->file_name,
                'original_name' => $this->getOriginalFileName(),
                'extension' => $this->getFileExtension(),
                'mime_type' => $this->getFileMimeType(),
                'size_bytes' => $this->getFileSizeBytes(),
                'size_display' => $this->getFileSizeDisplay(),
                'upload_date' => $this->uploaded_at?->format('Y-m-d H:i:s'),
            ],
            
            // Permissions and actions
            'permissions' => [
                'can_view' => $this->canView(),
                'can_update' => $this->canUpdate(),
                'can_delete' => $this->canDelete(),
                'can_retry' => $this->canRetry(),
                'can_download_template' => true,
                'can_download_errors' => $this->canDownloadErrors(),
            ],
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
                'resource_type' => 'pocket_expense_file_upload',
                'api_version' => 'v1',
                'generated_at' => now()->toISOString(),
                'upload_constraints' => [
                    'max_file_size_kb' => 10240,
                    'max_csv_rows' => 200,
                    'allowed_extensions' => ['csv', 'txt'],
                    'required_headers' => [
                        'Date',
                        'Merchant Name',
                        'Merchant Description',
                        'Expense Type',
                        'Currency Code',
                        'Amount',
                        'Merchant Address',
                        'VAT %',
                        'Source',
                        'Source Note',
                        'Notes'
                    ],
                ],
            ],
        ];
    }

    /**
     * Customize the response for a request.
     *
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'PocketExpenseFileUpload');
        $response