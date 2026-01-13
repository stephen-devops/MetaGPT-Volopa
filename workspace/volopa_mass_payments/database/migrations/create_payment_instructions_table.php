<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_instructions', function (Blueprint $table) {
            // Primary key - UUID as per Volopa standards
            $table->uuid('id')->primary();
            
            // Foreign key to mass payment file
            $table->uuid('mass_payment_file_id')->index();
            
            // Beneficiary relationship
            $table->unsignedBigInteger('beneficiary_id')->nullable()->index();
            
            // Payment details
            $table->decimal('amount', 15, 2); // Payment amount with 2 decimal precision
            $table->char('currency', 3); // ISO currency code
            $table->string('purpose_code', 20)->nullable(); // Purpose of payment code
            $table->string('reference', 255)->nullable(); // Payment reference
            
            // Status tracking with enum values
            $table->enum('status', [
                'pending',
                'validated',
                'validation_failed',
                'processing',
                'completed',
                'failed',
                'cancelled'
            ])->default('pending')->index();
            
            // Validation results
            $table->json('validation_errors')->nullable(); // Store row-specific validation errors
            
            // CSV row tracking
            $table->unsignedInteger('row_number'); // Original row number in CSV file
            
            // Standard timestamps
            $table->timestamps();
            
            // Soft deletes for audit purposes
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('mass_payment_file_id')
                  ->references('id')
                  ->on('mass_payment_files')
                  ->onDelete('cascade');
            
            $table->foreign('beneficiary_id')
                  ->references('id')
                  ->on('beneficiaries')
                  ->onDelete('set null');
            
            // Composite indexes for performance
            $table->index(['mass_payment_file_id', 'status'], 'payment_instructions_file_status_idx');
            $table->index(['mass_payment_file_id', 'row_number'], 'payment_instructions_file_row_idx');
            $table->index(['beneficiary_id', 'currency'], 'payment_instructions_beneficiary_currency_idx');
            $table->index(['status', 'created_at'], 'payment_instructions_status_created_idx');
            $table->index(['currency', 'purpose_code'], 'payment_instructions_currency_purpose_idx');
            
            // Unique constraint to prevent duplicate instructions within same file
            $table->unique(['mass_payment_file_id', 'row_number'], 'payment_instructions_file_row_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_instructions');
    }
};