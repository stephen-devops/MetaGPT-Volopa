<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseSourceResource
 * 
 * API Resource for transforming PocketExpenseSourceClientConfig models into JSON responses.
 * Shapes the output for expense source data and hides internal fields as per platform standards.
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
            'is_default' => (bool) $this->is_default,
            'is_active' => $this->isActive(),
            'is_global' => $this->client_id === null,
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
            
            // Conditional fields - only include if relationships are loaded
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id ?? null,
                    'name' => $this->client->name ?? null,
                ];
            }),
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
                'can_edit' => $this->canBeEdited(),
                'can_delete' => $this->canBeDeleted(),
            ],
        ];
    }

    /**
     * Determine if the expense source can be edited.
     * Global 'Other' source cannot be edited as per constraints.
     *
     * @return bool
     */
    private function canBeEdited(): bool
    {
        // Global 'Other' record cannot be edited as per constraints
        return !$this->isGlobalOther();
    }

    /**
     * Determine if the expense source can be deleted.
     * Global 'Other' source cannot be deleted as per constraints.
     *
     * @return bool
     */
    private function canBeDeleted(): bool
    {
        // Global 'Other' record cannot be deleted as per constraints
        return !$this->isGlobalOther() && $this->isActive();
    }
}