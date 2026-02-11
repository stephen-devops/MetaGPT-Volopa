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
        Schema::create('pocket_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('expense_type_id')->nullable();
            $table->date('date');
            $table->string('merchant_name', 255);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->enum('status', ['pending', 'approved', 'rejected', 'processing'])->default('pending');
            $table->text('description')->nullable();
            $table->string('receipt_url', 500)->nullable();
            $table->decimal('converted_amount', 15, 2)->nullable();
            $table->string('converted_currency', 3)->nullable();
            $table->decimal('fx_rate', 10, 6)->nullable();
            $table->decimal('fx_commission', 5, 4)->default(0.0000);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_billable')->default(true);
            $table->string('project_code', 50)->nullable();
            $table->string('cost_center', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['date', 'client_id']);
            $table->index(['approved_by', 'approved_at']);
            $table->index('status');
            $table->index('currency');
            $table->index('expense_type_id');
            $table->index('is_billable');
            $table->index('deleted_at');
            $table->index(['user_id', 'date', 'status']);
            $table->index(['client_id', 'expense_type_id']);

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type_id')->references('id')->on('opt_pocket_expense_types')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expenses');
    }
};