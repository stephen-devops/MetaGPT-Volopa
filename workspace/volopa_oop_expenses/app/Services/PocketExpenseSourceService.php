<?php

namespace App\Services;

use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * PocketExpenseSourceService
 * 
 * Service class for managing pocket expense sources (client configurations).
 * Handles CRUD operations, default source creation, and client-specific source management.
 */
class PocketExpenseSourceService
{
    /**
     * Create a new expense source for a client.
     *
     * @param array $data
     * @param int $clientId
     * @return PocketExpenseSourceClientConfig
     * @throws \Exception
     */
    public function createSource(array $data, int $clientId): PocketExpenseSourceClientConfig
    {
        // Validate maximum 20 active expense sources per client constraint
        $activeSourcesCount = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->count();
            
        if ($activeSourcesCount >= 20) {
            throw new \Exception('Maximum 20 active expense sources per client limit reached');
        }

        // Check for unique source name per client constraint
        $existingSource = PocketExpenseSourceClientConfig::forClient($clientId)
            ->byName($data['name'])
            ->active()
            ->first();
            
        if ($existingSource) {
            throw new \Exception("Source with name '{$data['name']}' already exists for this client");
        }

        // Create the expense source
        $sourceData = [
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientId,
            'name' => $data['name'],
            'is_default' => $data['is_default'] ?? 0,
            'deleted' => 0,
            'delete_time' => null,
            'create_time' => now(),
            'update_time' => null,
        ];

        return PocketExpenseSourceClientConfig::create($sourceData);
    }

    /**
     * Update an existing expense source.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @param array $data
     * @return PocketExpenseSourceClientConfig
     * @throws \Exception
     */
    public function updateSource(PocketExpenseSourceClientConfig $source, array $data): PocketExpenseSourceClientConfig
    {
        // Prevent editing of global 'Other' source as per constraints
        if ($source->isGlobalOther()) {
            throw new \Exception('Global "Other" record cannot be edited');
        }

        // Check for unique source name per client if name is being changed
        if (isset($data['name']) && $data['name'] !== $source->name) {
            $existingSource = PocketExpenseSourceClientConfig::forClient($source->client_id)
                ->byName($data['name'])
                ->active()
                ->where('id', '!=', $source->id)
                ->first();
                
            if ($existingSource) {
                throw new \Exception("Source with name '{$data['name']}' already exists for this client");
            }
        }

        // Update the source with provided data
        $updateData = array_intersect_key($data, array_flip(['name', 'is_default']));
        $updateData['update_time'] = now();

        $source->update($updateData);
        
        return $source->fresh();
    }

    /**
     * Soft delete an expense source.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     * @throws \Exception
     */
    public function deleteSource(PocketExpenseSourceClientConfig $source): bool
    {
        // Prevent deletion of global 'Other' source as per constraints
        if ($source->isGlobalOther()) {
            throw new \Exception('Global "Other" record cannot be deleted');
        }

        return $source->softDelete();
    }

    /**
     * Get all active expense sources for a client.
     * Includes the global 'Other' source.
     *
     * @param int $clientId
     * @return EloquentCollection
     */
    public function getSourcesForClient(int $clientId): EloquentCollection
    {
        // Get client-specific active sources
        $clientSources = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->orderBy('name')
            ->get();

        // Get global 'Other' source
        $globalOther = PocketExpenseSourceClientConfig::global()
            ->byName('Other')
            ->active()
            ->first();

        // Combine client sources with global 'Other' source
        $allSources = $clientSources;
        if ($globalOther) {
            $allSources = $allSources->concat([$globalOther]);
        }

        // Sort by name for consistent ordering
        return $allSources->sortBy('name')->values();
    }

    /**
     * Seed default expense sources for a client when OOP feature is enabled.
     * Creates 3 default sources: Cash, Corporate Card, Personal Card.
     *
     * @param int $clientId
     * @return void
     * @throws \Exception
     */
    public function seedDefaultSources(int $clientId): void
    {
        // Default sources to create as per constraints
        $defaultSources = [
            [
                'name' => 'Cash',
                'is_default' => 1,
            ],
            [
                'name' => 'Corporate Card', 
                'is_default' => 1,
            ],
            [
                'name' => 'Personal Card',
                'is_default' => 1,
            ],
        ];

        foreach ($defaultSources as $sourceData) {
            // Check if source already exists to avoid duplicates
            $existingSource = PocketExpenseSourceClientConfig::forClient($clientId)
                ->byName($sourceData['name'])
                ->active()
                ->first();

            if (!$existingSource) {
                try {
                    $this->createSource($sourceData, $clientId);
                } catch (\Exception $e) {
                    // Log error but continue with other default sources
                    // TODO: Implement proper logging mechanism
                    continue;
                }
            }
        }
    }

    /**
     * Get default expense sources for a client.
     *
     * @param int $clientId
     * @return EloquentCollection
     */
    public function getDefaultSources(int $clientId): EloquentCollection
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->default()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get non-default expense sources for a client.
     *
     * @param int $clientId
     * @return EloquentCollection
     */
    public function getNonDefaultSources(int $clientId): EloquentCollection
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->nonDefault()
            ->orderBy('name')
            ->get();
    }

    /**
     * Find an expense source by name for a client.
     * Includes global 'Other' source in search.
     *
     * @param string $name
     * @param int $clientId
     * @return PocketExpenseSourceClientConfig|null
     */
    public function findSourceByName(string $name, int $clientId): ?PocketExpenseSourceClientConfig
    {
        // First check client-specific sources
        $source = PocketExpenseSourceClientConfig::forClient($clientId)
            ->byName($name)
            ->active()
            ->first();

        // If not found and name is 'Other', check global source
        if (!$source && $name === 'Other') {
            $source = PocketExpenseSourceClientConfig::global()
                ->byName('Other')
                ->active()
                ->first();
        }

        return $source;
    }

    /**
     * Check if a client has reached the maximum number of active sources (20).
     *
     * @param int $clientId
     * @return bool
     */
    public function hasReachedMaxSources(int $clientId): bool
    {
        $activeSourcesCount = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->count();

        return $activeSourcesCount >= 20;
    }

    /**
     * Get the count of active sources for a client.
     *
     * @param int $clientId
     * @return int
     */
    public function getActiveSourceCount(int $clientId): int
    {
        return PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->count();
    }

    /**
     * Restore a soft-deleted expense source.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     * @throws \Exception
     */
    public function restoreSource(PocketExpenseSourceClientConfig $source): bool
    {
        // Check if restoring would exceed the maximum sources limit
        if ($this->hasReachedMaxSources($source->client_id)) {
            throw new \Exception('Cannot restore source: Maximum 20 active sources per client limit would be exceeded');
        }

        // Check for name conflicts with existing active sources
        $existingSource = PocketExpenseSourceClientConfig::forClient($source->client_id)
            ->byName($source->name)
            ->active()
            ->first();
            
        if ($existingSource) {
            throw new \Exception("Cannot restore source: Source with name '{$source->name}' already exists");
        }

        return $source->restore();
    }

    /**
     * Get expense sources available for CSV validation.
     * Returns names of all active sources for a client including global 'Other'.
     *
     * @param int $clientId
     * @return array
     */
    public function getSourceNamesForValidation(int $clientId): array
    {
        $sources = $this->getSourcesForClient($clientId);
        return $sources->pluck('name')->toArray();
    }
}