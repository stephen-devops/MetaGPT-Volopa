## Code: app/Models/PocketExpense.php

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * PocketExpense Model
 * 
 * Represents out-of-pocket expenses with multi-tenant support and soft delete.
 * Manages expense records with FX conversion, metadata, and approval workflow.
 * 
 * @property int $id
 * @property string|null $uuid External reference UUID
 * @property int $user_id User who owns this expense
 * @property int $client_id Client context for multi-tenancy
 * @property \Illuminate\Support\Carbon $date Expense date
 * @property string $merchant_name Name of the merchant
 * @property string|null $merchant_description Description of the merchant/expense
 * @property int $expense_type Reference to opt_pocket_expense_type
 * @property string $currency 3-letter ISO currency code
 * @property float $amount Expense amount with 4 decimal precision
 * @property string|null $merchant_address Address of the merchant
 * @property float|null $vat_amount VAT amount with 4 decimal precision
 * @property string|null $notes Additional notes for the expense
 * @property string $status Current status of the expense
 * @property int $created_by_user_id User who created this record
 * @property int|null $updated_by_user_id User who last updated this record
 * @property int|null $approved_by_user_id User who approved this expense
 * @property \Illuminate\Support\Carbon $create_time Record creation time
 * @property \Illuminate\Support\Carbon $update_time Record last update time
 * @property bool $deleted Soft delete flag
 * @property \Illuminate\Support\Carbon|null $delete_time When record was deleted
 * 
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Client $client
 * @property-read \App\Models\OptPocketExpenseType $expenseType
 * @property-read \App\Models\User $createdBy
 * @property-read \App\Models\User|null $updatedBy
 * @property-read \App\Models\User|null $approvedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PocketExpenseMetadata> $metadata
 */
class PocketExpense extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pocket_expense';

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
        'uuid',
        'user_id',
        'client_id',
        'date',
        'merchant_name',
        'merchant_description',
        'expense_type',
        'currency',
        'amount',
        'merchant_address',
        'vat_amount',
        'notes',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
        'approved_by_user_id',
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
        'uuid' => 'string',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'date' => 'date',
        'merchant_name' => 'string',
        'merchant_description' => 'string',
        'expense_type' => 'integer',
        'currency' => 'string',
        'amount' => 'decimal:4',
        'merchant_address' => 'string',
        'vat_amount' => 'decimal:4',
        'notes' => 'string',
        'status' => 'string',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'approved_by_user_id' => 'integer',
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
        'status' => 'draft',
        'deleted' => false,
    ];

    /**
     * The possible values for status enum.
     *
     * @var array<int, string>
     */
    public const STATUS_VALUES = [
        'draft',
        'submitted',
        'approved',
        'rejected',
    ];

    /**
     * Maximum length for merchant name field.
     *
     * @var int
     */
    public const MERCHANT_NAME_MAX_LENGTH = 180;

    /**
     * Maximum age in years for expense date validation.
     *
     * @var int
     */
    public const MAX_EXPENSE_AGE_YEARS = 3;

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        // Generate UUID on creation
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
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

        // Ensure all queries are scoped by authenticated user's client context
        static::addGlobalScope('client_scoped', function (Builder $builder) {
            if (auth()->check() && auth()->user()->client_id) {
                $builder->where('client_id', auth()->user()->client_id);
            }
        });
    }

    /**
     * Get the user who owns this expense.
     *
     * @return BelongsTo<\App\Models\User, PocketExpense>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the client context for this expense.
     *
     * @return BelongsTo<\App\Models\Client, PocketExpense>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the expense type that this