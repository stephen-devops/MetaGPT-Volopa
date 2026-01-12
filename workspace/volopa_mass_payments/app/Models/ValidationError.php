<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationError extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'payment_file_id',
        'row_number',
        'field_name',
        'error_message',
        'error_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payment_file_id' => 'integer',
        'row_number' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Available error codes for validation errors.
     */
    public const ERROR_REQUIRED_FIELD = 'REQUIRED_FIELD';
    public const ERROR_INVALID_FORMAT = 'INVALID_FORMAT';
    public const ERROR_INVALID_CURRENCY = 'INVALID_CURRENCY';
    public const ERROR_INVALID_AMOUNT = 'INVALID_AMOUNT';
    public const ERROR_INVALID_ACCOUNT = 'INVALID_ACCOUNT';
    public const ERROR_INVALID_SETTLEMENT_METHOD = 'INVALID_SETTLEMENT_METHOD';
    public const ERROR_AMOUNT_TOO_LARGE = 'AMOUNT_TOO_LARGE';
    public const ERROR_AMOUNT_TOO_SMALL = 'AMOUNT_TOO_SMALL';
    public const ERROR_FIELD_TOO_LONG = 'FIELD_TOO_LONG';
    public const ERROR_UNSUPPORTED_CURRENCY_SETTLEMENT = 'UNSUPPORTED_CURRENCY_SETTLEMENT';
    public const ERROR_DUPLICATE_REFERENCE = 'DUPLICATE_REFERENCE';

    /**
     * Available field names that can have validation errors.
     */
    public const FIELD_BENEFICIARY_NAME = 'beneficiary_name';
    public const FIELD_BENEFICIARY_ACCOUNT = 'beneficiary_account';
    public const FIELD_AMOUNT = 'amount';
    public const FIELD_CURRENCY = 'currency';
    public const FIELD_SETTLEMENT_METHOD = 'settlement_method';
    public const FIELD_PAYMENT_PURPOSE = 'payment_purpose';
    public const FIELD_REFERENCE = 'reference';

    /**
     * Get the payment file that owns the validation error.
     */
    public function paymentFile(): BelongsTo
    {
        return $this->belongsTo(PaymentFile::class);
    }

    /**
     * Scope a query to only include errors for a specific payment file.
     */
    public function scopeForPaymentFile($query, int $paymentFileId)
    {
        return $query->where('payment_file_id', $paymentFileId);
    }

    /**
     * Scope a query to only include errors for a specific row number.
     */
    public function scopeForRow($query, int $rowNumber)
    {
        return $query->where('row_number', $rowNumber);
    }

    /**
     * Scope a query to only include errors for a specific field.
     */
    public function scopeForField($query, string $fieldName)
    {
        return $query->where('field_name', $fieldName);
    }

    /**
     * Scope a query to only include errors with a specific error code.
     */
    public function scopeWithErrorCode($query, string $errorCode)
    {
        return $query->where('error_code', $errorCode);
    }

    /**
     * Scope a query to order errors by row number and then by field name.
     */
    public function scopeOrderedByRow($query)
    {
        return $query->orderBy('row_number')->orderBy('field_name');
    }

    /**
     * Scope a query to group errors by row number.
     */
    public function scopeGroupedByRow($query)
    {
        return $query->orderBy('row_number')->orderBy('created_at');
    }

    /**
     * Get the error severity based on the error code.
     */
    public function getSeverityAttribute(): string
    {
        $criticalErrors = [
            self::ERROR_REQUIRED_FIELD,
            self::ERROR_INVALID_CURRENCY,
            self::ERROR_INVALID_AMOUNT,
            self::ERROR_INVALID_ACCOUNT,
            self::ERROR_UNSUPPORTED_CURRENCY_SETTLEMENT,
        ];

        return in_array($this->error_code, $criticalErrors) ? 'critical' : 'warning';
    }

    /**
     * Check if this is a critical error that prevents processing.
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * Check if this is a warning that doesn't prevent processing.
     */
    public function isWarning(): bool
    {
        return $this->severity === 'warning';
    }

    /**
     * Get the user-friendly error message.
     */
    public function getUserFriendlyMessageAttribute(): string
    {
        $messages = [
            self::ERROR_REQUIRED_FIELD => "The {$this->field_name} field is required.",
            self::ERROR_INVALID_FORMAT => "The {$this->field_name} field has an invalid format.",
            self::ERROR_INVALID_CURRENCY => "The currency code is not supported.",
            self::ERROR_INVALID_AMOUNT => "The amount must be a valid positive number.",
            self::ERROR_INVALID_ACCOUNT => "The beneficiary account number is invalid.",
            self::ERROR_INVALID_SETTLEMENT_METHOD => "The settlement method is not supported.",
            self::ERROR_AMOUNT_TOO_LARGE => "The amount exceeds the maximum allowed limit.",
            self::ERROR_AMOUNT_TOO_SMALL => "The amount is below the minimum required amount.",
            self::ERROR_FIELD_TOO_LONG => "The {$this->field_name} field is too long.",
            self::ERROR_UNSUPPORTED_CURRENCY_SETTLEMENT => "The settlement method is not supported for this currency.",
            self::ERROR_DUPLICATE_REFERENCE => "The payment reference must be unique within the file.",
        ];

        return $messages[$this->error_code] ?? $this->error_message;
    }

    /**
     * Create a new validation error record.
     */
    public static function createError(
        int $paymentFileId,
        int $rowNumber,
        string $fieldName,
        string $errorMessage,
        string $errorCode
    ): self {
        return self::create([
            'payment_file_id' => $paymentFileId,
            'row_number' => $rowNumber,
            'field_name' => $fieldName,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
        ]);
    }

    /**
     * Create multiple validation errors in bulk.
     */
    public static function createBulkErrors(array $errors): bool
    {
        if (empty($errors)) {
            return true;
        }

        // Ensure all errors have timestamps
        $timestamp = now();
        foreach ($errors as &$error) {
            $error['created_at'] = $timestamp;
            $error['updated_at'] = $timestamp;
        }

        return self::insert($errors);
    }

    /**
     * Get all valid error codes.
     */
    public static function getValidErrorCodes(): array
    {
        return [
            self::ERROR_REQUIRED_FIELD,
            self::ERROR_INVALID_FORMAT,
            self::ERROR_INVALID_CURRENCY,
            self::ERROR_INVALID_AMOUNT,
            self::ERROR_INVALID_ACCOUNT,
            self::ERROR_INVALID_SETTLEMENT_METHOD,
            self::ERROR_AMOUNT_TOO_LARGE,
            self::ERROR_AMOUNT_TOO_SMALL,
            self::ERROR_FIELD_TOO_LONG,
            self::ERROR_UNSUPPORTED_CURRENCY_SETTLEMENT,
            self::ERROR_DUPLICATE_REFERENCE,
        ];
    }

    /**
     * Get all valid field names.
     */
    public static function getValidFieldNames(): array
    {
        return [
            self::FIELD_BENEFICIARY_NAME,
            self::FIELD_BENEFICIARY_ACCOUNT,
            self::FIELD_AMOUNT,
            self::FIELD_CURRENCY,
            self::FIELD_SETTLEMENT_METHOD,
            self::FIELD_PAYMENT_PURPOSE,
            self::FIELD_REFERENCE,
        ];
    }

    /**
     * Get error statistics for a payment file.
     */
    public static function getErrorStatistics(int $paymentFileId): array
    {
        $errors = self::forPaymentFile($paymentFileId)->get();
        
        $statistics = [
            'total_errors' => $errors->count(),
            'critical_errors' => 0,
            'warning_errors' => 0,
            'affected_rows' => $errors->pluck('row_number')->unique()->count(),
            'error_breakdown' => [],
            'field_breakdown' => [],
        ];

        foreach ($errors as $error) {
            // Count by severity
            if ($error->isCritical()) {
                $statistics['critical_errors']++;
            } else {
                $statistics['warning_errors']++;
            }

            // Count by error code
            $errorCode = $error->error_code;
            if (!isset($statistics['error_breakdown'][$errorCode])) {
                $statistics['error_breakdown'][$errorCode] = 0;
            }
            $statistics['error_breakdown'][$errorCode]++;

            // Count by field name
            $fieldName = $error->field_name;
            if (!isset($statistics['field_breakdown'][$fieldName])) {
                $statistics['field_breakdown'][$fieldName] = 0;
            }
            $statistics['field_breakdown'][$fieldName]++;
        }

        return $statistics;
    }

    /**
     * Get errors grouped by row number for a payment file.
     */
    public static function getErrorsByRow(int $paymentFileId): array
    {
        $errors = self::forPaymentFile($paymentFileId)
            ->orderedByRow()
            ->get();

        $errorsByRow = [];
        foreach ($errors as $error) {
            $rowNumber = $error->row_number;
            if (!isset($errorsByRow[$rowNumber])) {
                $errorsByRow[$rowNumber] = [];
            }
            $errorsByRow[$rowNumber][] = $error;
        }

        return $errorsByRow;
    }

    /**
     * Check if a payment file has critical errors.
     */
    public static function hasCriticalErrors(int $paymentFileId): bool
    {
        $criticalErrorCodes = [
            self::ERROR_REQUIRED_FIELD,
            self::ERROR_INVALID_CURRENCY,
            self::ERROR_INVALID_AMOUNT,
            self::ERROR_INVALID_ACCOUNT,
            self::ERROR_UNSUPPORTED_CURRENCY_SETTLEMENT,
        ];

        return self::forPaymentFile($paymentFileId)
            ->whereIn('error_code', $criticalErrorCodes)
            ->exists();
    }

    /**
     * Delete all validation errors for a payment file.
     */
    public static function deleteForPaymentFile(int $paymentFileId): bool
    {
        return self::forPaymentFile($paymentFileId)->delete() !== false;
    }

    /**
     * Get the most common error types for reporting.
     */
    public static function getMostCommonErrors(int $limit = 10): array
    {
        return self::selectRaw('error_code, COUNT(*) as count')
            ->groupBy('error_code')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get the most problematic fields for reporting.
     */
    public static function getMostProblematicFields(int $limit = 10): array
    {
        return self::selectRaw('field_name, COUNT(*) as count')
            ->groupBy('field_name')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}