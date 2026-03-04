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
            $table->string('uuid', 36)->nullable()->comment('External reference UUID');
            $table->unsignedBigInteger('user_id')->comment('User who owns this expense');
            $table->unsignedBigInteger('client_id')->comment('Client context for multi-tenancy');
            $table->date('date')->comment('Expense date');
            $table->string('merchant_name', 180)->comment('Name of the merchant');
            $table->text('merchant_description')->nullable()->comment('Description of the merchant/expense');
            $table->unsignedBigInteger('expense_type')->comment('Reference to opt_pocket_expense_type');
            $table->string('currency', 3)->comment('3-letter ISO currency code');
            $table->decimal('amount', 15, 4)->comment('Expense amount with 4 decimal precision');
            $table->text('merchant_address')->nullable()->comment('Address of the merchant');
            $table->decimal('vat_amount', 15, 4)->nullable()->comment('VAT amount with 4 decimal precision');
            $table->text('notes')->nullable()->comment('Additional notes for the expense');
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft')->comment('Current status of the expense');
            $table->unsignedBigInteger('created_by_user_id')->comment('User who created this record');
            $table->unsignedBigInteger('updated_by_user_id')->nullable()->comment('User who last updated this record');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->comment('User who approved this expense');
            $table->datetime('create_time')->useCurrent()->comment('Record creation time');
            $table->datetime('update_time')->useCurrent()->useCurrentOnUpdate()->comment('Record last update time');
            $table->boolean('deleted')->default(false)->comment('Soft delete flag');
            $table->datetime('delete_time')->nullable()->comment('When record was deleted');
            
            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('expense_type')->references('id')->on('opt_pocket_expense_type')->onDelete('restrict');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_user_id')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['user_id', 'client_id'], 'idx_user_client');
            $table->index(['client_id', 'status'], 'idx_client_status');
            $table->index(['client_id', 'date'], 'idx_client_date');
            $table->index(['expense_type'], 'idx_expense_type');
            $table->index(['currency'], 'idx_currency');
            $table->index(['status'], 'idx_status');
            $table->index(['deleted'], 'idx_deleted');
            $table->index(['uuid'], 'idx_uuid');
            $table->index(['created_by_user_id'], 'idx_created_by');
            $table->index(['approved_by_user_id'], 'idx_approved_by');
            $table->index(['create_time'], 'idx_create_time');
            $table->index(['update_time'], 'idx_update_time');
            $table->index(['date', 'client_id', 'deleted'], 'idx_date_client_deleted');
            $table->index(['merchant_name', 'client_id'], 'idx_merchant_client');
            
            // Composite index for common queries
            $table->index(['user_id', 'client_id', 'status', 'deleted'], 'idx_user_client_status_deleted');
            
            // Table configuration
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_unicode_ci');
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