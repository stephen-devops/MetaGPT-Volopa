<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * API Resource for transforming PocketExpenseSourceClientConfig model responses.
 * Shapes expense source configuration data for API consumption while hiding internal fields.
 * 
 * @property \App\Models\PocketExpenseSourceClientConfig $resource
 */
class PocketExpenseSourceResource extends JsonResource
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
            'uuid' => $this->uuid,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'is_active' => !$this->deleted, // Convert deleted flag to active status for API
            'client_id' => $this->client_id,
            'is_global' => $this->client_id === null, // Indicates if this is a global source like 'Other'
            'created_at' => $this->formatDateTime($this->create_time),
            'updated_at' => $this->formatDateTime($this->update_time),
            
            // Conditional fields - only include if not null/empty
            'deleted_at' => $this->when($this->deleted, function () {
                return $this->formatDateTime($this->delete_time);
            }),
            
            // Related data when loaded
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? 'Unknown Client',
                ];
            }),
            
            // Metadata about the source
            'metadata' => [
                'can_edit' => $this->canEdit(),
                'can_delete' => $this->canDelete(),
                'usage_count' => $this->when($this->relationLoaded('metadata'), function () {
                    return $this->metadata->count();
                }),
            ],
            
            // Timestamps in multiple formats for frontend flexibility
            'timestamps' => [
                'created_at' => [
                    'iso' => $this->formatDateTime($this->create_time, 'c'), // ISO 8601
                    'human' => $this->formatDateTime($this->create_time, 'M d, Y'),
                    'relative' => $this->getRelativeTime($this->create_time),
                ],
                'updated_at' => [
                    'iso' => $this->formatDateTime($this->update_time, 'c'),
                    'human' => $this->formatDateTime($this->update_time, 'M d, Y'),
                    'relative' => $this->getRelativeTime($this->update_time),
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
                'resource_type' => 'pocket_expense_source',
                'version' => '1.0',
                'generated_at' => now()->toISOString(),
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
        $response->header('X-Resource-Type', 'PocketExpenseSource');
        $response->header('X-Resource-Version', '1.0');
    }

    /**
     * Determine if the expense source can be edited.
     * Global 'Other' source cannot be edited as per system constraints.
     *
     * @return bool
     */
    private function canEdit(): bool
    {
        // Global 'Other' record (client_id = NULL) is not editable as per constraints
        if ($this->client_id === null && strtolower($this->name) === 'other') {
            return false;
        }
        
        // Soft-deleted sources cannot be edited
        if ($this->deleted) {
            return false;
        }
        
        return true;
    }

    /**
     * Determine if the expense source can be deleted.
     * Global 'Other' source cannot be deleted as per system constraints.
     *
     * @return bool
     */
    private function canDelete(): bool
    {
        // Global 'Other' record (client_id = NULL) is not deletable as per constraints
        if ($this->client_id === null && strtolower($this->name) === 'other') {
            return false;
        }
        
        // Already deleted sources cannot be deleted again
        if ($this->deleted) {
            return false;
        }
        
        return true;
    }

    /**
     * Format datetime with null safety and default format.
     *
     * @param mixed $datetime
     * @param string $format
     * @return string|null
     */
    private function formatDateTime($datetime, string $format = 'Y-m-d H:i:s'): ?string
    {
        if ($datetime === null) {
            return null;
        }
        
        try {
            if (is_string($datetime)) {
                $carbon = Carbon::parse($datetime);
            } elseif ($datetime instanceof Carbon) {
                $carbon = $datetime;
            } elseif ($datetime instanceof \DateTime) {
                $carbon = Carbon::instance($datetime);
            } else {
                return null;
            }
            
            return $carbon->format($format);
        } catch (\Exception $e) {
            // Log the error but don't break the API response
            \Log::warning('Failed to format datetime in PocketExpenseSourceResource', [
                'datetime' => $datetime,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get human-readable relative time.
     *
     * @param mixed $datetime
     * @return string|null
     */
    private function getRelativeTime($datetime): ?string
    {
        if ($datetime === null) {
            return null;
        }
        
        try {
            if (is_string($datetime)) {
                $carbon = Carbon::parse($datetime);
            } elseif ($datetime instanceof Carbon) {
                $carbon = $datetime;
            } elseif ($datetime instanceof \DateTime) {
                $carbon = Carbon::instance($datetime);
            } else {
                return null;
            }
            
            return $carbon->diffForHumans();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create a resource collection with pagination support.
     * Overrides parent method to provide consistent pagination metadata.
     *
     * @param mixed $resource
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        return parent::collection($resource)->additional([
            'meta' => [
                'resource_type' => 'pocket_expense_source_collection',
                'version' => '1.0',
                'constraints' => [
                    'max_active_sources_per_client' => 20,
                    'global_other_source_immutable' => true,
                    'soft_delete_enabled' => true,
                ],
            ],
        ]);
    }

    /**
     * Get the resource representation for API responses when the resource is empty/null.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toResponse($request)
    {
        if ($this->resource === null) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'resource_type' => 'pocket_expense_source',
                    'version' => '1.0',
                    'message' => 'Resource not found',
                ],
            ], 404);
        }

        return parent::toResponse($request);
    }

    /**
     * Determine if the resource should be wrapped.
     * 
     * @return string|null
     */
    public static function wrap(): ?string
    {
        return 'expense_source';
    }

    /**
     * Get array representation for dropdown/select lists.
     * Simplified format for UI components.
     *
     * @return array<string, mixed>
     */
    public function toSelectArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'value' => $this->id, // For compatibility with select components
            'label' => $this->name, // For compatibility with select components
            'is_default' => $this->is_default,
            'is_active' => !$this->deleted,
            'is_global' => $this->client_id === null,
            'disabled' => $this->deleted,
        ];
    }

    /**
     * Get a minimal representation for nested inclusion in other resources.
     * Used when including expense source data in expense resources.
     *
     * @return array<string, mixed>
     */
    public function toMinimal(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'is_global' => $this->client_id === null,
        ];
    }

    /**
     * Get detailed representation for single resource views.
     * Includes all available fields and computed values.
     *
     * @return array<string, mixed>
     */
    public function toDetailed(): array
    {
        return array_merge($this->toArray(request()), [
            'system_info' => [
                'is_system_default' => $this->isSystemDefault(),
                'creation_method' => $this->getCreationMethod(),
                'modification_history' => $this->whenLoaded('auditLogs', function () {
                    return $this->auditLogs->map(function ($log) {
                        return [
                            'action' => $log->action,
                            'user_id' => $log->user_id,
                            'timestamp' => $log->created_at->toISOString(),
                        ];
                    });
                }),
            ],
            'constraints' => [
                'can_edit' => $this->canEdit(),
                'can_delete' => $this->canDelete(),
                'is_deletable_reason' => $this->getDeletabilityReason(),
            ],
        ]);
    }

    /**
     * Determine if this is a system default source.
     *
     * @return bool
     */
    private function isSystemDefault(): bool
    {
        $systemDefaults = ['Cash', 'Corporate Card', 'Personal Card', 'Other'];
        return in_array($this->name, $systemDefaults, true);
    }

    /**
     * Get the creation method for this source.
     *
     * @return string
     */
    private function getCreationMethod(): string
    {
        if ($this->client_id === null && strtolower($this->name) === 'other') {
            return 'system_global';
        }
        
        if (in_array($this->name, ['Cash', 'Corporate Card', 'Personal Card'], true)) {
            return 'system_default';
        }
        
        return 'user_created';
    }

    /**
     * Get the reason why a source cannot be deleted (if applicable).
     *
     * @return string|null
     */
    private function getDeletabilityReason(): ?string
    {
        if ($this->client_id === null && strtolower($this->name) === 'other') {
            return 'Global Other source cannot be deleted per system constraints';
        }
        
        if ($this->deleted) {
            return 'Source is already soft-deleted';
        }
        
        // Check if source is in use (when metadata relationship is loaded)
        if ($this->relationLoaded('metadata') && $this->metadata->count() > 0) {
            return 'Source is currently in use by ' . $this->metadata->count() . ' expense(s)';
        }
        
        return null;
    }

    /**
     * Convert the resource to array for CSV export.
     *
     * @return array<string, mixed>
     */
    public function toCsvArray(): array
    {
        return [
            'ID' => $this->id,
            'UUID' => $this->uuid,
            'Name' => $this->name,
            'Client ID' => $this->client_id ?? 'Global',
            'Is Default' => $this->is_default ? 'Yes' : 'No',
            'Is Active' => $this->deleted ? 'No' : 'Yes',
            'Is Global' => $this->client_id === null ? 'Yes' : 'No',
            'Can Edit' => $this->canEdit() ? 'Yes' : 'No',
            'Can Delete' => $this->canDelete() ? 'Yes' : 'No',
            'Created At' => $this->formatDateTime($this->create_time, 'Y-m-d H:i:s'),
            'Updated At' => $this->formatDateTime($this->update_time, 'Y-m-d H:i:s'),
            'Deleted At' => $this->deleted ? $this->formatDateTime($this->delete_time, 'Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Get resource data with applied filters for specific contexts.
     *
     * @param string $context
     * @return array<string, mixed>
     */
    public function forContext(string $context): array
    {
        return match ($context) {
            'dropdown' => $this->toSelectArray(),
            'minimal' => $this->toMinimal(),
            'detailed' => $this->toDetailed(),
            'csv' => $this->toCsvArray(),
            'api' => $this->toArray(request()),
            default => $this->toArray(request()),
        };
    }

    /**
     * Determine if the resource has sensitive data that should be logged.
     *
     * @return bool
     */
    public function hasSensitiveData(): bool
    {
        // Expense source configuration generally doesn't contain sensitive data
        // but we should log access to global sources for audit purposes
        return $this->client_id === null;
    }

    /**
     * Get the cache key for this resource.
     *
     * @param string $context
     * @return string
     */
    public function getCacheKey(string $context = 'default'): string
    {
        $baseKey = 'pocket_expense_source:' . $this->id;
        $versionKey = md5($this->update_time?->toISOString() ?? '');
        
        return "{$baseKey}:{$context}:{$versionKey}";
    }

    /**
     * Transform the resource for API versioning compatibility.
     *
     * @param string $version
     * @return array<string, mixed>
     */
    public function forVersion(string $version): array
    {
        return match ($version) {
            'v1' => $this->toArray(request()),
            'v2' => array_merge($this->toArray(request()), [
                'enhanced_metadata' => true,
                'api_version' => 'v2',
            ]),
            default => $this->toArray(request()),
        };
    }
}