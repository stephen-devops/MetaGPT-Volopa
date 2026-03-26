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
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->json('expense_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();
            
            // Foreign key constraints
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            
            // Indexes for performance as specified in system constraints
            $table->index(['upload_id', 'status'], 'idx_upload_status');
            $table->index(['status'], 'idx_pending_sync');
            $table->index(['upload_id', 'line_number']);
            $table->index('line_number');
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