<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class MassPaymentFile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'mass_payment_files';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the auto-incrementing ID.
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'client_id',
        'tcc_account_id',
        'filename',
        'file_path',
        'total_amount',
        'currency',
        'status',
        'validation_errors',
        'total_instructions',
        'valid_instructions',
        'invalid_instructions',
        'approved_by',
        'approved_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'id' => 'string',
        'client_id' => 'string',
        'tcc_account_id' => 'string',
        'filename' => 'string',
        'file_path' => 'string',
        'total_amount' => 'decimal:2',
        'currency' => 'string',
        'status' => 'string',
        'validation_errors' => 'array',
        'total_instructions' => 'integer',
        'valid_instructions' => 'integer',
        'invalid_instructions' => 'integer',
        'approved_by' => 'string',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be guarded from mass assignment.
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'file_path',
        'deleted_at',
    ];

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_VALIDATION_FAILED = 'validation_failed';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Valid status values
     */
    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_VALIDATING,
        self::STATUS_VALIDATION_FAILED,
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Apply client scoping globally
        static::addGlobalScope('client', function (Builder $query) {
            if (auth()->check() && auth()->user()->client_id) {
                $query->where('client_id', auth()->user()->client_id);
            }
        });
    }

    /**
     * Get the client that owns the mass payment file.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    /**
     * Get the TCC account that owns the mass payment file.
     */
    public function tccAccount(): BelongsTo
    {
        return $this->belongsTo(TccAccount::class, 'tcc_account_id', 'id');
    }

    /**
     * Get the payment instructions for the mass payment file.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class, 'mass_payment_file_id', 'id');
    }

    /**
     * Scope a query to filter by client ID.
     */
    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper($currency));
    }

    /**
     * Scope a query to get pending approval files.
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_APPROVAL);
    }

    /**
     * Scope a query to get completed files.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED]);
    }

    /**
     * Scope a query to get active processing files.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_VALIDATING,
            self::STATUS_APPROVED,
            self::STATUS_PROCESSING
        ]);
    }

    /**
     * Check if the file is in draft status.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Check if the file is validating.
     */
    public function isValidating(): bool
    {
        return $this->status === self::STATUS_VALIDATING;
    }

    /**
     * Check if the file validation failed.
     */
    public function hasValidationFailed(): bool
    {
        return $this->status === self::STATUS_VALIDATION_FAILED;
    }

    /**
     * Check if the file is awaiting approval.
     */
    public function isAwaitingApproval(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    /**
     * Check if the file is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the file is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the file is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the file has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the file can be approved.
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_AWAITING_APPROVAL;
    }

    /**
     * Check if the file can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_VALIDATION_FAILED,
            self::STATUS_FAILED
        ]);
    }

    /**
     * Check if the file has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return !empty($this->validation_errors);
    }

    /**
     * Get the validation error count.
     */
    public function getValidationErrorCount(): int
    {
        return is_array($this->validation_errors) ? count($this->validation_errors) : 0;
    }

    /**
     * Get the success rate as a percentage.
     */
    public function getSuccessRate(): float
    {
        if ($this->total_instructions === 0) {
            return 0.0;
        }

        return round(($this->valid_instructions / $this->total_instructions) * 100, 2);
    }

    /**
     * Get the file size in human-readable format.
     */
    public function getFileSizeAttribute(): string
    {
        if (!$this->file_path || !file_exists($this->file_path)) {
            return 'Unknown';
        }

        $bytes = filesize($this->file_path);
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Get the progress percentage for processing.
     */
    public function getProgressPercentage(): int
    {
        switch ($this->status) {
            case self::STATUS_DRAFT:
                return 0;
            case self::STATUS_VALIDATING:
                return 25;
            case self::STATUS_VALIDATION_FAILED:
                return 25;
            case self::STATUS_AWAITING_APPROVAL:
                return 50;
            case self::STATUS_APPROVED:
                return 75;
            case self::STATUS_PROCESSING:
                return 90;
            case self::STATUS_COMPLETED:
                return 100;
            case self::STATUS_FAILED:
                return 100;
            default:
                return 0;
        }
    }

    /**
     * Update the file status.
     */
    public function updateStatus(string $status, ?array $validationErrors = null): bool
    {
        if (!in_array($status, self::VALID_STATUSES)) {
            return false;
        }

        $this->status = $status;
        
        if ($validationErrors !== null) {
            $this->validation_errors = $validationErrors;
        }

        return $this->save();
    }

    /**
     * Mark the file as approved.
     */
    public function markAsApproved(string $approvedBy): bool
    {
        $this->status = self::STATUS_APPROVED;
        $this->approved_by = $approvedBy;
        $this->approved_at = Carbon::now();

        return $this->save();
    }

    /**
     * Update instruction counts.
     */
    public function updateInstructionCounts(int $total, int $valid, int $invalid): bool
    {
        $this->total_instructions = $total;
        $this->valid_instructions = $valid;
        $this->invalid_instructions = $invalid;

        return $this->save();
    }

    /**
     * Get the formatted status for display.
     */
    public function getFormattedStatus(): string
    {
        return ucwords(str_replace('_', ' ', $this->status));
    }

    /**
     * Get the status color for UI display.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_VALIDATING => 'blue',
            self::STATUS_VALIDATION_FAILED => 'red',
            self::STATUS_AWAITING_APPROVAL => 'yellow',
            self::STATUS_APPROVED => 'green',
            self::STATUS_PROCESSING => 'blue',
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            default => 'gray',
        };
    }
}