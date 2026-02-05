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
        Schema::create('pocket_expense_file_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('target_user_id');
            $table->string('original_filename', 255);
            $table->string('stored_filename', 255);
            $table->string('file_path', 500);
            $table->string('mime_type', 100)->default('text/csv');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->enum('status', [
                'uploaded',
                'validation_failed',
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ])->default('uploaded');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('valid_records')->default(0);
            $table->unsignedInteger('processed_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->json('validation_errors')->nullable();
            $table->json('processing_errors')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();

            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['target_user_id', 'client_id']);
            $table->index(['status', 'deleted']);
            $table->index(['user_id', 'status', 'deleted'], 'user_status_deleted_idx');
            $table->index(['client_id', 'create_time', 'deleted'], 'client_created_deleted_idx');
            $table->index(['target_user_id', 'status'], 'target_user_status_idx');
            $table->index('uuid');
            $table->index('deleted');
            $table->index('started_at');
            $table->index('completed_at');
            $table->index('failed_at');

            // Composite indexes for common query patterns
            $table->index(['user_id', 'client_id', 'status', 'deleted'], 'user_client_status_deleted_idx');
            $table->index(['target_user_id', 'client_id', 'status'], 'target_client_status_idx');

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_file_uploads');
    }
};