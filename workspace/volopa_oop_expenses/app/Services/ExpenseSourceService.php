## Code: app/Services/ExpenseSourceService.php

```php
<?php

namespace App\Services;

use App\Models\PocketExpenseSourceClientConfig;
use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Exception;

/**
 * ExpenseSourceService
 * 
 * Service for managing expense source configurations for clients.
 * Handles CRUD operations, validation, caching, and default source management.
 * Supports both global and client-specific expense sources with proper validation.
 */
class ExpenseSourceService
{
    /**
     * Cache TTL for expense sources (30 minutes).
     */
    private const CACHE_TTL = 1800;

    /**
     * Cache key prefix for expense sources.
     */
    private const CACHE_PREFIX = 'expense_sources';

    /**
     * Maximum number of active sources per client (excluding global).
     */
    private const MAX_SOURCES_PER_CLIENT = 20;

    /**
     * Default sources that are created globally.
     */
    private const DEFAULT_GLOBAL_SOURCES = [
        'Cash' => ['is_default' => true],
        'Corporate Card' => ['is_default' => true],
        'Personal Card' => ['is_default' => true],
        'Other' => ['is_default' => false],
    ];

    /**
     * The "Other" source name constant.
     */
    private const OTHER_SOURCE_NAME = 'Other';

    /**
     * The Cache instance.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * Service configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * Create a new expense source service instance.
     */
    public function __construct()
    {
        $this->cache = Cache::store();
        $this->config = Config::get('pocket_expense.sources', []);
    }

    /**
     * Get all expense sources available for a specific client.
     *
     * @param int $clientId
     * @param bool $activeOnly
     * @return Collection<PocketExpenseSourceClientConfig>
     */
    public function getSourcesForClient(int $clientId, bool $activeOnly = true): Collection
    {
        $cacheKey = $this->getClientSourcesCacheKey($clientId, $activeOnly);

        return $this->cache->remember($cacheKey, self::CACHE_TTL, function () use ($clientId, $activeOnly) {
            $query = PocketExpenseSourceClientConfig::availableForClient($clientId);
            
            if ($activeOnly) {
                $query->active();
            }

            return $query->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Get all global expense sources.
     *
     * @param bool $activeOnly
     * @return Collection<PocketExpenseSourceClientConfig>
     */
    public function getGlobalSources(bool $activeOnly = true): Collection
    {
        $cacheKey = $this->getGlobalSourcesCacheKey($activeOnly);

        return $this->cache->remember($cacheKey, self::CACHE_TTL, function () use ($activeOnly) {
            $query = PocketExpenseSourceClientConfig::global();
            
            if ($activeOnly) {
                $query->active();
            }

            return $query->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Get client-specific expense sources only.
     *
     * @param int $clientId
     * @param bool $activeOnly
     * @return Collection<PocketExpenseSourceClientConfig>
     */
    public function getClientSpecificSources(int $clientId, bool $activeOnly = true): Collection
    {
        $cacheKey = $this->getClientSpecificSourcesCacheKey($clientId, $activeOnly);

        return $this->cache->remember($cacheKey, self::CACHE_TTL, function () use ($clientId, $activeOnly) {
            $query = PocketExpenseSourceClientConfig::forClient($clientId);
            
            if ($activeOnly) {
                $query->active();
            }

            return $query->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Find an expense source by name for a specific client.
     *
     * @param string $name
     * @param int $clientId
     * @param bool $activeOnly
     * @return PocketExpenseSourceClientConfig|null
     */
    public function findByNameForClient(string $name, int $clientId, bool $activeOnly = true): ?PocketExpenseSourceClientConfig
    {
        $query = PocketExpenseSourceClientConfig::availableForClient($clientId)
            ->byNameInsensitive($name);
            
        if ($activeOnly) {
            $query->active();
        }

        return $query->first();
    }

    /**
     * Find the "Other" expense source.
     *
     * @return PocketExpenseSourceClientConfig|null
     */
    public function getOtherSource(): ?PocketExpenseSourceClientConfig
    {
        return PocketExpenseSourceClientConfig::getOtherSource();
    }

    /**
     * Create a new client-specific expense source.
     *
     * @param int $clientId
     * @param string $name
     * @param bool $isDefault
     * @return PocketExpenseSourceClientConfig
     * @throws ValidationException
     */
    public function createClientSource(int $clientId, string $name, bool $isDefault = false): PocketExpenseSourceClientConfig
    {
        $this->validateClientSourceCreation($clientId, $name);

        DB::beginTransaction();

        try {
            $source = PocketExpenseSourceClientConfig::create([
                'client_id' => $clientId,
                'name' => trim($name),
                'is_default' => $isDefault,
                'deleted' => false,
            ]);

            // Clear related caches
            $this->clearClientSourcesCaches($clientId);

            DB::commit();

            Log::info('Client expense source created', [
                'client_id' => $clientId,
                'source_id' => $source->id,
                'name' => $name,
                'is_default' => $isDefault,
            ]);

            return $source;

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create client expense source', [
                'client_id' => $clientId,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Update an existing expense source.
     *
     * @param int $sourceId
     * @param array<string, mixed> $attributes
     * @return PocketExpenseSourceClientConfig
     * @throws ValidationException
     */
    public function updateSource(int $sourceId, array $attributes): PocketExpenseSourceClientConfig
    {
        $source = PocketExpenseSourceClientConfig::active()->findOrFail($sourceId);

        // Cannot update global sources
        if ($source->isGlobal()) {
            throw ValidationException::withMessages([
                'source' => 'Cannot update global expense sources.'
            ]);
        }

        // Validate name uniqueness if name is being changed
        if (isset($attributes['name']) && $attributes['name'] !== $source->name) {
            $this->validateSourceNameUniqueness($attributes['name'], $source->client_id, $sourceId);
        }

        DB::beginTransaction();

        try {
            $source->fill(array_intersect_key($attributes, array_flip([
                'name', 'is_default'
            ])));
            
            $source->save();

            // Clear related caches
            $this->clearClientSourcesCaches($source->client_id);

            DB::commit();

            Log::info('Expense source updated', [
                'source_id' => $sourceId,
                'client_id' => $source->client_id,
                'attributes' => $attributes,
            ]);

            return $source;

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update expense source', [
                'source_id' => $sourceId,
                'attributes' => $attributes,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Soft delete an expense source.
     *
     * @param int $sourceId
     * @return bool
     * @throws ValidationException
     */
    public function deleteSource(int $sourceId): bool
    {
        $source = PocketExpenseSourceClientConfig::active()->findOrFail($sourceId);

        if (!$source->canBeDeleted()) {
            throw ValidationException::withMessages([
                'source' => 'This expense source cannot be deleted.'
            ]);
        }

        DB::beginTransaction();

        try {
            $result = $source->softDelete();

            // Clear related caches
            $this->clearClientSourcesCaches($source->client_id);

            DB::commit();

            Log::info('Expense source deleted', [
                'source_id' => $sourceId,
                'client_id' => $source->client_id,
                'name' => $source->name,
            ]);

            return $result;

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete expense source', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Restore a soft-deleted expense source.
     *
     * @param int $sourceId
     * @return bool
     */
    public function restoreSource(int $sourceId): bool
    {
        $source = PocketExpenseSourceClientConfig::where('id', $sourceId)->first();

        if (!$source || !$source->isDeleted()) {
            return false;
        }

        DB::beginTransaction();

        try {
            $result = $source->restore();

            // Clear related caches
            if ($source->client_id) {
                $this->clearClientSourcesCaches($source->client_id);
            }

            DB::commit();

            Log::info('Expense source restored', [
                'source_id' => $sourceId,
                'client_id' => $source->client_id,
                'name' => $source->name,
            ]);

            return $result;

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to restore expense source', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Initialize default sources for a new client.
     *
     * @param int $clientId
     * @return Collection<PocketExpenseSourceClientConfig>
     */
    public function initializeDefaultSourcesForClient(int $clientId): Collection
    {
        // Check if client already has sources initialized
        $existingSources = $this->getClientSpecificSources($clientId, false);
        if ($existingSources->isNotEmpty()) {
            return $existingSources;
        }

        DB::beginTransaction();

        try {
            $createdSources = collect();
            $defaultSources = $this->config['default_sources'] ?? self::DEFAULT_GLOBAL_SOURCES;

            foreach ($defaultSources as $name => $config) {
                // Only create client-specific versions of default sources if configured
                if ($config['client_id'] ?? null === 'client_specific') {
                    $source = PocketExpenseSourceClientConfig::create([
                        'client_id' => $clientId,
                        'name' => $name,
                        'is_default' => $config['is_default'] ?? false,
                        'deleted' => false,
                    ]);
                    
                    $createdSources->push($source);
                }
            }

            // Clear related caches
            $this->clearClientSourcesCaches($clientId);

            DB::commit();

            Log::info('Default expense sources initialized for client', [
                'client_id' => $clientId,
                'sources_created' => $createdSources->count(),
            ]);

            return $createdSources;

        } catch (Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to initialize default sources for client', [
                'client_id' => $clientId,
                'error' => $e->getMessage(),
            ]);
            
            return collect();
        }
    }

    /**
     * Get expense sources formatted for dropdown selection.
     *
     * @param int $clientId
     * @param bool $includeOther
     * @return array<int, string>
     */
    public function getSourcesForDropdown(int $clientId, bool $includeOther = true): array
    {
        $sources = $this->getSourcesForClient($clientId);
        
        $dropdown = $sources->pluck('name', 'id')->toArray();
        
        // Optionally filter out "Other" if not needed
        if (!$includeOther) {
            $dropdown = array_filter($dropdown, function ($name) {
                return strtolower($name) !== 'other';
            });
        }

        return $dropdown;
    }

    /**
     * Validate if a source name is available for a client.
     *
     * @param string $name
     * @param int $clientId
     * @param int|null $excludeId
     * @return bool
     */
    public function isSourceNameAvailable(string $name, int $clientId, ?int $excludeId = null): bool
    {
        return PocketExpenseSourceClientConfig::isNameAvailableForClient($name, $clientId, $excludeId);
    }

    /**
     * Check if a client can add more expense sources.
     *
     * @param int $clientId
     * @return bool
     */
    public function canAddMoreSources(int $clientId): bool
    {
        $maxSources = $this->config['max_active_per_client'] ?? self::MAX_SOURCES_PER_CLIENT;
        $currentCount = PocketExpenseSourceClientConfig::countClientSources($clientId);
        
        return $currentCount < $maxSources;
    }

    /**
     * Get the current count of active sources for a client.
     *
     * @param int $clientId
     * @return int
     */
    public function getClientSourcesCount(int $clientId): int
    {
        return PocketExpenseSourceClientConfig::countClientSources($clientId);
    }

    /**
     * Bulk update sources for a client.
     *
     * @param int $clientId
     * @param array<int, array<string, mixed>> $sourcesData
     * @return Collection<PocketExpenseSourceClientConfig>
     * @throws ValidationException
     */
    public function bulkUpdateClientSources(int $clientId, array $sourcesData): Collection
    {
        DB::beginTransaction();

        try {
            $updatedSources = collect();

            foreach ($sourcesData as $sourceData) {
                if (!isset($sourceData['id'])) {
                    continue;
                }

                $source = PocketExpenseSourceClientConfig::active()
                    ->forClient($clientId)
                    ->findOrFail($sourceData['id']);

                $source->fill(array_intersect_key($sourceData, array_flip([
                    'name', 'is_default'
                ])));
                
                $source->save();
                $updatedSources->push($source);
            }

            // Clear related caches
            $this->clearClientSourcesCaches($clientId);

            DB::commit();

            Log::info