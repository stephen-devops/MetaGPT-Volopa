## Code: app/Http/Resources/UserFeaturePermissionResource.php

```php
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
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
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
            
            'feature' => $this->whenLoaded('feature', function () {
                return [
                    'id' => $this->feature->id,
                    'name' => $this->feature->name ?? 'Unknown Feature',
                    'code' => $this->feature->code ?? 'unknown',
                    'description' => $this->feature->description ?? null,
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name ?? 'Unknown User',
                    'email' => $this->grantor->email ?? 'no-email@example.com',
                    'role' => $this->grantor->role ?? 'user',
                ];
            }),
            
            'manager' => $this->whenLoaded('manager', function () {
                return [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name ?? 'Unknown User',
                    'email' => $this->manager->email ?? 'no-email@example.com',
                    'role' => $this->manager->role ?? 'user',
                ];
            }),
            
            // Additional computed fields
            'status' => $this->is_enabled ? 'active' : 'inactive',
            'permission_type' => $this->getPermissionType(),
            'granted_date' => $this->created_at?->format('Y-m-d'),
            'days_since_granted' => $this->created_at ? $this->created_at->diffInDays(now()) : null,
            
            // Permission management information
            'can_be_updated' => $this->canBeUpdated(),
            'can_be_revoked' => $this->canBeRevoked(),
            
            // Audit information
            'audit_info' => [
                'granted_by' => $this->grantor_id,
                'managed_by' => $this->manager_user_id,
                'granted_at' => $this->created_at?->toISOString(),
                'last_updated_at' => $this->updated_at?->toISOString(),
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
                'resource_type' => 'user_feature_permission',
                'api_version' => 'v1',
                'generated_at' => now()->toISOString(),
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
        $response->header('X-Resource-Type', 'UserFeaturePermission');
        $response->header('X-API-Version', 'v1');
    }

    /**
     * Get the permission type based on the feature and context.
     *
     * @return string
     */
    private function getPermissionType(): string
    {
        // Default permission type
        $permissionType = 'standard';
        
        try {
            // Check if this is related to OOP expenses feature (feature_id = 1)
            if ($this->feature_id === 1) {
                $permissionType = 'oop_expenses';
            }
            
            // Check if user is self-managed (user is their own manager)
            if ($this->user_id === $this->manager_user_id) {
                $permissionType .= '_self_managed';
            }
            
            // Check if permission is system-granted (grantor same as user)
            if ($this->grantor_id === $this->user_id) {
                $permissionType = 'system_granted';
            }
            
        } catch (\Exception $e) {
            // Default to standard if any error occurs
            $permissionType = 'standard';
        }
        
        return $permissionType;
    }

    /**
     * Check if this permission can be updated by the current context.
     *
     * @return bool
     */
    private function canBeUpdated(): bool
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return false;
            }
            
            // Primary Admin can update any permission
            if ($user->isPrimaryAdmin()) {
                return true;
            }
            
            // Admin can update permissions they granted or manage
            if ($user->isAdmin()) {
                return $this->grantor_id === $user->id || $this->manager_user_id === $user->id;
            }
            
            return false;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if this permission can be revoked by the current context.
     *
     * @return bool
     */
    private function canBeRevoked(): bool
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return false;
            }
            
            // Primary Admin can revoke any permission
            if ($user->isPrimaryAdmin()) {
                return true;
            }
            
            // Admin can revoke permissions they granted or manage
            if ($user->isAdmin()) {
                return $this->grantor_id === $user->id || $this->manager_user_id === $user->id;
            }
            
            return false;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a collection of resources.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'resource_type' => 'user_feature_permission_collection',
                'api_version' => 'v1',
                'generated_at'