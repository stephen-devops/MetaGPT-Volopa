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
        Schema::create('pocket_expense_source_client_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('source_name', 100);
            $table->string('source_type', 50)->default('manual');
            $table->boolean('is_active')->default(true);
            $table->json('configuration')->nullable();
            $table->integer('max_daily_transactions')->default(100);
            $table->decimal('max_transaction_amount', 15, 2)->default(10000.00);
            $table->text('description')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['client_id', 'is_active']);
            $table->index(['source_type', 'is_active']);
            $table->index('is_active');
            $table->index(['client_id', 'source_name']);

            // Unique constraint to prevent duplicate source names per client
            $table->unique(['client_id', 'source_name'], 'unique_client_source_name');

            // Foreign key constraints
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense_source_client_configs');
    }
};