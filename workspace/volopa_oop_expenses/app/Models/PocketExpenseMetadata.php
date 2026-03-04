## Code: app/Models/PocketExpenseMetadata.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * PocketExpenseMetadata Model
 * 
 * Represents normalized metadata storage for pocket expenses.
 * Manages flexible metadata with different types and reference relationships.
 * 
 * @property int $id
 * @property int $pocket_expense_id Reference to pocket_expense table
 * @property string $metadata_type Type of metadata being stored
 * @property int|null $transaction_category_id Reference to transaction category
 * @property int|null $tracking_code_id Reference to tracking code
 * @property int|null $project_id Reference to project
 * @property int|null $file_store_id Reference to file store for receipts
 * @property int|null $expense_source_id Reference to expense source client config
 * @property int|null $additional_field_id Reference to additional field definition
 * @property int $user_id User who created this metadata
 * @property array|null $details_json Additional JSON data for flexible metadata storage
 * @property \Illuminate\Support\Carbon $create_time Record creation time
 * @property \Illuminate\Support\Carbon $update_time Record last update time
 * @property bool $deleted Soft delete flag
 * @property \Illuminate\Support\Carbon|null $delete_time When record was deleted
 * 
 * @property-read \App\Models\PocketExpense $pocketExpense
 * @property-read \App\Models\TransactionCategory|null $transactionCategory
 * @property-read \App\Models\TrackingCode|null $trackingCode
 * @property-read \App\Models\Project|null $project
 * @property-read \App\Models\FileStore|null $fileStore
 * @property-read \App\Models\PocketExpenseSourceClientConfig|null $expenseSource
 * @property-read \App\Models\AdditionalField|null $additionalField
 * @property-read \App\Models\User $user
 */
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
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'create_time';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = 'update_time';

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
     * The attributes that should be cast.
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
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'deleted' => false,
        'details_json' => null,
    ];

    /**
     * The possible values for metadata_type enum.
     *
     * @var array<int, string>
     */
    public const METADATA_TYPE_VALUES = [
        'category',
        'tracking_code',
        'project',
        'receipt',
        'source',
        'additional_field',
    ];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically set timestamps on create/update
        static::creating(function ($model) {
            if (!$model->create_time) {
                $model->create_time = now();
            }
            $model->update_time = now();
        });

        static::updating(function ($model) {
            $model->update_time = now();
        });

        // Ensure all queries exclude deleted records by default
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('deleted', false);
        });

        // Ensure all queries are scoped by client context through pocket_expense relationship
        static::addGlobalScope('client_scoped', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->whereHas('pocketExpense', function ($query) {
                    $query->where('client_id', auth()->user()->client_id);
                });
            }
        });
    }

    /**
     * Get the pocket expense that this metadata belongs to.
     *
     * @return BelongsTo<\App\Models\PocketExpense, PocketExpenseMetadata>
     */
    public function pocketExpense(): BelongsTo
    {
        return $this->belongsTo(PocketExpense::class, 'pocket_expense_id');
    }

    /**
     * Get the transaction category associated with this metadata.
     *
     * @return BelongsTo<\App\Models\TransactionCategory, PocketExpenseMetadata>
     */
    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * Get the tracking code associated with this metadata.
     *
     * @return BelongsTo<\App\Models\TrackingCode, PocketExpenseMetadata>
     */
    public function trackingCode(): BelongsTo
    {
        return $this->belongsTo(TrackingCode::class, 'tracking_code_id');
    }

    /**
     * Get the project associated with this metadata.
     *
     * @return BelongsTo<\App\Models\Project, PocketExpenseMetadata>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the file store associated with this metadata.
     *
     * @return BelongsTo<\App\Models\FileStore, PocketExpenseMetadata>
     */
    public function fileStore(): BelongsTo
    {
        return $this->belongsTo(FileStore::class, 