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
        Schema::create('mass_payment_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('tcc_account_id');
            $table->string('filename', 255);
            $table->string('file_path', 500);
            $table->enum('status', [
                'uploading',
                'uploaded', 
                'validating',
                'validation_failed',
                'validated',
                'pending_approval',
                'approved',
                'rejected',
                'processing',
                'processed',
                'completed',
                'failed'
            ])->default('uploading');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('validation_errors')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->string('currency', 3);
            $table->unsignedBigInteger('uploaded_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('client_id');
            $table->index('tcc_account_id');
            $table->index('status');
            $table->index('uploaded_by');
            $table->index('approved_by');
            $table->index(['client_id', 'status']);
            $table->index(['tcc_account_id', 'status']);
            $table->index('created_at');

            // Foreign key constraints
            $table->foreign('tcc_account_id')
                  ->references('id')
                  ->on('tcc_accounts')
                  ->onDelete('restrict');

            $table->foreign('uploaded_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict');

            $table->foreign('approved_by')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict');

            // Unique constraint to prevent duplicate filename uploads per account
            $table->unique(['tcc_account_id', 'filename', 'deleted_at'], 'unique_filename_per_account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mass_payment_files');
    }
};