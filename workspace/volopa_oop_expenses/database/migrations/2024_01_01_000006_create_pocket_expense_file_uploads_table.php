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
            $table->bigIncrements('id');
            $table->string('uuid', 36)->unique()->comment('Unique identifier for the upload');
            $table->unsignedBigInteger('user_id')->comment('Target user for whom expenses are being uploaded');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            $table->unsignedBigInteger('created_by_user_id')->comment('Admin user who performed the upload');
            $table->string('file_name', 255)->comment('Original filename of the uploaded CSV');
            $table->string('file_path', 500)->comment('Storage path of the uploaded file');
            $table->integer('total_records')->default(0)->comment('Total number of data rows in the CSV (excluding header)');
            $table->integer('valid_records')->default(0)->comment('Number of rows that passed validation');
            $table->json('validation_errors')->nullable()->comment('JSON array of validation errors for failed rows');
            $table->enum('status', [
                'uploaded',
                'validation_failed', 
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ])->default('uploaded')->comment('Current processing status of the upload');
            $table->timestamp('uploaded_at')->nullable()->comment('Timestamp when file was uploaded');
            $table->timestamp('validated_at')->nullable()->comment('Timestamp when validation completed');
            $table->timestamp('processed_at')->nullable()->comment('Timestamp when background processing completed');
            $table->timestamps(); // Laravel standard timestamps (created_at, updated_at)
            $table->softDeletes(); // Laravel SoftDeletes (deleted_at)
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['status'], 'idx_status');
            $table->index(['uploaded_at'], 'idx_uploaded_at');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['deleted_at'], 'idx_deleted_at');
            
            // Composite indexes for common queries
            $table->index(['client_id', 'status', 'uploaded_at'], 'idx_client_status_date');
            $table->index(['user_id', 'status', 'uploaded_at'], 'idx_user_status_date');
            $table->index(['created_by_user_id', 'uploaded_at'], 'idx_creator_date');
            
            // Table configuration
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
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