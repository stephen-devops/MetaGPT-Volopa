## Code: app/Models/PocketExpenseMetadata.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class PocketExpenseMetadata extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense_metadata';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'pocket_expense_id',
        'metadata_type',
        'transaction_category_id',
        'tracking_code_id',
        'project_id',
        'file_store_id',
        'expense_source_id',
        'additional_field_id',
        'user_id',
        'details_json',
        'deleted',
        'delete_time',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'pocket_expense_id' => 'integer',
        'metadata_type' => 'string',
        'transaction_category_id' => 'integer',
        'tracking_code_id' => 'integer',
        'project_id' => 'integer',
        'file_store_id' => 'integer',
        'expense_source_id' => 'integer',
        'additional_field_id' => 'integer',
        'user_id' => 'integer',
        'details_json' => 'array',
        'create_time' => 'datetime',
        'update_time' => 'datetime',
        'deleted' => 'boolean',
        'delete_time' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'metadata_type' => 'other',
        'deleted' => false,
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'create_time',
        'update_time',
        'delete_time',
    ];

    /**
     * Define the timestamp column names for custom timestamp fields.
     *
     * @var string
     */
    const CREATED_AT = 'create_time';
    const UPDATED_AT = 'update_time';

    /**
     * Valid metadata types for pocket expense metadata.
     *
     * @var array<string>
     */
    const VALID_METADATA_TYPES = [
        'category',
        'tracking_code',
        'project',
        'file_store',
        'expense_source',
        'additional_field',
        'other',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Set create_time and update_time when creating
        static::creating(function (self $model): void {
            $model->create_time = now();
            $model->update_time = now();
        });

        // Update the update_time when saving
        static::updating(function (self $model): void {
            $model->update_time = now();
        });
    }

    /**
     * Get the pocket expense that this metadata belongs to.
     *
     * @return BelongsTo
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the transaction category for this metadata.
     *
     * @return BelongsTo
     */
    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code for this metadata.
     *
     * @return BelongsTo
     */
    public function trackingCode(): BelongsTo
    {
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the configurable project for this metadata.
     *
     * @return BelongsTo
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ConfigurableProject::class, 'project_id');
    }

    /**
     * Get the file store for this metadata.
     *
     * @return BelongsTo
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class, 'file_store_id');
    }

    /**
     * Get the expense source for this metadata.
     *
     * @return BelongsTo
     */
    public function expenseSource(): BelongsTo
    {
        return $this->belongsTo(PocketExpenseSourceClientConfig::class, 'expense_source_id');
    }

    /**
     * Get the additional field for this metadata.
     *
     * @return BelongsTo
     */
    public function additionalField(): BelongsTo
    {
        return $this->belongsTo(ExpenseAdditionalField::class, 'additional_field_id');
    }

    /**
     * Get the user associated with this metadata.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope a query to only include active (not deleted) metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false);
    }

    /**
     * Scope a query to only include deleted metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDeleted(Builder $query): Builder
    {
        return $query->where('deleted', true);
    }

    /**
     * Scope a query to filter by pocket expense.
     *
     * @param Builder $query
     * @param int $pocketExpenseId
     * @return Builder
     */
    public function scopeForExpense(Builder $query, int $pocketExpenseId): Builder
    {
        return $query->where('pocket_expense_id', $pocketExpenseId);
    }

    /**
     * Scope a query to filter by metadata type.
     *
     * @param Builder $query
     * @param string $metadataType
     * @return Builder
     */
    public function scopeByType(Builder $query, string $metadataType): Builder
    {
        return $query->where('metadata_type', $metadataType);
    }

    /**
     * Scope a query to filter by user.
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to get category metadata.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeCategory(Builder $query): Builder
    {
        return $query->where('metadata_type', 'category');
    }

    /**
     * Scope a query to get tracking code metadata.
     *
     * @param Builder $query
     