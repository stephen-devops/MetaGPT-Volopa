## Code: app/Http/Resources/UserPermissionResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class UserPermissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
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
            
            // Status checks
            'is_enabled_status' => $this->isEnabled(),
            'is_disabled_status' => $this->isDisabled(),
            'has_manager' => $this->hasManager(),
            
            // Permission context
            'belongs_to_user' => $this->when(
                $request->user(),
                function () use ($request) {
                    return $this->belongsToUser($request->user()->id);
                }
            ),
            'belongs_to_client' => $this->when(
                $request->has('client_id'),
                function () use ($request) {
                    return $this->belongsToClient((int) $request->get('client_id'));
                }
            ),
            'is_for_feature' => $this->when(
                $request->has('feature_id'),
                function () use ($request) {
                    return $this->isForFeature((int) $request->get('feature_id'));
                }
            ),
            'was_granted_by' => $this->when(
                $request->user(),
                function () use ($request) {
                    return $this->wasGrantedBy($request->user()->id);
                }
            ),
            'is_managed_by' => $this->when(
                $request->user(),
                function () use ($request) {
                    return $this->isManagedBy($request->user()->id);
                }
            ),
            
            // Age and timing information
            'age_in_days' => $this->getAgeInDaysAttribute(),
            'is_older_than_week' => $this->isOlderThan(7),
            'is_older_than_month' => $this->isOlderThan(30),
            'is_older_than_quarter' => $this->isOlderThan(90),
            
            // Timestamps
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'created_at_formatted' => $this->created_at ? $this->created_at->format('M j, Y g:i A') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_at_formatted' => $this->updated_at ? $this->updated_at->format('M j, Y g:i A') : null,
            
            // Display helpers
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'display_name' => $this->getDisplayName(),
            'permission_summary' => $this->getPermissionSummary(),
            
            // Relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                ];
            }),
            
            'feature' => $this->whenLoaded('feature', function () {
                return [
                    'id' => $this->feature->id,
                    'name' => $this->feature->name,
                    'description' => $this->feature->description ?? null,
                    'is_active' => $this->feature->is_active ?? true,
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name,
                    'email' => $this->grantor->email,
                ];
            }),
            
            'manager' => $this->whenLoaded('manager', function () {
                return $this->manager ? [
                    'id' => $this->manager->id,
                    'name' => $this->manager->name,
                    'email' => $this->manager->email,
                ] : null;
            }),
            
            // Permission hierarchy information
            'hierarchy_level' => $this->getHierarchyLevel(),
            'delegation_chain' => $this->when(
                $request->boolean('include_delegation_chain', false),
                $this->getDelegationChain()
            ),
            
            // Business logic flags
            'can_be_enabled' => $this->canBeEnabled(),
            'can_be_disabled' => $this->canBeDisabled(),
            'can_be_toggled' => $this->canBeToggled(),
            'can_be_revoked' => $this->canBeRevoked(),
            'can_set_manager' => $this->canSetManager(),
            'can_remove_manager' => $this->canRemoveManager(),
            'needs_review' => $this->needsReview(),
            
            // Additional metadata
            'meta' => [
                'permission_type' => $this->getPermissionType(),
                'grant_reason' => $this->getGrantReason(),
                'last_activity' => $this->getLastActivity(),
                'usage_count' => $this->getUsageCount(),
                'is_critical' => $this->isCriticalPermission(),
                'requires_approval' => $this->requiresApproval(),
                'auto_expires' => $this->hasAutoExpiration(),
                'expiration_date' => $this->getExpirationDate(),
                'risk_level' => $this->getRiskLevel(),
                'compliance_notes' => $this->getComplianceNotes(),
            ],
            
            // Links for HATEOAS
            'links' => [
                'self' => route('api.permissions.show', ['permission' => $this->id]),
                'update' => $this->when(
                    $this->canBeModified(),
                    route('api.permissions.update', ['permission' => $this->id])
                ),
                'delete' => $this->when(
                    $this->canBeRevoked(),
                    route('api.permissions.destroy', ['permission' => $this->id])
                ),
                'enable' => $this->when(
                    $this->canBeEnabled(),
                    route('api.permissions.enable', ['permission' => $this->id])
                ),
                'disable' => $this->when(
                    $this->canBeDisabled(),
                    route('api.permissions.disable', ['permission' => $this->id])
                ),
                'set_manager' => $this->when(
                    $this->canSetManager(),
                    route('api.permissions.set-manager', ['permission' => $this->id])
                ),
                'remove_manager' => $this->when(
                    $this->canRemoveManager(),
                    route('api.permissions.remove-manager', ['permission' => $this->id])
                ),
                'audit_trail' => route('api.permissions.audit', ['permission' => $this->id]),
                'usage_history' => route('api.permissions.usage', ['permission' => $this->id]),
            ],
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'timestamp' => now()->toISOString(),
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => app()->getLocale(),
                'resource_type' => 'user_permission',
                'version' => '1.0',
            ],
        ];
    }

    /**
     * Customize the response for a request.
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'user_permission');
        $response->header('X-Resource-Version', '1.0');
        
        if ($this->resource && $this->resource->updated_at) {
            $response->header('Last-Modified', $this->resource->updated_at->toRfc7231String());
            $response->header('ETag', '"' . md5($this->resource->updated_at->timestamp . '_' . $this->resource->id) . '"');
        }
    }

    /**
     * Get status label for display.
     */
    private function getStatusLabel(): string
    {
        return $this->is_enabled ? 'Enabled' : 'Disabled';
    }

    /**
     * Get status color for UI.
     */
    private function getStatusColor(): string
    {
        return $this->is_enabled ? 'success' : 'secondary';
    }

    /**
     * Get display name for the permission.
     */
    private function getDisplayName(): string
    {
        $featureName = $this->feature->name ?? 'Unknown Feature';
        $userName = $this->user->name ?? 'Unknown User';
        $clientName = $this->client->name ?? 'Unknown Client';
        
        return "{$featureName} - {$userName} ({$clientName})";
    }

    /**
     * Get permission summary for quick overview.
     */
    private function getPermissionSummary(): string
    {
        $status = $this->is_enabled ? 'enabled' : 'disabled';
        $featureName = $this->feature->name ?? 'unknown feature';
        $userName = $this->user->name ?? 'unknown user';
        
        return "Permission for {$featureName} is {$status} for {$userName}";
    }

    /**
     * Get hierarchy level (0 = direct grant, 1+ = delegated).
     */
    private function getHierarchyLevel(): int
    {
        // Simple implementation - in a real system, this would trace the delegation chain
        return $this->manager_user_id ? 1 : 0;
    }

    /**
     * Get delegation chain information.
     */
    private function getDelegationChain(): array
    {
        $chain = [];
        
        // Add grantor
        if ($this->grantor) {
            $chain[] = [
                'level' => 0,
                'user_id' => $this->grantor->id,
                'user_name' => $this->grantor->name,
                'role' => 'grantor',
                'granted_at' => $this->created_at ? $this->created_at->toISOString() : null,
            ];
        }
        
        // Add manager if exists
        if ($this->manager) {
            $chain[] = [
                'level' => 1,
                'user_id' => $this->manager->id,
                'user_name' => $this->manager->name,
                'role' => 'manager',
                'assigned_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            ];
        }
        
        return $chain;
    }

    /**
     * Check if permission can be enabled.
     */
    private function canBeEnabled(): bool
    {
        return !$this->is_enabled;
    }

    /**
     * Check if permission can be disabled.
     */
    private function canBeDisabled(): bool
    {
        return $this->is_enabled;
    }

    /**
     * Check if permission can be toggled.
     */
    private function canBeToggled(): bool
    {
        return true;
    }

    /**
     * Check if permission can be revoked.
     */
    private function canBeRevoked(): bool
    {
        return true;
    }

    /**
     * Check if permission can be modified.
     */
    private function canBeModified(): bool
    {
        return true;
    }

    /**
     * Check if manager can be set.
     */
    private function canSetManager(): bool
    {
        return true;
    }

    /**
     * Check if manager can be removed.
     */
    private function canRemoveManager(): bool
    {
        return $this->manager_user_id !== null;
    }

    /**
     * Check if permission needs review.
     */
    private function needsReview(): bool
    {
        // Consider permissions older than 90 days as needing review
        return $this->isOlderThan(90);
    }

    /**
     * Get permission type classification.
     */
    private function getPermissionType(): string
    {
        // This could be enhanced based on feature categorization
        return 'standard';
    }

    /**
     * Get grant reason.
     */
    private function getGrantReason(): ?string
    {
        // This would typically be stored during permission creation
        return null;
    }

    /**
     * Get last activity timestamp.
     */
    private function getLastActivity(): ?string
    {
        return $this->updated_at ? $this->updated_at->toISOString() : null;
    }

    /**
     * Get usage count (how many times this permission was used).
     */
    private function getUsageCount(): int
    {
        // This would typically be tracked in a separate table
        return 0;
    }

    /**
     * Check if this is a critical permission.
     */
    private function isCriticalPermission(): bool
    {
        // This could be based on feature criticality
        return false;
    }

    /**
     * Check if permission requires approval for changes.
     */
    private function requiresApproval(): bool
    {
        return $this->isCriticalPermission();
    }

    /**
     * Check if permission has auto-expiration.
     */
    private function hasAutoExpiration(): bool
    {
        return false;
    }

    /**
     * Get expiration date if applicable.
     */
    private function getExpirationDate(): ?string
    {
        return null;
    }

    /**
     * Get risk level assessment.
     */
    private function getRiskLevel(): string
    {
        return 'low';
    }

    /**
     * Get compliance notes.
     */
    private function getComplianceNotes(): ?string
    {
        return null;
    }

    /**
     * Create a new resource instance for a collection of models.
     */
    public static function collection($resource)
    {
        return parent::collection($resource);
    }

    /**
     * Create a conditional resource.
     */
    public static function make($resource): static
    {
        return new static($resource);
    }

    /**
     * Determine if the resource should be returned.
     */
    public function shouldReturn(): bool
    {
        return $this->resource !== null;
    }

    /**
     * Get the JSON serialization options.
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;
    }

    /**
     * Customize the pagination information for the resource.
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return array_merge($default, [
            'meta' => array_merge($default['meta'] ?? [], [
                'resource_type' => 'user_permission',
                'per_page_options' => [10, 15, 25, 50, 100],
                'sortable_fields' => [
                    'created_at', 'updated_at', 'user_name', 