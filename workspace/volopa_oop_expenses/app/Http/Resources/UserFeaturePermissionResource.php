<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User Feature Permission Resource
 * 
 * API Resource for transforming UserFeaturePermission model instances into JSON responses.
 * Shapes the response data and hides internal fields for API consumption.
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
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'client_id' => $this->resource->client_id,
            'feature_id' => $this->resource->feature_id,
            'grantor_id' => $this->resource->grantor_id,
            'manager_user_id' => $this->resource->manager_user_id,
            'is_enabled' => $this->resource->is_enabled,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
            
            // Relationships - only include when loaded to avoid N+1 queries
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->resource->user->id,
                    'name' => $this->resource->user->name ?? null,
                ];
            }),
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->resource->client->id,
                    'name' => $this->resource->client->name ?? null,
                ];
            }),
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->resource->grantor->id,
                    'name' => $this->resource->grantor->name ?? null,
                ];
            }),
            'manager' => $this->whenLoaded('manager', function () {
                return [
                    'id' => $this->resource->manager->id,
                    'name' => $this->resource->manager->name ?? null,
                ];
            }),
            
            // Computed attributes for convenience
            'status' => $this->resource->is_enabled ? 'enabled' : 'disabled',
            'can_manage' => $this->resource->isActive(),
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
                'resource_type' => 'user_feature_permission',
            ],
        ];
    }
}