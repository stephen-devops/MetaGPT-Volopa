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
            $table->bigIncrements('id');
            $table->unsignedBigInteger('upload_id')->comment('Foreign key to pocket_expense_file_uploads table');
            $table->integer('line_number')->comment('Line number in the original CSV file (including header row)');
            $table->enum('status', [
                'pending',
                'processing', 
                'synced',
                'failed'
            ])->default('pending')->comment('Processing status of this individual CSV row');
            $table->json('expense_data')->comment('JSON representation of the parsed CSV row data');
            $table->text('error_message')->nullable()->comment('Error message if processing failed');
            $table->timestamps(); // Laravel standard timestamps (created_at, updated_at)
            
            // Foreign key constraints
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['upload_id'], 'idx_upload');
            $table->index(['upload_id', 'status'], 'idx_upload_status');
            $table->index(['upload_id', 'line_number'], 'idx_upload_line');
            $table->index(['status'], 'idx_status');
            $table->index(['line_number'], 'idx_line_number');
            
            // Composite indexes for common queries
            $table->index(['upload_id', 'status', 'line_number'], 'idx_upload_status_line');
            $table->index(['upload_id', 'created_at'], 'idx_upload_created');
            
            // Unique constraint to prevent duplicate line entries per upload
            $table->unique(['upload_id', 'line_number'], 'unique_upload_line');
            
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
        Schema::dropIfExists('pocket_expense_uploads_data');
    }
};