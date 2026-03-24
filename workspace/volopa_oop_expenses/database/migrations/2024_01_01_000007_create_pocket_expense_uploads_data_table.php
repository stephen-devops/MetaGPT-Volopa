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
        Schema::create('pocket_expense_uploads_data', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign key to parent upload batch
            $table->unsignedBigInteger('upload_id')->comment('Reference to pocket_expense_file_uploads record');
            
            // CSV row tracking
            $table->integer('line_number')->unsigned()->comment('Line number in the original CSV file (starting from 2, after header)');
            
            // Processing status for individual row
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->comment('Processing status of this individual CSV row');
            
            // Raw expense data from CSV row
            $table->json('expense_data')->comment('JSON object containing parsed CSV row data with field mappings');
            
            // Processing error details for failed rows
            $table->json('processing_errors')->nullable()->comment('JSON array of processing errors if status=failed');
            
            // Reference to created expense record (if successfully processed)
            $table->unsignedBigInteger('created_expense_id')->nullable()->comment('Reference to pocket_expense.id if row was successfully processed');
            
            // Laravel standard timestamps
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            $table->foreign('created_expense_id')->references('id')->on('pocket_expense')->onDelete('set null');
            
            // Indexes for common queries and performance
            $table->index(['upload_id'], 'idx_upload');
            $table->index(['upload_id', 'line_number'], 'idx_upload_line');
            $table->index(['upload_id', 'status'], 'idx_upload_status');
            $table->index(['status'], 'idx_status');
            $table->index(['created_expense_id'], 'idx_created_expense');
            $table->index(['line_number'], 'idx_line_number');
            
            // Composite indexes for batch processing queries
            $table->index(['upload_id', 'status', 'line_number'], 'idx_upload_status_line');
            $table->index(['status', 'created_at'], 'idx_status_created');
            
            // Unique constraint to prevent duplicate processing of same CSV row
            $table->unique(['upload_id', 'line_number'], 'unique_upload_line');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_uploads_data');
    }
};