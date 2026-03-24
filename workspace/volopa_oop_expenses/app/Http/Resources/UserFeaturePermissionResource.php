<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;

/**
 * API Resource for UserFeaturePermission model
 * 
 * Transforms UserFeaturePermission model data into a consistent JSON response format
 * for API consumers. Includes related user and client information while hiding
 * sensitive internal fields and properly formatting timestamps.
 * 
 * This resource follows the Volopa API response patterns with camelCase field names
 * and proper data type formatting for frontend consumption.
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
            // Primary identifier
            'id' => $this->id,
            
            // User information - target user receiving the permission
            'userId' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'username' => $this->user->username,
                ];
            }),
            
            // Client context information
            'clientId' => $this->client_id,
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            // Feature information
            'featureId' => $this->feature_id,
            'feature' => $this->whenLoaded('feature', function () {
                return [
                    'id' => $this->feature->id,
                    'name' => $this->feature->name ?? 'OOP Expense', // Default name for feature ID 16
                ];
            }),
            
            // Permission grantor information
            'grantorId' => $this->grantor_id,
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name,
                    'username' => $this->grantor->username,
                ];
            }),
            
            // Optional manager information
            'managerUserId' => $this->manager_user_id,
            'managerUser' => $this->whenLoaded('managerUser', function () {
                return $this->manager_user_id ? [
                    'id' => $this->managerUser->id,
                    'name' => $this->managerUser->name,
                    'username' => $this->managerUser->username,
                ] : null;
            }),
            
            // Permission state
            'isEnabled' => (bool) $this->is_enabled,
            'enabled' => (bool) $this->is_enabled, // Alternative field name for consistency
            
            // Timestamps formatted for API consumption
            'createdAt' => $this->create_time ? $this->create_time->toISOString() : null,
            'updatedAt' => $this->update_time ? $this->update_time->toISOString() : null,
            
            // Additional metadata for frontend use
            'permissionType' => 'user_feature_permission',
            'scope' => 'client', // Indicates this is a client-scoped permission
            
            // Conditional fields based on loaded relationships
            'canManage' => $this->when(
                $this->relationLoaded('managerUser'),
                fn() => !is_null($this->manager_user_id)
            ),
            
            // Permission summary for quick reference
            'summary' => $this->when(
                $this->relationLoaded('user') && $this->relationLoaded('feature'),
                fn() => sprintf(
                    '%s has %s access to %s',
                    $this->user->name ?? 'User',
                    $this->is_enabled ? 'enabled' : 'disabled',
                    $this->feature->name ?? 'OOP Expense'
                )
            ),
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
                'timestamp' => now()->toISOString(),
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
        // Set consistent API headers
        $response->header('X-Resource-Type', 'UserFeaturePermission');
        $response->header('X-API-Version', 'v1');
    }

    /**
     * Static method to create a collection response with pagination.
     * 
     * @param \Illuminate\Contracts\Pagination\Paginator|\Illuminate\Support\Collection $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'resource_type' => 'user_feature_permission_collection',
                'api_version' => 'v1',
                'timestamp' => now()->toISOString(),
                'total_items' => method_exists($resource, 'total') ? $resource->total() : $resource->count(),
            ],
        ]);
    }

    /**
     * Create a minimal resource representation for nested responses.
     * 
     * @return array<string, mixed>
     */
    public function toMinimal(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'clientId' => $this->client_id,
            'featureId' => $this->feature_id,
            'isEnabled' => (bool) $this->is_enabled,
            'hasManager' => !is_null($this->manager_user_id),
        ];
    }

    /**
     * Create a summary resource representation for list views.
     * 
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->whenLoaded('user', fn() => $this->user->name, 'Unknown User'),
            'clientId' => $this->client_id,
            'clientName' => $this->whenLoaded('client', fn() => $this->client->name, 'Unknown Client'),
            'featureId' => $this->feature_id,
            'featureName' => $this->whenLoaded('feature', fn() => $this->feature->name, 'OOP Expense'),
            'isEnabled' => (bool) $this->is_enabled,
            'grantedBy' => $this->whenLoaded('grantor', fn() => $this->grantor->name, 'System'),
            'managedBy' => $this->whenLoaded('managerUser', fn() => $this->managerUser->name ?? null, null),
            'createdAt' => $this->create_time ? $this->create_time->toISOString() : null,
        ];
    }

    /**
     * Determine if the permission is currently active and effective.
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool) $this->is_enabled;
    }

    /**
     * Get the permission status as a human-readable string.
     * 
     * @return string
     */
    public function getStatusText(): string
    {
        return $this->is_enabled ? 'Active' : 'Inactive';
    }

    /**
     * Check if this permission has management delegation.
     * 
     * @return bool
     */
    public function hasManagementDelegation(): bool
    {
        return !is_null($this->manager_user_id);
    }

    /**
     * Get permission details for audit trail display.
     * 
     * @return array<string, mixed>
     */
    public function toAuditTrail(): array
    {
        return [
            'permissionId' => $this->id,
            'action' => $this->is_enabled ? 'granted' : 'revoked',
            'targetUser' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ], ['id' => $this->user_id, 'name' => 'Unknown User']),
            'feature' => [
                'id' => $this->feature_id,
                'name' => 'OOP Expense', // Default for feature ID 16
            ],
            'client' => $this->whenLoaded('client', fn() => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ], ['id' => $this->client_id, 'name' => 'Unknown Client']),
            'grantedBy' => $this->whenLoaded('grantor', fn() => [
                'id' => $this->grantor->id,
                'name' => $this->grantor->name,
            ], ['id' => $this->grantor_id, 'name' => 'System']),
            'delegatedTo' => $this->manager_user_id ? $this->whenLoaded('managerUser', fn() => [
                'id' => $this->managerUser->id,
                'name' => $this->managerUser->name,
            ], ['id' => $this->manager_user_id, 'name' => 'Manager']) : null,
            'timestamp' => $this->create_time ? $this->create_time->toISOString() : null,
        ];
    }
}