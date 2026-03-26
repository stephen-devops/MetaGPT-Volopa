<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pocket Expense Resource
 * 
 * API Resource for PocketExpense model to shape responses and hide internal fields.
 * Includes relationships to metadata, expense type, users, and client information.
 * Formats amounts, dates, and status for consistent API responses.
 */
class PocketExpenseResource extends JsonResource
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
            'id' => $this->id,
            'uuid' => $this->uuid,
            'user_id' => $this->user_id,
            'client_id' => $this->client_id,
            'date' => $this->date ? $this->date->format('Y-m-d') : null,
            'merchant_name' => $this->merchant_name,
            'merchant_description' => $this->merchant_description,
            'merchant_address' => $this->merchant_address,
            'expense_type_id' => $this->expense_type,
            'expense_type' => $this->whenLoaded('expenseType', function () {
                return [
                    'id' => $this->expenseType->id,
                    'option' => $this->expenseType->option,
                    'amount_sign' => $this->expenseType->amount_sign,
                ];
            }),
            'currency' => $this->currency,
            'amount' => $this->amount ? (float) $this->amount : null,
            'formatted_amount' => $this->when(
                $this->amount && $this->currency,
                function () {
                    return $this->getFormattedAmount();
                }
            ),
            'vat_amount' => $this->vat_amount ? (float) $this->vat_amount : null,
            'notes' => $this->notes,
            'status' => $this->status,
            'display_status' => $this->when(
                $this->status,
                function () {
                    return $this->getDisplayStatus();
                }
            ),
            
            // User relationships
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name ?? null,
                    // TODO: Add other user fields as needed based on User model structure
                ];
            }),
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name ?? null,
                    // TODO: Add other user fields as needed based on User model structure
                ];
            }),
            'updated_by' => $this->whenLoaded('updatedBy', function () {
                return $this->updatedBy ? [
                    'id' => $this->updatedBy->id,
                    'name' => $this->updatedBy->name ?? null,
                    // TODO: Add other user fields as needed based on User model structure
                ] : null;
            }),
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return $this->approvedBy ? [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name ?? null,
                    // TODO: Add other user fields as needed based on User model structure
                ] : null;
            }),
            
            // Client relationship
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name ?? null,
                    // TODO: Add other client fields as needed based on Client model structure
                ];
            }),
            
            // Metadata relationships
            'metadata' => PocketExpenseMetadataResource::collection($this->whenLoaded('metadata')),
            
            // Permissions and capabilities
            'permissions' => [
                'can_edit' => $this->when(
                    method_exists($this->resource, 'canEdit'),
                    function () {
                        return $this->canEdit();
                    },
                    false
                ),
                'can_delete' => $this->when(
                    method_exists($this->resource, 'canDelete'),
                    function () {
                        return $this->canDelete();
                    },
                    false
                ),
                'can_submit' => $this->when(
                    method_exists($this->resource, 'canSubmit'),
                    function () {
                        return $this->canSubmit();
                    },
                    false
                ),
                'can_approve' => $this->when(
                    method_exists($this->resource, 'canApprove'),
                    function () {
                        return $this->canApprove();
                    },
                    false
                ),
                'can_reject' => $this->when(
                    method_exists($this->resource, 'canReject'),
                    function () {
                        return $this->canReject();
                    },
                    false
                ),
            ],
            
            // Timestamps using Volopa legacy convention
            'created_at' => $this->create_time ? $this->create_time->toISOString() : null,
            'updated_at' => $this->update_time ? $this->update_time->toISOString() : null,
            'deleted_at' => $this->when(
                $this->deleted && $this->delete_time,
                function () {
                    return $this->delete_time->toISOString();
                }
            ),
            
            // Additional computed fields
            'is_active' => $this->when(
                method_exists($this->resource, 'isActive'),
                function () {
                    return $this->isActive();
                },
                true
            ),
            'is_draft' => $this->when(
                method_exists($this->resource, 'isDraft'),
                function () {
                    return $this->isDraft();
                },
                false
            ),
            'is_submitted' => $this->when(
                method_exists($this->resource, 'isSubmitted'),
                function () {
                    return $this->isSubmitted();
                },
                false
            ),
            'is_approved' => $this->when(
                method_exists($this->resource, 'isApproved'),
                function () {
                    return $this->isApproved();
                },
                false
            ),
            'is_rejected' => $this->when(
                method_exists($this->resource, 'isRejected'),
                function () {
                    return $this->isRejected();
                },
                false
            ),
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
                'currency_formats' => [
                    // TODO: Add currency formatting information from platform configuration
                ],
                'status_options' => [
                    'draft' => 'Draft',
                    'submitted' => 'Submitted',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ],
            ],
        ];
    }

    /**
     * Customize the outgoing response for the resource.
     *
     * @param Request $request
     * @param \Illuminate\Http\JsonResponse $response
     * @return void
     */
    public function withResponse(Request $request, $response): void
    {
        // TODO: Add any custom response headers or modifications as needed
    }
}