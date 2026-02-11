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
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('original_filename', 255);
            $table->string('stored_filename', 255);
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type', 50)->default('csv');
            $table->enum('status', ['uploading', 'validating', 'processing', 'completed', 'failed', 'cancelled'])->default('uploading');
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('successful_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->json('validation_errors')->nullable();
            $table->json('processing_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['created_by_user_id', 'client_id']);
            $table->index(['status', 'started_at']);
            $table->index('uuid');
            $table->index('status');
            $table->index('file_type');
            $table->index(['user_id', 'status']);
            $table->index(['client_id', 'created_at']);
            $table->index('deleted_at');
            $table->index(['started_at', 'completed_at']);

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
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