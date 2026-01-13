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
        Schema::create('payment_instructions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mass_payment_file_id')->index();
            $table->uuid('beneficiary_id')->index();
            $table->string('client_id', 50)->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('purpose_code', 10)->nullable();
            $table->string('remittance_information', 500)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'rejected'
            ])->default('pending')->index();
            $table->unsignedInteger('row_number')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('processing_reference', 100)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->decimal('processing_fee', 10, 2)->default(0.00);
            $table->string('exchange_rate', 20)->nullable();
            $table->decimal('local_amount', 15, 2)->nullable();
            $table->string('local_currency', 3)->nullable();
            $table->json('bank_response')->nullable();
            $table->string('transaction_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'currency']);
            $table->index(['mass_payment_file_id', 'status']);
            $table->index(['mass_payment_file_id', 'row_number']);
            $table->index(['beneficiary_id', 'status']);
            $table->index(['client_id', 'created_at']);
            $table->index(['processing_reference']);
            $table->index(['transaction_id']);
            $table->index(['processed_at']);
            
            // Composite indexes for common queries
            $table->index(['client_id', 'mass_payment_file_id', 'status']);
            $table->index(['mass_payment_file_id', 'beneficiary_id']);
            
            // Foreign key constraints
            $table->foreign('mass_payment_file_id')
                  ->references('id')
                  ->on('mass_payment_files')
                  ->onDelete('cascade');
                  
            $table->foreign('beneficiary_id')
                  ->references('id')
                  ->on('beneficiaries')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_instructions');
    }
};