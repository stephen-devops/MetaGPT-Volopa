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
            $table->uuid('id')->primary();
            $table->string('client_id', 50)->index();
            $table->uuid('tcc_account_id')->index();
            $table->string('filename', 255);
            $table->string('original_filename', 255);
            $table->string('file_path', 500);
            $table->enum('status', [
                'uploading',
                'processing', 
                'validation_completed',
                'validation_failed',
                'pending_approval',
                'approved',
                'processing_payments',
                'completed',
                'cancelled',
                'failed'
            ])->default('uploading')->index();
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->string('currency', 3);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->json('validation_summary')->nullable();
            $table->json('validation_errors')->nullable();
            $table->uuid('created_by')->index();
            $table->uuid('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'currency']);
            $table->index(['client_id', 'created_at']);
            $table->index(['tcc_account_id', 'status']);
            
            // Foreign key constraints
            $table->foreign('tcc_account_id')
                  ->references('id')
                  ->on('tcc_accounts')
                  ->onDelete('restrict');
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