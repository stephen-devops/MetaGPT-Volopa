<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserFeaturePermissionResource
 * 
 * API resource transformer for UserFeaturePermission model.
 * Shapes the response data for user feature permission endpoints.
 * 
 * @property \App\Models\UserFeaturePermission $resource
 */
class UserFeaturePermissionResource extends JsonResource
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
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'feature_id' => $this->feature_id,
            'grantor_id' => $this->grantor_id,
            'manager_user_id' => $this->manager_user_id,
            'is_enabled' => $this->is_enabled,
            'created_at' => $this->create_time?->toISOString(),
            'updated_at' => $this->update_time?->toISOString(),
            
            // Relationship data (conditionally loaded to prevent N+1 queries)
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? null,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? null,
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name ?? null,
                ];
            }),
            
            'manager' => $this->whenLoaded('manager', function () {
                return $this->manager ? [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name ?? null,
                ] : null;
            }),
            
            // TODO: Add feature relationship data when Feature model is implemented
            'feature' => $this->when($this->relationLoaded('feature'), function () {
                // TODO: Replace with actual Feature model data structure
                return [
                    'id' => $this->feature_id,
                    'name' => 'Feature #' . $this->feature_id, // Placeholder
                ];
            }),
            
            // Computed attributes for client convenience
            'status' => $this->is_enabled ? 'enabled' : 'disabled',
            'has_manager' => !is_null($this->manager_user_id),
            'display_name' => $this->getDisplayName(),
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
                'type' => 'user_feature_permission',
                'version' => '1.0',
            ],
        ];
    }
}