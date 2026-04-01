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
        Schema::create('pocket_expense_file_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->integer('total_records')->default(0);
            $table->integer('valid_records')->default(0);
            $table->json('validation_errors')->nullable();
            $table->enum('status', [
                'uploaded',
                'validation_failed',
                'validation_passed',
                'processing',
                'completed',
                'failed',
                'sync_failed'
            ])->default('uploaded');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['uuid']);
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['status']);
            $table->index(['created_by_user_id']);
            $table->index(['uploaded_at']);
            
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
        Schema::dropIfExists('pocket_expense_file_uploads');
    }
};