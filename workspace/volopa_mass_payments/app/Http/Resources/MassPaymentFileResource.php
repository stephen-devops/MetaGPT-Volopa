<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class MassPaymentFileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'currency' => $this->currency,
            'status' => $this->status,
            'formatted_status' => $this->getFormattedStatus(),
            'status_color' => $this->getStatusColor(),
            'total_amount' => $this->total_amount,
            'formatted_amount' => $this->getFormattedAmount(),
            'total_instructions' => $this->total_instructions,
            'valid_instructions' => $this->valid_instructions,
            'invalid_instructions' => $this->invalid_instructions,
            'success_rate' => $this->getSuccessRate(),
            'progress_percentage' => $this->calculateProgress(),
            'validation_errors' => $this->when(
                $this->hasValidationErrors(),
                $this->formatValidationErrors($this->validation_errors ?? [])
            ),
            'validation_error_count' => $this->getValidationErrorCount(),
            'file_size' => $this->getFileSizeAttribute(),
            'tcc_account' => [
                'id' => $this->tcc_account_id,
                'name' => $this->whenLoaded('tccAccount', function () {
                    return $this->tccAccount->account_name ?? 'Unknown Account';
                }),
            ],
            'approval_info' => $this->when(
                $this->isApproved(),
                [
                    'approved_by' => $this->approved_by,
                    'approved_at' => $this->approved_at?->toISOString(),
                    'approved_at_formatted' => $this->approved_at?->format('M j, Y g:i A'),
                ]
            ),
            'timestamps' => [
                'created_at' => $this->created_at->toISOString(),
                'updated_at' => $this->updated_at->toISOString(),
                'created_at_formatted' => $this->created_at->format('M j, Y g:i A'),
                'updated_at_formatted' => $this->updated_at->format('M j, Y g:i A'),
                'time_since_creation' => $this->created_at->diffForHumans(),
                'time_since_update' => $this->updated_at->diffForHumans(),
            ],
            'capabilities' => [
                'can_be_approved' => $this->canBeApproved(),
                'can_be_deleted' => $this->canBeDeleted(),
                'is_processing' => $this->isProcessing(),
                'is_completed' => $this->isCompleted(),
                'has_failed' => $this->hasFailed(),
            ],
            'statistics' => [
                'processing_progress' => $this->calculateProgress(),
                'error_percentage' => $this->calculateErrorPercentage(),
                'average_amount' => $this->calculateAverageAmount(),
                'estimated_completion_time' => $this->when(
                    $this->isProcessing(),
                    $this->estimateCompletionTime()
                ),
            ],
            'links' => [
                'self' => route('api.v1.mass-payment-files.show', ['id' => $this->id]),
                'approve' => $this->when(
                    $this->canBeApproved(),
                    route('api.v1.mass-payment-files.approve', ['id' => $this->id])
                ),
                'payment_instructions' => route('api.v1.payment-instructions.index', [
                    'mass_payment_file_id' => $this->id
                ]),
            ],
        ];
    }

    /**
     * Get formatted amount with currency symbol.
     */
    private function getFormattedAmount(): string
    {
        return number_format($this->total_amount, 2) . ' ' . strtoupper($this->currency);
    }

    /**
     * Format validation errors for API response.
     */
    private function formatValidationErrors(array $errors): array
    {
        if (empty($errors)) {
            return [];
        }

        $formattedErrors = [];

        foreach ($errors as $index => $error) {
            if (is_array($error)) {
                // Structured error format
                $formattedErrors[] = [
                    'index' => $index,
                    'row_number' => $error['row_number'] ?? null,
                    'field' => $error['field'] ?? null,
                    'message' => $error['message'] ?? 'Unknown error',
                    'value' => $error['value'] ?? null,
                    'severity' => $error['severity'] ?? 'error',
                ];
            } else {
                // Simple string error format
                $formattedErrors[] = [
                    'index' => $index,
                    'row_number' => null,
                    'field' => null,
                    'message' => (string) $error,
                    'value' => null,
                    'severity' => 'error',
                ];
            }
        }

        return [
            'count' => count($formattedErrors),
            'errors' => $formattedErrors,
            'summary' => $this->generateErrorSummary($formattedErrors),
        ];
    }

    /**
     * Calculate processing progress as percentage.
     */
    private function calculateProgress(): int
    {
        switch ($this->status) {
            case 'draft':
                return 0;
            case 'validating':
                return 25;
            case 'validation_failed':
                return 25;
            case 'awaiting_approval':
                return 50;
            case 'approved':
                return 75;
            case 'processing':
                // Dynamic progress based on payment instruction statuses
                return $this->calculateDynamicProgress();
            case 'completed':
                return 100;
            case 'failed':
                return 100;
            default:
                return 0;
        }
    }

    /**
     * Calculate dynamic progress for processing status.
     */
    private function calculateDynamicProgress(): int
    {
        if ($this->total_instructions === 0) {
            return 75;
        }

        // Get completed instructions count (you might need to load this relationship)
        $completedCount = $this->whenLoaded('paymentInstructions', function () {
            return $this->paymentInstructions
                ->whereIn('status', ['completed', 'failed', 'cancelled'])
                ->count();
        }, 0);

        if ($completedCount === 0) {
            return 75; // Just started processing
        }

        // Calculate progress between 75% (started) and 100% (completed)
        $processingProgress = ($completedCount / $this->total_instructions) * 25; // 25% range for processing
        return min(75 + $processingProgress, 100);
    }

    /**
     * Calculate error percentage.
     */
    private function calculateErrorPercentage(): float
    {
        if ($this->total_instructions === 0) {
            return 0.0;
        }

        return round(($this->invalid_instructions / $this->total_instructions) * 100, 2);
    }

    /**
     * Calculate average payment amount.
     */
    private function calculateAverageAmount(): float
    {
        if ($this->total_instructions === 0) {
            return 0.0;
        }

        return round($this->total_amount / $this->total_instructions, 2);
    }

    /**
     * Estimate completion time for processing files.
     */
    private function estimateCompletionTime(): ?string
    {
        if (!$this->isProcessing()) {
            return null;
        }

        // Get processing start time
        $processingStarted = $this->updated_at;
        $now = Carbon::now();
        $elapsedMinutes = $processingStarted->diffInMinutes($now);

        if ($elapsedMinutes === 0) {
            // Just started, estimate based on instruction count
            $estimatedMinutes = $this->estimateProcessingTime();
        } else {
            // Calculate based on current progress
            $progressPercent = $this->calculateDynamicProgress();
            if ($progressPercent <= 75) {
                // Still in initial processing phase
                $estimatedMinutes = $this->estimateProcessingTime();
            } else {
                // Calculate remaining time based on current rate
                $actualProgress = $progressPercent - 75; // Processing progress only
                if ($actualProgress > 0) {
                    $remainingProgress = 25 - $actualProgress; // Remaining in processing phase
                    $ratePerMinute = $actualProgress / $elapsedMinutes;
                    $estimatedMinutes = $ratePerMinute > 0 ? ($remainingProgress / $ratePerMinute) : 15;
                } else {
                    $estimatedMinutes = 15; // Default estimate
                }
            }
        }

        $estimatedCompletion = $now->addMinutes($estimatedMinutes);
        return $estimatedCompletion->toISOString();
    }

    /**
     * Estimate processing time based on instruction count.
     */
    private function estimateProcessingTime(): int
    {
        // Rough estimates based on instruction count
        if ($this->total_instructions <= 50) {
            return 2; // 2 minutes
        } elseif ($this->total_instructions <= 200) {
            return 5; // 5 minutes
        } elseif ($this->total_instructions <= 500) {
            return 10; // 10 minutes
        } elseif ($this->total_instructions <= 1000) {
            return 20; // 20 minutes
        } else {
            // For large files, estimate 1 instruction per 1.5 seconds
            return max(30, intval($this->total_instructions * 1.5 / 60));
        }
    }

    /**
     * Generate error summary for validation errors.
     */
    private function generateErrorSummary(array $formattedErrors): array
    {
        $errorsByField = [];
        $errorsBySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
        $mostCommonErrors = [];

        foreach ($formattedErrors as $error) {
            // Group by field
            $field = $error['field'] ?? 'unknown';
            $errorsByField[$field] = ($errorsByField[$field] ?? 0) + 1;

            // Group by severity
            $severity = $error['severity'] ?? 'error';
            $errorsBySeverity[$severity] = ($errorsBySeverity[$severity] ?? 0) + 1;

            // Track common error messages
            $message = $error['message'];
            $mostCommonErrors[$message] = ($mostCommonErrors[$message] ?? 0) + 1;
        }

        // Sort to get most common
        arsort($errorsByField);
        arsort($mostCommonErrors);

        return [
            'total_errors' => count($formattedErrors),
            'by_field' => array_slice($errorsByField, 0, 5, true), // Top 5 fields with errors
            'by_severity' => $errorsBySeverity,
            'most_common_errors' => array_slice(array_keys($mostCommonErrors), 0, 3), // Top 3 error messages
            'fields_with_most_errors' => array_slice(array_keys($errorsByField), 0, 3), // Top 3 problem fields
        ];
    }

    /**
     * Get additional attributes when specific conditions are met.
     */
    public function with(Request $request): array
    {
        $meta = [
            'timestamp' => Carbon::now()->toISOString(),
            'api_version' => 'v1',
        ];

        // Add processing metadata for active files
        if ($this->isProcessing() || $this->isValidating()) {
            $meta['processing'] = [
                'started_at' => $this->updated_at->toISOString(),
                'elapsed_time' => $this->updated_at->diffForHumans(),
                'estimated_remaining' => $this->estimateCompletionTime(),
            ];
        }

        // Add failure information for failed files
        if ($this->hasFailed() || $this->hasValidationFailed()) {
            $meta['failure_info'] = [
                'failed_at' => $this->updated_at->toISOString(),
                'can_retry' => $this->canBeDeleted(), // If can be deleted, can potentially be retried
                'error_count' => $this->getValidationErrorCount(),
            ];
        }

        return [
            'meta' => $meta,
        ];
    }
}