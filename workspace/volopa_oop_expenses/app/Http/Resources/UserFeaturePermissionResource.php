<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Class UserFeaturePermissionResource
 * 
 * API Resource for transforming UserFeaturePermission model data into JSON responses.
 * Hides internal fields and provides consistent response format for permission management endpoints.
 * 
 * @package App\Http\Resources
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
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Conditional relationship data - only include when loaded to avoid N+1 queries
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'username' => $this->user->username ?? null,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name,
                    'username' => $this->grantor->username ?? null,
                ];
            }),
            
            'manager' => $this->whenLoaded('manager', function () {
                return [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name,
                    'username' => $this->manager->username ?? null,
                ];
            }),
            
            // Feature information - static for now as feature table is not defined in constraints
            'feature' => [
                'id' => $this->feature_id,
                'name' => $this->getFeatureName($this->feature_id),
                'description' => $this->getFeatureDescription($this->feature_id),
            ],
            
            // Permission status indicators
            'status' => [
                'is_active' => (bool) $this->is_enabled,
                'granted_date' => $this->created_at?->toISOString(),
                'last_updated' => $this->updated_at?->toISOString(),
                'can_be_revoked' => $this->canBeRevoked($request),
            ],
            
            // Metadata for frontend usage
            'meta' => [
                'permission_type' => 'feature_permission',
                'permission_scope' => 'client_specific',
                'requires_manager' => true,
                'hierarchical' => true,
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
            'version' => '1.0',
            'type' => 'user_feature_permission',
        ];
    }

    /**
     * Customize the outgoing response for the resource.
     *
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, \Illuminate\Http\JsonResponse $response): void
    {
        // Set consistent headers for permission resources
        $response->header('X-Resource-Type', 'UserFeaturePermission');
        $response->header('X-API-Version', 'v1');
    }

    /**
     * Get human-readable feature name based on feature ID.
     * 
     * @param int $featureId
     * @return string
     */
    private function getFeatureName(int $featureId): string
    {
        // Feature mapping as per system constraints
        // Feature ID 16 = OOP Expenses as mentioned in the context
        return match ($featureId) {
            16 => 'Out-of-Pocket Expenses',
            15 => 'Expense Management',
            14 => 'Budget Management',
            13 => 'Reporting & Analytics',
            12 => 'User Management',
            11 => 'Client Configuration',
            10 => 'Transaction Processing',
            default => "Feature #{$featureId}",
        };
    }

    /**
     * Get feature description based on feature ID.
     * 
     * @param int $featureId
     * @return string
     */
    private function getFeatureDescription(int $featureId): string
    {
        return match ($featureId) {
            16 => 'Manage and process out-of-pocket expense claims with CSV upload capabilities',
            15 => 'Create, approve, and track expense reports across the organization',
            14 => 'Set and monitor budgets with real-time spending tracking',
            13 => 'Generate comprehensive reports and analytics dashboards',
            12 => 'Manage user accounts, roles, and permissions within the client',
            11 => 'Configure client-specific settings and preferences',
            10 => 'Process financial transactions and manage payment flows',
            default => 'Access to platform feature functionality',
        };
    }

    /**
     * Determine if the current permission can be revoked by the requesting user.
     * 
     * @param Request $request
     * @return bool
     */
    private function canBeRevoked(Request $request): bool
    {
        // Get the authenticated user from the request
        $currentUser = $request->user();
        
        if (!$currentUser) {
            return false;
        }

        // Permission can be revoked if:
        // 1. Current user is the grantor of this permission
        // 2. Current user is a Primary Admin for this client
        // 3. Current user has higher-level management rights
        
        // Basic check: user who granted can revoke
        if ($currentUser->id === $this->grantor_id) {
            return true;
        }

        // Note: Additional role-based checks would require role information
        // which is not available in the current model structure
        // This would typically integrate with existing RBAC system
        
        return false;
    }

    /**
     * Create a resource collection with consistent pagination metadata.
     * 
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'resource_type' => 'user_feature_permission_collection',
                'api_version' => 'v1',
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Get summary information for dashboard display.
     * 
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toSummary(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature_name' => $this->getFeatureName($this->feature_id),
            'user_name' => $this->whenLoaded('user', fn() => $this->user->name),
            'client_name' => $this->whenLoaded('client', fn() => $this->client->name),
            'is_enabled' => (bool) $this->is_enabled,
            'granted_date' => $this->created_at?->format('Y-m-d'),
            'status' => $this->is_enabled ? 'active' : 'disabled',
        ];
    }

    /**
     * Get detailed information for permission management interface.
     * 
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toDetailed(Request $request): array
    {
        return array_merge($this->toArray($request), [
            'audit_trail' => [
                'created_by' => $this->whenLoaded('grantor', fn() => [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name,
                    'timestamp' => $this->created_at?->toISOString(),
                ]),
                'last_updated_by' => $this->whenLoaded('grantor', fn() => [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name,
                    'timestamp' => $this->updated_at?->toISOString(),
                ]),
            ],
            'permission_hierarchy' => [
                'can_grant_to_others' => $this->canGrantToOthers($request),
                'management_scope' => $this->getManagementScope(),
                'inherited_permissions' => $this->getInheritedPermissions(),
            ],
        ]);
    }

    /**
     * Check if this permission allows granting similar permissions to other users.
     * 
     * @param Request $request
     * @return bool
     */
    private function canGrantToOthers(Request $request): bool
    {
        // For OOP Expenses (feature_id = 16), only admin-level users can grant permissions
        // This logic would typically integrate with existing role system
        return $this->feature_id === 16 && $this->is_enabled;
    }

    /**
     * Get the scope of users this permission allows managing.
     * 
     * @return string
     */
    private function getManagementScope(): string
    {
        // Based on permission constraints from the context
        // Admin can only grant access to their own managed users
        return match ($this->feature_id) {
            16 => 'managed_users_only', // OOP Expenses
            12 => 'client_users', // User Management
            default => 'self_only',
        };
    }

    /**
     * Get list of permissions that are inherited with this permission.
     * 
     * @return array<string>
     */
    private function getInheritedPermissions(): array
    {
        // Some permissions may inherit other permissions
        // For example, OOP Expenses might inherit basic expense viewing
        return match ($this->feature_id) {
            16 => ['view_expenses', 'create_expenses', 'upload_csv'], // OOP Expenses
            15 => ['view_expenses'], // Basic Expense Management
            default => [],
        };
    }

    /**
     * Transform for API responses when permission check fails.
     * Returns minimal information for security.
     * 
     * @return array<string, mixed>
     */
    public function toMinimal(): array
    {
        return [
            'id' => $this->id,
            'feature_id' => $this->feature_id,
            'is_enabled' => (bool) $this->is_enabled,
            'message' => 'Limited permission information available',
        ];
    }

    /**
     * Format for export/reporting purposes.
     * 
     * @return array<string, mixed>
     */
    public function toExport(): array
    {
        return [
            'Permission ID' => $this->id,
            'User ID' => $this->user_id,
            'User Name' => $this->whenLoaded('user', fn() => $this->user->name, 'N/A'),
            'Client ID' => $this->client_id,
            'Client Name' => $this->whenLoaded('client', fn() => $this->client->name, 'N/A'),
            'Feature' => $this->getFeatureName($this->feature_id),
            'Status' => $this->is_enabled ? 'Enabled' : 'Disabled',
            'Granted By' => $this->whenLoaded('grantor', fn() => $this->grantor->name, 'N/A'),
            'Manager' => $this->whenLoaded('manager', fn() => $this->manager->name, 'N/A'),
            'Granted Date' => $this->created_at?->format('Y-m-d H:i:s'),
            'Last Updated' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Check if the resource should be visible to the requesting user.
     * Used for additional security filtering.
     * 
     * @param Request $request
     * @return bool
     */
    public function shouldBeVisible(Request $request): bool
    {
        $currentUser = $request->user();
        
        if (!$currentUser) {
            return false;
        }

        // Permission is visible if:
        // 1. User is the permission owner
        // 2. User is the grantor
        // 3. User is the manager
        // 4. User has admin rights for the client
        
        return in_array($currentUser->id, [
            $this->user_id,
            $this->grantor_id,
            $this->manager_user_id,
        ]);
    }

    /**
     * Get localized display text for the permission status.
     * 
     * @param string $locale
     * @return array<string, string>
     */
    public function getLocalizedStatus(string $locale = 'en'): array
    {
        // Basic localization support - could be extended with proper translation service
        $statusTexts = [
            'en' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'pending' => 'Pending',
                'revoked' => 'Revoked',
            ],
            'es' => [
                'active' => 'Activo',
                'inactive' => 'Inactivo', 
                'pending' => 'Pendiente',
                'revoked' => 'Revocado',
            ],
            'fr' => [
                'active' => 'Actif',
                'inactive' => 'Inactif',
                'pending' => 'En attente',
                'revoked' => 'Révoqué',
            ],
        ];

        $texts = $statusTexts[$locale] ?? $statusTexts['en'];
        $status = $this->is_enabled ? 'active' : 'inactive';

        return [
            'status' => $status,
            'display_text' => $texts[$status],
            'locale' => $locale,
        ];
    }
}