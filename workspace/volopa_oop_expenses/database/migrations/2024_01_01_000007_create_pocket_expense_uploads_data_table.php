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
            $table->id()->comment('Primary key');
            $table->unsignedBigInteger('upload_id')->comment('Reference to pocket_expense_file_uploads table');
            $table->integer('line_number')->comment('Line number in the CSV file (including header)');
            $table->enum('status', [
                'pending',
                'processing', 
                'synced',
                'failed'
            ])->default('pending')->comment('Processing status for individual row');
            $table->json('expense_data')->comment('Parsed expense data from CSV row in JSON format');
            $table->timestamps();
            
            // Set table engine and charset according to constraints
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            
            // Foreign key constraint
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['upload_id', 'status'], 'idx_upload_status');
            $table->index(['upload_id', 'line_number'], 'idx_upload_line');
            $table->index(['status'], 'idx_status');
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