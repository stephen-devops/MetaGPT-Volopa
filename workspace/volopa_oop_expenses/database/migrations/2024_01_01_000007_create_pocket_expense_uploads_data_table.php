<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pocket_expense_uploads_data table for storing individual CSV row data
 * during the batch upload process. This table contains the raw expense data from each
 * CSV row along with its validation status and line number tracking.
 * Each record represents one row from an uploaded CSV file and its processing status.
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
        Schema::create('pocket_expense_uploads_data', function (Blueprint $table) {
            // Primary key
            $table->bigIncrements('id')->comment('Primary key for upload data record');
            
            // Foreign key to parent upload record
            $table->unsignedBigInteger('upload_id')->comment('Foreign key to pocket_expense_file_uploads table');
            
            // CSV row tracking
            $table->unsignedInteger('line_number')->comment('Line number in the CSV file (excluding header row)');
            
            // Processing status for this specific row
            $table->enum('status', [
                'pending',
                'valid',
                'invalid',
                'processed',
                'failed'
            ])->default('pending')->comment('Processing status of this individual CSV row');
            
            // Raw expense data from CSV row stored as JSON
            $table->json('expense_data')->comment('JSON object containing the parsed expense data from the CSV row');
            
            // Laravel timestamps
            $table->timestamps();
            
            // Database engine and charset
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Indexes for performance
            $table->index(['upload_id'], 'idx_upload_id');
            $table->index(['line_number'], 'idx_line_number');
            $table->index(['status'], 'idx_status');
            $table->index(['created_at'], 'idx_created_at');
            $table->index(['updated_at'], 'idx_updated_at');
            
            // Composite indexes for common queries
            $table->index(['upload_id', 'line_number'], 'idx_upload_line');
            $table->index(['upload_id', 'status'], 'idx_upload_status');
            $table->index(['upload_id', 'status', 'line_number'], 'idx_upload_status_line');
            
            // Unique constraint to prevent duplicate line numbers per upload
            $table->unique(['upload_id', 'line_number'], 'uk_upload_line_number');
            
            // Foreign key constraint
            $table->foreign('upload_id')
                  ->references('id')
                  ->on('pocket_expense_file_uploads')
                  ->onDelete('cascade')
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
        Schema::dropIfExists('pocket_expense_uploads_data');
    }
};