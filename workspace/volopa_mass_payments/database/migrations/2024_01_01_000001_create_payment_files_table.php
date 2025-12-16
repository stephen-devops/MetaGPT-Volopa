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
        Schema::create('payment_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('file_size');
            $table->enum('status', [
                'uploaded',
                'processing',
                'validated',
                'pending_approval',
                'approved',
                'rejected',
                'ready_for_processing',
                'processing_payments',
                'completed',
                'failed'
            ])->default('uploaded');
            $table->integer('total_records')->default(0);
            $table->integer('valid_records')->default(0);
            $table->integer('invalid_records')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('user_id');
            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index('created_at');
            
            // Foreign key constraint
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_files');
    }
};