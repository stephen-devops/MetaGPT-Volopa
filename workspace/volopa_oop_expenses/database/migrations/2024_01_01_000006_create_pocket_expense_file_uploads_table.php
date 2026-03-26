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
            $table->string('uuid', 36)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->integer('total_records')->default(0);
            $table->integer('valid_records')->default(0);
            $table->json('validation_errors')->nullable();
            $table->enum('status', ['uploaded', 'validating', 'processing', 'completed', 'failed'])->default('uploaded');
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();
            $table->timestamp('deleted_at')->nullable();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['status', 'uploaded_at']);
            $table->index(['created_by_user_id']);
            $table->index('uuid');
            $table->index('deleted_at');
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