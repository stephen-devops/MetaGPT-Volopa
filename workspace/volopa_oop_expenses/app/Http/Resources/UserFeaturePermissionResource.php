<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

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
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
            
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? '',
                    'email' => $this->user->email ?? '',
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? '',
                    'is_active' => $this->client->is_active ?? false,
                ];
            }),
            
            'feature' => $this->whenLoaded('feature', function () {
                return [
                    'id' => $this->feature->id,
                    'name' => $this->feature->name ?? '',
                    'display_name' => $this->feature->display_name ?? '',
                    'description' => $this->feature->description ?? '',
                    'is_active' => $this->feature->is_active ?? false,
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name ?? '',
                    'email' => $this->grantor->email ?? '',
                ];
            }),
            
            'manager' => $this->whenLoaded('manager', function () {
                return [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name ?? '',
                    'email' => $this->manager->email ?? '',
                ];
            }),
            
            // Computed fields
            'status' => $this->getStatusText(),
            'granted_at' => $this->created_at?->toISOString(),
            'granted_by' => $this->whenLoaded('grantor', function () {
                return $this->grantor->name ?? 'Unknown';
            }),
            'managed_by' => $this->whenLoaded('manager', function () {
                return $this->manager->name ?? 'Unknown';
            }),
            
            // Permission metadata
            'permission_meta' => [
                'can_be_revoked' => $this->canBeRevoked(),
                'can_be_enabled' => $this->canBeEnabled(),
                'can_be_disabled' => $this->canBeDisabled(),
                'is_expired' => $this->isExpired(),
                'days_since_granted' => $this->getDaysSinceGranted(),
                'granted_by_current_user' => $this->isGrantedByCurrentUser($request),
                'managed_by_current_user' => $this->isManagedByCurrentUser($request),
            ],
        ];
    }

    /**
     * Get the status text for the permission.
     *
     * @return string
     */
    private function getStatusText(): string
    {
        if ($this->deleted_at) {
            return 'revoked';
        }
        
        if (!$this->is_enabled) {
            return 'disabled';
        }
        
        return 'active';
    }

    /**
     * Check if the permission can be revoked.
     *
     * @return bool
     */
    private function canBeRevoked(): bool
    {
        return !$this->deleted_at && $this->is_enabled;
    }

    /**
     * Check if the permission can be enabled.
     *
     * @return bool
     */
    private function canBeEnabled(): bool
    {
        return !$this->deleted_at && !$this->is_enabled;
    }

    /**
     * Check if the permission can be disabled.
     *
     * @return bool
     */
    private function canBeDisabled(): bool
    {
        return !$this->deleted_at && $this->is_enabled;
    }

    /**
     * Check if the permission is expired (placeholder for future implementation).
     *
     * @return bool
     */
    private function isExpired(): bool
    {
        // This could be implemented based on business rules
        // For now, permissions don't expire
        return false;
    }

    /**
     * Get the number of days since the permission was granted.
     *
     * @return int
     */
    private function getDaysSinceGranted(): int
    {
        if (!$this->created_at) {
            return 0;
        }
        
        return (int) $this->created_at->diffInDays(Carbon::now());
    }

    /**
     * Check if the permission was granted by the current user.
     *
     * @param Request $request
     * @return bool
     */
    private function isGrantedByCurrentUser(Request $request): bool
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            return false;
        }
        
        return $this->grantor_id === $currentUser->id;
    }

    /**
     * Check if the permission is managed by the current user.
     *
     * @param Request $request
     * @return bool
     */
    private function isManagedByCurrentUser(Request $request): bool
    {
        $currentUser = $request->user();
        if (!$currentUser) {
            return false;
        }
        
        return $this->manager_user_id === $currentUser->id;
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
        $response->header('X-Resource-Type', 'UserFeaturePermission');
        $response->header('X-Resource-Version', '1.0');
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
                'version' => '1.0',
                'timestamp' => Carbon::now()->toISOString(),
            ],
        ];
    }

    /**
     * Create a new resource collection.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'resource_type' => 'user_feature_permission_collection',
                'version' => '1.0',
                'timestamp' => Carbon::now()->toISOString(),
            ],
        ]);
    }
}