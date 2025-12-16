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
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_file_id');
            $table->unsignedBigInteger('approver_id');
            $table->enum('status', [
                'pending',
                'approved',
                'rejected'
            ])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('payment_file_id');
            $table->index('approver_id');
            $table->index('status');
            $table->index(['payment_file_id', 'status']);
            $table->index(['approver_id', 'status']);
            $table->index('approved_at');
            $table->index('created_at');
            
            // Foreign key constraints
            $table->foreign('payment_file_id')->references('id')->on('payment_files')->onDelete('cascade');
            $table->foreign('approver_id')->references('id')->on('users')->onDelete('cascade');

            // Unique constraint to prevent duplicate approval requests
            $table->unique(['payment_file_id', 'approver_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};