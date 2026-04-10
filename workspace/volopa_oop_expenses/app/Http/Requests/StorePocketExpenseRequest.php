<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * StorePopocketExpenseRequest
 * 
 * Form request for validating and authorizing creation of pocket expenses.
 * Handles validation of expense data including metadata and FX conversion requirements.
 */
class StorePocketExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled by PocketExpensePolicy, so we return true here
        // The actual authorization logic is in PocketExpenseController using policies
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Core expense fields
            'date' => [
                'required',
                'date',
                'date_format:Y-m-d',
                function ($attribute, $value, $fail) {
                    // Date must not be older than 3 years
                    $threeYearsAgo = Carbon::now()->subYears(3);
                    if (Carbon::parse($value)->lt($threeYearsAgo)) {
                        $fail('The date must not be older than 3 years.');
                    }
                },
            ],
            'merchant_name' => [
                'required',
                'string',
                'max:180', // VARCHAR(180) as per database constraint
                'min:1',
            ],
            'merchant_description' => [
                'nullable',
                'string',
                'max:255',
            ],
            'expense_type' => [
                'required',
                'integer',
                'exists:opt_pocket_expense_type,id',
            ],
            'currency' => [
                'required',
                'string',
                'size:3', // 3-letter ISO currency code
                'regex:/^[A-Z]{3}$/',
                // TODO: Add validation against platform currency list
            ],
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d+(\.\d{1,2})?$/', // Decimal(14,2) format
                'min:0.01',
                'max:999999999999.99', // Max for DECIMAL(14,2)
            ],
            'merchant_address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'vat_amount' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
                'regex:/^\d+(\.\d{1,2})?$/', // Decimal(12,2) format
            ],
            'notes' => [
                'nullable',
                'string',
                'max:65535', // TEXT field limit
            ],
            
            // System fields
            'client_id' => [
                'required',
                'integer',
                'exists:clients,id',
            ],
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            
            // Optional status field (defaults to 'draft' if not provided)
            'status' => [
                'nullable',
                'string',
                Rule::in(['draft', 'submitted', 'approved', 'rejected']),
            ],
            
            // Metadata fields (all optional)
            'metadata' => [
                'nullable',
                'array',
            ],
            'metadata.*.metadata_type' => [
                'required_with:metadata',
                'string',
                Rule::in([
                    'category',
                    'tracking_code_type_1',
                    'tracking_code_type_2',
                    'project',
                    'additional_field',
                    'file',
                    'expense_source'
                ]),
            ],
            'metadata.*.transaction_category_id' => [
                'nullable',
                'integer',
                'exists:transaction_category,id',
            ],
            'metadata.*.tracking_code_id' => [
                'nullable',
                'integer',
                'exists:tracking_codes,id',
            ],
            'metadata.*.project_id' => [
                'nullable',
                'integer',
                'exists:configurable_projects,id',
            ],
            'metadata.*.file_store_id' => [
                'nullable',
                'integer',
                'exists:file_store,id',
            ],
            'metadata.*.expense_source_id' => [
                'nullable',
                'integer',
                'exists:pocket_expense_source_client_config,id',
            ],
            'metadata.*.additional_field_id' => [
                'nullable',
                'integer',
                'exists:expense_additional_field,id',
            ],
            'metadata.*.details_json' => [
                'nullable',
                'json',
            ],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'The expense date is required.',
            'date.date_format' => 'The date must be in YYYY-MM-DD format.',
            'merchant_name.required' => 'The merchant name is required.',
            'merchant_name.max' => 'The merchant name must not exceed 180 characters.',
            'expense_type.required' => 'The expense type is required.',
            'expense_type.exists' => 'The selected expense type is invalid.',
            'currency.required' => 'The currency code is required.',
            'currency.size' => 'The currency code must be exactly 3 characters.',
            'currency.regex' => 'The currency code must be 3 uppercase letters.',
            'amount.required' => 'The expense amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'amount.max' => 'The amount exceeds the maximum allowed value.',
            'vat_amount.min' => 'The VAT amount cannot be negative.',
            'vat_amount.max' => 'The VAT amount cannot exceed 100.',
            'client_id.required' => 'The client ID is required.',
            'client_id.exists' => 'The selected client is invalid.',
            'user_id.required' => 'The user ID is required.',
            'user_id.exists' => 'The selected user is invalid.',
            'status.in' => 'The status must be one of: draft, submitted, approved, rejected.',
            'metadata.*.metadata_type.required_with' => 'The metadata type is required when metadata is provided.',
            'metadata.*.metadata_type.in' => 'The metadata type is invalid.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'merchant_name' => 'merchant name',
            'merchant_description' => 'merchant description',
            'expense_type' => 'expense type',
            'vat_amount' => 'VAT amount',
            'client_id' => 'client',
            'user_id' => 'user',
        ];
    }

    /**
     * Prepare the data for validation.
     * Clean and format input data before validation.
     */
    protected function prepareForValidation(): void
    {
        $data = [];

        // Clean and trim text fields to prevent SQL injection and excess whitespace
        if ($this->has('merchant_name')) {
            $data['merchant_name'] = trim(strip_tags($this->merchant_name));
        }

        if ($this->has('merchant_description')) {
            $data['merchant_description'] = trim(strip_tags($this->merchant_description));
        }

        if ($this->has('merchant_address')) {
            $data['merchant_address'] = trim(strip_tags($this->merchant_address));
        }

        if ($this->has('notes')) {
            $data['notes'] = trim(strip_tags($this->notes));
        }

        // Normalize currency to uppercase
        if ($this->has('currency')) {
            $data['currency'] = strtoupper(trim($this->currency));
        }

        // Strip % sign from VAT amount if present
        if ($this->has('vat_amount') && is_string($this->vat_amount)) {
            $vatAmount = str_replace('%', '', trim($this->vat_amount));
            if (is_numeric($vatAmount)) {
                $data['vat_amount'] = (float) $vatAmount;
            }
        }

        // Set default status if not provided
        if (!$this->has('status')) {
            $data['status'] = 'draft';
        }

        // Ensure amount is properly formatted
        if ($this->has('amount') && is_numeric($this->amount)) {
            $data['amount'] = (float) $this->amount;
        }

        $this->merge($data);
    }

    /**
     * Get the validated data with additional processing.
     * This method can be used by the controller to get clean, validated data.
     *
     * @return array<string, mixed>
     */
    public function getValidatedData(): array
    {
        $validated = $this->validated();

        // Ensure required system fields are set
        $validated['created_by_user_id'] = auth()->id();
        $validated['create_time'] = now();

        // Generate UUID for the expense
        $validated['uuid'] = (string) \Illuminate\Support\Str::uuid();

        // Set default status if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = 'draft';
        }

        return $validated;
    }

    /**
     * Determine if the user belongs to the specified client.
     * Used for additional authorization checks.
     */
    public function userBelongsToClient(): bool
    {
        // TODO: Implement logic to verify user belongs to client
        // This should check the user's client association
        return true;
    }
}