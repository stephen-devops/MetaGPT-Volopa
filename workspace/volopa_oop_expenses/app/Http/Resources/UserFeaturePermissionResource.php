<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserFeaturePermissionResource
 * 
 * API Resource for transforming UserFeaturePermission model data
 * into consistent JSON responses while hiding internal fields.
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
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Include related model data when loaded
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? '',
                    'username' => $this->user->username ?? '',
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? '',
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name ?? '',
                    'username' => $this->grantor->username ?? '',
                ];
            }),
            
            'manager' => $this->whenLoaded('manager', function () {
                return [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name ?? '',
                    'username' => $this->manager->username ?? '',
                ];
            }),
            
            // TODO: Include feature data when Feature model is available
            'feature' => $this->whenLoaded('feature', function () {
                return [
                    'id' => $this->feature_id,
                    // TODO: Add feature name and other relevant fields once Feature model is implemented
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
                'resource_type' => 'user_feature_permission',
                'api_version' => 'v1',
            ],
        ];
    }
}