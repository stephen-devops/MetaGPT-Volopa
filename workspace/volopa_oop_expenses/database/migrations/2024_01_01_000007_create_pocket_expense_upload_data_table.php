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
        Schema::create('pocket_expense_upload_data', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('upload_id');
            $table->unsignedInteger('line_number');
            $table->enum('status', [
                'pending',
                'synced',
                'failed',
                'skipped'
            ])->default('pending');
            $table->json('expense_data');
            $table->json('validation_errors')->nullable();
            $table->json('processing_errors')->nullable();
            $table->unsignedBigInteger('created_expense_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();

            // Indexes for performance
            $table->index(['upload_id', 'deleted']);
            $table->index(['upload_id', 'status', 'deleted'], 'upload_status_deleted_idx');
            $table->index(['upload_id', 'line_number'], 'upload_line_idx');
            $table->index(['status', 'deleted']);
            $table->index(['created_expense_id']);
            $table->index(['synced_at']);
            $table->index(['failed_at']);
            $table->index('uuid');
            $table->index('deleted');
            $table->index('line_number');

            // Composite indexes for common query patterns
            $table->index(['upload_id', 'status', 'line_number'], 'upload_status_line_idx');
            $table->index(['status', 'create_time', 'deleted'], 'status_created_deleted_idx');

            // Unique constraint to prevent duplicate line numbers per upload
            $table->unique(['upload_id', 'line_number', 'deleted'], 'upload_line_deleted_unique');

            // Foreign key constraints
            $table->foreign('upload_id')->references('id')->on('pocket_expense_file_uploads')->onDelete('cascade');
            $table->foreign('created_expense_id')->references('id')->on('pocket_expense')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_upload_data');
    }
};