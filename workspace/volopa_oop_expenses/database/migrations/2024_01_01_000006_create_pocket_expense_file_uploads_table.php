<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pocket_expense_file_uploads', function (Blueprint $table) {
            $table->id()->comment('Primary key');
            $table->string('uuid', 36)->unique()->comment('UUID for external references');
            $table->unsignedBigInteger('user_id')->comment('Reference to users table - target user for expenses');
            $table->unsignedBigInteger('client_id')->comment('Reference to clients table - multi-tenancy');
            $table->unsignedBigInteger('created_by_user_id')->comment('Reference to users table - admin who uploaded');
            $table->string('file_name', 255)->comment('Original uploaded file name');
            $table->string('file_path', 255)->comment('Stored file path');
            $table->unsignedInteger('total_records')->default(0)->comment('Total number of records in CSV');
            $table->unsignedInteger('valid_records')->default(0)->comment('Number of valid records after validation');
            $table->json('validation_errors')->nullable()->comment('Validation errors in JSON format');
            $table->enum('status', [
                'uploaded',
                'validation_failed', 
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ])->default('uploaded')->comment('Upload processing status');
            $table->timestamp('uploaded_at')->useCurrent()->comment('File upload timestamp');
            $table->timestamp('validated_at')->nullable()->comment('Validation completion timestamp');
            $table->timestamp('processed_at')->nullable()->comment('Processing completion timestamp');
            $table->timestamps();
            $table->softDeletes();
            
            // Set table engine and charset according to constraints
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
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