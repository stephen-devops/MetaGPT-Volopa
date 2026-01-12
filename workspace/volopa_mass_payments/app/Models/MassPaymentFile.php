<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MassPaymentFile extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mass_payment_files';

    protected $fillable = [
        'client_id',
        'tcc_account_id',
        'filename',
        'file_path',
        'status',
        'total_rows',
        'valid_rows',
        'error_rows',
        'validation_errors',
        'total_amount',
        'currency',
        'uploaded_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'validation_errors' => 'array',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'client_id' => 'integer',
        'tcc_account_id' => 'integer',
        'uploaded_by' => 'integer',
        'approved_by' => 'integer',
    ];

    protected $attributes = [
        'status' => 'uploading',
        'total_rows' => 0,
        'valid_rows' => 0,
        'error_rows' => 0,
        'total_amount' => 0.00,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('client', function (Builder $query) {
            if (Auth::check() && Auth::user()->client_id) {
                $query->where('client_id', Auth::user()->client_id);
            }
        });
    }

    /**
     * Get the TCC account that owns the mass payment file.
     */
    public function tccAccount(): BelongsTo
    {
        return $this->belongsTo(TccAccount::class, 'tcc_account_id');
    }

    /**
     * Get the payment instructions for the mass payment file.
     */
    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class, 'mass_payment_file_id');
    }

    /**
     * Get the user who uploaded the file.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the user who approved the file.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to only include files with a specific status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include files for a specific TCC account.
     */
    public function scopeByTccAccount(Builder $query, int $tccAccountId): Builder
    {
        return $query->where('tcc_account_id', $tccAccountId);
    }

    /**
     * Scope a query to only include files uploaded by a specific user.
     */
    public function scopeByUploader(Builder $query, int $uploaderId): Builder
    {
        return $query->where('uploaded_by', $uploaderId);
    }

    /**
     * Scope a query to only include files with a specific currency.
     */
    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope a query to only include files that need approval.
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_approval');
    }

    /**
     * Scope a query to only include approved files.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include files with validation errors.
     */
    public function scopeWithValidationErrors(Builder $query): Builder
    {
        return $query->where('status', 'validation_failed')
                    ->whereNotNull('validation_errors');
    }

    /**
     * Check if the file is in a pending state.
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['uploading', 'uploaded', 'validating', 'validated', 'pending_approval']);
    }

    /**
     * Check if the file has been approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if the file has been rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if the file has validation errors.
     */
    public function hasValidationErrors(): bool
    {
        return $this->status === 'validation_failed' && !empty($this->validation_errors);
    }

    /**
     * Check if the file is processing or processed.
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, ['processing', 'processed']);
    }

    /**
     * Check if the file is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the file has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
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
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->valid_rows / $this->total_rows) * 100, 2);
    }

    /**
     * Get the error rate as a percentage.
     */
    public function getErrorRate(): float
    {
        if ($this->total_rows === 0) {
            return 0.0;
        }

        return round(($this->error_rows / $this->total_rows) * 100, 2);
    }

    /**
     * Update the file status with optional additional data.
     */
    public function updateStatus(string $status, array $additionalData = []): bool
    {
        $data = array_merge(['status' => $status], $additionalData);
        
        return $this->update($data);
    }

    /**
     * Mark the file as approved by a specific user.
     */
    public function markAsApproved(int $approverId): bool
    {
        return $this->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    /**
     * Mark the file as rejected with a reason.
     */
    public function markAsRejected(string $reason): bool
    {
        return $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Update validation results.
     */
    public function updateValidationResults(int $totalRows, int $validRows, int $errorRows, array $errors = []): bool
    {
        return $this->update([
            'total_rows' => $totalRows,
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'validation_errors' => $errors,
            'status' => empty($errors) ? 'validated' : 'validation_failed',
        ]);
    }

    /**
     * Calculate and update the total amount from payment instructions.
     */
    public function updateTotalAmount(): bool
    {
        $totalAmount = $this->paymentInstructions()
                           ->where('status', '!=', 'failed_validation')
                           ->sum('amount');

        return $this->update(['total_amount' => $totalAmount]);
    }
}