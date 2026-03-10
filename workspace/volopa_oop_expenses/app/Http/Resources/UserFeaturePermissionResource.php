<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

/**
 * UserFeaturePermissionResource
 * 
 * API resource for transforming UserFeaturePermission models into JSON responses.
 * This resource shapes the output of user feature permission data for API consumers,
 * hiding internal fields and providing computed attributes. Follows the mental model:
 * Controller -> Service -> Model -> API Resource -> JSON with correct status codes.
 * 
 * Key responsibilities:
 * - Transform UserFeaturePermission model data into API-friendly format
 * - Hide sensitive internal fields and database implementation details
 * - Include computed attributes and relationship data
 * - Provide consistent JSON structure across all permission endpoints
 * - Support conditional field inclusion based on request context
 * - Format dates and timestamps according to API standards
 * - Include permission status and metadata information
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
            
            // Computed status fields
            'is_active' => $this->isActive(),
            'is_inactive' => $this->isInactive(),
            'description' => $this->getDescription(),
            
            // Permission capabilities from details_json
            'capabilities' => $this->formatCapabilities(),
            
            // Relationship data
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'role' => $this->user->role,
                ];
            }),
            
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'code' => $this->client->code ?? null,
                ];
            }),
            
            'feature' => $this->whenLoaded('feature', function () {
                return [
                    'id' => $this->feature->id,
                    'name' => $this->feature->name,
                    'code' => $this->feature->code ?? null,
                    'description' => $this->feature->description ?? null,
                ];
            }),
            
            'grantor' => $this->whenLoaded('grantor', function () {
                return [
                    'id' => $this->grantor->id,
                    'name' => $this->grantor->name,
                    'email' => $this->grantor->email,
                    'role' => $this->grantor->role,
                ];
            }),
            
            'manager' => $this->when($this->manager_user_id, function () {
                return $this->whenLoaded('manager', function () {
                    return [
                        'id' => $this->manager->id,
                        'name' => $this->manager->name,
                        'email' => $this->manager->email,
                        'role' => $this->manager->role,
                    ];
                });
            }),
            
            // Permission management context
            'permission_context' => [
                'was_granted_by_current_user' => $this->wasGrantedBy(auth()->user()->id ?? 0),
                'is_managed_by_current_user' => $this->isManagedBy(auth()->user()->id ?? 0),
                'belongs_to_current_user' => $this->belongsToUser(auth()->user()->id ?? 0),
                'belongs_to_current_client' => $this->belongsToClient(auth()->user()->client_id ?? 0),
                'is_for_oop_feature' => $this->isForFeature(16), // OOP_FEATURE_ID
            ],
            
            // Audit information
            'audit' => [
                'granted_at' => $this->created_at?->toISOString(),
                'last_updated_at' => $this->updated_at?->toISOString(),
                'granted_by' => $this->whenLoaded('grantor', $this->grantor->name ?? 'Unknown'),
                'managed_by' => $this->when($this->manager_user_id, function () {
                    return $this->whenLoaded('manager', $this->manager->name ?? 'Unknown');
                }),
            ],
            
            // Metadata and additional details
            'metadata' => $this->formatMetadata(),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Format permission capabilities from details_json.
     *
     * @return array<string, mixed>
     */
    private function formatCapabilities(): array
    {
        $detailsJson = $this->details_json ?? [];
        
        return [
            'can_approve' => (bool) ($detailsJson['can_approve'] ?? false),
            'can_manage' => (bool) ($detailsJson['can_manage'] ?? false),
            'can_delegate' => (bool) ($detailsJson['can_delegate'] ?? false),
            'has_approval_rights' => (bool) ($detailsJson['can_approve'] ?? false),
            'has_management_rights' => (bool) ($detailsJson['can_manage'] ?? false),
            'has_delegation_rights' => (bool) ($detailsJson['can_delegate'] ?? false),
            'permission_level' => $this->determinePermissionLevel($detailsJson),
        ];
    }

    /**
     * Format metadata and additional information.
     *
     * @return array<string, mixed>
     */
    private function formatMetadata(): array
    {
        $detailsJson = $this->details_json ?? [];
        
        $metadata = [
            'expiry_date' => null,
            'notes' => null,
            'granted_reason' => null,
            'updated_reason' => null,
            'has_expiry' => false,
            'is_expired' => false,
            'days_until_expiry' => null,
        ];

        // Process expiry date
        if (isset($detailsJson['expiry_date']) && !empty($detailsJson['expiry_date'])) {
            try {
                $expiryDate = Carbon::parse($detailsJson['expiry_date']);
                $metadata['expiry_date'] = $expiryDate->toISOString();
                $metadata['has_expiry'] = true;
                $metadata['is_expired'] = $expiryDate->isPast();
                $metadata['days_until_expiry'] = $expiryDate->isFuture() ? 
                    now()->diffInDays($expiryDate) : 0;
            } catch (\Exception $e) {
                // Invalid date format, leave as null
            }
        }

        // Process text fields
        $metadata['notes'] = $this->sanitizeString($detailsJson['notes'] ?? null);
        $metadata['granted_reason'] = $this->sanitizeString($detailsJson['granted_reason'] ?? null);
        $metadata['updated_reason'] = $this->sanitizeString($detailsJson['updated_reason'] ?? null);

        // Add computed metadata
        $metadata['permission_age_days'] = $this->created_at ? 
            $this->created_at->diffInDays(now()) : 0;
        $metadata['last_update_days_ago'] = $this->updated_at ? 
            $this->updated_at->diffInDays(now()) : 0;

        return $metadata;
    }

    /**
     * Determine permission level based on capabilities.
     *
     * @param array<string, mixed> $detailsJson
     * @return string
     */
    private function determinePermissionLevel(array $detailsJson): string
    {
        $canApprove = (bool) ($detailsJson['can_approve'] ?? false);
        $canManage = (bool) ($detailsJson['can_manage'] ?? false);
        $canDelegate = (bool) ($detailsJson['can_delegate'] ?? false);

        if ($canApprove && $canManage && $canDelegate) {
            return 'full';
        }

        if ($canApprove || $canManage) {
            return 'elevated';
        }

        if ($canDelegate) {
            return 'delegation';
        }

        return 'basic';
    }

    /**
     * Sanitize string value for output.
     *
     * @param string|null $value
     * @return string|null
     */
    private function sanitizeString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get additional attributes to include in response.
     * This method can be overridden to include additional computed attributes.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'resource_type' => 'user_feature_permission',
                'api_version' => '1.0',
                'generated_at' => now()->toISOString(),
                'client_timezone' => $request->header('X-Client-Timezone', 'UTC'),
            ],
        ];
    }

    /**
     * Customize the response for this resource.
     * 
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        $response->header('X-Resource-Type', 'UserFeaturePermission');
        $response->header('X-API-Version', '1.0');
    }

    /**
     * Get the JSON serialization options that should be applied to the resource response.
     *
     * @return int
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;
    }
}