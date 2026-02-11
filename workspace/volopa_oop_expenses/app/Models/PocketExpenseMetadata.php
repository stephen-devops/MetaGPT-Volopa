<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PocketExpenseMetadata extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'pocket_expense_metadata';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'pocket_expense_id',
        'metadata_type',
        'details_json',
        'value',
        'label',
        'is_required',
        'is_editable',
        'sort_order',
        'category_id',
        'source_id',
        'country_id',
        'reference_type',
        'reference_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'integer',
        'pocket_expense_id' => 'integer',
        'metadata_type' => 'string',
        'details_json' => 'array',
        'value' => 'string',
        'label' => 'string',
        'is_required' => 'boolean',
        'is_editable' => 'boolean',
        'sort_order' => 'integer',
        'category_id' => 'integer',
        'source_id' => 'integer',
        'country_id' => 'integer',
        'reference_type' => 'string',
        'reference_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'deleted_at',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'metadata_type' => 'custom',
        'is_required' => false,
        'is_editable' => true,
        'sort_order' => 0,
    ];

    /**
     * The possible metadata type values.
     */
    const METADATA_TYPE_CATEGORY = 'category';
    const METADATA_TYPE_SOURCE = 'source';
    const METADATA_TYPE_LOCATION = 'location';
    const METADATA_TYPE_TAX = 'tax';
    const METADATA_TYPE_CUSTOM = 'custom';

    /**
     * Get all possible metadata type values.
     */
    public static function getMetadataTypeOptions(): array
    {
        return [
            self::METADATA_TYPE_CATEGORY,
            self::METADATA_TYPE_SOURCE,
            self::METADATA_TYPE_LOCATION,
            self::METADATA_TYPE_TAX,
            self::METADATA_TYPE_CUSTOM,
        ];
    }

    /**
     * Get the pocket expense that owns this metadata.
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the category associated with this metadata.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /**
     * Get the source configuration associated with this metadata.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'source_id');
    }

    /**
     * Get the country associated with this metadata.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    /**
     * Scope a query to only include metadata for a specific expense.
     */
    public function scopeForExpense($query, int $pocketExpenseId)
    {
        return $query->where('pocket_expense_id', $pocketExpenseId);
    }

    /**
     * Scope a query to filter by metadata type.
     */
    public function scopeByType($query, string $metadataType)
    {
        return $query->where('metadata_type', $metadataType);
    }

    /**
     * Scope a query to only include category metadata.
     */
    public function scopeCategory($query)
    {
        return $query->where('metadata_type', self::METADATA_TYPE_CATEGORY);
    }

    /**
     * Scope a query to only include source metadata.
     */
    public function scopeSource($query)
    {
        return $query->where('metadata_type', self::METADATA_TYPE_SOURCE);
    }

    /**
     * Scope a query to only include location metadata.
     */
    public function scopeLocation($query)
    {
        return $query->where('metadata_type', self::METADATA_TYPE_LOCATION);
    }

    /**
     * Scope a query to only include tax metadata.
     */
    public function scopeTax($query)
    {
        return $query->where('metadata_type', self::METADATA_TYPE_TAX);
    }

    /**
     * Scope a query to only include custom metadata.
     */
    public function scopeCustom($query)
    {
        return $query->where('metadata_type', self::METADATA_TYPE_CUSTOM);
    }

    /**
     * Scope a query to only include required metadata.
     */
    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    /**
     * Scope a query to only include optional metadata.
     */
    public function scopeOptional($query)
    {
        return $query->where('is_required', false);
    }

    /**
     * Scope a query to only include editable metadata.
     */
    public function scopeEditable($query)
    {
        return $query->where('is_editable', true);
    }

    /**
     * Scope a query to only include read-only metadata.
     */
    public function scopeReadOnly($query)
    {
        return $query->where('is_editable', false);
    }

    /**
     * Scope a query to filter by category ID.
     */
    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope a query to filter by source ID.
     */
    public function scopeBySource($query, int $sourceId)
    {
        return $query->where('source_id', $sourceId);
    }

    /**
     * Scope a query to filter by country ID.
     */
    public function scopeByCountry($query, int $countryId)
    {
        return $query->where('country_id', $countryId);
    }

    /**
     * Scope a query to filter by reference type and ID.
     */
    public function scopeByReference($query, string $referenceType, int $referenceId)
    {
        return $query->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId);
    }

    /**
     * Scope a query to order by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    /**
     * Check if the metadata type is category.
     */
    public function isCategory(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_CATEGORY;
    }

    /**
     * Check if the metadata type is source.
     */
    public function isSource(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_SOURCE;
    }

    /**
     * Check if the metadata type is location.
     */
    public function isLocation(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_LOCATION;
    }

    /**
     * Check if the metadata type is tax.
     */
    public function isTax(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_TAX;
    }

    /**
     * Check if the metadata type is custom.
     */
    public function isCustom(): bool
    {
        return $this->metadata_type === self::METADATA_TYPE_CUSTOM;
    }

    /**
     * Check if the metadata is required.
     */
    public function isRequired(): bool
    {
        return $this->is_required;
    }

    /**
     * Check if the metadata is optional.
     */
    public function isOptional(): bool
    {
        return !$this->is_required;
    }

    /**
     * Check if the metadata is editable.
     */
    public function isEditable(): bool
    {
        return $this->is_editable;
    }

    /**
     * Check if the metadata is read-only.
     */
    public function isReadOnly(): bool
    {
        return !$this->is_editable;
    }

    /**
     * Mark the metadata as required.
     */
    public function markAsRequired(): bool
    {
        return $this->update(['is_required' => true]);
    }

    /**
     * Mark the metadata as optional.
     */
    public function markAsOptional(): bool
    {
        return $this->update(['is_required' => false]);
    }

    /**
     * Mark the metadata as editable.
     */
    public function markAsEditable(): bool
    {
        return $this->update(['is_editable' => true]);
    }

    /**
     * Mark the metadata as read-only.
     */
    public function markAsReadOnly(): bool
    {
        return $this->update(['is_editable' => false]);
    }

    /**
     * Get the details JSON value for a specific key.
     */
    public function getDetailsValue(string $key, mixed $default = null): mixed
    {
        $details = $this->details_json ?? [];
        return $details[$key] ?? $default;
    }

    /**
     * Set a details JSON value.
     */
    public function setDetailsValue(string $key, mixed $value): bool
    {
        $details = $this->details_json ?? [];
        $details[$key] = $value;
        
        return $this->update(['details_json' => $details]);
    }

    /**
     * Update multiple details JSON values.
     */
    public function updateDetails(array $newDetails): bool
    {
        $details = array_merge($this->details_json ?? [], $newDetails);
        
        return $this->update(['details_json' => $details]);
    }

    /**
     * Check if a details key exists.
     */
    public function hasDetailsKey(string $key): bool
    {
        $details = $this->details_json ?? [];
        return array_key_exists($key, $details);
    }

    /**
     * Remove a details key.
     */
    public function removeDetailsKey(string $key): bool
    {
        $details = $this->details_json ?? [];
        unset($details[$key]);
        
        return $this->update(['details_json' => $details]);
    }

    /**
     * Get all details keys.
     */
    public function getDetailsKeys(): array
    {
        $details = $this->details_json ?? [];
        return array_keys($details);
    }

    /**
     * Check if details JSON has any data.
     */
    public function hasDetails(): bool
    {
        $details = $this->details_json ?? [];
        return !empty($details);
    }

    /**
     * Clear all details JSON data.
     */
    public function clearDetails(): bool
    {
        return $this->update(['details_json' => []]);
    }

    /**
     * Get the display value for this metadata.
     */
    public function getDisplayValue(): string
    {
        if (!empty($this->label)) {
            return $this->label;
        }

        if (!empty($this->value)) {
            return $this->value;
        }

        if ($this->hasDetails()) {
            $details = $this->details_json;
            if (isset($details['display_value'])) {
                return (string) $details['display_value'];
            }
            if (isset($details['name'])) {
                return (string) $details['name'];
            }
            if (isset($details['title'])) {
                return (string) $details['title'];
            }
        }

        return 'N/A';
    }

    /**
     * Get the reference object based on reference_type and reference_id.
     */
    public function getReferenceObject(): ?Model
    {
        if (empty($this->reference_type) || empty($this->reference_id)) {
            return null;
        }

        try {
            $modelClass = 'App\\Models\\' . str_replace('_', '', ucwords($this->reference_type, '_'));
            
            if (class_exists($modelClass)) {
                return $modelClass::find($this->reference_id);
            }
        } catch (\Exception $e) {
            // Log the exception if needed
        }

        return null;
    }

    /**
     * Set the reference object.
     */
    public function setReferenceObject(Model $model): bool
    {
        $referenceType = strtolower(class_basename($model));
        
        return $this->update([
            'reference_type' => $referenceType,
            'reference_id' => $model->id,
        ]);
    }

    /**
     * Clear the reference object.
     */
    public function clearReferenceObject(): bool
    {
        return $this->update([
            'reference_type' => null,
            'reference_id' => null,
        ]);
    }

    /**
     * Check if this metadata has a reference object.
     */
    public function hasReferenceObject(): bool
    {
        return !empty($this->reference_type) && !empty($this->reference_id);
    }

    /**
     * Update the sort order.
     */
    public function updateSortOrder(int $sortOrder): bool
    {
        return $this->update(['sort_order' => $sortOrder]);
    }

    /**
     * Move the metadata up in sort order.
     */
    public function moveUp(): bool
    {
        $previousMetadata = static::forExpense($this->pocket_expense_id)
            ->where('sort_order', '<', $this->sort_order)
            ->orderBy('sort_order', 'desc')
            ->first();

        if ($previousMetadata) {
            $tempSortOrder = $this->sort_order;
            $this->update(['sort_order' => $previousMetadata->sort_order]);
            $previousMetadata->update(['sort_order' => $tempSortOrder]);
            return true;
        }

        return false;
    }

    /**
     * Move the metadata down in sort order.
     */
    public function moveDown(): bool
    {
        $nextMetadata = static::forExpense($this->pocket_expense_id)
            ->where('sort_order', '>', $this->sort_order)
            ->orderBy('sort_order', 'asc')
            ->first();

        if ($nextMetadata) {
            $tempSortOrder = $this->sort_order;
            $this->update(['sort_order' => $nextMetadata->sort_order]);
            $nextMetadata->update(['sort_order' => $tempSortOrder]);
            return true;
        }

        return false;
    }

    /**
     * Get metadata grouped by type for an expense.
     */
    public static function getGroupedByTypeForExpense(int $pocketExpenseId): array
    {
        $metadata = static::forExpense($pocketExpenseId)
            ->ordered()
            ->get()
            ->groupBy('metadata_type');

        $result = [];
        foreach (static::getMetadataTypeOptions() as $type) {
            $result[$type] = $metadata->get($type, collect());
        }

        return $result;
    }

    /**
     * Create category metadata for an expense.
     */
    public static function createCategoryMetadata(int $pocketExpenseId, int $categoryId, array $additionalData = []): self
    {
        return static::create(array_merge([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_CATEGORY,
            'category_id' => $categoryId,
        ], $additionalData));
    }

    /**
     * Create source metadata for an expense.
     */
    public static function createSourceMetadata(int $pocketExpenseId, int $sourceId, array $additionalData = []): self
    {
        return static::create(array_merge([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_SOURCE,
            'source_id' => $sourceId,
        ], $additionalData));
    }

    /**
     * Create location metadata for an expense.
     */
    public static function createLocationMetadata(int $pocketExpenseId, int $countryId, array $additionalData = []): self
    {
        return static::create(array_merge([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_LOCATION,
            'country_id' => $countryId,
        ], $additionalData));
    }

    /**
     * Create tax metadata for an expense.
     */
    public static function createTaxMetadata(int $pocketExpenseId, array $taxData, array $additionalData = []): self
    {
        return static::create(array_merge([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_TAX,
            'details_json' => $taxData,
        ], $additionalData));
    }

    /**
     * Create custom metadata for an expense.
     */
    public static function createCustomMetadata(int $pocketExpenseId, string $label, mixed $value, array $additionalData = []): self
    {
        return static::create(array_merge([
            'pocket_expense_id' => $pocketExpenseId,
            'metadata_type' => self::METADATA_TYPE_CUSTOM,
            'label' => $label,
            'value' => is_string($value) ? $value : json_encode($value),
        ], $additionalData));
    }

    /**
     * Bulk create metadata for an expense.
     */
    public static function bulkCreateForExpense(int $pocketExpenseId, array $metadataItems): bool
    {
        try {
            $insertData = [];
            $currentTimestamp = now();

            foreach ($metadataItems as $item) {
                $insertData[] = array_merge($item, [
                    'pocket_expense_id' => $pocketExpenseId,
                    'created_at' => $currentTimestamp,
                    'updated_at' => $currentTimestamp,
                ]);
            }

            if (!empty($insertData)) {
                static::insert($insertData);
                return true;
            }
        } catch (\Exception $e) {
            // Log the exception if needed
        }

        return false;
    }

    /**
     * Get metadata summary for an expense.
     */
    public static function getSummaryForExpense(int $pocketExpenseId): array
    {
        $metadata = static::forExpense($pocketExpenseId)->get();

        return [
            'total_count' => $metadata->count(),
            'category_count' => $metadata->where('metadata_type', self::METADATA_TYPE_CATEGORY)->count(),
            'source_count' => $metadata->where('metadata_type', self::METADATA_TYPE_SOURCE)->count(),
            'location_count' => $metadata->where('metadata_type', self::METADATA_TYPE_LOCATION)->count(),
            'tax_count' => $metadata->where('metadata_type', self::METADATA_TYPE_TAX)->count(),
            'custom_count' => $metadata->where('metadata_type', self::METADATA_TYPE_CUSTOM)->count(),
            'required_count' => $metadata->where('is_required', true)->count(),
            'editable_count' => $metadata->where('is_editable', true)->count(),
        ];
    }

    /**
     * Validate metadata completeness for an expense.
     */
    public static function validateCompletenessForExpense(int $pocketExpenseId): array
    {
        $requiredMetadata = static::forExpense($pocketExpenseId)
            ->required()
            ->get();

        $missingFields = [];
        foreach ($requiredMetadata as $metadata) {
            if (empty($metadata->value) && empty($metadata->details_json) && 
                empty($metadata->category_id) && empty($metadata->source_id) && 
                empty($metadata->country_id)) {
                $missingFields[] = $metadata->label ?: "Metadata #{$metadata->id}";
            }
        }

        return [
            'is_complete' => empty($missingFields),
            'missing_fields' => $missingFields,
            'required_count' => $requiredMetadata->count(),
            'completed_count' => $requiredMetadata->count() - count($missingFields),
        ];
    }

    /**
     * Get the next available sort order for an expense.
     */
    public static function getNextSortOrderForExpense(int $pocketExpenseId): int
    {
        $maxSortOrder = static::forExpense($pocketExpenseId)
            ->max('sort_order');

        return ($maxSortOrder ?? -1) + 1;
    }

    /**
     * Reorder metadata for an expense.
     */
    public static function reorderForExpense(int $pocketExpenseId, array $metadataIds): bool
    {
        try {
            foreach ($metadataIds as $index => $metadataId) {
                static::where('id', $metadataId)
                    ->where('pocket_expense_id', $pocketExpenseId)
                    ->update(['sort_order' => $index]);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Duplicate metadata from one expense to another.
     */
    public static function duplicateFromExpense(int $sourcePocketExpenseId, int $targetPocketExpenseId): bool
    {
        try {
            $sourceMetadata = static::forExpense($sourcePocketExpenseId)->get();
            $currentTimestamp = now();

            $insertData = [];
            foreach ($sourceMetadata as $metadata) {
                $insertData[] = [
                    'pocket_expense_id' => $targetPocketExpenseId,
                    'metadata_type' => $metadata->metadata_type,
                    'details_json' => $metadata->details_json,
                    'value' => $metadata->value,
                    'label' => $metadata->label,
                    'is_required' => $metadata->is_required,
                    'is_editable' => $metadata->is_editable,
                    'sort_order' => $metadata->sort_order,
                    'category_id' => $metadata->category_id,
                    'source_id' => $metadata->source_id,
                    'country_id' => $metadata->country_id,
                    'reference_type' => $metadata->reference_type,
                    'reference_id' => $metadata->reference_id,
                    'created_at' => $currentTimestamp,
                    'updated_at' => $currentTimestamp,
                ];
            }

            if (!empty($insertData)) {
                static::insert($insertData);
                return true;
            }
        } catch (\Exception $e) {
            // Log the exception if needed
        }

        return false;
    }

    /**
     * Clean up orphaned metadata (where pocket_expense_id doesn't exist).
     */
    public static function cleanupOrphaned(): int
    {
        return static::whereNotExists(function ($query) {
            $query->select('id')
                ->from('pocket_expenses')
                ->whereRaw('pocket_expenses.id = pocket_expense_metadata.pocket_expense_id');
        })->delete();
    }

    /**
     * Get metadata statistics.
     */
    public static function getStatistics(): array
    {
        $total = static::count();
        $byType = static::selectRaw('metadata_type, COUNT(*) as count')
            ->groupBy('metadata_type')
            ->pluck('count', 'metadata_type')
            ->toArray();

        return [
            'total_metadata' => $total,
            'by_type' => $byType,
            'required_percentage' => $total > 0 ? round((static::where('is_required', true)->count() / $total) * 100, 2) : 0,
            'editable_percentage' => $total > 0 ? round((static::where('is_editable', true)->count() / $total) * 100, 2) : 0,
        ];
    }
}