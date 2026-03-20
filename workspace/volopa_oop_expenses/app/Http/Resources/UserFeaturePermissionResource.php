<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserFeaturePermissionResource
 * 
 * API resource for transforming UserFeaturePermission model data into JSON responses.
 * Shapes the output to hide internal implementation details and provide consistent formatting.
 * Follows Laravel API resource pattern with relationship loading and conditional data inclusion.
 * 
 * @property-read \App\Models\UserFeaturePermission $resource
 */
class UserFeaturePermissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
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
            'has_manager' => $this->hasManager(),
            'is_active' => $this->isActive(),
            'status' => $this->is_enabled ? 'enabled' : 'disabled',
            'descriptive_name' => $this->descriptive_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Include related user information when loaded
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? 'Unknown User',
                    'email' => $this->user->email ?? null,
                ];
            }),
            
            // Include client information when loaded
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                    'code' => $this->client->code ?? null,
                ];
            }),
            
            // Include grantor information when loaded
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name ?? 'Unknown User',
                    'email' => $this->grantor->email ?? null,
                ];
            }),
            
            // Include manager information when loaded and exists
            'manager' => $this->whenLoaded('manager', function () {
                if ($this->manager) {
                    return [
                        'id' => $this->manager->id,
                        'name' => $this->manager->name ?? 'Unknown User',
                        'email' => $this->manager->email ?? null,
                    ];
                }
                return null;
            }),
            
            // Feature information (if available)
            'feature' => $this->when($this->feature_id, function () {
                // Since we don't have a Feature model in the context, 
                // we'll provide basic feature identification
                return [
                    'id' => $this->feature_id,
                    'name' => $this->getFeatureName($this->feature_id),
                ];
            }),
            
            // Permission management information
            'permission_details' => [
                'granted_at' => $this->created_at,
                'last_modified_at' => $this->updated_at,
                'can_be_managed' => $this->hasManager(),
                'delegation_enabled' => !is_null($this->manager_user_id),
            ],
            
            // Audit information (conditionally included for admin users)
            $this->mergeWhen($request->user()?->isAdmin() ?? false, [
                'audit_trail' => [
                    'granted_by_user_id' => $this->grantor_id,
                    'managed_by_user_id' => $this->manager_user_id,
                    'created_timestamp' => $this->created_at,
                    'updated_timestamp' => $this->updated_at,
                ],
            ]),
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param \Illuminate\Http\Request $request
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
     * Customize the response for a request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Http\Response $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Set custom headers for permission resources
        $response->header('X-Resource-Type', 'UserFeaturePermission');
        $response->header('X-Permission-Status', $this->is_enabled ? 'enabled' : 'disabled');
    }

    /**
     * Get a human-readable feature name based on feature ID.
     * This is a placeholder method since we don't have a Feature model in the context.
     *
     * @param int $featureId
     * @return string
     */
    protected function getFeatureName(int $featureId): string
    {
        // This would typically query a features table or use a feature service
        // For now, we'll return a generic name based on common feature IDs
        return match ($featureId) {
            1 => 'Pocket Expenses Management',
            2 => 'User Permission Management', 
            3 => 'CSV Batch Upload',
            4 => 'Expense Approval',
            5 => 'Financial Reporting',
            default => 'Feature ' . $featureId,
        };
    }

    /**
     * Create a collection resource for multiple UserFeaturePermission instances.
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
                'timestamp' => now()->toISOString(),
                'total_permissions' => $resource->count(),
                'enabled_permissions' => $resource->where('is_enabled', true)->count(),
                'disabled_permissions' => $resource->where('is_enabled', false)->count(),
            ],
        ]);
    }

    /**
     * Transform the resource for a summary view (minimal data).
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'feature_id' => $this->feature_id,
            'feature_name' => $this->getFeatureName($this->feature_id),
            'is_enabled' => $this->is_enabled,
            'status' => $this->is_enabled ? 'enabled' : 'disabled',
            'has_manager' => $this->hasManager(),
            'granted_at' => $this->created_at,
        ];
    }

    /**
     * Transform the resource for a detailed admin view.
     *
     * @return array<string, mixed>
     */
    public function toAdminView(): array
    {
        $baseData = $this->toArray(request());
        
        return array_merge($baseData, [
            'system_information' => [
                'internal_id' => $this->id,
                'database_timestamps' => [
                    'created_at' => $this->created_at,
                    'updated_at' => $this->updated_at,
                ],
                'permission_hierarchy' => [
                    'grantor_user_id' => $this->grantor_id,
                    'target_user_id' => $this->user_id,
                    'delegated_manager_id' => $this->manager_user_id,
                ],
                'validation_status' => [
                    'is_valid_permission' => $this->isActive(),
                    'has_delegation' => $this->hasManager(),
                    'permission_scope' => 'client_scoped',
                ],
            ],
            'debug_information' => [
                'model_class' => get_class($this->resource),
                'resource_class' => self::class,
                'loaded_relationships' => array_keys($this->resource->getRelations()),
            ],
        ]);
    }

    /**
     * Get permissions grouped by status.
     *
     * @param \Illuminate\Database\Eloquent\Collection $permissions
     * @return array<string, mixed>
     */
    public static function getGroupedByStatus($permissions): array
    {
        $grouped = $permissions->groupBy(function ($permission) {
            return $permission->is_enabled ? 'enabled' : 'disabled';
        });

        return [
            'enabled' => self::collection($grouped->get('enabled', collect())),
            'disabled' => self::collection($grouped->get('disabled', collect())),
            'summary' => [
                'total_permissions' => $permissions->count(),
                'enabled_count' => $grouped->get('enabled', collect())->count(),
                'disabled_count' => $grouped->get('disabled', collect())->count(),
            ],
        ];
    }

    /**
     * Get permissions grouped by feature.
     *
     * @param \Illuminate\Database\Eloquent\Collection $permissions
     * @return array<string, mixed>
     */
    public static function getGroupedByFeature($permissions): array
    {
        $grouped = $permissions->groupBy('feature_id');
        $result = [];

        foreach ($grouped as $featureId => $featurePermissions) {
            $result[] = [
                'feature_id' => $featureId,
                'feature_name' => (new self(new \App\Models\UserFeaturePermission()))->getFeatureName($featureId),
                'permissions' => self::collection($featurePermissions),
                'summary' => [
                    'total_users' => $featurePermissions->count(),
                    'enabled_users' => $featurePermissions->where('is_enabled', true)->count(),
                    'disabled_users' => $featurePermissions->where('is_enabled', false)->count(),
                ],
            ];
        }

        return $result;
    }

    /**
     * Create a minimal resource for dropdown/select options.
     *
     * @return array<string, mixed>
     */
    public function toSelectOption(): array
    {
        return [
            'value' => $this->id,
            'label' => sprintf(
                '%s - %s (%s)',
                $this->user->name ?? 'User #' . $this->user_id,
                $this->getFeatureName($this->feature_id),
                $this->is_enabled ? 'Enabled' : 'Disabled'
            ),
            'is_enabled' => $this->is_enabled,
            'feature_id' => $this->feature_id,
            'user_id' => $this->user_id,
        ];
    }
}