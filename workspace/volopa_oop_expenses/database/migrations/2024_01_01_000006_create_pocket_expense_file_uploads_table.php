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
            $table->id();
            $table->string('uuid', 36)->nullable()->comment('External reference UUID');
            $table->unsignedBigInteger('user_id')->comment('User who uploaded the file');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this upload record');
            $table->string('file_name', 255)->comment('Original name of uploaded file');
            $table->string('file_path', 500)->comment('Storage path of uploaded file');
            $table->integer('total_records')->default(0)->comment('Total number of records in uploaded file');
            $table->integer('valid_records')->default(0)->comment('Number of valid records after validation');
            $table->json('validation_errors')->nullable()->comment('JSON array of validation errors');
            $table->enum('status', [
                'uploaded',
                'validation_failed',
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ])->default('uploaded')->comment('Current processing status of upload');
            $table->timestamp('uploaded_at')->useCurrent()->comment('When file was uploaded');
            $table->timestamp('validated_at')->nullable()->comment('When validation was completed');
            $table->timestamp('processed_at')->nullable()->comment('When processing was completed');
            $table->timestamps(); // Laravel standard created_at and updated_at
            $table->softDeletes(); // Laravel soft delete with deleted_at
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index(['status'], 'idx_status');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['uploaded_at'], 'idx_uploaded_at');
            $table->index(['validated_at'], 'idx_validated_at');
            $table->index(['processed_at'], 'idx_processed_at');
            $table->index(['created_at'], 'idx_created_at');
            $table->index(['updated_at'], 'idx_updated_at');
            $table->index(['deleted_at'], 'idx_deleted_at');
            $table->index(['file_name'], 'idx_file_name');
            
            // Composite indexes for common queries
            $table->index(['user_id', 'client_id', 'status', 'deleted_at'], 'idx_user_client_status_deleted');
            $table->index(['client_id', 'uploaded_at', 'deleted_at'], 'idx_client_uploaded_deleted');
            $table->index(['status', 'uploaded_at'], 'idx_status_uploaded');
            $table->index(['status', 'validated_at'], 'idx_status_validated');
            $table->index(['status', 'processed_at'], 'idx_status_processed');
            
            // Table configuration
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
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