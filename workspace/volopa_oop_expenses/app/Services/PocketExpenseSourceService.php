<?php

namespace App\Services;

use App\Models\PocketExpenseSourceClientConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * PocketExpenseSourceService
 * 
 * Business logic service for managing pocket expense sources.
 * Handles CRUD operations, client source limits, and default source seeding.
 */
class PocketExpenseSourceService
{
    /**
     * Maximum allowed active expense sources per client as per system constraints.
     */
    private const MAX_SOURCES_PER_CLIENT = 20;

    /**
     * Default source names that are auto-created on feature enable.
     */
    private const DEFAULT_SOURCE_NAMES = [
        'Cash',
        'Corporate Card',
        'Personal Card',
    ];

    /**
     * Create a new expense source for a client.
     *
     * @param array $data
     * @return PocketExpenseSourceClientConfig
     * @throws Exception
     */
    public function createSource(array $data): PocketExpenseSourceClientConfig
    {
        // Validate client source limit before creating
        if (isset($data['client_id']) && $data['client_id']) {
            $this->validateClientSourceLimit($data['client_id']);
        }

        DB::beginTransaction();

        try {
            $source = new PocketExpenseSourceClientConfig();
            $source->uuid = Str::uuid()->toString();
            $source->client_id = $data['client_id'] ?? null;
            $source->name = $data['name'];
            $source->is_default = $data['is_default'] ?? false;
            $source->deleted = 0;
            $source->delete_time = null;
            $source->create_time = now();
            $source->update_time = now();

            $source->save();

            DB::commit();

            return $source;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing expense source.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @param array $data
     * @return PocketExpenseSourceClientConfig
     * @throws Exception
     */
    public function updateSource(PocketExpenseSourceClientConfig $source, array $data): PocketExpenseSourceClientConfig
    {
        // Prevent editing of global 'Other' record as per system constraints
        if ($source->isGlobalOther()) {
            throw new Exception('Global "Other" record cannot be edited.');
        }

        DB::beginTransaction();

        try {
            if (isset($data['name'])) {
                $source->name = $data['name'];
            }

            if (isset($data['is_default'])) {
                $source->is_default = $data['is_default'];
            }

            $source->update_time = now();
            $source->save();

            DB::commit();

            return $source;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Soft delete an expense source.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     * @throws Exception
     */
    public function deleteSource(PocketExpenseSourceClientConfig $source): bool
    {
        // Prevent deletion of global 'Other' record as per system constraints
        if ($source->isGlobalOther()) {
            throw new Exception('Global "Other" record cannot be deleted.');
        }

        DB::beginTransaction();

        try {
            $result = $source->softDelete();

            DB::commit();

            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get all active expense sources for a client (including global 'Other').
     *
     * @param int $clientId
     * @return Collection
     */
    public function getClientSources(int $clientId): Collection
    {
        return PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Seed default expense sources for a client.
     * Creates the 3 default sources as per system constraints.
     *
     * @param int $clientId
     * @return void
     * @throws Exception
     */
    public function seedDefaultSources(int $clientId): void
    {
        DB::beginTransaction();

        try {
            foreach (self::DEFAULT_SOURCE_NAMES as $sourceName) {
                // Check if source already exists for this client
                $existingSource = PocketExpenseSourceClientConfig::where('client_id', $clientId)
                    ->where('name', $sourceName)
                    ->first();

                if (!$existingSource) {
                    $this->createSource([
                        'client_id' => $clientId,
                        'name' => $sourceName,
                        'is_default' => true,
                    ]);
                }
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Restore a soft-deleted expense source.
     *
     * @param PocketExpenseSourceClientConfig $source
     * @return bool
     * @throws Exception
     */
    public function restoreSource(PocketExpenseSourceClientConfig $source): bool
    {
        if (!$source->isDeleted()) {
            return true; // Already active
        }

        // Validate client source limit before restoring
        if ($source->client_id) {
            $this->validateClientSourceLimit($source->client_id);
        }

        DB::beginTransaction();

        try {
            $result = $source->restore();

            DB::commit();

            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get active source count for a client.
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
     * Check if a client can add more sources.
     *
     * @param int $clientId
     * @return bool
     */
    public function canAddMoreSources(int $clientId): bool
    {
        return $this->getActiveSourceCount($clientId) < self::MAX_SOURCES_PER_CLIENT;
    }

    /**
     * Get sources that can be used in dropdowns (active only).
     * Excludes soft-deleted sources as per system constraints.
     *
     * @param int $clientId
     * @return Collection
     */
    public function getDropdownSources(int $clientId): Collection
    {
        return $this->getClientSources($clientId);
    }

    /**
     * Find source by name for a client.
     *
     * @param int $clientId
     * @param string $sourceName
     * @return PocketExpenseSourceClientConfig|null
     */
    public function findSourceByName(int $clientId, string $sourceName): ?PocketExpenseSourceClientConfig
    {
        // Check client-specific sources first
        $source = PocketExpenseSourceClientConfig::forClient($clientId)
            ->active()
            ->where('name', $sourceName)
            ->first();

        // If not found, check global sources (like 'Other')
        if (!$source) {
            $source = PocketExpenseSourceClientConfig::global()
                ->active()
                ->where('name', $sourceName)
                ->first();
        }

        return $source;
    }

    /**
     * Validate that a client hasn't exceeded the maximum source limit.
     *
     * @param int $clientId
     * @return void
     * @throws Exception
     */
    private function validateClientSourceLimit(int $clientId): void
    {
        if (!$this->canAddMoreSources($clientId)) {
            throw new Exception(
                "Client has reached the maximum limit of " . self::MAX_SOURCES_PER_CLIENT . " active expense sources."
            );
        }
    }

    /**
     * Get default sources for initial client setup.
     *
     * @return array
     */
    public static function getDefaultSourceNames(): array
    {
        return self::DEFAULT_SOURCE_NAMES;
    }

    /**
     * Get maximum allowed sources per client.
     *
     * @return int
     */
    public static function getMaxSourcesPerClient(): int
    {
        return self::MAX_SOURCES_PER_CLIENT;
    }

    /**
     * Bulk update source order/priority.
     * TODO: Implement if source ordering becomes a requirement.
     *
     * @param array $sourceOrderData
     * @return bool
     */
    public function updateSourceOrder(array $sourceOrderData): bool
    {
        // TODO: Implement source ordering functionality if needed
        // This would handle drag-and-drop reordering of sources in the UI
        throw new Exception('Source ordering not yet implemented.');
    }

    /**
     * Get source usage statistics.
     * TODO: Implement if analytics on source usage is needed.
     *
     * @param int $clientId
     * @return array
     */
    public function getSourceUsageStats(int $clientId): array
    {
        // TODO: Implement source usage analytics
        // This would return statistics on how often each source is used
        throw new Exception('Source usage statistics not yet implemented.');
    }
}