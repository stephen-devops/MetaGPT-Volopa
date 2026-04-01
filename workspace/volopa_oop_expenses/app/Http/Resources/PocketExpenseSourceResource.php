<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseSourceResource
 * 
 * API resource transformer for PocketExpenseSourceClientConfig responses.
 * Shapes the output data and hides internal fields from API consumers.
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
            'is_global' => $this->isGlobalOther(),
            'is_active' => $this->isActive(),
            'display_name' => $this->getDisplayName(),
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
            'deleted_at' => $this->when($this->isDeleted(), $this->delete_time?->toISOString()),
            
            // Relationships
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                ];
            }),
            
            // Metadata
            'metadata' => [
                'can_edit' => $this->when(
                    !$this->isGlobalOther(),
                    true,
                    false
                ),
                'can_delete' => $this->when(
                    !$this->isGlobalOther() && $this->isActive(),
                    true,
                    false
                ),
                'usage_count' => $this->whenCounted('expenses'),
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
                'resource_type' => 'pocket_expense_source',
                'api_version' => '1.0',
            ],
        ];
    }
}