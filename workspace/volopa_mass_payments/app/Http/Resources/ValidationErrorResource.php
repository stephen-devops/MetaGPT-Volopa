## Code: app/Http/Resources/ValidationErrorResource.php

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValidationErrorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->generateErrorId(),
            'type' => $this->getErrorType(),
            'severity' => $this->getErrorSeverity(),
            'row_number' => $this->getRowNumber(),
            'column' => $this->getColumn(),
            'field' => $this->getField(),
            'value' => $this->when(
                $this->shouldIncludeValue($request),
                $this->getValue()
            ),
            'message' => $this->getMessage(),
            'description' => $this->getDescription(),
            'error_code' => $this->getErrorCode(),
            'suggested_fix' => $this->getSuggestedFix(),
            'validation_rule' => $this->getValidationRule(),
            'context' => $this->when(
                $this->shouldIncludeContext($request),
                $this->getContext()
            ),
            'metadata' => $this->when(
                $this->shouldIncludeMetadata($request),
                $this->formatMetadata()
            ),
            'created_at' => $this->getCreatedAt(),
            'display' => [
                'severity_color' => $this->getSeverityColor(),
                'severity_icon' => $this->getSeverityIcon(),
                'category_label' => $this->getCategoryLabel(),
                'formatted_row_column' => $this->getFormattedRowColumn(),
                'is_blocking' => $this->isBlockingError(),
                'can_be_ignored' => $this->canBeIgnored(),
                'priority_score' => $this->getPriorityScore()
            ],
            'resolution' => [
                'is_fixable' => $this->isFixable(),
                'fix_complexity' => $this->getFixComplexity(),
                'estimated_fix_time' => $this->getEstimatedFixTime(),
                'requires_manual_intervention' => $this->requiresManualIntervention(),
                'auto_fix_available' => $this->hasAutoFix()
            ]
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'type' => 'validation_error',
                'api_version' => 'v1',
                'timestamp' => now()->toISOString(),
                'error_categories' => $this->getErrorCategories(),
                'severity_levels' => $this->getSeverityLevels()
            ]
        ];
    }

    /**
     * Generate unique error ID for tracking.
     *
     * @return string
     */
    private function generateErrorId(): string
    {
        $rowNumber = $this->getRowNumber();
        $field = $this->getField();
        $errorCode = $this->getErrorCode();
        
        return md5("error_{$rowNumber}_{$field}_{$errorCode}");
    }

    /**
     * Get error type from resource data.
     *
     * @return string
     */
    private function getErrorType(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['type'] ?? 'validation_error';
        }

        return $this->resource->type ?? 'validation_error';
    }

    /**
     * Get error severity level.
     *
     * @return string
     */
    private function getErrorSeverity(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['severity'] ?? 'error';
        }

        return $this->resource->severity ?? 'error';
    }

    /**
     * Get row number where error occurred.
     *
     * @return int
     */
    private function getRowNumber(): int
    {
        if (is_array($this->resource)) {
            return (int) ($this->resource['row_number'] ?? 0);
        }

        return (int) ($this->resource->row_number ?? 0);
    }

    /**
     * Get column where error occurred.
     *
     * @return string|null
     */
    private function getColumn(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['column'] ?? null;
        }

        return $this->resource->column ?? null;
    }

    /**
     * Get field name where error occurred.
     *
     * @return string
     */
    private function getField(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['field'] ?? 'unknown';
        }

        return $this->resource->field ?? 'unknown';
    }

    /**
     * Get the invalid value.
     *
     * @return mixed
     */
    private function getValue()
    {
        if (is_array($this->resource)) {
            return $this->resource['value'] ?? null;
        }

        return $this->resource->value ?? null;
    }

    /**
     * Get error message.
     *
     * @return string
     */
    private function getMessage(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['message'] ?? 'Validation error occurred';
        }

        return $this->resource->message ?? 'Validation error occurred';
    }

    /**
     * Get detailed error description.
     *
     * @return string
     */
    private function getDescription(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['description'] ?? $this->getMessage();
        }

        return $this->resource->description ?? $this->getMessage();
    }

    /**
     * Get error code for categorization.
     *
     * @return string
     */
    private function getErrorCode(): string
    {
        if (is_array($this->resource)) {
            return $this->resource['error_code'] ?? 'VALIDATION_ERROR';
        }

        return $this->resource->error_code ?? 'VALIDATION_ERROR';
    }

    /**
     * Get suggested fix for the error.
     *
     * @return string|null
     */
    private function getSuggestedFix(): ?string
    {
        if (is_array($this->resource)) {
            $suggestedFix = $this->resource['suggested_fix'] ?? null;
        } else {
            $suggestedFix = $this->resource->suggested_fix ?? null;
        }

        // Generate suggested fix based on error code if not provided
        if (!$suggestedFix) {
            $suggestedFix = $this->generateSuggestedFix();
        }

        return $suggestedFix;
    }

    /**
     * Get validation rule that was violated.
     *
     * @return string|null
     */
    private function getValidationRule(): ?string
    {
        if (is_array($this->resource)) {
            return $this->resource['validation_rule'] ?? null;
        }

        return $this->resource->validation_rule ?? null;
    }

    /**
     * Get error context information.
     *
     * @return array
     */
    private function getContext(): array
    {
        if (is_array($this->resource)) {
            $context = $this->resource['context'] ?? [];
        } else {
            $context = $this->resource->context ?? [];
        }

        // Ensure context is an array
        if (!is_array($context)) {
            $context = [];
        }

        return array_merge([
            'file_position' => "Row {$this->getRowNumber()}, Column {$this->getColumn()}",
            'field_type' => $this->getFieldType(),
            'expected_format' => $this->getExpectedFormat(),
            'validation_timestamp' => now()->toISOString()
        ], $context);
    }

    /**
     * Get error metadata.
     *
     * @return array
     */
    private function formatMetadata(): array
    {
        if (is_array($this->resource)) {
            $metadata = $this->resource['metadata'] ?? [];
        } else {
            $metadata = $this->resource->metadata ?? [];
        }

        // Ensure metadata is an array
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return array_merge([
            'validation_engine' => 'csv_validation_service',
            'error_category' => $this->getErrorCategory(),
            'impact_level' => $this->getImpactLevel(),
            'frequency' => $this->getErrorFrequency(),
            'related_errors' => $this->getRelatedErrors(),
            'processing_stage' => $this->getProcessingStage()
        ], $metadata);
    }

    /**
     * Get created timestamp.
     *
     * @return string
     */
    private function getCreatedAt(): string
    {
        if (is_array($this->resource)) {
            $createdAt = $this->resource['created_at'] ?? null;
        } else {
            $createdAt = $this->resource->created_at ?? null;
        }

        if ($createdAt) {
            return is_string($createdAt) ? $createdAt : $createdAt->toISOString();
        }

        return now()->toISOString();
    }

    /**
     * Determine if value should be included in response.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeValue(Request $request): bool
    {
        // Don't include sensitive data values
        $sensitiveFields = [
            'account_number',
            'iban',
            'routing_number',
            'swift_code',
            'payment_reference'
        ];

        $field = $this->getField();
        if (in_array($field, $sensitiveFields)) {
            return false;
        }

        // Include if specifically requested
        return $request->boolean('include_values', true);
    }

    /**
     * Determine if context should be included in response.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeContext(Request $request): bool
    {
        return $request->boolean('include_context', false) ||
               $request->route()?->getName() === 'api.v1.validation-errors.show';
    }

    /**
     * Determine if metadata should be included in response.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldIncludeMetadata(Request $request): bool
    {
        return $request->boolean('include_metadata', false) ||
               $this->getErrorSeverity() === 'critical';
    }

    /**
     * Get severity color for UI display.
     *
     * @return string
     */
    private function getSeverityColor(): string
    {
        $severityColors = [
            'critical' => 'red',
            'error' => 'red',
            'warning' => 'orange',
            'info' => 'blue',
            'debug' => 'gray'
        ];

        $severity = $this->getErrorSeverity();
        return $severityColors[$severity] ?? 'gray';
    }

    /**
     * Get severity icon for UI display.
     *
     * @return string
     */
    private function getSeverityIcon(): string
    {
        $severityIcons = [
            'critical' => 'times-circle',
            'error' => 'exclamation-circle',
            'warning' => 'exclamation-triangle',
            'info' => 'info-circle',
            'debug' => 'bug'
        ];

        $severity = $this->getErrorSeverity();
        return $severityIcons[$severity] ?? 'question-circle';
    }

    /**
     * Get category label for display.
     *
     * @return string
     */
    private function getCategoryLabel(): string
    {
        $category = $this->getErrorCategory();
        
        $categoryLabels = [
            'format' => 'Format Error',
            'validation' => 'Validation Error',
            'business_rule' => 'Business Rule Violation',
            'data_integrity' => 'Data Integrity Error',
            'compliance' => 'Compliance Error',
            'security' => 'Security Error',
            'system' => 'System Error'
        ];

        return $categoryLabels[$category] ?? 'Unknown Error';
    }

    /**
     * Get formatted row and column information.
     *
     * @return string
     */
    private function getFormattedRowColumn(): string
    {
        $row = $this->getRowNumber();
        $column = $this->getColumn();

        if ($column) {
            return "Row {$row}, Column {$column}";
        }

        return "Row {$row}";
    }

    /**
     * Check if this is a blocking error.
     *
     * @return bool
     */
    private function isBlockingError(): bool
    {
        $severity = $this->getErrorSeverity();
        return in_array($severity, ['critical', 'error']);
    }

    /**
     * Check if error can be ignored.
     *
     * @return bool
     */
    private function canBeIgnored(): bool
    {
        $severity = $this->getErrorSeverity();
        return in_array($severity, ['warning', 'info', 'debug']);
    }

    /**
     * Get priority score for error sorting.
     *
     * @return int
     */
    private function getPriorityScore(): int
    {
        $severityScores = [
            'critical' => 100,
            'error' => 80,
            'warning' => 60,
            'info' => 40,
            'debug' => 20
        ];

        $severity = $this->getErrorSeverity();
        $baseScore = $severityScores[$severity] ?? 50;

        // Adjust score based on blocking nature
        if ($this->isBlockingError()) {
            $baseScore += 20;
        }

        // Adjust score based on fix complexity
        $complexity = $this->getFixComplexity();
        if ($complexity === 'simple') {
            $baseScore += 10;
        } elseif ($complexity === 'complex') {
            $baseScore -= 10;
        }

        return $baseScore;
    }

    /**
     * Check if error is fixable.
     *
     * @return bool
     */
    private function isFixable(): bool
    {
        $unfixableErrorCodes = [
            'SYSTEM_ERROR',
            'EXTERNAL_SERVICE_ERROR',
            'NETWORK_ERROR'
        ];

        $errorCode = $this->getErrorCode();
        return !in_array($errorCode, $unfixableErrorCodes);
    }

    /**
     * Get fix complexity level.
     *
     * @return string
     */
    private function getFixComplexity(): string
    {
        $errorCode = $this->getErrorCode();

        $simpleFixCodes = [
            'REQUIRED_FIELD_MISSING',
            'INVALID_FORMAT',
            'INVALID_LENGTH',
            'INVALID_CHARACTER'
        ];

        $complexFixCodes = [
            'BUSINESS_RULE_VIOLATION',
            'COMPLIANCE_ERROR',
            'DATA_INTEGRITY_ERROR'
        ];

        if (in_array($errorCode, $simpleFixCodes)) {
            return 'simple';
        } elseif (in_array($errorCode, $complexFixCodes)) {
            return 'complex';
        }

        return 'medium';
    }

    /**
     * Get estimated fix time.