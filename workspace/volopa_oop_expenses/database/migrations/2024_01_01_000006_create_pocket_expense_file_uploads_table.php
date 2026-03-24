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
        Schema::create('pocket_expense_file_uploads', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // UUID for external references
            $table->string('uuid', 36)->nullable()->comment('External UUID reference for tracking');
            
            // User and client context - target user vs creating admin
            $table->unsignedBigInteger('user_id')->comment('Target user for whom expenses will be created (expense_user_id in API)');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            $table->unsignedBigInteger('created_by_user_id')->comment('Admin user who performed the upload (user_id in API)');
            
            // File information
            $table->string('file_name', 255)->comment('Original uploaded file name');
            $table->string('file_path', 500)->comment('Storage path to the uploaded CSV file');
            
            // Processing statistics
            $table->integer('total_records')->unsigned()->default(0)->comment('Total number of data rows in the CSV file');
            $table->integer('valid_records')->unsigned()->default(0)->comment('Number of records that passed validation');
            
            // Validation and error tracking
            $table->json('validation_errors')->nullable()->comment('JSON array of validation errors with line numbers and details');
            
            // Processing status workflow
            $table->enum('status', ['uploaded', 'validating', 'validation_failed', 'processing', 'completed', 'failed'])->default('uploaded')->comment('Upload processing status');
            
            // Processing timestamps
            $table->dateTime('uploaded_at')->nullable()->comment('Timestamp when file was initially uploaded');
            $table->dateTime('validated_at')->nullable()->comment('Timestamp when validation completed');
            $table->dateTime('processed_at')->nullable()->comment('Timestamp when batch processing completed');
            
            // Laravel standard timestamps (used alongside Volopa legacy pattern for compatibility)
            $table->timestamps();
            
            // Soft delete using Laravel pattern for this upload tracking table
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes for common queries and performance
            $table->index(['user_id'], 'idx_user');
            $table->index(['client_id'], 'idx_client');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['status'], 'idx_status');
            $table->index(['uploaded_at'], 'idx_uploaded_at');
            $table->index(['validated_at'], 'idx_validated_at');
            $table->index(['processed_at'], 'idx_processed_at');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['deleted_at'], 'idx_deleted_at');
            
            // Composite indexes for common filtering scenarios
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index(['user_id', 'status'], 'idx_user_status');
            $table->index(['created_by_user_id', 'status'], 'idx_creator_status');
            $table->index(['client_id', 'status', 'deleted_at'], 'idx_client_status_active');
            
            // Index for processing queue queries
            $table->index(['status', 'uploaded_at'], 'idx_status_upload_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_file_uploads');
    }
};