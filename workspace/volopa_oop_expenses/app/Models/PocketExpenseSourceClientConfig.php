<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PocketExpenseSourceClientConfig extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'pocket_expense_source_client_configs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'source_name',
        'source_type',
        'is_active',
        'configuration',
        'max_daily_transactions',
        'max_transaction_amount',
        'description',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'client_id' => 'integer',
        'source_name' => 'string',
        'source_type' => 'string',
        'is_active' => 'boolean',
        'configuration' => 'array',
        'max_daily_transactions' => 'integer',
        'max_transaction_amount' => 'decimal:2',
        'description' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'source_type' => 'manual',
        'is_active' => true,
        'max_daily_transactions' => 100,
        'max_transaction_amount' => 10000.00,
    ];

    /**
     * The possible source type values.
     */
    const SOURCE_TYPE_MANUAL = 'manual';
    const SOURCE_TYPE_API = 'api';
    const SOURCE_TYPE_IMPORT = 'import';
    const SOURCE_TYPE_INTEGRATION = 'integration';

    /**
     * Get all possible source type values.
     */
    public static function getSourceTypeOptions(): array
    {
        return [
            self::SOURCE_TYPE_MANUAL,
            self::SOURCE_TYPE_API,
            self::SOURCE_TYPE_IMPORT,
            self::SOURCE_TYPE_INTEGRATION,
        ];
    }

    /**
     * Get the client that owns this source configuration.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the pocket expense metadata that reference this source.
     */
    public function pocketExpenseMetadata(): HasMany
    {
        return $this->hasMany(PocketExpenseMetadata::class, 'source_id');
    }

    /**
     * Scope a query to only include active source configurations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include inactive source configurations.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope a query to only include configurations for a specific client.
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by source type.
     */
    public function scopeBySourceType($query, string $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Scope a query to filter by source name.
     */
    public function scopeBySourceName($query, string $sourceName)
    {
        return $query->where('source_name', $sourceName);
    }

    /**
     * Scope a query to only include manual source types.
     */
    public function scopeManual($query)
    {
        return $query->where('source_type', self::SOURCE_TYPE_MANUAL);
    }

    /**
     * Scope a query to only include API source types.
     */
    public function scopeApi($query)
    {
        return $query->where('source_type', self::SOURCE_TYPE_API);
    }

    /**
     * Scope a query to only include import source types.
     */
    public function scopeImport($query)
    {
        return $query->where('source_type', self::SOURCE_TYPE_IMPORT);
    }

    /**
     * Scope a query to only include integration source types.
     */
    public function scopeIntegration($query)
    {
        return $query->where('source_type', self::SOURCE_TYPE_INTEGRATION);
    }

    /**
     * Check if the source configuration is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if the source configuration is inactive.
     */
    public function isInactive(): bool
    {
        return !$this->is_active;
    }

    /**
     * Check if the source type is manual.
     */
    public function isManual(): bool
    {
        return $this->source_type === self::SOURCE_TYPE_MANUAL;
    }

    /**
     * Check if the source type is API.
     */
    public function isApi(): bool
    {
        return $this->source_type === self::SOURCE_TYPE_API;
    }

    /**
     * Check if the source type is import.
     */
    public function isImport(): bool
    {
        return $this->source_type === self::SOURCE_TYPE_IMPORT;
    }

    /**
     * Check if the source type is integration.
     */
    public function isIntegration(): bool
    {
        return $this->source_type === self::SOURCE_TYPE_INTEGRATION;
    }

    /**
     * Activate the source configuration.
     */
    public function activate(): bool
    {
        return $this->update(['is_active' => true]);
    }

    /**
     * Deactivate the source configuration.
     */
    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Check if the client has reached the maximum number of active expense sources.
     */
    public static function hasReachedMaxActiveSources(int $clientId, int $maxSources = 20): bool
    {
        $activeSourcesCount = static::forClient($clientId)
            ->active()
            ->count();

        return $activeSourcesCount >= $maxSources;
    }

    /**
     * Get the count of active sources for a client.
     */
    public static function getActiveSourcesCount(int $clientId): int
    {
        return static::forClient($clientId)
            ->active()
            ->count();
    }

    /**
     * Get all active source configurations for a client as an array suitable for dropdowns.
     */
    public static function getActiveOptionsForClient(int $clientId): array
    {
        return static::forClient($clientId)
            ->active()
            ->orderBy('source_name')
            ->pluck('source_name', 'id')
            ->toArray();
    }

    /**
     * Find a source configuration by client and source name.
     */
    public static function findByClientAndName(int $clientId, string $sourceName): ?self
    {
        return static::forClient($clientId)
            ->where('source_name', $sourceName)
            ->first();
    }

    /**
     * Find an active source configuration by client and source name.
     */
    public static function findActiveByClientAndName(int $clientId, string $sourceName): ?self
    {
        return static::forClient($clientId)
            ->active()
            ->where('source_name', $sourceName)
            ->first();
    }

    /**
     * Check if a source name exists for a specific client.
     */
    public static function sourceNameExistsForClient(int $clientId, string $sourceName, ?int $excludeId = null): bool
    {
        $query = static::forClient($clientId)
            ->where('source_name', $sourceName);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Create a new source configuration with unique name validation per client.
     */
    public static function createUniqueForClient(array $attributes): ?self
    {
        $clientId = $attributes['client_id'] ?? null;
        $sourceName = $attributes['source_name'] ?? '';

        if (!$clientId || static::sourceNameExistsForClient($clientId, $sourceName)) {
            return null;
        }

        // Check if client has reached maximum active sources
        if (static::hasReachedMaxActiveSources($clientId)) {
            return null;
        }

        return static::create($attributes);
    }

    /**
     * Get the configuration value for a specific key.
     */
    public function getConfigurationValue(string $key, mixed $default = null): mixed
    {
        $configuration = $this->configuration ?? [];
        return $configuration[$key] ?? $default;
    }

    /**
     * Set a configuration value.
     */
    public function setConfigurationValue(string $key, mixed $value): bool
    {
        $configuration = $this->configuration ?? [];
        $configuration[$key] = $value;
        
        return $this->update(['configuration' => $configuration]);
    }

    /**
     * Update multiple configuration values.
     */
    public function updateConfiguration(array $newConfiguration): bool
    {
        $configuration = array_merge($this->configuration ?? [], $newConfiguration);
        
        return $this->update(['configuration' => $configuration]);
    }

    /**
     * Check if daily transaction limit would be exceeded.
     */
    public function wouldExceedDailyLimit(int $additionalTransactions = 1): bool
    {
        // This would need to be implemented based on actual transaction counting logic
        // For now, we'll return false as a placeholder
        return false;
    }

    /**
     * Check if transaction amount exceeds the maximum allowed.
     */
    public function exceedsTransactionLimit(float $amount): bool
    {
        return $amount > $this->max_transaction_amount;
    }

    /**
     * Get the remaining daily transaction capacity.
     */
    public function getRemainingDailyCapacity(): int
    {
        // This would need to be implemented based on actual transaction counting logic
        // For now, we'll return the max limit as a placeholder
        return $this->max_daily_transactions;
    }

    /**
     * Check if the source configuration is within operational limits.
     */
    public function isWithinOperationalLimits(float $transactionAmount = 0, int $additionalTransactions = 1): bool
    {
        if ($transactionAmount > 0 && $this->exceedsTransactionLimit($transactionAmount)) {
            return false;
        }

        if ($additionalTransactions > 0 && $this->wouldExceedDailyLimit($additionalTransactions)) {
            return false;
        }

        return true;
    }

    /**
     * Get the count of pocket expense metadata records referencing this source.
     */
    public function getPocketExpenseMetadataCount(): int
    {
        return $this->pocketExpenseMetadata()->count();
    }

    /**
     * Check if this source configuration has any pocket expense metadata references.
     */
    public function hasPocketExpenseMetadata(): bool
    {
        return $this->pocketExpenseMetadata()->exists();
    }
}