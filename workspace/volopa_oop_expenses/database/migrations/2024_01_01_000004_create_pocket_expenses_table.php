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
            $table->string('merchant_name', 255);
            $table->text('merchant_description')->nullable();
            $table->unsignedBigInteger('expense_type');
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->string('merchant_address', 500)->nullable();
            $table->string('merchant_country', 2)->nullable();
            $table->decimal('vat_amount', 5, 2)->nullable();
            $table->decimal('user_converted_amount', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('create_time')->useCurrent();
            $table->timestamp('update_time')->useCurrent()->useCurrentOnUpdate();
            $table->boolean('deleted')->default(false);
            $table->timestamp('delete_time')->nullable();

            // Indexes for performance
            $table->index(['user_id', 'client_id']);
            $table->index(['client_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['date', 'client_id']);
            $table->index(['status', 'deleted']);
            $table->index(['created_by_user_id']);
            $table->index(['approved_by_user_id']);
            $table->index(['expense_type']);
            $table->index('uuid');
            $table->index('deleted');
            $table->index('currency');

            // Composite indexes for common query patterns
            $table->index(['user_id', 'client_id', 'status', 'deleted'], 'user_client_status_deleted_idx');
            $table->index(['client_id', 'date', 'deleted'], 'client_date_deleted_idx');

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type')->references('id')->on('opt_pocket_expense_type')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
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