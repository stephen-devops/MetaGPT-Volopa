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
        Schema::create('pocket_expense', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('client_id');
            $table->date('date');
            $table->string('merchant_name', 180);
            $table->text('merchant_description')->nullable();
            $table->unsignedBigInteger('expense_type');
            $table->char('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->text('merchant_address')->nullable();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type')->references('id')->on('opt_pocket_expense_type')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('restrict');

            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'date']);
            $table->index(['client_id', 'deleted']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'deleted']);
            $table->index(['expense_type']);
            $table->index(['currency']);
            $table->index(['status']);
            $table->index(['deleted']);
            $table->index(['created_by_user_id']);
            $table->index(['approved_by_user_id']);
            $table->index(['date']);
            $table->index('uuid');

            // Composite indexes for common queries
            $table->index(['client_id', 'user_id', 'status', 'deleted'], 'idx_client_user_status_deleted');
            $table->index(['client_id', 'date', 'deleted'], 'idx_client_date_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pocket_expense');
    }
};