## Code: app/Http/Resources/UserFeaturePermissionResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * UserFeaturePermissionResource
 * 
 * API Resource for transforming UserFeaturePermission model responses.
 * Shapes output data structure and hides internal model fields for API responses
 * following Laravel best practices with proper data transformation.
 * 
 * Response Structure:
 * - Exposes essential permission data for frontend consumption
 * - Includes related user, client, feature, grantor, and manager information
 * - Formats timestamps consistently
 * - Hides sensitive internal fields and database specifics
 * - Provides clear permission status and metadata
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
            'is_enabled' => (bool) $this->is_enabled,
            'status' => $this->is_enabled ? 'active' : 'inactive',
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            
            // Related user information
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name ?? 'Unknown User',
                'email' => $this->user?->email,
                'role' => $this->user?->role ?? 'Unknown Role',
            ],
            
            // Related client information
            'client' => [
                'id' => $this->client?->id,
                'name' => $this->client?->name ?? 'Unknown Client',
                'code' => $this->client?->code,
            ],
            
            // Related feature information
            'feature' => [
                'id' => $this->feature?->id,
                'name' => $this->feature?->name ?? 'Unknown Feature',
                'code' => $this->feature?->code,
                'description' => $this->feature?->description,
            ],
            
            // Related grantor information
            'grantor' => [
                'id' => $this->grantor?->id,
                'name' => $this->grantor?->name ?? 'Unknown Grantor',
                'email' => $this->grantor?->email,
                'role' => $this->grantor?->role ?? 'Unknown Role',
            ],
            
            // Related manager information
            'manager' => [
                'id' => $this->manager?->id,
                'name' => $this->manager?->name ?? 'Unknown Manager',
                'email' => $this->manager?->email,
                'role' => $this->manager?->role ?? 'Unknown Role',
            ],
            
            // Permission metadata
            'permission_info' => [
                'granted_at' => $this->created_at ? $this->created_at->toISOString() : null,
                'granted_by' => $this->grantor?->name ?? 'Unknown Grantor',
                'managed_by' => $this->manager?->name ?? 'Unknown Manager',
                'is_active' => (bool) $this->is_enabled,
                'can_be_revoked' => $this->canBeRevoked(),
            ],
            
            // Timestamps in human-readable format
            'dates' => [
                'created' => [
                    'iso' => $this->created_at ? $this->created_at->toISOString() : null,
                    'human' => $this->created_at ? $this->created_at->diffForHumans() : null,
                    'formatted' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
                ],
                'updated' => [
                    'iso' => $this->updated_at ? $this->updated_at->toISOString() : null,
                    'human' => $this->updated_at ? $this->updated_at->diffForHumans() : null,
                    'formatted' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
                ],
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
     * Determine if the permission can be revoked by the current user.
     *
     * @return bool
     */
    private function canBeRevoked(): bool
    {
        $currentUser = auth()->user();
        
        if (!$currentUser) {
            return false;
        }

        // Primary Administrator can revoke any permission
        if ($currentUser->role === 'Primary Administrator') {
            return true;
        }

        // Administrator can revoke permissions they manage
        if ($currentUser->role === 'Administrator' && $this->manager_user_id === $currentUser->id) {
            return true;
        }

        // Business User and Card User cannot revoke permissions
        return false;
    }

    /**
     * Create a collection resource.
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
                'generated_at' => now()->toISOString(),
                'total_count' => $resource instanceof \Illuminate\Pagination\LengthAwarePaginator 
                    ? $resource->total() 
                    : $resource->count(),
            ],
        ]);
    }

    /**
     * Resolve the resource to an array when used conditionally.
     *
     * @param Request|null $request
     * @return array<string, mixed>
     */
    public function resolve($request = null): array
    {
        // Ensure the resource is only resolved when the model exists
        if (!$this->resource) {
            return [];
        }

        return parent::resolve($request);
    }

    /**
     * Determine if the resource should be returned.
     *
     * @param Request $request
     * @return bool
     */
    public function shouldBeReturned(Request $request): bool
    {
        // Only return the resource if the permission exists and user has access
        if (!$this->resource || !$this->id) {
            return false;
        }

        $currentUser = auth()->user();
        if (!$currentUser || !$currentUser->client_id) {
            return false;
        }