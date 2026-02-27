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
            $table->id();
            $table->unsignedBigInteger('upload_id');
            $table->integer('line_number');
            $table->enum('status', [
                'pending',
                'synced',
                'failed',
                'skipped'
            ])->default('pending');
            $table->json('expense_data');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('upload_id')
                  ->references('id')
                  ->on('pocket_expense_file_uploads')
                  ->onDelete('cascade');

            // Indexes for performance
            $table->index(['upload_id', 'status']);
            $table->index(['upload_id', 'line_number']);
            $table->index(['status']);
            $table->index(['upload_id', 'status', 'line_number'], 'idx_upload_status_line');
            $table->index(['upload_id', 'created_at']);

            // Unique constraint to prevent duplicate line numbers per upload
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