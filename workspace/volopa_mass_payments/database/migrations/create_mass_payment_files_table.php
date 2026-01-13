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
        Schema::create('mass_payment_files', function (Blueprint $table) {
            // Primary key - UUID as per Volopa standards
            $table->uuid('id')->primary();
            
            // Multi-tenant architecture - client scoping
            $table->unsignedBigInteger('client_id')->index();
            
            // TCC account relationship
            $table->unsignedBigInteger('tcc_account_id')->index();
            
            // File metadata
            $table->string('filename', 255); // Stored filename
            $table->string('original_filename', 500); // Original upload filename
            $table->unsignedBigInteger('file_size'); // File size in bytes
            
            // Payment summary data
            $table->decimal('total_amount', 15, 2)->default(0.00); // Total payment amount
            $table->char('currency', 3); // ISO currency code
            
            // Status tracking with enum values
            $table->enum('status', [
                'draft',
                'validating', 
                'validation_failed',
                'awaiting_approval',
                'approved',
                'processing',
                'completed',
                'failed'
            ])->default('draft')->index();
            
            // Validation results
            $table->json('validation_errors')->nullable(); // Store validation error details
            
            // Audit trail - who uploaded
            $table->unsignedBigInteger('uploaded_by')->index();
            
            // Approval tracking
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            
            // Standard timestamps
            $table->timestamps();
            
            // Soft deletes for audit purposes
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('tcc_account_id')->references('id')->on('tcc_accounts')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('restrict');
            
            // Composite indexes for performance
            $table->index(['client_id', 'status'], 'mass_payment_files_client_status_idx');
            $table->index(['client_id', 'currency'], 'mass_payment_files_client_currency_idx');
            $table->index(['status', 'created_at'], 'mass_payment_files_status_created_idx');
            
            // Unique constraint to prevent duplicate file processing
            $table->unique(['client_id', 'original_filename', 'file_size', 'created_at'], 'mass_payment_files_duplicate_prevention');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mass_payment_files');
    }
};