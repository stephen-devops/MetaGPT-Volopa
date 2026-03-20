<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PocketExpenseSourceResource
 * 
 * API Resource for transforming PocketExpenseSourceClientConfig model instances
 * into properly formatted JSON responses. Handles client scoping, soft delete status,
 * and provides clean API output for expense source configurations.
 * 
 * Used for:
 * - Single expense source response transformation
 * - Hiding internal model fields (deleted, delete_time, etc.)
 * - Providing consistent API response format
 * - Supporting both client-specific and global sources
 * 
 * @property-read \App\Models\PocketExpenseSourceClientConfig $resource
 */
class PocketExpenseSourceResource extends JsonResource
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
            'id' => $this->resource->id,
            'uuid' => $this->resource->uuid,
            'client_id' => $this->resource->client_id,
            'name' => $this->resource->name,
            'is_default' => $this->resource->is_default,
            'is_global' => $this->resource->isGlobal(),
            'is_active' => $this->resource->isActive(),
            'metadata_count' => $this->whenLoaded('metadata', function () {
                return $this->resource->metadata->count();
            }, 0),
            'created_at' => $this->resource->create_time?->toISOString(),
            'updated_at' => $this->resource->update_time?->toISOString(),
            
            // Conditional fields based on request context
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->resource->client?->id,
                    'name' => $this->resource->client?->name ?? null,
                ];
            }),
            
            // Include metadata statistics if requested
            'metadata_summary' => $this->when(
                $request->query('include_metadata_stats', false),
                function () {
                    $metadataCount = $this->resource->metadata()->count();
                    return [
                        'total_metadata_entries' => $metadataCount,
                        'can_delete' => $this->resource->canDelete(),
                        'has_usage' => $metadataCount > 0,
                    ];
                }
            ),
            
            // Administrative fields for admin users only
            'admin_fields' => $this->when(
                $request->user()?->can('viewAdminFields', $this->resource) ?? false,
                function () {
                    return [
                        'deleted' => $this->resource->deleted,
                        'delete_time' => $this->resource->delete_time?->toISOString(),
                        'can_delete' => $this->resource->canDelete(),
                        'is_deleted' => $this->resource->isDeleted(),
                    ];
                }
            ),
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
                'resource_type' => 'pocket_expense_source',
                'api_version' => 'v1',
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Customize the response for a single resource.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Http\Response $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // Add custom headers for expense source responses
        $response->header('X-Resource-Type', 'PocketExpenseSource');
        $response->header('X-Resource-UUID', $this->resource->uuid);
        
        // Add cache headers for stable sources
        if ($this->resource->isActive() && !$this->resource->isDeleted()) {
            $response->header('Cache-Control', 'public, max-age=300'); // 5 minutes cache
        }
    }

    /**
     * Create a new resource collection with proper typing.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'collection_type' => 'pocket_expense_sources',
                'api_version' => 'v1',
                'generated_at' => now()->toISOString(),
                'instructions' => [
                    'Global sources are available to all clients',
                    'Client-specific sources are only available to their respective clients',
                    'Default sources are automatically selected in forms',
                    'Deleted sources are hidden unless explicitly requested by admin users',
                ],
            ],
        ]);
    }

    /**
     * Create a minimal resource representation for lightweight responses.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toMinimalArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'uuid' => $this->resource->uuid,
            'name' => $this->resource->name,
            'is_default' => $this->resource->is_default,
            'is_global' => $this->resource->isGlobal(),
        ];
    }

    /**
     * Create a resource representation for select/dropdown options.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toSelectOption(Request $request): array
    {
        return [
            'value' => $this->resource->id,
            'label' => $this->resource->name,
            'uuid' => $this->resource->uuid,
            'is_default' => $this->resource->is_default,
            'is_global' => $this->resource->isGlobal(),
            'disabled' => $this->resource->isDeleted(),
            'group' => $this->resource->isGlobal() ? 'Global Sources' : 'Client Sources',
        ];
    }

    /**
     * Transform the resource for administrative purposes with full details.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toAdminArray(Request $request): array
    {
        return array_merge($this->toArray($request), [
            'internal_fields' => [
                'deleted' => $this->resource->deleted,
                'delete_time' => $this->resource->delete_time?->toISOString(),
                'create_time' => $this->resource->create_time?->toISOString(),
                'update_time' => $this->resource->update_time?->toISOString(),
                'can_delete' => $this->resource->canDelete(),
                'can_restore' => $this->resource->isDeleted(),
                'metadata_count_raw' => $this->resource->metadata()->count(),
            ],
            'relationships' => [
                'client_name' => $this->resource->client?->name ?? 'Global',
                'has_client' => !is_null($this->resource->client_id),
                'metadata_entries' => $this->whenLoaded('metadata', function () {
                    return $this->resource->metadata->count();
                }),
            ],
            'validation_status' => [
                'is_valid_name' => !empty(trim($this->resource->name)),
                'is_within_limits' => strlen($this->resource->name) <= 100,
                'has_valid_uuid' => !empty($this->resource->uuid) && 
                                   preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $this->resource->uuid),
            ],
        ]);
    }

    /**
     * Get the summary statistics for this expense source.
     *
     * @return array<string, mixed>
     */
    public function getUsageStats(): array
    {
        $metadataCount = $this->resource->metadata()->count();
        
        return [
            'total_usage_count' => $metadataCount,
            'is_actively_used' => $metadataCount > 0,
            'can_safely_delete' => $this->resource->canDelete(),
            'usage_level' => match (true) {
                $metadataCount === 0 => 'unused',
                $metadataCount <= 10 => 'low',
                $metadataCount <= 50 => 'medium',
                default => 'high',
            },
            'last_activity' => $this->resource->update_time?->toISOString(),
        ];
    }

    /**
     * Transform the resource for CSV export format.
     *
     * @return array<string, mixed>
     */
    public function toCsvArray(): array
    {
        return [
            'ID' => $this->resource->id,
            'UUID' => $this->resource->uuid,
            'Name' => $this->resource->name,
            'Client ID' => $this->resource->client_id ?? 'Global',
            'Client Name' => $this->resource->client?->name ?? 'Global',
            'Is Default' => $this->resource->is_default ? 'Yes' : 'No',
            'Is Global' => $this->resource->isGlobal() ? 'Yes' : 'No',
            'Status' => $this->resource->isActive() ? 'Active' : 'Deleted',
            'Usage Count' => $this->resource->metadata()->count(),
            'Can Delete' => $this->resource->canDelete() ? 'Yes' : 'No',
            'Created At' => $this->resource->create_time?->format('Y-m-d H:i:s'),
            'Updated At' => $this->resource->update_time?->format('Y-m-d H:i:s'),
            'Deleted At' => $this->resource->delete_time?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    /**
     * Conditional logic to determine when to include client relationship.
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function shouldIncludeClient(Request $request): bool
    {
        // Include client data for admin users or when explicitly requested
        return $request->user()?->can('viewAny', $this->resource::class) ?? false ||
               $request->query('include_client', false) ||
               !$this->resource->isGlobal();
    }

    /**
     * Conditional logic to determine when to include usage statistics.
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function shouldIncludeUsageStats(Request $request): bool
    {
        return $request->query('include_stats', false) ||
               $request->routeIs('admin.*') ||
               $request->user()?->can('viewStatistics', $this->resource) ?? false;
    }

    /**
     * Get conditional fields based on user permissions and request parameters.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    protected function getConditionalFields(Request $request): array
    {
        $fields = [];

        // Add client information if appropriate
        if ($this->shouldIncludeClient($request)) {
            $fields['client_info'] = [
                'client_id' => $this->resource->client_id,
                'client_name' => $this->resource->client?->name ?? 'Global',
                'is_global' => $this->resource->isGlobal(),
            ];
        }

        // Add usage statistics if requested
        if ($this->shouldIncludeUsageStats($request)) {
            $fields['usage_stats'] = $this->getUsageStats();
        }

        // Add administrative fields for privileged users
        if ($request->user()?->can('administrate', $this->resource) ?? false) {
            $fields['admin_info'] = [
                'deleted' => $this->resource->deleted,
                'delete_time' => $this->resource->delete_time?->toISOString(),
                'can_delete' => $this->resource->canDelete(),
                'can_restore' => $this->resource->isDeleted(),
                'internal_id' => $this->resource->id,
            ];
        }

        return $fields;
    }

    /**
     * Validate that the resource is properly loaded before transformation.
     *
     * @return bool
     */
    protected function validateResource(): bool
    {
        return !is_null($this->resource) &&
               !empty($this->resource->uuid) &&
               !empty($this->resource->name) &&
               is_bool($this->resource->deleted) &&
               is_bool($this->resource->is_default);
    }

    /**
     * Handle resource transformation errors gracefully.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Exception $exception
     * @return array<string, mixed>
     */
    protected function handleTransformationError(Request $request, \Exception $exception): array
    {
        \Log::warning('PocketExpenseSourceResource transformation error', [
            'resource_id' => $this->resource?->id ?? 'unknown',
            'resource_uuid' => $this->resource?->uuid ?? 'unknown',
            'error' => $exception->getMessage(),
            'user_id' => $request->user()?->id ?? 'anonymous',
        ]);

        return [
            'id' => $this->resource?->id ?? null,
            'uuid' => $this->resource?->uuid ?? null,
            'name' => $this->resource?->name ?? 'Unknown Source',
            'error' => 'Resource transformation failed',
            'is_active' => false,
        ];
    }
}