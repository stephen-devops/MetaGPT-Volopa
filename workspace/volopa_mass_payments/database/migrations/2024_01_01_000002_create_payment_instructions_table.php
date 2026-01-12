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
            $table->id();
            $table->unsignedBigInteger('payment_file_id');
            $table->integer('row_number');
            $table->string('beneficiary_name', 255);
            $table->string('beneficiary_account', 255);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('settlement_method', 100);
            $table->string('payment_purpose', 500)->nullable();
            $table->string('reference', 255)->nullable();
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled'
            ])->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('payment_file_id');
            $table->index('status');
            $table->index(['payment_file_id', 'status']);
            $table->index('row_number');
            $table->index(['payment_file_id', 'row_number']);
            $table->index('processed_at');
            $table->index('currency');
            $table->index('settlement_method');
            
            // Foreign key constraint
            $table->foreign('payment_file_id')->references('id')->on('payment_files')->onDelete('cascade');
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