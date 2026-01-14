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
            $table->string('client_id')->index();
            $table->uuid('tcc_account_id')->index();
            $table->string('filename');
            $table->string('file_path');
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->string('currency', 3);
            $table->enum('status', [
                'draft',
                'validating',
                'validation_failed',
                'awaiting_approval',
                'approved',
                'processing',
                'completed',
                'failed'
            ])->default('draft');
            $table->json('validation_errors')->nullable();
            $table->integer('total_instructions')->default(0);
            $table->integer('valid_instructions')->default(0);
            $table->integer('invalid_instructions')->default(0);
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'currency']);
            $table->index(['status', 'created_at']);
            
            // Foreign key constraints
            $table->foreign('tcc_account_id')->references('id')->on('tcc_accounts');
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