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
            $table->uuid('beneficiary_id')->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('purpose_code', 10);
            $table->string('reference', 255);
            $table->enum('status', [
                'draft',
                'validated',
                'validation_failed',
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled'
            ])->default('draft');
            $table->json('validation_errors')->nullable();
            $table->integer('row_number')->index();
            
            // Beneficiary information from CSV
            $table->string('beneficiary_name');
            $table->string('beneficiary_account_number')->nullable();
            $table->string('beneficiary_sort_code')->nullable();
            $table->string('beneficiary_iban')->nullable();
            $table->string('beneficiary_swift_code')->nullable();
            $table->string('beneficiary_bank_name')->nullable();
            $table->string('beneficiary_bank_address')->nullable();
            $table->string('beneficiary_address_line1')->nullable();
            $table->string('beneficiary_address_line2')->nullable();
            $table->string('beneficiary_city')->nullable();
            $table->string('beneficiary_state')->nullable();
            $table->string('beneficiary_postal_code')->nullable();
            $table->string('beneficiary_country', 2)->nullable();
            $table->string('beneficiary_email')->nullable();
            $table->string('beneficiary_phone')->nullable();
            
            // Currency-specific required fields
            $table->string('invoice_number')->nullable(); // Required for INR
            $table->date('invoice_date')->nullable(); // Required for INR
            $table->string('incorporation_number')->nullable(); // Required for TRY business recipients
            $table->enum('beneficiary_type', ['individual', 'business'])->default('individual');
            
            // Processing information
            $table->string('external_transaction_id')->nullable();
            $table->decimal('fx_rate', 10, 6)->nullable();
            $table->decimal('fee_amount', 15, 2)->default(0.00);
            $table->string('fee_currency', 3)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['mass_payment_file_id', 'status']);
            $table->index(['mass_payment_file_id', 'row_number']);
            $table->index(['status', 'created_at']);
            $table->index(['currency', 'status']);
            $table->index(['beneficiary_id', 'status']);
            $table->index('external_transaction_id');
            
            // Foreign key constraints
            $table->foreign('mass_payment_file_id')
                  ->references('id')
                  ->on('mass_payment_files')
                  ->onDelete('cascade');
                  
            $table->foreign('beneficiary_id')
                  ->references('id')
                  ->on('beneficiaries')
                  ->onDelete('set null');
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