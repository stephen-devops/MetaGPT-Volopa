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
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('file_upload_id');
            $table->unsignedInteger('row_number');
            $table->json('original_data');
            $table->json('processed_data')->nullable();
            $table->json('validation_errors')->nullable();
            $table->enum('row_status', ['pending', 'valid', 'invalid', 'processed', 'failed'])->default('pending');
            $table->unsignedBigInteger('created_expense_id')->nullable();
            $table->text('processing_notes')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('file_upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            $table->foreign('created_expense_id')->references('id')->on('oop_expenses')->onDelete('set null');

            // Indexes for performance
            $table->index(['file_upload_id', 'row_number']);
            $table->index(['file_upload_id', 'row_status']);
            $table->index(['row_status', 'created_at']);
            $table->index('uuid');
            $table->index('deleted');
            $table->index(['deleted', 'row_status']);
            $table->index(['file_upload_id', 'deleted']);

            // Unique constraint to prevent duplicate rows per upload
            $table->unique(['file_upload_id', 'row_number'], 'upload_row_unique');
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