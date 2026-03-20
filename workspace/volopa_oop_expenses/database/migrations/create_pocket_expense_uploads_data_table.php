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
        Schema::create('pocket_expense_uploads_data', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Primary key
            $table->bigIncrements('id');
            
            // Foreign key to the upload batch record
            $table->unsignedBigInteger('upload_id')->comment('Reference to pocket_expense_file_uploads record');
            
            // Line tracking
            $table->unsignedInteger('line_number')->comment('Line number in the CSV file (excluding header)');
            
            // Processing status for individual record
            $table->enum('status', [
                'pending',
                'validated', 
                'validation_failed',
                'processed',
                'failed'
            ])->default('pending')->comment('Processing status of this individual record');
            
            // JSON storage for the expense data from CSV row
            $table->json('expense_data')->comment('JSON representation of the expense data from CSV row');
            
            // Laravel timestamps for created_at and updated_at
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('upload_id')
                  ->references('id')
                  ->on('pocket_expense_file_uploads')
                  ->onDelete('cascade');
            
            // Indexes for common queries
            $table->index(['upload_id', 'status'], 'upload_status_index');
            $table->index(['upload_id', 'line_number'], 'upload_line_index');
            $table->index(['status', 'created_at'], 'status_created_index');
            $table->index(['line_number'], 'line_number_index');
            
            // Unique constraint to prevent duplicate line numbers per upload
            $table->unique(['upload_id', 'line_number'], 'upload_line_unique');
            
            // Composite index for batch processing queries
            $table->index(['upload_id', 'status', 'id'], 'batch_processing_index');
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