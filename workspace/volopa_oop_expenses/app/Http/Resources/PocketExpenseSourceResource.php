<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pocket Expense Source Resource
 * 
 * API Resource for transforming PocketExpenseSourceClientConfig model data
 * into consistent JSON response format. Hides internal fields and shapes
 * output according to API specification.
 * 
 * @mixin \App\Models\PocketExpenseSourceClientConfig
 */
class PocketExpenseSourceResource extends JsonResource
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
            'client_id' => $this->client_id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'is_global' => $this->when(is_null($this->client_id), true, false),
            'is_other' => $this->when($this->name === 'Other' && is_null($this->client_id), true, false),
            'can_edit' => $this->when($this->resource->canEdit(), true, false),
            'can_delete' => $this->when($this->resource->canDelete(), true, false),
            'status' => $this->deleted ? 'deleted' : 'active',
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
            'deleted_at' => $this->when($this->deleted, $this->delete_time?->toISOString()),
            
            // Relationships - only include when loaded to avoid N+1 queries
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            // Metadata count - only include when loaded
            'metadata_count' => $this->whenLoaded('metadata', function () {
                return $this->metadata->count();
            }),
            
            // Additional fields for admin/management views
            'display_name' => $this->getDisplayName(),
            'type' => $this->when($this->is_default, 'default', 'custom'),
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
                'version' => '1.0',
                'resource' => 'pocket_expense_source',
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
        // Set proper cache headers for source configuration data
        $response->header('Cache-Control', 'public, max-age=300'); // 5 minutes cache
    }
}