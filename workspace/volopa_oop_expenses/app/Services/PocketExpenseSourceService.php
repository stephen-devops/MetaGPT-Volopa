<?php

namespace App\Services;

use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

/**
 * Pocket Expense Source Service
 * 
 * Manages expense source configuration for clients.
 * Handles CRUD operations, default source creation, and validation constraints.
 * Enforces business rules: max 20 active sources per client, unique names, global 'Other' protection.
 */
class PocketExpenseSourceService
{
    /**
     * Maximum active expense sources allowed per client
     */
    private const MAX_SOURCES_PER_CLIENT = 20;

    /**
     * Default expense sources created when OOP feature is enabled
     */
    private const DEFAULT_SOURCES = [
        'Cash',
        'Corporate Card', 
        'Personal Card'
    ];

    /**
     * Create a new expense source for a client.
     * Validates uniqueness and enforces max sources constraint.
     *
     * @param array $data
     * @return PocketExpenseSourceClientConfig
     * @throws Exception
     */
    public function create(array $data): PocketExpenseSourceClientConfig
    {
        $clientId = $data['client_id'];
        $name = $data['name'];

        // Check maximum sources constraint
        if ($this->getActiveSourceCountForClient($clientId) >= self::MAX_SOURCES_PER_CLIENT) {
            throw new Exception("Client has reached maximum of " . self::MAX_SOURCES_PER_CLIENT . " active expense sources");
        }

        // Check name uniqueness for client
        if ($this->sourceExistsForClient($clientId, $name)) {
            throw new Exception("Expense source name '{$name}' already exists for this client");
        }

        // Create the source
        return PocketExpenseSourceClientConfig::create([
            'client_id' => $clientId,
            'name' => $name,
            'is_default' => $data['is_default'] ?? false,
            'deleted' => false,
            'delete_time' => null,
        ]);
    }

    /**
     * Update an existing expense source.
     * Validates constraints and prevents editing global 'Other' record.
     *
     * @param int $id
     * @param array $data
     * @return PocketExpenseSourceClientConfig
     * @throws Exception
     */
    public function update(int $id, array $data): PocketExpenseSourceClientConfig
    {
        $source = $this->findById($id);

        // Prevent editing global 'Other' record
        if (!$source->canEdit()) {
            throw new Exception("Global 'Other' record cannot be edited");
        }

        // If name is being changed, check uniqueness
        if (isset($data['name']) && $data['name'] !== $source->name) {
            if ($this->sourceExistsForClient($source->client_id, $data['name'], $id)) {
                throw new Exception("Expense source name '{$data['name']}' already exists for this client");
            }
        }

        // Update the source
        $source->update([
            'name' => $data['name'] ?? $source->name,
            'is_default' => $data['is_default'] ?? $source->is_default,
        ]);

        return $source->fresh();
    }

    /**
     * Soft delete an expense source.
     * Prevents deletion of global 'Other' record.
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function delete(int $id): bool
    {
        $source = $this->findById($id);

        // Prevent deleting global 'Other' record
        if (!$source->canDelete()) {
            throw new Exception("Global 'Other' record cannot be deleted");
        }

        return $source->softDelete();
    }

    /**
     * Find expense source by ID.
     *
     * @param int $id
     * @return PocketExpenseSourceClientConfig
     * @throws ModelNotFoundException
     */
    public function findById(int $id): PocketExpenseSourceClientConfig
    {
        return PocketExpenseSourceClientConfig::findOrFail($id);
    }

    /**
     * Get available expense sources for a client.
     * Includes client-specific sources and global sources (like 'Other').
     *
     * @param int $clientId
     * @return Collection
     */
    public function getAvailableForClient(int $clientId): Collection
    {
        return PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Get active expense sources for a client (excluding global).
     *
     * @param int $clientId
     * @return Collection
     */
    public function getClientSources(int $clientId): Collection
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Get all expense sources for a client including soft-deleted ones.
     * Used for historical records display.
     *
     * @param int $clientId
     * @return Collection
     */
    public function getAllForClient(int $clientId): Collection
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->orderBy('deleted', 'asc')
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Create default expense sources for a client when OOP feature is enabled.
     * Creates 3 default sources: Cash, Corporate Card, Personal Card.
     *
     * @param int $clientId
     * @return Collection
     */
    public function createDefaultSourcesForClient(int $clientId): Collection
    {
        $createdSources = collect();

        foreach (self::DEFAULT_SOURCES as $sourceName) {
            // Skip if source already exists
            if ($this->sourceExistsForClient($clientId, $sourceName)) {
                continue;
            }

            $source = PocketExpenseSourceClientConfig::create([
                'client_id' => $clientId,
                'name' => $sourceName,
                'is_default' => true,
                'deleted' => false,
                'delete_time' => null,
            ]);

            $createdSources->push($source);
        }

        return $createdSources;
    }

    /**
     * Restore a soft-deleted expense source.
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function restore(int $id): bool
    {
        $source = PocketExpenseSourceClientConfig::findOrFail($id);

        if (!$source->deleted) {
            throw new Exception("Expense source is not deleted");
        }

        // Check if restoring would violate name uniqueness
        if ($this->sourceExistsForClient($source->client_id, $source->name)) {
            throw new Exception("Cannot restore: expense source name '{$source->name}' already exists for this client");
        }

        // Check if restoring would exceed max sources limit
        if ($this->getActiveSourceCountForClient($source->client_id) >= self::MAX_SOURCES_PER_CLIENT) {
            throw new Exception("Cannot restore: client has reached maximum of " . self::MAX_SOURCES_PER_CLIENT . " active expense sources");
        }

        return $source->restore();
    }

    /**
     * Get count of active expense sources for a client.
     *
     * @param int $clientId
     * @return int
     */
    public function getActiveSourceCountForClient(int $clientId): int
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->count();
    }

    /**
     * Check if an expense source exists for a client by name.
     *
     * @param int $clientId
     * @param string $name
     * @param int|null $excludeId
     * @return bool
     */
    public function sourceExistsForClient(int $clientId, string $name, ?int $excludeId = null): bool
    {
        $query = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->where('name', $name);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Find expense source by name for a client.
     * Includes both client-specific and global sources.
     *
     * @param int $clientId
     * @param string $name
     * @return PocketExpenseSourceClientConfig|null
     */
    public function findByNameForClient(int $clientId, string $name): ?PocketExpenseSourceClientConfig
    {
        return PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->where('name', $name)
            ->first();
    }

    /**
     * Get default expense sources for a client.
     *
     * @param int $clientId
     * @return Collection
     */
    public function getDefaultSourcesForClient(int $clientId): Collection
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->default()
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Set a source as default for a client.
     * Only one source can be default per client.
     *
     * @param int $id
     * @param bool $isDefault
     * @return PocketExpenseSourceClientConfig
     * @throws Exception
     */
    public function setDefault(int $id, bool $isDefault = true): PocketExpenseSourceClientConfig
    {
        $source = $this->findById($id);

        // Prevent setting global 'Other' as default
        if ($source->isGlobalOther() && $isDefault) {
            throw new Exception("Global 'Other' record cannot be set as default");
        }

        if ($isDefault && $source->client_id) {
            // Remove default flag from other sources for this client
            PocketExpenseSourceClientConfig::forClient($source->client_id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $source->update(['is_default' => $isDefault]);

        return $source->fresh();
    }

    /**
     * Validate expense source data.
     *
     * @param array $data
     * @param int|null $excludeId
     * @return array
     */
    public function validateSourceData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Required fields
        if (empty($data['client_id'])) {
            $errors['client_id'] = 'Client ID is required';
        }

        if (empty($data['name'])) {
            $errors['name'] = 'Source name is required';
        }

        // Name length validation (adjust based on DB constraints)
        if (isset($data['name']) && strlen($data['name']) > 255) {
            $errors['name'] = 'Source name cannot exceed 255 characters';
        }

        // Uniqueness validation
        if (isset($data['client_id']) && isset($data['name'])) {
            if ($this->sourceExistsForClient($data['client_id'], $data['name'], $excludeId)) {
                $errors['name'] = 'Source name already exists for this client';
            }
        }

        // Max sources validation
        if (isset($data['client_id']) && !$excludeId) {
            if ($this->getActiveSourceCountForClient($data['client_id']) >= self::MAX_SOURCES_PER_CLIENT) {
                $errors['client_id'] = 'Client has reached maximum of ' . self::MAX_SOURCES_PER_CLIENT . ' active expense sources';
            }
        }

        return $errors;
    }

    /**
     * Get global expense sources (client_id = null).
     *
     * @return Collection
     */
    public function getGlobalSources(): Collection
    {
        return PocketExpenseSourceClientConfig::global()
            ->active()
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Check if client has reached maximum sources limit.
     *
     * @param int $clientId
     * @return bool
     */
    public function hasReachedMaxSources(int $clientId): bool
    {
        return $this->getActiveSourceCountForClient($clientId) >= self::MAX_SOURCES_PER_CLIENT;
    }

    /**
     * Get expense sources statistics for a client.
     *
     * @param int $clientId
     * @return array
     */
    public function getClientSourcesStats(int $clientId): array
    {
        $activeSources = $this->getActiveSourceCountForClient($clientId);
        $deletedSources = PocketExpenseSourceClientConfig::forClient($clientId)
            ->deleted()
            ->count();
        $defaultSources = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->default()
            ->count();

        return [
            'active_count' => $activeSources,
            'deleted_count' => $deletedSources,
            'default_count' => $defaultSources,
            'max_allowed' => self::MAX_SOURCES_PER_CLIENT,
            'remaining_slots' => self::MAX_SOURCES_PER_CLIENT - $activeSources,
            'has_reached_max' => $activeSources >= self::MAX_SOURCES_PER_CLIENT,
        ];
    }
}