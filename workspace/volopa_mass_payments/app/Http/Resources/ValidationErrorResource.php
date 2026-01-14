## Code: app/Http/Resources/ValidationErrorResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ValidationErrorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->getErrorType(),
            'severity' => $this->getErrorSeverity(),
            'code' => $this->getErrorCode(),
            'message' => $this->getErrorMessage(),
            'description' => $this->getErrorDescription(),
            'field' => $this->getErrorField(),
            'value' => $this->getErrorValue(),
            'row_number' => $this->getRowNumber(),
            'column' => $this->getColumn(),
            'context' => $this->getErrorContext(),
            'suggestion' => $this->getErrorSuggestion(),
            'help_url' => $this->getHelpUrl(),
            'category' => $this->getErrorCategory(),
            'is_critical' => $this->isCriticalError(),
            'is_fixable' => $this->isFixableError(),
            'metadata' => $this->getErrorMetadata(),
            'location' => [
                'file' => $this->getSourceFile(),
                'row' => $this->getRowNumber(),
                'column' => $this->getColumn(),
                'field_path' => $this->getFieldPath(),
            ],
            'validation_rule' => [
                'rule_name' => $this->getValidationRuleName(),
                'rule_parameters' => $this->getValidationRuleParameters(),
                'expected_format' => $this->getExpectedFormat(),
                'actual_format' => $this->getActualFormat(),
            ],
            'remediation' => [
                'suggested_value' => $this->getSuggestedValue(),
                'correction_steps' => $this->getCorrectionSteps(),
                'required_action' => $this->getRequiredAction(),
                'can_auto_fix' => $this->canAutoFix(),
            ],
            'impact' => [
                'severity_level' => $this->getSeverityLevel(),
                'blocks_processing' => $this->blocksProcessing(),
                'affects_compliance' => $this->affectsCompliance(),
                'financial_impact' => $this->hasFinancialImpact(),
            ],
            'related_errors' => $this->getRelatedErrors(),
            'timestamps' => [
                'detected_at' => $this->getDetectedAt(),
                'first_occurrence' => $this->getFirstOccurrence(),
                'last_occurrence' => $this->getLastOccurrence(),
            ],
        ];
    }

    /**
     * Get error type from resource data.
     */
    private function getErrorType(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['type'] ?? 'validation_error';
        }

        return $this->type ?? 'validation_error';
    }

    /**
     * Get error severity level.
     */
    private function getErrorSeverity(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['severity'] ?? 'error';
        }

        return $this->severity ?? 'error';
    }

    /**
     * Get standardized error code.
     */
    private function getErrorCode(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['code'] ?? $this->generateErrorCode();
        }

        return $this->code ?? $this->generateErrorCode();
    }

    /**
     * Get human-readable error message.
     */
    private function getErrorMessage(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['message'] ?? 'Unknown validation error';
        }

        if (is_string($this->resource)) {
            return $this->resource;
        }

        return $this->message ?? 'Unknown validation error';
    }

    /**
     * Get detailed error description.
     */
    private function getErrorDescription(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['description'] ?? $this->generateDescription();
        }

        return $this->description ?? $this->generateDescription();
    }

    /**
     * Get the field that has the error.
     */
    private function getErrorField(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['field'] ?? null;
        }

        return $this->field ?? null;
    }

    /**
     * Get the invalid value.
     */
    private function getErrorValue(): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource['value'] ?? null;
        }

        return $this->value ?? null;
    }

    /**
     * Get row number where error occurred.
     */
    private function getRowNumber(): ?int
    {
        if (is_array($this->resource)) {
            return $this->resource['row_number'] ?? $this->resource['row'] ?? null;
        }

        return $this->row_number ?? $this->row ?? null;
    }

    /**
     * Get column number or name where error occurred.
     */
    private function getColumn(): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource['column'] ?? null;
        }

        return $this->column ?? null;
    }

    /**
     * Get additional context for the error.
     */
    private function getErrorContext(): array
    {
        if (is_array($this->resource)) {
            $context = $this->resource['context'] ?? [];
        } else {
            $context = $this->context ?? [];
        }

        // Ensure context is always an array
        if (!is_array($context)) {
            $context = [];
        }

        // Add default context if missing
        return array_merge([
            'currency' => null,
            'beneficiary_type' => null,
            'payment_amount' => null,
            'file_section' => null,
        ], $context);
    }

    /**
     * Get error correction suggestion.
     */
    private function getErrorSuggestion(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['suggestion'] ?? $this->generateSuggestion();
        }

        return $this->suggestion ?? $this->generateSuggestion();
    }

    /**
     * Get help documentation URL.
     */
    private function getHelpUrl(): ?string
    {
        $field = $this->getErrorField();
        $errorType = $this->getErrorType();
        
        if ($field) {
            return config('volopa.documentation.base_url', 'https://docs.volopa.com') . 
                   "/validation-errors/{$field}";
        }

        return config('volopa.documentation.base_url', 'https://docs.volopa.com') . 
               "/validation-errors/{$errorType}";
    }

    /**
     * Get error category.
     */
    private function getErrorCategory(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['category'] ?? $this->categorizeError();
        }

        return $this->category ?? $this->categorizeError();
    }

    /**
     * Check if this is a critical error.
     */
    private function isCriticalError(): bool
    {
        $severity = $this->getErrorSeverity();
        $category = $this->getErrorCategory();
        
        return in_array($severity, ['critical', 'error']) ||
               in_array($category, ['security', 'compliance', 'financial']);
    }

    /**
     * Check if this error can be automatically fixed.
     */
    private function isFixableError(): bool
    {
        if (is_array($this->resource)) {
            return $this->resource['is_fixable'] ?? $this->determineIfFixable();
        }

        return $this->is_fixable ?? $this->determineIfFixable();
    }

    /**
     * Get error metadata.
     */
    private function getErrorMetadata(): array
    {
        if (is_array($this->resource)) {
            $metadata = $this->resource['metadata'] ?? [];
        } else {
            $metadata = $this->metadata ?? [];
        }

        // Ensure metadata is always an array
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return array_merge([
            'error_id' => $this->generateErrorId(),
            'source' => 'validation',
            'version' => '1.0',
            'language' => 'en',
        ], $metadata);
    }

    /**
     * Get source file name.
     */
    private function getSourceFile(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['source_file'] ?? $this->resource['file'] ?? null;
        }

        return $this->source_file ?? $this->file ?? null;
    }

    /**
     * Get field path for nested structures.
     */
    private function getFieldPath(): ?string
    {
        $field = $this->getErrorField();
        $row = $this->getRowNumber();
        
        if ($field && $row) {
            return "row[{$row}].{$field}";
        }
        
        return $field;
    }

    /**
     * Get validation rule name.
     */
    private function getValidationRuleName(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['rule'] ?? $this->resource['validation_rule'] ?? null;
        }

        return $this->rule ?? $this->validation_rule ?? null;
    }

    /**
     * Get validation rule parameters.
     */
    private function getValidationRuleParameters(): array
    {
        if (is_array($this->resource)) {
            $params = $this->resource['rule_parameters'] ?? [];
        } else {
            $params = $this->rule_parameters ?? [];
        }

        return is_array($params) ? $params : [];
    }

    /**
     * Get expected value format.
     */
    private function getExpectedFormat(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['expected_format'] ?? $this->generateExpectedFormat();
        }

        return $this->expected_format ?? $this->generateExpectedFormat();
    }

    /**
     * Get actual value format.
     */
    private function getActualFormat(): ?string
    {
        $value = $this->getErrorValue();
        
        if ($value === null) {
            return 'null';
        }
        
        if (is_string($value)) {
            return strlen($value) === 0 ? 'empty_string' : 'string';
        }
        
        return gettype($value);
    }

    /**
     * Get suggested correction value.
     */
    private function getSuggestedValue(): mixed
    {
        if (is_array($this->resource)) {
            return $this->resource['suggested_value'] ?? null;
        }

        return $this->suggested_value ?? null;
    }

    /**
     * Get step-by-step correction instructions.
     */
    private function getCorrectionSteps(): array
    {
        if (is_array($this->resource)) {
            $steps = $this->resource['correction_steps'] ?? [];
        } else {
            $steps = $this->correction_steps ?? [];
        }

        if (!is_array($steps)) {
            return [];
        }

        return array_values($steps); // Ensure indexed array
    }

    /**
     * Get required action for error resolution.
     */
    private function getRequiredAction(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['required_action'] ?? $this->determineRequiredAction();
        }

        return $this->required_action ?? $this->determineRequiredAction();
    }

    /**
     * Check if error can be automatically fixed.
     */
    private function canAutoFix(): bool
    {
        $field = $this->getErrorField();
        $category = $this->getErrorCategory();
        
        // Auto-fixable field types
        $autoFixableFields = [
            'beneficiary_email',
            'beneficiary_phone',
            'beneficiary_country',
            'currency',
            'amount',
        ];

        // Auto-fixable categories
        $autoFixableCategories = [
            'format',
            'case_sensitivity',
            'whitespace',
        ];

        return in_array($field, $autoFixableFields) ||
               in_array($category, $autoFixableCategories);
    }

    /**
     * Get severity level as integer.
     */
    private function getSeverityLevel(): int
    {
        return match ($this->getErrorSeverity()) {
            'critical' => 4,
            'error' => 3,
            'warning' => 2,
            'info' => 1,
            default => 0,
        };
    }

    /**
     * Check if error blocks processing.
     */
    private function blocksProcessing(): bool
    {
        $severity = $this->getErrorSeverity();
        $category = $this->getErrorCategory();
        
        return in_array($severity, ['critical', 'error']) ||
               in_array($category, ['required_field', 'data_integrity', 'compliance']);
    }

    /**
     * Check if error affects regulatory compliance.
     */
    private function affectsCompliance(): bool
    {
        $category = $this->getErrorCategory();
        $field = $this->getErrorField();
        
        $complianceFields = [
            'invoice_number',
            'invoice_date',
            'incorporation_number',
            'purpose_code',
            'beneficiary_country',
        ];

        return $category === 'compliance' || in_array($field, $complianceFields);
    }

    /**
     * Check if error has financial impact.
     */
    private function hasFinancialImpact(): bool
    {
        $field = $this->getErrorField();
        $category = $this->getErrorCategory();
        
        $financialFields = [
            'amount',
            'currency',
            'beneficiary_account_number',
            'beneficiary_iban',
            'beneficiary_swift_code',
        ];

        return $category === 'financial' || in_array($field, $financialFields);
    }

    /**
     * Get related errors.
     */
    private function getRelatedErrors(): array
    {
        if (is_array($this->resource)) {
            $related = $this->resource['related_errors'] ?? [];
        } else {
            $related = $this->related_errors ?? [];
        }

        return is_array($related) ? $related : [];
    }

    /**
     * Get error detection timestamp.
     */
    private function getDetectedAt(): string
    {
        if (is_array($this->resource)) {
            $timestamp = $this->resource['detected_at'] ?? null;
        } else {
            $timestamp = $this->detected_at ?? null;
        }

        if ($timestamp) {
            return Carbon::parse($timestamp)->toISOString();
        }

        return Carbon::now()->toISOString();
    }

    /**
     * Get first occurrence timestamp.
     */
    private function getFirstOccurrence(): string
    {
        if (is_array($this->resource)) {
            $timestamp = $this->resource['first_occurrence'] ?? null;
        } else {
            $timestamp = $this->first_occurrence ?? null;
        }

        if ($timestamp) {
            return Carbon::parse($timestamp)->toISOString();
        }

        return $this->getDetectedAt();
    }

    /**
     * Get last occurrence timestamp.
     */
    private function getLastOccurrence(): string
    {
        if (is_