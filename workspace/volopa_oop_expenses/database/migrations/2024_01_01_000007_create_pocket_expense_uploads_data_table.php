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
        Schema::create('pocket_expense_uploads_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('upload_id');
            $table->integer('line_number');
            $table->enum('status', ['pending', 'processing', 'synced', 'failed'])->default('pending');
            $table->json('expense_data');
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            
            // Indexes for performance as per system constraints
            $table->index(['upload_id', 'status'], 'idx_upload_status');
            $table->index(['status'], 'idx_pending_sync');
            $table->index(['line_number']);
            
            // Table settings
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