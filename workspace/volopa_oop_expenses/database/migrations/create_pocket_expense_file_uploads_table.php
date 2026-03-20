<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // UUID for external reference
            $table->uuid('uuid')->unique();
            
            // Foreign key references - required fields
            $table->unsignedBigInteger('user_id')->comment('User who initiated the upload');
            $table->unsignedBigInteger('client_id')->comment('Client scope for multi-tenancy');
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this upload record');
            
            // File information
            $table->string('file_name', 255)->comment('Original filename of uploaded CSV');
            $table->string('file_path', 500)->comment('Storage path to the uploaded file');
            
            // Processing statistics
            $table->unsignedInteger('total_records')->default(0)->comment('Total number of records in CSV file');
            $table->unsignedInteger('valid_records')->default(0)->comment('Number of records that passed validation');
            
            // Validation results
            $table->json('validation_errors')->nullable()->comment('JSON array of validation errors if any');
            
            // Status tracking with comprehensive enum values
            $table->enum('status', [
                'uploaded',
                'validation_failed', 
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ])->default('uploaded')->comment('Current processing status of the upload');
            
            // Processing timestamps
            $table->datetime('uploaded_at')->nullable()->comment('When file was uploaded');
            $table->datetime('validated_at')->nullable()->comment('When validation was completed');
            $table->datetime('processed_at')->nullable()->comment('When processing was completed');
            
            // Laravel timestamps for created_at and updated_at
            $table->timestamps();
            
            // Laravel SoftDeletes support
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
                  
            $table->foreign('client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('cascade');
                  
            $table->foreign('created_by_user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
            
            // Indexes for common queries
            $table->index(['user_id', 'client_id'], 'user_client_uploads_index');
            $table->index(['client_id', 'status'], 'client_status_uploads_index');
            $table->index(['status', 'created_at'], 'status_created_uploads_index');
            $table->index(['uuid'], 'uuid_index');
            $table->index(['created_by_user_id'], 'created_by_index');
            $table->index(['uploaded_at'], 'uploaded_at_index');
            $table->index(['validated_at'], 'validated_at_index');
            $table->index(['processed_at'], 'processed_at_index');
            $table->index(['file_path'], 'file_path_index');
            
            // Composite index for file cleanup queries
            $table->index(['status', 'processed_at', 'deleted_at'], 'cleanup_query_index');
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