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
        Schema::create('oop_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->date('date');
            $table->string('merchant_name', 255);
            $table->text('description')->nullable();
            $table->string('transaction_type', 50)->default('Point of Sale');
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->string('merchant_address', 500)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('category', 100)->nullable();
            $table->json('custom_fields')->nullable();
            $table->string('tracking_code_i', 100)->nullable();
            $table->string('tracking_code_ii', 100)->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('vat', 5, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');

            // Indexes for performance
            $table->index(['user_id', 'created_at']);
            $table->index(['client_id', 'created_at']);
            $table->index('status');
            $table->index('date');
            $table->index(['user_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oop_expenses');
    }
};