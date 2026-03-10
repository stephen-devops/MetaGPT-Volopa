<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pocket_expense_file_uploads table for tracking CSV file uploads
 * and their processing status in the batch expense upload system.
 * This table manages the upload lifecycle from initial file upload through
 * validation, processing, and completion. Includes soft delete pattern and
 * comprehensive status tracking for batch operations.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('pocket_expense_file_uploads', function (Blueprint $table) {
            // Primary key
            $table->bigIncrements('id')->comment('Primary key for file upload tracking');
            
            // UUID for external references
            $table->string('uuid', 36)->unique()->comment('Unique identifier for external references');
            
            // Foreign key relationships
            $table->unsignedBigInteger('user_id')->comment('The user who initiated the upload');
            $table->unsignedBigInteger('client_id')->comment('The client context for this upload');
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this upload record (same as user_id typically)');
            
            // File information
            $table->string('file_name', 255)->comment('Original filename of the uploaded CSV');
            $table->string('file_path', 500)->comment('Storage path to the uploaded file');
            
            // Processing statistics
            $table->unsignedInteger('total_records')->default(0)->comment('Total number of records found in the CSV file');
            $table->unsignedInteger('valid_records')->default(0)->comment('Number of records that passed validation');
            
            // Validation and error tracking
            $table->json('validation_errors')->nullable()->comment('JSON array of validation errors encountered during processing');
            
            // Processing status
            $table->enum('status', [
                'uploaded',
                'validating', 
                'validation_failed',
                'processing',
                'completed',
                'failed'
            ])->default('uploaded')->comment('Current processing status of the upload');
            
            // Processing timestamps
            $table->timestamp('uploaded_at')->comment('Timestamp when the file was uploaded');
            $table->timestamp('validated_at')->nullable()->comment('Timestamp when validation completed');
            $table->timestamp('processed_at')->nullable()->comment('Timestamp when processing completed');
            
            // Laravel timestamps
            $table->timestamps();
            
            // Soft delete using Laravel's built-in soft delete pattern
            $table->softDeletes();
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id'], 'idx_client_id');
            $table->index(['user_id'], 'idx_user_id');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['status'], 'idx_status');
            $table->index(['uploaded_at'], 'idx_uploaded_at');
            $table->index(['validated_at'], 'idx_validated_at');
            $table->index(['processed_at'], 'idx_processed_at');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['deleted_at'], 'idx_deleted_at');
            
            // Composite indexes for common queries
            $table->index(['client_id', 'user_id', 'status'], 'idx_client_user_status');
            $table->index(['client_id', 'status', 'uploaded_at'], 'idx_client_status_uploaded');
            $table->index(['user_id', 'status', 'uploaded_at'], 'idx_user_status_uploaded');
            
            // Foreign key constraints
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade')
                  ->onUpdate('cascade');
                  
            $table->foreign('created_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict')
                  ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_file_uploads');
    }
};